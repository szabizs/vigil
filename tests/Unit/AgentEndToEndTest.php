<?php

use Illuminate\Contracts\Events\Dispatcher;
use Materom\Vigil\Ingest;
use Materom\Vigil\RecordsBuffer;
use Materom\Vigil\SocketStreamFactory;
use Materom\Vigil\Tests\Support\AgentFakes;
use Materom\Vigil\Tests\Support\AgentProcess;
use Materom\Vigil\Tests\Support\FakeProduct;

beforeEach(function () {
    if (! is_file(AgentProcess::pharPath())) {
        $this->markTestSkipped('agent/build/agent.phar has not been built (agent/build.sh).');
    }

    $this->work = AgentFakes::tempDirectory();
    $this->agentPort = AgentProcess::freePort();
    $this->product = new FakeProduct($this->work.'/product', AgentProcess::freePort());
    $this->processes = [];

    $this->startAgent = function (array $variables = []): AgentProcess {
        $agent = new AgentProcess($this->work, [
            'token' => 'vigil-test-token',
            'baseUrl' => $this->product->url(),
            'listenOn' => "127.0.0.1:{$this->agentPort}",
            'spoolPath' => $this->work.'/spool',
            'spoolSegmentSeconds' => 0.2,
            'uploadInterval' => 0.1,
            'drainSeconds' => 5,
            'server' => 'e2e-server',
            'environment' => 'e2e',
            'packageVersion' => '9.9.9-test',
            'verbose' => true,
            ...$variables,
        ]);
        $this->processes[] = $agent;

        expect($agent->waitForLog('Vigil relay initiated'))->toBeTrue($agent->log());

        return $agent;
    };

    $this->relayClient = fn (): Ingest => new Ingest(
        transmitTo: "127.0.0.1:{$this->agentPort}",
        connectionTimeout: 1.0,
        timeout: 2.0,
        streamFactory: new SocketStreamFactory,
        buffer: new RecordsBuffer(length: 500),
        tokenHash: substr(hash('xxh128', 'vigil-test-token'), 0, 7),
        events: $this->app->make(Dispatcher::class),
    );

    $this->segments = fn (): array => glob($this->work.'/spool/*.ndjson') ?: [];
});

afterEach(function () {
    foreach ($this->processes ?? [] as $process) {
        $process->stop();
    }

    if (isset($this->product)) {
        $this->product->stop();
    }

    if (isset($this->work)) {
        AgentFakes::removeDirectory($this->work);
    }
});

function sendRecords(Ingest $ingest, array $records): void
{
    foreach ($records as $record) {
        $ingest->write($record);
    }

    $ingest->digest();
}

it('acknowledges frames from the Vigil client and ships them as gzip batches', function () {
    $this->product->start();
    $agent = ($this->startAgent)();

    $first = [['v' => 1, 't' => 'request', 'url' => 'https://shop.test/a', 'duration' => 1.5], ['v' => 1, 't' => 'query', 'sql' => 'select 1']];
    $second = [['v' => 1, 't' => 'log', 'message' => 'héllo/world:2']];

    sendRecords(($this->relayClient)(), $first);
    sendRecords(($this->relayClient)(), $second);
    ($this->relayClient)()->ping();

    expect(AgentProcess::waitUntil(fn () => count($this->product->records()) === 3, 10.0))->toBeTrue($agent->log());

    $batches = array_values(array_filter($this->product->requests(), fn ($request) => $request['records'] !== []));
    $headers = $batches[0]['headers'];

    expect($this->product->records())->toBe([...$first, ...$second])
        ->and($batches[0]['gzip'])->toBeTrue()
        ->and($headers['authorization'])->toBe('Bearer vigil-test-token')
        ->and($headers['content-type'])->toBe('application/json')
        ->and($headers['content-encoding'])->toBe('gzip')
        ->and($headers['vigil-server'])->toBe('e2e-server')
        ->and($headers['vigil-environment'])->toBe('e2e')
        ->and($this->product->requests()[0]['headers']['vigil-environment'])->toBe('e2e')
        ->and($agent->log())->toContain('Environment [e2e]')
        ->and($headers['user-agent'])->toBe('VigilAgent/9.9.9-test')
        ->and($this->product->requests()[0]['records'])->toBe([])
        ->and($agent->log())->toContain('Authentication successful')
        ->and($agent->log())->toContain('token [6fb...] accepted')
        ->and($agent->log())->not->toContain('vigil-test-token')
        ->and(AgentProcess::waitUntil(fn () => ($this->segments)() === [], 2.0))->toBeTrue();
});

it('keeps acknowledged frames on disk while the product is down and delivers them once it is back', function () {
    $agent = ($this->startAgent)();

    $records = [['v' => 1, 't' => 'log', 'message' => 'while down 1'], ['v' => 1, 't' => 'log', 'message' => 'while down 2']];
    sendRecords(($this->relayClient)(), $records);

    expect($agent->waitForLog('Ingest failed', 10.0))->toBeTrue($agent->log())
        ->and(($this->segments)())->toHaveCount(1)
        ->and(file_get_contents(($this->segments)()[0]))->toBe(json_encode($records)."\n");

    $this->product->start();

    expect(AgentProcess::waitUntil(fn () => $this->product->records() === $records, 15.0))->toBeTrue($agent->log())
        ->and(AgentProcess::waitUntil(fn () => ($this->segments)() === [], 2.0))->toBeTrue();
});

