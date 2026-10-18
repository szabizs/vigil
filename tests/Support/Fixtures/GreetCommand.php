<?php

namespace Materom\Vigil\Tests\Support\Fixtures;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class GreetCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'vigil-test:greet {name} {--loud}';

    /**
     * @var string
     */
    protected $description = 'Greets someone for the Vigil test-suite.';

    public function handle(): int
    {
        DB::select('select 1 as greet_query');

        $this->line('Hello '.$this->argument('name'));

        return 3;
    }
}
