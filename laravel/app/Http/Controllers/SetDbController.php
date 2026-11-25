<?php

namespace App\Http\Controllers;

use App\Support\CompanyDbManager;
use App\Support\CmtI18n;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Company DB switcher — POST /set-db, and language switcher — POST /set-lang.
 *
 * Contract parity with the Python app (main.py::set_db / set_lang):
 *   POST, form field; unknown value silently falls back (db -> default,
 *   lang -> 'id'); responds 303 to the referer (or /); sets a 1-year cookie
 *   (max_age=31536000, samesite=lax, Secure per CMT_COOKIE_SECURE). cmt_db
 *   is NOT HttpOnly (Python parity); cmt_lang neither.
 */
class SetDbController extends Controller
{
    public const ONE_YEAR_SECONDS = 31536000;

    public function __construct(
        protected CompanyDbManager $manager,
        protected CmtI18n $i18n,
    ) {
    }

    public function setDb(Request $request): RedirectResponse
    {
        $requested = (string) $request->input('db_name', '');

        // Unknown name -> cookie carries the default (silent fallback, Python
        // parity). The manager owns this rule; the controller only turns it
        // into an HTTP response.
        $name = $this->manager->isValidName($requested) ? $requested : $this->manager->defaultName();

        return $this->switchResponse($request, $this->manager->dbCookie(), $name);
    }

    public function setLang(Request $request): RedirectResponse
    {
        $lang = (string) $request->input('lang', '');
        if (! in_array($lang, CmtI18n::SUPPORTED, true)) {
            $lang = 'id';
        }

        return $this->switchResponse($request, 'cmt_lang', $lang);
    }

    protected function switchResponse(Request $request, string $cookieName, string $value): RedirectResponse
    {
        $response = redirect()->to($request->headers->get('referer', '/'), 303);

        return $response->withCookie(cookie(
            $cookieName,
            $value,
            self::ONE_YEAR_SECONDS / 60,     // minutes -> Max-Age = 31536000
            '/',                             // path
            null,                            // domain
            (bool) config('cmt.cookie_secure'), // CMT_COOKIE_SECURE (Python parity)
            false,                           // NOT httpOnly — Python set_cookie omits it
            false,                           // raw
            'lax',                           // sameSite
        ));
    }
}
