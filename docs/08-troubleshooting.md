# Troubleshooting

Start with these two commands and the relay log (run the relay with `-v` to see every upload):

```bash
php artisan vigil:status            # is something answering on VIGIL_INGEST_URI?
php artisan vigil:status --remote   # does the product accept the token and environment?
```

Quoted log lines below are the real messages written by the relay or the commands. Timings, counts and paths vary. Product-side causes (limits, settings) are described from the client's point of view; check the product documentation for where to change them.

## Symptoms, causes and fixes

### Status commands

| Symptom or log line | Cause | Fix |
| --- | --- | --- |
| `vigil:status`: `The Vigil relay at [127.0.0.1:2407] is not reachable: ...` | Nothing is listening on `VIGIL_INGEST_URI`: the relay is not running, crashed, or the address or port differs from the relay's. In Docker, the port is not published or the host name is wrong. | Start `vigil:agent` under a process manager; make sure the application and the relay use the same `VIGIL_INGEST_URI`; check the port with `ss -ltnp`. |
| `vigil:status`: `Unexpected response from collector [...]` (empty brackets or other text) | Something other than a Vigil relay answers on the port, or the relay refused the frame without a reply (token mismatch, spool full). | Check what runs on the port; compare `VIGIL_TOKEN` in the application and the relay; read the relay log for `Incoming token hash mismatch` or `Spool is full`. |
| `vigil:status`: `The VIGIL_TOKEN environment variable has not been configured.` | Token empty, or cached config without it. | Set `VIGIL_TOKEN`; `php artisan config:cache` again. |
| `vigil:status`: `Vigil is disabled [VIGIL_ENABLED=false].` | The master switch is off. | Set `VIGIL_ENABLED=true`. |
| `vigil:status --remote`: `The VIGIL_BASE_URL environment variable has not been configured.` | No base URL. | Set `VIGIL_BASE_URL`. |
| `vigil:status --remote`: `The Vigil product at [...] is not reachable: ...` | DNS, firewall, TLS or proxy problem between this server and the product. | Test with `curl -v $VIGIL_BASE_URL/api/ingest`; fix DNS, certificates or egress rules. A self-signed product certificate must be trusted by the server. |
| `vigil:status --remote`: `... rejected the token [HTTP 401]: ...` or `... answered HTTP 4xx/5xx: ...` | See the 401 and 422 rows below; any other status is the product's own answer. | Fix as described for that status. |

### Authentication and environment

| Symptom or log line | Cause | Fix |
| --- | --- | --- |
| Relay: `Authentication failed [0.1s]: 401 [Invalid token]: the product rejected token [abc...]. Payloads are spooled and kept until the token is fixed.` Later uploads: `Ingest refused [...]: 401 [Invalid token]. The product rejected VIGIL_TOKEN. Spooled data is kept; retrying every 300s until the token is fixed.` | The token is unknown to the product: typo, truncated, a token of another product, or it was regenerated. | Copy the token again from Settings, Projects, Show credentials in the product. Update `VIGIL_TOKEN` in the application and the relay, restart the relay. Spooled data is delivered afterwards. Only the first 3 characters of the token's hash are logged. |
| Same lines with `401 [Project ingest disabled]` | An admin disabled ingest for the project in the product. | Re-enable ingest for the project in the product. The relay keeps data and retries every 5 minutes, so nothing is lost while it is off (within the 1 GB spool cap). |
| Relay: `Environment rejected [...]: 422 [Unknown environment]. Payloads are spooled and kept; retrying every 60s until the environment exists or VIGIL_ENVIRONMENT is fixed.` Later: `Ingest refused [...]: 422 [Unknown environment]. The product does not accept VIGIL_ENVIRONMENT. ...` | The project does not have an environment with that name and the project is set not to create environments automatically. | Create the environment in the product, or fix `VIGIL_ENVIRONMENT` (it defaults to `APP_ENV`, which may be `local` or `prod` rather than the name you expect). Restart the relay after changing the variable. |
| Same lines with `422 [Environment required]` | `VIGIL_ENVIRONMENT` is empty and the project has no default environment. | Set `VIGIL_ENVIRONMENT`, or set a default environment in the product. |
| Relay: `Ingest failed [...]: 422 [Invalid environment]; batch kept, retrying in 2.5s.` (backoff growing) | The product refuses the name itself: longer than 60 characters, empty after slug conversion, or reserved. This answer is not special-cased by the relay, so it backs off like a generic failure. | Use a short, simple `VIGIL_ENVIRONMENT`. |

