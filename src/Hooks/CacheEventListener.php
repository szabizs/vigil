<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Cache\Events\CacheEvent;
use Materom\Vigil\Core;
use Materom\Vigil\State\CommandState;
use Materom\Vigil\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class CacheEventListener
{
    /**
     * @param  Core<RequestState|CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(CacheEvent $event): void
    {
        try {
            $this->vigil->cacheEvent($event);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
