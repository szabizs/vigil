# Configuration

All settings live in `config/vigil.php` (merged from the package; publish it only if you want to edit it, see [Installation](02-installation.md#publish-the-configuration-optional)). Every key reads an environment variable, so you normally configure Vigil from `.env`.

After changing configuration on a server with cached config, run `php artisan config:cache` again and restart long-running processes (queue workers, Octane, Horizon, and the relay).

## General

| Config key | Variable | Default | Meaning |
| --- | --- | --- | --- |
| `enabled` | `VIGIL_ENABLED` | `true` | Master switch. When `false`, no hooks are registered and nothing is captured. `vigil:status` then fails with `Vigil is disabled [VIGIL_ENABLED=false].` Turn it off in local development and in tests. |
| `token` | `VIGIL_TOKEN` | none | The project credential (`vgl_...`) from Settings, Projects, Show credentials in the product. One token per project. |
| `environment` | `VIGIL_ENVIRONMENT` | `APP_ENV` | Environment of the project this application reports to. Sent as the `Vigil-Environment` header by the relay, `vigil:status --remote` and `vigil:deploy`. If it resolves to an empty value, the product uses the project's default environment. |
| `base_url` | `VIGIL_BASE_URL` | none | Base URL of the product. Required by the relay, `vigil:status --remote` and `vigil:deploy`. Use `https://`. |
| `deployment` | `VIGIL_DEPLOY` | `LARAVEL_CLOUD_DEPLOY_UUID`, then `FORGE_DEPLOY_COMMIT`, then `VAPOR_COMMIT_HASH` | Deploy identifier attached to every record, so the product can tie data to a release. Set it to your release id or commit hash in your deploy script. It is also the default argument of `vigil:deploy`. |
| `server` | `VIGIL_SERVER` | `gethostname()` | Server name attached to every record and sent by the relay as `Vigil-Server`. Set it when hostnames are not meaningful (containers). |

`VIGIL_FORCE_REQUEST` (no config key, read directly) makes a console process behave as an HTTP request. It exists for testing; leave it unset.

## Capture and redaction

| Config key | Variable | Default | Meaning |
| --- | --- | --- | --- |
| `capture_exception_source_code` | `VIGIL_CAPTURE_EXCEPTION_SOURCE_CODE` | `true` | Include source code snippets around each frame of an exception trace. Disable if your code is sensitive in the product's eyes or to reduce record size. |
| `capture_request_payload` | `VIGIL_CAPTURE_REQUEST_PAYLOAD` | `false` | Capture the request payload, but only for requests that end in HTTP 500. Off by default. Enable it when you need to reproduce failures and accept that request data (minus redacted fields) is stored in the product. |
| `redact_payload_fields` | `VIGIL_REDACT_PAYLOAD_FIELDS` | `_token,password,password_confirmation` | Comma-separated payload field names whose string values become `[N bytes redacted]`. Matches the key at any depth. Add fields such as `card_number` or `api_key`. Setting the variable replaces the default list, so repeat the defaults. |
| `redact_headers` | `VIGIL_REDACT_HEADERS` | `Authorization,Cookie,Proxy-Authorization,X-XSRF-TOKEN` | Comma-separated headers whose values are redacted. Setting the variable replaces the default list. |

For anything these lists cannot express, use the `Vigil::redact*()` callbacks ([Usage in code](05-usage-in-code.md#redacting-records)).

## Sampling

Sampling keeps a fraction of executions. Rates are between `0.0` (nothing) and `1.0` (everything). A value outside that range is treated as `0.0`. The decision is per execution: all records of a sampled execution are kept, all records of an unsampled one are dropped before they leave the process.

| Config key | Variable | Default | Meaning |
| --- | --- | --- | --- |
| `sampling.requests` | `VIGIL_REQUEST_SAMPLE_RATE` | `1.0` | Fraction of HTTP requests captured. |
| `sampling.commands` | `VIGIL_COMMAND_SAMPLE_RATE` | `1.0` | Fraction of Artisan commands captured. Queue jobs do not use this rate: a job attempt follows the sampling decision of the process that dispatched it (see [Usage in code](05-usage-in-code.md#sampling-and-ignoring)). |
| `sampling.scheduled_tasks` | `VIGIL_SCHEDULED_TASK_SAMPLE_RATE` | `1.0` | Fraction of scheduled task runs captured. |
| `sampling.exceptions` | `VIGIL_EXCEPTION_SAMPLE_RATE` | `1.0` | Applies to exceptions raised in an execution that was not sampled. With `1.0` every exception is still recorded even when its request was not sampled. Lower it to drop exceptions together with their execution. |

Start at `1.0`. Lower the request rate only for very high traffic, and keep the exception rate at `1.0` so errors are never lost. Per-route and per-task overrides are in [Usage in code](05-usage-in-code.md#sampling-and-ignoring).

## Filtering

| Config key | Variable | Default | Meaning |
| --- | --- | --- | --- |
| `filtering.ignore_cache_events` | `VIGIL_IGNORE_CACHE_EVENTS` | `false` | Do not capture cache events. Useful when cache traffic is very high. |
| `filtering.ignore_mail` | `VIGIL_IGNORE_MAIL` | `false` | Do not capture mail. |
| `filtering.ignore_notifications` | `VIGIL_IGNORE_NOTIFICATIONS` | `false` | Do not capture notifications. |
| `filtering.ignore_outgoing_requests` | `VIGIL_IGNORE_OUTGOING_REQUESTS` | `false` | Do not capture outgoing HTTP requests (Laravel `Http` client and `Vigil::guzzleMiddleware()`). |
| `filtering.ignore_queries` | `VIGIL_IGNORE_QUERIES` | `false` | Do not capture queries. The count of queries in request records is also not collected. |
| `filtering.log_level` | `VIGIL_LOG_LEVEL` | `LOG_LEVEL`, then `debug` | Minimum level of the `vigil` log channel. |

There is no ignore flag for exceptions, jobs, commands, scheduled tasks or logs. Use sampling, `Vigil::rejectQueuedJobs()` or the `vigil` log channel's level for those.

### Logging

The package registers a log channel named `vigil` (unless you define `logging.channels.vigil` yourself). It is not added to your stack automatically. To send logs to Vigil, include it in your stack:

```dotenv
LOG_STACK=daily,vigil
```

(Laravel 11 and newer.) On Laravel 10, add `vigil` to the `channels` array of your `stack` channel in `config/logging.php`. Raise `VIGIL_LOG_LEVEL` (for example `warning`) to send fewer log entries. Logs are only captured when Vigil is enabled.

## Ingest (application to relay or collector)

| Config key | Variable | Default | Meaning |
| --- | --- | --- | --- |
| `ingest.uri` | `VIGIL_INGEST_URI` | `127.0.0.1:2407` | `host:port` where the application sends frames: the local relay (which listens on this address) or the product's collector. |
| `ingest.timeout` | `VIGIL_INGEST_TIMEOUT` | `0.5` | Seconds to wait for the `2:OK` reply. |
| `ingest.connection_timeout` | `VIGIL_INGEST_CONNECTION_TIMEOUT` | `0.5` | Seconds to wait to connect. |
| `ingest.event_buffer` | `VIGIL_INGEST_EVENT_BUFFER` | `500` | Records buffered in memory before a sampled execution transmits early. |

When to change them:

- Keep the 0.5 s timeouts with the local relay. They bound how long an execution can be delayed if the relay hangs.
- Raise the timeouts moderately (for example `1.0`) only in direct mode over a slow link, accepting added latency per request.
- Raise `event_buffer` only for executions that legitimately produce thousands of records (long commands); it raises memory use. For daemons see `Vigil::digest()` in [Usage in code](05-usage-in-code.md#long-running-processes).

`vigil:status` and `agent/vigil-status` use the same URI and timeouts.

## Relay agent

Used by `php artisan vigil:agent` (`vigil.agent.*`). In the Docker image the same variables are read from the container environment. See [Running the relay](03-running-the-relay.md).

| Config key | Variable | Default | Meaning |
| --- | --- | --- | --- |
| `agent.spool_path` | `VIGIL_AGENT_SPOOL_PATH` | `storage/app/vigil/spool` | Spool directory (segments, checkpoints, `dead/`, lock). Standalone PHAR default is `./vigil-spool`. |
| `agent.spool_segment_bytes` | `VIGIL_AGENT_SPOOL_SEGMENT_BYTES` | `4194304` (4 MB) | Rotate the open segment at this size. |
| `agent.spool_segment_seconds` | `VIGIL_AGENT_SPOOL_SEGMENT_SECONDS` | `5` | Rotate the open segment this many seconds after its first frame. This is also the minimum latency before data is uploaded. |
| `agent.spool_max_bytes` | `VIGIL_AGENT_SPOOL_MAX_BYTES` | `1073741824` (1 GB) | Stop acknowledging above this much unshipped data. Lower it on small disks. |
| `agent.spool_fsync` | `VIGIL_AGENT_SPOOL_FSYNC` | `false` | `fsync` every frame before acknowledging. Survives power loss; costs latency per execution. |
| `agent.upload_bytes` | `VIGIL_AGENT_UPLOAD_BYTES` | `8388608` (8 MB) | Raw record bytes per upload; larger segments are split. Keep it below the product's request limits. |
| `agent.concurrency` | `VIGIL_AGENT_CONCURRENCY` | `2` | Concurrent uploads. |
| `agent.auth_connection_timeout` | `VIGIL_AGENT_AUTH_CONNECTION_TIMEOUT` | `5` | Connect timeout of the startup token check, seconds. Also used by `vigil:status --remote`. |
| `agent.auth_timeout` | `VIGIL_AGENT_AUTH_TIMEOUT` | `10` | Timeout of the startup token check, seconds. Also used by `vigil:status --remote`. |
| `agent.ingest_connection_timeout` | `VIGIL_AGENT_INGEST_CONNECTION_TIMEOUT` | `5` | Connect timeout of an upload, seconds. |
| `agent.ingest_timeout` | `VIGIL_AGENT_INGEST_TIMEOUT` | `30` | Timeout of an upload, seconds. Raise it if big batches time out on a slow link. |
| `agent.drain_seconds` | `VIGIL_AGENT_DRAIN_SECONDS` | `10` | Length of the final upload round on SIGTERM or deploy shutdown. Keep your process manager's stop timeout above it. |

Two more variables exist only for the standalone PHAR (Docker), not in `config/vigil.php`:

| Variable | Default | Meaning |
| --- | --- | --- |
| `VIGIL_AGENT_LOG_LEVEL` | none | `verbose`, `error` or `critical`. |
| `VIGIL_AGENT_VERSION` | `unknown` | Version in the `User-Agent` header (`VigilAgent/<version>`). `php artisan vigil:agent` fills it from Composer; the Docker image sets it from the `VIGIL_AGENT_VERSION` build argument. |

The relay applies minimums: segment size at least 1 byte, rotation at least 0.05 s, upload size at least 1024 bytes, concurrency at least 1.

## Example `.env` for production with a relay

```dotenv
VIGIL_ENABLED=true
VIGIL_TOKEN=vgl_xxxxxxxxxxxxxxxxxxxxxxxx
VIGIL_ENVIRONMENT=production
VIGIL_BASE_URL=https://vigil.example.internal
VIGIL_INGEST_URI=127.0.0.1:2407
VIGIL_DEPLOY=${RELEASE_ID}
LOG_STACK=daily,vigil
VIGIL_LOG_LEVEL=warning
```
