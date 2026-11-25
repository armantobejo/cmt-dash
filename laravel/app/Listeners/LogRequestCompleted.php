<?php

namespace App\Listeners;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Log;

class LogRequestCompleted
{
    public function __construct(private mixed $auth)
    {
    }

    public function handle(RequestHandled $event): void
    {
        $request = $event->request;
        $response = $event->response;

        $username = '';
        if ($this->auth !== null) {
            $username = $this->auth->username ?? $this->auth->getUsername() ?? '';
        }

        Log::channel('cmt.access')->debug('request', [
            'event' => 'request',
            'request_id' => $request->attributes->get('cmt_request_id', ''),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $request->attributes->get('cmt_request_start', 0) > 0
                ? round((microtime(true) - $request->attributes->get('cmt_request_start')) * 1000, 2)
                : 0,
            'username' => $username,
        ]);
    }
}
