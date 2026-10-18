<?php

use Materom\Vigil\Payload;
use Materom\VigilAgent\Frame;

beforeEach(function () {
    $this->hash = Frame::tokenHash('secret');
});

it('derives the token hash from the first seven xxh128 characters', function () {
    expect($this->hash)->toBe(substr(hash('xxh128', 'secret'), 0, 7))->toHaveLength(7);
});

it('parses the exact frame the Vigil client writes', function () {
    $frame = new Frame;
    $frame->append(Payload::json([['t' => 'log', 'message' => 'a:b:c']], $this->hash)->pull());

    expect($frame->complete)->toBeTrue()
        ->and($frame->versionIsValid())->toBeTrue()
        ->and($frame->tokenHashMatches($this->hash))->toBeTrue()
        ->and($frame->value)->toBe('[{"t":"log","message":"a:b:c"}]')
        ->and(Frame::encode($frame->value, $this->hash))->toBe(Payload::json([['t' => 'log', 'message' => 'a:b:c']], $this->hash)->pull());
});

it('becomes complete only after the last chunk arrives', function () {
    $encoded = Frame::encode('[{"t":"log","message":"a:b:c"}]', $this->hash);
    $chunks = str_split($encoded, 5);
    $frame = new Frame;

    foreach ($chunks as $index => $chunk) {
        $frame->append($chunk);

        if ($index < count($chunks) - 1) {
            expect($frame->complete)->toBeFalse();
        }
    }

    expect($frame->complete)->toBeTrue()->and($frame->value)->toBe('[{"t":"log","message":"a:b:c"}]');
});

it('detects a ping', function () {
    $frame = new Frame;
    $frame->append(Payload::text('PING', $this->hash)->pull());

    expect($frame->complete)->toBeTrue()->and($frame->isPing())->toBeTrue();
});

it('flags a wrong version', function () {
    $frame = new Frame;
    $frame->append(Frame::encode('[]', $this->hash, 'v2'));

    expect($frame->complete)->toBeTrue()->and($frame->versionIsValid())->toBeFalse();
});

it('flags a wrong token hash', function () {
    $frame = new Frame;
    $frame->append(Frame::encode('[]', Frame::tokenHash('other')));

    expect($frame->complete)->toBeTrue()->and($frame->tokenHashMatches($this->hash))->toBeFalse();
});

it('never completes when the declared length does not match', function (int $delta) {
    [$length, $rest] = explode(':', Frame::encode('[]', $this->hash), 2);
    $frame = new Frame;
    $frame->append(($length + $delta).':'.$rest);

    expect($frame->complete)->toBeFalse();
})->with([-1, 1, 100]);

it('stays incomplete when the header is partial', function () {
    $frame = new Frame;
    $frame->append('17:v1:abc');

    expect($frame->complete)->toBeFalse()->and($frame->length)->toBeNull();
});

it('reports an empty connection', function () {
    expect((new Frame)->isEmpty())->toBeTrue();
});

it('stays complete when bytes arrive after the frame', function () {
    $frame = new Frame;
    $frame->append(Frame::encode('[{"t":"log"}]', $this->hash));
    $frame->append("\ntrailing");

    expect($frame->complete)->toBeTrue()
        ->and($frame->malformed)->toBeFalse()
        ->and($frame->value)->toBe('[{"t":"log"}]');
});

it('flags a body longer than declared in the same chunk', function () {
    $frame = new Frame;
    $frame->append(Frame::encode('[]', $this->hash).'extra');

    expect($frame->complete)->toBeFalse()->and($frame->malformed)->toBeTrue();
});

it('flags a header that does not parse within the limit', function (string $data) {
    $frame = new Frame;
    $frame->append($data);

    expect($frame->malformed)->toBeTrue()->and($frame->length)->toBeNull();
})->with([
    'no colons' => [str_repeat('x', Frame::MAX_HEADER_BYTES)],
    'colons too late' => [str_repeat('1', Frame::MAX_HEADER_BYTES).':v1:abc:[]'],
    'non numeric length' => ['abc:v1:hash:[]'],
]);

it('parses the header in bounded time for a colon-free stream', function () {
    $frame = new Frame;
    $chunk = str_repeat('x', 65536);
    $started = hrtime(true);

    for ($index = 0; $index < 256; $index++) {
        $frame->append($chunk);
    }

    expect($frame->malformed)->toBeTrue()
        ->and(strlen($frame->value))->toBeLessThan(70000)
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(0.5);
});
