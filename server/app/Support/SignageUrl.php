<?php

namespace App\Support;

use Illuminate\Http\Request;

class SignageUrl
{
    public static function media(string $path, ?Request $request = null): string
    {
        $request ??= request();

        return $request->getSchemeAndHttpHost().'/storage/'.ltrim(str_replace('\\', '/', $path), '/');
    }

    public static function cliBase(int $port = 8000): string
    {
        $ip = self::guessLanIp() ?? '127.0.0.1';

        return 'http://'.$ip.':'.$port;
    }

    public static function lanBase(?Request $request = null): string
    {
        $request ??= request();
        $host = $request->getHost();
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return $request->getSchemeAndHttpHost();
        }

        $ip = self::guessLanIp();
        if ($ip === null) {
            return $request->getSchemeAndHttpHost();
        }

        $port = $request->getPort();
        $suffix = $port && ! in_array((int) $port, [80, 443], true) ? ':'.$port : '';

        return $request->getScheme().'://'.$ip.$suffix;
    }

    private static function guessLanIp(): ?string
    {
        $candidates = [];
        if (PHP_OS_FAMILY === 'Darwin') {
            $iface = trim((string) shell_exec("route -n get default 2>/dev/null | awk '/interface:/{print \$2}'"));
            if ($iface !== '') {
                $candidates[] = trim((string) shell_exec('ipconfig getifaddr '.escapeshellarg($iface).' 2>/dev/null'));
            }
            $candidates[] = trim((string) shell_exec('ipconfig getifaddr en0 2>/dev/null'));
            $candidates[] = trim((string) shell_exec('ipconfig getifaddr en1 2>/dev/null'));
        }
        $candidates[] = trim((string) shell_exec("hostname -I 2>/dev/null | awk '{print \$1}'"));

        foreach ($candidates as $ip) {
            if ($ip === '' || $ip === '127.0.0.1') {
                continue;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                continue;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return null;
    }
}
