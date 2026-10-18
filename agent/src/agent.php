<?php

namespace Materom\VigilAgent;

use Closure;
use React\EventLoop\Loop as GlobalLoop;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ServerInterface;
use Throwable;

require __DIR__.'/../bootstrap.php';

/*
 * The Vigil relay agent.
 *
 * Run by `php artisan vigil:agent` (which sets the variables below and requires the PHAR) or directly with
 * `php agent.phar` (everything is read from the environment). Frames from the Vigil client arrive over TCP on
 * VIGIL_INGEST_URI, are appended to a local disk spool before they are acknowledged, and are shipped as gzip
 * batches to `{VIGIL_BASE_URL}/api/ingest`.
 */

$env = static function (string $key, mixed $default = null): mixed {
    $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

    return $value === false || $value === null || $value === '' ? $default : $value;
};

/*
 * Input...
 */

/** @var ?string $token */
$token ??= $env('VIGIL_TOKEN', '');
/** @var ?string $baseUrl */
$baseUrl ??= $env('VIGIL_BASE_URL', '');
/** @var ?string $listenOn */
$listenOn ??= $env('VIGIL_INGEST_URI', '127.0.0.1:2407');
/** @var ?string $spoolPath */
$spoolPath ??= $env('VIGIL_AGENT_SPOOL_PATH', getcwd().'/vigil-spool');
/** @var int|string|null $spoolSegmentBytes */
$spoolSegmentBytes ??= $env('VIGIL_AGENT_SPOOL_SEGMENT_BYTES', 4194304);
/** @var float|string|null $spoolSegmentSeconds */
$spoolSegmentSeconds ??= $env('VIGIL_AGENT_SPOOL_SEGMENT_SECONDS', 5);
/** @var int|string|null $spoolMaxBytes */
$spoolMaxBytes ??= $env('VIGIL_AGENT_SPOOL_MAX_BYTES', 1073741824);
/** @var bool|string|null $spoolFsync */
$spoolFsync ??= $env('VIGIL_AGENT_SPOOL_FSYNC', false);
/** @var int|string|null $uploadBytes */
$uploadBytes ??= $env('VIGIL_AGENT_UPLOAD_BYTES', 8388608);
/** @var int|string|null $concurrency */
$concurrency ??= $env('VIGIL_AGENT_CONCURRENCY', 2);
/** @var float|string|null $authenticationConnectionTimeout */
$authenticationConnectionTimeout ??= $env('VIGIL_AGENT_AUTH_CONNECTION_TIMEOUT', 5);
/** @var float|string|null $authenticationTimeout */
$authenticationTimeout ??= $env('VIGIL_AGENT_AUTH_TIMEOUT', 10);
/** @var float|string|null $ingestConnectionTimeout */
$ingestConnectionTimeout ??= $env('VIGIL_AGENT_INGEST_CONNECTION_TIMEOUT', 5);
/** @var float|string|null $ingestTimeout */
$ingestTimeout ??= $env('VIGIL_AGENT_INGEST_TIMEOUT', 30);
/** @var float|string|null $drainSeconds */
$drainSeconds ??= $env('VIGIL_AGENT_DRAIN_SECONDS', 10);
/** @var ?string $server */
$server ??= $env('VIGIL_SERVER', (string) gethostname());
/** @var ?string $environment */
$environment ??= $env('VIGIL_ENVIRONMENT', $env('APP_ENV', ''));
/** @var ?string $packageVersion */
$packageVersion ??= $env('VIGIL_AGENT_VERSION', 'unknown');
/** @var ?bool $silent */
$silent ??= strtolower((string) $env('VIGIL_AGENT_LOG_LEVEL', '')) === 'critical';
/** @var ?bool $quiet */
$quiet ??= strtolower((string) $env('VIGIL_AGENT_LOG_LEVEL', '')) === 'error';
/** @var ?bool $verbose */
$verbose ??= strtolower((string) $env('VIGIL_AGENT_LOG_LEVEL', '')) === 'verbose';

/*
 * Testing...
 */

/** @var ?LoopInterface $loop */
$loop ??= null;
/** @var ?Transport $transport */
$transport ??= null;
/** @var (Closure(): ServerInterface)|null $serverResolver */
$serverResolver ??= null;
/** @var ?string $signaturePath */
$signaturePath ??= null;
/** @var float|int|null $signatureCheckInterval */
$signatureCheckInterval ??= 60;
/** @var float|int|null $signatureShutdownDelay */
$signatureShutdownDelay ??= 300;
/** @var float|int|null $uploadInterval */
$uploadInterval ??= 1;

