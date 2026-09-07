<?php

namespace App\Support;

use Illuminate\Http\Request;

class SignageApk
{
    public const FILENAME = 'foodtale-player.apk';

    public const META_FILENAME = 'foodtale-player.json';

    /**
     * @return array{version:string, version_code:int, apk_url:string, available:bool}
     */
    public static function meta(?Request $request = null): array
    {
        $request ??= request();
        $apk = public_path(self::FILENAME);
        $metaPath = public_path(self::META_FILENAME);
        $meta = is_file($metaPath)
            ? json_decode((string) file_get_contents($metaPath), true)
            : [];
        if (! is_array($meta)) {
            $meta = [];
        }

        return [
            'version' => (string) ($meta['version'] ?? '0.0.0'),
            'version_code' => (int) ($meta['version_code'] ?? 0),
            'apk_url' => $request->getSchemeAndHttpHost().'/'.self::FILENAME,
            'available' => is_file($apk),
        ];
    }

    public static function publicUrl(?Request $request = null): string
    {
        return self::meta($request)['apk_url'];
    }
}
