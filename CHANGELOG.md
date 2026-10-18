# Changelog

## Unreleased

Initial fork of `laravel/nightwatch` 1.30.1.

### Added
- Local relay agent `php artisan vigil:agent` (`agent/build/agent.phar`, sources in `agent/`): listens on
  `VIGIL_INGEST_URI` with the unchanged frame protocol, acknowledges only after appending to a disk spool
  (`storage/app/vigil/spool`), ships gzip `{"records":[...]}` batches to `{VIGIL_BASE_URL}/api/ingest` with retries,
  backoff, `retry_in` handling and a `dead/` folder, restarts after deploys (signature watch) and drains on SIGTERM.
- `vigil.agent.*` configuration (`VIGIL_AGENT_*`), `agent/Dockerfile`, `agent/vigil-status`, supervisor and
  systemd examples.
- `vigil:status --remote` checks connectivity and the token against the product over HTTPS.

### Changed
- Credentials are per project: `VIGIL_TOKEN` is a project credential and the new `VIGIL_ENVIRONMENT` (default
  `APP_ENV`, `vigil.environment`) declares the environment. The relay, `vigil:status --remote` and `vigil:deploy`
  send it as the `Vigil-Environment` header (`vigil:deploy` also as the JSON field `environment`; `--environment=`
  overrides it on `vigil:agent`, `vigil:status` and `vigil:deploy`). The relay keeps data and retries every minute
  on `422 Unknown environment` / `Environment required`, and treats `401 Project ingest disabled` like any 401.
- Namespace `Laravel\Nightwatch` → `Materom\Vigil`, facade `Vigil`, provider `VigilServiceProvider`,
  config `config/vigil.php`, log channel `vigil`, environment variables `NIGHTWATCH_*` → `VIGIL_*`.
- `VIGIL_INGEST_URI` is either the local relay (`vigil:agent`) or the Vigil collector itself.
- Stream helpers moved from `agent/helpers.php` (`Nightwatch\`) to `src/Support/streams.php`
  (`Materom\Vigil\Support\`).
- `vigil:status` pings the collector over TCP with the standard framing (was `nightwatch:status`).
- `vigil:deploy` posts to `{VIGIL_BASE_URL}/api/deployments`; there is no default base URL.
- An `Materom\Vigil\Contracts\Ingest` bound in the container before the provider registers replaces
  the TCP ingest (used by the test-suite).
- Exception locations ignore frames from the package's own `src/` directory as well, so symlinked
  path-repository installs are treated as internal.

### Removed
- `nightwatch:agent` and its PHAR (replaced by `vigil:agent`), the `nightwatch-status` script (replaced by
  `agent/vigil-status`), the Boost skill and `version.txt`.

### Unchanged
- Wire framing, every record shape and version, hooks, sampling, redaction, rejection and the
  `IngestingEvents` event.