$exitCode = 0;

/*
 * Logging...
 */

$logger = new Logger(STDOUT, STDERR, Logger::levelFrom($silent, $quiet, $verbose));

/*
 * Signature...
 */

$basePath = str_replace(['phar://', '/agent.phar/src'], '', __DIR__);
$signaturePath ??= SignatureWatcher::resolvePath($basePath);
$logger->debug("Reading signature from [{$signaturePath}]");
$expectedSignature = @file_get_contents($signaturePath);

if ($expectedSignature === false) {
    $logger->critical("Unable to read the signature [{$signaturePath}]");
    $exitCode = 1;

    return;
}

$logger->debug('Found signature ['.substr(rtrim($expectedSignature), 0, 16).'...]');

/*
 * Configuration checks...
 */

$token = (string) $token;
$baseUrl = rtrim((string) $baseUrl, '/');

if ($token === '') {
    $logger->critical('The VIGIL_TOKEN environment variable has not been configured.');
    $exitCode = 1;

    return;
}

if ($baseUrl === '') {
    $logger->critical('The VIGIL_BASE_URL environment variable has not been configured.');
    $exitCode = 1;

    return;
}

$environment = trim((string) $environment);

$tokenHash = Frame::tokenHash($token);

/*
 * Spool...
 */

$directory = new SpoolDirectory((string) $spoolPath);

try {
    $lock = $directory->lock();
} catch (Throwable $e) {
    $logger->critical($e->getMessage());
    $exitCode = 1;

    return;
}

if ($lock === null) {
    $logger->critical("Another Vigil relay is already using the spool [{$directory->path}].");
    $exitCode = 1;

    return;
}

$spool = new SpoolWriter(
    directory: $directory,
    logger: $logger,
    segmentBytes: max(1, (int) $spoolSegmentBytes),
    segmentSeconds: max(0.05, (float) $spoolSegmentSeconds),
    maxBytes: max(1, (int) $spoolMaxBytes),
    fsync: filter_var($spoolFsync, FILTER_VALIDATE_BOOL),
);

$recovered = $spool->start();

if ($recovered > 0) {
    $logger->info("Recovered [{$recovered}] spool segment(s) left open by a previous run");
}

/*
 * Loop, transport and uploader...
 */

$loop ??= new StreamSelectLoop;
GlobalLoop::set($loop);

$headers = [
    'Accept' => 'application/json',
    'Authorization' => "Bearer {$token}",
    'Content-Type' => 'application/json',
    'Content-Encoding' => 'gzip',
    'Vigil-Server' => (string) $server,
    'User-Agent' => 'VigilAgent/'.$packageVersion,
];

if ($environment !== '') {
    $headers['Vigil-Environment'] = $environment;
}

$ingestUrl = $baseUrl.'/api/ingest';

$transport ??= new ReactTransport($ingestUrl, $headers, (float) $ingestConnectionTimeout, (float) $ingestTimeout, $logger, $loop);
$checkTransport = $transport instanceof ReactTransport
    ? new ReactTransport($ingestUrl, $headers, (float) $authenticationConnectionTimeout, (float) $authenticationTimeout, $logger, $loop)
    : $transport;

$uploader = new Uploader(
    directory: $directory,
    reader: new BatchReader(max(1024, (int) $uploadBytes)),
    transport: $transport,
    logger: $logger,
    concurrency: max(1, (int) $concurrency),
    defer: static function (Closure $callback) use ($loop): void {
        $loop->futureTick($callback);
    },
);

/*
 * Shutdown...
 */

$shuttingDown = false;
$tcpServer = null;

$shutdown = static function (string $reason) use (&$shuttingDown, &$tcpServer, $loop, $logger, $spool, $uploader, $directory, $drainSeconds): void {
    if ($shuttingDown) {
        return;
    }

    $shuttingDown = true;
    $logger->info("Shutting down ({$reason}): no longer accepting payloads, shipping the spool for up to {$drainSeconds}s");

    $tcpServer?->close();
    $spool->rotate();

    $deadline = $loop->addTimer(max(0.0, (float) $drainSeconds), static function () use ($loop, $logger, $directory): void {
        $logger->info('Drain time is up: ['.count($directory->segments()).'] segment(s) stay on disk for the next start');
        $loop->stop();
    });

    $uploader->drain(static function () use ($loop, $logger, $directory, $deadline): void {
        $loop->cancelTimer($deadline);
        $remaining = count($directory->segments());
        $logger->info($remaining === 0 ? 'Spool drained' : "Upload failed while draining: [{$remaining}] segment(s) stay on disk for the next start");
        $loop->stop();
    });
};

