<?php

use Materom\Vigil\Tests\Support\AgentFakes;
use Materom\VigilAgent\SpoolDirectory;
use Materom\VigilAgent\SpoolWriter;

beforeEach(function () {
    $this->path = AgentFakes::tempDirectory();
    $this->directory = new SpoolDirectory($this->path);
    $this->now = 1000.0;
    $this->logger = AgentFakes::logger();
    $this->writer = fn (int $segmentBytes = 4194304, float $segmentSeconds = 5.0, int $maxBytes = 1073741824) => new SpoolWriter(
        $this->directory, $this->logger, $segmentBytes, $segmentSeconds, $maxBytes, clock: fn () => $this->now,
    );
});

afterEach(function () {
    AgentFakes::removeDirectory($this->path);
});

it('appends one line per frame body to an open segment, flushed before returning', function () {
    $writer = ($this->writer)();
    $writer->start();

    expect($writer->append('[{"t":"log","n":1}]'))->toBeTrue()
        ->and($writer->append('[{"t":"log","n":2}]'))->toBeTrue();

    $open = $this->directory->openSegments();

    expect($open)->toHaveCount(1)
        ->and(file_get_contents($open[0]))->toBe("[{\"t\":\"log\",\"n\":1}]\n[{\"t\":\"log\",\"n\":2}]\n")
        ->and($this->directory->segments())->toBe([]);
});

it('keeps every body on one line', function () {
    $writer = ($this->writer)();
    $writer->append("[{\"m\":\"a\nb\"}]");
    $writer->rotate();

    expect(file_get_contents($this->directory->segments()[0]))->toBe("[{\"m\":\"a b\"}]\n");
});

it('rotates a segment by size', function () {
    $writer = ($this->writer)(segmentBytes: 30);

    $writer->append('[{"t":"log","n":1}]');
    expect($this->directory->segments())->toBe([]);

    $writer->append('[{"t":"log","n":2}]');
    expect($this->directory->segments())->toHaveCount(1)
        ->and($this->directory->openSegments())->toBe([]);

    $writer->append('[{"t":"log","n":3}]');
    expect($this->directory->openSegments())->toHaveCount(1);
});

it('rotates a segment by time', function () {
    $writer = ($this->writer)(segmentSeconds: 5.0);
    $writer->append('[1]');

    $this->now += 4.9;
    $writer->tick();
    expect($this->directory->segments())->toBe([]);

    $this->now += 0.2;
    $writer->tick();
    expect($this->directory->segments())->toHaveCount(1)
        ->and(basename($this->directory->segments()[0]))->toMatch('/^\d{13}-\d{6}\.ndjson$/');
});

it('names segments so they sort chronologically', function () {
    $writer = ($this->writer)(segmentBytes: 1);

    foreach ([1, 2, 3] as $n) {
        $writer->append("[{$n}]");
        $this->now += 0.001;
    }

    $contents = array_map(fn ($path) => trim(file_get_contents($path)), $this->directory->segments());

    expect($contents)->toBe(['[1]', '[2]', '[3]']);
});

it('recovers open segments left behind by a crashed relay and drops empty ones', function () {
    file_put_contents($this->path.'/0000000000001-000001.open', "[1]\n");
    file_put_contents($this->path.'/0000000000002-000001.open', '');
    file_put_contents($this->path.'/0000000000000-000009.ndjson.offset', '4');

    $recovered = ($this->writer)()->start();

    expect($recovered)->toBe(2)
        ->and(array_map('basename', $this->directory->segments()))->toBe(['0000000000001-000001.ndjson'])
        ->and($this->directory->openSegments())->toBe([])
        ->and(file_exists($this->path.'/0000000000000-000009.ndjson.offset'))->toBeFalse()
        ->and(is_dir($this->path.'/dead'))->toBeTrue();
});

it('refuses to append beyond the byte cap and logs it once a minute', function () {
    $writer = ($this->writer)(maxBytes: 10);

    expect($writer->append('[{"n":1}]'))->toBeTrue()
        ->and($writer->append('[{"n":2}]'))->toBeFalse()
        ->and($writer->append('[{"n":3}]'))->toBeFalse();

    $fullLines = array_filter($this->logger->lines, fn ($line) => str_contains($line, 'Spool is full'));
    expect($fullLines)->toHaveCount(1);

    $this->now += 61;
    $writer->append('[{"n":4}]');

    $fullLines = array_filter($this->logger->lines, fn ($line) => str_contains($line, 'Spool is full'));
    expect($fullLines)->toHaveCount(2);
});

it('accepts again once the uploader has drained the spool', function () {
    $writer = ($this->writer)(segmentBytes: 1, maxBytes: 10);
    $writer->append('[{"n":1}]');
    expect($writer->append('[{"n":2}]'))->toBeFalse();

    foreach ($this->directory->segments() as $segment) {
        $this->directory->delete($segment);
    }

    $this->now += 1.5;

    expect($writer->append('[{"n":3}]'))->toBeTrue();
});

it('counts only the unshipped part of partially uploaded segments', function () {
    file_put_contents($this->path.'/0000000000001-000001.ndjson', str_repeat('x', 99)."\n");
    $this->directory->saveCheckpoint($this->path.'/0000000000001-000001.ndjson', 60);

    expect($this->directory->totalBytes())->toBe(40)
        ->and($this->directory->checkpoint($this->path.'/0000000000001-000001.ndjson'))->toBe(60);
});

it('dead-letters a byte range with the error next to it', function () {
    $segment = $this->path.'/0000000000001-000001.ndjson';
    file_put_contents($segment, "[1]\n[2]\n[3]\n");
    $this->directory->ensureExists();

    $dead = $this->directory->deadLetter($segment, 4, 8, 413, 'Payload too large');

    expect(basename($dead))->toBe('0000000000001-000001-4.ndjson')
        ->and(file_get_contents($dead))->toBe("[2]\n")
        ->and(json_decode(file_get_contents($this->path.'/dead/0000000000001-000001-4.error.json'), true))
        ->toMatchArray(['status' => 413, 'error' => 'Payload too large', 'offset' => 4, 'bytes' => 4]);
});

it('lets only one relay use a spool', function () {
    $first = $this->directory->lock();

    expect($first)->toBeResource()
        ->and((new SpoolDirectory($this->path))->lock())->toBeNull();

    fclose($first);

    expect((new SpoolDirectory($this->path))->lock())->toBeResource();
});
