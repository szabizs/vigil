<?php

namespace Materom\Vigil\Records;

final class Notification
{
    public function __construct(
        public readonly string $channel,
        public readonly string $class,
        public readonly int $duration,
    ) {
        //
    }
}
