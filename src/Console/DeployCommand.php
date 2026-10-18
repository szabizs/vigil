<?php

namespace Materom\Vigil\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

use function config;
use function is_string;
use function rtrim;

/**
 * @internal
 */
#[AsCommand(name: 'vigil:deploy', description: 'Send deployment metadata to the Vigil monitoring product.')]
final class DeployCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'vigil:deploy
        {deploy? : A unique value for the deploy <comment>[default: `VIGIL_DEPLOY`]</comment>}
        {--ref= : The git ref (tag or hash) of the deploy}
        {--environment= : The environment of the deploy <comment>[default: `VIGIL_ENVIRONMENT`]</comment>}
        {--name= : The human-readable name of the deploy}
        {--url= : A URL with information related to the deploy}
        {--timestamp= : The timestamp of the deploy <comment>[default: `now()`]</comment>}';

    /**
     * @var string
     */
    protected $description = 'Send deployment metadata to the Vigil monitoring product.';

    public function __construct(
        #[SensitiveParameter] private ?string $token,
        private ?string $baseUrl = null,
        private ?string $environment = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $timestamp = is_string($this->option('timestamp')) ? CarbonImmutable::parse($this->option('timestamp')) : CarbonImmutable::now();

        $deploy = $this->argument('deploy') ?? config('vigil.deployment');

        if (! $deploy) {
            $this->components->error('Please configure the [VIGIL_DEPLOY] environment variable.');

            return 0;
        }

        if (! $this->token) {
            $this->components->error('Please configure the [VIGIL_TOKEN] environment variable.');

            return 0;
        }

        if (! $this->baseUrl) {
            $this->components->error('Please configure the [VIGIL_BASE_URL] environment variable.');

            return 0;
        }

        $baseUrl = rtrim($this->baseUrl, '/');

        $environment = $this->option('environment') ?? $this->environment;
        $environment = is_string($environment) && $environment !== '' ? $environment : null;

        try {
            Http::connectTimeout(5)
                ->timeout(10)
                ->acceptJson()
                ->withToken($this->token)
                ->withHeaders($environment !== null ? ['Vigil-Environment' => $environment] : [])
                ->post("{$baseUrl}/api/deployments", [
                    'timestamp' => $timestamp->utc()->toDateTimeString('microsecond'),
                    'deploy' => $deploy,
                    'environment' => $environment,
                    'ref' => $this->option('ref'),
                    'name' => $this->option('name'),
                    'url' => $this->option('url'),
                ])
                ->throw();

            $this->components->info('Deployment sent to Vigil successfully.');
        } catch (RequestException $e) {
            $message = Str::limit($e->response->json('message') ?? "[{$e->getCode()}] {$e->response->body()}", 1000, '[...]'); // @phpstan-ignore argument.type

            $this->components->error("Deployment could not be sent to Vigil: {$message}");
        } catch (Throwable $e) {
            $this->components->error("Deployment could not be sent to Vigil: {$e->getMessage()}");
        }

        return 0;
    }
}
