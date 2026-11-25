<?php

namespace App\Http\Controllers;

use App\Support\CmtAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Login/logout — contract parity with the Python app (main.py):
 *   GET  /login  -> login page
 *   POST /login  -> missing fields 422 (FastAPI validation); bad creds
 *                   re-render page (200); good creds 302 -> / with cmt_session
 *                   cookie (httpOnly, lax, Secure per CMT_COOKIE_SECURE). The
 *                   cookie has NO Max-Age — a browser-session cookie, exactly
 *                   like Starlette's set_cookie without max_age.
 *   POST /logout -> invalidate server-side, 302 -> /login, cookie deleted
 *                   (Max-Age=0 forget, like Starlette delete_cookie).
 */
class AuthController extends Controller
{
    public function __construct(
        protected CmtAuth $auth,
    ) {
    }

    public function showLogin(): \Illuminate\View\View
    {
        return view('login');
    }

    public function login(Request $request): mixed
    {
        // FastAPI Form(...) fields are REQUIRED: a missing field is a 422
        // validation response, not a re-rendered login form.
        $missing = [];
        foreach (['username', 'password'] as $field) {
            if (! $request->has($field)) {
                // Key order + shape mirror FastAPI's RequestValidationError item
                // exactly (validated against real FastAPI output).
                $missing[] = [
                    'type' => 'missing',
                    'loc' => ['body', $field],
                    'msg' => 'Field required',
                    'input' => null,
                ];
            }
        }
        if ($missing !== []) {
            return response()->json(['detail' => $missing], 422);
        }

        $token = $this->auth->authenticate(
            (string) $request->input('username'),
            (string) $request->input('password'),
        );

        if ($token === null) {
            // Python re-renders the form with an error (HTTP 200, not a 401).
            return view('login', ['error' => 'Username or password incorrect']);
        }

        $response = redirect()->to('/', 302);

        // expire=0 -> no Max-Age attribute -> browser-session cookie (parity
        // with Starlette set_cookie(max_age absent)).
        return $response->withCookie(cookie(
            (string) config('cmt.session_cookie'),
            $token,
            0,
            '/',
            null,
            (bool) config('cmt.cookie_secure'),
            true,       // httpOnly
            false,      // raw
            'lax',
        ));
    }

    public function logout(Request $request): RedirectResponse
    {
        $token = $request->cookie((string) config('cmt.session_cookie'));
        if (is_string($token) && $token !== '') {
            $this->auth->logout($token);
        }

        $response = redirect()->to('/login', 302);

        // delete_cookie parity: Max-Age=0 expiry -> browser deletes the cookie.
        return $response->withCookie(cookie()->forget((string) config('cmt.session_cookie')));
    }
}
