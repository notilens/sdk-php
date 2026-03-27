<?php

namespace NotiLens;

class NotiLens
{
    private string $agent;
    private string $token;
    private string $secret;
    private array  $metrics = [];

    private function __construct(string $agent, string $token, string $secret)
    {
        $this->agent  = $agent;
        $this->token  = $token;
        $this->secret = $secret;
    }

    // ── Factory ───────────────────────────────────────────────────────────────

    public static function init(
        string  $agent,
        ?string $token  = null,
        ?string $secret = null,
    ): self {
        $token  ??= getenv('NOTILENS_TOKEN') ?: null;
        $secret ??= getenv('NOTILENS_SECRET') ?: null;

        // fall back to saved config
        if (!$token || !$secret) {
            $conf   = Config::getAgent($agent);
            $token  ??= $conf['token']  ?? null;
            $secret ??= $conf['secret'] ?? null;
        }

        if (!$token || !$secret) {
            throw new \InvalidArgumentException(
                "NotiLens: token and secret are required. Pass them directly or set " .
                "NOTILENS_TOKEN / NOTILENS_SECRET env vars, or run: " .
                "notilens init --agent {$agent} --token TOKEN --secret SECRET"
            );
        }

        return new self($agent, $token, $secret);
    }

    // ── Metrics ───────────────────────────────────────────────────────────────

    /**
     * Set a metric. Numeric values are accumulated (added) if the key exists.
     * Call anytime during a task — all metrics are auto-included in every send.
     *
     *   $agent->metric('tokens', 512);
     *   $agent->metric('cost', 0.003);
     *   $agent->metric('records', 1500);
     */
    public function metric(string $key, int|float|string $value): self
    {
        if (is_numeric($value) && isset($this->metrics[$key]) && is_numeric($this->metrics[$key])) {
            $this->metrics[$key] += $value;
        } else {
            $this->metrics[$key] = $value;
        }
        return $this;
    }

    /** Reset one metric by key, or all metrics if no key given. */
    public function resetMetrics(?string $key = null): self
    {
        if ($key !== null) {
            unset($this->metrics[$key]);
        } else {
            $this->metrics = [];
        }
        return $this;
    }

    // ── Task lifecycle ────────────────────────────────────────────────────────

    public function taskQueued(?string $taskId = null): string
    {
        $taskId    ??= 'task_' . round(microtime(true) * 1000);
        $stateFile = State::getFile($this->agent, $taskId);
        State::write($stateFile, [
            'agent'          => $this->agent,
            'task'           => $taskId,
            'queued_at'      => (int)(microtime(true) * 1000),
            'retry_count'    => 0,
            'loop_count'     => 0,
            'error_count'    => 0,
            'pause_count'    => 0,
            'wait_count'     => 0,
            'pause_total_ms' => 0,
            'wait_total_ms'  => 0,
        ]);
        $this->send('task.queued', 'Task queued', taskId: $taskId);
        return $taskId;
    }

    public function taskStart(?string $taskId = null): string
    {
        $taskId    ??= 'task_' . round(microtime(true) * 1000);
        $stateFile = State::getFile($this->agent, $taskId);
        $now       = (int)(microtime(true) * 1000);
        $existing  = State::read($stateFile);
        if (!empty($existing)) {
            State::update($stateFile, ['start_time' => $now]);
        } else {
            State::write($stateFile, [
                'agent'          => $this->agent,
                'task'           => $taskId,
                'start_time'     => $now,
                'retry_count'    => 0,
                'loop_count'     => 0,
                'error_count'    => 0,
                'pause_count'    => 0,
                'wait_count'     => 0,
                'pause_total_ms' => 0,
                'wait_total_ms'  => 0,
            ]);
        }
        $this->send('task.started', 'Task started', taskId: $taskId);
        return $taskId;
    }

    public function taskProgress(string $message, string $taskId): void
    {
        $this->send('task.progress', $message, taskId: $taskId);
    }

    public function taskLoop(string $message, string $taskId): void
    {
        $sf    = State::getFile($this->agent, $taskId);
        $state = State::read($sf);
        $count = ($state['loop_count'] ?? 0) + 1;
        State::update($sf, ['loop_count' => $count]);
        $this->send('task.loop', $message, taskId: $taskId);
    }

