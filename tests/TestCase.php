<?php

namespace Materom\Vigil\Tests;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Application;
use Materom\Vigil\Compatibility;
use Materom\Vigil\Contracts\Ingest as IngestContract;
use Materom\Vigil\Core;
use Materom\Vigil\Facades\Vigil;
use Materom\Vigil\Tests\Support\FakeIngest;
use Materom\Vigil\VigilServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

abstract class TestCase extends Orchestra
{
    public const TOKEN = 'vigil-test-token';

    protected FakeIngest $ingest;

    protected function setUp(): void
    {
        VigilServiceProvider::flushState();
        Compatibility::$context = [];

        parent::setUp();

        $this->rerouteConsoleEvents();
    }

    /**
     * Outside of unit tests the console kernel re-routes Symfony's console events to CommandStarting /
     * CommandFinished, which is what Vigil's command hooks listen to. Enable that here too.
     */
    protected function rerouteConsoleEvents(): void
    {
        $kernel = $this->app->make(ConsoleKernel::class);

        $kernel->rerouteSymfonyCommandEvents();
        $kernel->setArtisan(null);
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [VigilServiceProvider::class];
    }

    /**
     * @param  Application  $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['Vigil' => Vigil::class];
    }

    /**
     * Per-test configuration applied before the service provider registers (see reconfigure()).
     *
     * @var array<string, mixed>
     */
    protected array $configOverrides = [];

    /**
     * The Vigil service provider reads its configuration while registering, which happens before
     * defineEnvironment(), so the fake ingest and configuration are applied while configuration loads.
     *
     * @param  Application  $app
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        $app->instance(IngestContract::class, $this->ingest = new FakeIngest);

        $app['config']->set([
            'vigil.token' => self::TOKEN,
            'vigil.deployment' => 'test-deploy',
            'vigil.server' => 'test-server',
            'vigil.environment' => 'testing',
            'vigil.ingest.uri' => '127.0.0.1:9',
            'database.default' => 'testing',
            'queue.default' => 'database',
            'mail.default' => 'array',
            'cache.default' => 'array',
            ...$this->configOverrides,
        ]);
    }

    /**
     * Rebuild the application with the given configuration applied before Vigil registers.
     *
     * @param  array<string, mixed>  $config
     */
    protected function reconfigure(array $config): static
    {
        $this->configOverrides = $config;

        VigilServiceProvider::flushState();
        Compatibility::$context = [];

        $this->refreshApplication();

        $this->rerouteConsoleEvents();

        return $this;
    }

    protected function core(): Core
    {
        return $this->app->make(Core::class);
    }

    /**
     * Run a command through the console kernel exactly like the artisan binary does (handle + terminate),
     * so the command lifecycle hooks fire.
     *
     * @param  list<string>  $arguments
     */
    protected function runArtisan(array $arguments): int
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $input = new ArgvInput(['artisan', ...$arguments]);

        $status = $kernel->handle($input, new BufferedOutput);

        $kernel->terminate($input, $status);

        return $status;
    }

    /**
     * @return list<string>
     */
    protected function expectedKeys(string $type): array
    {
        /** @var array<string, list<string>> $keys */
        $keys = require __DIR__.'/Fixtures/record-keys.php';

        return $keys[$type];
    }
}
