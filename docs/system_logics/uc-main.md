This file serves as a placeholder for detailed system logic descriptions.

**Derives from**: `docs/data_model.md`, `src/app/main.py`

## System Logic: Authentication (`POST /login`)

1. **Receive** `username` and `password` from form data.
2. **Retrieve** user record from `users` table where `username` matches. If not found, return "incorrect username or password".
3. **Verify** password using `hashlib.pbkdf2_hmac` against the stored `pw_hash` and `salt`. If no match, return "incorrect username or password".
4. **Generate** a new, secure random session token.
5. **Create** a new record in the `sessions` table, linking the token to the `user_id` and setting `created_at` and `expires_at` timestamps.
6. **Return** a `302 Redirect` to `/`, with a `Set-Cookie` header for `cmt_session` containing the token.

## System Logic: Data Sync (`POST /sync`)

1. **Verify** user role is `management` or higher via `require_role("management")`.
2. **Execute** `tools/sync_db.py` as a subprocess, capturing `stdout` and `stderr`.
    - The sync script will create a temporary DB, copy all data, verify, and atomically rename.
3. **After** the subprocess completes successfully, re-create the `UnifiedShipment` view in the newly synced database. This is a critical step to ensure the application's queries continue to work.
4. **Return** a JSON response summarizing the outcome (success/failure, output logs).

## System Logic: Structured Logging (Middleware)

1. A custom ASGI middleware intercepts every incoming request.
2. On request start:
    - Generate a unique `request_id`.
    - Record the start time.
3. On request end (after response is generated):
    - Calculate `duration_ms`.
    - Gather context: `method`, `path`, `status_code`, `username` (from session), `request_id`.
    - **Log** a structured JSON message to `stdout` with all collected context.
