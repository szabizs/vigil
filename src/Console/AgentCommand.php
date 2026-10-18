<?php

namespace Materom\Vigil\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function defined;

/**
 * Runs the Vigil relay agent (agent/build/agent.phar) inside this application.
 *
 * Exactly like the original `nightwatch:agent`, the command only prepares variables and requires the PHAR; the
 * PHAR reads them from the including scope. Everything the relay needs is isolated inside the (scoped) PHAR.
 *
 * @internal
 */
#[AsCommand(name: 'vigil:agent', description: 'Run the Vigil relay agent: spool payloads locally and ship them to the Vigil product.')]
final class AgentCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'vigil:agent
        {--listen-on= : The address to listen on <comment>[default: `VIGIL_INGEST_URI`]</comment>}
        {--spool= : The spool directory <comment>[default: `VIGIL_AGENT_SPOOL_PATH`]</comment>}
        {--auth-connection-timeout= : Connect timeout in seconds of the startup token check}
        {--auth-timeout= : Timeout in seconds of the startup token check}
        {--ingest-connection-timeout= : Connect timeout in seconds of an upload}
        {--ingest-timeout= : Timeout in seconds of an upload}
        {--environment= : The environment sent to the product <comment>[default: `VIGIL_ENVIRONMENT`]</comment>}
        {--server= : The server name sent to the product <comment>[default: `VIGIL_SERVER`]</comment>}';

    /**
     * @var string
     */
    protected $description = 'Run the Vigil relay agent: spool payloads locally and ship them to the Vigil product.';

    /**
     * @param  array{
     *     spool_path?: string|null,
     *     spool_segment_bytes?: int|string|null,
     *     spool_segment_seconds?: float|int|string|null,
     *     spool_max_bytes?: int|string|null,
     *     spool_fsync?: bool|string|null,
     *     upload_bytes?: int|string|null,
     *     concurrency?: int|string|null,
     *     auth_connection_timeout?: float|int|string|null,
     *     auth_timeout?: float|int|string|null,
     *     ingest_connection_timeout?: float|int|string|null,
     *     ingest_timeout?: float|int|string|null,
     *     drain_seconds?: float|int|string|null,
     * }  $agent
     */
    public function __construct(
        #[SensitiveParameter] private ?string $token,
        private ?string $baseUrl,
        private ?string $server,
        private ?string $environment,
        private ?string $ingestUri,
        private array $agent = [],
        private ?string $agentPath = null,
    ) {
        // Symfony Console 7.2+ ships a global --silent option; older versions need the command to define it.
        if (! defined(OutputInterface::class.'::VERBOSITY_SILENT')) {
            $this->signature .= "\n        {--silent : Do not output any message}";
        }

        parent::__construct();
    }

    public function handle(): int
    {
        $token = $this->token;

        $baseUrl = $this->baseUrl;

        $listenOn = $this->option('listen-on') ?? $this->ingestUri;

        $spoolPath = $this->option('spool') ?? $this->agent['spool_path'] ?? null;

        $spoolSegmentBytes = $this->agent['spool_segment_bytes'] ?? null;

        $spoolSegmentSeconds = $this->agent['spool_segment_seconds'] ?? null;

        $spoolMaxBytes = $this->agent['spool_max_bytes'] ?? null;

        $spoolFsync = $this->agent['spool_fsync'] ?? null;

        $uploadBytes = $this->agent['upload_bytes'] ?? null;

        $concurrency = $this->agent['concurrency'] ?? null;

        $authenticationConnectionTimeout = $this->option('auth-connection-timeout') ?? $this->agent['auth_connection_timeout'] ?? null;

        $authenticationTimeout = $this->option('auth-timeout') ?? $this->agent['auth_timeout'] ?? null;

        $ingestConnectionTimeout = $this->option('ingest-connection-timeout') ?? $this->agent['ingest_connection_timeout'] ?? null;

        $ingestTimeout = $this->option('ingest-timeout') ?? $this->agent['ingest_timeout'] ?? null;

        $drainSeconds = $this->agent['drain_seconds'] ?? null;

        $server = $this->option('server') ?? $this->server;

        $environment = $this->option('environment') ?? $this->environment;

        $packageVersion = $this->packageVersion();

        $silent = $this->option('silent') ?: null;

        $quiet = $this->option('quiet') ?: null;

        $verbose = $this->option('verbose') ?: null;

        $exitCode = 0;

        require $this->agentPath ?? __DIR__.'/../../agent/build/agent.phar';

        return (int) $exitCode;
    }

    private function packageVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('materom/vigil') ?? 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
