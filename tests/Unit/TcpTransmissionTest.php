<?php

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Materom\Vigil\Core;
use Materom\Vigil\Events\IngestingEvents;
use Materom\Vigil\Ingest;
use Materom\Vigil\RecordsBuffer;
use Materom\Vigil\Tests\Support\FakeCollector;

function makeIngest(FakeCollector $collector, Dispatcher $events): Ingest
{
    return new Ingest(
        transmitTo: $collector->address(),
        connectionTimeout: 0.5,
        timeout: 0.5,
        streamFactory: $collector->streamFactory(),
        buffer: new RecordsBuffer(length: 500),
        tokenHash: substr(hash('xxh128', 'vigil-test-token'), 0, 7),
        events: $events,
    );
}

it('transmits a framed batch over TCP and accepts the 2:OK acknowledgment', function () {
    $collector = new FakeCollector('2:OK');
    $ingest = makeIngest($collector, $this->app->make(Dispatcher::class));

    $ingest->write(['v' => 1, 't' => 'log', 'message' => 'first']);
    $ingest->write(['v' => 1, 't' => 'log', 'message' => 'second']);
    $ingest->digest();

    $frame = $collector->frame();

    expect($collector->connectionCount())->toBe(1)
        ->and($frame['version'])->toBe('v1')
        ->and($frame['tokenHash'])->toBe('6fb2a45')
        ->and($frame['received'])->toBe($frame['length'])
        ->and($frame['length'])->toBe(strlen('v1:6fb2a45:'.$frame['body']))
        ->and(json_decode($frame['body'], true))->toBe([
            ['v' => 1, 't' => 'log', 'message' => 'first'],
            ['v' => 1, 't' => 'log', 'message' => 'second'],
        ])
        ->and($ingest->buffer->count())->toBe(0);

    $collector->close();
});

it('raises when the collector replies with anything but 2:OK', function () {
    $collector = new FakeCollector('5:NOPE');
    $ingest = makeIngest($collector, $this->app->make(Dispatcher::class));

    try {
        $ingest->ping();
    } finally {
        $frame = $collector->frame();
        $collector->close();
    }

    expect($frame['body'])->toBe('PING');
})->throws(RuntimeException::class, 'Unexpected response from collector [5:NO');

it('raises when nothing listens on the ingest address', function () {
    $collector = new FakeCollector;
    $address = $collector->address();
    $collector->close();

    $ingest = new Ingest(
        transmitTo: $address,
        connectionTimeout: 0.5,
        timeout: 0.5,
        streamFactory: new Materom\Vigil\SocketStreamFactory,
        buffer: new RecordsBuffer(length: 500),
        tokenHash: 'abcdefg',
        events: $this->app->make(Dispatcher::class),
    );

    $ingest->ping();
})->throws(Exception::class, 'Connection refused');

it('ships real hook records end to end through the service provider wiring', function () {
    $collector = new FakeCollector('2:OK');
    $events = $this->app->make(Dispatcher::class);
    $dispatched = [];
    $events->listen(IngestingEvents::class, function (IngestingEvents $event) use (&$dispatched) {
        $dispatched[] = count($event->records);
    });

    $this->app->make(Core::class)->ingest = makeIngest($collector, $events);

    DB::select('select 42 as answer');
    $this->app->make(Core::class)->digest();

    $frame = $collector->frame();
    $records = json_decode($frame['body'], true);

    expect($frame['tokenHash'])->toBe('6fb2a45')
        ->and($dispatched)->toBe([1])
        ->and($records)->toHaveCount(1)
        ->and(array_keys($records[0]))->toBe($this->expectedKeys('query'))
        ->and($records[0]['sql'])->toBe('select 42 as answer');

    $collector->close();
});
