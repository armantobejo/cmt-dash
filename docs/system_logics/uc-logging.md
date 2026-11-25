# System Logic: Access & Auth Logging

**Derives from**: `docs/srs.md` FR-006, `docs/data_model.md`

## Request Logging (ASGI Middleware)

1. Middleware intercepts every incoming request.
2. On request start: generate `request_id` (`secrets.token_hex(8)`), record start time.
3. On response: compute `duration_ms`, gather `method`, `path`, `status`, `username` (from session), `request_id`.
4. Emit one JSON log line via `logging.getLogger("access")`.

Fields: `ts`, `level`, `event=request`, `method`, `path`, `status`, `duration_ms`, `request_id`, `username`.

## Auth Events

| Event | Logger | When | Fields |
|---|---|---|---|
| `user_login` | `auth` | login attempt | `username`, `outcome` (success/fail), `ip`, `ua` |
| `user_logout` | `auth` | logout | `username` |
| `session_expired` | `auth` | expired token rejected | `username` (if resolvable) |

## Config

JSON formatter via `logging.config.dictConfig` in `src/app/logging_config.py` (`ts`, `level`, `logger`, `msg`, `event`, extra fields).