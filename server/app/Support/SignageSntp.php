<?php

namespace App\Support;

class SignageSntp
{
    public const PORT = 8123;

    private const UNIX_TO_NTP = 2208988800.0;

    public static function reply(string $request, float $receiveUnix): string
    {
        $packet = str_pad(substr($request, 0, 48), 48, "\0");
        $originate = substr($packet, 40, 8);
        $recv = self::packTimestamp($receiveUnix);
        $xmit = self::packTimestamp(microtime(true));

        $out = $packet;
        $out[0] = chr(0x24);
        $out[1] = chr(2);
        $out[2] = chr(6);
        $out[3] = chr(236);
        $out = substr_replace($out, $originate, 24, 8);
        $out = substr_replace($out, $recv, 32, 8);
        $out = substr_replace($out, $xmit, 40, 8);

        return $out;
    }

    public static function packTimestamp(float $unix): string
    {
        $ntp = $unix + self::UNIX_TO_NTP;
        $sec = floor($ntp);
        $frac = round(($ntp - $sec) * 4294967296.0);
        if ($frac >= 4294967296.0) {
            $sec += 1.0;
            $frac = 0.0;
        }

        return self::packUint32($sec).self::packUint32($frac);
    }

    /** Big-endian uint32 that survives 32-bit PHP_INT_MAX. */
    private static function packUint32(float $n): string
    {
        $n = fmod($n, 4294967296.0);
        if ($n < 0) {
            $n += 4294967296.0;
        }
        $hi = (int) floor($n / 65536.0);
        $lo = (int) fmod($n, 65536.0);

        return pack('n', $hi).pack('n', $lo);
    }
}
