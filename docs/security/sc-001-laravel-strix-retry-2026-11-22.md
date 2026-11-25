# SC-001 — Laravel re-check & Strix retry (2026-11-22)

## 1. Target

- **Application**: CMT Dashboard — Laravel rebuild (cutover target per PRD §P6 / SC-001), codebase at `D:/PROJECTS/cmt-dash/laravel/`
- **Target URL**: `http://127.0.0.1:8002` (Laravel dev server; confirmed listening)
- **Commit SHA**: `c6976bcd3ef96bcb1d360c4f952e070cf66277d7`
- **Gate**: SC-001 / NFR-001 — pre-cutover pentest: 0 Critical, 0 High findings (PRD.md §499, specs/cmt-dashboard/spec.md §SC-001)

## 2. Strix scan attempts (2026-11-22)

Fresh Strix scan against `http://127.0.0.1:8002` could **not complete**.

| Run | Result | Evidence |
|-----|--------|----------|
| `strix -t http://127.0.0.1:8002 -n -m quick` | FAILED | TUI: "LLM CONNECTION FAILED ... Error code: 402 - {'error': {'message': 'Insufficient credit balance.', 'type': 'billing_error', 'code': 'insufficient_balance'}}" |
| `host-docker-internal-8002_e416` (2026-11-22 17:02) | FAILED | `run.json` status=failed; LLM relay `9inference.cloud` returned 402 insufficient balance; 0 surfaces, 0 findings; `strix_runs/host-docker-internal-8002_e416/` |
| `host-docker-internal-8002_a1ea` (2026-11-22 16:49) | FAILED | `strix_fresh.log`: `openai.BadRequestError` 400 from relay (`v1.inifree.my.id`), STRIX_EXIT=1 |

Cause: LLM relay credit exhausted / relay errors (`v1.inifree.my.id` previously DNS-unresolvable). No alternate LLM configured (`STRIX_LLM=openai/deepseek-v4.1-flash`, `OPENAI_API_BASE=http://127.0.0.1:18081/v1` in `~/.strix/cli-config.json`). Strix CLI has no local/offline model option, so no fresh Strix SARIF report exists for this build.

## 3. Manual re-verification (cookie-aware `urllib` probe, 2026-11-22)

`GET /login` → HTTP 200. Login form emits CSRF field `_token` and `XSRF-TOKEN` cookie; session cookie `cmt-dashboard-session` set without `Secure` flag (HttpOnly, SameSite=Lax).

| Probe | Result | Interpretation |
|-------|--------|----------------|
| `POST /login` `_token` + `username=admin&password=admin` | HTTP 200, returns dashboard HTML (`<title>CMT Dashboard</title>`) | **default credentials accepted — vuln-0001 OPEN** |
| `POST /login` `_token` + `email=admin&password=admin` | HTTP 422 (`username` required) | same auth path; no account lockout/rate limiting observed across repeated rapid POSTs |
| Session cookie `cmt-dashboard-session` | `HttpOnly; SameSite=Lax`, **no `Secure`** | cookie flag finding from original scan persists |

`vuln-0001` (CRITICAL, CVSS 9.1): **OPEN on Laravel** — `admin:admin` accepted at `POST /login` on `http://127.0.0.1:8002`; no rate limiting; session cookie lacks `Secure` flag.

`vuln-0002` (MEDIUM, CVSS 5.3): not reproduced on Laravel — `GET /api/summary` returns HTTP 404 (route absent; confirmed in prior card `t_8a0aacb6`). Session-protected in current FastAPI source (`t_ecaabd56`).

## 4. Gate result

**BLOCKED** — gate criteria: CLEAN only if zero CRITICAL open AND all MEDIUM fixed or owner-accepted.

| Criterion | Status | Detail |
|-----------|--------|--------|
| Zero CRITICAL open | FAIL | vuln-0001 CRITICAL OPEN on Laravel (cutover target) — `admin:admin` accepted at `POST /login`, re-verified 2026-11-22 |
| All MEDIUM fixed or owner-accepted | PASS (Laravel) | vuln-0002 not reproduced on Laravel |

**Result: BLOCKED.** Gate fails because CRITICAL finding vuln-0001 remains open on the Laravel target. Fresh Strix confirmation is pending relay credit.

## 5. Conditions to reach CLEAN

1. Fix vuln-0001 on Laravel: remove seeded legacy `admin`/`superadmin` accounts from Laravel SQLite DB, ensure `CMT_USERS` env is sole credential source, add/enforce Laravel login rate limiting (`throttle:5,1`).
2. Complete `t_009b8736` critical-fix verification across both stacks (Python + Laravel) and record evidence.
3. Confirm FastAPI `/api/summary` protection in deployed build (`t_1153e307`) or obtain owner acceptance.
4. Re-run Strix against current Laravel build at `http://127.0.0.1:8002` (once relay credit restored or alternate LLM configured) and confirm 0 Critical / 0 High findings.