if (function_exists('pcntl_signal') && defined('SIGTERM')) {
    $onSignal = static function (int $signal) use (&$shuttingDown, $shutdown, $loop, $logger): void {
        if ($shuttingDown) {
            $logger->info('Second signal received: stopping now');
            $loop->stop();

            return;
        }

        $shutdown($signal === SIGINT ? 'SIGINT' : 'SIGTERM');
    };

    $loop->addSignal(SIGTERM, $onSignal);
    $loop->addSignal(SIGINT, $onSignal);
}

/*
 * Server...
 */

$tcpServer = new Server(
    listenOn: (string) $listenOn,
    handler: new FrameHandler($spool, $tokenHash, $logger),
    logger: $logger,
    loop: $loop,
    onShutdownRequested: $shutdown,
    socketResolver: $serverResolver,
);

try {
    $tcpServer->start();
} catch (Throwable $e) {
    $logger->critical("Unable to listen on [{$listenOn}]: {$e->getMessage()}");
    $tcpServer = null;
    $exitCode = 1;
    fclose($lock);

    return;
}

$logger->info("Vigil relay initiated: Listening on [{$listenOn}]; Shipping to [{$ingestUrl}]; Spool [{$directory->path}]; Environment [".($environment === '' ? 'project default' : $environment)."]; Version [{$packageVersion}]");

/*
 * Connectivity and token check...
 */

$checkStart = microtime(true);
$checkTransport->post(
    (string) gzencode('{"records":[]}'),
    static function (int $status, string $body) use ($logger, $checkStart, $tokenHash): void {
        $duration = round(microtime(true) - $checkStart, 3);
        $outcome = Outcome::fromResponse($status, $body);
        $token = '['.substr($tokenHash, 0, 3).'...]';

        match ($outcome->kind) {
            Outcome::DELIVERED => $logger->info("Authentication successful [{$duration}s]: token {$token} accepted"),
            Outcome::UNAUTHORIZED => $logger->critical("Authentication failed [{$duration}s]: {$outcome->describe()}: the product rejected token {$token}. Payloads are spooled and kept until the token is fixed."),
            Outcome::ENVIRONMENT => $logger->critical("Environment rejected [{$duration}s]: {$outcome->describe()}. Payloads are spooled and kept; retrying every ".((int) Uploader::ENVIRONMENT_RETRY_SECONDS).'s until the environment exists or VIGIL_ENVIRONMENT is fixed.'),
            default => $logger->error("Connectivity check failed [{$duration}s]: {$outcome->describe()}. Payloads are spooled and retried."),
        };
    },
    static function (string $error) use ($logger, $checkStart): void {
        $logger->error('Connectivity check failed ['.round(microtime(true) - $checkStart, 3)."s]: {$error}. Payloads are spooled and retried.");
    },
);

/*
 * Timers...
 */

$loop->addPeriodicTimer(0.25, static function () use ($spool): void {
    $spool->tick();
});

$loop->addPeriodicTimer(max(0.05, (float) $uploadInterval), static function () use (&$shuttingDown, $uploader): void {
    if (! $shuttingDown) {
        $uploader->tick();
    }
});

$watcher = new SignatureWatcher($signaturePath, $expectedSignature, static function () use ($loop, $logger, $shutdown, $signatureShutdownDelay): void {
    $logger->info('Agent signature changed: shutting down in '.round((float) $signatureShutdownDelay).'s');
    $loop->addTimer(max(0.0, (float) $signatureShutdownDelay), static function () use ($shutdown): void {
        $shutdown('signature changed');
    });
});

$signatureTimer = $loop->addPeriodicTimer(max(0.05, (float) $signatureCheckInterval), static function () use (&$signatureTimer, $watcher, $loop, $logger): void {
    $logger->debug('Signature checked');

    if ($watcher->check()) {
        $loop->cancelTimer($signatureTimer);
    }
});

/*
 * Get things rolling...
 */

$loop->run();

$spool->rotate();
flock($lock, LOCK_UN);
fclose($lock);

$logger->info('Shut down');
