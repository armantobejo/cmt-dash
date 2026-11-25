# Probe scope — CMT Dashboard liveness probe

**Owner:** afnan (architect)  
**Status:** final  
**Consumers:** probe client (t_200edbbd, yervant), probe tests (t_4cf1ce9b, yevna), probe review (t_9dc69ea8, bunda)

## 1. Target

| Field | Value |
|---|---|
| System | CMT Dashboard (FastAPI, read-only cargo/accounting dashboard) |
| Host | `localhost` (127.0.0.1) |
| Port | `8000` |
| Protocol | HTTP/1.1 over TCP (plaintext; `CMT_COOKIE_SECURE=0`, no TLS in dev) |
| Endpoint | `GET /api/health` |
| Request payload | None — GET, no query string, no body |
| Auth | Public — no session cookie, no role required (see `src/app/main.py` `@app.get("/api/health")`) |

## 2. Expected response

- Status: HTTP `200 OK`
- Content-Type: `application/json`
- Body: `{"status": "ok"}`

Any deviation = probe failure (section 4).

## 3. Timeout

- Total timeout: **3 seconds** (connect + read combined)
- Matches the container healthcheck in `docker-compose.yml` (`urllib.request.urlopen('http://localhost:8000/api/health', timeout=3)`)

## 4. Pass / fail criteria

**PASS when all of:**
1. TCP connect to 127.0.0.1:8000 succeeds within timeout.
2. Server responds with HTTP `200`.
3. Response body parses as JSON and `body["status"] == "ok"`.
4. End-to-end latency ≤ 3 seconds.

**FAIL when any of:**
1. Connection refused / DNS failure / route unreachable.
2. Timeout exceeded (> 3s) at connect or read.
3. Non-200 status (e.g. 500, 502, 404).
4. Body is not valid JSON, or `status` key missing, or `status != "ok"`.
5. Probe client throws/unhandled exception.

## 5. Out of scope

- Authenticated endpoints (`/api/summary`, `/api/reports/*`, etc.) — probe must not require credentials.
- Raw TCP or DB-level probe — HTTP is the protocol.
- User-supplied target addresses — target is a fixed constant in code (no SSRF surface, no secrets logged).

## 6. Reference

- `docker-compose.yml` service `dashboard`, port mapping `8000:8000`, healthcheck interval 30s / timeout 5s / retries 3.
- Existing (debug-only) probe test: `tests/test_zz_probe_soa.py` (to be removed/replaced by t_4cf1ce9b tests).
