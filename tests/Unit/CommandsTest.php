<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Materom\Vigil\Console\AgentCommand;
use Materom\Vigil\Console\StatusCommand;
use Materom\Vigil\Tests\Support\FakeCollector;

function bindStatusCommand(FakeCollector $collector, ?string $token = 'vigil-test-token', bool $enabled = true): void
{
    app()->instance(StatusCommand::class, new StatusCommand(
        token: $token,
        ingestUri: $collector->address(),
        connectionTimeout: 0.5,
        timeout: 0.5,
        enabled: $enabled,
        streamFactory: $collector->streamFactory(),
    ));
}

it('vigil:status pings the collector with a framed PING', function () {
    $collector = new FakeCollector('2:OK');
    bindStatusCommand($collector);

    $this->artisan('vigil:status')
        ->expectsOutputToContain('is running and accepting connections')
        ->assertExitCode(0);

    $frame = $collector->frame();

    expect($frame['raw'])->toBe('15:v1:6fb2a45:PING');

    $collector->close();
});

it('vigil:status fails on an unexpected reply', function () {
    $collector = new FakeCollector('2:NO');
    bindStatusCommand($collector);

    $this->artisan('vigil:status')
        ->expectsOutputToContain('Unexpected response from collector [2:NO]')
        ->assertExitCode(1);

    $collector->close();
});

it('vigil:status fails when the collector is unreachable', function () {
    $this->app->forgetInstance(StatusCommand::class);
    config()->set('vigil.ingest.uri', '127.0.0.1:9');

    $this->artisan('vigil:status')
        ->expectsOutputToContain('is not reachable')
        ->assertExitCode(1);
});

it('vigil:status requires a token', function () {
    $collector = new FakeCollector;
    bindStatusCommand($collector, token: '');

    $this->artisan('vigil:status')
        ->expectsOutputToContain('VIGIL_TOKEN')
        ->assertExitCode(1);

    expect($collector->connectionCount())->toBe(0);

    $collector->close();
});

it('vigil:deploy posts the deployment to the product API', function () {
    $this->reconfigure(['vigil.base_url' => 'http://vigil.test/']);

    Http::fake(['vigil.test/api/deployments' => Http::response(['id' => 1], 201)]);

    $this->artisan('vigil:deploy', [
        'deploy' => 'release-42',
        '--ref' => 'abc123',
        '--name' => 'Release 42',
        '--url' => 'https://git.example.com/r/42',
        '--timestamp' => '2026-10-17 12:00:00',
    ])->expectsOutputToContain('Deployment sent to Vigil successfully.')->assertExitCode(0);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'http://vigil.test/api/deployments'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer vigil-test-token')
            && $request->data() === [
                'timestamp' => '2026-10-17 12:00:00.000000',
                'deploy' => 'release-42',
                'environment' => 'testing',
                'ref' => 'abc123',
                'name' => 'Release 42',
                'url' => 'https://git.example.com/r/42',
            ];
    });
});

it('vigil:deploy falls back to VIGIL_DEPLOY and reports API errors', function () {
    $this->reconfigure(['vigil.base_url' => 'http://vigil.test']);

    Http::fake(['vigil.test/*' => Http::response(['message' => 'Invalid token.'], 401)]);

    $this->artisan('vigil:deploy')
        ->expectsOutputToContain('Deployment could not be sent to Vigil: Invalid token.')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request->data()['deploy'] === 'test-deploy');
});

