# NotiLens

Send notifications from AI agents, background jobs, workflows, and any PHP project to [NotiLens](https://www.notilens.com).

Two ways to use it — pick one or both:

- **CLI** — for shell scripts, bash pipelines, any terminal workflow
- **SDK** — for PHP projects (import and call directly in code)

## Installation

```bash
composer global require notilens/notilens
```

Or per-project:

```bash
composer require notilens/notilens
```

---

# CLI

## Setup

Get your token and secret from the [NotiLens dashboard](https://www.notilens.com).

```bash
notilens init --agent my-agent --token YOUR_TOKEN --secret YOUR_SECRET
```

This saves credentials to `~/.notilens_config.json`. All future commands read from there — no need to pass token/secret again.

**Multiple agents** (each agent notifies a different topic):
```bash
notilens init --agent scraper --token TOKEN_A --secret SECRET_A
notilens init --agent mailer  --token TOKEN_B --secret SECRET_B
```

**List / remove agents:**
```bash
notilens agents
notilens remove-agent my-agent
```

---

## Commands

### Task Lifecycle

```bash
# required: --agent
# optional: --task (auto-generated if omitted)

notilens task.queue    --agent my-agent --task job_001
notilens task.start    --agent my-agent --task job_001
notilens task.progress "Fetching data"  --agent my-agent --task job_001
notilens task.loop     "Step 3 of 10"   --agent my-agent --task job_001
notilens task.retry    --agent my-agent --task job_001
notilens task.pause    "Rate limited"   --agent my-agent --task job_001
notilens task.resume   "Resuming"       --agent my-agent --task job_001
notilens task.wait     "Awaiting tool"  --agent my-agent --task job_001
notilens task.stop     --agent my-agent --task job_001
notilens task.complete "All done"       --agent my-agent --task job_001
notilens task.error    "Step 3 failed"  --agent my-agent --task job_001
notilens task.fail     "Unrecoverable"  --agent my-agent --task job_001
notilens task.timeout  "Took too long"  --agent my-agent --task job_001
notilens task.cancel   "User cancelled" --agent my-agent --task job_001
notilens task.terminate "Out of memory" --agent my-agent --task job_001
```

### Output Events

```bash
notilens output.generated "Report ready"    --agent my-agent --task job_001
notilens output.failed    "Model timed out" --agent my-agent --task job_001
```

### Input / Human-in-the-loop

```bash
notilens input.required "Please confirm the output" --agent my-agent --task job_001
notilens input.approve  "Confirmed"                 --agent my-agent --task job_001
notilens input.reject   "Rejected"                  --agent my-agent --task job_001
```

### Generic Event

```bash
notilens track order.placed "Order #1234" --agent my-agent --meta amount=99.99
notilens track disk.full "Only 1GB remaining" --agent my-agent --type warning
```

### Metrics

Pass any key=value pairs — numeric values accumulate across calls:

```bash
notilens metric tokens=512 cost=0.003 --agent my-agent --task job_001
notilens metric records=1500          --agent my-agent --task job_001

# Reset one metric
notilens metric.reset tokens --agent my-agent --task job_001

# Reset all metrics
notilens metric.reset --agent my-agent --task job_001
```

---

## Options

| Flag | Description |
|------|-------------|
| `--agent <name>` | Agent name **(required)** |
| `--task <id>` | Task ID (auto-generated if omitted) |
| `--type` | Override type: `info` `success` `warning` `urgent` |
| `--meta key=value` | Custom metadata (repeatable) |
| `--image_url <url>` | Attach an image |
| `--open_url <url>` | Link to open |
| `--download_url <url>` | Link to download |
| `--tags "tag1,tag2"` | Comma-separated tags |
| `--is_actionable true\|false` | Override actionable flag |

---

## Notification Types

Assigned automatically based on the event — can be overridden with `--type`.

| Type | Events |
|------|--------|
| `success` | `task.completed`, `output.generated`, `input.approved` |
| `urgent` | `task.failed`, `task.timeout`, `task.error`, `task.terminated`, `output.failed` |
| `warning` | `task.retry`, `task.cancelled`, `input.required`, `input.rejected` |
| `info` | All others |

---

## Full Example

```bash
notilens init --agent summarizer --token MY_TOKEN --secret MY_SECRET

notilens task.start    --agent summarizer --task job_42
notilens metric tokens=1024 --agent summarizer --task job_42
notilens metric cost=0.004  --agent summarizer --task job_42
notilens task.complete "Summary ready" --agent summarizer --task job_42 \
  --meta input_file=report.pdf \
  --open_url https://example.com/summary.pdf
```

---

# SDK

Use the SDK to send notifications directly from your PHP code.

## 1. Setup

```php
use NotiLens\NotiLens;

// Option A — pass credentials directly
$agent = NotiLens::init('my-agent', token: 'YOUR_TOKEN', secret: 'YOUR_SECRET');

// Option B — read from environment variables
// NOTILENS_TOKEN=xxx NOTILENS_SECRET=yyy
$agent = NotiLens::init('my-agent');

// Option C — read from saved CLI config (~/.notilens_config.json)
// after running: notilens init --agent my-agent --token TOKEN --secret SECRET
$agent = NotiLens::init('my-agent');
```

## 2. Task Lifecycle

```php
$taskId = $agent->taskQueued();                  // pre-start signal, returns task_id
$taskId = $agent->taskStart('job_001');          // auto-generates ID if null

$agent->taskProgress('Fetching records', $taskId);
$agent->taskLoop('Processing batch 2', $taskId);
$agent->taskRetry($taskId);
$agent->taskPaused('Waiting for rate limit', $taskId);    // non-terminal warning
$agent->taskResumed('Resuming work', $taskId);             // non-terminal info
$agent->taskWaiting('Waiting for tool response', $taskId); // non-terminal warning
$agent->taskError('Non-fatal error', $taskId);   // task continues
$agent->taskComplete('All done', $taskId);        // terminal — clears state
$agent->taskFail('Unrecoverable', $taskId);       // terminal
$agent->taskTimeout('Exceeded 30s', $taskId);     // terminal
$agent->taskCancel('User cancelled', $taskId);    // terminal
$agent->taskTerminate('OOM', $taskId);            // terminal
$agent->taskStop($taskId);
```

## 3. Input / Human-in-the-loop

```php
$agent->inputRequired('Please confirm the output', $taskId);
$agent->inputApproved('User confirmed', $taskId);
$agent->inputRejected('User rejected', $taskId);
```

## 4. Output Events

```php
// Use for any kind of generated output — AI response, report, file, API result
$agent->outputGenerated('Summary ready', $taskId);
$agent->outputFailed('Model timed out', $taskId);
```

## 5. Metrics

Track any numeric or string values — accumulated automatically and included in every notification.

```php
$agent->metric('tokens', 350);    // set
$agent->metric('tokens', 210);    // now 560 (numeric values accumulate)
$agent->metric('cost', 0.0012);
$agent->metric('records', 1500);
$agent->metric('model', 'gpt-4'); // strings are replaced, not accumulated

$agent->resetMetrics('tokens');   // reset one metric
$agent->resetMetrics();           // reset all metrics
```

Metrics are auto-included in `meta.metrics` on every `send()` call.

## Automatic Timing

NotiLens automatically tracks task timing. These fields are included in every notification's `meta` payload when non-zero:

| Field | Description |
|-------|-------------|
| `total_duration_ms` | Wall-clock time since `task_start` |
| `queue_ms` | Time between `task_queue` and `task_start` |
| `pause_ms` | Cumulative time spent paused |
| `wait_ms` | Cumulative time spent waiting |
| `active_ms` | Active time (`total − pause − wait`) |

---

## 6. Generic Events

```php
// Free-form events for anything beyond task lifecycle
$agent->track('order.placed', 'Order #1234', meta: ['amount' => 99.99]);
$agent->track('disk.full', 'Only 1GB remaining', level: 'warning');
$agent->track('user.registered', 'New signup', meta: ['plan' => 'pro']);
```

## Full Example

```php
use NotiLens\NotiLens;

$agent  = NotiLens::init('summarizer', token: 'TOKEN', secret: 'SECRET');
$taskId = $agent->taskStart();

try {
    $agent->taskProgress('Fetching PDF', $taskId);

    $result = $llm->complete($prompt);
    $agent->metric('tokens', $result->usage->total_tokens);
    $agent->metric('cost', $result->usage->cost);

    $agent->outputGenerated('Summary ready', $taskId);
    $agent->taskComplete('All done', $taskId);
} catch (\Throwable $e) {
    $agent->taskFail($e->getMessage(), $taskId);
}
```

---

## Requirements

- PHP >= 8.1
- Composer

## License

MIT — [notilens.com](https://www.notilens.com)
