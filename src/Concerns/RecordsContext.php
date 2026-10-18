<?php

namespace Materom\Vigil\Concerns;

use Illuminate\Support\Facades\Context;
use Materom\Vigil\Compatibility;
use Materom\Vigil\Facades\Vigil;
use Materom\Vigil\Types\Str;
use Throwable;

use function json_encode;

/**
 * @internal
 */
trait RecordsContext
{
    private function serializedContext(): string
    {
        if (! Compatibility::$contextExists) {
            return '';
        }

        try {
            return Str::text(json_encode((object) Context::all(), flags: JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            Vigil::unrecoverableExceptionOccurred($e);

            return '{"_nightwatch_error":"Failed to serialize context"}';
        }
    }
}
