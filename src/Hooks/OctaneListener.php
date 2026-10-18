<?php

namespace Materom\Vigil\Hooks;

use Laravel\Octane\Events\RequestReceived;
use Materom\Vigil\Core;
use Materom\Vigil\State\CommandState;
use Materom\Vigil\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class OctaneListener
{
    /**
     * @param  Core<RequestState|CommandState>  $vigil
     */
    public function __construct(private Core $vigil)
    {
        //
    }

    public function __invoke(RequestReceived $event): void // @phpstan-ignore class.notFound
    {
        try {
            $this->vigil->prepareForRequest($event->request); // @phpstan-ignore class.notFound
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
