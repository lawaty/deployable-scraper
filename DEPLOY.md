# Deployable Scraper - Slave Agent

This is the primary deployment mode: the agent polls central. Manual mode is
also built into the same `agent.php` (`--input`/`--slug`/`--url`), but it writes
local output only and does not contact central.

## Requirements
- PHP 8.1+
- Extensions: curl, dom, libxml, mbstring, json
- No composer, DB, or Redis required on the slave

## Setup
1. Copy the `deployable-scraper/` directory to the slave (e.g. `/opt/deployable-scraper/`).
2. Make the agent executable: `chmod +x agent.php`
3. Create writable directories: `mkdir -p state output state/dlq logs` (the agent will create them, but ensure permissions are correct)
4. Create `secret.txt` — the per-server HMAC credential used by `agent.php` to
   sign `/next`, `/results`, and `/verify`. It is **not** a dashboard password.
   Create it without putting the secret on a command line (so it never lands in
   shell history or the process list):

   ```bash
   install -m 600 /dev/null /opt/deployable-scraper/secret.txt
   # paste only the secret into that file, then save
   chown <agent-user>:<agent-user> /opt/deployable-scraper/secret.txt
   ```

   Contents must be only the raw secret string: no `KEY=`, no quotes, no JSON.
   A single trailing newline is fine (whitespace is trimmed). Mode `0600`,
   owned by the agent runtime user. Override the path with `--secret-file=PATH`;
   `--secret=STRING` also works but exposes the value in shell history/process
   list, so it is not recommended. Manual mode writes local output only and does
   not use this file.

## Registration
1. In the dashboard (Scrapers section), register the server:
   - Name
   - Host (IP/DNS). For central-host deployment use `localhost`/`127.0.0.1`
   - Capacity (batch size: central leases up to this many games per poll)
2. Copy the one-time secret from the registration into `secret.txt` on the slave.
   The dashboard shows it in a popup on create/rotate — copy it immediately.
   Rotation generates a new secret and invalidates the old one at once. The
   plaintext is returned by create/rotate and by the explicit RootOnly
   `GET /api/scraper/servers/{id}/secret` reveal endpoint, but never in server
   list/get responses. Verify afterwards:

   ```bash
   php agent.php --central=https://superminty.com/api --server-id=<id> --secret-file=/opt/deployable-scraper/secret.txt --verify
   ```

## Testing
```bash
php agent.php --central=https://superminty.com/api --server-id=<id> --secret-file=/opt/deployable-scraper/secret.txt --verify
```
A 2xx response indicates successful authentication/verification. Non-2xx will exit 1.

## Running
### systemd (recommended)
Create `/etc/systemd/system/deployable-scraper-agent.service`:
```ini
[Unit]
Description=Deployable Scraper Slave Agent
After=network.target

[Service]
Type=simple
User=www-data
ExecStart=/usr/bin/php /opt/deployable-scraper/agent.php --central=https://superminty.com/api --server-id=<id> --secret-file=/opt/deployable-scraper/secret.txt
Restart=always
RestartSec=30
WorkingDirectory=/opt/deployable-scraper

[Install]
WantedBy=multi-user.target
```
```bash
systemctl daemon-reload
systemctl enable --now deployable-scraper-agent
```

### cron (--once)
For scheduled runs:
```cron
*/1 * * * * /usr/bin/php /opt/deployable-scraper/agent.php --central=https://superminty.com/api --server-id=<id> --secret-file=/opt/deployable-scraper/secret.txt --once >> /var/log/deployable-scraper-agent.log 2>&1
```

## Manual mode

Run an explicit JSON file or a single slug/URL through the same agent:

```bash
php agent.php --input=jobs.json
```

- Manual mode writes local JSON output only; it does not post to central and does
  not use `secret.txt`.
- `--dry-run` prints the normalized jobs and does nothing else.
- `--game-id` is optional and is only passed through into the result document.

## Running on the central host
Register a server row with host `localhost`/`127.0.0.1` and run a second agent process on the central host. It uses the same pull protocol and needs no special privileges.

## Firewall
Outbound HTTPS to the central API only. No inbound ports required for pull mode.

## Tuning
- Central server assigns the batch size from the server's `capacity` (max concurrently leased games); the agent just polls and runs the batch.
- `--poll-interval`: seconds between polls when idle (min 5)
- Scraper config (`config/pricecharting.php` or `--config=`): `request_interval_seconds`, per-run/day budgets, block breaker settings remain authoritative per slave

## Slave logs
Local logs are written to `state/logs/scrape-YYYY-MM-DD.log` (or
`DIR/logs/...` when `--state=DIR` is used), not to a top-level `logs/`
directory. The agent also mirrors them to STDERR, so systemd users can use
`journalctl -u deployable-scraper-agent`.

The agent sends a heartbeat about once a minute while a batch is running, and
uploads the last 100 log lines with `/next`, `/results`, and heartbeats. This
lets central show `processing` instead of `offline` during long batches.
In the dashboard, use the terminal icon in the server row to open the uploaded
log tail.

## Result delivery
The agent posts result docs to `/api/scraper/results`. The central side fast-acks to Redis and the ingest worker persists/categorizes. 
- Local DLQ: failed chunks after 8 attempts are written as one JSON line per result to `$dlqDir/YYYY-MM-DD.jsonl` with `{"result":..., "error":..., "failed_at":"..."}` (locked with LOCK_EX). Inspect and replay/resolve DLQ entries manually if needed.

## Troubleshooting
- **401 (Authentication failed)**: Check that the secret and server id match the registration.
- **403 (Server disabled)**: Server disabled in central; agent sleeps 300s.
- **429 (Queue full)**: Central result queue is saturated; the agent respects `Retry-After` and retries later.
- **Budgets/block breaker**: Check logs for `stopped early: budget` or `stopped early: block_breaker`. These are authoritative per slave.
- **No jobs returned**: server capacity may be fully leased or the queue is empty; check the dashboard queue/activity.