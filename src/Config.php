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

    public static function getSource(string $name): ?array
    {
        return self::read()[$name] ?? null;
    }

    public static function saveSource(string $name, string $token, string $secret): void
    {
        $config = self::read();
        $config[$name] = ['token' => $token, 'secret' => $secret];
        self::write($config);
    }

    public static function removeSource(string $name): bool
    {
        $config = self::read();
        if (!isset($config[$name])) return false;
        unset($config[$name]);
        self::write($config);
        return true;
    }

    public static function listSources(): array
    {
        return array_keys(self::read());
    }
}
