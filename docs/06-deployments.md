# Deployments

`vigil:deploy` tells the product that a release went live. The product stores it against the environment, so you can line up changes in behaviour with releases. See the product documentation for how deployments are displayed.

Separately, every record the agent sends carries a deploy identifier (`VIGIL_DEPLOY`). Use the same value in both places, so that the deployment entry and the records of that release match.

## Usage

```bash
php artisan vigil:deploy [deploy] [--ref=] [--environment=] [--name=] [--url=] [--timestamp=]
```

| Argument or option | Default | Meaning |
| --- | --- | --- |
| `deploy` | `VIGIL_DEPLOY` | A unique value for the deploy, for example a release id or commit hash. Required (from the argument or the variable). |
| `--ref=` | none | Git ref (tag or hash) of the deploy. |
| `--environment=` | `VIGIL_ENVIRONMENT` | Environment of the deploy. Sent as the `Vigil-Environment` header and as the JSON field `environment`. |
| `--name=` | none | Human-readable name, for example `Release 42`. |
| `--url=` | none | A link with more information (a CI run, a release page). |
| `--timestamp=` | now | When the deploy happened. Any string Carbon can parse; it is sent as UTC with microseconds. |

The command needs `VIGIL_TOKEN` and `VIGIL_BASE_URL` in the environment where it runs. It POSTs JSON to `{VIGIL_BASE_URL}/api/deployments` with the token as Bearer token, a 5 s connect timeout and 10 s total timeout. The request body is:

```json
{
  "timestamp": "2026-10-17 12:00:00.000000",
  "deploy": "release-42",
  "environment": "production",
  "ref": "abc123",
  "name": "Release 42",
  "url": "https://git.example.com/r/42"
}
```

Success prints `Deployment sent to Vigil successfully.` A failure prints `Deployment could not be sent to Vigil: <message>`, where the message comes from the product's JSON response (for example `Invalid token.`) or from the network error.

### Exit code

**`vigil:deploy` always exits with status 0**, including when a setting is missing (`Please configure the [VIGIL_DEPLOY] environment variable.`, `[VIGIL_TOKEN]`, `[VIGIL_BASE_URL]`) or the product refuses the request. This is deliberate: a monitoring call must not fail your deploy. The consequence is that a CI job cannot detect a failed notification from the exit code. Read the output if you need to alert on it:

```bash
php artisan vigil:deploy "$RELEASE" --ref="$GIT_SHA" | tee /tmp/vigil-deploy.log
grep -q 'sent to Vigil successfully' /tmp/vigil-deploy.log || echo "WARNING: Vigil was not notified"
```

## Examples

### Generic CI step (after the new release is live)

```bash
export VIGIL_TOKEN="$VIGIL_TOKEN_SECRET"
export VIGIL_BASE_URL=https://vigil.example.internal
export VIGIL_ENVIRONMENT=production

php artisan vigil:deploy "$CI_COMMIT_SHA" \
  --ref="$CI_COMMIT_SHA" \
  --name="Release $CI_PIPELINE_ID" \
  --url="$CI_PIPELINE_URL"
```

Store the token in your CI's secret storage. The command runs the application's Artisan, so it must run in a checkout with dependencies installed, normally on the server or in the release directory.

### Envoyer

Add a deployment hook after the new release is activated, using Envoyer's placeholders for the release path and commit:

```bash
cd {{ release }}
php artisan vigil:deploy "{{ sha }}" --ref="{{ sha }}"
```

### Forge deploy script

Append to the deploy script, after migrations and cache clearing. Forge exposes the commit as `FORGE_DEPLOY_COMMIT`, which `config/vigil.php` already uses as the default `VIGIL_DEPLOY`:

```bash
php artisan vigil:deploy
```

### Vapor and similar platforms

The config also falls back to `VAPOR_COMMIT_HASH` (and `LARAVEL_CLOUD_DEPLOY_UUID`) for `VIGIL_DEPLOY`. On these platforms run `php artisan vigil:deploy` in a post-deploy step where the `VIGIL_*` variables are available, or set `VIGIL_DEPLOY` yourself.

## Consistency with the relay

The relay restarts itself after a deploy replaced the package. See [Running the relay](03-running-the-relay.md#deploys-and-package-upgrades). `vigil:deploy` does not depend on the relay; it talks to the product directly over HTTPS.

The product resolves the environment from the `Vigil-Environment` header. If it cannot, it answers with an error, and `vigil:deploy` prints the product's message. Use the same `VIGIL_ENVIRONMENT` value for records and deployments.
