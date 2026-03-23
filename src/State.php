<?php

namespace NotiLens;

class State
{
    public static function getFile(string $agent, string $taskId): string
    {
        $user = get_current_user();
        return sys_get_temp_dir() . "/notilens_{$user}_{$agent}_{$taskId}.json";
    }

    public static function read(string $file): array
    {
        if (!file_exists($file)) return [];
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    public static function write(string $file, array $state): void
    {
        file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT));
    }

    public static function update(string $file, array $updates): void
    {
        $state = self::read($file);
        self::write($file, array_merge($state, $updates));
    }

    public static function delete(string $file): void
    {
        if (file_exists($file)) unlink($file);
    }
}
