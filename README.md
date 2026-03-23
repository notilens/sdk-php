# NotiLens

CLI tool for sending AI agent task lifecycle notifications to [NotiLens](https://www.notilens.com).

## Installation

```bash
composer global require notilens/notilens
```

Or per-project:

```bash
composer require notilens/notilens
```

## Setup

Register your agent with its endpoint and secret from the NotiLens dashboard:

```bash
notilens add-agent <agent-name> http <endpoint> <secret>
```

**Example:**
```bash
notilens add-agent my-agent http https://hook.notilens.com/webhook/[TOPIC_TOKEN]/send [TOPIC_SECRET]
```

---

## Usage

### Task Lifecycle

```bash
# Start a task (auto-generates task ID if omitted)
notilens task.start --agent my-agent --task task_001

# Mark in progress
notilens task.in_progress --agent my-agent --task task_001

# Complete successfully
notilens task.complete "Processed 100 records" --agent my-agent --task task_001

# Report an error (non-terminal, task continues)
notilens task.error "Step 3 flow Crashed" --agent my-agent --task task_001

# Retry
notilens task.retry --agent my-agent --task task_001

# Loop iteration
notilens task.loop "Processing step 1" --agent my-agent --task task_001

# Fail (terminal)
notilens task.fail "Worker failed" --agent my-agent --task task_001

# Timeout (terminal)
notilens task.timeout "Took more than 15 secs" --agent my-agent --task task_001

# Cancel (terminal)
notilens task.cancel "User cancelled" --agent my-agent --task task_001

# Terminate (terminal)
notilens task.terminate "Out of memory" --agent my-agent --task task_001
```

### AI Response Events

```bash
notilens ai.response.generate "Summary generated" --agent my-agent --task task_001
notilens ai.response.fail "Model unavailable" --agent my-agent --task task_001
```

### Input Events

```bash
notilens input.required "Please confirm the output" --agent my-agent --task task_001
notilens input.approve "User approved" --agent my-agent --task task_001
notilens input.reject "User rejected" --agent my-agent --task task_001
```

### Metrics

```bash
notilens set.metrics 512 128 0.95 --agent my-agent --task task_001
```

### Generic Event

```bash
notilens emit "data.processed" "Ingested 500 rows" --agent my-agent --task task_001
```

---

## Options

| Flag | Description |
|------|-------------|
| `--agent <name>` | Agent name (required) |
| `--task <id>` | Task ID (auto-generated if omitted) |
| `--type` | Override notification type: `info` `success` `warning` `urgent` `important` |
| `--meta key=value` | Custom metadata (repeatable) |
| `--image_url <url>` | Attach an image |
| `--open_url <url>` | Link to open |
| `--download_url <url>` | Link to download |
| `--tags "tag1,tag2"` | Comma-separated tags |
| `--is_actionable true\|false` | Override actionable flag |
| `--confidence <0-1>` | Confidence score |

---

## Notification Types

| Type | Events |
|------|--------|
| `success` | `task.completed`, `response.generated`, `input.approved` |
| `urgent` | `task.failed`, `task.timeout`, `response.failed` |
| `important` | `task.error`, `task.terminated` |
| `warning` | `task.retrying`, `task.cancelled`, `input.required`, `input.rejected` |
| `info` | All others |

---

## Full Example

```bash
notilens add-agent summarizer http https://hook.notilens.com/webhook/my_topic_token/send mysecret

notilens task.start --agent summarizer --task job_42
notilens set.metrics 1024 256 --agent summarizer --task job_42
notilens task.complete "Summary ready" --agent summarizer --task job_42 \
  --meta input_file=report.pdf \
  --meta pages=12 \
  --open_url https://example.com/summary.pdf
```

---

## Requirements

- PHP >= 8.1
- Composer

## License

MIT — [notilens.com](https://www.notilens.com)
