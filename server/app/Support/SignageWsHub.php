<?php

namespace App\Support;

use App\Models\Device;

/**
 * Minimal JSON WebSocket hub + internal HTTP kick endpoint.
 * Kept off php -S so long-lived TV sockets cannot stall admin uploads.
 */
class SignageWsHub
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var resource */
    private $server;

    /** @var array<int, array{sock: resource, buffer: string, stage: string, device_id: ?int, playlist_id: ?int}> */
    private array $clients = [];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $secret,
    ) {}

    public function run(): int
    {
        $bind = 'tcp://'.$this->host.':'.$this->port;
        $server = @stream_socket_server($bind, $errno, $errstr);
        if ($server === false) {
            fwrite(STDERR, "signage:ws failed to bind {$bind}: {$errstr} ({$errno})\n");

            return 1;
        }
        stream_set_blocking($server, false);
        $this->server = $server;

        fwrite(STDOUT, "signage:ws listening on ws://{$this->host}:{$this->port}/device\n");

        while (true) {
            $read = [$this->server];
            foreach ($this->clients as $client) {
                $read[] = $client['sock'];
            }
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 1) === false) {
                continue;
            }

            foreach ($read as $sock) {
                if ($sock === $this->server) {
                    $this->accept();
                    continue;
                }
                $id = (int) $sock;
                if (! isset($this->clients[$id])) {
                    continue;
                }
                $chunk = @fread($sock, 8192);
                if ($chunk === false || $chunk === '') {
                    $this->drop($id);

                    continue;
                }
                $this->clients[$id]['buffer'] .= $chunk;
                $this->pump($id);
            }
        }
    }

    private function accept(): void
    {
        $sock = @stream_socket_accept($this->server, 0);
        if ($sock === false) {
            return;
        }
        stream_set_blocking($sock, false);
        $id = (int) $sock;
        $this->clients[$id] = [
            'sock' => $sock,
            'buffer' => '',
            'stage' => 'http',
            'device_id' => null,
            'playlist_id' => null,
        ];
    }

    private function drop(int $id): void
    {
        if (! isset($this->clients[$id])) {
            return;
        }
        @fclose($this->clients[$id]['sock']);
        unset($this->clients[$id]);
    }

    private function pump(int $id): void
    {
        $client = &$this->clients[$id];
        if ($client['stage'] === 'http') {
            if (! str_contains($client['buffer'], "\r\n\r\n")) {
                return;
            }
            $this->handleHttp($id);

            return;
        }

        $this->handleWsFrames($id);
    }

    private function handleHttp(int $id): void
    {
        $client = &$this->clients[$id];
        $raw = $client['buffer'];
        $headerEnd = strpos($raw, "\r\n\r\n");
        if ($headerEnd === false) {
            return;
        }
        $headerBlob = substr($raw, 0, $headerEnd);
        $body = substr($raw, $headerEnd + 4);
        $lines = explode("\r\n", $headerBlob);
        $requestLine = $lines[0] ?? '';
        if (! preg_match('/^(GET|POST)\s+(\S+)\s+HTTP\//i', $requestLine, $m)) {
            $this->httpRespond($id, 400, 'Bad Request');
            $this->drop($id);

            return;
        }
        $method = strtoupper($m[1]);
        $target = $m[2];
        $path = parse_url($target, PHP_URL_PATH) ?: '/';
        $query = [];
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

        $headers = [];
        for ($i = 1; $i < count($lines); $i++) {
            if (! str_contains($lines[$i], ':')) {
                continue;
            }
            [$k, $v] = explode(':', $lines[$i], 2);
            $headers[strtolower(trim($k))] = trim($v);
        }

        $contentLength = (int) ($headers['content-length'] ?? 0);
        if ($method === 'POST' && strlen($body) < $contentLength) {
            return;
        }
        if ($contentLength > 0) {
            $body = substr($body, 0, $contentLength);
        }
        $client['buffer'] = substr($raw, $headerEnd + 4 + $contentLength);

        if ($method === 'POST' && $path === '/internal/kick') {
            $this->handleInternalKick($id, $headers, $body);

            return;
        }

        if ($method === 'GET' && ($path === '/device' || $path === '/device/')) {
            $this->upgradeDevice($id, $headers, $query);

            return;
        }

        $this->httpRespond($id, 404, 'Not Found');
        $this->drop($id);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function handleInternalKick(int $id, array $headers, string $body): void
    {
        $got = $headers['x-signage-secret'] ?? '';
        if ($this->secret === '' || ! hash_equals($this->secret, $got)) {
            $this->httpRespond($id, 403, 'Forbidden');
            $this->drop($id);

            return;
        }

        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            $this->httpRespond($id, 400, 'Bad JSON');
            $this->drop($id);

            return;
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $sent = 0;
        foreach ($this->clients as $cid => $peer) {
            if ($peer['stage'] !== 'ws') {
                continue;
            }
            $this->wsSend($cid, $json);
            $sent++;
        }

        $this->httpRespond($id, 200, json_encode(['ok' => true, 'sent' => $sent], JSON_THROW_ON_ERROR), 'application/json');
        $this->drop($id);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $query
     */
    private function upgradeDevice(int $id, array $headers, array $query): void
    {
        $token = (string) ($query['token'] ?? '');
        if ($token === '') {
            $this->httpRespond($id, 401, 'Missing token');
            $this->drop($id);

            return;
        }

        $device = Device::query()->where('device_token', $token)->first();
        if (! $device) {
            $this->httpRespond($id, 401, 'Invalid token');
            $this->drop($id);

            return;
        }

        $key = $headers['sec-websocket-key'] ?? '';
        $upgrade = strtolower($headers['upgrade'] ?? '');
        if ($key === '' || $upgrade !== 'websocket') {
            $this->httpRespond($id, 400, 'Expected WebSocket upgrade');
            $this->drop($id);

            return;
        }

        $accept = base64_encode(sha1($key.self::GUID, true));
        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Accept: {$accept}\r\n"
            ."\r\n";
        @fwrite($this->clients[$id]['sock'], $response);

        $this->clients[$id]['stage'] = 'ws';
        $this->clients[$id]['device_id'] = (int) $device->id;
        $this->clients[$id]['playlist_id'] = $device->playlist_id !== null ? (int) $device->playlist_id : null;
        $this->wsSend($id, json_encode([
            'v' => 2,
            'kind' => 'hello',
            'device_id' => (int) $device->id,
        ], JSON_THROW_ON_ERROR));
    }

    private function handleWsFrames(int $id): void
    {
        $client = &$this->clients[$id];
        $buf = $client['buffer'];

        while (strlen($buf) >= 2) {
            $b0 = ord($buf[0]);
            $b1 = ord($buf[1]);
            $opcode = $b0 & 0x0F;
            $masked = ($b1 & 0x80) !== 0;
            $len = $b1 & 0x7F;
            $offset = 2;

            if ($len === 126) {
                if (strlen($buf) < 4) {
                    break;
                }
                $len = unpack('n', substr($buf, 2, 2))[1];
                $offset = 4;
            } elseif ($len === 127) {
                if (strlen($buf) < 10) {
                    break;
                }
                $hi = unpack('N', substr($buf, 2, 4))[1];
                $lo = unpack('N', substr($buf, 6, 4))[1];
                $len = ($hi << 32) | $lo;
                $offset = 10;
            }

            $maskLen = $masked ? 4 : 0;
            if (strlen($buf) < $offset + $maskLen + $len) {
                break;
            }

            $mask = $masked ? substr($buf, $offset, 4) : '';
            $offset += $maskLen;
            $payload = substr($buf, $offset, $len);
            if ($masked) {
                $decoded = '';
                for ($i = 0; $i < $len; $i++) {
                    $decoded .= $payload[$i] ^ $mask[$i % 4];
                }
                $payload = $decoded;
            }
            $buf = substr($buf, $offset + $len);

            if ($opcode === 0x8) {
                $client['buffer'] = $buf;
                $this->drop($id);

                return;
            }
            if ($opcode === 0x9) {
                $this->wsSendRaw($id, $this->frame(0xA, $payload));
                continue;
            }
            if ($opcode === 0x1) {
                $this->onDeviceMessage($id, $payload);
            }
        }

        $client['buffer'] = $buf;
    }

    private function onDeviceMessage(int $id, string $payload): void
    {
        $json = json_decode($payload, true);
        if (! is_array($json)) {
            return;
        }
        if (($json['kind'] ?? '') === 'ping') {
            $this->wsSend($id, json_encode(['v' => 2, 'kind' => 'pong'], JSON_THROW_ON_ERROR));

            return;
        }
        if (($json['kind'] ?? '') === 'playlist' && array_key_exists('playlist_id', $json)) {
            $this->clients[$id]['playlist_id'] = $json['playlist_id'] !== null
                ? (int) $json['playlist_id']
                : null;
        }
    }

    private function wsSend(int $id, string $text): void
    {
        $this->wsSendRaw($id, $this->frame(0x1, $text));
    }

    private function wsSendRaw(int $id, string $frame): void
    {
        if (! isset($this->clients[$id])) {
            return;
        }
        $ok = @fwrite($this->clients[$id]['sock'], $frame);
        if ($ok === false) {
            $this->drop($id);
        }
    }

    private function frame(int $opcode, string $payload): string
    {
        $len = strlen($payload);
        $head = chr(0x80 | ($opcode & 0x0F));
        if ($len < 126) {
            $head .= chr($len);
        } elseif ($len <= 0xFFFF) {
            $head .= chr(126).pack('n', $len);
        } else {
            $head .= chr(127).pack('NN', ($len >> 32) & 0xFFFFFFFF, $len & 0xFFFFFFFF);
        }

        return $head.$payload;
    }

    private function httpRespond(int $id, int $status, string $body, string $contentType = 'text/plain'): void
    {
        if (! isset($this->clients[$id])) {
            return;
        }
        $reason = match ($status) {
            200 => 'OK',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            default => 'Error',
        };
        $response = "HTTP/1.1 {$status} {$reason}\r\n"
            ."Content-Type: {$contentType}\r\n"
            .'Content-Length: '.strlen($body)."\r\n"
            ."Connection: close\r\n"
            ."\r\n"
            .$body;
        @fwrite($this->clients[$id]['sock'], $response);
    }
}
