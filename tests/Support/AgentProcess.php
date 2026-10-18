<?php

namespace Materom\Vigil\Tests\Support;

use RuntimeException;

/**
 * The built relay PHAR running in a child process (see run-agent.php).
 */
final class AgentProcess
{
    /**
     * @var resource
     */
    private $process;

    private ?int $exitCode = null;

    public readonly string $logPath;

    /**
     * @param  array<string, mixed>  $variables
     */
    public function __construct(public readonly string $workPath, array $variables)
    {
        $configPath = $workPath.'/agent-'.bin2hex(random_bytes(4)).'.json';
        $this->logPath = $configPath.'.log';

        file_put_contents($configPath, json_encode([
            'pharPath' => self::pharPath(),
            ...$variables,
        ], JSON_THROW_ON_ERROR));

        $process = proc_open(
            [PHP_BINARY, __DIR__.'/run-agent.php', $configPath],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->logPath, 'a'], 2 => ['file', $this->logPath, 'a']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the relay.');
        }

        $this->process = $process;
    }

    public static function pharPath(): string
    {
        return dirname(__DIR__, 2).'/agent/build/agent.phar';
    }

    public function log(): string
    {
        return is_file($this->logPath) ? (string) file_get_contents($this->logPath) : '';
    }

    public function waitForLog(string $needle, float $timeout = 10.0): bool
    {
        return self::waitUntil(fn () => str_contains($this->log(), $needle), $timeout);
    }

    public function running(): bool
    {
        if ($this->exitCode !== null) {
            return false;
        }

        $status = proc_get_status($this->process);

        if ($status['running']) {
            return true;
        }

        $this->exitCode = $status['exitcode'];

        return false;
    }

    public function signal(int $signal): void
    {
        proc_terminate($this->process, $signal);
    }

    public function waitForExit(float $timeout = 10.0): ?int
    {
        self::waitUntil(fn () => ! $this->running(), $timeout);

        return $this->exitCode;
    }

    public function stop(): void
    {
        if ($this->running()) {
            proc_terminate($this->process, 9);
            $this->waitForExit(2.0);
        }

        proc_close($this->process);
    }

    public static function waitUntil(callable $condition, float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;

        do {
            if ($condition()) {
                return true;
            }

            usleep(20000);
        } while (microtime(true) < $deadline);

        return (bool) $condition();
    }

    public static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        fclose($server);

        return $port;
    }
}
