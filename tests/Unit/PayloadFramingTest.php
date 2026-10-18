<?php

use Materom\Vigil\Payload;

it('frames a JSON payload exactly like laravel/nightwatch', function () {
    $tokenHash = substr(hash('xxh128', 'vigil-test-token'), 0, 7);

    $frame = Payload::json([['v' => 1, 't' => 'log', 'message' => 'héllo/world', 'duration' => 1.0]], $tokenHash)->pull();

    $body = '[{"v":1,"t":"log","message":"héllo/world","duration":1.0}]';

    expect($tokenHash)->toBe('6fb2a45')
        ->and($frame)->toBe((strlen($body) + 11).':v1:6fb2a45:'.$body)
        ->and($frame)->toBe('70:v1:6fb2a45:[{"v":1,"t":"log","message":"héllo/world","duration":1.0}]');
});

it('frames a PING text payload', function () {
    expect(Payload::text('PING', '6fb2a45')->pull())->toBe('15:v1:6fb2a45:PING');
});

it('refuses to pull a payload twice', function () {
    $payload = Payload::text('PING', '6fb2a45');
    $payload->pull();

    $payload->pull();
})->throws(RuntimeException::class, 'Payload has already been read');
