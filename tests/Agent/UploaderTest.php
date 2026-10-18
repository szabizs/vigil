<?php

use Materom\Vigil\Tests\Support\AgentFakes;
use Materom\Vigil\Tests\Support\FakeTransport;
use Materom\VigilAgent\BatchReader;
use Materom\VigilAgent\SpoolDirectory;
use Materom\VigilAgent\Uploader;

beforeEach(function () {
    $this->path = AgentFakes::tempDirectory();
    $this->directory = new SpoolDirectory($this->path);
    $this->directory->ensureExists();
    $this->transport = new FakeTransport;
    $this->logger = AgentFakes::logger();
    $this->now = 1000.0;
    $this->deferred = [];
    $this->uploader = fn (int $maxBytes = 8388608, int $concurrency = 2) => new Uploader(
        $this->directory,
        new BatchReader($maxBytes),
        $this->transport,
        $this->logger,
        concurrency: $concurrency,
        clock: fn () => $this->now,
        defer: function (Closure $callback) {
            $this->deferred[] = $callback;
        },
    );
    $this->segment = function (string $name, string ...$frames): string {
        $path = $this->path.'/'.$name.'.ndjson';
        file_put_contents($path, implode("\n", $frames)."\n");

        return $path;
    };
    $this->runDeferred = function () {
        while ($this->deferred !== []) {
            array_shift($this->deferred)();
        }
    };
});

afterEach(function () {
    AgentFakes::removeDirectory($this->path);
});

function loggedLine(array $lines, string $needle): bool
{
    foreach ($lines as $line) {
        if (str_contains($line, $needle)) {
            return true;
        }
    }

    return false;
}

it('ships the oldest segment and deletes it on 202', function () {
    $older = ($this->segment)('0000000000001-000001', '[{"n":1}]', '[{"n":2}]');
    ($this->segment)('0000000000002-000001', '[{"n":3}]');
    $this->transport->answer(null, null);

    ($this->uploader)(concurrency: 1)->tick();
    $this->transport->resolve(202, '{"accepted":2}');

    expect($this->transport->posts)->toHaveCount(1)
        ->and($this->transport->posts[0]['records'])->toBe([['n' => 1], ['n' => 2]])
        ->and(file_exists($older))->toBeFalse()
        ->and(loggedLine($this->logger->lines, '2 record(s) accepted'))->toBeTrue();

    ($this->runDeferred)();
    $this->transport->resolve(202, '{"accepted":1}');

    expect($this->transport->posts)->toHaveCount(2)
        ->and($this->transport->posts[1]['records'])->toBe([['n' => 3]])
        ->and($this->directory->segments())->toBe([]);
});

it('runs at most two uploads at a time, never two of the same segment', function () {
    foreach ([1, 2, 3] as $n) {
        ($this->segment)("000000000000{$n}-000001", '[{"n":'.$n.'}]');
    }

    $this->transport->answer(null, null, null);
    $uploader = ($this->uploader)();

    $uploader->tick();
    $uploader->tick();

    expect($this->transport->posts)->toHaveCount(2)
        ->and($uploader->inFlight())->toBe(2)
        ->and(array_column($this->transport->posts, 'records'))->toBe([[['n' => 1]], [['n' => 2]]]);

    $this->transport->resolve(202);
    ($this->runDeferred)();

    expect($this->transport->posts)->toHaveCount(3)
        ->and($this->transport->posts[2]['records'])->toBe([['n' => 3]]);
});

