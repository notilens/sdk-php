<?php

namespace NotiLens;

class Notify
{
    private const MAX_RETRIES = 2;
    private const TIMEOUT_S   = 10;

    public static function send(string $endpoint, string $secret, array $payload): void
    {
        $body    = json_encode($payload);
        $version = self::version();
        $headers = implode("\r\n", [
            'Content-Type: application/json',
            "X-NOTILENS-KEY: {$secret}",
            "User-Agent: NotiLens-CLI/{$version}",
            'Content-Length: ' . strlen($body),
        ]);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => $headers,
                'content'       => $body,
                'timeout'       => self::TIMEOUT_S,
                'ignore_errors' => true,
            ],
        ]);

        $lastErr = null;
        for ($i = 0; $i <= self::MAX_RETRIES; $i++) {
            try {
                $result = @file_get_contents($endpoint, false, $context);
                if ($result !== false) return;
                throw new \RuntimeException('Request failed');
            } catch (\Throwable $e) {
                $lastErr = $e;
                if ($i < self::MAX_RETRIES) sleep(1);
            }
        }

        throw $lastErr;
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
