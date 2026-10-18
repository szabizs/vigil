# Running the relay

The relay is `php artisan vigil:agent`. It is a small long-running daemon, one per application server. It receives frames from your PHP processes over loopback, writes them to a disk spool and uploads them to the product. One relay serves every PHP-FPM worker, queue worker and scheduler on that server.

Internally the command requires `agent/build/agent.phar`, a scoped build with its own ReactPHP dependencies, so it never conflicts with your application's packages.

## Command and options

```bash
php artisan vigil:agent                       # everything from config / .env
php artisan vigil:agent --listen-on=127.0.0.1:2407 --spool=/var/spool/vigil -v
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--listen-on=` | `VIGIL_INGEST_URI` (`127.0.0.1:2407`) | Address to listen on. |
| `--spool=` | `VIGIL_AGENT_SPOOL_PATH` (`storage/app/vigil/spool`) | Spool directory. |
| `--auth-connection-timeout=` | `5` | Connect timeout of the startup token check, seconds. |
| `--auth-timeout=` | `10` | Timeout of the startup token check, seconds. |
| `--ingest-connection-timeout=` | `5` | Connect timeout of an upload, seconds. |
| `--ingest-timeout=` | `30` | Timeout of an upload, seconds. |
| `--environment=` | `VIGIL_ENVIRONMENT` | Environment sent to the product. |
| `--server=` | `VIGIL_SERVER` (hostname) | Server name sent to the product (`Vigil-Server` header). |
| `--silent` | | Log only CRITICAL lines. |
| `-q`, `--quiet` | | Log errors and critical lines only (no INFO). |
| `-v` | | Also log DEBUG lines (each upload, each signature check). |

