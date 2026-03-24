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

    public static function saveAgent(string $agent, string $token, string $secret): void
    {
        $config = self::read();
        $config[$agent] = ['token' => $token, 'secret' => $secret];
        self::write($config);
    }

    public static function removeAgent(string $agent): bool
    {
        $config = self::read();
        if (!isset($config[$agent])) return false;
        unset($config[$agent]);
        self::write($config);
        return true;
    }

    public static function listAgents(): array
    {
        return array_keys(self::read());
    }
}
