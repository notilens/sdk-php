# NotiLens

Send alerts to NotiLens from PHP scripts, apps, and AI agents.

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

## 1. Setup

Get your token and secret from the [NotiLens dashboard](https://www.notilens.com).

```bash
notilens init --name my-app --token YOUR_TOKEN --secret YOUR_SECRET
```

This saves credentials to `~/.notilens_config.json`. All future commands read from there — no need to pass token/secret again.

**Multiple sources** (each notifies a different topic):
```bash
notilens init --name scraper --token TOKEN_A --secret SECRET_A
notilens init --name mailer  --token TOKEN_B --secret SECRET_B
```

**List / remove:**
```bash
notilens sources
notilens remove-source my-app
```

---

## 2. Notify

The simplest way to send a notification — no task or run context needed:

```bash
notilens notify order.placed    "Order #1234"      --name my-app
notilens notify disk.space.full "Only 1GB left"    --name my-app --type warning
notilens notify report.ready    "Report is ready"  --name my-app --download_url https://example.com/report.pdf
```

---

## 3. Commands

`--task` is a semantic label (e.g. `email`, `report`). Each `task.start` creates an isolated run internally — concurrent executions of the same label never conflict.

### Task Lifecycle

```bash
notilens queue    --name my-app --task email
notilens start    --name my-app --task email
notilens progress "Fetching data"  --name my-app --task email
notilens loop     "Step 3 of 10"   --name my-app --task email
notilens retry    --name my-app --task email
notilens pause    "Rate limited"   --name my-app --task email
notilens resume   "Resuming"       --name my-app --task email
notilens wait     "Awaiting tool"  --name my-app --task email
notilens stop     --name my-app --task email
notilens complete "All done"       --name my-app --task email
notilens error    "Step 3 failed"  --name my-app --task email
notilens fail     "Unrecoverable"  --name my-app --task email
notilens timeout  "Took too long"  --name my-app --task email
notilens cancel   "User cancelled" --name my-app --task email
notilens terminate "Out of memory" --name my-app --task email
```

`task.start` prints the internal `run_id` to stdout.

### Output Events

```bash
notilens output.generate "Report ready"     --name my-app --task email
notilens output.fail     "Model unavailable" --name my-app --task email
```

### Input / Human-in-the-loop

```bash
notilens input.required "Please confirm" --name my-app --task email
notilens input.approve  "Confirmed"      --name my-app --task email
notilens input.reject   "Rejected"       --name my-app --task email
```

### Metrics

```bash
notilens metric tokens=512 cost=0.003 --name my-app --task email
notilens metric.reset tokens          --name my-app --task email
notilens metric.reset                 --name my-app --task email
```

### Custom Events

```bash
notilens track order.placed "Order #1234" --name my-app
```

---

# SDK

## 1. Setup

```php
use NotiLens\NotiLens;

// Pass credentials directly
$nl = NotiLens::init('my-app', token: 'YOUR_TOKEN', secret: 'YOUR_SECRET');

// Or via env vars: NOTILENS_TOKEN / NOTILENS_SECRET
$nl = NotiLens::init('my-app');

// All options
$nl = NotiLens::init(
    name:     'my-app',
    token:    'YOUR_TOKEN',   // required (or env var)
    secret:   'YOUR_SECRET',  // required (or env var)
    stateTtl: 86400,          // optional — orphaned state TTL in seconds (default: 86400 / 24h)
);
```

---

## 2. Notify

The simplest way to send a notification — no task or run context needed:

```php
$nl->notify('order.placed', 'Order #1234');
$nl->notify('disk.space.full', 'Only 1GB left', level: 'warning');
$nl->notify('report.ready', 'Your report is ready',
    downloadUrl: 'https://example.com/report.pdf',
    tags: 'report,weekly',
);

// Also available on a run
$run->notify('deploy.done', 'Deployed to production',
    openUrl: 'https://example.com/deploy/123',
);
```

---

## 3. Task Lifecycle

`$nl->task($label)` creates a `Run` — an isolated execution context. Multiple concurrent runs of the same label never conflict.

```php
$run = $nl->task('email');  // create a run for the "email" task
$run->queue();               // optional — pre-start signal
$run->start();               // begin the run

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

---

## 4. Input / Output

```php
$run->inputRequired('Approve deployment?');
$run->inputApproved('Approved');
$run->inputRejected('Rejected');

$run->outputGenerated('Report ready');
$run->outputFailed('Rendering failed');
```

---

## 5. Metrics

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

---

## 6. Custom Events

```php
$run->track('custom.event', 'Something happened');
$run->track('custom.event', 'With meta', ['key' => 'value']);

$nl->track('app.deployed', 'v2.3.1 deployed');
```

---

## Full Example

```php
use NotiLens\NotiLens;

$nl  = NotiLens::init('summarizer', token: 'TOKEN', secret: 'SECRET');
$run = $nl->task('report');
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
