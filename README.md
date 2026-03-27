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

`--task` is a semantic label (e.g. `email`, `report`). Each `task.start` creates an isolated run internally — concurrent executions of the same label never conflict.

### Task Lifecycle

```bash
notilens queue    --agent my-agent --task email
notilens start    --agent my-agent --task email
notilens progress "Fetching data"  --agent my-agent --task email
notilens loop     "Step 3 of 10"   --agent my-agent --task email
notilens retry    --agent my-agent --task email
notilens pause    "Rate limited"   --agent my-agent --task email
notilens resume   "Resuming"       --agent my-agent --task email
notilens wait     "Awaiting tool"  --agent my-agent --task email
notilens stop     --agent my-agent --task email
notilens complete "All done"       --agent my-agent --task email
notilens error    "Step 3 failed"  --agent my-agent --task email
notilens fail     "Unrecoverable"  --agent my-agent --task email
notilens timeout  "Took too long"  --agent my-agent --task email
notilens cancel   "User cancelled" --agent my-agent --task email
notilens terminate "Out of memory" --agent my-agent --task email
```

`task.start` prints the internal `run_id` to stdout.

### Output Events

```bash
notilens output.generate "Report ready"     --agent my-agent --task email
notilens output.fail     "Model unavailable" --agent my-agent --task email
```

### Input / Human-in-the-loop

```bash
notilens input.required "Please confirm" --agent my-agent --task email
notilens input.approve  "Confirmed"      --agent my-agent --task email
notilens input.reject   "Rejected"       --agent my-agent --task email
```

### Metrics

```bash
notilens metric tokens=512 cost=0.003 --agent my-agent --task email
notilens metric.reset tokens          --agent my-agent --task email
notilens metric.reset                 --agent my-agent --task email
```

### Custom Events

```bash
notilens track order.placed "Order #1234" --agent my-agent
```

---

# SDK

## Setup

```php
use NotiLens\NotiLens;

// Pass credentials directly
$agent = NotiLens::init('my-agent', token: 'YOUR_TOKEN', secret: 'YOUR_SECRET');

// Or via env vars: NOTILENS_TOKEN / NOTILENS_SECRET
$agent = NotiLens::init('my-agent');

// All options
$agent = NotiLens::init(
    agent:    'my-agent',
    token:    'YOUR_TOKEN',   // required (or env var)
    secret:   'YOUR_SECRET',  // required (or env var)
    stateTtl: 86400,          // optional — orphaned state TTL in seconds (default: 86400 / 24h)
);
```

## Task Lifecycle

`$agent->task($label)` creates a `Run` — an isolated execution context. Multiple concurrent runs of the same label never conflict.

```php
$run = $agent->task('email');  // create a run for the "email" task
$run->queue();                  // optional — pre-start signal
$run->start();                  // begin the run

$run->progress('Fetching data');
$run->loop('Processing item 42');
$run->retry();
$run->pause('Rate limited');
$run->resume('Resuming work');
$run->wait('Waiting for tool response');

$run->stop();
$run->error('Step failed, retrying');  // non-fatal, run continues

// Terminal — pick one
$run->complete('All done');
$run->fail('Unrecoverable error');
$run->timeout('Timed out after 5m');
$run->cancel('Cancelled by user');
$run->terminate('Force-killed');
```

## Input / Output

```php
$run->inputRequired('Approve deployment?');
$run->inputApproved('Approved');
$run->inputRejected('Rejected');

$run->outputGenerated('Report ready');
$run->outputFailed('Rendering failed');
```

## Metrics

```php
$run->metric('tokens', 512);
$run->metric('tokens', 128);   // now 640
$run->metric('cost', 0.003);

$run->resetMetrics('tokens');  // reset one
$run->resetMetrics();          // reset all
```

## Automatic Timing

NotiLens automatically tracks task timing. These fields are included in every notification's `meta` payload when non-zero:

| Field | Description |
|-------|-------------|
| `total_duration_ms` | Wall-clock time since `start` |
| `queue_ms` | Time between `queue` and `start` |
| `pause_ms` | Cumulative time spent paused |
| `wait_ms` | Cumulative time spent waiting |
| `active_ms` | Active time (`total − pause − wait`) |

## Generic Events

```php
$run->track('custom.event', 'Something happened');
$run->track('custom.event', 'With meta', ['key' => 'value']);

$agent->track('app.deployed', 'v2.3.1 deployed');
```

## Full Example

```php
use NotiLens\NotiLens;

$agent = NotiLens::init('summarizer', token: 'TOKEN', secret: 'SECRET');
$run   = $agent->task('report');
$run->start();

try {
    $run->progress('Fetching PDF');
    $result = $llm->complete($prompt);
    $run->metric('tokens', $result->usage->total_tokens);
    $run->outputGenerated('Summary ready');
    $run->complete('All done');
} catch (\Throwable $e) {
    $run->fail($e->getMessage());
}
```

## Requirements

- PHP >= 8.1

## License

MIT — [notilens.com](https://www.notilens.com)