it('vigil:deploy requires a base url', function () {
    Http::fake();

    $this->artisan('vigil:deploy', ['deploy' => 'x'])
        ->expectsOutputToContain('VIGIL_BASE_URL')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('vigil:agent requires the relay PHAR with the configuration injected into its scope', function () {
    app()->instance(AgentCommand::class, new AgentCommand(
        token: 'vigil-test-token',
        baseUrl: 'https://vigil.test',
        server: 'web-1',
        environment: 'production',
        ingestUri: '127.0.0.1:2407',
        agent: [
            'spool_path' => '/var/spool/vigil',
            'spool_segment_bytes' => 1024,
            'spool_segment_seconds' => 2,
            'spool_max_bytes' => 4096,
            'spool_fsync' => true,
            'upload_bytes' => 2048,
            'concurrency' => 3,
            'auth_connection_timeout' => 1,
            'auth_timeout' => 2,
            'ingest_connection_timeout' => 3,
            'ingest_timeout' => 4,
            'drain_seconds' => 9,
        ],
        agentPath: dirname(__DIR__).'/Support/Fixtures/agent-stub.php',
    ));

    $this->artisan('vigil:agent', ['--listen-on' => '0.0.0.0:2408', '--ingest-timeout' => '20', '--server' => 'web-2'])
        ->assertExitCode(7);

    $variables = $GLOBALS['vigilAgentStubVariables'];

    expect($variables)->toMatchArray([
        'token' => 'vigil-test-token',
        'baseUrl' => 'https://vigil.test',
        'listenOn' => '0.0.0.0:2408',
        'spoolPath' => '/var/spool/vigil',
        'spoolSegmentBytes' => 1024,
        'spoolSegmentSeconds' => 2,
        'spoolMaxBytes' => 4096,
        'spoolFsync' => true,
        'uploadBytes' => 2048,
        'concurrency' => 3,
        'authenticationConnectionTimeout' => 1,
        'authenticationTimeout' => 2,
        'ingestConnectionTimeout' => 3,
        'ingestTimeout' => '20',
        'drainSeconds' => 9,
        'server' => 'web-2',
        'environment' => 'production',
        'silent' => null,
        'quiet' => null,
        'verbose' => null,
    ])->and($variables['packageVersion'])->toBeString();

    unset($GLOBALS['vigilAgentStubVariables']);
});

it('vigil:agent falls back to the configuration and passes --spool and --silent', function () {
    $this->reconfigure([
        'vigil.base_url' => 'https://vigil.test',
        'vigil.agent.spool_path' => '/tmp/from-config',
        'vigil.ingest.uri' => '127.0.0.1:2499',
    ]);

    $command = $this->app->make(AgentCommand::class);
    (fn () => $this->agentPath = dirname(__DIR__).'/Support/Fixtures/agent-stub.php')->call($command);

    $this->artisan('vigil:agent', ['--silent' => true])->assertExitCode(7);

    expect($GLOBALS['vigilAgentStubVariables'])->toMatchArray([
        'listenOn' => '127.0.0.1:2499',
        'spoolPath' => '/tmp/from-config',
        'silent' => true,
        'server' => 'test-server',
    ]);

    $this->artisan('vigil:agent', ['--spool' => '/tmp/from-option'])->assertExitCode(7);

    expect($GLOBALS['vigilAgentStubVariables']['spoolPath'])->toBe('/tmp/from-option');

    unset($GLOBALS['vigilAgentStubVariables']);
});

it('vigil:status --remote posts an empty gzip batch to the product', function () {
    $this->reconfigure(['vigil.base_url' => 'https://vigil.test/']);

    Http::fake(['vigil.test/api/ingest' => Http::response(['accepted' => 0], 202)]);

    $this->artisan('vigil:status', ['--remote' => true])
        ->expectsOutputToContain('accepted the token [HTTP 202]')
        ->assertExitCode(0);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://vigil.test/api/ingest'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer vigil-test-token')
            && $request->hasHeader('Content-Encoding', 'gzip')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->hasHeader('Vigil-Server', 'test-server')
            && str_starts_with($request->header('User-Agent')[0], 'VigilAgent/')
            && gzdecode($request->body()) === '{"records":[]}';
    });
});

