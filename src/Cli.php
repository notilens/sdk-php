<?php

namespace NotiLens;

class Cli
{
    // ── Arg helpers ──────────────────────────────────────────────────────────

    private static function positionalArgs(array $args): array
    {
        $result = [];
        foreach ($args as $a) {
            if (str_starts_with($a, '--')) break;
            $result[] = $a;
        }
        return $result;
    }

    private static function parseFlags(array $args): array
    {
        $flags = [
            'agent'         => '',
            'task_label'    => '',
            'type'          => '',
            'meta'          => [],
            'image_url'     => '',
            'open_url'      => '',
            'download_url'  => '',
            'tags'          => '',
            'is_actionable' => '',
        ];

        $i = 0;
        $count = count($args);
        while ($i < $count) {
            switch ($args[$i]) {
                case '--agent':         $flags['agent']         = $args[++$i] ?? ''; break;
                case '--task':          $flags['task_label']    = $args[++$i] ?? ''; break;
                case '--type':          $flags['type']          = $args[++$i] ?? ''; break;
                case '--image_url':     $flags['image_url']     = $args[++$i] ?? ''; break;
                case '--open_url':      $flags['open_url']      = $args[++$i] ?? ''; break;
                case '--download_url':  $flags['download_url']  = $args[++$i] ?? ''; break;
                case '--tags':          $flags['tags']          = $args[++$i] ?? ''; break;
                case '--is_actionable': $flags['is_actionable'] = $args[++$i] ?? ''; break;
                case '--meta':
                    $kv = $args[++$i] ?? '';
                    $eq = strpos($kv, '=');
                    if ($eq !== false) {
                        $flags['meta'][substr($kv, 0, $eq)] = substr($kv, $eq + 1);
                    }
                    break;
            }
            $i++;
        }

        if (!$flags['agent']) {
            fwrite(STDERR, "❌ --agent is required\n");
            exit(1);
        }

        return $flags;
    }

    private static function resolveRunId(array $flags): string
    {
        $runId = State::readPointer($flags['agent'], $flags['task_label']);
        if (!$runId) {
            $label = $flags['task_label'] ?: '(no --task)';
            fwrite(STDERR, "❌ No active run for agent '{$flags['agent']}' task '{$label}'. Run start first.\n");
            exit(1);
        }
        return $runId;
    }

    // ── Event type / actionable mapping ──────────────────────────────────────

    private static function getEventType(string $event): string
    {
        return match (true) {
            in_array($event, ['task.completed', 'output.generated', 'input.approved'])  => 'success',
            in_array($event, ['task.failed', 'task.timeout', 'output.failed',
                              'task.error', 'task.terminated'])                          => 'urgent',
            in_array($event, ['task.retry', 'task.cancelled', 'task.paused',
                              'task.waiting', 'input.required', 'input.rejected'])       => 'warning',
            default                                                                      => 'info',
        };
    }

    private static function getActionableDefault(string $event): bool
    {
        return in_array($event, [
            'task.error', 'task.failed', 'task.timeout', 'task.retry', 'task.loop',
            'output.failed', 'input.required', 'input.rejected',
        ], true);
    }

    private static function validateType(string $t): string
    {
        return in_array($t, ['info', 'success', 'warning', 'urgent'], true) ? $t : '';
    }

    // ── Core send ─────────────────────────────────────────────────────────────

