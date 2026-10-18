<?php

namespace Materom\Vigil;

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Cache\Events\WritingManyKeys;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\Queue;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestReceived;
use Livewire\Livewire;
use Livewire\LivewireManager;
use Materom\Vigil\Console\AgentCommand;
use Materom\Vigil\Console\DeployCommand;
use Materom\Vigil\Console\StatusCommand;
use Materom\Vigil\Contracts\Ingest as IngestContract;
use Materom\Vigil\Facades\Vigil;
use Materom\Vigil\Factories\Logger;
use Materom\Vigil\Hooks\ArtisanStartingListener;
use Materom\Vigil\Hooks\CacheEventListener;
use Materom\Vigil\Hooks\CommandBootedHandler;
use Materom\Vigil\Hooks\CommandStartingListener;
use Materom\Vigil\Hooks\ContextDehydratingHandler;
use Materom\Vigil\Hooks\CreateQueuePayloadHandler;
use Materom\Vigil\Hooks\ExceptionHandlerResolvedHandler;
use Materom\Vigil\Hooks\GlobalMiddleware;
use Materom\Vigil\Hooks\HttpClientFactoryResolvedHandler;
use Materom\Vigil\Hooks\HttpKernelResolvedHandler;
use Materom\Vigil\Hooks\LivewireListener;
use Materom\Vigil\Hooks\LogoutListener;
use Materom\Vigil\Hooks\MailListener;
use Materom\Vigil\Hooks\NotificationListener;
use Materom\Vigil\Hooks\OctaneListener;
use Materom\Vigil\Hooks\PolyfillContextDehydration;
use Materom\Vigil\Hooks\PolyfillContextHydration;
use Materom\Vigil\Hooks\PreparingResponseListener;
use Materom\Vigil\Hooks\QueryExecutedListener;
use Materom\Vigil\Hooks\QueuedJobListener;
use Materom\Vigil\Hooks\RequestBootedHandler;
use Materom\Vigil\Hooks\RequestHandledListener;
use Materom\Vigil\Hooks\ResponsePreparedListener;
use Materom\Vigil\Hooks\RouteMatchedListener;
use Materom\Vigil\Hooks\RouteMiddleware;
use Materom\Vigil\Hooks\TerminatingListener;
use Materom\Vigil\Http\Middleware\Sample;
use Materom\Vigil\State\CommandState;
use Materom\Vigil\State\RequestState;
use Materom\Vigil\Support\Uuid;
use Ramsey\Uuid\Uuid as BaseUuid;
use Throwable;

use function class_exists;
use function defined;
use function hash;
use function microtime;
use function substr;

/**
 * @internal
 */
final class VigilServiceProvider extends ServiceProvider
{
    /**
     * @var Core<RequestState|CommandState>
     */
    private Core $core;

    private float $timestamp;

    private bool $isRequest;

    private Repository $config;

    /**
     * @var array{
     *     enabled?: bool,
     *     sampling?: array{
     *        requests?: float,
     *        commands?: float,
     *        exceptions?: float,
     *        scheduled_tasks?: float,
     *     },
     *     filtering?: array{
     *         ignore_cache_events?: bool,
     *         ignore_mail?: bool,
     *         ignore_notifications?: bool,
     *         ignore_outgoing_requests?: bool,
     *         ignore_queries?: bool,
     *         log_level?: \Psr\Log\LogLevel::*,
     *     },
     *     token?: string,
     *     deployment?: string,
     *     server?: string,
     *     base_url?: string,
     *     ingest?: array{ uri?: string, timeout?: float|int, connection_timeout?: float|int, event_buffer?: int },
     *     agent?: array<string, mixed>,
     *     capture_exception_source_code?: bool,
     *     capture_request_payload?: bool,
     *     redact_payload_fields?: string[],
     *     redact_headers?: string[],
     *  }
     */
    private array $vigilConfig;

    private ?Throwable $registerException = null;

