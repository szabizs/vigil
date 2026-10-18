# Installation

## Requirements

| Requirement | Detail |
| --- | --- |
| PHP | 8.2 or newer |
| Laravel | 10, 11, 12 or 13 |
| PHP extension | `ext-zlib` (required by Composer; the relay and `vigil:status --remote` gzip their uploads) |
| The Vigil product | A running product, a project and its credential (token). |
| For the relay | `pcntl` is recommended for clean SIGTERM handling (the official `php:*-cli` Docker images need `docker-php-ext-install pcntl`, as the agent Dockerfile does). Without it the relay still works, but does not trap signals. |
| Network | Outbound HTTPS from the application server to the product (relay mode), or TCP to the product's collector (direct mode). |

## Install the package

```bash
composer require materom/vigil
```

The service provider and the `Vigil` facade alias are auto-discovered (`extra.laravel` in the package's `composer.json`). Nothing needs registering.

### From the private GitHub repository (recommended)

The package is published as the private repository `https://github.com/szabizs/vigil` and versioned with tags (`v0.1.0`, …). Add it as a VCS repository and require a tagged version:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/szabizs/vigil" }
],
"require": {
    "materom/vigil": "^0.1"
}
```

Composer needs read access to the repository on every machine that runs `composer install` (developer machines, build agents, servers). Create a GitHub **fine-grained personal access token** (Settings → Developer settings → Fine-grained tokens) with *Repository access: only `szabizs/vigil`* and *Permissions → Contents: Read-only*, then store it once per machine, outside the project:

```bash
composer config --global github-oauth.github.com <token>
```

Alternatively give a server a deploy key and use the SSH URL `git@github.com:szabizs/vigil.git` in the repository entry. Never commit the token; `composer.json` holds only the URL.

Then:

```bash
composer require materom/vigil:^0.1
```

Upgrades are deliberate: `composer update materom/vigil` moves to the newest tag allowed by the constraint.

### From a local checkout or monorepo (path repository)

When the package lives next to your application, for example in `../vigil`, add a path repository to the application's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../vigil",
            "options": { "symlink": true }
        }
    ]
}
```

Then require it. A path checkout has no version tag, so allow the dev version:

```bash
composer require materom/vigil:@dev
```

With `symlink: true` the application runs the checkout directly, so edits in the package take effect immediately. For the relay this has one consequence: the relay watches the PHAR's signature file in the package, so rebuilding the PHAR (`agent/build.sh`) makes a running relay restart itself about five minutes later (see [Running the relay](03-running-the-relay.md#deploys-and-package-upgrades)). The package ships the built `agent/build/agent.phar` and `agent/build/signature.txt`; you do not build anything to use it.

## Publish the configuration (optional)

The package works with its built-in defaults. Publish the file only if you want to edit `config/vigil.php` directly:

```bash
php artisan vendor:publish --tag=vigil-config
```

The tag `vigil` also works. Every setting has an environment variable, so most installs never need the file. See [Configuration](04-configuration.md).

## Minimal `.env`

```dotenv
VIGIL_TOKEN=vgl_xxxxxxxxxxxxxxxxxxxxxxxx
VIGIL_ENVIRONMENT=production
VIGIL_BASE_URL=https://vigil.example.internal
VIGIL_INGEST_URI=127.0.0.1:2407
```

| Variable | Where the value comes from |
| --- | --- |
| `VIGIL_TOKEN` | In the product: Settings, then Projects, then Show credentials. The token belongs to a project and starts with `vgl_`. Keep it secret; never commit it. |
| `VIGIL_ENVIRONMENT` | The environment of that project this application reports to, for example `production` or `staging`. Defaults to `APP_ENV` when unset. If you leave it empty, the product uses the project's default environment. Whether an unknown name is created automatically or rejected is a product setting (rejected names show up as `Unknown environment`, see [Troubleshooting](08-troubleshooting.md)). |
| `VIGIL_BASE_URL` | The base URL of the product, without a trailing path. Used by the relay's uploads, `vigil:status --remote` and `vigil:deploy`. It has no default. |
| `VIGIL_INGEST_URI` | `host:port` the application sends frames to. Default `127.0.0.1:2407`. In relay mode the relay listens on this same address, so keep it on loopback. For direct mode, use the collector's address. |

Optional but common: `VIGIL_SERVER` (defaults to the hostname) and `VIGIL_DEPLOY` (a deploy identifier attached to every record; it falls back to `LARAVEL_CLOUD_DEPLOY_UUID`, `FORGE_DEPLOY_COMMIT`, then `VAPOR_COMMIT_HASH`).

To capture application logs, add the `vigil` channel to your log stack, for example `LOG_STACK=daily,vigil` on Laravel 11 and newer.

After changing `.env` on a server that caches configuration, run `php artisan config:cache` again, and restart the relay and any queue workers or Octane workers so they pick up the new values.

## Start the relay

In relay mode, start `php artisan vigil:agent` under a process manager. See [Running the relay](03-running-the-relay.md). For a first test you can simply run it in a terminal:

```bash
php artisan vigil:agent -v
```

## Verify

```bash
php artisan vigil:status
```

This sends a framed `PING` to `VIGIL_INGEST_URI` and expects `2:OK`. Expected output:

```
INFO  The Vigil relay at [127.0.0.1:2407] is running and accepting connections.
```

Failure output reads `The Vigil relay at [127.0.0.1:2407] is not reachable: ...` and the exit code is 1.

```bash
php artisan vigil:status --remote
```

This POSTs an empty gzip batch (`{"records":[]}`) to `{VIGIL_BASE_URL}/api/ingest` with your token. It checks connectivity to the product, the token and the environment, without sending data. Expected output:

```
INFO  The Vigil product at [https://vigil.example.internal/api/ingest] accepted the token [HTTP 202].
```

Both forms exit with 1 when `VIGIL_ENABLED=false`, when `VIGIL_TOKEN` is empty, or on any failure. `--remote` also needs `VIGIL_BASE_URL`. You can pass `--environment=staging` to `--remote` to test a different environment than `VIGIL_ENVIRONMENT`; the plain TCP check ignores it.

Note that the plain `vigil:status` only proves that something answers on `VIGIL_INGEST_URI`. Use `--remote` to prove that the product accepts your token.

## First data check

1. Make sure the relay is running and both status checks pass.
2. Open any page of the application, or run an Artisan command such as `php artisan about`.
3. Within a few seconds, the relay (run with `-v`) logs `Uploading [...]` and then `Ingest successful [...]: 202 [...]; N record(s) accepted`.
4. In the product, open the project and environment you configured and look for the request. If nothing appears, see [Troubleshooting](08-troubleshooting.md).

Segments are closed every 5 seconds or 4 MB, so allow a few seconds between the request and the upload.

Vigil's own commands are not recorded: `vigil:status` is on the default list of ignored vendor commands.
