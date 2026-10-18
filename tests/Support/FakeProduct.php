<?php

namespace Materom\Vigil\Tests\Support;

use RuntimeException;

/**
 * A fake Vigil product (`php -S` with fake-product.php) recording the batches the relay uploads.
 */
final class FakeProduct
{
    /**
     * @var resource|null
     */
    private $process = null;

    public function __construct(public readonly string $directory, public readonly int $port)
    {
        @mkdir($directory, 0775, true);
    }

    public function start(): self
    {
        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", __DIR__.'/fake-product.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
            $pipes,
            null,
            ['FAKE_PRODUCT_DIR' => $this->directory, 'PATH' => (string) getenv('PATH')],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the fake product.');
        }

        $this->process = $process;

        AgentProcess::waitUntil(function () {
            $socket = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $code, $message, 0.1);

            if ($socket === false) {
                return false;
            }

            fclose($socket);

            return true;
        }, 5.0);

        return $this;
    }

    public function url(): string
    {
        return "http://127.0.0.1:{$this->port}";
    }

    public function respondWith(int $status, ?string $message = null): void
    {
        $message === null ? @unlink($this->directory.'/message') : file_put_contents($this->directory.'/message', $message);
        file_put_contents($this->directory.'/status', (string) $status);
    }

    /**
     * @return list<array{status: int, headers: array<string, string>, gzip: bool, json: string, records: list<mixed>|null}>
     */
    public function requests(): array
    {
        $files = glob($this->directory.'/request-*.json') ?: [];
        sort($files);

        return array_map(fn ($file) => json_decode((string) file_get_contents($file), true), $files);
    }

    /**
     * Records of every non-empty batch, in arrival order.
     *
     * @return list<mixed>
     */
    public function records(): array
    {
        $records = [];

        foreach ($this->requests() as $request) {
            if ($request['status'] === 202) {
                array_push($records, ...($request['records'] ?? []));
            }
        }

        return $records;
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
            $this->process = null;
        }
    }
}
