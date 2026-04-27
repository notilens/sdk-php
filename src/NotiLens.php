<?php

namespace NotiLens;

class NotiLens
{
    private string $name;
    private string $token;
    private string $secret;
    private int    $stateTtl;
    private array  $metrics = [];

    private function __construct(string $name, string $token, string $secret, int $stateTtl)
    {
        $this->name     = $name;
        $this->token    = $token;
        $this->secret   = $secret;
        $this->stateTtl = $stateTtl;
        State::cleanupStale($name, $stateTtl);
    }

    // ── Factory ───────────────────────────────────────────────────────────────

    public static function init(
        string  $name,
        ?string $token    = null,
        ?string $secret   = null,
        int     $stateTtl = 86400,
    ): self {
        $token  ??= getenv('NOTILENS_TOKEN') ?: null;
        $secret ??= getenv('NOTILENS_SECRET') ?: null;

        if (!$token || !$secret) {
            $conf   = Config::getSource($name);
            $token  ??= $conf['token']  ?? null;
            $secret ??= $conf['secret'] ?? null;
        }

        if (!$token || !$secret) {
            throw new \InvalidArgumentException(
                "NotiLens: token and secret are required. Pass them directly or set " .
                "NOTILENS_TOKEN / NOTILENS_SECRET env vars, or run: " .
                "notilens init --name {$name} --token TOKEN --secret SECRET"
            );
        }

        return new self($name, $token, $secret, $stateTtl);
    }

    // ── Task factory ──────────────────────────────────────────────────────────

    public function task(string $label): Run
    {
        State::cleanupStale($this->name, $this->stateTtl);
        return new Run($this, $label, $this->genRunId());
    }

    // ── Metrics ───────────────────────────────────────────────────────────────

    public function metric(string $key, int|float|string $value): self
    {
        if (is_numeric($value) && isset($this->metrics[$key]) && is_numeric($this->metrics[$key])) {
            $this->metrics[$key] += $value;
        } else {
            $this->metrics[$key] = $value;
        }
        return $this;
    }

    public function resetMetrics(?string $key = null): self
    {
        if ($key !== null) {
            unset($this->metrics[$key]);
        } else {
            $this->metrics = [];
        }
        return $this;
    }

    // ── Track / Notify ────────────────────────────────────────────────────────

    public function track(string $event, string $message, array $meta = [], string $level = 'info'): void
    {
        $this->sendPayload($event, $message, '', '', '', $this->metrics, $meta, $level);
    }

    public function notify(
        string $event,
        string $message,
        string $level       = 'info',
        array  $meta        = [],
        string $imageUrl    = '',
        string $openUrl     = '',
        string $downloadUrl = '',
        string $tags        = '',
    ): void {
        $extra = $meta;
        if ($imageUrl)    $extra['image_url']    = $imageUrl;
        if ($openUrl)     $extra['open_url']     = $openUrl;
        if ($downloadUrl) $extra['download_url'] = $downloadUrl;
        if ($tags)        $extra['tags']         = $tags;
        $this->sendPayload($event, $message, '', '', '', $this->metrics, $extra, $level);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getName(): string { return $this->name; }

    // ── Internals ─────────────────────────────────────────────────────────────

    private static array $successEvents = [
        'task.completed', 'output.generated', 'input.approved',
    ];

    private static array $actionableEvents = [
        'task.error', 'task.failed', 'task.timeout', 'task.retry', 'task.loop',
        'output.failed', 'input.required', 'input.rejected',
    ];

    private static array $levelToType = [
        'debug'    => 'info',
        'info'     => 'info',
        'warning'  => 'warning',
        'error'    => 'urgent',
        'critical' => 'urgent',
    ];

    public function sendPayload(
        string $event,
        string $message,
        string $runId,
        string $label,
        string $stateFile,
        array  $runMetrics,
        array  $extraMeta = [],
        string $level = 'info',
    ): void {
        $ntype = in_array($event, self::$successEvents, true)
            ? 'success'
            : (self::$levelToType[$level] ?? 'info');

        if (in_array($event, ['task.failed', 'task.timeout', 'task.error', 'task.terminated', 'output.failed'], true)) {
            $ntype = 'urgent';
        } elseif (in_array($event, ['task.retry', 'task.cancelled', 'task.paused', 'task.waiting', 'input.required', 'input.rejected'], true)) {
            $ntype = 'warning';
        }

        $meta = ['agent' => $this->name];  // kept as "agent" for backend compatibility
        if ($runId) $meta['run_id'] = $runId;
        if ($label) $meta['task']   = $label;

        if ($stateFile) {
            $s          = State::read($stateFile);
            $now        = (int)(microtime(true) * 1000);
            $startTime  = $s['start_time']     ?? 0;
            $queuedAt   = $s['queued_at']      ?? 0;
            $pauseTotal = $s['pause_total_ms'] ?? 0;
            $waitTotal  = $s['wait_total_ms']  ?? 0;
            if (!empty($s['paused_at'])) $pauseTotal += $now - $s['paused_at'];
            if (!empty($s['wait_at']))   $waitTotal  += $now - $s['wait_at'];
            $totalMs  = $startTime ? $now - $startTime : 0;
            $queueMs  = ($startTime && $queuedAt) ? $startTime - $queuedAt : 0;
            $activeMs = max(0, $totalMs - $pauseTotal - $waitTotal);

            if ($totalMs   > 0) $meta['total_duration_ms'] = $totalMs;
            if ($queueMs   > 0) $meta['queue_ms']          = $queueMs;
            if ($pauseTotal > 0) $meta['pause_ms']         = $pauseTotal;
            if ($waitTotal  > 0) $meta['wait_ms']          = $waitTotal;
            if ($activeMs  > 0) $meta['active_ms']         = $activeMs;
            if (($s['retry_count'] ?? 0) > 0) $meta['retry_count'] = $s['retry_count'];
            if (($s['loop_count']  ?? 0) > 0) $meta['loop_count']  = $s['loop_count'];
            if (($s['error_count'] ?? 0) > 0) $meta['error_count'] = $s['error_count'];
            if (($s['pause_count'] ?? 0) > 0) $meta['pause_count'] = $s['pause_count'];
            if (($s['wait_count']  ?? 0) > 0) $meta['wait_count']  = $s['wait_count'];
            if (!empty($s['metrics'])) $meta = array_merge($meta, $s['metrics']);
        }

        if (!empty($runMetrics)) $meta = array_merge($meta, $runMetrics);
        $meta = array_merge($meta, $extraMeta);

        $title = $label
            ? "{$this->name} | {$label} | {$event}"
            : "{$this->name} | {$event}";

        $payload = [
            'event'         => $event,
            'title'         => $title,
            'message'       => $message,
            'type'          => $ntype,
            'agent'         => $this->name,  // kept as "agent" for backend compatibility
            'task_id'       => $label,
            'is_actionable' => in_array($event, self::$actionableEvents, true),
            'image_url'     => $extraMeta['image_url']    ?? '',
            'open_url'      => $extraMeta['open_url']     ?? '',
            'download_url'  => $extraMeta['download_url'] ?? '',
            'tags'          => $extraMeta['tags']         ?? '',
            'ts'            => microtime(true),
            'meta'          => $meta,
        ];

        try {
            Notify::send($this->token, $this->secret, $payload);
        } catch (\Throwable) {
            // silent fail
        }
    }

    private function genRunId(): string
    {
        return 'run_' . round(microtime(true) * 1000) . '_' . bin2hex(random_bytes(4));
    }
}
