<?php

namespace Materom\Vigil\Tests\Support;

use Materom\VigilAgent\Logger;

final class AgentFakes
{
    public static function logger(): Logger
    {
        return new Logger(null, null, 'verbose', remember: true);
    }

    public static function tempDirectory(): string
    {
        $path = sys_get_temp_dir().'/vigil-agent-test-'.bin2hex(random_bytes(6));
        mkdir($path, 0775, true);

        return $path;
    }

    public static function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path.'/'.$entry;
            is_dir($full) && ! is_link($full) ? self::removeDirectory($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
