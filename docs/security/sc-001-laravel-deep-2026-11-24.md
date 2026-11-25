# Security Penetration Test Report

**Generated:** 2026-11-24 06:37:19 UTC

# Executive Summary

An authorized black-box assessment of the **CMT Dashboard** (`http://host.docker.internal:8002`) was conducted. The target is a server-rendered **Laravel / PHP 8.2.28** operational dashboard for a logistics/parts business, with UI in Bahasa Indonesia and data synchronised from a legacy **FoxPro** system.

**Overall risk posture:** Elevated but not critical. The application presents a very small attack surface (seven routes total) and enforces authentication, CSRF, parameterised database access, and a fixed dataset allowlist correctly on the paths that were fully reachable. One confirmed issue was found, of low severity, and several higher-value surfaces could not be reached without credentials.

**Key findings**
- **Missing CSRF token enforcement** on `POST /set-db` and `POST /set-lang` (Low). These session-mutating routes accept requests with no `_token` at all, unlike `/login` which correctly returns HTTP 419. The practical impact is limited because the session cookie is `SameSite=Lax`, which blocks cross-site POST cookie delivery; it is a genuine missing-control failure that becomes directly exploitable if that attribute is ever relaxed.

**Business impact**
- The confirmed CSRF gap can, under conditions that are not currently met, allow a forced switch of the victim's active dataset/branch and interface language — a data-scoping confusion risk rather than direct data theft.
- Notably, **no SQL injection, no XSS, no SSTI, no path traversal, no authentication bypass, and no unauthenticated data exposure** were found on the reachable surface.

**Principal limitation.** Several high-value surfaces are behind authentication, and no valid credential was obtained because `POST /login` enforces a 5-attempt-then-429 throttle that is not bypassable via forwarding-header rotation. The authenticated area (`/admin`, `/users`, the post-login dashboard, and the `/sync` FoxPro import handler) therefore remains unassessed — this is the single largest gap in this engagement and the recommended focus of any follow-up.

**Overarching remediation theme:** apply the CSRF control uniformly across all session-mutating routes, and gate the dataset selector on an explicit server-side allowlist so a client-chosen value can never influence database or connection resolution.

# Methodology

**Engagement type:** Black-box external assessment. No source code, build artefacts, or credentials were provided; all conclusions are drawn from live HTTP interaction.

**Frameworks followed:** OWASP Web Security Testing Guide (WSTG) and PTES, with severity expressed via CVSS v3.1.

**Scope:** The single in-scope target `http://host.docker.internal:8002` (plain HTTP, port 8002). No other hosts or ports were assessed.

**Approach and coverage:**
- **Reconnaissance and attack-surface mapping** — technology fingerprinting, header and cookie analysis, full route enumeration by crawling and by directory/content fuzzing (custom CMT/Laravel wordlist, `common.txt` at 4,751 words, extension sweeps for `.php`/`.blade.php`/`.json`/`.xml`/`.csv`/`.xls`/`.pdf`/`.bak`/`.txt`, and `index.php` path-confusion prefixes). The surface resolved to seven routes.
- **Authentication and session testing** — login behaviour, throttle characteristics and bypass attempts, session-cookie tamper and forgery testing, session-state mutation endpoints, and CSRF enforcement matrix across all state-changing routes.
- **Injection testing** — SQL injection against the login form (error-based, boolean-paired, and time-based) and against the dataset-selection parameter (SQL, path, and DSN payload classes); template-injection probes; command/argument injection, path traversal, and SSRF probes against the sync route; CRLF/header-injection probes.
- **Client-side and disclosure testing** — reflected and stored cross-site scripting across every discoverable input, header, and the custom error view; sensitive-file exposure enumeration (`.env` variants, `.git`, composer manifests, framework internals, log files, and alternate extensions).
- **Authorization testing** — method-by-method verb matrix against every route to detect action-level authorization gaps, and object-reference fuzzing where identifiers existed.

**Constraints affecting depth:**
- The target was persistently unstable — roughly 10–20 seconds per request, throughput capped near 0.3 requests/second, and multi-minute windows of outright unreachability. Transport failures were treated as inconclusive and never as negative results, and every negative was re-confirmed during a healthy window.
- `POST /login` enforces a five-attempt throttle keyed to the real client socket; forwarding-header rotation was tested and does not bypass it. This constrained credential testing and prevented reaching the authenticated surface.
- 404 filtering required a body marker rather than a hash, because the custom error view embeds a live timestamp that changes the 404 body on every response.

