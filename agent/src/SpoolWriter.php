<?php

namespace Materom\VigilAgent;

use Closure;

use function basename;
use function fclose;
use function fflush;
use function fopen;
use function fsync;
use function ftruncate;
use function function_exists;
use function fwrite;
use function is_resource;
use function microtime;
use function strlen;
use function strpbrk;
use function strtr;

/**
 * Appends each accepted frame body as one line to the open segment and rotates it.
 *
 * `append()` returns only after the line has been handed to the operating system (`fflush`, plus `fsync` when
 * enabled), so a frame the relay acknowledged survives a relay crash. A segment is rotated (renamed from `.open`
 * to `.ndjson`) once it holds `segmentBytes` bytes or `segmentSeconds` after its first line. Above `maxBytes` of
 * spooled data `append()` refuses and the caller closes the connection without acknowledging.
 */
final class SpoolWriter
{
    private const SIZE_REFRESH_INTERVAL = 5.0;

    private const FULL_REFRESH_INTERVAL = 1.0;

    private const FULL_LOG_INTERVAL = 60.0;

    /**
     * @var resource|null
     */
    private $handle = null;

    private ?string $openPath = null;

    private int $openBytes = 0;

    private float $openedAt = 0.0;

    private int $sequence = 0;

    private int $spooledBytes = 0;

    private float $sizeCheckedAt = -INF;

    private float $fullLoggedAt = -INF;

    private int $refusedWhileFull = 0;

    /**
     * @var Closure(): float
     */
    private Closure $clock;

    /**
     * @param  (Closure(): float)|null  $clock
     */
    public function __construct(
        private SpoolDirectory $directory,
        private Logger $logger,
        private int $segmentBytes = 4194304,
        private float $segmentSeconds = 5.0,
        private int $maxBytes = 1073741824,
        private bool $fsync = false,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * Close segments a previous relay left open, so the uploader can ship them. Returns how many.
     */
    public function start(): int
    {
        $this->directory->ensureExists();
        $this->directory->removeOrphanCheckpoints();

        return $this->directory->recoverOpenSegments();
    }

    /**
     * Append one frame body as a line. False when the spool is full or the write failed: nothing was spooled.
     */
    public function append(string $body): bool
    {
        if ($this->isFull()) {
            $this->logFull();

            return false;
        }

        if (strpbrk($body, "\r\n") !== false) {
            $body = strtr($body, "\r\n", '  ');
        }

        $line = $body."\n";

        if (! $this->writeLine($line)) {
            return false;
        }

        $this->openBytes += strlen($line);
        $this->spooledBytes += strlen($line);

        if ($this->openBytes >= $this->segmentBytes) {
            $this->rotate();
        }

        return true;
    }

    /**
     * Rotate the open segment once it is old enough. Called periodically by the event loop.
     */
    public function tick(): void
    {
        if ($this->openPath !== null && ($this->clock)() - $this->openedAt >= $this->segmentSeconds) {
            $this->rotate();
        }
    }

    public function rotate(): void
    {
        if ($this->openPath === null) {
            return;
        }

        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->directory->close($this->openPath);
        $this->handle = null;
        $this->openPath = null;
        $this->openBytes = 0;
    }

    public function isFull(): bool
    {
        $now = ($this->clock)();
        $full = $this->spooledBytes >= $this->maxBytes;

        if ($now - $this->sizeCheckedAt >= ($full ? self::FULL_REFRESH_INTERVAL : self::SIZE_REFRESH_INTERVAL)) {
            $this->spooledBytes = $this->directory->totalBytes();
            $this->sizeCheckedAt = $now;
        }

        return $this->spooledBytes >= $this->maxBytes;
    }

    public function openPath(): ?string
    {
        return $this->openPath;
    }

    private function writeLine(string $line): bool
    {
        if ($this->handle === null && ! $this->open()) {
            return false;
        }

        $written = @fwrite($this->handle, $line);

        if ($written === strlen($line) && fflush($this->handle) && (! $this->fsync || ! function_exists('fsync') || fsync($this->handle))) {
            return true;
        }

        $this->logger->critical('Spool write to ['.basename((string) $this->openPath).'] failed: payload not acknowledged.');

        if ($written !== false && $written > 0) {
            @ftruncate($this->handle, $this->openBytes);
        }

        $this->rotate();

        return false;
    }

    private function open(): bool
    {
        $now = ($this->clock)();
        $path = $this->directory->newOpenSegment($now, $this->sequence);
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            $this->logger->critical("Unable to create a spool segment in [{$this->directory->path}]: payload not acknowledged.");

            return false;
        }

        $this->handle = $handle;
        $this->openPath = $path;
        $this->openBytes = 0;
        $this->openedAt = $now;

        return true;
    }

    private function logFull(): void
    {
        $this->refusedWhileFull++;
        $now = ($this->clock)();

        if ($now - $this->fullLoggedAt < self::FULL_LOG_INTERVAL) {
            return;
        }

        $this->logger->error('Spool is full ['.Logger::bytes($this->spooledBytes).' of '.Logger::bytes($this->maxBytes).']: closing connections without acknowledging ['.$this->refusedWhileFull.' refused].');
        $this->fullLoggedAt = $now;
        $this->refusedWhileFull = 0;
    }
}
