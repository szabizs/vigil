<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Auth\Events\Logout;
use Materom\Vigil\Core;
use Materom\Vigil\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class LogoutListener
{
    /**
     * @param  Core<RequestState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(Logout $event): void
    {
        try {
            if ($event->user !== null) {
                $this->vigil->remember($event->user);
            }
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