    private static ?string $initialTrace = null;

    public function register(): void
    {
        try {
            $this->captureTimestamp();
            Compatibility::boot($this->app);
            $this->captureExecutionType();
            $this->registerAndCaptureConfig();
            $this->registerBindings();

            if (! $this->core->enabled()) {
                return;
            }

            $this->registerHooks();
        } catch (Throwable $e) {
            $this->registerException = $e;
        }
    }

    public function boot(): void
    {
        try {
            if ($this->registerException) {
                $this->handleAndClearRegisterException();

                return;
            }

            if ($this->app->runningInConsole()) {
                $this->registerPublications();
                $this->registerCommands();
            }
        } catch (Throwable $e) {
            Vigil::unrecoverableExceptionOccurred($e);
        }
    }

    private function captureTimestamp(): void
    {
        $this->timestamp = match (true) {
            defined('LARAVEL_START') => LARAVEL_START,
            default => $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),
        };
    }

    private function captureExecutionType(): void
    {
        $this->isRequest = ! $this->app->runningInConsole() || Env::get('VIGIL_FORCE_REQUEST');
    }

    private function registerAndCaptureConfig(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vigil.php', 'vigil');

        $this->config = $this->app->make(Repository::class);

        $this->vigilConfig = $this->config->get('vigil') ?? []; // @phpstan-ignore assign.propertyType
    }

    private function registerBindings(): void
    {
        $this->registerLogger();
        $this->registerMiddleware();
        $this->registerStatusCommand();
        $this->registerDeployCommand();
        $this->registerAgentCommand();
        $this->buildAndRegisterCore();
    }

    private function registerLogger(): void
    {
        if (! $this->config->has('logging.channels.vigil')) {
            $this->config->set('logging.channels.vigil', [
                'driver' => 'custom',
                'via' => Logger::class,
                'level' => $this->vigilConfig['filtering']['log_level'] ?? 'debug',
            ]);
        }

        $this->app->singleton(Logger::class, fn () => new Logger($this->core));
    }

    private function registerMiddleware(): void
    {
        $this->app->singleton(RouteMiddleware::class, fn () => new RouteMiddleware($this->core)); // @phpstan-ignore argument.type

        $this->app->scoped(GlobalMiddleware::class, fn () => new GlobalMiddleware($this->core)); // @phpstan-ignore argument.type

        $this->app->singleton(Sample::class, fn () => new Sample($this->core)); // @phpstan-ignore argument.type
    }

    private function registerStatusCommand(): void
    {
        $this->app->singleton(StatusCommand::class, fn () => new StatusCommand(
            token: $this->vigilConfig['token'] ?? null,
            ingestUri: $this->vigilConfig['ingest']['uri'] ?? '127.0.0.1:2407',
            connectionTimeout: (float) ($this->vigilConfig['ingest']['connection_timeout'] ?? 0.5),
            timeout: (float) ($this->vigilConfig['ingest']['timeout'] ?? 0.5),
            enabled: (bool) ($this->vigilConfig['enabled'] ?? true),
            baseUrl: $this->vigilConfig['base_url'] ?? null,
            server: $this->vigilConfig['server'] ?? null,
            environment: $this->vigilConfig['environment'] ?? null,
            remoteConnectionTimeout: (float) ($this->vigilConfig['agent']['auth_connection_timeout'] ?? 5),
            remoteTimeout: (float) ($this->vigilConfig['agent']['auth_timeout'] ?? 10),
        ));
    }

    private function registerAgentCommand(): void
    {
        $this->app->singleton(AgentCommand::class, fn () => new AgentCommand(
            token: $this->vigilConfig['token'] ?? null,
            baseUrl: $this->vigilConfig['base_url'] ?? null,
            server: $this->vigilConfig['server'] ?? null,
            environment: $this->vigilConfig['environment'] ?? null,
            ingestUri: $this->vigilConfig['ingest']['uri'] ?? null,
            agent: $this->vigilConfig['agent'] ?? [], // @phpstan-ignore argument.type
        ));
    }

    private function registerDeployCommand(): void
    {
        $this->app->singleton(DeployCommand::class, fn () => new DeployCommand(
            token: $this->vigilConfig['token'] ?? null,
            baseUrl: $this->vigilConfig['base_url'] ?? null,
            environment: $this->vigilConfig['environment'] ?? null,
        ));
    }

    private function buildAndRegisterCore(): void
    {
        $clock = new Clock;
        $uuid = new Uuid(static fn () => BaseUuid::uuid4()->toString());
        $trace = self::$initialTrace ??= $this->isRequest && Compatibility::$isLaravelCloud
            ? ($_SERVER['HTTP_CLOUD_REQUEST_ID'] ?? $uuid->make())
            : $uuid->make();
        $executionState = $this->executionState($trace);
        $tokenHash = substr(hash('xxh128', $this->vigilConfig['token'] ?? ''), 0, 7);

        $this->app->instance(Core::class, $this->core = new Core(
            ingest: $this->app->bound(IngestContract::class) ? $this->app->make(IngestContract::class) : new Ingest(
                transmitTo: $this->vigilConfig['ingest']['uri'] ?? '127.0.0.1:2407',
                connectionTimeout: $this->vigilConfig['ingest']['connection_timeout'] ?? 0.5,
                timeout: $this->vigilConfig['ingest']['timeout'] ?? 0.5,
                streamFactory: new SocketStreamFactory,
                buffer: new RecordsBuffer(
                    length: $this->vigilConfig['ingest']['event_buffer'] ?? 500,
                ),
                tokenHash: $tokenHash,
                events: $this->app->make(Dispatcher::class),
            ),
            sensor: new SensorManager(
                executionState: $executionState,
                clock: $clock = new Clock,
                location: new Location(
                    basePath: $this->app->basePath(),
                    publicPath: $this->app->publicPath(),
                ),
                captureExceptionSourceCode: (bool) ($this->vigilConfig['capture_exception_source_code'] ?? true),
                captureRequestPayload: (bool) ($this->vigilConfig['capture_request_payload'] ?? false),
                redactPayloadFields: $this->vigilConfig['redact_payload_fields'] ?? ['_token', 'password', 'password_confirmation'],
                redactHeaders: $this->vigilConfig['redact_headers'] ?? ['Authorization', 'Cookie', 'Proxy-Authorization', 'X-XSRF-TOKEN'],
                container: $this->app,
            ),
            executionState: $executionState,
            clock: $clock,
            uuid: $uuid,
            config: [
                'enabled' => $this->vigilConfig['enabled'] ?? true,
                'sampling' => [
                    'requests' => $this->vigilConfig['sampling']['requests'] ?? 1.0,
                    'commands' => $this->vigilConfig['sampling']['commands'] ?? 1.0,
                    'exceptions' => $this->vigilConfig['sampling']['exceptions'] ?? 1.0,
                    'scheduled_tasks' => $this->vigilConfig['sampling']['scheduled_tasks'] ?? 1.0,
                ],
                'filtering' => [
                    'ignore_cache_events' => (bool) ($this->vigilConfig['filtering']['ignore_cache_events'] ?? false),
                    'ignore_mail' => (bool) ($this->vigilConfig['filtering']['ignore_mail'] ?? false),
                    'ignore_notifications' => (bool) ($this->vigilConfig['filtering']['ignore_notifications'] ?? false),
                    'ignore_outgoing_requests' => (bool) ($this->vigilConfig['filtering']['ignore_outgoing_requests'] ?? false),
                    'ignore_queries' => (bool) ($this->vigilConfig['filtering']['ignore_queries'] ?? false),
                ],
            ],
        ));
    }

    private function handleAndClearRegisterException(): void
    {
        Vigil::unrecoverableExceptionOccurred($this->registerException); // @phpstan-ignore argument.type

        $this->registerException = null;
    }

    private function registerPublications(): void
    {
        $this->publishes([
            __DIR__.'/../config/vigil.php' => $this->app->configPath('vigil.php'),
        ], ['vigil', 'vigil-config']);
    }

    private function registerCommands(): void
    {
        $this->commands([
            AgentCommand::class,
            StatusCommand::class,
            DeployCommand::class,
        ]);
    }

    private function registerHooks(): void
    {
        $core = $this->core;

        /** @var Dispatcher */
        $events = $this->app->make(Dispatcher::class);

        //
        // -------------------------------------------------------------------------
        // Sensor hooks
        // --------------------------------------------------------------------------
        //

        /**
         * @see Records\Query
         */
        $events->listen(QueryExecuted::class, (new QueryExecutedListener($core))(...));

        /**
         * @see Records\Exception
         */
        $this->callAfterResolving(ExceptionHandler::class, (new ExceptionHandlerResolvedHandler($core))(...));

        /**
         * @see Records\QueuedJob
         */
        $events->listen([JobQueueing::class, JobQueued::class], (new QueuedJobListener($core))(...));

        /**
         * @see Records\Notification
         */
        $events->listen([NotificationSending::class, NotificationSent::class], (new NotificationListener($core))(...));

        /**
         * @see Records\Mail
         */
        $events->listen([MessageSending::class, MessageSent::class], (new MailListener($core))(...));

        /**
         * @see Records\OutgoingRequest
         */
        $this->callAfterResolving(Http::class, (new HttpClientFactoryResolvedHandler($core))(...));

        /**
         * @see Records\CacheEvent
         */
        $events->listen([
            RetrievingKey::class,
            RetrievingManyKeys::class,
            CacheHit::class,
            CacheMissed::class,
            WritingKey::class,
            WritingManyKeys::class,
            KeyWritten::class,
            KeyWriteFailed::class,
            ForgettingKey::class,
            KeyForgotten::class,
            KeyForgetFailed::class,
        ], (new CacheEventListener($core))(...));

        $events->listen(RequestReceived::class, (new OctaneListener($core))(...)); // @phpstan-ignore class.notFound

        Queue::createPayloadUsing(new CreateQueuePayloadHandler($core));

        if (Compatibility::$contextExists) {
            Context::dehydrating(new ContextDehydratingHandler($core));
        } else {
            Queue::createPayloadUsing(new PolyfillContextDehydration($core));
            $events->listen((new PolyfillContextHydration($core))(...));
        }

        //
        // -------------------------------------------------------------------------
        // Execution stage hooks
        // --------------------------------------------------------------------------
        //

        if ($this->isRequest) {
            /** @var Core<RequestState> $core */
            $this->registerRequestHooks($events, $core);
        } else {
            /** @var Core<CommandState> $core */
            $this->registerConsoleHooks($events, $core);
        }

        /** @var Core<RequestState|CommandState> $core */

        /**
         * @see ExecutionStage::Terminating
         */
        $events->listen(Terminating::class, (new TerminatingListener($core))(...));
    }

    /**
     * @param  Core<RequestState>  $core
     */
    private function registerRequestHooks(Dispatcher $events, Core $core): void
    {
        // TODO resolve the kernel inline rather than in the listener.

        /**
         * @see RequestState::$user
         *
         * TODO handle this on the queue
         */
        $events->listen(Logout::class, (new LogoutListener($core))(...));

        /**
         * @see ExecutionStage::BeforeMiddleware
         */
        $this->app->booted((new RequestBootedHandler($core))(...));

        /**
         * @see ExecutionStage::Action
         * @see ExecutionStage::Terminating
         */
        $events->listen(RouteMatched::class, (new RouteMatchedListener($core))(...));

        /**
         * @see ExecutionStage::Render
         */
        $events->listen(PreparingResponse::class, (new PreparingResponseListener($core))(...));

        /**
         * @see ExecutionStage::AfterMiddleware
         */
        $events->listen(ResponsePrepared::class, (new ResponsePreparedListener($core))(...));

        /**
         * @see ExecutionStage::Sending
         */
        $events->listen(RequestHandled::class, (new RequestHandledListener($core))(...));

        /**
         * @see ExecutionStage::End
         * @see Records\Request
         * @see ExecutionStage::Terminating
         * @see Core::finishExecution()
         */
        $this->callAfterResolving(HttpKernelContract::class, (new HttpKernelResolvedHandler($core))(...));

        $this->registerLivewireHooks($core);
    }

    /**
     * @param  Core<CommandState>  $core
     */
    private function registerConsoleHooks(Dispatcher $events, Core $core): void
    {
        /** @var ConsoleKernelContract */
        $kernel = $this->app->make(ConsoleKernelContract::class);

        /**
         * @see CommandState::$artisan
         */
        $events->listen(ArtisanStarting::class, (new ArtisanStartingListener($core))(...));

        /**
         * @see ExecutionStage::Action
         */
        $this->app->booted((new CommandBootedHandler($core))(...));

        /**
         * @see CommandState::$name
         *
         * Commands...
         * @see ExecutionStage::Terminating
         * @see ExecutionStage::End
         * @see Records\Command
         * @see Core::finishExecution()
         *
         * Jobs...
         * @see CommandState::$source
         * @see CommandState::flush()
         * @see CommandState::$timestamp
         * @see CommandState::$id
         * @see Records\JobAttempt
         * @see Records\Exception
         *
         * Scheduled tasks...
         * @see Core::finishExecution()
         */
        $events->listen(CommandStarting::class, (new CommandStartingListener($events, $core, $kernel))(...));
    }

    /**
     * @param  Core<RequestState>  $core
     */
    private function registerLivewireHooks(Core $core): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        $this->app->booted(static function ($app) use ($core) {
            if (! $app->bound(LivewireManager::class)) {
                return;
            }

            $listener = new LivewireListener($core);

            // Livewire 2
            Livewire::listen('component.hydrate.subsequent', $listener->componentHydrateSubsequent(...));

            // Livewire 3
            Livewire::listen('hydrate', $listener->hydrate(...));
        });
    }

    private function executionState(string $trace): RequestState|CommandState
    {
        Compatibility::addTraceIdToContext($trace);

        if ($this->isRequest) {
            return new RequestState(
                timestamp: $this->timestamp,
                trace: $trace,
                id: $trace,
                currentExecutionStageStartedAtMicrotime: $this->timestamp,
                deploy: $this->vigilConfig['deployment'] ?? '',
                server: $this->vigilConfig['server'] ?? '',
                user: $this->userProvider(),
            );
        } else {
            return new CommandState(
                timestamp: $this->timestamp,
                trace: new LazyValue(function () {
                    return (string) Compatibility::getTraceIdFromContext(function () { // @phpstan-ignore cast.string
                        $trace = $this->core->uuid->make();

                        Compatibility::addTraceIdToContext($trace);

                        return $trace;
                    });
                }),
                id: $trace,
                currentExecutionStageStartedAtMicrotime: $this->timestamp,
                deploy: $this->vigilConfig['deployment'] ?? '',
                server: $this->vigilConfig['server'] ?? '',
                user: $this->userProvider(),
            );
        }
    }

    private function userProvider(): UserProvider
    {
        /** @var AuthManager */
        $auth = $this->app->make(AuthManager::class);

        return new UserProvider(
            fn (callable $callback) => $this->core->ignore(static fn () => $callback($auth)),
            fn () => $this->core->userDetailsResolver,
            fn () => $this->core->report(...),
        );
    }

    public static function flushState(): void
    {
        self::$initialTrace = null;
    }
}