# Technical Analysis

**Severity model.** Ratings were assigned with CVSS v3.1 on the basis of demonstrated impact only. Reachability and missing controls were not treated as impact in their own right, and every non-None impact metric maps to explicit evidence in the corresponding finding.

**Findings overview**

1. **Missing CSRF token enforcement on `/set-db` and `/set-lang` — Low (CVSS 3.1, `CWE-352`).**
   Both routes mutate session state while accepting requests that omit the `_token` field entirely; a tokenless `POST /set-db` with body `db_name=SBY` returns HTTP 303 and sets `cmt_db=SBY`, flipping the active dataset selector from `CMT` to `SBY`. The control is demonstrably configured and working elsewhere: `POST /login` rejects the same tokenless request with HTTP 419. Severity is held to Low because the session cookie carries `SameSite=Lax`, which prevents cross-site POST cookie delivery in current browsers — a real missing-control/defence-in-depth failure rather than a presently exploitable cross-site state change.

**Areas tested and cleared**

2. **SQL injection in `POST /login` — ruled out.** The full payload matrix (`admin'--`, `' OR 1=1--`, `admin' OR '1'='1`, `admin'#`, the boolean pair `AND 1=1#` / `AND 1=2#`, time-based `SLEEP(15)#`, `'||'`, and password-field injection) returned an identical HTTP 200 login-failure body of 9,578 bytes in every case, with no database error markers, no boolean differential, and no time delta. The login path uses parameterised query binding, so payloads are treated as literal credential strings.

3. **SQL and path/DSN injection via `POST /set-db` — no issue found.** The endpoint is unauthenticated and accepts arbitrary `db_name` values without CSRF enforcement, writing the value verbatim into a plaintext one-year `cmt_db` cookie. However, the read side enforces a fixed seven-value allowlist (`CME`, `CMT`, `CSM`, `DATA`, `INIT`, `MAL`, `SBY`): invalid values and forged cookies all resolve to `CMT`, and protected routes ignore the cookie entirely. SQL payloads and path/DSN payloads produced a plain 303 with no error, stack trace, or database-name leak.

4. **Unauthenticated access to `POST /sync` — ruled out.** Every body variant (empty, JSON, form-encoded, multipart, XML) returns HTTP 419 from CSRF verification, and returns a 303 redirect to `/login` once a valid CSRF token and guest session are supplied. The handler never executes pre-authentication, so command-injection, path-traversal, and SSRF payloads all produced the identical redirect and reached no sink. No sibling upload or import route exists.

5. **Cross-site scripting, template injection, and header injection on the guest surface — no issue found.** Canary tokens placed in path segments, query strings, all form fields, cookies, and request headers (`Referer`, `User-Agent`, `Accept-Language`, `X-Forwarded-For`, `X-Forwarded-Host`, `X-Original-URL`) were never reflected. The custom error view is static and does not echo the requested path or query. Template probes (`{{7*7}}`, `${7*7}`, `#{7*7}`, `<%= 7*7 %>`) rendered literally. CRLF payloads produced no injected `Set-Cookie` or `Location` header, and both `db_name` and `lang` are allowlist-validated before any cookie is written.

6. **Sensitive-file and information disclosure — no issue found.** `/.env` plus nine variants, `.git` metadata, composer manifests, `/artisan`, `/vendor/autoload.php`, framework config caches, and route files all returned 404. Log files returned 403 and resisted bypass via null bytes, trailing dots, double slashes, and `index.php` path confusion. The only readable artefact is `public/.htaccess`, a standard Laravel rewrite ruleset containing no secrets.

7. **Content and route discovery — exhausted.** Beyond the seven known routes, no additional endpoint was discovered by any wordlist or extension sweep. No route returned data, a JSON list, or an exported file without authentication, and no action-level authorization gap was found: every auth-gated route returned a redirect to `/login` across all HTTP verbs including `POST`, `PUT`, `DELETE`, `PATCH`, and `OPTIONS`.

