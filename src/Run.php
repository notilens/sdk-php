<?php

namespace NotiLens;

class Run
{
    private string $stateFile;
    private array  $metrics = [];

    public function __construct(
        private readonly NotiLens $agent,
        public readonly string    $label,
        public readonly string    $runId,
    ) {
        $this->stateFile = State::getFile($agent->getName(), $runId);
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

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function queue(): self
    {
        State::write($this->stateFile, [
            'agent'          => $this->agent->getName(),
            'task'           => $this->label,
            'run_id'         => $this->runId,
            'queued_at'      => (int)(microtime(true) * 1000),
            'retry_count'    => 0,
            'loop_count'     => 0,
            'error_count'    => 0,
            'pause_count'    => 0,
            'wait_count'     => 0,
            'pause_total_ms' => 0,
            'wait_total_ms'  => 0,
        ]);
        $this->send('task.queued', 'Task queued');
        return $this;
    }

    public function start(): self
    {
        $now      = (int)(microtime(true) * 1000);
        $existing = State::read($this->stateFile);
        if (!empty($existing)) {
            State::update($this->stateFile, ['start_time' => $now]);
        } else {
            State::write($this->stateFile, [
                'agent'          => $this->agent->getName(),
                'task'           => $this->label,
                'run_id'         => $this->runId,
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
        $this->send('task.started', 'Task started');
        return $this;
    }

    public function progress(string $message): void { $this->send('task.progress', $message); }

    public function loop(string $message): void
    {
        $state = State::read($this->stateFile);
        State::update($this->stateFile, ['loop_count' => ($state['loop_count'] ?? 0) + 1]);
        $this->send('task.loop', $message);
    }

    public function retry(): void
    {
        $state = State::read($this->stateFile);
        State::update($this->stateFile, ['retry_count' => ($state['retry_count'] ?? 0) + 1]);
        $this->send('task.retry', 'Retrying task');
    }

    public function pause(string $message): void
    {
        $state = State::read($this->stateFile);
        State::update($this->stateFile, [
            'paused_at'   => (int)(microtime(true) * 1000),
            'pause_count' => ($state['pause_count'] ?? 0) + 1,
        ]);
        $this->send('task.paused', $message);
    }

    public function resume(string $message): void
    {
        $state   = State::read($this->stateFile);
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
        if (!empty($updates)) State::update($this->stateFile, $updates);
        $this->send('task.resumed', $message);
    }

    public function wait(string $message): void
    {
        $state = State::read($this->stateFile);
        State::update($this->stateFile, [
            'wait_at'    => (int)(microtime(true) * 1000),
            'wait_count' => ($state['wait_count'] ?? 0) + 1,
        ]);
        $this->send('task.waiting', $message);
    }

    public function stop(): void    { $this->send('task.stopped',  'Task stopped'); }

    public function error(string $message): void
    {
        $state = State::read($this->stateFile);
        State::update($this->stateFile, [
            'last_error'  => $message,
            'error_count' => ($state['error_count'] ?? 0) + 1,
        ]);
        $this->send('task.error', $message);
    }

    public function complete(string $message): void  { $this->send('task.completed',  $message); $this->terminal(); }
    public function fail(string $message): void      { $this->send('task.failed',      $message); $this->terminal(); }
    public function timeout(string $message): void   { $this->send('task.timeout',     $message); $this->terminal(); }
    public function cancel(string $message): void    { $this->send('task.cancelled',   $message); $this->terminal(); }
    public function terminate(string $message): void { $this->send('task.terminated',  $message); $this->terminal(); }

    // ── Input / Output ────────────────────────────────────────────────────────

    public function inputRequired(string $message): void   { $this->send('input.required',   $message); }
    public function inputApproved(string $message): void   { $this->send('input.approved',   $message); }
    public function inputRejected(string $message): void   { $this->send('input.rejected',   $message); }
    public function outputGenerated(string $message): void { $this->send('output.generated', $message); }
    public function outputFailed(string $message): void    { $this->send('output.failed',    $message); }

    // ── Track / Notify ────────────────────────────────────────────────────────

    public function track(string $event, string $message, array $meta = [], string $level = 'info'): void
    {
        $this->send($event, $message, $meta, $level);
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
        $this->send($event, $message, $extra, $level);
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function send(string $event, string $message, array $extraMeta = [], string $level = 'info'): void
    {
        $this->agent->sendPayload($event, $message, $this->runId, $this->label, $this->stateFile, $this->metrics, $extraMeta, $level);
    }

    private function terminal(): void
    {
        State::delete($this->stateFile);
        State::deletePointer($this->agent->getName(), $this->label);
    }
}
