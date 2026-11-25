<?php

namespace App\Http\Middleware;

use App\Support\CmtAuth;
use App\Support\CmtUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware aliases:
 *   'cmt.auth'       — 303 -> /login for anonymous users (require_login).
 *   'cmt.role:admin' — 403 if the user's role level is below the given role
 *                      (require_role). Implies cmt.auth semantics.
 */
class CmtRole
{
    public function handle(Request $request, Closure $next, string $minRole = ''): Response
    {
        $user = $request->get('cmt_user') ?? app(CmtUser::class);

        // require_login: anonymous -> 303 /login (FastAPI-style redirect).
        if (! $user->authenticated) {
            return redirect()->to('/login', 303);
        }

        // require_role: below the minimum level -> 403 with the Python detail.
        if ($minRole !== '') {
            $minLevel = CmtAuth::ROLE_LEVEL[$minRole] ?? 0;
            if ($user->level() < $minLevel) {
                // Body matches FastAPI's HTTPException rendering.
                return new JsonResponse(['detail' => "Requires role >= {$minRole}"], 403);
            }
        }

        return $next($request);
    }
}
