<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Console\Events\ArtisanStarting;
use Materom\Vigil\Core;
use Materom\Vigil\State\CommandState;
use Throwable;

/**
 * @internal
 */
final class ArtisanStartingListener
{
    /**
     * @param  Core<CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(ArtisanStarting $event): void
    {
        try {
            $this->vigil->captureArtisan($event->artisan);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
