<?php

namespace Materom\Vigil\Hooks;

use Materom\Vigil\Core;
use Materom\Vigil\State\CommandState;
use Materom\Vigil\State\RequestState;
use Throwable;

/**
 * @internal
 */
final class CreateQueuePayloadHandler
{
    /**
     * @param  Core<RequestState|CommandState>  $vigil
     */
    public function __construct(
        private Core $vigil,
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function __invoke(mixed $connection, mixed $queue, array $payload): array
    {
        try {
            return [
                ...$payload,
                'nightwatch' => [
                    ...($payload['nightwatch'] ?? []),  // @phpstan-ignore arrayUnpacking.nonIterable
                    'job_id' => $this->vigil->uuid->make(),
                ],
            ];
        } catch (Throwable $e) {
            $this->vigil->report($e, handled: true);

            return $payload;
        }
    }
}
