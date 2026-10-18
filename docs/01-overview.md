# Overview

## What the agent does

Vigil runs inside your Laravel application. Hooks on the framework's events record what happens during each HTTP request, Artisan command, queued job attempt and scheduled task. At the end of that execution the records are sent, in one batch, to a receiver on the same machine or on the network. A Vigil monitoring product stores them and shows them.

The package is a fork of `laravel/nightwatch` 1.30.1. The record format and the wire framing are unchanged, so the product understands exactly what the official client produces. The differences are the namespace (`Materom\Vigil`), the facade (`Vigil`), the `VIGIL_*` variables and the local relay (`vigil:agent`), which replaces Nightwatch's cloud forwarder.

## How data flows

There are two transport modes. What listens on `VIGIL_INGEST_URI` decides which one you use. The application does not know the difference.

### Recommended: local relay with a disk spool

```
app process --TCP loopback--> vigil:agent --append + flush--> local spool on disk
                                                                   |
                                          gzip, HTTPS POST /api/ingest (Bearer token)
                                                                   v
                                                         the Vigil product
```

1. The application process finishes a request, command, job or task. It opens a TCP connection to `VIGIL_INGEST_URI` (default `127.0.0.1:2407`), writes one frame holding the buffered records and waits for `2:OK`.
2. The relay (`php artisan vigil:agent`) appends the frame as one line to a segment file in the spool directory and answers `2:OK` only after the line was written and flushed to the operating system.
3. A background uploader ships closed segments, oldest first, as gzip-compressed `POST {VIGIL_BASE_URL}/api/ingest` requests with the project token as Bearer token.
4. When the product answers `202`, the segment is deleted. If the product is down or refuses, the data stays on disk and is retried.

The application never waits on the network or on the product. It only waits for a loopback write to disk.

### Alternative: direct TCP to the product's collector

Point `VIGIL_INGEST_URI` at the product's own TCP collector (the product runs it as `php artisan watch:agent`, default port 2407). The application then transmits straight to the collector at the end of every execution.

This needs no process on the application server, but there is no spool. A transmission that fails or times out is swallowed and those records are lost. On a slow or lossy link every execution also waits for the network, bounded by the timeouts below. Use direct mode only when the collector is on the same host or on a fast, reliable local network. See [Installation](02-installation.md) for how to point at it.

## What is captured

| Record | Captured when |
| --- | --- |
| Requests | Every HTTP request: route, status, duration, stage timings, sizes, counts of queries, cache events, mail, and so on. |
| Queries | Every executed query: SQL text as Laravel reports it, duration, connection, file and line. Query bindings are not part of the record. |
| Exceptions | Unhandled exceptions, exceptions Laravel reports, and anything you pass to `Vigil::report()`. Includes the trace and, by default, source code snippets. |
| Logs | Entries written to the `vigil` log channel (you add it to your log stack, see [Configuration](04-configuration.md#logging)). |
| Queued jobs and job attempts | Dispatching a job, and each attempt to process it (processed, released or failed). |
| Commands | Artisan commands, except the vendor commands listed in [Usage in code](05-usage-in-code.md#sampling-and-ignoring). |
| Scheduled tasks | Each task run by `schedule:run`, `schedule:work` or `vapor:schedule`, including skipped and failed ones. |
| Cache events | Hits, misses, writes and forgets, except keys of framework and vendor internals. |
| Mail | Each mail sent. Recipients are recorded as counts, not addresses. |
| Notifications | Each notification sent. |
| Outgoing requests | Requests made with Laravel's `Http` client, and with any Guzzle client that uses `Vigil::guzzleMiddleware()`. |
| Users | The authenticated user (id, name, username) behind a request or job. Customizable with `Vigil::user()`. |

## What is not sent

- **Secrets in headers.** `Authorization`, `Cookie`, `Proxy-Authorization` and `X-XSRF-TOKEN` header values are redacted by default (`VIGIL_REDACT_HEADERS`).
- **Request bodies, by default.** The request payload is only captured for requests that end in HTTP 500, and only if you set `VIGIL_CAPTURE_REQUEST_PAYLOAD=true`. Even then `_token`, `password` and `password_confirmation` fields are replaced with `[N bytes redacted]` (`VIGIL_REDACT_PAYLOAD_FIELDS`). Uploaded files are recorded by metadata, not content. Without the opt-in, a failing request carries only a marker (see [Usage in code](05-usage-in-code.md#markers-in-records)).
- **Everything, when you sample.** With a sample rate below `1.0`, the records of unsampled executions are dropped in memory and never leave the process. Unhandled exceptions are the exception: they are still sent, according to the exception sample rate.
- **Anything you reject or ignore.** `VIGIL_IGNORE_*` flags, `Vigil::reject*()` callbacks and `Vigil::ignore()` stop records at the source. `Vigil::redact*()` callbacks let you edit records before they are sent.
- **The token itself.** Only the first 7 hex characters of `xxh128(VIGIL_TOKEN)` travel with each frame between the application and the relay. The relay sends the full token to the product over HTTPS, as a Bearer token. Use an `https://` base URL.

Redaction and filtering happen inside the application, before records leave the process.

## Performance characteristics

- **Short timeouts.** The client waits at most 0.5 s to connect and 0.5 s for the `2:OK` reply (`VIGIL_INGEST_CONNECTION_TIMEOUT`, `VIGIL_INGEST_TIMEOUT`). Against a local relay a connect or write takes well under a millisecond.
- **Failures never break your app.** If the relay or collector is unreachable or slow, the transmission is abandoned, the records of that execution are lost and your request, job or command carries on. Errors inside the agent are swallowed.
- **Bounded memory.** Records are buffered in memory (500 by default, `VIGIL_INGEST_EVENT_BUFFER`). When a sampled execution fills the buffer, it is transmitted early. An unsampled execution keeps only the most recent records, then discards them.
- **One transmission per execution.** Records are batched; a typical request causes a single short TCP exchange at its end.
- **Local relay cost.** The relay is a single small PHP process. See [Running the relay](03-running-the-relay.md#resource-usage).
