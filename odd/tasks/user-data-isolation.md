# Feature: user-data-isolation

Locator: `odd/tasks/user-data-isolation.md` · Engram mirror: `odd/user-data-isolation/tasks`
Branch: `feat/user-data-isolation` (from `fix/caja-mes` @ `267997a`)
Source: `tasks/backend-tasks.md` section "Autenticación y separación por usuario" (A1–A13, APP1–APP4).

## Objective

Each user only sees and modifies the clientes, productos and ventas they created. The owner is always the
`us_ide` taken from the validated JWT, never from a request parameter.

## Problem / why

- Owner columns (`cl_emp`, `pr_emp`, `vt_emp`) are `varchar` and store a user **name** (`'Ariza'`), an id, or nothing.
- No endpoint filters by owner; any client can read and modify every record.
- `clientes.php` hardcodes `"Ariza"`, `productos.php` never writes `pr_emp`, `ventas.php` trusts `usuarioId` from the app.
- The app does not send the JWT, so enforcing auth requires app changes (APP1–APP4).

## Scope

In: local DB `sodapp_test` migration script, `api/*.php` (except `login.php` behavior), isolation tests, app token handling in `SodAppComposse`.
Out: production DB execution (user runs the script after backup), login hardening (L1–L5), T-items not required for isolation.

## Constraints

- Production untouched; everything runs against local XAMPP MySQL `sodapp_test`.
- Migration must be generic (match by `us_nom`), not tied to local ids.
- Responses for records owned by another user are 404 (do not reveal existence).
- Request-provided `usuarioId` / `vt_emp` stay accepted for compatibility but are ignored.

## Tasks

- [x] **U1 – Owner column migration (A1, A11).** SQL script under `tablasDB/migrations/`, local seed users `Ariza` and `Congre`, convert names to ids, `INT NOT NULL`, indexes. Route: inline (one SQL file, schema already read).
- [ ] **U2 – Isolation test script (A13), RED first.** PHP CLI script hitting the API through the PHP built-in server with two users. Route: delegated writer.
- [ ] **U3 – Auth helper + auth on every endpoint (A2–A4).** `current_user_id()` in `auth.php`; `require_auth()` on all methods of all endpoints except `login.php`. Route: delegated writer (6+ files).
- [ ] **U4 – Owner filtering (A5–A10, A12).** Reads, inserts, updates/deletes, sale/debt ownership checks, JOINs by `pr_emp = vt_emp`, `UNIQUE (pr_emp, pr_nom)` + 409. Route: delegated writer.
- [ ] **U5 – App token handling (APP1–APP4).** Persist token, OkHttp interceptor, 401 → login, clear on logout. Route: delegated writer, repo `SodAppComposse`.

## Acceptance criteria

- Without a valid token every endpoint except `login.php` returns 401.
- User A never sees, edits or deletes user B's clientes, productos, ventas, caja, agenda or deuda (list, by id, by name).
- New records are owned by the token user regardless of request parameters.
- Two users can each have a product with the same name.

## Checks

- Migration: before/after `GROUP BY` counts, no unmatched owners, `SHOW CREATE TABLE`.
- `php -l` on every changed PHP file.
- Isolation test script green.
- App: `gradlew assembleDebug` (U5).

## Delivery

Forecast: ~700 authored changed lines (>400). Strategy: `ask-on-risk` (default); chain strategy `stacked-to-main` (user choice 2026-10-12).
Planned slices: PR1 U1+U2 · PR2 U3 · PR3 U4 · PR4 U5 (app repo). Note: base `fix/caja-mes` is not merged into `main` yet; PR1 must target `fix/caja-mes` or wait until it merges.

## Progress

- 2026-10-12: feature document created; schema of `sap_us00/cl00/pr00/vt00` read. Local DB has only user `Test` (id 1); `'Ariza'` owns 35 clientes, 5 productos, 368 ventas; 1 cliente with empty owner; 1 venta with `'1'`.
- 2026-10-12 U1 (inline): backup of the 4 tables in session scratchpad; `000_local_seed_users.sql` adds Ariza (id 2) and Congre (id 3), password `test1234`; `001_owner_columns_to_user_id.sql` run. Evidence: after counts cl 2→36, pr 2→5, vt 1→1 / 2→368; step 4 report empty; columns `int(11) NOT NULL` without default; indexes `idx_cl_emp`, `idx_pr_emp_nom`, `idx_vt_emp_fec` present. Note: step 5 is not re-runnable (ADD INDEX). Commit pending chain-strategy answer.

## Next step

Commit U1, then U2 (RED isolation tests).