    public function taskRetry(string $taskId): void
    {
        $sf    = State::getFile($this->agent, $taskId);
        $state = State::read($sf);
        State::update($sf, ['retry_count' => ($state['retry_count'] ?? 0) + 1]);
        $this->send('task.retry', 'Retrying task', taskId: $taskId);
    }

    public function taskError(string $message, string $taskId): void
    {
        $sf    = State::getFile($this->agent, $taskId);
        $state = State::read($sf);
        State::update($sf, ['last_error' => $message, 'error_count' => ($state['error_count'] ?? 0) + 1]);
        $this->send('task.error', $message, taskId: $taskId);
    }

    public function taskComplete(string $message, string $taskId): void
    {
        $this->send('task.completed', $message, taskId: $taskId);
        State::delete(State::getFile($this->agent, $taskId));
    }

    public function taskFail(string $message, string $taskId): void
    {
        $this->send('task.failed', $message, taskId: $taskId);
        State::delete(State::getFile($this->agent, $taskId));
    }

    public function taskTimeout(string $message, string $taskId): void
    {
        $this->send('task.timeout', $message, taskId: $taskId);
        State::delete(State::getFile($this->agent, $taskId));
    }

    public function taskCancel(string $message, string $taskId): void
    {
        $this->send('task.cancelled', $message, taskId: $taskId);
        State::delete(State::getFile($this->agent, $taskId));
    }

    public function taskStop(string $taskId): void
    {
        $this->send('task.stopped', 'Task stopped', taskId: $taskId);
    }

    public function taskPaused(string $message, string $taskId): void
    {
        $sf    = State::getFile($this->agent, $taskId);
        $state = State::read($sf);
        $now   = (int)(microtime(true) * 1000);
        State::update($sf, [
            'paused_at'   => $now,
            'pause_count' => ($state['pause_count'] ?? 0) + 1,
        ]);
        $this->send('task.paused', $message, taskId: $taskId);
    }

    public function taskResumed(string $message, string $taskId): void
    {
        $sf      = State::getFile($this->agent, $taskId);
        $state   = State::read($sf);
        $now     = (int)(microtime(true) * 1000);
        $updates = [];
        if (!empty($state['paused_at'])) {
            $updates['pause_total_ms'] = ($state['pause_total_ms'] ?? 0) + ($now - $state['paused_at']);
            $updates['paused_at']      = null;
        }
        if (!empty($state['wait_at'])) {
            $updates['wait_total_ms'] = ($state['wait_total_ms'] ?? 0) + ($now - $state['wait_at']);
            $updates['wait_at']       = null;
        }
        if (!empty($updates)) {
            State::update($sf, $updates);
        }
        $this->send('task.resumed', $message, taskId: $taskId);
    }

    public function taskWaiting(string $message, string $taskId): void
    {
        $sf    = State::getFile($this->agent, $taskId);
        $state = State::read($sf);
        $now   = (int)(microtime(true) * 1000);
        State::update($sf, [
            'wait_at'    => $now,
            'wait_count' => ($state['wait_count'] ?? 0) + 1,
        ]);
        $this->send('task.waiting', $message, taskId: $taskId);
    }

    public function taskTerminate(string $message, string $taskId): void
    {
        $this->send('task.terminated', $message, taskId: $taskId);
        State::delete(State::getFile($this->agent, $taskId));
    }

    // ── Input events ──────────────────────────────────────────────────────────

    public function inputRequired(string $message, string $taskId): void
    {
        $this->send('input.required', $message, taskId: $taskId);
    }

    public function inputApproved(string $message, string $taskId): void
    {
        $this->send('input.approved', $message, taskId: $taskId);
    }

    public function inputRejected(string $message, string $taskId): void
    {
        $this->send('input.rejected', $message, taskId: $taskId);
    }

    // ── Output events ─────────────────────────────────────────────────────────

    public function outputGenerated(string $message, string $taskId): void
    {
        $this->send('output.generated', $message, taskId: $taskId);
    }

    public function outputFailed(string $message, string $taskId): void
    {
        $this->send('output.failed', $message, taskId: $taskId);
    }

    // ── Generic track ─────────────────────────────────────────────────────────

    public function track(string $event, string $message, array $meta = [], string $level = 'info'): void
    {
        $this->send($event, $message, meta: $meta, level: $level);
    }

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

