<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Materom\Vigil\Facades\Vigil;
use Materom\Vigil\Records\Query;
use Materom\Vigil\Tests\Support\Fixtures\PingNotification;

it('captures a query record with the exact catalogue keys', function () {
    DB::select('select 1 as one');

    Vigil::digest();

    $record = $this->ingest->first('query');

    expect(array_keys($record))->toBe($this->expectedKeys('query'))
        ->and($record['v'])->toBe(1)
        ->and($record['sql'])->toBe('select 1 as one')
        ->and($record['connection'])->toBe('testing');
});

it('rejects queries matched by Vigil::rejectQueries()', function () {
    Vigil::rejectQueries(fn (Query $query) => str_contains($query->sql, 'secret_table'));

    DB::select("select 'x' as secret_table");
    DB::select('select 2 as kept');

    Vigil::digest();

    expect(array_column($this->ingest->records('query'), 'sql'))->toBe(['select 2 as kept']);
});

it('captures a log record with the exact catalogue keys', function () {
    Log::channel('vigil')->warning('Disk almost full', ['disk' => 'local']);

    Vigil::digest();

    $record = $this->ingest->first('log');

    expect(array_keys($record))->toBe($this->expectedKeys('log'))
        ->and($record['v'])->toBe(1)
        ->and($record['level'])->toBe('warning')
        ->and($record['message'])->toBe('Disk almost full')
        ->and(json_decode($record['context'], true))->toBe(['disk' => 'local']);
});

it('captures cache hit, miss and write records', function () {
    Cache::get('vigil-test-key');
    Cache::put('vigil-test-key', 'value', 60);
    Cache::get('vigil-test-key');

    Vigil::digest();

    $records = $this->ingest->records('cache-event');

    expect(array_column($records, 'type'))->toBe(['miss', 'write', 'hit'])
        ->and(array_keys($records[0]))->toBe($this->expectedKeys('cache-event'))
        ->and($records[0]['v'])->toBe(1)
        ->and($records[0]['key'])->toBe('vigil-test-key')
        ->and($records[0]['store'])->toBeString()
        ->and($records[1]['ttl'])->toBe(60);
});

it('captures a mail record with the exact catalogue keys', function () {
    Mail::raw('Hello there', function ($message) {
        $message->to(['a@example.com', 'b@example.com'])->cc('c@example.com')->subject('Greetings');
    });

    Vigil::digest();

    $record = $this->ingest->first('mail');

    expect(array_keys($record))->toBe($this->expectedKeys('mail'))
        ->and($record['v'])->toBe(1)
        ->and($record['mailer'])->toBe('array')
        ->and($record['subject'])->toBe('Greetings')
        ->and($record['to'])->toBe(2)
        ->and($record['cc'])->toBe(1)
        ->and($record['bcc'])->toBe(0)
        ->and($record['failed'])->toBeFalse();
});

it('captures a notification record with the exact catalogue keys', function () {
    Notification::route('mail', 'someone@example.com')->notify(new PingNotification);

    Vigil::digest();

    $record = $this->ingest->first('notification');

    expect(array_keys($record))->toBe($this->expectedKeys('notification'))
        ->and($record['v'])->toBe(1)
        ->and($record['channel'])->toBe('mail')
        ->and($record['class'])->toBe(PingNotification::class)
        ->and($record['failed'])->toBeFalse();
});

it('captures an outgoing request through the Guzzle middleware', function () {
    $stack = HandlerStack::create(new MockHandler([new Response(201, [], '{"ok":true}')]));
    $stack->push(Vigil::guzzleMiddleware());

    $response = (new Client(['handler' => $stack]))->post('https://api.example.com/v1/items?page=2', ['body' => 'abcd']);

    Vigil::digest();

    $record = $this->ingest->first('outgoing-request');

    expect($response->getStatusCode())->toBe(201)
        ->and(array_keys($record))->toBe($this->expectedKeys('outgoing-request'))
        ->and($record['v'])->toBe(1)
        ->and($record['host'])->toBe('api.example.com')
        ->and($record['method'])->toBe('POST')
        ->and($record['url'])->toBe('https://api.example.com/v1/items?page=2')
        ->and($record['status_code'])->toBe(201)
        ->and($record['request_size'])->toBe(4)
        ->and($record['response_size'])->toBe(11);
});

it('reports handled exceptions from the console with version 3', function () {
    report(new RuntimeException('Console handled'));

    Vigil::digest();

    $record = $this->ingest->first('exception');

    expect(array_keys($record))->toBe($this->expectedKeys('exception'))
        ->and($record['v'])->toBe(3)
        ->and($record['handled'])->toBeTrue()
        ->and(json_decode($record['trace'], true))->toBeArray();
});
