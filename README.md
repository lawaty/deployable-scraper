# Deployable PriceCharting Scraper

This directory contains the slave agent for the distributed scraper. The single
entry point is `agent.php`.

- **Polling mode (primary):** the agent polls central for leased jobs, runs them,
  and posts results back.
- **Manual mode:** the agent runs an explicit JSON file or a single URL/slug
  and writes local output only; it does not contact central.

Both modes use the same scraper, anti-ban controls, and output layout through
the single `agent.php` entry point.

## Requirements

- PHP 8.1+
- Extensions: `curl`, `dom`, `libxml`, `mbstring`, `json`
- No Composer, no WordPress, no Redis, no database
- Writable `state/` and `output/` directories

## Install

Copy this directory to each scraping server:

```bash
rsync -av deployable-scraper/ user@scraper-host:/opt/deployable-scraper/
ssh user@scraper-host 'chmod +x /opt/deployable-scraper/agent.php'
```

The runtime user must be able to write `state/` and `output/` (created on first
run).

## Usage

### Primary: polling central

Register the server in the dashboard (**Scrapers -> Servers -> Add Server**),
then copy the one-time HMAC secret to the slave.

`secret.txt` is the per-server HMAC credential for `agent.php`. It is a plain
text file holding that secret; the agent uses it to sign requests to `/next`,
`/results`, and `/verify`. The secret itself is never sent over the wire.

- **Default path:** `<deployable-scraper-dir>/secret.txt` (next to `agent.php`),
  not the current working directory.
- **Override:** `--secret-file=PATH`. `--secret=STRING` also works but exposes
  the value in shell history/process list, so it is not recommended.
- **Contents:** only the secret string. No `KEY=`, no quotes, no JSON. A
  trailing newline is trimmed.
- **Permissions:** mode `0600`, owned by the agent runtime user.
- **Create it without putting the secret on a command line:**

```bash
install -m 600 /dev/null /opt/deployable-scraper/secret.txt
# paste only the secret into that file, then save
chown <agent-user>:<agent-user> /opt/deployable-scraper/secret.txt
```

Verify credentials:

```bash
php agent.php \
  --central=https://superminty.com/api \
  --server-id=<id> \
  --secret-file=/opt/deployable-scraper/secret.txt \
  --verify
```

Run long-lived:

```bash
php agent.php \
  --central=https://superminty.com/api \
  --server-id=<id> \
  --secret-file=/opt/deployable-scraper/secret.txt
```

Or run one claim/execute/post cycle from cron:

```bash
php agent.php \
  --central=https://superminty.com/api \
  --server-id=<id> \
  --secret-file=/opt/deployable-scraper/secret.txt \
  --once
```

No jobs file is needed in this mode. Central assigns the batch size from the
server's capacity; the agent polls `/api/scraper/next` and posts to
`/api/scraper/results`.

While a batch is running, the agent sends a heartbeat about once a minute
(with its recent log tail) so central can distinguish `processing` from a
disconnected slave. A server with leased work but no recent heartbeat is shown
as `stalled`; with no leased work and no heartbeat it is `offline`.

In the dashboard, open the server's **Logs** action (terminal icon) to inspect
the uploaded tail.

Local agent logs live under **`state/logs/scrape-YYYY-MM-DD.log`** (relative to
`agent.php`), not in a top-level `logs/` directory. If `--state=DIR` is used,
they live under `DIR/logs/`. The agent also mirrors log lines to STDERR, so
systemd users can inspect them with `journalctl -u deployable-scraper-agent`.

Caveats:

- Do not commit, email, or log `secret.txt`. Treat it like a password.
- Rotating the secret invalidates the old value immediately.
- A missing or empty `secret.txt` makes `agent.php` exit with status `2`.

See `DEPLOY.md` for systemd/cron and registration details.

### Manual mode

Manual mode runs jobs from a JSON file or a single slug/URL and writes local
JSON output. It does **not** contact central, does not use `secret.txt`, and is
intended for offline/one-off runs.

```bash
php agent.php --input=jobs.json
```

Single job:

```bash
php agent.php --slug=super-mario-64 --category=nintendo-64 --game-id=1234
php agent.php --url=https://www.pricecharting.com/game/playstation/some-game --slug=some-game
```

`--game-id` is optional and only passed through into the result document.

Dry run (no network, no writes):

```bash
php agent.php --input=jobs.json --dry-run
```

Sharding:

```bash
php agent.php --input=jobs.json --offset=0 --limit=100
php agent.php --input=jobs.json --offset=100 --limit=100
```

Skip already-scraped successes:

```bash
php agent.php --input=jobs.json --skip-existing
```

`--skip-existing` is handled inside `JobRunner::run()`: jobs whose success
document already exists are not rescraped. They appear in `manifest.jsonl` with
`"status":"skipped","error":"already_exists"` and keep the manifest `index`
aligned with the input job list.

Config override:

```bash
php agent.php --input=jobs.json --config=/etc/scraper/pricecharting.php
```

`--input` cannot be combined with `--slug`/`--url`. `--slug` without `--url`
requires `--category`. `--once` is not allowed in manual mode.

## Options

