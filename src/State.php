<?php

namespace NotiLens;

class State
{
    private static function user(): string
    {
        $u = get_current_user();
        return $u ?: (getenv('USER') ?: 'default');
    }

    public static function getFile(string $agent, string $runId): string
    {
        return sys_get_temp_dir() . '/notilens_' . self::user() . "_{$agent}_{$runId}.json";
    }

    public static function getPointerFile(string $agent, string $label): string
    {
        $safeLabel = preg_replace('/[\/\\\\]/', '_', $label);
        return sys_get_temp_dir() . '/notilens_' . self::user() . "_{$agent}_{$safeLabel}.ptr";
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

    public static function readPointer(string $agent, string $label): string
    {
        $pf = self::getPointerFile($agent, $label);
        if (!file_exists($pf)) return '';
        return trim(file_get_contents($pf));
    }

    public static function writePointer(string $agent, string $label, string $runId): void
    {
        file_put_contents(self::getPointerFile($agent, $label), $runId);
    }

    public static function deletePointer(string $agent, string $label): void
    {
        $pf = self::getPointerFile($agent, $label);
        if (file_exists($pf)) unlink($pf);
    }

    public static function cleanupStale(string $agent, int $stateTtlSeconds): void
    {
        $user   = self::user();
        $tmp    = sys_get_temp_dir();
        $cutoff = time() - $stateTtlSeconds;
        $prefix = "notilens_{$user}_{$agent}_";

        foreach (scandir($tmp) as $file) {
            if (!str_starts_with($file, $prefix)) continue;
            if (!str_ends_with($file, '.json') && !str_ends_with($file, '.ptr')) continue;
            $full = $tmp . DIRECTORY_SEPARATOR . $file;
            try {
                if (filemtime($full) < $cutoff) unlink($full);
            } catch (\Throwable) {}
        }
    }
}
