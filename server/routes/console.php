<?php

use App\Support\SignageUpload;
use App\Support\SignageUrl;
use App\Support\SignageWsHub;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('signage:lan {--port=8000}', function () {
    $url = SignageUrl::cliBase((int) $this->option('port'));
    $this->line($url);
    $this->comment('Type this into the Android app Server URL field.');
    $this->comment('Serve with: php artisan signage:serve --host=0.0.0.0 --port='.$this->option('port'));
})->purpose('Print the LAN URL to type into the Android player');

Artisan::command('signage:ws {--host=0.0.0.0} {--port=8080}', function () {
    $host = (string) $this->option('host');
    $port = (int) $this->option('port');
    $secret = (string) env('SIGNAGE_WS_SECRET', '');
    if ($secret === '') {
        $this->error('Set SIGNAGE_WS_SECRET in .env before starting the WebSocket hub.');

        return 1;
    }

    return (new SignageWsHub($host, $port, $secret))->run();
})->purpose('JSON WebSocket hub for active CMS kicks (separate from php -S)');

Artisan::command('signage:serve {--host=0.0.0.0} {--port=8000} {--ws-port=8080}', function () {
    $host = (string) $this->option('host');
    $port = (string) $this->option('port');
    $wsPort = (string) $this->option('ws-port');
    $router = file_exists(base_path('server.php'))
        ? base_path('server.php')
        : base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php');

    $secret = (string) env('SIGNAGE_WS_SECRET', '');
    if ($secret === '') {
        $secret = Str::random(40);
        $envPath = base_path('.env');
        if (is_file($envPath)) {
            $env = file_get_contents($envPath);
            if (is_string($env)) {
                if (! str_contains($env, 'SIGNAGE_WS_SECRET=')) {
                    file_put_contents(
                        $envPath,
                        rtrim($env)."\n\nSIGNAGE_WS_SECRET={$secret}\nSIGNAGE_WS_PORT={$wsPort}\n"
                    );
                } else {
                    $env = preg_replace('/^SIGNAGE_WS_SECRET=.*$/m', 'SIGNAGE_WS_SECRET='.$secret, $env) ?? $env;
                    if (! str_contains($env, 'SIGNAGE_WS_PORT=')) {
                        $env = rtrim($env)."\nSIGNAGE_WS_PORT={$wsPort}\n";
                    }
                    file_put_contents($envPath, $env);
                }
            }
        }
        putenv('SIGNAGE_WS_SECRET='.$secret);
        $_ENV['SIGNAGE_WS_SECRET'] = $secret;
        $_SERVER['SIGNAGE_WS_SECRET'] = $secret;
        $this->comment('Generated SIGNAGE_WS_SECRET and wrote it to .env');
    }

    putenv('SIGNAGE_WS_PORT='.$wsPort);
    $_ENV['SIGNAGE_WS_PORT'] = $wsPort;
    $_SERVER['SIGNAGE_WS_PORT'] = $wsPort;

    // php -d on `artisan serve` is useless: Laravel then forks `php -S`
    // without those flags, so upload_max_filesize stays at the default 2M.
    $httpParts = [escapeshellarg(PHP_BINARY)];
    foreach (SignageUpload::phpServeDirectives() as $directive) {
        $httpParts[] = '-d '.escapeshellarg($directive);
    }
    $httpParts[] = '-S';
    $httpParts[] = escapeshellarg($host.':'.$port);
    $httpParts[] = escapeshellarg($router);
    $httpCmd = implode(' ', $httpParts);

    $wsCmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(base_path('artisan'))
        .' signage:ws --host='.escapeshellarg($host).' --port='.escapeshellarg($wsPort);

    $lanHost = parse_url(SignageUrl::cliBase((int) $port), PHP_URL_HOST) ?: '127.0.0.1';
    $this->line(SignageUrl::cliBase((int) $port));
    $this->line('WebSocket: ws://'.$lanHost.':'.$wsPort.'/device');
    $this->comment('Upload ceiling: '.SignageUpload::maxMegabytes().' MB (applied to php -S)');
    $this->comment('Press Ctrl+C to stop HTTP + WebSocket');

    $spawn = function (string $command, string $cwd) {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => STDOUT,
            2 => STDERR,
        ];
        $proc = proc_open($command, $descriptors, $pipes, $cwd);
        if (! is_resource($proc)) {
            return null;
        }

        return $proc;
    };

    $httpProc = $spawn($httpCmd, public_path());
    $wsProc = $spawn($wsCmd, base_path());
    if ($httpProc === null || $wsProc === null) {
        $this->error('Failed to start HTTP or WebSocket process');
        foreach ([$httpProc, $wsProc] as $proc) {
            if (is_resource($proc)) {
                proc_terminate($proc);
                proc_close($proc);
            }
        }

        return 1;
    }

    $stop = function () use (&$httpProc, &$wsProc) {
        foreach ([&$httpProc, &$wsProc] as &$proc) {
            if (! is_resource($proc)) {
                continue;
            }
            $status = proc_get_status($proc);
            if (! empty($status['running'])) {
                proc_terminate($proc, 15);
            }
        }
        unset($proc);
        usleep(200000);
        foreach ([&$httpProc, &$wsProc] as &$proc) {
            if (! is_resource($proc)) {
                continue;
            }
            $status = proc_get_status($proc);
            if (! empty($status['running'])) {
                proc_terminate($proc, 9);
            }
            proc_close($proc);
            $proc = null;
        }
        unset($proc);
    };

    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function () use ($stop) {
            $stop();
            exit(0);
        });
        pcntl_signal(SIGTERM, function () use ($stop) {
            $stop();
            exit(0);
        });
    }

    while (true) {
        $httpStatus = proc_get_status($httpProc);
        $wsStatus = proc_get_status($wsProc);
        if (empty($httpStatus['running']) || empty($wsStatus['running'])) {
            $stop();

            return empty($httpStatus['running'])
                ? (int) ($httpStatus['exitcode'] ?? 1)
                : (int) ($wsStatus['exitcode'] ?? 1);
        }
        usleep(300000);
    }
})->purpose('Serve the API with a raised media upload ceiling and the kick WebSocket hub');
