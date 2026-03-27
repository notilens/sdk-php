<?php

namespace NotiLens;

class Notify
{
    private const HOST    = 'hook.notilens.com';
    private const PATH    = '/webhook/%s/send';
    private const PORT    = 443;
    private const CONNECT_TIMEOUT = 2.0;

    public static function send(string $token, string $secret, array $payload): void
    {
        $body    = json_encode($payload);
        $path    = sprintf(self::PATH, $token);
        $version = self::version();

        $request = implode("\r\n", [
            "POST {$path} HTTP/1.1",
            'Host: ' . self::HOST,
            'Content-Type: application/json',
            "X-NOTILENS-KEY: {$secret}",
            "User-Agent: NotiLens-PHP/{$version}",
            'Content-Length: ' . strlen($body),
            'Connection: close',
            '',
            $body,
        ]);

        // Fire-and-forget — open TLS socket, write request, close without reading response
        $ctx  = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $sock = @stream_socket_client(
            'ssl://' . self::HOST . ':' . self::PORT,
            $errno, $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if ($sock === false) return; // server unreachable — skip silently

        // Write synchronously (just a kernel buffer copy — microseconds),
        // then close. OS sends buffered data in background after close.
        @fwrite($sock, $request);
        @fclose($sock);
    }

    private static function version(): string
    {
        $composer = dirname(__DIR__) . '/composer.json';
        if (file_exists($composer)) {
            $data = json_decode(file_get_contents($composer), true);
            return $data['version'] ?? '0.0.0';
        }
        return '0.0.0';
    }
}