it('splits a large segment into several uploads and checkpoints each accepted one', function () {
    $frames = [];

    for ($n = 1; $n <= 6; $n++) {
        $frames[] = '[{"n":'.$n.',"pad":"'.str_repeat('p', 80).'"}]';
    }

    $segment = ($this->segment)('0000000000001-000001', ...$frames);
    $this->transport->answer([202, '{}'], [503, '{"retry_in":7}']);
    $uploader = ($this->uploader)(maxBytes: 200);

    $uploader->tick();

    expect($this->transport->posts)->toHaveCount(1)
        ->and(array_column($this->transport->posts[0]['records'], 'n'))->toBe([1, 2])
        ->and($this->directory->checkpoint($segment))->toBeGreaterThan(0);

    ($this->runDeferred)();

    expect($this->transport->posts)->toHaveCount(2)
        ->and(array_column($this->transport->posts[1]['records'], 'n'))->toBe([3, 4])
        ->and(file_exists($segment))->toBeTrue();

    $this->now += 7;
    $restarted = ($this->uploader)(maxBytes: 200);
    $restarted->tick();
    ($this->runDeferred)();

    expect(array_map(fn ($post) => array_column($post['records'], 'n'), array_slice($this->transport->posts, 2)))
        ->toBe([[3, 4], [5, 6]])
        ->and(file_exists($segment))->toBeFalse()
        ->and(file_exists($segment.'.offset'))->toBeFalse();
});

it('moves a batch rejected with :dataset to dead/ and carries on', function (int $status) {
    $segment = ($this->segment)('0000000000001-000001', '[{"n":1}]');
    ($this->segment)('0000000000002-000001', '[{"n":2}]');
    $this->transport->answer([$status, '{"message":"Rejected batch"}'], [202, '{}']);

    ($this->uploader)(concurrency: 1)->tick();
    ($this->runDeferred)();

    expect(file_exists($segment))->toBeFalse()
        ->and(file_get_contents($this->path.'/dead/0000000000001-000001-0.ndjson'))->toBe("[{\"n\":1}]\n")
        ->and(json_decode(file_get_contents($this->path.'/dead/0000000000001-000001-0.error.json'), true))
        ->toMatchArray(['status' => $status, 'error' => 'Rejected batch'])
        ->and($this->transport->posts)->toHaveCount(2)
        ->and($this->directory->segments())->toBe([])
        ->and(loggedLine($this->logger->lines, "Ingest rejected [0s]: {$status} [Rejected batch]"))->toBeTrue();
})->with([400, 413]);

it('keeps the data on 401 and retries every five minutes', function () {
    $segment = ($this->segment)('0000000000001-000001', '[{"n":1}]');
    $this->transport->answer([401, '{"message":"Invalid token."}'], [401, '{}'], [202, '{}']);
    $uploader = ($this->uploader)();

    $uploader->tick();

    expect(file_exists($segment))->toBeTrue()
        ->and($uploader->pausedFor())->toBe(300.0)
        ->and(loggedLine($this->logger->lines, '[CRITICAL] Ingest refused [0s]: 401 [Invalid token.]'))->toBeTrue()
        ->and($this->deferred)->toBe([]);

    $this->now += 299;
    $uploader->tick();
    expect($this->transport->posts)->toHaveCount(1);

    $this->now += 1;
    $uploader->tick();
    expect($this->transport->posts)->toHaveCount(2)
        ->and($uploader->pausedFor())->toBe(300.0);

    $this->now += 300;
    $uploader->tick();
    expect(file_exists($segment))->toBeFalse();
});

it('keeps the data on 422 :dataset and retries every minute', function (string $message) {
    $segment = ($this->segment)('0000000000001-000001', '[{"n":1}]');
    $this->transport->answer([422, '{"message":"'.$message.'"}'], [422, '{"message":"'.$message.'"}'], [202, '{}']);
    $uploader = ($this->uploader)();

    $uploader->tick();

    expect(file_exists($segment))->toBeTrue()
        ->and($uploader->pausedFor())->toBe(60.0)
        ->and(loggedLine($this->logger->lines, "[CRITICAL] Ingest refused [0s]: 422 [{$message}]"))->toBeTrue()
        ->and($this->deferred)->toBe([]);

    $this->now += 59;
    $uploader->tick();
    expect($this->transport->posts)->toHaveCount(1);

    $this->now += 1;
    $uploader->tick();
    expect($this->transport->posts)->toHaveCount(2)
        ->and($uploader->pausedFor())->toBe(60.0);

    $this->now += 60;
    $uploader->tick();
    expect(file_exists($segment))->toBeFalse();
})->with(['Unknown environment', 'Environment required']);