| Option | Mode | Meaning |
|---|---|---|
| `--central=URL` | polling | Central API base URL |
| `--server-id=INT` | polling | Registered server id |
| `--secret-file=PATH` | polling | HMAC secret file (default `<script-dir>/secret.txt`) |
| `--secret=STRING` | polling | Secret override (not recommended) |
| `--config=PATH` | both | PHP config file returning an array |
| `--poll-interval=INT` | polling | Seconds between polls when idle (default 30, min 5) |
| `--output=DIR` | both | Output directory (default `<script-dir>/output`) |
| `--state=DIR` | both | State directory (default `<script-dir>/state`) |
| `--max-retries=INT` | both | Scraper retries per request (default 3) |
| `--interval=FLOAT` | both | Override request interval seconds |
| `--dlq-dir=DIR` | polling | Result DLQ directory (default `state/dlq`) |
| `--once` | polling | Claim/run/post once then exit |
| `--verify` | polling | POST `/scraper/verify` then exit |
| `--input=PATH` | manual | JSON jobs file |
| `--slug=SLUG` | manual | Single job slug |
| `--category=CAT` | manual | Category for `--slug` |
| `--url=URL` | manual | Explicit PriceCharting URL |
| `--expected-title=T` | manual | Expected game title |
| `--game-id=ID` | manual | Opaque game id passthrough |
| `--limit=N` | manual | First N jobs after offset |
| `--offset=N` | manual | Skip first N jobs |
| `--skip-existing` | manual | Skip jobs whose success doc exists |
| `--dry-run` | manual | Normalize and print jobs; no network/writes |
| `--help` | both | Show help |

The default request interval is **30 seconds**, read from
`config/pricecharting.php`. `--interval` overrides it; the minimum enforced is
0.5s.

## Jobs file schema

Used only by manual mode (`--input`).

```json
{
  "jobs": [
    {"game_id": 1234, "slug": "super-mario-64", "category": "nintendo-64", "expected_title": "Super Mario 64"},
    {"game_id": 5678, "slug": "the-legend-of-zelda-ocarina-of-time", "category": "nintendo-64", "expected_title": "The Legend of Zelda: Ocarina of Time"},
    {"slug": "some-game", "url": "https://www.pricecharting.com/game/playstation/some-game"}
  ]
}
```

A top-level JSON array of job objects is also accepted. Each job requires a
non-empty `slug` and either `url` or `category`. Optional: `expected_title`
(string|null), `game_id` (int|null). `game_id` is required when posting to
central.

## Output layout

Both modes write local output through `JobRunner`:

```
output/
  <category-or-_url>/
    <slug>.json          # success document
  _failed/
    <category-or-_url>/
      <slug>.json        # failure document
  manifest.jsonl         # one compact JSON line per processed job
  run-summary.json       # run summary (includes input_count among its keys)
```

Polling mode posts successful docs to central automatically. Manual mode writes
local output only and never posts.

## Result document schema

Success:

```json
{
  "schema_version": 1,
  "source": "pricecharting",
  "run_id": "...",
  "scraped_at": "2026-10-25T12:34:56+00:00",
  "job": {"game_id": 123, "slug": "...", "category": "...", "expected_title": "...", "url": "https://..."},
  "status": "success",
  "error": null,
  "http_status": 200,
  "http_attempts": 1,
  "blocked": false,
  "price_guide": {"...": "..."},
  "recent_sales": [{"date":"...","title":"...","link":"...","price":"..."}]
}
```

Failure: `status:"failed"`, `error:"..."`, plus `http_status`, `http_attempts`,
`blocked`; `price_guide` and `recent_sales` are omitted.

## Result delivery

Polling mode uses `POST /api/scraper/results` with the server HMAC credential.

- Each entry is `{job_id, attempt, doc}` tied to a leased job.
- Central verifies the lease, owner, and attempt before enqueueing.
- Manual mode does not post; results remain under `output/` for manual use.

## Anti-ban and state

File-backed request lock, retries/backoff, block breaker, per-run and per-day
budgets, honest User-Agent, no proxies. State lives under `state/`:

- `state.json` / `state.lock` — block breaker + daily counters
- `pricecharting-request.lock` — global request lock
- `anomalies/` — anomalous raw HTML dumps (can be deleted freely)
- `logs/` — dated log files
- `dlq/` — result posting DLQ (`YYYY-MM-DD.jsonl`)

Deleting `state/` resets budget/breaker state.

## Scheduling and resume

Polling mode: systemd long-running or cron `--once`; the central queue is the
source of truth, so restarts resume naturally.

Manual mode: run from cron when needed:

```cron
0 2 * * * cd /opt/deployable-scraper && php agent.php --input=jobs.json --skip-existing >> /var/log/scraper.log 2>&1
```

Re-running is safe: success documents are overwritten, failures are re-attempted,
and `--skip-existing` avoids rescraping successes.

## Exit codes

Manual mode:

- `0` — success (no failures, no early stop)
- `1` — one or more failed jobs
- `2` — stopped early (block breaker or budget)
- `3` — fatal (bad args/input/config)

Polling mode:

- `0` — clean exit (`--once` empty, or graceful shutdown)
- `1` — authentication failure, disabled server under `--once`, or fatal polling error
- `2` — bad arguments/config

## Troubleshooting

- `FATAL: ...` on STDERR with exit 3 in manual mode means bad arguments, input,
  or config.
- Missing/incorrect secret: ensure `secret.txt` mode `0600` and contents match
  registration.
- `401 (Authentication failed)`: check the server id and secret.
- `403 (Server disabled)`: enable the server in the dashboard; polling sleeps
  300s.
- `429 (Queue full)`: central result queue is saturated; the agent respects
  `Retry-After`.
- No jobs returned: the server may be at capacity or the queue may be empty.
- Empty output: check `state/` permissions and `logs/`.
- Repeated 403/429: the block breaker may be open; wait or delete
  `state/state.json`.

## Intentionally dropped from production

Database persistence, game ID remapping, dedup, negative eBay IDs, retry queue,
cursors, and WordPress integration are intentionally NOT in this scraper. That
work belongs on the central server, which consumes the result documents.
