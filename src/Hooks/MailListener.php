<?php

namespace Materom\Vigil\Hooks;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Materom\Vigil\Core;
use Materom\Vigil\State\CommandState;
use Materom\Vigil\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class MailListener
{
    /**
     * @param  Core<RequestState|CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    public function __invoke(MessageSending|MessageSent $event): void
    {
        try {
            $this->vigil->mail($event);
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);
        }
    }
}
