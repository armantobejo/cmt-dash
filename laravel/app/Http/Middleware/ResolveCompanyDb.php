<?php

namespace App\Http\Middleware;

use App\Support\CompanyDbManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the active company DB from the cmt_db cookie on every request.
 * Everything else (validity, fallback, availability) is CompanyDbManager's
 * job — this class only bridges HTTP to it.
 */
class ResolveCompanyDb
{
    public function __construct(
        protected CompanyDbManager $manager,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->manager->resolve($request->cookie((string) config('cmt.db_cookie')));

        return $next($request);
    }
}
