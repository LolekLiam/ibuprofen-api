# API documentation

See the [quick start](../README.md) for installation. Commands below run from the API directory.

A self-hosted cache for public eAsistent timetables, with a separate administrator account and an Android admin panel. Personal eAsistent passwords and mobile account tokens stay between the Android app and eAsistent; this server does not proxy them.

## Quick start

Use PHP 8.1+ with PDO SQLite, DOM, mbstring and Composer. From this directory:

```sh
composer install --no-dev
php bin/admin.php create owner
```

The command prompts twice for a password without echoing it. Choose 12–72 bytes. Running it again changes the password and revokes the user's sessions. No default account or password exists. For automation, the command also accepts `IBUPROFEN_ADMIN_PASSWORD` from the environment; keep it out of command history and deployment files.

Start the local API and its background worker in separate terminals:

```sh
IBUPROFEN_ALLOW_HTTP=1 php -S 0.0.0.0:8080 -t public public/index.php
php bin/worker.php
```

On the Android emulator, configure `http://10.0.2.2:8080/` in Settings → Advanced settings → Proxy connection, enable the proxy and save it. Server administration appears only while the proxy is enabled. In Settings → Server administration, sign in with the admin account. The app verifies `/v1/admin/me` and shows admin controls automatically. Admin sign-in is separate from eAsistent sign-in; there is no tap sequence or client-side password granting privileges.

Register a school using its public timetable key, then queue a current-week or school-year synchronization. Progress updates automatically. If a job stays queued, start the worker. All admins manage the same server; `php bin/admin.php disable owner` disables an admin and immediately revokes their sessions.

## Production hosting

Serve **only `public/`**, behind HTTPS. Keep the database, source, Composer files, and `vendor/` outside the public document root. PHP-FPM behind an HTTPS web server should receive the HTTPS scheme from that trusted server, for example `fastcgi_param HTTPS on` in the TLS virtual host. Incoming `X-Forwarded-Proto` and `X-Forwarded-For` headers are not trusted by the API.

For a simple container installation:

```sh
docker compose build
docker compose up -d
docker compose exec api php bin/admin.php create owner
```

The provided Compose file binds HTTP to **0.0.0.0:8080** and sets `IBUPROFEN_ALLOW_HTTP=1`, allowing LAN access. For an HTTPS reverse proxy on the same host, start Compose with `IBUPROFEN_BIND_ADDRESS=127.0.0.1 docker compose up -d`. For example, Caddy can terminate HTTPS with:

```caddyfile
api.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

Configure `https://api.example.com/` in the app. The Android app accepts HTTP and HTTPS server URLs, binds saved credentials to the exact server URL, and does not follow redirects on admin requests. The PHP built-in server is suitable for a small private instance; use PHP-FPM for production concurrency/load. Admin endpoints otherwise reject HTTP unless `IBUPROFEN_ALLOW_HTTP=1` is deliberately set for LAN hosting, an isolated backend or local development.

Back up the persistent data directory using SQLite's backup facility or stop both API and worker before copying it. Do not copy just `api.sqlite` while writers are running, because WAL files may contain committed changes.

## Routes