All other settings (segment size, spool cap, concurrency, drain time) come from the `vigil.agent.*` configuration, see [Configuration](04-configuration.md#relay-agent).

The relay also needs `VIGIL_TOKEN` and `VIGIL_BASE_URL`. Without them it exits with code 1 and a CRITICAL line (`The VIGIL_TOKEN environment variable has not been configured.` or `The VIGIL_BASE_URL environment variable has not been configured.`).

### Log levels

Logs go to stdout (DEBUG, INFO) and stderr (ERROR, CRITICAL), one line each, prefixed with a timestamp and level.

| Level | Flag / variable | Lines shown |
| --- | --- | --- |
| verbose | `-v` or `VIGIL_AGENT_LOG_LEVEL=verbose` | DEBUG, INFO, ERROR, CRITICAL |
| default | none | INFO, ERROR, CRITICAL |
| error | `-q` or `VIGIL_AGENT_LOG_LEVEL=error` | ERROR, CRITICAL |
| critical | `--silent` or `VIGIL_AGENT_LOG_LEVEL=critical` | CRITICAL |

`VIGIL_AGENT_LOG_LEVEL` is read when the PHAR runs on its own (the Docker image). With `php artisan vigil:agent` use the flags.

### Startup

A healthy start logs lines like:

```
2026-10-18 09:00:00 [INFO] Vigil relay initiated: Listening on [127.0.0.1:2407]; Shipping to [https://vigil.example.internal/api/ingest]; Spool [/var/www/app/storage/app/vigil/spool]; Environment [production]; Version [1.0.0]
2026-10-18 09:00:00 [INFO] Authentication successful [0.121s]: token [3fa...] accepted
```

At startup the relay also closes segments that a crashed predecessor left open (`Recovered [N] spool segment(s) left open by a previous run`) and sends an empty batch to the product to check connectivity and the token. A failure of that check does not stop the relay: it keeps accepting and spooling, and retries uploads.

## The spool directory

Default: `storage/app/vigil/spool` (`VIGIL_AGENT_SPOOL_PATH`). The relay creates it, and `dead/`, if missing. The user running the relay needs write access. Use a local disk with enough room (see the 1 GB cap below).

```
spool/
  1760778000123-000001.open            segment being appended to (at most one)
  1760777995120-000001.ndjson          closed segment, waiting to be uploaded
  1760777995120-000001.ndjson.offset   checkpoint of a partly uploaded segment
  agent.lock                           held by the running relay
  dead/
    1760777990000-000003-0.ndjson        rejected batch
    1760777990000-000003-0.error.json    why the product rejected it
```

| Item | Meaning |
| --- | --- |
| `<unix-ms>-<seq>.open` | The segment being written. Each accepted frame is appended as one line (a JSON array of records). |
| `<unix-ms>-<seq>.ndjson` | A closed segment. A segment is closed 5 seconds after its first frame, or at 4 MB, whichever comes first (`VIGIL_AGENT_SPOOL_SEGMENT_SECONDS`, `VIGIL_AGENT_SPOOL_SEGMENT_BYTES`). Names sort by time; the oldest ships first. Never edited after closing. |
| `*.ndjson.offset` | Byte offset up to which a segment has already been delivered. Used when a segment is bigger than one upload (8 MB of raw records), so a restart never re-sends an accepted part. |
| `agent.lock` | Exclusive lock. A second relay on the same spool exits with `Another Vigil relay is already using the spool [...]`. |
| `dead/` | Batches the product rejected as invalid (HTTP 400 or 413). Each is named `<segment>-<offset>.ndjson` and has an `.error.json` with the segment name, offset, byte count, HTTP status, the product's message and the time. |

A segment is deleted once the product has answered `202` for all of it. Newlines inside a frame are replaced by spaces so that one frame is always one line.

## Guarantees

- **ACK means on disk.** The relay answers `2:OK` only after the frame's line was written and flushed to the operating system. Such a frame survives a relay crash, a restart and any product downtime. It does not survive an operating-system crash or power loss unless you set `VIGIL_AGENT_SPOOL_FSYNC=true`, which adds an `fsync` for every frame (slower).
- **Product downtime is absorbed.** If the product is down, slow or returns 5xx, the spool grows and uploads retry with backoff of 2.5 s, 5 s, 10 s, 20 s, 40 s, 80 s, 160 s, then every 300 s. When it recovers, the backlog is shipped oldest first, two uploads at a time (`VIGIL_AGENT_CONCURRENCY`).
- **At-least-once delivery.** If the product commits a batch but the `202` is lost (for example a timeout), the batch is sent again. In rare cases the product can see the same records twice.
- **Bounded size.** Above `VIGIL_AGENT_SPOOL_MAX_BYTES` (1 GB) of unshipped data, the relay stops acknowledging: it closes each connection without `2:OK`. The client treats that as a failure and drops that execution's records; the application is not affected. The relay logs once a minute:

  ```
  [ERROR] Spool is full [1024 MB of 1024 MB]: closing connections without acknowledging [N refused].
  ```

  Normal operation resumes by itself as soon as the uploader has caught up. Data in `dead/` does not count against the cap.
- **Nothing is dropped silently.** A frame whose token hash differs from the relay's token is refused and logged (`Incoming token hash mismatch [...]`). A spool write failure logs CRITICAL and the frame is not acknowledged.
- **Handled answers.**

  | Product answer | What the relay does |
  | --- | --- |
  | 2xx | Deletes the batch. |
  | 400, 413 | Moves the batch to `dead/` with its `.error.json` and continues. |
  | 401 | Keeps everything and retries every 300 s (CRITICAL). Fix the token. |
  | 422 `Unknown environment` or `Environment required` | Keeps everything and retries every 60 s (CRITICAL). Create the environment or fix `VIGIL_ENVIRONMENT`. |
  | 429, 503 | Keeps the batch and waits `retry_in` seconds from the response (between 0.5 s and 1 hour); if the answer has none, uses the backoff above. |
  | Anything else (other 4xx or 5xx, network error, timeout) | Keeps the batch and backs off 2.5 s up to 300 s. |

## Deploys and package upgrades

The relay runs from the PHAR inside `vendor/materom/vigil`. When a deploy or `composer update` replaces the package, the running relay would otherwise keep running old code.

- Every minute the relay compares `agent/build/signature.txt` (the SHA-512 of the PHAR) with the value it read at startup. For Envoyer-style layouts (`releases/<n>/...`) it reads the file through the `current/` symlink, so a new release is noticed.
- If the signature changed, it logs `Agent signature changed: shutting down in 300s` and shuts down gracefully five minutes later. The delay lets all web workers of the old release finish.
- A frame with a payload version the relay does not know (an application running a newer client) shuts it down immediately, logging `Incoming payload version has changed [vX]; expected [v1]`.

In both cases the relay exits with code 0 and relies on a **process manager** to start it again on the new code. Without one, the relay stays down after a deploy and the spool grows. Always run it under Supervisor, systemd, a Forge or Envoyer daemon, or Docker with a restart policy.

Also restart it after changing `.env` or `config/vigil.php` values the relay uses. It reads them at startup only.

## Shutdown (SIGTERM and SIGINT)

On `SIGTERM` or `SIGINT` the relay:

1. stops accepting connections,
2. closes the open segment,
3. runs one final upload round for up to `VIGIL_AGENT_DRAIN_SECONDS` (10 s), ignoring any backoff pause,
4. exits with code 0.

Anything not shipped stays on disk for the next start (`Drain time is up: [N] segment(s) stay on disk for the next start`). A second signal stops it immediately. Set your process manager's stop timeout above the drain time (30 s in the examples). Signal handling needs the `pcntl` extension.

## Running under a process manager

### Supervisor

From `agent/docker/supervisor.conf.example`. Copy to `/etc/supervisor/conf.d/vigil-agent.conf`, adjust paths and user:

```ini
[program:vigil-agent]
command=php /var/www/app/current/artisan vigil:agent
directory=/var/www/app/current
user=www-data
autostart=true
autorestart=true
startsecs=3
stopsignal=TERM
stopwaitsecs=30
stdout_logfile=/var/www/app/shared/storage/logs/vigil-agent.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=5
redirect_stderr=true
```

```bash
supervisorctl reread && supervisorctl update && supervisorctl status vigil-agent
```

### systemd

From `agent/docker/vigil-agent.service.example`. Copy to `/etc/systemd/system/vigil-agent.service`:

```ini
[Unit]
Description=Vigil relay agent
After=network-online.target
Wants=network-online.target

[Service]
User=www-data
WorkingDirectory=/var/www/app/current
ExecStart=/usr/bin/php artisan vigil:agent
Restart=always
RestartSec=2
KillSignal=SIGTERM
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload && systemctl enable --now vigil-agent
```

### Forge and Envoyer-style daemons

Register a daemon with the command `php artisan vigil:agent` in the current release directory, with auto-restart on.

### Docker sidecar

`agent/Dockerfile` builds a standalone relay that does not need the Laravel application. It is a `php:8.2-cli-alpine` image with `pcntl`, the PHAR and a health check. Build it from the package root:

```bash
docker build -f agent/Dockerfile --build-arg VIGIL_AGENT_VERSION=1.0.0 -t vigil-agent .
```

Run it:

```bash
docker run -d --name vigil-agent --restart unless-stopped \
  -p 127.0.0.1:2407:2407 \
  -v vigil-spool:/vigil-agent/spool \
  -e VIGIL_TOKEN=vgl_xxxxxxxxxxxxxxxxxxxxxxxx \
  -e VIGIL_BASE_URL=https://vigil.example.internal \
  -e VIGIL_ENVIRONMENT=production \
  vigil-agent
```

The image sets `VIGIL_INGEST_URI=0.0.0.0:2407` and `VIGIL_AGENT_SPOOL_PATH=/vigil-agent/spool` (a volume, so the spool survives container replacement). The entrypoint forwards SIGTERM, so `docker stop` triggers the drain. Use a stop timeout above `VIGIL_AGENT_DRAIN_SECONDS`, for example `--stop-timeout 30`.

Point the application's `VIGIL_INGEST_URI` at the container (for example `vigil-agent:2407` on a shared network). Publish the port on a private interface only: the relay has no authentication beyond the token hash in each frame. Set the same `VIGIL_TOKEN` in the application, otherwise frames are refused with `Incoming token hash mismatch`.

In the container the relay reads its settings from environment variables only: `VIGIL_TOKEN`, `VIGIL_BASE_URL`, `VIGIL_ENVIRONMENT` (falls back to `APP_ENV`), `VIGIL_SERVER`, `VIGIL_AGENT_*` and `VIGIL_AGENT_LOG_LEVEL`. A new image brings a new PHAR; replace the container to upgrade.

## Health check

```bash
php artisan vigil:status
```

Exit code 0 means the relay answered the framed `PING` with `2:OK`. Use it in monitoring and deploy scripts. In Docker the `HEALTHCHECK` runs `php vigil-status` (`agent/vigil-status`), which does the same from the image (every 30 s, 5 s timeout). Add `--remote` to also verify the token and the product, but do not use `--remote` as a liveness probe: product downtime does not mean the relay is unhealthy, since the relay spools through it.

Other signs of health: `ls spool/*.ndjson` shows only a few young segments, and `dead/` is empty.

## Resource usage

These are expectations from the design, not measured guarantees.

- **Process:** one single-threaded PHP process on an event loop, idle most of the time. Segments are read from disk in batches of at most 8 MB of raw records, so memory does not scale with the size of the spool.
- **CPU:** mainly gzip compression of batches and JSON handling. Low for typical applications.
- **Disk:** the spool is bounded by `VIGIL_AGENT_SPOOL_MAX_BYTES` (1 GB) for pending data, plus whatever accumulates in `dead/`. Plan for the cap on the volume that holds the spool, or lower the cap.
- **Network:** at most two concurrent uploads (default), each up to 8 MB raw, gzip-compressed, with a 30 s timeout.
- **File descriptors:** one per open connection from your PHP processes. Idle connections are closed after 30 s.
