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

    public function taskStart(?string $taskId = null): string
    {
        $taskId    ??= 'task_' . round(microtime(true) * 1000);
        $stateFile = State::getFile($this->agent, $taskId);
        State::write($stateFile, [
            'agent'       => $this->agent,
            'task'        => $taskId,
            'start_time'  => (int)(microtime(true) * 1000),
            'retry_count' => 0,
            'loop_count'  => 0,
        ]);
        $this->send('task.started', 'Task started', taskId: $taskId);
        return $taskId;
    }

    public function taskProgress(string $message, string $taskId): void
    {
        $sf = State::getFile($this->agent, $taskId);
        State::update($sf, ['duration_ms' => $this->calcDuration($sf)]);
        $this->send('task.progress', $message, taskId: $taskId);
    }

    public function taskLoop(string $message, string $taskId): void
    {
        $sf    = State::getFile($this->agent, $taskId);
        $state = State::read($sf);
        $count = ($state['loop_count'] ?? 0) + 1;
        State::update($sf, ['duration_ms' => $this->calcDuration($sf), 'loop_count' => $count]);
        $this->send('task.loop', $message, taskId: $taskId);
    }

    public function taskRetry(string $taskId): void  // fires task.retry
    {
        $sf    = State::getFile($this->agent, $taskId);
        $state = State::read($sf);
        State::update($sf, [
            'duration_ms' => $this->calcDuration($sf),
            'retry_count' => ($state['retry_count'] ?? 0) + 1,
        ]);
        $this->send('task.retry', 'Retrying task', taskId: $taskId);
    }

    public function taskError(string $message, string $taskId): void
    {
        $sf = State::getFile($this->agent, $taskId);
        State::update($sf, ['duration_ms' => $this->calcDuration($sf), 'last_error' => $message]);
        $this->send('task.error', $message, taskId: $taskId);
    }

    public function taskComplete(string $message, string $taskId): void
    {
        $sf = State::getFile($this->agent, $taskId);
        State::update($sf, ['duration_ms' => $this->calcDuration($sf)]);
        $this->send('task.completed', $message, taskId: $taskId);
        State::delete($sf);
    }

    public function taskFail(string $message, string $taskId): void
    {
        $sf = State::getFile($this->agent, $taskId);
        State::update($sf, ['duration_ms' => $this->calcDuration($sf)]);
        $this->send('task.failed', $message, taskId: $taskId);
        State::delete($sf);
    }

    public function taskTimeout(string $message, string $taskId): void
    {
        $sf = State::getFile($this->agent, $taskId);
        State::update($sf, ['duration_ms' => $this->calcDuration($sf)]);
        $this->send('task.timeout', $message, taskId: $taskId);
        State::delete($sf);
    }

    public function taskCancel(string $message, string $taskId): void
    {
        $sf = State::getFile($this->agent, $taskId);
        State::update($sf, ['duration_ms' => $this->calcDuration($sf)]);
        $this->send('task.cancelled', $message, taskId: $taskId);
        State::delete($sf);
    }

    public function taskStop(string $taskId): void
    {
        $sf = State::getFile($this->agent, $taskId);
        State::update($sf, ['duration_ms' => $this->calcDuration($sf)]);
        $this->send('task.stopped', 'Task stopped', taskId: $taskId);
    }

    public function taskTerminate(string $message, string $taskId): void
    {
        $sf = State::getFile($this->agent, $taskId);
        State::update($sf, ['duration_ms' => $this->calcDuration($sf)]);
        $this->send('task.terminated', $message, taskId: $taskId);
        State::delete($sf);
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

    // ── Generic emit ──────────────────────────────────────────────────────────

    public function emit(string $event, string $message, array $meta = [], string $level = 'info'): void
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
        } elseif (in_array($event, ['task.retry', 'task.cancelled', 'input.required', 'input.rejected'], true)) {
            $ntype = 'warning';
        }

        $title = $taskId
            ? "{$this->agent} | {$taskId} | {$event}"
            : "{$this->agent} | {$event}";

        // Pull duration/counts from state if available
        $duration   = 0;
        $retryCount = 0;
        $loopCount  = 0;
        if ($taskId) {
            $sf        = State::getFile($this->agent, $taskId);
            $stateData = State::read($sf);
            $duration   = $stateData['duration_ms']  ?? 0;
            $retryCount = $stateData['retry_count']  ?? 0;
            $loopCount  = $stateData['loop_count']   ?? 0;
        }

        $extraMeta = array_diff_key($meta, array_flip(['image_url', 'open_url', 'download_url', 'tags']));
        $extraMeta['agent'] = $this->agent;
        if ($duration   > 0) $extraMeta['duration_ms']  = $duration;
        if ($retryCount > 0) $extraMeta['retry_count']  = $retryCount;
        if ($loopCount  > 0) $extraMeta['loop_count']   = $loopCount;
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

    private function calcDuration(string $stateFile): int
    {
        $start = State::read($stateFile)['start_time'] ?? 0;
        return $start ? (int)(microtime(true) * 1000) - $start : 0;
    }
}