    private static function sendNotify(string $event, string $message, array $flags, string $runId): void
    {
        $conf = Config::getAgent($flags['agent']);
        if (!$conf || empty($conf['token']) || empty($conf['secret'])) {
            fwrite(STDERR, "❌ Agent '{$flags['agent']}' not configured. Run: notilens init --agent {$flags['agent']} --token TOKEN --secret SECRET\n");
            exit(1);
        }

        $stateFile  = State::getFile($flags['agent'], $runId);
        $state      = State::read($stateFile);
        $now        = (int)(microtime(true) * 1000);
        $startTime  = $state['start_time']     ?? 0;
        $queuedAt   = $state['queued_at']      ?? 0;
        $pauseTotal = $state['pause_total_ms'] ?? 0;
        $waitTotal  = $state['wait_total_ms']  ?? 0;
        if (!empty($state['paused_at'])) $pauseTotal += $now - $state['paused_at'];
        if (!empty($state['wait_at']))   $waitTotal  += $now - $state['wait_at'];
        $totalMs  = $startTime ? $now - $startTime : 0;
        $queueMs  = ($startTime && $queuedAt) ? $startTime - $queuedAt : 0;
        $activeMs = max(0, $totalMs - $pauseTotal - $waitTotal);

        $meta = [
            'run_id' => $runId,
            'task'   => $flags['task_label'],
            'agent'  => $flags['agent'],
        ];
        if ($flags['image_url'])     $meta['image_url']     = $flags['image_url'];
        if ($flags['open_url'])      $meta['open_url']      = $flags['open_url'];
        if ($flags['download_url'])  $meta['download_url']  = $flags['download_url'];
        if ($flags['tags'])          $meta['tags']          = $flags['tags'];
        if ($flags['is_actionable']) $meta['is_actionable'] = $flags['is_actionable'];
        if ($totalMs   > 0) $meta['total_duration_ms'] = $totalMs;
        if ($queueMs   > 0) $meta['queue_ms']          = $queueMs;
        if ($pauseTotal > 0) $meta['pause_ms']         = $pauseTotal;
        if ($waitTotal  > 0) $meta['wait_ms']          = $waitTotal;
        if ($activeMs  > 0) $meta['active_ms']         = $activeMs;
        if (($state['retry_count'] ?? 0) > 0) $meta['retry_count'] = $state['retry_count'];
        if (($state['loop_count']  ?? 0) > 0) $meta['loop_count']  = $state['loop_count'];
        if (($state['error_count'] ?? 0) > 0) $meta['error_count'] = $state['error_count'];
        if (($state['pause_count'] ?? 0) > 0) $meta['pause_count'] = $state['pause_count'];
        if (($state['wait_count']  ?? 0) > 0) $meta['wait_count']  = $state['wait_count'];
        if (!empty($state['metrics'])) $meta = array_merge($meta, $state['metrics']);
        $meta = array_merge($meta, $flags['meta']);

        $finalType       = self::validateType($flags['type']) ?: self::getEventType($event);
        $finalActionable = $flags['is_actionable'] !== ''
            ? strtolower($flags['is_actionable']) === 'true'
            : self::getActionableDefault($event);

        $payload = [
            'event'         => $event,
            'title'         => "{$flags['agent']} | {$flags['task_label']} | {$event}",
            'message'       => $message,
            'type'          => $finalType,
            'agent'         => $flags['agent'],
            'task_id'       => $flags['task_label'],
            'is_actionable' => $finalActionable,
            'image_url'     => $flags['image_url'],
            'open_url'      => $flags['open_url'],
            'download_url'  => $flags['download_url'],
            'tags'          => $flags['tags'],
            'ts'            => microtime(true),
            'meta'          => $meta,
        ];

        try {
            Notify::send($conf['token'], $conf['secret'], $payload);
        } catch (\Throwable) {
            // silent fail
        }
    }

    private static function genRunId(): string
    {
        return 'run_' . round(microtime(true) * 1000) . '_' . bin2hex(random_bytes(4));
    }

    // ── Commands ──────────────────────────────────────────────────────────────

