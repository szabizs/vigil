<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Materom\Vigil\Facades\Vigil;
use Materom\Vigil\Tests\Support\Fixtures\RecordingJob;

beforeEach(function () {
    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    RecordingJob::$handled = 0;
});

it('captures the queued job and the job attempt through a real worker', function () {
    RecordingJob::dispatch()->onQueue('reports');

    Vigil::digest();

    $queued = $this->ingest->first('queued-job');

    expect(array_keys($queued))->toBe($this->expectedKeys('queued-job'))
        ->and($queued['v'])->toBe(1)
        ->and($queued['name'])->toBe(RecordingJob::class)
        ->and($queued['connection'])->toBe('database')
        ->and($queued['queue'])->toBeString()
        ->and($queued['job_id'])->toBeString()->not->toBeEmpty();

    $this->runArtisan(['queue:work', 'database', '--queue=reports', '--once']);

    $attempt = $this->ingest->first('job-attempt');

    expect(RecordingJob::$handled)->toBe(1)
        ->and(array_keys($attempt))->toBe($this->expectedKeys('job-attempt'))
        ->and($attempt['v'])->toBe(1)
        ->and($attempt['job_id'])->toBe($queued['job_id'])
        ->and($attempt['name'])->toBe(RecordingJob::class)
        ->and($attempt['status'])->toBe('processed')
        ->and($attempt['attempt'])->toBe(1)
        ->and($attempt['queries'])->toBeGreaterThanOrEqual(1)
        ->and($attempt['queue'])->toBe('reports');
});