All successful responses use `{"data": ..., "meta": {}}`. Errors use `{"error":{"code":"...","message":"..."}}` with appropriate HTTP status codes. Send JSON bodies with `Content-Type: application/json`.

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/v1/health` | Health and API version |
| GET | `/v1/schools/{key}` | School metadata, classes and week IDs |
| GET | `/v1/schools/{key}/classes` | Classes |
| GET | `/v1/schools/{key}/teachers` | Teacher labels from cached schedules |
| GET | `/v1/schools/{key}/rooms` | Room labels from cached schedules |
| GET | `/v1/schools/{key}/timetable?class_id=123&week=7` | One class; fetches upstream when missing/expired |
| GET | `/v1/schools/{key}/timetable?teacher=J.%20Plavec&week=7` | Cached teacher schedule with source classes |
| GET | `/v1/schools/{key}/timetable?room=D3.1&week=7` | Cached room schedule with source classes |
| GET | `/v1/schools/{key}/timetables?week=7` | All available cached class schedules in one request |
| POST | `/v1/admin/sessions` | Admin login: `username`, `password` |
| GET | `/v1/admin/me` | Verify admin identity and role |
| DELETE | `/v1/admin/sessions/current` | Revoke the current admin session |
| GET | `/v1/admin/stats` | Cache/job counts |
| GET | `/v1/admin/schools` | Registered schools |
| POST | `/v1/admin/schools` | Register/update school metadata: `key` |
| POST | `/v1/admin/schools/{key}/sync-jobs` | Queue `{"scope":"week","week":7}` or `{"scope":"year"}` |
| GET | `/v1/admin/jobs` | Latest 50 jobs |
| GET | `/v1/admin/jobs/{id}` | Job status and progress |

All admin routes except login require `Authorization: Bearer <admin-token>`. Sessions default to eight hours. Passwords use PHP's password-hashing API; only SHA-256 hashes of cryptographically random session tokens are persisted. Login is limited per peer IP and username, public requests are rate limited, and request bodies are capped at 16 KiB. Password changes/disable revoke all sessions. Authentication and access are enforced on every protected request; UI visibility is only a convenience.

Choose exactly one timetable filter. Omitted `week` or `week=0` means the current school week. Unknown classes, invalid weeks and unsupported query parameters are rejected. Teacher/room/bulk responses report `meta.complete`, `meta.missing_class_ids`, and `meta.classes_total`; missing or expired class data is not presented as complete. They do not trigger an anonymous full-school/full-year refresh. The Android app uses bulk cached schedules first and fetches only missing classes, falling back to the public upstream when a proxy call fails.

Jobs return HTTP 202 immediately. Identical active requests are deduplicated. One worker fetches timetables sequentially with bounded timeouts, keeps HTTP requests responsive, and uses short atomic database transactions. Its per-class checkpoints survive a crash; restarting the worker resumes pending tasks. Failed classes yield `partial`, preserving successful cache entries; queuing the job again retries the schedule. Run `php bin/worker.php --once` to process one queued/interrupted job. For periodic refresh:

```cron
0 */3 * * * cd /path/to/ibuprofen-api && php bin/sync-cron.php
```

This queues current-week jobs for registered schools; the continuously running worker processes them. It does not repeatedly fetch every future week.

## Configuration

Environment variables are read by both API and CLI; use the same values for every process. Run CLI commands as the same operating-system user as the API/worker so the private data directory is accessible (for PHP-FPM, for example `sudo -u www-data php bin/admin.php create owner`).

| Variable | Default | Meaning |
| --- | --- | --- |
| `IBUPROFEN_DATA_DIR` | `var/` in this project | Private SQLite and lock-file directory |
| `IBUPROFEN_CACHE_TTL` | `3600` | Timetable freshness in seconds; metadata caches for 10 minutes |
| `IBUPROFEN_ADMIN_SESSION_TTL` | `28800` | Admin session lifetime; clamped to 300–86400 seconds |
| `IBUPROFEN_ALLOW_HTTP` | off | Explicit admin HTTP allowance (including LAN hosting) |

## Migration

Public school metadata uses the string `schoolKey` as its identifier. The obsolete numeric `schoolId` field is no longer part of the model; school pages do not need an `id_sola` script. Existing cache/database files can be kept.

This replaces the old unversioned routes. In particular, unauthenticated GET synchronization, `force=true`, the old teacher-ID/date route, and eAsistent credential passthrough routes are removed. Upgrade the Android app together with the server. Existing `database.sqlite` is left untouched; v1 starts a separate `var/api.sqlite` and repopulates its cache when schools are registered/synced. There is no automatic import of old cache data or passwords.

## Validation and implementation

```sh
php tests/run.php
composer audit
```

Tests use original synthetic MIT-licensed timetable fixtures in `tests/fixtures/` and an injected upstream client; they never need a real eAsistent login. Cache hits retain the complete parsed JSON, SQLite WAL and indexed lesson lookups support readers during short writes, and per-resource locks prevent concurrent refreshes from fetching the same timetable. Failed refreshes leave the prior cache intact. Table parsing uses direct rows/cells to avoid accidentally treating lesson sub-tables as periods.

References: [PHP password hashing](https://www.php.net/manual/en/function.password-hash.php), [SQLite WAL](https://www.sqlite.org/wal.html).