    private function send(
        string  $event,
        string  $message,
        ?string $taskId = null,
        array   $meta   = [],
        string  $level  = 'info',
    ): void {
        $ntype = in_array($event, self::$successEvents, true)
            ? 'success'
            : (self::$levelToType[$level] ?? 'info');

        // urgent overrides for specific events
        if (in_array($event, ['task.failed', 'task.timeout', 'task.error', 'task.terminated', 'output.failed'], true)) {
            $ntype = 'urgent';
        } elseif (in_array($event, ['task.retry', 'task.cancelled', 'task.paused', 'task.waiting', 'input.required', 'input.rejected'], true)) {
            $ntype = 'warning';
        }

        $title = $taskId
            ? "{$this->agent} | {$taskId} | {$event}"
            : "{$this->agent} | {$event}";

        // Compute duration fields from state
        $totalMs = $queueMs = $pauseMs = $waitMs = $activeMs = 0;
        $retryCount = $loopCount = $errorCount = $pauseCount = $waitCount = 0;
        if ($taskId) {
            $sf    = State::getFile($this->agent, $taskId);
            $s     = State::read($sf);
            $now   = (int)(microtime(true) * 1000);
            $startTime  = $s['start_time']    ?? 0;
            $queuedAt   = $s['queued_at']     ?? 0;
            $pauseTotal = $s['pause_total_ms'] ?? 0;
            $waitTotal  = $s['wait_total_ms']  ?? 0;
            if (!empty($s['paused_at'])) $pauseTotal += $now - $s['paused_at'];
            if (!empty($s['wait_at']))   $waitTotal  += $now - $s['wait_at'];
            $totalMs  = $startTime ? $now - $startTime : 0;
            $queueMs  = ($startTime && $queuedAt) ? $startTime - $queuedAt : 0;
            $activeMs = max(0, $totalMs - $pauseTotal - $waitTotal);
            $pauseMs  = $pauseTotal;
            $waitMs   = $waitTotal;
            $retryCount = $s['retry_count'] ?? 0;
            $loopCount  = $s['loop_count']  ?? 0;
            $errorCount = $s['error_count'] ?? 0;
            $pauseCount = $s['pause_count'] ?? 0;
            $waitCount  = $s['wait_count']  ?? 0;
        }

        $extraMeta = array_diff_key($meta, array_flip(['image_url', 'open_url', 'download_url', 'tags']));
        $extraMeta['agent'] = $this->agent;
        if ($taskId)      $extraMeta['task_id']          = $taskId;
        if ($totalMs  > 0) $extraMeta['total_duration_ms'] = $totalMs;
        if ($queueMs  > 0) $extraMeta['queue_ms']          = $queueMs;
        if ($pauseMs  > 0) $extraMeta['pause_ms']          = $pauseMs;
        if ($waitMs   > 0) $extraMeta['wait_ms']           = $waitMs;
        if ($activeMs > 0) $extraMeta['active_ms']         = $activeMs;
        if ($retryCount > 0) $extraMeta['retry_count'] = $retryCount;
        if ($loopCount  > 0) $extraMeta['loop_count']  = $loopCount;
        if ($errorCount > 0) $extraMeta['error_count'] = $errorCount;
        if ($pauseCount > 0) $extraMeta['pause_count'] = $pauseCount;
        if ($waitCount  > 0) $extraMeta['wait_count']  = $waitCount;
        if (!empty($this->metrics)) $extraMeta = array_merge($extraMeta, $this->metrics);

        $payload = [
            'event'         => $event,
            'title'         => $title,
            'message'       => $message,
            'type'          => $ntype,
            'agent'         => $this->agent,
            'task_id'       => $taskId ?? '',
            'is_actionable' => in_array($event, self::$actionableEvents, true),
            'image_url'     => $meta['image_url']    ?? '',
            'open_url'      => $meta['open_url']     ?? '',
            'download_url'  => $meta['download_url'] ?? '',
            'tags'          => $meta['tags']         ?? '',
            'ts'            => microtime(true),
            'meta'          => $extraMeta,
        ];

        try {
            Notify::send($this->token, $this->secret, $payload);
        } catch (\Throwable) {
            // silent fail
        }
    }

}