    public static function run(array $argv): void
    {
        $command = $argv[1] ?? '';
        $rest    = array_slice($argv, 2);

        switch ($command) {

            case 'init':
                $token = $secret = $agent = '';
                for ($i = 0; $i < count($rest); $i++) {
                    match ($rest[$i]) {
                        '--agent'  => $agent  = $rest[++$i] ?? '',
                        '--token'  => $token  = $rest[++$i] ?? '',
                        '--secret' => $secret = $rest[++$i] ?? '',
                        default    => null,
                    };
                }
                if (!$agent || !$token || !$secret) {
                    fwrite(STDERR, "Usage: notilens init --agent <name> --token <token> --secret <secret>\n");
                    exit(1);
                }
                Config::saveAgent($agent, $token, $secret);
                echo "✔ Agent '{$agent}' saved\n";
                break;

            case 'agents':
                $agents = Config::listAgents();
                if (!$agents) {
                    echo "No agents configured.\n";
                } else {
                    foreach ($agents as $a) echo "  {$a}\n";
                }
                break;

            case 'remove-agent':
                $agent = $rest[0] ?? '';
                if (!$agent) {
                    fwrite(STDERR, "Usage: notilens remove-agent <agent>\n");
                    exit(1);
                }
                Config::removeAgent($agent)
                    ? print("✔ Agent '{$agent}' removed\n")
                    : fwrite(STDERR, "Agent '{$agent}' not found\n");
                break;

            case 'queue': {
                $flags     = self::parseFlags($rest);
                $runId     = self::genRunId();
                $stateFile = State::getFile($flags['agent'], $runId);
                State::write($stateFile, [
                    'agent'          => $flags['agent'],
                    'task'           => $flags['task_label'],
                    'run_id'         => $runId,
                    'queued_at'      => (int)(microtime(true) * 1000),
                    'retry_count'    => 0,
                    'loop_count'     => 0,
                    'error_count'    => 0,
                    'pause_count'    => 0,
                    'wait_count'     => 0,
                    'pause_total_ms' => 0,
                    'wait_total_ms'  => 0,
                ]);
                State::writePointer($flags['agent'], $flags['task_label'], $runId);
                self::sendNotify('task.queued', 'Task queued', $flags, $runId);
                echo $runId . "\n";
                break;
            }

            case 'start': {
                $flags    = self::parseFlags($rest);
                $existing = State::readPointer($flags['agent'], $flags['task_label']);
                $runId    = $existing ?: self::genRunId();
                $sf       = State::getFile($flags['agent'], $runId);
                if ($existing) {
                    State::update($sf, ['start_time' => (int)(microtime(true) * 1000)]);
                } else {
                    State::write($sf, [
                        'agent'          => $flags['agent'],
                        'task'           => $flags['task_label'],
                        'run_id'         => $runId,
                        'start_time'     => (int)(microtime(true) * 1000),
                        'retry_count'    => 0,
                        'loop_count'     => 0,
                        'error_count'    => 0,
                        'pause_count'    => 0,
                        'wait_count'     => 0,
                        'pause_total_ms' => 0,
                        'wait_total_ms'  => 0,
                    ]);
                    State::writePointer($flags['agent'], $flags['task_label'], $runId);
                }
                self::sendNotify('task.started', 'Task started', $flags, $runId);
                echo $runId . "\n";
                break;
            }

            case 'progress': {
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                $runId = self::resolveRunId($flags);
                self::sendNotify('task.progress', $msg, $flags, $runId);
                echo "⏳ Progress: {$flags['agent']} | {$flags['task_label']}\n";
                break;
            }

            case 'stop': {
                $flags = self::parseFlags($rest);
                $runId = self::resolveRunId($flags);
                self::sendNotify('task.stopped', 'Task stopped', $flags, $runId);
                echo "⏹  Stopped: {$flags['agent']} | {$flags['task_label']}\n";
                break;
            }

            case 'pause': {
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $runId     = self::resolveRunId($flags);
                $stateFile = State::getFile($flags['agent'], $runId);
                $state     = State::read($stateFile);
                State::update($stateFile, [
                    'paused_at'   => (int)(microtime(true) * 1000),
                    'pause_count' => ($state['pause_count'] ?? 0) + 1,
                ]);
                self::sendNotify('task.paused', $msg, $flags, $runId);
                echo "⏸  Paused: {$flags['agent']} | {$flags['task_label']}\n";
                break;
            }

            case 'resume': {
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $runId     = self::resolveRunId($flags);
                $stateFile = State::getFile($flags['agent'], $runId);
                $state     = State::read($stateFile);
                $now       = (int)(microtime(true) * 1000);
                $updates   = [];
                if (!empty($state['paused_at'])) {
                    $updates['pause_total_ms'] = ($state['pause_total_ms'] ?? 0) + ($now - $state['paused_at']);
                    $updates['paused_at']      = null;
                }
                if (!empty($state['wait_at'])) {
                    $updates['wait_total_ms'] = ($state['wait_total_ms'] ?? 0) + ($now - $state['wait_at']);
                    $updates['wait_at']       = null;
                }
                if (!empty($updates)) State::update($stateFile, $updates);
                self::sendNotify('task.resumed', $msg, $flags, $runId);
                echo "▶️  Resumed: {$flags['agent']} | {$flags['task_label']}\n";
                break;
            }

            case 'wait': {
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $runId     = self::resolveRunId($flags);
                $stateFile = State::getFile($flags['agent'], $runId);
                $state     = State::read($stateFile);
                State::update($stateFile, [
                    'wait_at'    => (int)(microtime(true) * 1000),
                    'wait_count' => ($state['wait_count'] ?? 0) + 1,
                ]);
                self::sendNotify('task.waiting', $msg, $flags, $runId);
                echo "⏳ Waiting: {$flags['agent']} | {$flags['task_label']}\n";
                break;
            }

            case 'retry': {
                $flags     = self::parseFlags($rest);
                $runId     = self::resolveRunId($flags);
                $stateFile = State::getFile($flags['agent'], $runId);
                $state     = State::read($stateFile);
                State::update($stateFile, ['retry_count' => ($state['retry_count'] ?? 0) + 1]);
                self::sendNotify('task.retry', 'Retrying task', $flags, $runId);
                echo "🔁 Retry: {$flags['agent']} | {$flags['task_label']}\n";
                break;
            }

            case 'loop': {
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $runId     = self::resolveRunId($flags);
                $stateFile = State::getFile($flags['agent'], $runId);
                $state     = State::read($stateFile);
                $lc        = ($state['loop_count'] ?? 0) + 1;
                State::update($stateFile, ['loop_count' => $lc]);
                self::sendNotify('task.loop', $msg, $flags, $runId);
                echo "🔄 Loop ({$lc}): {$flags['agent']} | {$flags['task_label']}\n";
                break;
            }

            case 'error': {
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $runId     = self::resolveRunId($flags);
                $stateFile = State::getFile($flags['agent'], $runId);
                $state     = State::read($stateFile);
                State::update($stateFile, [
                    'last_error'  => $msg,
                    'error_count' => ($state['error_count'] ?? 0) + 1,
                ]);
                self::sendNotify('task.error', $msg, $flags, $runId);
                fwrite(STDERR, "❌ Error: {$msg}\n");
                break;
            }

            case 'fail': {
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $runId     = self::resolveRunId($flags);
                self::sendNotify('task.failed', $msg, $flags, $runId);
                State::delete(State::getFile($flags['agent'], $runId));
                State::deletePointer($flags['agent'], $flags['task_label']);
                echo "💥 Failed: {$flags['agent']} | {$flags['task_label']}\n";
                break;
            }

            case 'timeout': {
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                $runId = self::resolveRunId($flags);
                self::sendNotify('task.timeout', $msg, $flags, $runId);
                State::delete(State::getFile($flags['agent'], $runId));
                State::deletePointer($flags['agent'], $flags['task_label']);
                echo "⏰ Timeout: {$flags['agent']} | {$flags['task_label']}\n";
                break;
            }

            case 'cancel': {
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                $runId = self::resolveRunId($flags);
                self::sendNotify('task.cancelled', $msg, $flags, $runId);
                State::delete(State::getFile($flags['agent'], $runId));
                State::deletePointer($flags['agent'], $flags['task_label']);
                echo "🚫 Cancelled: {$flags['agent']} | {$flags['task_label']}\n";
                break;
            }

            case 'terminate': {
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                $runId = self::resolveRunId($flags);
                self::sendNotify('task.terminated', $msg, $flags, $runId);
                State::delete(State::getFile($flags['agent'], $runId));
                State::deletePointer($flags['agent'], $flags['task_label']);
                echo "⚠️  Terminated: {$flags['agent']} | {$flags['task_label']}\n";
                break;
            }

            case 'complete': {
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                $runId = self::resolveRunId($flags);
                self::sendNotify('task.completed', $msg, $flags, $runId);
                State::delete(State::getFile($flags['agent'], $runId));
                State::deletePointer($flags['agent'], $flags['task_label']);
                echo "✅ Completed: {$flags['agent']} | {$flags['task_label']}\n";
                break;
            }

            case 'metric': {
                $pos       = self::positionalArgs($rest);
                $flags     = self::parseFlags($rest);
                $runId     = self::resolveRunId($flags);
                $stateFile = State::getFile($flags['agent'], $runId);
                $state     = State::read($stateFile);
                $metrics   = $state['metrics'] ?? [];
                foreach ($pos as $kv) {
                    $eq = strpos($kv, '=');
                    if ($eq === false) continue;
                    $k = substr($kv, 0, $eq);
                    $v = substr($kv, $eq + 1);
                    $v = is_numeric($v) ? $v + 0 : $v;
                    if (is_numeric($v) && isset($metrics[$k]) && is_numeric($metrics[$k])) {
                        $metrics[$k] += $v;
                    } else {
                        $metrics[$k] = $v;
                    }
                }
                State::update($stateFile, ['metrics' => $metrics]);
                echo "📊 Metrics: " . implode(', ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($metrics), $metrics)) . "\n";
                break;
            }

            case 'metric.reset': {
                $pos       = self::positionalArgs($rest);
                $flags     = self::parseFlags($rest);
                $runId     = self::resolveRunId($flags);
                $stateFile = State::getFile($flags['agent'], $runId);
                $state     = State::read($stateFile);
                $key       = $pos[0] ?? null;
                if ($key) {
                    $metrics = $state['metrics'] ?? [];
                    unset($metrics[$key]);
                    State::update($stateFile, ['metrics' => $metrics]);
                    echo "📊 Metric '{$key}' reset\n";
                } else {
                    State::update($stateFile, ['metrics' => []]);
                    echo "📊 All metrics reset\n";
                }
                break;
            }

            case 'output.generate': {
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                $runId = self::resolveRunId($flags);
                self::sendNotify('output.generated', $msg, $flags, $runId);
                break;
            }

            case 'output.fail': {
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                $runId = self::resolveRunId($flags);
                self::sendNotify('output.failed', $msg, $flags, $runId);
                break;
            }

            case 'input.required': {
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                $runId = self::resolveRunId($flags);
                self::sendNotify('input.required', $msg, $flags, $runId);
                break;
            }

            case 'input.approve': {
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                $runId = self::resolveRunId($flags);
                self::sendNotify('input.approved', $msg, $flags, $runId);
                break;
            }

            case 'input.reject': {
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                $runId = self::resolveRunId($flags);
                self::sendNotify('input.rejected', $msg, $flags, $runId);
                break;
            }

            case 'track': {
                $pos   = self::positionalArgs($rest);
                $event = $pos[0] ?? '';
                $msg   = $pos[1] ?? '';
                $flags = self::parseFlags(array_slice($rest, 2));
                $runId = State::readPointer($flags['agent'], $flags['task_label']) ?: '';
                self::sendNotify($event, $msg, $flags, $runId);
                echo "📡 Tracked: {$event}\n";
                break;
            }

            case 'version':
                $composer = dirname(__DIR__) . '/composer.json';
                $ver = '0.0.0';
                if (file_exists($composer)) {
                    $data = json_decode(file_get_contents($composer), true);
                    $ver  = $data['version'] ?? '0.0.0';
                }
                echo "NotiLens v{$ver}\n";
                break;

            default:
                self::printUsage();
        }
    }

    private static function printUsage(): void
    {
        echo <<<USAGE
Usage:
  notilens init --agent <name> --token <token> --secret <secret>
  notilens agents
  notilens remove-agent <agent>

Task Lifecycle:
  notilens queue           --agent <agent> --task <label>
  notilens start           --agent <agent> --task <label>
  notilens progress  "msg" --agent <agent> --task <label>
  notilens loop      "msg" --agent <agent> --task <label>
  notilens retry           --agent <agent> --task <label>
  notilens stop            --agent <agent> --task <label>
  notilens pause     "msg" --agent <agent> --task <label>
  notilens resume    "msg" --agent <agent> --task <label>
  notilens wait      "msg" --agent <agent> --task <label>
  notilens error     "msg" --agent <agent> --task <label>
  notilens fail      "msg" --agent <agent> --task <label>
  notilens timeout   "msg" --agent <agent> --task <label>
  notilens cancel    "msg" --agent <agent> --task <label>
  notilens terminate "msg" --agent <agent> --task <label>
  notilens complete  "msg" --agent <agent> --task <label>

Output / Input:
  notilens output.generate "msg" --agent <agent> --task <label>
  notilens output.fail     "msg" --agent <agent> --task <label>
  notilens input.required  "msg" --agent <agent> --task <label>
  notilens input.approve   "msg" --agent <agent> --task <label>
  notilens input.reject    "msg" --agent <agent> --task <label>

Metrics:
  notilens metric       tokens=512 cost=0.003 --agent <agent> --task <label>
  notilens metric.reset tokens               --agent <agent> --task <label>
  notilens metric.reset                      --agent <agent> --task <label>

Generic:
  notilens track <event> "msg" --agent <agent> [--task <label>]

Options:
  --agent <name>
  --task <label>          Task label (e.g. "email", "report")
  --type success|warning|urgent|info
  --meta key=value        (repeatable)
  --image_url <url>
  --open_url <url>
  --download_url <url>
  --tags "tag1,tag2"
  --is_actionable true|false

Other:
  notilens version
USAGE;
    }
}
