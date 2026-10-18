# Migrating from laravel/nightwatch

Vigil is a fork of `laravel/nightwatch` 1.30.1. A migration is a rename, plus switching the agent process. Record shapes and wire framing are unchanged.

## Steps

1. Create or pick a project in the Vigil product and copy its token (Settings, Projects, Show credentials). The Nightwatch token does not work with Vigil.
2. Swap the packages:

   ```bash
   composer remove laravel/nightwatch
   composer require materom/vigil
   ```

   (Use the path-repository form from [Installation](02-installation.md) for a local checkout.)
3. Rename the environment variables (table below) and add `VIGIL_BASE_URL` and `VIGIL_ENVIRONMENT`.
4. Rename code references (see below).
5. Replace the `nightwatch:agent` process (Supervisor, Forge daemon, ...) with `vigil:agent`, see [Running the relay](03-running-the-relay.md). Or remove it and point `VIGIL_INGEST_URI` at the product's collector.
6. Delete `config/nightwatch.php` if you published it. Publish `config/vigil.php` only if you need to edit it.
7. Clear and rebuild cached config, restart queue workers and Octane, restart the relay, and run:

   ```bash
   php artisan vigil:status
   php artisan vigil:status --remote
   ```

## Environment variables

| laravel/nightwatch | materom/vigil | Note |
| --- | --- | --- |
| `NIGHTWATCH_ENABLED` | `VIGIL_ENABLED` | |
| `NIGHTWATCH_TOKEN` | `VIGIL_TOKEN` | A new project token from the Vigil product. |
| none | `VIGIL_ENVIRONMENT` | New. Defaults to `APP_ENV`. |
| `NIGHTWATCH_BASE_URL` | `VIGIL_BASE_URL` | Now required for the relay, `vigil:status --remote` and `vigil:deploy`; there is no SaaS default. |
| `NIGHTWATCH_DEPLOY` | `VIGIL_DEPLOY` | |
| `NIGHTWATCH_SERVER` | `VIGIL_SERVER` | |
| `NIGHTWATCH_CAPTURE_EXCEPTION_SOURCE_CODE` | `VIGIL_CAPTURE_EXCEPTION_SOURCE_CODE` | |
| `NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD` | `VIGIL_CAPTURE_REQUEST_PAYLOAD` | |
| `NIGHTWATCH_REDACT_PAYLOAD_FIELDS` | `VIGIL_REDACT_PAYLOAD_FIELDS` | |
| `NIGHTWATCH_REDACT_HEADERS` | `VIGIL_REDACT_HEADERS` | |
| `NIGHTWATCH_REQUEST_SAMPLE_RATE` | `VIGIL_REQUEST_SAMPLE_RATE` | |
| `NIGHTWATCH_COMMAND_SAMPLE_RATE` | `VIGIL_COMMAND_SAMPLE_RATE` | |
| `NIGHTWATCH_EXCEPTION_SAMPLE_RATE` | `VIGIL_EXCEPTION_SAMPLE_RATE` | |
| `NIGHTWATCH_SCHEDULED_TASK_SAMPLE_RATE` | `VIGIL_SCHEDULED_TASK_SAMPLE_RATE` | |
| `NIGHTWATCH_IGNORE_CACHE_EVENTS` | `VIGIL_IGNORE_CACHE_EVENTS` | |
| `NIGHTWATCH_IGNORE_MAIL` | `VIGIL_IGNORE_MAIL` | |
| `NIGHTWATCH_IGNORE_NOTIFICATIONS` | `VIGIL_IGNORE_NOTIFICATIONS` | |
| `NIGHTWATCH_IGNORE_OUTGOING_REQUESTS` | `VIGIL_IGNORE_OUTGOING_REQUESTS` | |
| `NIGHTWATCH_IGNORE_QUERIES` | `VIGIL_IGNORE_QUERIES` | |
| `NIGHTWATCH_LOG_LEVEL` | `VIGIL_LOG_LEVEL` | |
| `NIGHTWATCH_INGEST_URI` | `VIGIL_INGEST_URI` | The local relay (`vigil:agent`), or the product's collector. |
| `NIGHTWATCH_INGEST_TIMEOUT` | `VIGIL_INGEST_TIMEOUT` | |
| `NIGHTWATCH_INGEST_CONNECTION_TIMEOUT` | `VIGIL_INGEST_CONNECTION_TIMEOUT` | |
| `NIGHTWATCH_INGEST_EVENT_BUFFER` | `VIGIL_INGEST_EVENT_BUFFER` | |
| `NIGHTWATCH_FORCE_REQUEST` | `VIGIL_FORCE_REQUEST` | |
| none | `VIGIL_AGENT_*` | New: relay settings, see [Configuration](04-configuration.md#relay-agent). |

## Code and configuration renames

| Nightwatch | Vigil |
| --- | --- |
| `Laravel\Nightwatch\...` | `Materom\Vigil\...` |
| `Laravel\Nightwatch\Facades\Nightwatch` | `Materom\Vigil\Facades\Vigil` (the `Vigil` alias is auto-registered) |
| `Laravel\Nightwatch\Http\Middleware\Sample` | `Materom\Vigil\Http\Middleware\Sample` |
| `Laravel\Nightwatch\Console\Sample` | `Materom\Vigil\Console\Sample` |
| `config/nightwatch.php` | `config/vigil.php` |
| log channel `nightwatch` | log channel `vigil` (update `LOG_STACK` or your `stack` channel) |
| `nightwatch:status` | `vigil:status` (also `--remote`) |
| deploy notification | `vigil:deploy` (posts to `{VIGIL_BASE_URL}/api/deployments`), see [Deployments](06-deployments.md) |

The facade methods are the same: `user`, `sample`, `dontSample`, `ignore`, `report`, `digest`, `guzzleMiddleware`, `reject*`, `redact*`. See [Usage in code](05-usage-in-code.md).

## What changed in the agent

- `nightwatch:agent` is replaced by `vigil:agent`. Nightwatch's agent buffered in memory and forwarded to the cloud. The Vigil relay spools to local disk, acknowledges only after the write, and uploads to `{VIGIL_BASE_URL}/api/ingest` with the project token instead of a short-lived ingest token. It never drops data on authentication or quota errors; it keeps it and retries.
- It keeps the frame protocol, the PHAR isolation (scoped dependencies, autoload isolation, eager class loading), the signature watch, and the restart when the payload version changes.
- `agent/vigil-status` replaces `agent/nightwatch-status` in the Docker image.
- The cloud forwarder, the Nightwatch cloud base URL default, the Laravel Boost skill and `version.txt` are removed. The version reported to the product is the Composer package version.

## What was kept on purpose

- The queue payload key `nightwatch` and the hidden Context keys `nightwatch_trace_id`, `nightwatch_should_sample` and `nightwatch_user_id`. Jobs queued by `laravel/nightwatch` before the switch still link to their attempts.
- The `_nightwatch_error` and `_nightwatch_files` markers inside record payloads. The product's catalogue depends on them.

## Data-shape compatibility

Record shapes and versions are byte-for-byte those of Nightwatch 1.30.1, and so is the frame `<len>:v1:<tokenHash>:<body>`. A product that understands Nightwatch records understands Vigil records, and the `IngestingEvents` event is unchanged. Nothing in your history is converted; the Vigil product starts with the data it receives from now on.

## Upgrading an earlier Vigil install

If you installed a Vigil build from before environments existed, nothing changes except adding `VIGIL_ENVIRONMENT` (without it, `APP_ENV` is used). Restart the relay so it picks up the new PHAR and variable.
