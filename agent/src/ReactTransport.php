<?php

namespace Materom\VigilAgent;

use Closure;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Socket\Connector;
use Throwable;

/**
 * Transport over the asynchronous ReactPHP HTTP client.
 */
final class ReactTransport implements Transport
{
    private Browser $browser;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        private string $url,
        private array $headers,
        float $connectionTimeout,
        float $timeout,
        private Logger $logger,
        ?LoopInterface $loop = null,
    ) {
        $connector = new Connector(['timeout' => $connectionTimeout], $loop);

        $this->browser = (new Browser($connector, $loop))
            ->withTimeout($timeout)
            ->withRejectErrorResponse(false)
            ->withFollowRedirects(false);
    }

    public function post(string $gzipBody, Closure $onResponse, Closure $onError): void
    {
        $this->browser->post($this->url, $this->headers, $gzipBody)->then(
            static function (ResponseInterface $response) use ($onResponse): void {
                $onResponse($response->getStatusCode(), (string) $response->getBody());
            },
            static function (Throwable $e) use ($onError): void {
                $onError($e->getMessage());
            },
        )->catch(function (Throwable $e): void {
            $this->logger->critical('Internal error while handling an upload response: '.$e->getMessage());
        });
    }
}
