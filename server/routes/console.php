<?php

use App\Support\SignageUpload;
use App\Support\SignageUrl;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('signage:lan {--port=8000}', function () {
    $url = SignageUrl::cliBase((int) $this->option('port'));
    $this->line($url);
    $this->comment('Type this into the Android app Server URL field.');
    $this->comment('Serve with: php artisan signage:serve --host=0.0.0.0 --port='.$this->option('port'));
})->purpose('Print the LAN URL to type into the Android player');

Artisan::command('signage:sntp {--host=0.0.0.0} {--port=8123}', function () {
    $host = (string) $this->option('host');
    $port = (int) $this->option('port');
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($sock === false) {
        $this->error('Could not create UDP socket');

        return 1;
    }
    if (! socket_bind($sock, $host, $port)) {
        $this->error('Could not bind UDP '.$host.':'.$port);

        return 1;
    }
    $this->comment('LAN SNTP listening on UDP '.$host.':'.$port);
    while (true) {
        $buf = '';
        $from = '';
        $fromPort = 0;
        $n = socket_recvfrom($sock, $buf, 512, 0, $from, $fromPort);
        if ($n === false || $n < 48) {
            continue;
        }
        $reply = \App\Support\SignageSntp::reply($buf, microtime(true));
        socket_sendto($sock, $reply, strlen($reply), 0, $from, $fromPort);
    }
})->purpose('LAN SNTP clock for players (not PHP /api/time)');

Artisan::command('signage:serve {--host=0.0.0.0} {--port=8000}', function () {
    $host = (string) $this->option('host');
    $port = (string) $this->option('port');
    $router = file_exists(base_path('server.php'))
        ? base_path('server.php')
        : base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php');

    // php -d on `artisan serve` is useless: Laravel then forks `php -S`
    // without those flags, so upload_max_filesize stays at the default 2M.
    $parts = [escapeshellarg(PHP_BINARY)];
    foreach (SignageUpload::phpServeDirectives() as $directive) {
        $parts[] = '-d '.escapeshellarg($directive);
    }
    $parts[] = '-S';
    $parts[] = escapeshellarg($host.':'.$port);
    $parts[] = escapeshellarg($router);
    $cmd = implode(' ', $parts);

    $sntpPort = \App\Support\SignageSntp::PORT;
    $sntpCmd = implode(' ', [
        escapeshellarg(PHP_BINARY),
        escapeshellarg(base_path('artisan')),
        'signage:sntp',
        '--host='.escapeshellarg($host),
        '--port='.$sntpPort,
    ]);
    $sntp = proc_open($sntpCmd, [
        0 => STDIN,
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ], $pipes, base_path());

    $this->line(SignageUrl::cliBase((int) $port));
    $this->comment('Upload ceiling: '.SignageUpload::maxMegabytes().' MB (applied to php -S)');
    $this->comment('LAN SNTP: UDP '.$host.':'.$sntpPort);
    $this->comment('Press Ctrl+C to stop the server');

    chdir(public_path());
    try {
        passthru($cmd, $code);
    } finally {
        if (is_resource($sntp)) {
            proc_terminate($sntp);
            proc_close($sntp);
        }
    }

    return is_int($code) ? $code : 0;
})->purpose('Serve the API with a raised media upload ceiling');
