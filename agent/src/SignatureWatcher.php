<?php

namespace Materom\VigilAgent;

use Closure;

use function clearstatcache;
use function file_get_contents;

/**
 * Detects that a deploy replaced the relay's PHAR on disk (its `signature.txt` changed).
 *
 * `check()` runs periodically. The first time the signature differs from the one read at startup, `$onChanged`
 * is called once and the watcher stops checking; the caller schedules the graceful shutdown so the process
 * manager restarts the relay on the new code. A missing or empty signature (mid-deploy) is ignored.
 */
final class SignatureWatcher
{
    private bool $changed = false;

    /**
     * @param  Closure(string $signature): void  $onChanged
     */
    public function __construct(
        private string $path,
        private string $expected,
        private Closure $onChanged,
    ) {
        //
    }

    public static function resolvePath(string $basePath): string
    {
        $envoyerPath = preg_replace('#^(.*?)/releases/\d+/(.*)$#', '$1/current/$2', $basePath) ?? '';

        if (is_file($envoyerPath.'/signature.txt') && realpath($envoyerPath) === $basePath) {
            return $envoyerPath.'/signature.txt';
        }

        return $basePath.'/signature.txt';
    }

    public function check(): bool
    {
        if ($this->changed) {
            return true;
        }

        clearstatcache(true, $this->path);
        $signature = is_file($this->path) ? (string) @file_get_contents($this->path) : '';

        if ($signature === '' || $signature === $this->expected) {
            return false;
        }

        $this->changed = true;
        ($this->onChanged)($signature);

        return true;
    }

    public function changed(): bool
    {
        return $this->changed;
    }
}