### Spool, delivery and limits

| Symptom or log line | Cause | Fix |
| --- | --- | --- |
| Spool directory keeps growing; relay logs `Ingest failed [...]: ...; batch kept, retrying in Ns.` or `Ingest deferred [...]: ...; retrying in Ns.` | The product is unreachable, slow, or answering 5xx, 429 or 503. Data is kept and retried with backoff up to 300 s. | Check the product's health and the network. Nothing needs to be done on the client; the backlog ships when the product recovers. For `503 [Spool full]` the product's own intake is backed up; for `429 [Too many requests]` the project's ingest rate limit was exceeded and the relay honours `retry_in`. |
| `Ingest failed [...]: 404 [HTTP ingest is disabled]; batch kept, retrying in Ns.` | The product has HTTP ingest turned off, so the relay cannot upload. | Enable HTTP ingest in the product, or use direct TCP mode by pointing `VIGIL_INGEST_URI` at the product's collector. |
| `Spool is full [1024 MB of 1024 MB]: closing connections without acknowledging [N refused].` (once a minute) | More than `VIGIL_AGENT_SPOOL_MAX_BYTES` is waiting to be uploaded. New frames are refused and those executions' records are dropped. Your application is not affected. | Fix the reason uploads fail (see the rows above). It recovers by itself once the backlog is below the cap. Raise the cap only if the disk can take it. |
| Files in `spool/dead/`; relay log `Ingest rejected [...]: 413 [...]; batch moved to [dead/....ndjson].` or `400 [...]` | The product refused the batch as invalid (400) or too large (413). A 413 can come from the product's own limits (its request size and PHP `post_max_size`) or from a reverse proxy in front of it (for example nginx `client_max_body_size`), when they are below the size of one upload. | Read the matching `.error.json` for the status and message. For 413 raise the product-side or proxy limit, or lower `VIGIL_AGENT_UPLOAD_BYTES` (default 8 MB of raw records) so uploads are smaller. Dead batches are never retried automatically. After fixing the cause you can move a `dead/*.ndjson` file back into the spool directory to have it uploaded; try one file first. Delete files you do not need. |
| `Ingest rejected [...]; unable to dead-letter the batch, keeping it: ...` | The relay could not write to `dead/` (permissions, disk). | Fix permissions or free space on the spool volume. |
| `Spool write to [....open] failed: payload not acknowledged.` or `Unable to create a spool segment in [...]: payload not acknowledged.` | The spool directory is not writable or the disk is full. Frames are refused, so records are lost. | Fix ownership (the relay user must write to the directory), free disk space, or point `VIGIL_AGENT_SPOOL_PATH` somewhere writable. |
| `Unable to read [...]: ...` / `Skipped [N] unreadable line(s) in [...].` | A segment file is unreadable or has damaged lines (disk problem, a manual edit). Damaged lines are skipped. | Check the disk; do not edit segments by hand. |
| `Another Vigil relay is already using the spool [...].` (relay exits with code 1) | A second relay was started with the same spool directory. | Run one relay per spool. Use a different `--spool` or stop the other process. |
| `Unable to listen on [127.0.0.1:2407]: ...` (exit code 1) | The port is in use (a second relay or another program) or the address is invalid. | Free the port or change `VIGIL_INGEST_URI` on both sides. |
| `Unable to read the signature [...]` (exit code 1) | `agent/build/signature.txt` is missing next to the PHAR, for example after an incomplete copy. | Reinstall the package so `agent/build/` contains both `agent.phar` and `signature.txt`. |
| `Drain time is up: [N] segment(s) stay on disk for the next start` or `Upload failed while draining: ...` | Shutdown could not upload everything in `VIGIL_AGENT_DRAIN_SECONDS`. Not an error: data stays in the spool. | None needed. Raise `VIGIL_AGENT_DRAIN_SECONDS` and your process manager's stop timeout if you want fuller drains. |
| `Connectivity check failed [...]: ... Payloads are spooled and retried.` at startup | The product was unreachable at that moment or answered a non-auth error. | The relay continues and retries. If it persists, see `vigil:status --remote`. |

