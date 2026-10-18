<?php

namespace Materom\VigilAgent;

use Closure;

/**
 * Posts one gzip-compressed `{"records":[...]}` body to `{base}/api/ingest`.
 */
interface Transport
{
    /**
     * Exactly one of the callbacks is invoked, possibly synchronously.
     *
     * @param  Closure(int $status, string $body): void  $onResponse
     * @param  Closure(string $error): void  $onError
     */
    public function post(string $gzipBody, Closure $onResponse, Closure $onError): void;
}