8. **Authentication bypass and session forgery — ruled out.** Tampering with the encrypted `cmt-dashboard-session` payload is rejected and a fresh cookie is issued, consistent with Laravel's encrypted-and-MACed session handling. The login throttle is keyed to the real client address; rotating `X-Forwarded-For` and `X-Real-IP` does not evade it.

**Systemic themes**
- **Control application is uneven, not absent.** Authentication and CSRF are correctly enforced on the login route and on `/sync`; the gap is that the two session-mutating convenience routes were left outside the same middleware group. The corrective action is to apply the existing control uniformly rather than to build a new one.
- **Trust in the dataset selector is correctly bounded on the read path but unbounded on the write path.** The write path accepts and stores any string; the read path then validates against a seven-value allowlist. That layering is what keeps the issue low severity, and it should be made explicit and intentional.
- **Legacy integration is the residual risk concentration.** The FoxPro synchronisation bridge is the only component whose behaviour could not be observed, and legacy import handlers of this shape are the most common location for command execution and path manipulation. It warrants priority review once access is available.

**Unresolved items (open, not clean)**
- Authenticated surface entirely unassessed — `/admin`, `/users`, the post-login dashboard, and their sidebar navigation — because no valid credential was obtained.
- `POST /sync` handler behaviour after authentication, including anything reachable through FoxPro path or import-parameter control.
- Whether an arbitrary `db_name` value persists in session state and later reaches a database connection name on the authenticated read path.
- Completion of the credential list, which was blocked by the non-bypassable five-attempt throttle.
- Session-identifier rotation on successful login and server-side session invalidation on logout, both of which require an authenticated session to test.

# Recommendations

**Immediate**
1. **Apply CSRF verification to `/set-db` and `/set-lang`.** Register both routes inside the same middleware group that already protects `/login`, and confirm they are not listed in the CSRF exception configuration. After the change, a tokenless POST to either route should return HTTP 419, matching `/login`.
2. **Validate the dataset selector against a server-side allowlist.** Accept only the seven declared dataset codes (`CME`, `CMT`, `CSM`, `DATA`, `INIT`, `MAL`, `SBY`) and reject anything else, so a client-chosen value can never be stored in session state or influence database or connection resolution.
3. **Apply the same allowlist discipline to the language parameter.** Restrict it to the supported set (`id`, `en`, `zh`) server-side rather than relying on the UI to constrain it.

**Short-term**
4. **Restore assessment access to the authenticated surface.** Provision a low-privilege test account (and, separately, an administrative one) so that `/admin`, `/users`, and the post-login dashboard can be tested for object-level and function-level authorization failures — the highest-probability remaining location for a high-severity finding.
5. **Review the FoxPro synchronisation handler.** Audit the `/sync` implementation for command execution, path manipulation, and remote-fetch behaviour, and confirm that no request-supplied value reaches a shell invocation, a filesystem path, or an outbound network destination. Treat the legacy bridge as the highest residual risk in the application.
6. **Make the read-side dataset validation explicit and intentional.** Document that the selector is validated on the read path, so a future change to the session-storage side does not silently become exploitable.

**Medium-term**
7. **Remove or harden the `public/.htaccess` exposure.** Serve the document root configuration such that the rewrite ruleset is not readable over HTTP; it discloses deployment structure without serving any functional purpose.
8. **Reduce version disclosure.** Suppress the `X-Powered-By` header that advertises the exact PHP version, and keep the runtime patched against that version's advisories.
9. **Consider tightening the content security policy.** The current policy permits `'unsafe-inline'` script execution; removing it would significantly raise the cost of any future stored-XSS defect in the dashboard.
10. **Address deployment stability and transport.** The service was persistently slow with multi-minute outages, which materially limited test depth; investigate the resource constraints. If the application is ever exposed beyond a trusted network, terminate TLS in front of it and enforce HSTS.

**Retest and validation**
Re-test the immediate items first: confirm that tokenless requests to `/set-db` and `/set-lang` now return HTTP 419, and that an out-of-allowlist `db_name` is rejected with no session mutation. Then invalidate the throttle constraint by provisioning test accounts, and re-run the authorization and sync-handler testing against the authenticated surface, which is where the remaining risk is concentrated.

