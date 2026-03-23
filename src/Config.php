<?php

namespace NotiLens;

class Config
{
    private static function configFile(): string
    {
        return rtrim(getenv('HOME') ?: posix_getpwuid(posix_getuid())['dir'], '/') . '/.notilens_config.json';
    }

    public static function read(): array
    {
        $file = self::configFile();
        if (!file_exists($file)) return [];
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private static function write(array $config): void
    {
        file_put_contents(self::configFile(), json_encode($config, JSON_PRETTY_PRINT));
    }

    public static function getAgent(string $agent): ?array
    {
        return self::read()[$agent] ?? null;
    }

    public static function addAgent(string $agent, string $transport, string $endpoint, string $secret): void
    {
        $config = self::read();
        $config[$agent] = ['transport' => $transport, 'endpoint' => $endpoint, 'secret' => $secret];
        self::write($config);
    }
}
