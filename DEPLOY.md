# SC-001 Deploy: CMT Auth Weak-Credential Hardening

## What changed

- `laravel/app/Support/CmtAuth.php`: `createUser()` and `updateUser()` now
  reject weak/default credentials via `isWeakCredential()` deny list — same
  list Python `auth.py` uses:
  - admin: [admin, password, 123456, root, admin123]
  - superadmin: [superadmin, password, 123456, root, admin123]
  - root: [root, password, 123456, toor]
  - plus empty password and username==password
- `laravel/app/Console/Commands/PurgeWeakCredentials.php` — new Artisan command
  `cmt:auth:purge-weak-credentials [--delete]` for deploy-time purge of legacy
  weak DB rows. Defaults to report-only; `--delete` removes accounts.
- `laravel/bootstrap/app.php` — registered PurgeWeakCredentials in `withCommands`.
- Tests: `test_create_user_rejects_weak_credential`,
  `test_update_user_rejects_weak_password`,
  `test_purge_weak_credentials_command_deletes_weak_accounts` (29 tests, 124 assertions OK).

Commit: d33d3a9 (main). Python-side fix already merged at f279d7f.

## Deployment (zero-downtime)

1. **Pre-deploy (staging):** run `php artisan cmt:auth:purge-weak-credentials`
   (report-only) on staging to see what weak rows exist in the staging DB.
2. **Deploy merge to main:** GitHub Actions `gates.yml` runs on push to main:
   PHP unit tests + Python `run_gates.py --fast`. All gates must pass.
3. **Post-deploy purge (production):** after the Laravel container/image rolls out,
   run once: `php artisan cmt:auth:purge-weak-credentials --delete`
   on the production host. This removes legacy weak rows that survived the
   idempotent seed.
4. **Post-deploy verification:** run `php -d extension=pdo_sqlite -d extension=sqlite3
   laravel/vendor/bin/phpunit --filter CmtAuthTest` against the production
   environment (or the staging mirror) — expect 29 tests, 124 assertions OK.

## Rollback plan

If any post-deploy check fails:

1. **Revert code:** `git revert d33d3a9` (or `git checkout f279d7f -- <files>`)
   and push to main. CI redeploys the previous version automatically.
2. **DB state:** The purge command only *deletes* weak rows when `--delete` is
   passed. If you ran report-only (`cmt:auth:purge-weak-credentials` without
   `--delete`), nothing changed — no DB rollback needed.
   If you ran `--delete`, weak accounts were removed. To restore them you must
   recreate from backup or re-seed from `CMT_USERS` (which now rejects weak
   entries, so the same weak accounts cannot be re-seeded — only strong ones).
3. **Feature flag:** none — revert is immediate on next container roll.

## Post-deploy verification checklist

- [ ] CI gates pass on main (gates.yml green)
- [ ] `cmt:auth:purge-weak-credentials` report-only run shows expected weak rows
- [ ] `cmt:auth:purge-weak-credentials --delete` run succeeds, deletes only weak accounts
- [ ] `phpunit --filter CmtAuthTest` green (29 tests / 124 assertions)
- [ ] Login still works for existing strong-password accounts
- [ ] Creating a weak account via `/users/new` returns "Weak or default credential"
- [ ] Changing an account to a weak password via `/users/{id}/edit` returns "Weak or default credential"

## Commands reference

```
# Report-only (safe, run anytime)
php artisan cmt:auth:purge-weak-credentials

# Delete weak accounts (run once post-deploy on production)
php artisan cmt:auth:purge-weak-credentials --delete

# Run SC-001 test suite
cd laravel && php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit --filter CmtAuthTest

# Revert everything
git revert d33d3a9
git push origin main
```
