# materom/vigil

The Laravel client ("agent") for **Vigil**, Materom's self-hosted monitoring product. Installed in a monitored Laravel application, it collects requests, queries, exceptions, logs, queued jobs and job attempts, commands, scheduled tasks, cache events, mail, notifications, outgoing HTTP requests and users, and ships them to the Vigil product.

Vigil is derived from [`laravel/nightwatch`](https://github.com/laravel/nightwatch) 1.30.1 (MIT, see `LICENSE.md` and `NOTICE`). The record format and wire framing are kept byte-for-byte identical. The monitoring product itself (collector, storage, UI) is a separate application; this package is only the client.

**Documentation: [docs/](docs/README.md)**

## Requirements

- PHP 8.2+, `ext-zlib`
- Laravel 10, 11, 12 or 13
- A Vigil product, with a project token (`vgl_...`) from Settings, Projects, Show credentials

## Quick start

```bash
composer require materom/vigil
```

```dotenv
VIGIL_TOKEN=vgl_xxxxxxxxxxxxxxxxxxxxxxxx
VIGIL_ENVIRONMENT=production
VIGIL_BASE_URL=https://vigil.example.internal
VIGIL_INGEST_URI=127.0.0.1:2407
```

Run the local relay under a process manager (Supervisor, systemd, Docker), then verify:

```bash
php artisan vigil:agent               # the relay: spools to disk, uploads over HTTPS
php artisan vigil:status              # is the relay (or collector) answering?
php artisan vigil:status --remote     # does the product accept the token?
```

The service provider and the `Vigil` facade are auto-discovered. Publish the config with `php artisan vendor:publish --tag=vigil-config` if you want to edit it.

## How it works

```
app process --TCP loopback--> vigil:agent --> local disk spool --gzip HTTPS--> Vigil product /api/ingest
```

At the end of every request, command, job attempt or scheduled task the client writes one frame to `VIGIL_INGEST_URI` with 0.5 s timeouts. The relay acknowledges only after the frame is on local disk, so the application never waits on the network or the product, and product downtime does not lose data (the spool is capped at 1 GB). Without a relay you can point `VIGIL_INGEST_URI` at the product's own TCP collector; failed transmissions are then lost. Details: [Overview](docs/01-overview.md).

## Commands

| Command | Purpose |
| --- | --- |
| `vigil:agent` | Run the relay. See [Running the relay](docs/03-running-the-relay.md). |
| `vigil:status [--remote] [--environment=]` | Check the relay (framed `PING`) or, with `--remote`, the product and token. |
| `vigil:deploy [deploy] [--ref=] [--environment=] [--name=] [--url=] [--timestamp=]` | Tell the product about a deployment. See [Deployments](docs/06-deployments.md). |

## API

```php
use Materom\Vigil\Facades\Vigil;

Vigil::user(fn ($user) => ['name' => $user->name, 'username' => $user->email]);
Vigil::rejectQueries(fn ($query) => str_contains($query->sql, 'telescope_'));
Vigil::report($exception, handled: true);
Vigil::digest();   // transmit buffered records now (long-running processes)
```

Full reference with `reject*`, `redact*`, sampling, `ignore()` and `guzzleMiddleware()`: [Usage in code](docs/05-usage-in-code.md). All settings: [Configuration](docs/04-configuration.md). Migrating from `laravel/nightwatch`: [Migration guide](docs/07-migrating-from-nightwatch.md). Problems: [Troubleshooting](docs/08-troubleshooting.md).

## Development

### Tests

```bash
composer install
vendor/bin/pest
```

`tests/Agent` covers the relay's classes from `agent/src` (frame parser, spool, batch assembly, uploader answers, signature watch). `tests/Unit/AgentEndToEndTest.php` runs the built `agent/build/agent.phar` in a child process against a fake product (`php -S`); rebuild the PHAR first when `agent/` changed.

### Rebuilding the PHAR

The relay lives in `agent/` with its own `composer.json` (`materom/vigil-agent`: ReactPHP socket, http, event-loop, promise and stream; Box and PHP-Scoper for the build). `agent/build.sh` installs it, compiles `agent/build/agent.phar` with Box, prefixing every vendored namespace with a random `VigilAgent_<random>` namespace (`agent/scoper.inc.php`; pin it with `VIGIL_AGENT_SCOPER_PREFIX`), and writes `agent/build/signature.txt` (sha512 of the PHAR). Commit both artifacts after changing anything in `agent/`. Composer's platform is pinned to PHP 8.2, so the PHAR runs on PHP 8.2+. `agent/bootstrap.php` isolates the PHAR's Composer autoloader from the host application's and eagerly loads every class, so a PHAR replaced on disk by a deploy never breaks the process still running the old one.

### Wire format

Each transmission is one frame `<len>:v1:<tokenHash>:<body>`, where `<len>` is the byte length of everything after the first colon, `<tokenHash>` is the first 7 hex characters of `xxh128(VIGIL_TOKEN)` and `<body>` is a JSON array of records (or `PING`). The relay or collector replies `2:OK`. Record shapes are documented in the product's `docs/RECORDS.md`.

See [CHANGELOG.md](CHANGELOG.md) for changes.
