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

    public function queue(bool $forceSend = false): self
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
        $this->send('task.queued', 'Task queued', [], 'info', $forceSend);
        return $this;
    }

    public function start(bool $forceSend = false): self
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
        $this->send('task.started', 'Task started', [], 'info', $forceSend);
        return $this;
    }

    public function progress(string $message, bool $forceSend = false): void { $this->send('task.progress', $message, [], 'info', $forceSend); }

    public function loop(string $message, bool $forceSend = false): void
    {
        $state = State::read($this->stateFile);
        State::update($this->stateFile, ['loop_count' => ($state['loop_count'] ?? 0) + 1]);
        $this->send('task.loop', $message, [], 'warning', $forceSend);
    }

    public function retry(bool $forceSend = false): void
    {
        $state = State::read($this->stateFile);
        State::update($this->stateFile, ['retry_count' => ($state['retry_count'] ?? 0) + 1]);
        $this->send('task.retry', 'Retrying task', [], 'warning', $forceSend);
    }

    public function pause(string $message, bool $forceSend = false): void
    {
        $state = State::read($this->stateFile);
        State::update($this->stateFile, [
            'paused_at'   => (int)(microtime(true) * 1000),
            'pause_count' => ($state['pause_count'] ?? 0) + 1,
        ]);
        $this->send('task.paused', $message, [], 'warning', $forceSend);
    }

    public function resume(string $message, bool $forceSend = false): void
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
        $this->send('task.resumed', $message, [], 'info', $forceSend);
    }

    public function wait(string $message, bool $forceSend = false): void
    {
        $state = State::read($this->stateFile);
        State::update($this->stateFile, [
            'wait_at'    => (int)(microtime(true) * 1000),
            'wait_count' => ($state['wait_count'] ?? 0) + 1,
        ]);
        $this->send('task.waiting', $message, [], 'warning', $forceSend);
    }

    public function stop(bool $forceSend = false): void { $this->send('task.stopped', 'Task stopped', [], 'info', $forceSend); }

    public function error(string $message, bool $forceSend = false): void
    {
        $state = State::read($this->stateFile);
        State::update($this->stateFile, [
            'last_error'  => $message,
            'error_count' => ($state['error_count'] ?? 0) + 1,
        ]);
        $this->send('task.error', $message, [], 'error', $forceSend);
    }

    public function complete(string $message, bool $forceSend = false): void  { $this->send('task.completed',  $message, [], 'info',    $forceSend); $this->terminal(); }
    public function fail(string $message,      bool $forceSend = true):  void { $this->send('task.failed',     $message, [], 'error',   $forceSend); $this->terminal(); }
    public function timeout(string $message,   bool $forceSend = true):  void { $this->send('task.timeout',    $message, [], 'error',   $forceSend); $this->terminal(); }
    public function cancel(string $message,    bool $forceSend = false): void { $this->send('task.cancelled',  $message, [], 'warning', $forceSend); $this->terminal(); }
    public function terminate(string $message, bool $forceSend = true):  void { $this->send('task.terminated', $message, [], 'error',   $forceSend); $this->terminal(); }

    // ── Input / Output ────────────────────────────────────────────────────────

    public function inputRequired(string $message,   bool $forceSend = true):  void { $this->send('input.required',   $message, [], 'warning', $forceSend); }
    public function inputApproved(string $message,   bool $forceSend = false): void { $this->send('input.approved',   $message, [], 'info',    $forceSend); }
    public function inputRejected(string $message,   bool $forceSend = false): void { $this->send('input.rejected',   $message, [], 'warning', $forceSend); }
    public function outputGenerated(string $message, bool $forceSend = true):  void { $this->send('output.generated', $message, [], 'info',    $forceSend); }
    public function outputFailed(string $message,    bool $forceSend = true):  void { $this->send('output.failed',    $message, [], 'error',   $forceSend); }

    // ── Track / Notify ────────────────────────────────────────────────────────

    public function track(string $event, string $message, array $meta = [], string $level = 'info', bool $forceSend = false): void
    {
        $this->send($event, $message, $meta, $level, $forceSend);
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
        bool   $forceSend   = true,
    ): void {
        $extra = $meta;
        if ($imageUrl)    $extra['image_url']    = $imageUrl;
        if ($openUrl)     $extra['open_url']     = $openUrl;
        if ($downloadUrl) $extra['download_url'] = $downloadUrl;
        if ($tags)        $extra['tags']         = $tags;
        $this->send($event, $message, $extra, $level, $forceSend);
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function send(string $event, string $message, array $extraMeta = [], string $level = 'info', bool $forceSend = false): void
    {
        $this->agent->sendPayload($event, $message, $this->runId, $this->label, $this->stateFile, $this->metrics, $extraMeta, $level, $forceSend);
    }

    private function terminal(): void
    {
        State::delete($this->stateFile);
        State::deletePointer($this->agent->getName(), $this->label);
    }
}
