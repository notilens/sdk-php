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
            'task_id'       => '',
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
                case '--agent':        $flags['agent']         = $args[++$i] ?? ''; break;
                case '--task':         $flags['task_id']       = $args[++$i] ?? ''; break;
                case '--type':         $flags['type']          = $args[++$i] ?? ''; break;
                case '--image_url':    $flags['image_url']     = $args[++$i] ?? ''; break;
                case '--open_url':     $flags['open_url']      = $args[++$i] ?? ''; break;
                case '--download_url': $flags['download_url']  = $args[++$i] ?? ''; break;
                case '--tags':         $flags['tags']          = $args[++$i] ?? ''; break;
                case '--is_actionable':$flags['is_actionable'] = $args[++$i] ?? ''; break;
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

        if (!$flags['task_id']) {
            $flags['task_id'] = 'task_' . round(microtime(true) * 1000);
        }

        return $flags;
    }

    // ── Event type / actionable mapping ──────────────────────────────────────

    private static function getEventType(string $event): string
    {
        return match (true) {
            in_array($event, ['task.completed', 'output.generated', 'input.approved'])  => 'success',
            in_array($event, ['task.failed', 'task.timeout', 'output.failed',
                              'task.error', 'task.terminated'])                          => 'urgent',
            in_array($event, ['task.retry', 'task.cancelled', 'input.required',
                              'input.rejected'])                                         => 'warning',
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

    private static function sendNotify(string $event, string $message, array $flags): void
    {
        $conf = Config::getAgent($flags['agent']);
        if (!$conf || empty($conf['token']) || empty($conf['secret'])) {
            fwrite(STDERR, "❌ Agent '{$flags['agent']}' not configured. Run: notilens init --agent {$flags['agent']} --token TOKEN --secret SECRET\n");
            exit(1);
        }

        $stateFile = State::getFile($flags['agent'], $flags['task_id']);
        $state     = State::read($stateFile);

        $stateMeta = ['agent' => $flags['agent']];
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

        if ($flags['task_id'])   $stateMeta['task_id']          = $flags['task_id'];
        if ($totalMs   > 0) $stateMeta['total_duration_ms'] = $totalMs;
        if ($queueMs   > 0) $stateMeta['queue_ms']          = $queueMs;
        if ($pauseTotal > 0) $stateMeta['pause_ms']         = $pauseTotal;
        if ($waitTotal  > 0) $stateMeta['wait_ms']          = $waitTotal;
        if ($activeMs  > 0) $stateMeta['active_ms']         = $activeMs;
        if (($state['retry_count'] ?? 0) > 0) $stateMeta['retry_count'] = $state['retry_count'];
        if (($state['loop_count']  ?? 0) > 0) $stateMeta['loop_count']  = $state['loop_count'];
        if (($state['error_count'] ?? 0) > 0) $stateMeta['error_count'] = $state['error_count'];
        if (($state['pause_count'] ?? 0) > 0) $stateMeta['pause_count'] = $state['pause_count'];
        if (($state['wait_count']  ?? 0) > 0) $stateMeta['wait_count']  = $state['wait_count'];
        if (!empty($state['metrics'])) $stateMeta = array_merge($stateMeta, $state['metrics']);

        $meta = array_merge($stateMeta, $flags['meta']);

        $taskId = $flags['task_id'];
        $title  = $taskId
            ? "{$flags['agent']} | {$taskId} | {$event}"
            : "{$flags['agent']} | {$event}";

        $finalType       = self::validateType($flags['type']) ?: self::getEventType($event);
        $finalActionable = $flags['is_actionable'] !== ''
            ? strtolower($flags['is_actionable']) === 'true'
            : self::getActionableDefault($event);

        $payload = [
            'event'        => $event,
            'title'        => $title,
            'message'      => $message,
            'type'         => $finalType,
            'agent'        => $flags['agent'],
            'task_id'      => $taskId,
            'is_actionable'=> $finalActionable,
            'image_url'    => $flags['image_url'],
            'open_url'     => $flags['open_url'],
            'download_url' => $flags['download_url'],
            'tags'         => $flags['tags'],
            'ts'           => microtime(true),
            'meta'         => $meta,
        ];

        try {
            Notify::send($conf['token'], $conf['secret'], $payload);
            usleep(300_000); // 0.3s flush
        } catch (\Throwable) {
            // silent fail
        }
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

            case 'task.queue':
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                State::write($stateFile, [
                    'agent'          => $flags['agent'],
                    'task'           => $flags['task_id'],
                    'queued_at'      => (int)(microtime(true) * 1000),
                    'retry_count'    => 0,
                    'loop_count'     => 0,
                    'error_count'    => 0,
                    'pause_count'    => 0,
                    'wait_count'     => 0,
                    'pause_total_ms' => 0,
                    'wait_total_ms'  => 0,
                ]);
                self::sendNotify('task.queued', 'Task queued', $flags);
                echo "⏸  Queued: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.start':
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                $existing  = State::read($stateFile);
                if (isset($existing['queued_at'])) {
                    State::update($stateFile, ['start_time' => (int)(microtime(true) * 1000)]);
                } else {
                    State::write($stateFile, [
                        'agent'          => $flags['agent'],
                        'task'           => $flags['task_id'],
                        'start_time'     => (int)(microtime(true) * 1000),
                        'retry_count'    => 0,
                        'loop_count'     => 0,
                        'error_count'    => 0,
                        'pause_count'    => 0,
                        'wait_count'     => 0,
                        'pause_total_ms' => 0,
                        'wait_total_ms'  => 0,
                    ]);
                }
                self::sendNotify('task.started', 'Task started', $flags);
                echo "▶️  Started: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.progress':
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                self::sendNotify('task.progress', $msg, $flags);
                echo "⏳ Progress: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.stop':
                $flags = self::parseFlags($rest);
                self::sendNotify('task.stopped', 'Task stopped', $flags);
                echo "⏹  Stopped: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.pause':
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                $state     = State::read($stateFile);
                State::update($stateFile, [
                    'paused_at'   => (int)(microtime(true) * 1000),
                    'pause_count' => ($state['pause_count'] ?? 0) + 1,
                ]);
                self::sendNotify('task.paused', $msg, $flags);
                echo "⏸  Paused: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.resume':
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
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
                self::sendNotify('task.resumed', $msg, $flags);
                echo "▶️  Resumed: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.wait':
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                $state     = State::read($stateFile);
                State::update($stateFile, [
                    'wait_at'    => (int)(microtime(true) * 1000),
                    'wait_count' => ($state['wait_count'] ?? 0) + 1,
                ]);
                self::sendNotify('task.waiting', $msg, $flags);
                echo "⏳ Waiting: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.retry':
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                $state     = State::read($stateFile);
                State::update($stateFile, [
                    'retry_count' => ($state['retry_count'] ?? 0) + 1,
                ]);
                self::sendNotify('task.retry', 'Retrying task', $flags);
                echo "🔁 Retry: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.loop':
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                $state     = State::read($stateFile);
                $loopCount = ($state['loop_count'] ?? 0) + 1;
                State::update($stateFile, ['loop_count' => $loopCount]);
                self::sendNotify('task.loop', $msg, $flags);
                echo "🔄 Loop ({$loopCount}): {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.error':
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                $state     = State::read($stateFile);
                State::update($stateFile, [
                    'last_error'  => $msg,
                    'error_count' => ($state['error_count'] ?? 0) + 1,
                ]);
                self::sendNotify('task.error', $msg, $flags);
                fwrite(STDERR, "❌ Error: {$msg}\n");
                break;

            case 'task.fail':
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                self::sendNotify('task.failed', $msg, $flags);
                State::delete($stateFile);
                echo "💥 Failed: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.timeout':
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                self::sendNotify('task.timeout', $msg, $flags);
                State::delete($stateFile);
                echo "⏰ Timeout: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.cancel':
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                self::sendNotify('task.cancelled', $msg, $flags);
                State::delete($stateFile);
                echo "🚫 Cancelled: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.terminate':
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                self::sendNotify('task.terminated', $msg, $flags);
                State::delete($stateFile);
                echo "⚠️  Terminated: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.complete':
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                self::sendNotify('task.completed', $msg, $flags);
                State::delete($stateFile);
                echo "✅ Completed: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'metric':
                // notilens metric key=value [key=value ...] --agent <agent> --task <id>
                $pos       = self::positionalArgs($rest);
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                $state     = State::read($stateFile);
                $metrics   = $state['metrics'] ?? [];
                foreach ($pos as $kv) {
                    $eq = strpos($kv, '=');
                    if ($eq === false) continue;
                    $k = substr($kv, 0, $eq);
                    $v = substr($kv, $eq + 1);
                    $v = is_numeric($v) ? $v + 0 : $v;
                    // accumulate numeric, replace strings
                    if (is_numeric($v) && isset($metrics[$k]) && is_numeric($metrics[$k])) {
                        $metrics[$k] += $v;
                    } else {
                        $metrics[$k] = $v;
                    }
                }
                State::update($stateFile, ['metrics' => $metrics]);
                echo "📊 Metrics: " . implode(', ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($metrics), $metrics)) . "\n";
                break;

            case 'metric.reset':
                // notilens metric.reset [key] --agent <agent> --task <id>
                $pos       = self::positionalArgs($rest);
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
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

            case 'output.generate':
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                self::sendNotify('output.generated', $msg, $flags);
                break;

            case 'output.fail':
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                self::sendNotify('output.failed', $msg, $flags);
                break;

            case 'input.required':
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                self::sendNotify('input.required', $msg, $flags);
                break;

            case 'input.approve':
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                self::sendNotify('input.approved', $msg, $flags);
                break;

            case 'input.reject':
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                self::sendNotify('input.rejected', $msg, $flags);
                break;

            case 'track':
                $pos   = self::positionalArgs($rest);
                $event = $pos[0] ?? '';
                $msg   = $pos[1] ?? '';
                $flags = self::parseFlags(array_slice($rest, 2));
                self::sendNotify($event, $msg, $flags);
                echo "📡 Tracked: {$event}\n";
                break;

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
  notilens task.queue           --agent <agent> [--task <id>]
  notilens task.start     --agent <agent> [--task <id>]
  notilens task.progress  "msg" --agent <agent> [--task <id>]
  notilens task.loop      "msg" --agent <agent> [--task <id>]
  notilens task.retry           --agent <agent> [--task <id>]
  notilens task.stop            --agent <agent> [--task <id>]
  notilens task.pause     "msg" --agent <agent> [--task <id>]
  notilens task.resume    "msg" --agent <agent> [--task <id>]
  notilens task.wait      "msg" --agent <agent> [--task <id>]
  notilens task.error     "msg" --agent <agent> [--task <id>]
  notilens task.fail      "msg" --agent <agent> [--task <id>]
  notilens task.timeout   "msg" --agent <agent> [--task <id>]
  notilens task.cancel    "msg" --agent <agent> [--task <id>]
  notilens task.terminate "msg" --agent <agent> [--task <id>]
  notilens task.complete  "msg" --agent <agent> [--task <id>]

Output / Input:
  notilens output.generate "msg" --agent <agent> [--task <id>]
  notilens output.fail     "msg" --agent <agent> [--task <id>]
  notilens input.required  "msg" --agent <agent> [--task <id>]
  notilens input.approve   "msg" --agent <agent> [--task <id>]
  notilens input.reject    "msg" --agent <agent> [--task <id>]

Metrics (accumulated, auto-sent with every notification):
  notilens metric       tokens=512 cost=0.003 --agent <agent> --task <id>
  notilens metric.reset tokens               --agent <agent> --task <id>
  notilens metric.reset                      --agent <agent> --task <id>

Generic:
  notilens track <event> "msg" --agent <agent>

Options:
  --agent <name>
  --task <id>
  --type success|warning|urgent|info
  --meta key=value   (repeatable)
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
