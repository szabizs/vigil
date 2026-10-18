<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Materom\Vigil\Facades\Vigil;

it('captures a request record with the exact catalogue keys', function () {
    Route::get('/vigil/{id}', fn (string $id) => response()->json(['id' => $id]))->name('vigil.show');

    $this->get('/vigil/5?foo=bar')->assertOk();

    $record = $this->ingest->first('request');

    expect(array_keys($record))->toBe($this->expectedKeys('request'))
        ->and($record['v'])->toBe(1)
        ->and($record['t'])->toBe('request')
        ->and($record['deploy'])->toBe('test-deploy')
        ->and($record['server'])->toBe('test-server')
        ->and($record['method'])->toBe('GET')
        ->and($record['url'])->toBe('http://localhost/vigil/5?foo=bar')
        ->and($record['route_name'])->toBe('vigil.show')
        ->and($record['route_path'])->toBe('/vigil/{id}')
        ->and($record['route_methods'])->toBe(['GET', 'HEAD'])
        ->and($record['status_code'])->toBe(200)
        ->and($record['response_size'])->toBe(strlen('{"id":"5"}'))
        ->and($record['_group'])->toHaveLength(32)
        ->and($record['trace_id'])->toBeString()->not->toBeEmpty()
        ->and($record['user'])->toBe('')
        ->and($record['payload'])->toBe('');
});

it('counts queries executed during the request and emits query records', function () {
    Route::get('/queries', function () {
        DB::select('select 1 as one');
        DB::select('select 2 as two');

        return 'ok';
    });

    $this->get('/queries')->assertOk();

    expect($this->ingest->first('request')['queries'])->toBe(2)
        ->and($this->ingest->records('query'))->toHaveCount(2)
        ->and(array_keys($this->ingest->first('query')))->toBe($this->expectedKeys('query'))
        ->and($this->ingest->first('query')['execution_source'])->toBe('request');
});

it('redacts the configured headers', function () {
    $this->reconfigure(['vigil.redact_headers' => ['Authorization', 'X-Api-Key']]);

    Route::get('/headers', fn () => 'ok');

    $this->get('/headers', [
        'Authorization' => 'Bearer super-secret',
        'X-Api-Key' => 'abcdef',
        'X-Visible' => 'shown',
    ])->assertOk();

    $headers = json_decode($this->ingest->first('request')['headers'], true);

    expect($headers['authorization'])->toBe(['Bearer [12 bytes redacted]'])
        ->and($headers['x-api-key'])->toBe(['[6 bytes redacted]'])
        ->and($headers['x-visible'])->toBe(['shown']);
});

it('captures and redacts the payload of a failed request', function () {
    $this->reconfigure([
        'vigil.capture_request_payload' => true,
        'vigil.redact_payload_fields' => ['password', 'secret_field'],
    ]);

    Route::post('/fail', fn () => throw new RuntimeException('Boom'));

    $this->postJson('/fail', ['email' => 'a@b.c', 'password' => 'secret123', 'nested' => ['secret_field' => 'xyz']])
        ->assertStatus(500);

    $request = $this->ingest->first('request');
    $payload = json_decode($request['payload'], true);

    expect($request['status_code'])->toBe(500)
        ->and($request['exceptions'])->toBe(1)
        ->and($request['exception_preview'])->toBe('Boom')
        ->and($payload['email'])->toBe('a@b.c')
        ->and($payload['password'])->toBe('[9 bytes redacted]')
        ->and($payload['nested']['secret_field'])->toBe('[3 bytes redacted]')
        ->and($payload['_nightwatch_files'])->toBe([]);
});

it('reports an unhandled exception as a v3 exception record with a JSON trace', function () {
    Route::get('/explode', fn () => throw new LogicException('Unhandled kaboom', 42));

    $this->get('/explode')->assertStatus(500);

    $record = $this->ingest->first('exception');
    $trace = json_decode($record['trace'], true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($record))->toBe($this->expectedKeys('exception'))
        ->and($record['v'])->toBe(3)
        ->and($record['class'])->toBe(LogicException::class)
        ->and($record['message'])->toBe('Unhandled kaboom')
        ->and($record['code'])->toBe('42')
        ->and($record['handled'])->toBeFalse()
        ->and($record['execution_source'])->toBe('request')
        ->and($trace)->toBeArray()->not->toBeEmpty()
        ->and(array_keys($trace[0]))->toBe(['file', 'source', 'code']);
});

it('reports a manually reported exception as handled', function () {
    Route::get('/handled', function () {
        report(new DomainException('Handled one'));

        return 'ok';
    });

    $this->get('/handled')->assertOk();

    $record = $this->ingest->first('exception');

    expect(array_keys($record))->toBe($this->expectedKeys('exception'))
        ->and($record['v'])->toBe(3)
        ->and($record['handled'])->toBeTrue()
        ->and($this->ingest->first('request')['exceptions'])->toBe(1);
});

it('emits a user record resolved through Vigil::user()', function () {
    Vigil::user(fn ($user) => ['name' => 'Custom '.$user->name, 'username' => 'custom-handle']);

    Route::get('/me', fn () => 'ok');

    $this->actingAs(new GenericUser(['id' => 42, 'name' => 'Ada', 'email' => 'ada@example.com']))
        ->get('/me')
        ->assertOk();

    $user = $this->ingest->first('user');

    expect(array_keys($user))->toBe($this->expectedKeys('user'))
        ->and($user['v'])->toBe(1)
        ->and($user['id'])->toBe('42')
        ->and($user['name'])->toBe('Custom Ada')
        ->and($user['username'])->toBe('custom-handle')
        ->and($this->ingest->first('request')['user'])->toBe('42');
});

it('drops every buffered record when the request sample rate is 0.0', function () {
    $this->reconfigure(['vigil.sampling.requests' => 0.0]);

    Route::get('/unsampled', function () {
        DB::select('select 1');

        return 'ok';
    });

    $this->get('/unsampled')->assertOk();

    expect($this->ingest->records())->toBe([])
        ->and($this->core()->sampling())->toBeFalse();
});
