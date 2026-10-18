<?php

use Materom\Vigil\Tests\Support\AgentFakes;
use Materom\VigilAgent\BatchReader;

beforeEach(function () {
    $this->path = AgentFakes::tempDirectory();
    $this->segment = $this->path.'/0000000000001-000001.ndjson';
});

afterEach(function () {
    AgentFakes::removeDirectory($this->path);
});

it('gzips the concatenation of every frame\'s records into {"records":[...]}', function () {
    file_put_contents($this->segment, "[{\"t\":\"log\",\"n\":1},{\"t\":\"log\",\"n\":2}]\n[{\"t\":\"query\",\"n\":3}]\n");

    $batch = (new BatchReader)->read($this->segment);
    $raw = gzdecode($batch->body);

    expect(substr($batch->body, 0, 2))->toBe("\x1f\x8b")
        ->and($raw)->toBe('{"records":[{"t":"log","n":1},{"t":"log","n":2},{"t":"query","n":3}]}')
        ->and(json_decode($raw, true)['records'])->toHaveCount(3)
        ->and($batch->frames)->toBe(2)
        ->and($batch->start)->toBe(0)
        ->and($batch->end)->toBe(filesize($this->segment))
        ->and($batch->completesSegment())->toBeTrue();
});

it('matches the original agent\'s StreamBuffer byte for byte', function () {
    $frames = ['[{"a":1}]', '[{"b":2},{"c":3}]', '[{"d":"x,y]"}]'];
    file_put_contents($this->segment, implode("\n", $frames)."\n");

    $streamBuffer = '';

    foreach ($frames as $frame) {
        $input = substr($frame, 1, -1);
        $streamBuffer = $streamBuffer === '' ? $input : "{$streamBuffer},{$input}";
    }

    expect(gzdecode((new BatchReader)->read($this->segment)->body))->toBe('{"records":['.$streamBuffer.']}');
});

it('skips empty arrays, accepts a lone record object and skips garbage', function () {
    file_put_contents($this->segment, "[]\n{\"t\":\"log\"}\nnot json\n[{\"t\":\"query\"}]\n");

    $batch = (new BatchReader)->read($this->segment);

    expect(gzdecode($batch->body))->toBe('{"records":[{"t":"log"},{"t":"query"}]}')
        ->and($batch->frames)->toBe(2)
        ->and($batch->skippedLines)->toBe(1)
        ->and($batch->completesSegment())->toBeTrue();
});

it('drops a torn last line that was never acknowledged', function () {
    file_put_contents($this->segment, "[{\"n\":1}]\n[{\"n\":2");

    $batch = (new BatchReader)->read($this->segment);

    expect(gzdecode($batch->body))->toBe('{"records":[{"n":1}]}')
        ->and($batch->completesSegment())->toBeTrue();
});

it('splits a segment larger than the upload cap into several uploads by lines', function () {
    $lines = [];

    for ($n = 1; $n <= 10; $n++) {
        $lines[] = '[{"n":'.$n.',"pad":"'.str_repeat('p', 80).'"}]';
    }

    file_put_contents($this->segment, implode("\n", $lines)."\n");
    $reader = new BatchReader(maxBytes: 300);
    $records = [];
    $offset = 0;
    $uploads = 0;

    while (($batch = $reader->read($this->segment, $offset)) !== null) {
        $uploads++;
        expect($batch->rawBytes)->toBeLessThanOrEqual(300);

        foreach (json_decode(gzdecode($batch->body), true)['records'] as $record) {
            $records[] = $record['n'];
        }

        $offset = $batch->end;
    }

    expect($uploads)->toBe(4)
        ->and($records)->toBe(range(1, 10));
});

it('ships a single line larger than the cap on its own', function () {
    file_put_contents($this->segment, '[{"big":"'.str_repeat('b', 500)."\"}]\n[{\"n\":2}]\n");

    $reader = new BatchReader(maxBytes: 100);
    $first = $reader->read($this->segment);
    $second = $reader->read($this->segment, $first->end);

    expect($first->frames)->toBe(1)
        ->and($first->completesSegment())->toBeFalse()
        ->and(gzdecode($second->body))->toBe('{"records":[{"n":2}]}')
        ->and($reader->read($this->segment, $second->end))->toBeNull();
});