### Frames from the application

| Symptom or log line | Cause | Fix |
| --- | --- | --- |
| `Incoming payload version has changed [v2]; expected [v1]` and the relay shuts down | The application runs a newer client than the relay. This is deliberate: the relay exits and relies on the process manager to restart on the matching code. | Make sure a process manager restarts the relay. If it keeps happening, an old relay process (another release, or an old Docker image) is still running: stop it and run one built from the same package version. |
| `Agent signature changed: shutting down in 300s` | A deploy replaced the package. Normal. | Nothing, as long as the relay is supervised. |
| Relay stayed down after a deploy | No process manager, or it does not restart on exit code 0 (systemd `Restart=on-failure` is not enough; use `Restart=always`). | Use `autorestart=true` (Supervisor) or `Restart=always` (systemd). See [Running the relay](03-running-the-relay.md#running-under-a-process-manager). |
| `Incoming token hash mismatch [abc1234]! Check your application/agent configuration (VIGIL_TOKEN).` | The application's `VIGIL_TOKEN` differs from the relay's. Frames are refused and records dropped. | Use the same token in both. Check queue workers and schedulers too; they may be running with an old environment. |
| `Malformed payload received. Length: [...] Received: [...]` | A client sent something that is not a Vigil frame (a port scanner, a wrong service configured to the port). | Check what connects to the relay's port; keep the port on a private interface. |
| `Incomplete payload received. Length: [...] Received: [...]` | A client disconnected before the whole frame arrived, for example a PHP process killed mid-write or a timeout on a congested host. Occasional lines are harmless. | Investigate only if frequent: look at CPU pressure and the application's timeouts. |

### Nothing appears, or the application is slow

| Symptom | Cause | Fix |
| --- | --- | --- |
| Nothing appears in the product | A chain of causes; check in order: 1) `VIGIL_ENABLED` is true; 2) the application's config is not cached with old values; 3) `vigil:status` and `--remote` succeed; 4) the relay runs and (with `-v`) logs `Uploading ...` and `Ingest successful [...]`; 5) the sample rates are not `0.0` and the route or command is not on an exclusion list; 6) you are looking at the right project and environment in the product; 7) queue workers, Octane and Horizon were restarted after the change. | Fix the first step that fails. For the "no data from workers" case, queue workers hold configuration in memory until restarted. |
| The relay logs `Ingest successful` but the product shows nothing for the environment you are looking at | The environment name differs from the one you view (for example `prod` versus `production`). | Compare `VIGIL_ENVIRONMENT` with the environment names in the product. |
| Artisan command or job missing | The command is on the default ignore list (for example `queue:monitor`, `horizon:snapshot`, `schedule:list`), or job attempts follow the sampling decision of the process that dispatched them. | See [Usage in code](05-usage-in-code.md#sampling-and-ignoring). |
| No logs in the product | The `vigil` log channel is not in the log stack. | Add it, for example `LOG_STACK=daily,vigil`. |
| Request latency increased | The client could not reach the relay or collector and waited for its timeouts (0.5 s to connect and 0.5 s for the `2:OK`) at the end of the request, or the relay is overloaded. Direct TCP over a slow link does this on every request. `VIGIL_AGENT_SPOOL_FSYNC=true` adds per-frame disk sync latency. | Check `vigil:status`; keep the relay on loopback; use the relay instead of direct TCP across a network; do not raise `VIGIL_INGEST_TIMEOUT` or `VIGIL_INGEST_CONNECTION_TIMEOUT` unless needed; leave fsync off unless you need power-loss durability. |
| Missing records on very large requests or commands | A sampled execution transmits early when 500 records are buffered; an unsampled one keeps only the newest 500. | Call `Vigil::digest()` in long loops, or raise `VIGIL_INGEST_EVENT_BUFFER`. See [Usage in code](05-usage-in-code.md#long-running-processes). |
| `vigil:deploy` printed an error but CI passed | `vigil:deploy` always exits 0. | Read its output; see [Deployments](06-deployments.md#exit-code). |

## Collecting information for support

Run the relay with `-v` for a while and keep the log, note the output of both status commands, the contents of any `dead/*.error.json`, and the package version (`composer show materom/vigil`). Never include the token; the logs only show the first 3 characters of its hash.
