<?php

namespace Materom\Vigil\Tests\Support\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

final class RecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public static int $handled = 0;

    public function handle(): void
    {
        self::$handled++;

        DB::select('select 1 as job_query');
    }
}
