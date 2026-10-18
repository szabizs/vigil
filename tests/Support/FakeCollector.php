<?php

namespace Materom\Vigil\Tests\Support;

use Closure;
use Materom\Vigil\SocketStreamFactory;
use RuntimeException;

/**
 * A tiny in-process collector built on stream_socket_server.
 *
 * PHP is single threaded, so the reply cannot be written after the client sends its frame and before
 * it blocks waiting for the acknowledgment. Instead the reply is queued on the accepted connection as
 * soon as the client connects (it sits in the kernel socket buffer until the client reads it), and
 * the frame the client sent is read and parsed afterwards with frame().
 */
final class FakeCollector
{
    /**
     * @var resource
     */
    private $server;

    /**
     * @var list<resource>
     */
    private array $connections = [];

    public function __construct(private string $reply = '2:OK')
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

        if ($server === false) {
            throw new RuntimeException("Unable to start the fake collector: {$errorMessage} [{$errorCode}]");
        }

        $this->server = $server;
    }

    public function address(): string
    {
        return (string) stream_socket_get_name($this->server, false);
    }

    /**
     * @return Closure(string, float): resource
     */
    public function streamFactory(): Closure
    {
        return function (string $address, float $connectionTimeout) {
            $client = (new SocketStreamFactory)($address, $connectionTimeout);

            $connection = stream_socket_accept($this->server, 1.0);

            if ($connection === false) {
                throw new RuntimeException('The fake collector did not accept the connection');
            }

            fwrite($connection, $this->reply);

            $this->connections[] = $connection;

            return $client;
        };
    }

    public function connectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * Read and validate one frame of the form `<len>:v1:<tokenHash>:<body>` from the given connection.
     *
     * @return array{length: int, version: string, tokenHash: string, body: string, raw: string, received: int}
     */
    public function frame(int $connection = 0): array
    {
        $stream = $this->connections[$connection] ?? throw new RuntimeException("No connection [{$connection}]");

        stream_set_timeout($stream, 1);

        $prefix = '';

        while (! str_ends_with($prefix, ':')) {
            $char = fread($stream, 1);

            if ($char === false || $char === '') {
                throw new RuntimeException("Incomplete frame length prefix [{$prefix}]");
            }

            $prefix .= $char;
        }

        $length = (int) substr($prefix, 0, -1);
        $message = '';

        while (strlen($message) < $length) {
            $chunk = fread($stream, $length - strlen($message));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $message .= $chunk;
        }

        [$version, $tokenHash, $body] = explode(':', $message, 3) + ['', '', ''];

        return [
            'length' => $length,
            'version' => $version,
            'tokenHash' => $tokenHash,
            'body' => $body,
            'raw' => $prefix.$message,
            'received' => strlen($message),
        ];
    }

    public function close(): void
    {
        foreach ($this->connections as $connection) {
            fclose($connection);
        }

        fclose($this->server);
    }
}
