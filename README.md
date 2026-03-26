# NotiLens

Send notifications from AI agents and any PHP project to [NotiLens](https://www.notilens.com).

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

notilens task.start    --agent my-agent --task job_001
notilens task.progress "Fetching data"  --agent my-agent --task job_001
notilens task.loop     "Step 3 of 10"   --agent my-agent --task job_001
notilens task.retry    --agent my-agent --task job_001
notilens task.stop     --agent my-agent --task job_001
notilens task.complete "All done"       --agent my-agent --task job_001
notilens task.error    "Step 3 failed"  --agent my-agent --task job_001
notilens task.fail     "Unrecoverable"  --agent my-agent --task job_001
notilens task.timeout  "Took too long"  --agent my-agent --task job_001
notilens task.cancel   "User cancelled" --agent my-agent --task job_001
notilens task.terminate "Out of memory" --agent my-agent --task job_001
```

### AI Response Events

```bash
notilens ai.response.generate "Summary generated" --agent my-agent --task job_001
notilens ai.response.fail     "Model unavailable" --agent my-agent --task job_001
```

### Input / Human-in-the-loop

```bash
notilens input.required "Please confirm the output" --agent my-agent --task job_001
notilens input.approve  "Confirmed"                 --agent my-agent --task job_001
notilens input.reject   "Rejected"                  --agent my-agent --task job_001
```

### Generic Event

```bash
notilens emit order.placed "Order #1234" --agent my-agent --meta amount=99.99
notilens emit disk.space.full "Only 1GB remaining" --agent my-agent --type warning
```

### Metrics

```bash
notilens set.metrics 512 128 0.95 --agent my-agent --task job_001
#                    ^   ^   ^
#                    |   |   confidence score (optional)
#                    |   completion_tokens
#                    prompt_tokens
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
| `--confidence <0-1>` | Confidence score |

---

## Notification Types

Assigned automatically based on the event — can be overridden with `--type`.

| Type | Events |
|------|--------|
| `success` | `task.completed`, `ai.response.generated`, `input.approved` |
| `urgent` | `task.failed`, `task.timeout`, `task.error`, `task.terminated`, `ai.response.failed` |
| `warning` | `task.retrying`, `task.cancelled`, `input.required`, `input.rejected` |
| `info` | All others |

---

## Full Example

```bash
notilens init --agent summarizer --token MY_TOKEN --secret MY_SECRET

notilens task.start --agent summarizer --task job_42
notilens set.metrics 1024 256 0.95 --agent summarizer --task job_42
notilens task.complete "Summary ready" --agent summarizer --task job_42 \
  --meta input_file=report.pdf \
  --meta pages=12 \
  --open_url https://example.com/summary.pdf
```

---

# SDK

Use the SDK to send notifications directly from your PHP code.

## 1. Setup

```php
use NotiLens\NotiLens;

// Option A — pass credentials directly (required on first use)
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
$taskId = $agent->taskStart('job_001');          // required: auto-generates ID if null

$agent->taskProgress('Fetching records', $taskId);
$agent->taskLoop('Processing batch 2', $taskId);
$agent->taskRetry($taskId);
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

## 4. AI Response Events

```php
$agent->aiResponseGenerated('Summary: the document is about X', $taskId);
$agent->aiResponseFailed('Model timeout', $taskId);
```

## 5. Generic Events

```php
// Free-form events for anything beyond task lifecycle
$agent->emit('order.placed', 'Order #1234', meta: ['amount' => 99.99]);
$agent->emit('disk.space.full', 'Only 1GB remaining', level: 'warning');
$agent->emit('user.registered', 'New signup', meta: ['plan' => 'pro']);
```

## Full Example

```php
use NotiLens\NotiLens;

$agent  = NotiLens::init('summarizer', token: 'TOKEN', secret: 'SECRET');
$taskId = $agent->taskStart();

try {
    // ... your logic ...
    $agent->taskProgress('Fetching PDF', $taskId);
    // ... more logic ...
    $agent->taskComplete('Summary ready', $taskId);
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
