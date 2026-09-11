<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Active CMS wake-up for phones. Same JSON goes over UDP (office LAN broadcast)
 * and the local WebSocket hub (any path that cannot broadcast). Phones dedupe by kick_id.
 *
 * UDP alone will not leave the office subnet — that is why the WS pipe exists.
 */
class SignageKick
{
    public const UDP_PORT = 48721;

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function content(int $playlistId, array $extra = []): void
    {
        self::fanout(array_merge([
            'v' => 2,
            'kind' => 'content',
            'playlist_id' => $playlistId,
            'kick_id' => (string) Str::uuid(),
        ], $extra));
    }

    public static function origin(int $playlistId, \DateTimeInterface|string $startAt): void
    {
        $iso = self::iso($startAt);

        self::fanout([
            'v' => 2,
            'kind' => 'origin',
            'playlist_id' => $playlistId,
            'start_at' => $iso,
            't' => self::nowMs(),
            'kick_id' => (string) Str::uuid(),
        ]);
    }

    /**
     * CMS mutation: phones must fetch content AND take the new origin (Restart).
     *
     * @param  array<string, mixed>  $extra
     */
    public static function wall(int $playlistId, \DateTimeInterface|string $startAt, array $extra = []): void
    {
        self::fanout(array_merge([
            'v' => 2,
            'kind' => 'wall',
            'playlist_id' => $playlistId,
            'start_at' => self::iso($startAt),
            't' => self::nowMs(),
            'kick_id' => (string) Str::uuid(),
        ], $extra));
    }

    private static function nowMs(): float
    {
        return SignageNtp::unixMilliseconds();
    }

    private static function iso(\DateTimeInterface|string $startAt): string
    {
        return \Carbon\Carbon::parse($startAt)->utc()->toIso8601String();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fanout(array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        self::udpBroadcast($json);
        self::postToHub($json);
    }

    public static function udpBroadcast(string $json): void
    {
        try {
            if (function_exists('socket_create')) {
                $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
                if ($sock !== false) {
                    socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
                    @socket_sendto($sock, $json, strlen($json), 0, '255.255.255.255', self::UDP_PORT);
                    socket_close($sock);

                    return;
                }
            }

            $ctx = stream_context_create(['socket' => ['so_broadcast' => true]]);
            $fp = @stream_socket_client(
                'udp://255.255.255.255:'.self::UDP_PORT,
                $errno,
                $errstr,
                0.2,
                STREAM_CLIENT_CONNECT,
                $ctx
            );
            if ($fp !== false) {
                @fwrite($fp, $json);
                @fclose($fp);
            }
        } catch (\Throwable) {
            // Kick is best-effort; phones keep last origin if both pipes miss.
        }
    }

    public static function postToHub(string $json): void
    {
        $port = (int) env('SIGNAGE_WS_PORT', 8080);
        $secret = (string) env('SIGNAGE_WS_SECRET', '');
        if ($secret === '') {
            return;
        }

        $url = 'http://127.0.0.1:'.$port.'/internal/kick';
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'X-Signage-Secret: '.$secret,
                    'Content-Length: '.strlen($json),
                    'Connection: close',
                ]),
                'content' => $json,
                'timeout' => 0.4,
                'ignore_errors' => true,
            ],
        ]);

        try {
            @file_get_contents($url, false, $ctx);
        } catch (\Throwable) {
            // Hub may be down; UDP still covers the office LAN.
        }
    }
}