it('drains the spool on SIGTERM and exits cleanly', function () {
    if (! function_exists('pcntl_signal')) {
        $this->markTestSkipped('pcntl is required for signal handling.');
    }

    $this->product->start();
    $agent = ($this->startAgent)(['spoolSegmentSeconds' => 600, 'uploadInterval' => 600]);

    $records = [['v' => 1, 't' => 'log', 'message' => 'drained on shutdown']];
    sendRecords(($this->relayClient)(), $records);

    expect(glob($this->work.'/spool/*.open'))->toHaveCount(1);

    $agent->signal(SIGTERM);

    expect($agent->waitForExit(10.0))->toBe(0)
        ->and($this->product->records())->toBe($records)
        ->and(($this->segments)())->toBe([])
        ->and($agent->log())->toContain('Shutting down (SIGTERM)')
        ->and($agent->log())->toContain('Spool drained');
});

it('stops accepting and keeps the spool when the product is down at SIGTERM', function () {
    if (! function_exists('pcntl_signal')) {
        $this->markTestSkipped('pcntl is required for signal handling.');
    }

    $agent = ($this->startAgent)(['spoolSegmentSeconds' => 600, 'uploadInterval' => 600]);
    sendRecords(($this->relayClient)(), [['v' => 1, 't' => 'log', 'message' => 'kept']]);

    $agent->signal(SIGTERM);

    expect($agent->waitForExit(10.0))->toBe(0)
        ->and(($this->segments)())->toHaveCount(1)
        ->and($agent->log())->toContain('stay on disk for the next start');

    $restarted = ($this->startAgent)();
    $this->product->start();

    expect(AgentProcess::waitUntil(fn () => count($this->product->records()) === 1, 15.0))->toBeTrue($restarted->log());
});

it('shuts down after a deploy replaced the PHAR signature', function () {
    $signature = $this->work.'/signature.txt';
    file_put_contents($signature, 'release-1');
    $this->product->start();

    $agent = ($this->startAgent)(['signaturePath' => $signature, 'signatureCheckInterval' => 0.1, 'signatureShutdownDelay' => 0.3]);
    sendRecords(($this->relayClient)(), [['v' => 1, 't' => 'log', 'message' => 'before deploy']]);

    file_put_contents($signature, 'release-2');

    expect($agent->waitForExit(10.0))->toBe(0)
        ->and($agent->log())->toContain('Agent signature changed: shutting down in 0s')
        ->and($agent->log())->toContain('Shutting down (signature changed)')
        ->and($this->product->records())->toBe([['v' => 1, 't' => 'log', 'message' => 'before deploy']]);
});

it('refuses frames with another token and shuts down on a new payload version', function () {
    $this->product->start();
    $agent = ($this->startAgent)();

    $send = function (string $frame): string {
        $socket = stream_socket_client("tcp://127.0.0.1:{$this->agentPort}", $code, $message, 1.0);
        stream_set_timeout($socket, 2);
        fwrite($socket, $frame);
        $reply = (string) fread($socket, 4);
        fclose($socket);

        return $reply;
    };

    $tokenHash = substr(hash('xxh128', 'vigil-test-token'), 0, 7);
    $otherHash = substr(hash('xxh128', 'other'), 0, 7);

    expect($send('15:v1:'.$tokenHash.':PING'))->toBe('2:OK')
        ->and($send('15:v1:'.$otherHash.':PING'))->toBe('')
        ->and($agent->log())->toContain('Incoming token hash mismatch')
        ->and($send('15:v2:'.$tokenHash.':PING'))->toBe('')
        ->and($agent->waitForExit(10.0))->toBe(0)
        ->and($agent->log())->toContain('Incoming payload version has changed');
});

it('omits the Vigil-Environment header when no environment is configured', function () {
    $this->product->start();
    $agent = ($this->startAgent)(['environment' => '']);

    expect($agent->waitForLog('Authentication successful'))->toBeTrue($agent->log())
        ->and($this->product->requests()[0]['headers'])->not->toHaveKey('vigil-environment')
        ->and($agent->log())->toContain('Environment [project default]');
});

it('keeps the data and retries when the product does not know the environment', function () {
    $this->product->start();
    $this->product->respondWith(422, 'Unknown environment');
    $agent = ($this->startAgent)();

    $records = [['v' => 1, 't' => 'log', 'message' => 'kept on 422']];
    sendRecords(($this->relayClient)(), $records);

    expect($agent->waitForLog('Environment rejected', 10.0))->toBeTrue($agent->log())
        ->and(AgentProcess::waitUntil(fn () => ($this->segments)() !== [], 2.0))->toBeTrue()
        ->and($agent->running())->toBeTrue();
});
