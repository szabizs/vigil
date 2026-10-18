# Usage in code

Everything you can do from your application is on the `Vigil` facade:

```php
use Materom\Vigil\Facades\Vigil;
```

Register callbacks (`user`, `reject*`, `redact*`) once during boot, for example in `AppServiceProvider::boot()`. They apply to the whole process lifetime, which also matters under Octane and queue workers.

## Identifying users

By default, the authenticated user is recorded with `id` (the auth identifier), `name` (`$user->name`) and `username` (`$user->email`). To change what is sent, pass a callback that returns an array. `id` defaults to `getAuthIdentifier()` and is kept unless your array contains an `id` key; return `name` and `username` as needed:

```php
Vigil::user(fn ($user) => [
    'name' => $user->full_name,
    'username' => $user->employee_number,
]);
```

Return only what you are comfortable storing in the monitoring product. The result is resolved once per execution and reused.

## Rejecting records

`reject*` methods take a callback that receives the record and returns `true` to drop it. The callback is not recorded itself.

| Method | Callback receives | Typical use |
| --- | --- | --- |
| `Vigil::rejectQueries()` | `Materom\Vigil\Records\Query` (`sql`, `file`, `line`, `duration`, `connection`) | Drop noisy internal tables. |
| `Vigil::rejectCacheEvents()` | `CacheEvent` (`store`, `key`, `type`, `duration`, `ttl`) | Drop by store or event type. |
| `Vigil::rejectCacheKeys(array $keys)` | not a callback: a list of strings | Each entry is a regular expression (with delimiters) or an exact key. |
| `Vigil::rejectMail()` | `Mail` (`mailer`, `class`, `subject`, recipient counts) | Skip system mail. |
| `Vigil::rejectNotifications()` | `Notification` (`channel`, `class`, `duration`) | Skip a channel or class. |
| `Vigil::rejectOutgoingRequests()` | `OutgoingRequest` (`method`, `url`, `statusCode`, ...) | Skip health checks or a chatty host. |
| `Vigil::rejectQueuedJobs()` | `QueuedJob` (`name`, `connection`, `queue`, ...) | Skip dispatching of a job class. |

```php
use Materom\Vigil\Records\OutgoingRequest;
use Materom\Vigil\Records\Query;

Vigil::rejectQueries(fn (Query $query) => str_contains($query->sql, 'telescope_'));

Vigil::rejectOutgoingRequests(fn (OutgoingRequest $request) => str_contains($request->url, '/healthz'));

Vigil::rejectCacheKeys(['/^rate-limit:/', 'maintenance-flag']);
```

Namespace: all record classes are in `Materom\Vigil\Records`. Callbacks can be added more than once; a record is dropped if any callback returns `true`.

Limitations: there is no reject callback for requests, commands, exceptions or logs. Use sampling or the log level for those. `rejectQueuedJobs()` drops the record of the dispatch, not the job attempt on the worker.

### Built-in rejections

Cache keys matching these patterns are rejected by default, because they are framework internals:

```
/(^laravel_vapor_job_attemp(t?)s:)/
/^illuminate:(?!cache:flexible:created:)/
/^framework\/schedule/
/^laravel:pulse:/
/^laravel:reverb:/
/^nova/
/^telescope:/
/^livewire-checksum-failures:/
```

Call `Vigil::captureDefaultVendorCacheKeys()` to capture them anyway, and `Vigil::defaultVendorCacheKeys()` returns the list. Your own `rejectCacheKeys()` entries always apply.

## Redacting records

`redact*` methods take a callback that may modify the record before it is sent. Only the non-`readonly` properties can be changed.

| Method | Record | Editable properties |
| --- | --- | --- |
| `Vigil::redactRequests()` | `Request` | `url`, `ip`, `headers`, `payload`, `files` |
| `Vigil::redactQueries()` | `Query` | `sql` |
| `Vigil::redactExceptions()` | `Exception` | `message` |
| `Vigil::redactCommands()` | `Command` | `command` |
| `Vigil::redactMail()` | `Mail` | `subject` |
| `Vigil::redactOutgoingRequests()` | `OutgoingRequest` | `url` |
| `Vigil::redactCacheEvents()` | `CacheEvent` | `key` |