it('vigil:status --remote reports a rejected token, other errors and an unreachable product', function (int|string $answer, string $expected) {
    $this->reconfigure(['vigil.base_url' => 'https://vigil.test']);

    Http::fake(['vigil.test/*' => is_int($answer)
        ? Http::response(['message' => 'Invalid token.'], $answer)
        : fn () => throw new ConnectionException($answer)]);

    $this->artisan('vigil:status', ['--remote' => true])
        ->expectsOutputToContain($expected)
        ->assertExitCode(1);
})->with([
    '401' => [401, 'rejected the token [HTTP 401]: Invalid token.'],
    '503' => [503, 'answered HTTP 503: Invalid token.'],
    'down' => ['Connection refused', 'is not reachable: Connection refused'],
]);

it('vigil:status --remote requires a base url', function () {
    Http::fake();

    $this->artisan('vigil:status', ['--remote' => true])
        ->expectsOutputToContain('VIGIL_BASE_URL')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('vigil:status --remote sends the configured environment and lets --environment override it', function () {
    $this->reconfigure(['vigil.base_url' => 'https://vigil.test']);
    Http::fake(['vigil.test/api/ingest' => Http::response(['accepted' => 0], 202)]);

    $this->artisan('vigil:status', ['--remote' => true])->assertExitCode(0);
    $this->artisan('vigil:status', ['--remote' => true, '--environment' => 'staging'])->assertExitCode(0);

    $environments = [];
    Http::assertSent(function (Request $request) use (&$environments) {
        $environments[] = $request->header('Vigil-Environment')[0] ?? null;

        return true;
    });

    expect($environments)->toBe(['testing', 'staging']);
});

it('vigil:status --remote omits the environment header when it is empty', function () {
    $this->reconfigure(['vigil.base_url' => 'https://vigil.test']);
    Http::fake(['vigil.test/api/ingest' => Http::response(['accepted' => 0], 202)]);

    $this->artisan('vigil:status', ['--remote' => true, '--environment' => ''])->assertExitCode(0);

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Vigil-Environment'));
});

it('vigil:deploy sends the environment as header and field, with --environment overriding', function () {
    $this->reconfigure(['vigil.base_url' => 'http://vigil.test']);
    Http::fake(['vigil.test/api/deployments' => Http::response(['id' => 1], 201)]);

    $this->artisan('vigil:deploy', ['deploy' => 'r1'])->assertExitCode(0);
    $this->artisan('vigil:deploy', ['deploy' => 'r2', '--environment' => 'production'])->assertExitCode(0);

    $seen = [];
    Http::assertSent(function (Request $request) use (&$seen) {
        $seen[] = [$request->header('Vigil-Environment')[0] ?? null, $request->data()['environment']];

        return true;
    });

    expect($seen)->toBe([['testing', 'testing'], ['production', 'production']]);
});

it('vigil:deploy omits the environment header when it is empty', function () {
    $this->reconfigure(['vigil.base_url' => 'http://vigil.test']);
    Http::fake(['vigil.test/api/deployments' => Http::response(['id' => 1], 201)]);

    $this->artisan('vigil:deploy', ['deploy' => 'r1', '--environment' => ''])->assertExitCode(0);

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Vigil-Environment') && $request->data()['environment'] === null);
});

it('vigil:agent passes the environment into the relay scope', function () {
    $this->reconfigure(['vigil.base_url' => 'https://vigil.test']);

    $command = $this->app->make(AgentCommand::class);
    (fn () => $this->agentPath = dirname(__DIR__).'/Support/Fixtures/agent-stub.php')->call($command);

    $this->artisan('vigil:agent')->assertExitCode(7);
    expect($GLOBALS['vigilAgentStubVariables']['environment'])->toBe('testing');

    $this->artisan('vigil:agent', ['--environment' => 'staging'])->assertExitCode(7);
    expect($GLOBALS['vigilAgentStubVariables']['environment'])->toBe('staging');

    unset($GLOBALS['vigilAgentStubVariables']);
});
