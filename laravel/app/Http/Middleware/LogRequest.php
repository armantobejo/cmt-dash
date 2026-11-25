<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class LogRequest
{
    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('cmt_request_id', bin(random_bytes(8)));
        $request->attributes->set('cmt_request_start', microtime(true));

        return $next($request);
    }
}
