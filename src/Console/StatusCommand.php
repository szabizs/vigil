<?php

namespace Materom\Vigil\Console;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Materom\Vigil\Payload;
use Materom\Vigil\SocketStreamFactory;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

use function gzencode;
use function hash;
use function Materom\Vigil\Support\fclose_safely;
use function Materom\Vigil\Support\fread_all;
use function Materom\Vigil\Support\fwrite_all;
use function Materom\Vigil\Support\stream_configure_read_timeout;
use function rtrim;
use function substr;

/**
 * @internal
 */
#[AsCommand(name: 'vigil:status', description: 'Check that the Vigil relay (or collector) is reachable, or with --remote that the Vigil product accepts the token.')]
final class StatusCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'vigil:status
        {--environment= : The environment to check <comment>[default: `VIGIL_ENVIRONMENT`]</comment>}
        {--remote : Check the Vigil product over HTTPS (VIGIL_BASE_URL) instead of the local relay}';

    /**
     * @var string
     */
    protected $description = 'Check that the Vigil relay (or collector) is reachable, or with --remote that the Vigil product accepts the token.';

    /**
     * @param  (Closure(string $address, float $connectionTimeout): resource)|null  $streamFactory
     */
    public function __construct(
        #[SensitiveParameter] private ?string $token,
        private string $ingestUri,
        private float $connectionTimeout,
        private float $timeout,
        private bool $enabled = true,
        private ?Closure $streamFactory = null,
        private ?string $baseUrl = null,
        private ?string $server = null,
        private float $remoteConnectionTimeout = 5.0,
        private float $remoteTimeout = 10.0,
        private ?string $environment = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->enabled) {
            $this->components->error('Vigil is disabled [VIGIL_ENABLED=false].');

            return 1;
        }

        if ($this->token === null || $this->token === '') {
            $this->components->error('The VIGIL_TOKEN environment variable has not been configured.');

            return 1;
        }

        if ($this->option('remote')) {
            return $this->checkRemote();
        }

        try {
            $this->ping();
        } catch (Throwable $e) {
            $this->components->error("The Vigil relay at [{$this->ingestUri}] is not reachable: {$e->getMessage()}");

            return 1;
        }

        $this->components->info("The Vigil relay at [{$this->ingestUri}] is running and accepting connections.");

        return 0;
    }

    private function checkRemote(): int
    {
        if ($this->baseUrl === null || $this->baseUrl === '') {
            $this->components->error('The VIGIL_BASE_URL environment variable has not been configured.');

            return 1;
        }

        $url = rtrim($this->baseUrl, '/').'/api/ingest';

        $environment = $this->option('environment') ?? $this->environment;

        try {
            $response = Http::connectTimeout($this->remoteConnectionTimeout)
                ->timeout($this->remoteTimeout)
                ->acceptJson()
                ->withToken((string) $this->token)
                ->withHeaders([
                    'Content-Encoding' => 'gzip',
                    'Vigil-Server' => (string) $this->server,
                    'User-Agent' => 'VigilAgent/'.$this->packageVersion(),
                    ...($environment !== null && $environment !== '' ? ['Vigil-Environment' => $environment] : []),
                ])
                ->withBody((string) gzencode('{"records":[]}'), 'application/json')
                ->post($url);
        } catch (Throwable $e) {
            $this->components->error("The Vigil product at [{$url}] is not reachable: {$e->getMessage()}");

            return 1;
        }

        if ($response->successful()) {
            $this->components->info("The Vigil product at [{$url}] accepted the token [HTTP {$response->status()}].");

            return 0;
        }

        $message = Str::limit((string) ($response->json('message') ?? $response->body()), 500, '[...]');

        if ($response->status() === 401) {
            $this->components->error("The Vigil product at [{$url}] rejected the token [HTTP 401]: {$message}");

            return 1;
        }

        $this->components->error("The Vigil product at [{$url}] answered HTTP {$response->status()}: {$message}");

        return 1;
    }

    private function packageVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('materom/vigil') ?? 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }

    private function ping(): void
    {
        $streamFactory = $this->streamFactory ?? (new SocketStreamFactory)(...);

        $stream = $streamFactory("tcp://{$this->ingestUri}", $this->connectionTimeout);

        $payload = Payload::text('PING', substr(hash('xxh128', (string) $this->token), 0, 7));

        try {
            stream_configure_read_timeout($stream, $this->timeout);

            fwrite_all($stream, $payload->pull(...));

            $response = fread_all($stream, 4);

            if ($response !== '2:OK') {
                throw new RuntimeException("Unexpected response from collector [{$response}]");
            }
        } finally {
            fclose_safely($stream);
        }
    }
}
