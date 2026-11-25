<?php

namespace App\Http\Middleware;

use App\Support\CmtAuth;
use App\Support\CmtUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the cmt_session cookie into the per-request CmtUser (scoped
 * binding, mirroring Python's get_current_user dependency). Anonymous
 * requests get CmtUser::anonymous() — route middleware decides what that
 * is allowed to do.
 */
class CmtSession
{
    public function __construct(
        protected CmtAuth $auth,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie((string) config('cmt.session_cookie'));
        $user = is_string($token) && $token !== ''
            ? ($this->auth->userForToken($token) ?? CmtUser::anonymous())
            : CmtUser::anonymous();

        // Both a request attribute (read by middleware/controllers) and the
        // scoped container binding (read anywhere else via app(CmtUser::class)).
        $request->attributes->set('cmt_user', $user);
        app()->instance(CmtUser::class, $user);

        return $next($request);
    }
}
