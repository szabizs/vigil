<?php

namespace Materom\VigilAgent;

use function date;
use function fflush;
use function fwrite;
use function in_array;
use function strlen;
use function substr;

/**
 * Timestamped line logger writing to stdout (debug, info) and stderr (error, critical).
 *
 * Levels follow VIGIL_AGENT_LOG_LEVEL: `verbose` adds debug lines, `error` drops info lines and `critical`
 * keeps only critical lines (a rejected token, a spool that cannot be written).
 */
final class Logger
{
    /**
     * @var list<string>
     */
    public array $lines = [];

    /**
     * @param  resource|null  $stdout
     * @param  resource|null  $stderr
     * @param  'verbose'|'info'|'error'|'critical'  $level
     */
    public function __construct(
        private $stdout,
        private $stderr,
        private string $level = 'info',
        private bool $remember = false,
    ) {
        //
    }

    public static function levelFrom(?bool $silent, ?bool $quiet, ?bool $verbose): string
    {
        return match (true) {
            (bool) $silent => 'critical',
            (bool) $quiet => 'error',
            (bool) $verbose => 'verbose',
            default => 'info',
        };
    }

    public function debug(string $message): void
    {
        if ($this->level === 'verbose') {
            $this->write($this->stdout, 'DEBUG', $message);
        }
    }

    public function info(string $message): void
    {
        if (in_array($this->level, ['verbose', 'info'], true)) {
            $this->write($this->stdout, 'INFO', $message);
        }
    }

    public function error(string $message): void
    {
        if ($this->level !== 'critical') {
            $this->write($this->stderr, 'ERROR', $message);
        }
    }

    public function critical(string $message): void
    {
        $this->write($this->stderr, 'CRITICAL', $message);
    }

    /**
     * @param  resource|null  $stream
     */
    private function write($stream, string $label, string $message): void
    {
        $line = date('Y-m-d H:i:s').' ['.$label.'] '.$message;

        if ($this->remember) {
            $this->lines[] = $line;
        }

        if ($stream === null) {
            return;
        }

        $line .= PHP_EOL;

        while ($line !== '') {
            $written = @fwrite($stream, $line);

            if ($written === false || $written === 0) {
                return;
            }

            $line = substr($line, $written);
        }

        @fflush($stream);
    }

    public static function bytes(int|float $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024, 1).' KB',
            default => ((int) $bytes).' B',
        };
    }

    public static function limit(string $message, int $length = 500): string
    {
        return strlen($message) > $length + 5 ? substr($message, 0, $length).'[...]' : $message;
    }
}
