<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\CmtRole;
use App\Http\Middleware\CmtSession;
use App\Http\Middleware\ResolveCompanyDb;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([\App\Console\Commands\ParityCapture::class,
        \App\Console\Commands\PurgeWeakCredentials::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            ResolveCompanyDb::class,
            CmtSession::class,
            AddSecurityHeaders::class,
        ]);

        // Per-request access-log tag: request_id + start time (outermost
        // of the app stack, like Python's RequestLogMiddleware).
        $middleware->append([\App\Http\Middleware\LogRequest::class]);

        $middleware->alias([
            'cmt.auth' => CmtRole::class,
            'cmt.role' => CmtRole::class,
        ]);

        // KG-3 parity: /set-db (and /set-lang) are PUBLIC POST endpoints
        // in the Python app — no login, no CSRF token. Reproduce exactly.
        $middleware->validateCsrfTokens(except: [
            'set-db',
            'set-lang',
            'api/capture/p5',
        ]);

        // Byte-parity with Python's Set-Cookie: the cmt_db cookie carries a
        // plaintext DB name there (nothing sensitive) — no Laravel encryption.
        // The session cookie is httpOnly+secure-flagged but not value-encrypted
        // (Python sets the raw token).
        $middleware->encryptCookies(except: [
            'cmt_db',
            'cmt_session',
            'cmt_lang',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // p5 capture API error shapes per contract (docs/decisions/p5-capture-harness.md)
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request): ?\Illuminate\Http\JsonResponse {
            if (! $request->is('api/capture/p5')) {
                return null;
            }
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
            }
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException) {
                return response()->json(['ok' => false, 'error' => 'rate_limited'], 429);
            }
            return null;
        });

        // Custom 404 page preserving sidebar chrome (resources/views/errors/404.blade.php
        // extends layouts.cmt). API and JSON requests fall through to the default handler.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request): ?\Illuminate\Http\Response {
            if (! $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                return null;
            }
            $accept = $request->header('Accept', '');
            if (! str_contains($accept, 'text/html')) {
                return null;
            }
            return response()->view('errors.404', [], 404);
        });
    })->create();
