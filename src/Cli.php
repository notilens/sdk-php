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
            'agent'            => '',
            'task_id'          => '',
            'type'             => '',
            'meta'             => [],
            'image_url'        => '',
            'open_url'         => '',
            'download_url'     => '',
            'tags'             => '',
            'is_actionable'    => '',
            'confidence_score' => 0.0,
        ];

        $i = 0;
        $count = count($args);
        while ($i < $count) {
            switch ($args[$i]) {
                case '--agent':        $flags['agent']            = $args[++$i] ?? ''; break;
                case '--task':         $flags['task_id']          = $args[++$i] ?? ''; break;
                case '--type':         $flags['type']             = $args[++$i] ?? ''; break;
                case '--image_url':    $flags['image_url']        = $args[++$i] ?? ''; break;
                case '--open_url':     $flags['open_url']         = $args[++$i] ?? ''; break;
                case '--download_url': $flags['download_url']     = $args[++$i] ?? ''; break;
                case '--tags':         $flags['tags']             = $args[++$i] ?? ''; break;
                case '--is_actionable':$flags['is_actionable']    = $args[++$i] ?? ''; break;
                case '--confidence':   $flags['confidence_score'] = (float)($args[++$i] ?? 0); break;
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
        return match ($event) {
            'task.completed', 'response.generated', 'input.approved'         => 'success',
            'task.failed', 'task.timeout', 'response.failed',
            'validation.failed'                                               => 'urgent',
            'task.error', 'task.terminated', 'guardrail.triggered'           => 'important',
            'task.retrying', 'task.cancelled', 'input.required',
            'input.rejected'                                                  => 'warning',
            default                                                           => 'info',
        };
    }

    private static function getActionableDefault(string $event): bool
    {
        return in_array($event, [
            'task.error', 'task.failed', 'task.timeout', 'task.retrying',
            'ai.response.failed', 'ai.validation.failed', 'ai.guardrail.triggered',
            'input.required', 'input.rejected',
        ], true);
    }

    private static function validateType(string $t): string
    {
        return in_array($t, ['info', 'success', 'warning', 'urgent', 'important'], true) ? $t : '';
    }

    // ── Core send ─────────────────────────────────────────────────────────────

    private static function sendNotify(string $event, string $title, string $message, array $flags): void
    {
        $conf = Config::getAgent($flags['agent']);
        if (!$conf || empty($conf['endpoint']) || empty($conf['secret'])) {
            fwrite(STDERR, "❌ Agent not configured\n");
            return;
        }

        $stateFile = State::getFile($flags['agent'], $flags['task_id']);
        $state     = State::read($stateFile);

        $promptTokens     = $state['prompt_tokens']     ?? 0;
        $completionTokens = $state['completion_tokens'] ?? 0;

        $meta = array_merge([
            'duration_ms'       => $state['duration_ms']  ?? 0,
            'prompt_tokens'     => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens'      => $promptTokens + $completionTokens,
            'retry_count'       => $state['retry_count']  ?? 0,
            'confidence_score'  => $flags['confidence_score'],
            'loop_count'        => $state['loop_count']   ?? 0,
        ], $flags['meta']);

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
            'task_id'      => $flags['task_id'],
            'meta'         => $meta,
            'image_url'    => $flags['image_url'],
            'open_url'     => $flags['open_url'],
            'download_url' => $flags['download_url'],
            'tags'         => $flags['tags'],
            'is_actionable'=> $finalActionable,
        ];

        try {
            Notify::send($conf['endpoint'], $conf['secret'], $payload);
            usleep(300_000); // 0.3s — matches JS/Python
        } catch (\Throwable) {
            // silent fail
        }
    }

    private static function calcDuration(string $stateFile): int
    {
        $state = State::read($stateFile);
        return (int)(microtime(true) * 1000) - ($state['start_time'] ?? 0);
    }

    // ── Commands ──────────────────────────────────────────────────────────────

    public static function run(array $argv): void
    {
        $command = $argv[1] ?? '';
        $rest    = array_slice($argv, 2);

        switch ($command) {

            case 'add-agent':
                if (count($rest) < 4) {
                    fwrite(STDERR, "Usage: notilens add-agent <agent> <transport> <endpoint> <secret>\n");
                    exit(1);
                }
                Config::addAgent($rest[0], $rest[1], $rest[2], $rest[3]);
                echo "✔ Agent '{$rest[0]}' added\n";
                break;

            case 'task.start':
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                State::write($stateFile, [
                    'agent'       => $flags['agent'],
                    'task'        => $flags['task_id'],
                    'start_time'  => (int)(microtime(true) * 1000),
                    'retry_count' => 0,
                ]);
                self::sendNotify('task.started', "{$flags['agent']} | {$flags['task_id']} started", 'Task started', $flags);
                echo "▶️  Started: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.in_progress':
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                State::update($stateFile, ['duration_ms' => self::calcDuration($stateFile)]);
                self::sendNotify('task.in_progress', "{$flags['agent']} | {$flags['task_id']} running", 'Task in progress', $flags);
                echo "⏳ In Progress: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.stop':
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                $dur       = self::calcDuration($stateFile);
                State::update($stateFile, ['duration_ms' => $dur]);
                self::sendNotify('task.stopped', "{$flags['agent']} | {$flags['task_id']} stopped", 'Task stopped', $flags);
                echo "⏹  Stopped: {$flags['agent']} | {$flags['task_id']} ({$dur} ms)\n";
                break;

            case 'task.retry':
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                $state     = State::read($stateFile);
                State::update($stateFile, [
                    'duration_ms'  => self::calcDuration($stateFile),
                    'retry_count'  => ($state['retry_count'] ?? 0) + 1,
                ]);
                self::sendNotify('task.retrying', "{$flags['agent']} | {$flags['task_id']} retry", 'Retrying task', $flags);
                echo "🔁 Retry: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.loop':
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                $state     = State::read($stateFile);
                $loopCount = ($state['loop_count'] ?? 0) + 1;
                State::update($stateFile, ['duration_ms' => self::calcDuration($stateFile), 'loop_count' => $loopCount]);
                self::sendNotify('task.loop', "{$flags['agent']} | {$flags['task_id']} loop #{$loopCount}", $msg, $flags);
                echo "🔄 Loop ({$loopCount}): {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.error':
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                State::update($stateFile, ['duration_ms' => self::calcDuration($stateFile), 'last_error' => $msg]);
                self::sendNotify('task.error', "{$flags['agent']} | {$flags['task_id']} error", $msg, $flags);
                fwrite(STDERR, "❌ Error: {$msg}\n");
                break;

            case 'task.fail':
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                State::update($stateFile, ['duration_ms' => self::calcDuration($stateFile), 'failed' => true]);
                self::sendNotify('task.failed', "{$flags['agent']} | {$flags['task_id']} failed", $msg, $flags);
                State::delete($stateFile);
                echo "💥 Failed: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.timeout':
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                State::update($stateFile, ['duration_ms' => self::calcDuration($stateFile), 'timeout' => true]);
                self::sendNotify('task.timeout', "{$flags['agent']} | {$flags['task_id']} timeout", $msg, $flags);
                State::delete($stateFile);
                echo "⏰ Timeout: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.cancel':
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                State::update($stateFile, ['duration_ms' => self::calcDuration($stateFile), 'cancelled' => true]);
                self::sendNotify('task.cancelled', "{$flags['agent']} | {$flags['task_id']} cancelled", $msg, $flags);
                State::delete($stateFile);
                echo "🚫 Cancelled: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.terminate':
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                State::update($stateFile, ['duration_ms' => self::calcDuration($stateFile), 'terminated' => true]);
                self::sendNotify('task.terminated', "{$flags['agent']} | {$flags['task_id']} terminated", $msg, $flags);
                State::delete($stateFile);
                echo "⚠️  Terminated: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'task.complete':
                $pos       = self::positionalArgs($rest);
                $msg       = $pos[0] ?? '';
                $flags     = self::parseFlags($rest);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                State::update($stateFile, ['duration_ms' => self::calcDuration($stateFile)]);
                self::sendNotify('task.completed', "{$flags['agent']} | {$flags['task_id']} workflow", $msg, $flags);
                State::delete($stateFile);
                echo "✅ Completed: {$flags['agent']} | {$flags['task_id']}\n";
                break;

            case 'set.metrics':
                $pos       = self::positionalArgs($rest);
                $flags     = self::parseFlags($rest);
                $prompt    = (int)($pos[0] ?? 0);
                $completion= (int)($pos[1] ?? 0);
                $conf      = (float)($pos[2] ?? 0);
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                State::update($stateFile, ['prompt_tokens' => $prompt, 'completion_tokens' => $completion]);
                if ($conf) $flags['confidence_score'] = $conf;
                echo "📊 Metrics set: tokens({$prompt}/{$completion}) confidence({$conf})\n";
                break;

            case 'ai.response.generate':
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                self::sendNotify('ai.response.generated', "{$flags['agent']} | {$flags['task_id']} response", $msg, $flags);
                break;

            case 'ai.response.fail':
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                self::sendNotify('ai.response.failed', "{$flags['agent']} | {$flags['task_id']} response failed", $msg, $flags);
                break;

            case 'input.required':
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                self::sendNotify('input.required', "{$flags['agent']} | {$flags['task_id']} input required", $msg, $flags);
                break;

            case 'input.approve':
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                self::sendNotify('input.approved', "{$flags['agent']} | {$flags['task_id']} input approved", $msg, $flags);
                break;

            case 'input.reject':
                $pos   = self::positionalArgs($rest);
                $msg   = $pos[0] ?? '';
                $flags = self::parseFlags($rest);
                self::sendNotify('input.rejected', "{$flags['agent']} | {$flags['task_id']} input rejected", $msg, $flags);
                break;

            case 'emit':
                $pos       = self::positionalArgs($rest);
                $event     = $pos[0] ?? '';
                $msg       = $pos[1] ?? '';
                $flags     = self::parseFlags(array_slice($rest, 2));
                $stateFile = State::getFile($flags['agent'], $flags['task_id']);
                State::update($stateFile, ['duration_ms' => self::calcDuration($stateFile)]);
                self::sendNotify($event, "{$flags['agent']} | {$flags['task_id']} {$event}", $msg, $flags);
                echo "📡 Event emitted: {$event}\n";
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
  notilens add-agent <agent> <transport> <endpoint> <secret>

Core Commands:
  notilens task.start --agent <agent> [--task <id>]
  notilens task.in_progress --agent <agent> [--task <id>]
  notilens task.stop --agent <agent> [--task <id>]
  notilens task.retry --agent <agent> [--task <id>]
  notilens task.loop "msg" --agent <agent>
  notilens task.error "msg" --agent <agent> [--task <id>]
  notilens task.fail "msg" --agent <agent> [--task <id>]
  notilens task.timeout "msg" --agent <agent> [--task <id>]
  notilens task.cancel "msg" --agent <agent> [--task <id>]
  notilens task.terminate "msg" --agent <agent> [--task <id>]
  notilens task.complete "msg" --agent <agent> [--task <id>]
  notilens ai.response.generate "msg" --agent <agent>
  notilens ai.response.fail "msg" --agent <agent>
  notilens input.required "msg" --agent <agent>
  notilens input.approve "msg" --agent <agent>
  notilens input.reject "msg" --agent <agent>

Generic Event:
  notilens emit <event> "msg" --agent <agent>

Metrics:
  notilens set.metrics <prompt_tokens> <completion_tokens> [confidence] --agent <agent> [--task <id>]

Options:
  --agent <agent>
  --task <id>
  --type success|warning|urgent|important|info
  --meta key=value            (repeatable)
  --image_url <url>
  --open_url <url>
  --download_url <url>
  --tags "tag1,tag2"
  --is_actionable true|false
  --confidence <0-1>

Other:
  notilens version
USAGE;
    }
}
