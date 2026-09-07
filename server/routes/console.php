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

    $this->line(SignageUrl::cliBase((int) $port));
    $this->comment('Upload ceiling: '.SignageUpload::maxMegabytes().' MB (applied to php -S)');
    $this->comment('Press Ctrl+C to stop the server');

    chdir(public_path());
    passthru($cmd, $code);

    return is_int($code) ? $code : 0;
})->purpose('Serve the API with a raised media upload ceiling');
