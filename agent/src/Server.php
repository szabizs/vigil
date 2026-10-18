<?php

namespace Materom\VigilAgent;

use Closure;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\ServerInterface;
use React\Socket\TcpServer;
use Throwable;

/**
 * The loopback TCP endpoint the Vigil client writes frames to.
 *
 * A frame is spooled as soon as it is complete and `2:OK` is written only after the spool accepted it; a refused
 * frame (token mismatch, spool full, write failure) closes the connection without an acknowledgment. A frame
 * with an unknown version means the application now runs a newer client, so the relay shuts down to be restarted
 * on the matching code (exactly like the original agent).
 */
final class Server
{
    private const IDLE_CONNECTION_SECONDS = 30.0;

    private ?ServerInterface $socket = null;

    private int $connections = 0;

    /**
     * @param  Closure(string $reason): void  $onShutdownRequested
     * @param  (Closure(): ServerInterface)|null  $socketResolver
     */
    public function __construct(
        private string $listenOn,
        private FrameHandler $handler,
        private Logger $logger,
        private LoopInterface $loop,
        private Closure $onShutdownRequested,
        private ?Closure $socketResolver = null,
    ) {
        //
    }

    public function start(): void
    {
        $this->socket = $this->socketResolver !== null
            ? ($this->socketResolver)()
            : new TcpServer($this->listenOn, $this->loop);

        $this->socket->on('connection', $this->accept(...));

        $this->socket->on('error', function (Throwable $e): void {
            $this->logger->error("Server error: {$e->getMessage()}");
        });
    }

    public function close(): void
    {
        $this->socket?->close();
        $this->socket = null;
    }

    public function address(): ?string
    {
        return $this->socket?->getAddress();
    }

    public function connections(): int
    {
        return $this->connections;
    }

    private function accept(ConnectionInterface $connection): void
    {
        $this->connections++;
        $frame = new Frame;
        $handled = false;

        $idle = $this->loop->addTimer(self::IDLE_CONNECTION_SECONDS, static function () use ($connection): void {
            $connection->close();
        });

        $connection->on('data', function (string $chunk) use ($connection, $frame, &$handled): void {
            if ($handled) {
                return;
            }

            $frame->append($chunk);

            if ($frame->malformed) {
                $handled = true;
                $this->logger->error("Malformed payload received. Length: [{$frame->length}] Received: [{$frame->bytesReceived}]");
                $connection->close();

                return;
            }

            if (! $frame->complete) {
                return;
            }

            $handled = true;

            try {
                $decision = $this->handler->handle($frame);
            } catch (Throwable $e) {
                $this->logger->critical("Unable to spool a payload: {$e->getMessage()}");
                $decision = FrameHandler::REFUSE;
            }

            if ($decision === FrameHandler::ACK) {
                $connection->end('2:OK');

                return;
            }

            $connection->close();

            if ($decision === FrameHandler::SHUTDOWN) {
                ($this->onShutdownRequested)('payload version changed');
            }
        });

        $connection->on('close', function () use ($frame, &$handled, $idle): void {
            $this->loop->cancelTimer($idle);
            $this->connections--;

            if (! $handled && ! $frame->isEmpty()) {
                $this->logger->error("Incomplete payload received. Length: [{$frame->length}] Received: [{$frame->bytesReceived}]");
            }
        });

        $connection->on('error', function (Throwable $e): void {
            $this->logger->error("Connection error: {$e->getMessage()}");
        });
    }
}
