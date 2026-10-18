<?php

namespace Materom\Vigil\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Materom\Vigil\Core;
use Materom\Vigil\Facades\Vigil;
use Materom\Vigil\State\RequestState;
use Throwable;

final class Sample
{
    /**
     * @param  Core<RequestState>  $vigil
     */
    public function __construct(private Core $vigil)
    {
        //
    }

    public static function rate(float $rate): string
    {
        $rate = (string) $rate;

        if ($rate === '0') {
            $rate = '0.0';
        }

        return self::class.':'.$rate;
    }

    public static function always(): string
    {
        return self::class.':1.0';
    }

    public static function never(): string
    {
        return self::class.':0.0';
    }

    public function handle(Request $request, Closure $next, float $rate): mixed
    {
        try {
            $this->vigil->sample($rate);
        } catch (Throwable $e) {
            Vigil::unrecoverableExceptionOccurred($e);
        }

        return $next($request);
    }
}