```php
use Materom\Vigil\Records\Request;

Vigil::redactRequests(function (Request $request) {
    $request->url = preg_replace('#/reset/[^/?]+#', '/reset/[token]', $request->url);
});

Vigil::redactOutgoingRequests(function ($request) {
    $request->url = preg_replace('/api_key=[^&]+/', 'api_key=[redacted]', $request->url);
});
```

Headers and payload fields can also be redacted by name with `VIGIL_REDACT_HEADERS` and `VIGIL_REDACT_PAYLOAD_FIELDS` ([Configuration](04-configuration.md#capture-and-redaction)).

## Sampling and ignoring

```php
Vigil::sample(0.25);      // capture this execution with 25 % probability
Vigil::dontSample();      // never capture this execution
Vigil::sampling();        // bool: is this execution being captured?
```

`sample()` decides for the current execution. `Vigil::dontSample()` is `sample(0)`. Rates are in `config/vigil.php` per type; these calls override the decision for one execution. A rate outside 0 to 1 is treated as 0.

Per route, with the middleware (`Materom\Vigil\Http\Middleware\Sample`):

```php
use Materom\Vigil\Http\Middleware\Sample;

Route::get('/ping', PingController::class)->middleware(Sample::never());
Route::get('/feed', FeedController::class)->middleware(Sample::rate(0.1));
Route::get('/checkout', CheckoutController::class)->middleware(Sample::always());
```

Per scheduled task (`Materom\Vigil\Console\Sample`):

```php
use Materom\Vigil\Console\Sample;

$schedule->command('reports:daily')->daily()->tap(Sample::always());
$schedule->command('cache:warm')->everyMinute()->tap(Sample::never());
```

Suspend capture for a block, or manually:

```php
$result = Vigil::ignore(fn () => $this->bulkImport());   // nothing inside is captured

Vigil::pause();
Vigil::resume();
Vigil::paused();   // bool
```

`ignore()` returns the callback's result and restores the previous state afterwards, even on exceptions. Sampling decisions (and `ignore()`) are also written to Laravel's hidden context, so jobs dispatched inside an ignored or unsampled block are not captured either.

Records that are not sampled never leave the process. Unhandled exceptions are an exception: an unsampled execution still sends them according to `VIGIL_EXCEPTION_SAMPLE_RATE`.

**Jobs.** The commands sample rate does not apply to queue workers. Each job attempt is captured unless the process that dispatched the job was unsampled or inside `ignore()`/`pause()`.

**Vendor commands.** These commands are never captured by default: `auth:clear-resets`, `config:cache`, `horizon:snapshot`, `horizon:status`, `horizon:supervisor`, `inertia:start-ssr`, `invoke-serialized-closure`, `model:prune`, `octane:status`, `queue:monitor`, `reverb:start`, `schedule:list` and `vigil:status`. `help`, `inspire` and `schedule:finish` are skipped as well. Call `Vigil::captureDefaultVendorCommands()` to capture the listed ones. `Vigil::defaultVendorCommands()` returns the list.

## Outgoing requests

Requests made through Laravel's `Http` facade are captured automatically. For a Guzzle client you build yourself, add the middleware to its handler stack:

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Materom\Vigil\Facades\Vigil;

$stack = HandlerStack::create();
$stack->push(Vigil::guzzleMiddleware());

$client = new Client(['handler' => $stack]);
```

Only responses are recorded: a request that fails with an exception (connection error) is not. If outgoing requests are ignored (`VIGIL_IGNORE_OUTGOING_REQUESTS=true`) or capture is paused when the client is created, the middleware does nothing.

## Reporting exceptions

Unhandled exceptions and everything passed through Laravel's exception handler are reported automatically. To report an exception you caught yourself:

```php
try {
    $gateway->charge($order);
} catch (GatewayException $e) {
    Vigil::report($e, handled: true);

    // fall back to something else
}
```

`handled: true` marks the exception as handled, so the product can tell it apart from crashes. The record is buffered with the execution instead of being sent immediately.

Without the `handled` argument, Vigil infers it: it is treated as handled when the call comes through Laravel's global `report()` helper, and as unhandled otherwise. Prefer passing it explicitly. Exceptions your handler lists in `$dontReport` are skipped when you pass `handled` explicitly. Exceptions that reach Laravel's exception handler while a scheduled task runs are not reported through the handler hook; call `Vigil::report()` yourself if you need them.

## Long-running processes

Records are sent when an execution ends: a request finishes, a command exits, a job attempt completes. A custom Artisan command that loops forever would never send anything. Call `Vigil::digest()` to transmit what is buffered now:

```php
public function handle(): int
{
    while (! $this->shouldStop()) {
        $this->processBatch();

        Vigil::digest();   // transmit the records buffered so far
        sleep(1);
    }

    return self::SUCCESS;
}
```

If you do not call it, a sampled execution transmits early whenever 500 records are buffered (`VIGIL_INGEST_EVENT_BUFFER`). An unsampled one keeps only the newest 500.

What the package already handles by itself:

- **Queue workers** (`queue:work`, `queue:listen`, `horizon:work`, `vapor:work`): records are digested between jobs and when the worker loops or stops, and each job gets its own execution. You do not need `digest()` in jobs. `horizon:work` is the worker process Horizon starts; Horizon's own supervisor and snapshot commands are on the ignore list above. Vapor workers also reset per job.
- **Octane:** each request is prepared on Octane's `RequestReceived` event, so each request is its own execution.
- **Scheduler** (`schedule:run`, `schedule:work`, `vapor:schedule`): each task is captured as its own execution.
- **Livewire:** the component class is added to the request's route action.

After changing the code or `.env` on a server running Octane or workers, restart them. They hold the configuration in memory.

## Laravel Context and queue payloads

When a request dispatches a job, the job attempt is linked to the request through a trace id, and the sampling decision and user id are carried along:

- **Laravel 11 and newer:** the values travel in Laravel's hidden Context under the keys `nightwatch_trace_id`, `nightwatch_should_sample` and `nightwatch_user_id`.
- **Laravel 10 (no Context):** the same three values are stored in the job payload under the key `nightwatch`.
- Every queued job payload also carries `nightwatch.job_id`, a UUID that links the dispatch record to the attempt.

The `nightwatch` and `nightwatch_*` names are deliberately kept from the original package. This keeps jobs that were queued by `laravel/nightwatch` before you migrated linked to their attempts, and the product's record catalogue depends on them. Do not use these keys for anything else. If you want to read them, use `Context::getHidden('nightwatch_trace_id')`.

## Markers in records

Some fields cannot always be filled. Instead of failing, Vigil stores a small JSON marker. The name starts with `_nightwatch` for compatibility with the record format.

| Marker | Field | Meaning |
| --- | --- | --- |
| `{"_nightwatch_error":"NOT_ENABLED"}` | request `payload` | The request ended in HTTP 500 but payload capture is off. Set `VIGIL_CAPTURE_REQUEST_PAYLOAD=true` to record it. Not set for GET, HEAD, OPTIONS or TRACE requests with no payload. |
| `{"_nightwatch_error":"UNSUPPORTED_CONTENT_TYPE"}` | request `payload` | Capture is on, but the request body is not JSON, `application/x-www-form-urlencoded` or `multipart/form-data`, and nothing was parsed. |
| `{"_nightwatch_error":"SERIALIZATION_FAILED"}` | request `payload` | The payload could not be turned into JSON. |
| `{"_nightwatch_error":"Failed to serialize headers"}` | request `headers` | Headers could not be serialized. |
| `{"_nightwatch_error":"Failed to serialize context"}` | `context` | Laravel Context could not be serialized. |
| `_nightwatch_files` (a key inside `payload`) | request `payload` | Not an error: metadata of uploaded files (`originalName`, `size`, `error`). Contents are never sent. |

The payload field is empty for any response that is not HTTP 500.

## Hooks and events

- `Materom\Vigil\Events\IngestingEvents` is dispatched just before a batch is transmitted, with the records (`->records`, `->eventCount()`). A listener that returns `false` stops the batch from being sent.
- `Vigil::handleUnrecoverableExceptionsUsing(fn (Throwable $e) => ...)` sets a callback for errors inside the agent itself. By default these are swallowed so that monitoring never breaks your application.