it('treats any other 422 as a retryable failure', function () {
    $segment = ($this->segment)('0000000000001-000001', '[{"n":1}]');
    $this->transport->answer([422, '{"message":"Nope"}']);

    ($this->uploader)()->tick();

    expect(file_exists($segment))->toBeTrue()
        ->and(loggedLine($this->logger->lines, 'Ingest failed'))->toBeTrue();
});

it('waits retry_in seconds on :dataset', function (int $status) {
    $segment = ($this->segment)('0000000000001-000001', '[{"n":1}]');
    $this->transport->answer([$status, '{"retry_in":12}'], [202, '{}']);
    $uploader = ($this->uploader)();

    $uploader->tick();

    expect($uploader->pausedFor())->toBe(12.0)
        ->and(file_exists($segment))->toBeTrue();

    $this->now += 11.9;
    $uploader->tick();
    expect($this->transport->posts)->toHaveCount(1);

    $this->now += 0.1;
    $uploader->tick();
    expect(file_exists($segment))->toBeFalse();
})->with([429, 503]);

it('backs off exponentially from 2.5s to 5 minutes on server and network errors', function () {
    $segment = ($this->segment)('0000000000001-000001', '[{"n":1}]');
    $answers = [[500, 'boom'], 'Connection refused', [502, ''], [404, 'Not Found'], 'Timed out', [500, ''], [500, ''], [500, ''], [500, ''], [202, '{}']];
    $this->transport->answer(...$answers);
    $uploader = ($this->uploader)();
    $waits = [];

    for ($attempt = 0; $attempt < 9; $attempt++) {
        $uploader->tick();
        $waits[] = $uploader->pausedFor();
        $this->now += $uploader->pausedFor();
    }

    expect($waits)->toBe([2.5, 5.0, 10.0, 20.0, 40.0, 80.0, 160.0, 300.0, 300.0])
        ->and(file_exists($segment))->toBeTrue()
        ->and(loggedLine($this->logger->lines, 'Ingest failed [0s]: Connection refused; batch kept, retrying in 5s.'))->toBeTrue();

    $uploader->tick();

    expect(file_exists($segment))->toBeFalse()
        ->and($uploader->failures())->toBe(0);
});

it('drains the spool in one final round, ignoring any pause', function () {
    ($this->segment)('0000000000001-000001', '[{"n":1}]');
    ($this->segment)('0000000000002-000001', '[{"n":2}]');
    ($this->segment)('0000000000003-000001', '[{"n":3}]');
    $this->transport->answer('Connection refused');
    $uploader = ($this->uploader)(concurrency: 1);
    $uploader->tick();

    expect($uploader->pausedFor())->toBe(2.5);

    $drained = false;
    $uploader->drain(function () use (&$drained) {
        $drained = true;
    });
    ($this->runDeferred)();

    expect($drained)->toBeTrue()
        ->and($this->directory->segments())->toBe([])
        ->and($this->transport->posts)->toHaveCount(4);
});

it('stops draining at the first failure and leaves the rest on disk', function () {
    ($this->segment)('0000000000001-000001', '[{"n":1}]');
    ($this->segment)('0000000000002-000001', '[{"n":2}]');
    $this->transport->answer([202, '{}'], [503, '{}']);
    $uploader = ($this->uploader)(concurrency: 1);

    $drained = false;
    $uploader->drain(function () use (&$drained) {
        $drained = true;
    });
    ($this->runDeferred)();

    expect($drained)->toBeTrue()
        ->and($this->directory->segments())->toHaveCount(1)
        ->and($this->transport->posts)->toHaveCount(2);
});

it('drains immediately when the spool is empty', function () {
    $drained = false;

    ($this->uploader)()->drain(function () use (&$drained) {
        $drained = true;
    });

    expect($drained)->toBeTrue()->and($this->transport->posts)->toBe([]);
});
