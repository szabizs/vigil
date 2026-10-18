<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Materom\Vigil\Tests\Support\Fixtures\GreetCommand;

beforeEach(function () {
    $this->app->make(ConsoleKernel::class)->registerCommand($this->app->make(GreetCommand::class));
});

it('captures a command record with the exact catalogue keys', function () {
    $status = $this->runArtisan(['vigil-test:greet', 'Ada', '--loud']);

    $record = $this->ingest->first('command');

    expect($status)->toBe(3)
        ->and(array_keys($record))->toBe($this->expectedKeys('command'))
        ->and($record['v'])->toBe(1)
        ->and($record['class'])->toBe(GreetCommand::class)
        ->and($record['name'])->toBe('vigil-test:greet')
        ->and($record['command'])->toBe('vigil-test:greet Ada --loud')
        ->and($record['exit_code'])->toBe(3)
        ->and($record['queries'])->toBe(1)
        ->and($record['deploy'])->toBe('test-deploy')
        ->and($this->ingest->first('query')['execution_source'])->toBe('command');
});

it('drops the command records when the command sample rate is 0.0', function () {
    $this->reconfigure(['vigil.sampling.commands' => 0.0]);
    $this->app->make(ConsoleKernel::class)->registerCommand($this->app->make(GreetCommand::class));

    $this->runArtisan(['vigil-test:greet', 'Ada']);

    expect($this->ingest->records())->toBe([]);
});

it('captures a scheduled task record with the exact catalogue keys', function () {
    $ran = false;

    $this->app->make(Schedule::class)
        ->call(function () use (&$ran) {
            $ran = true;
        })
        ->name('vigil-test-task')
        ->everyMinute()
        ->withoutOverlapping();

    $this->runArtisan(['schedule:run']);

    $record = $this->ingest->first('scheduled-task');

    expect($ran)->toBeTrue()
        ->and(array_keys($record))->toBe($this->expectedKeys('scheduled-task'))
        ->and($record['v'])->toBe(1)
        ->and($record['name'])->toBe('vigil-test-task')
        ->and($record['cron'])->toBe('* * * * *')
        ->and($record['status'])->toBe('processed')
        ->and($record['without_overlapping'])->toBeTrue()
        ->and($record['on_one_server'])->toBeFalse()
        ->and($this->ingest->records('command'))->toBe([]);
});
