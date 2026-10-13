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
- [x] **U2 – Isolation test script (A13), RED first.** PHP CLI script hitting the API through the PHP built-in server with two users. Route: delegated writer.
- [x] **U3 – Auth helper + auth on every endpoint (A2–A4).** `current_user_id()` in `auth.php`; `require_auth()` on all methods of all endpoints except `login.php`. Route: delegated writer (6+ files).
- [x] **U4 – Owner filtering (A5–A10, A12).** Reads, inserts, updates/deletes, sale/debt ownership checks, JOINs by `pr_emp = vt_emp`, `UNIQUE (pr_emp, pr_nom)` + 409. Route: delegated writer.
- [x] **U5 – App token handling (APP1–APP4).** Persist token, OkHttp interceptor, 401 → login, clear on logout. Route: delegated writer, repo `SodAppComposse`.

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
- 2026-10-12 U2 (delegated writer): `tests/isolation_test.php` (built-in server on 127.0.0.1:8765, uses itself as router to putenv the DB/JWT vars because Windows drops empty `DB_PASS=` from a child env). `api/conexion.php` now treats a defined-but-empty env var as valid. RED observed: `php tests/isolation_test.php` → 25 passed, 80 failed, exit 1 (every no-token/invalid-token case returns 200/201/404 instead of 401; Congre sees 36 clientes, 5 productos, 15 ventas, caja, dias and 22 deudores of Ariza; new records get cl_emp=0 / vt_emp=2 / product insert blocked by global name check; cross-user GET/PUT/DELETE/deuda/venta succeed; duplicates return 400 not 409). DB checksums identical before/after the run. Commit `d2f8102`. Note: local `sap_pr00` has no `deleted_at` column used by `productos.php`; the test adds it for the run and drops it at the end (production schema to be confirmed).
- 2026-10-12 U3 (delegated writer): `current_user_id()` in `api/auth.php`; every endpoint except `login.php` calls it right after `allow_cors()`, so all methods (and unsupported ones) answer 401 without a valid token; redundant per-method `require_auth()` calls removed. Evidence: `php -l` clean on the 7 files; `php tests/isolation_test.php` → 61 passed, 44 failed (all 42 auth cases PASS; remaining failures are the owner-filtering cases for U4). Commit `83af55a`.
- 2026-10-12 U4 (delegated writer): every SELECT filters by the token owner (clientes list/id, productos list/name, ventas GET both shapes, cajaMes, dias list/id, deudaCliente list/id); INSERTs write the token owner (hardcoded "Ariza" removed, productos POST sets `pr_emp`, ventas POST ignores `usuarioId`); UPDATE/DELETE/soft-delete add the owner condition, with a SELECT ownership check before clientes/productos PUT and deudaCliente PUT to avoid false 404 on unchanged values; ventas POST requires cliente and every product to belong to the owner (404); ventas/cajaMes JOINs by name add `pr.pr_emp = vt.vt_emp`; `dias.php` PUT (still unreachable, T5/T6) got the owner condition. `tablasDB/migrations/002_unique_product_name_per_user.sql` run on sodapp_test: duplicate report empty, `idx_pr_emp_nom` replaced by `UNIQUE uq_pr_emp_nom (pr_emp, pr_nom)`; productos POST/PUT answer 409 on duplicate (pre-check + errno 1062). `lib.php` sets `mysqli_report(MYSQLI_REPORT_OFF)` so errno checks also work on PHP 8.1+. List endpoints that used `mysqli_query` keep it (owner id interpolated via `intval`) so their JSON stays string-typed as the app receives it today. Evidence: `php -l` clean on all `api/*.php` and the test; `php tests/isolation_test.php` → 105 passed, 0 failed, exit 0 (twice, re-runnable); table checksums identical before/after. Commit: `feat(api): scope clientes, productos and ventas to the token user`.
- 2026-10-12 parent: U4 commit `2ca7e5d`; parent re-ran `php tests/isolation_test.php` → 105 passed, 0 failed. Review assess: high (auth hot path) → native 4-lens review granted by user; base widened by provider to `4fba524` (includes `26fa7b9`, `267997a`); all lenses admitted, result approved, acknowledgement burned (lineage `review-656a830ad5c8d665`). Reviewed boundary is now `2ca7e5d`.
- 2026-10-12 production: user backed up the 4 tables (`tablasDB/sap_*.sql`, untracked, contain password hashes), then ran 001 (after collation fix `c3761da`) and 002. User-confirmed: step 4 report empty, final counts only owners 1 (Ariza) and 2 (Congre). Correction (user, same day): the bridge hotfix was NOT applied; the old production API keeps running and new clientes/productos are saved with owner `0` (non-strict MySQL converts `"Ariza"` / missing value to 0). User accepts this for now. At deploy time run `UPDATE ... SET <owner> = 1 WHERE <owner> = 0` on sap_cl00 / sap_pr00 or those rows stay invisible. The new API from this branch is NOT deployed in `api/`; user is uploading it to `api_2/` (debug builds of the app point to `api_2/`, `app/build.gradle.kts:27`).
- 2026-10-12 U5 (delegated writer, repo `SodAppComposse`, branch `feat/auth-token` from `main` @ `8a5ded3`): `60f45bd` commits the user's pending api_2/debug config; `fa3c05f` adds `auth/AuthInterceptor` + `auth/AuthSession` (Bearer token on every request except login.php, 401 → single session-expired signal → Login with back stack cleared), token parsed from `"token"` and persisted in DataStore, logout/login clear repository caches. Evidence: RED = new tests did not compile; GREEN = `gradlew testDebugUnitTest assembleDebug` 42 tests, 0 failures; parent re-ran `testDebugUnitTest installDebug` → BUILD SUCCESSFUL, installed on moto e32. Device check of 401 → login and logout pending (user). Review assess: high (auth hot path); user granted native 4-lens review (provider widened base to `3cd9270`, 30 files); all lenses admitted, approved, acknowledgement burned (lineage `review-3e7d1629e0556a65`).
- 2026-10-12 device test on `api_2` (user): every request returned 401 "Missing Authorization token" because the hosting's Apache runs PHP as CGI/FPM and drops `Authorization`. Fix `2f3cca6` (`api/.htaccess` forwards the header; `jwt.php` reads `REDIRECT_HTTP_AUTHORIZATION` and case-insensitive `apache_request_headers()`); confirmed with one authorized test request ("Invalid or expired token" for a fake token) and by the user logging in. App `c5a97dd`: login success used the exception template; RED/GREEN in `LoginViewModelTest`, 43 tests pass. User confirmed separation, creates, edits, deletes and session on device. Paying a debt failed with 400 (T3): fix `3710d6f` reads query params and answers 409 when the amount exceeds the debt (T4); isolation test 111 passed; user confirmed on device.
- 2026-10-12 app `04a8066`: debug builds point to `api/` again (user request); 43 tests pass, installed on device. Native reviews requested by the user (assess said medium/under budget): backend lineage `review-7a98db9a32609a48` (provider base `4fba524`, 41 files) and app lineage `review-3b8179028b078c0b` (base `3cd9270`, 31 files) — both approved, acknowledgements burned. Branches pushed: `feat/user-data-isolation`, `fix/caja-mes`, `feat/auth-token`.

## Next step

User tests on device against `api_2` with Ariza and Congre. Previous note: U5 (app token handling, repo `SodAppComposse`). Before production: run 001 and 002 after a backup and confirm `sap_pr00.deleted_at` exists there (missing locally).
