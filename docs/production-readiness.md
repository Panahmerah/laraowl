# Production Readiness — Handoff

Status as of 2026-07-03. Covers the pre-launch security audit, prod env setup, and Laravel Cloud migration plan.

## 1. Security fixes (shipped)

### IDOR — cross-tenant access on project sub-resources (HIGH, fixed)
Routes under `{team}/{project}` checked team/project membership but never verified nested resources (alert rules, integrations, issues, thresholds, records) actually belonged to that project. Sequential IDs made cross-tenant read/write/delete trivial for any authenticated user.

**Fix:** `Controller::ensureBelongsToProject()` guard (`app/Http/Controllers/Controller.php`), applied to:
- `AlertRuleController::update/destroy`
- `IntegrationController::update/destroy/test`
- `IssueController::show/update/comment`
- `ThresholdController::destroy`
- `RecordController::showOccurrence`

Also tightened `IssueController::update` — `assigned_to` now restricted to current team members (was `exists:users,id` against all users).

Regression tests: `tests/Feature/Projects/CrossProjectAccessTest.php` (6 tests, all cross-tenant paths).

### SSRF — webhook and uptime-check URLs (MEDIUM, fixed)
Integration webhook URLs and project uptime-check URLs were validated for syntax only, letting any team member point the server at internal addresses (e.g. cloud metadata `169.254.169.254`) via "Test Connection" or the recurring uptime check.

**Fix:** `App\Rules\PublicUrl` — rejects loopback/private/link-local/reserved IPs (resolves DNS) and non-http(s) schemes. Wired into:
- `ProjectController` — `url` (store/update)
- `IntegrationController` — `data.webhook_url` / `data.url` (store/update)
- `IngestController` — auto-set `app_url` from ingest payload
- `CheckProjectUptime` — runtime backstop before every check

Tests: `tests/Feature/Rules/PublicUrlTest.php` (6 tests).

### Registration — already disabled
`ALLOW_REGISTRATION=false` in `.env` / `.env.example` / `.env.prod.example` disables the Fortify registration feature server-side (no `/register` route exists). `login.tsx` hides the link via the `canRegister` shared prop. No action needed — confirmed, not assumed.

## 2. Remaining open items (not yet fixed — flagged, awaiting go-ahead)

| Item | Risk | Where |
|---|---|---|
| No `trustProxies()` in `bootstrap/app.php` | Behind a load balancer (Cloud or self-hosted Caddy), `$request->isSecure()` / asset URLs / secure-cookie detection can misbehave without it | `bootstrap/app.php` |
| `HorizonServiceProvider` not explicitly registered in `bootstrap/providers.php` | Currently harmless — Horizon's auth gate fails closed in production (`APP_ENV=production` → dashboard denied to everyone). Relies on package auto-discovery. Only matters if someone later expects the dashboard to work | `bootstrap/providers.php` |
| No `->onOneServer()` on scheduled commands | If the app compute cluster scales past 1 replica, `projects:check-health` and `model:prune` run on every replica — duplicate uptime checks, duplicate alert sends | `routes/console.php:10-11` |
| `aws/aws-sdk-php` not in `composer.json` | Required by Laravel Cloud's managed queue. Queue jobs won't dispatch to the `cloud` connection without it | `composer.json` |
| `laravel/framework` constraint is `^13.7` | Laravel Cloud managed queues require ≥13.11.2. Constraint allows it but may not be the locked version | `composer.json` |

## 3. `.env.prod.example` (self-hosted Docker path)

Renamed from `.env.prod` (was accidentally implying it was the real prod file rather than a template). Changes made:

| Var | Before | After | Why |
|---|---|---|---|
| `APP_URL` | `http://...` | `https://...` | Caddy terminates TLS; app should know it's on https |
| `LOG_LEVEL` | `debug` | `error` | `debug` is noisy/slow in prod |
| `SESSION_ENCRYPT` | `false` | `true` | Encrypt session cookie contents |
| `MAIL_MAILER` | `log` | `smtp` (placeholder creds) | `log` silently swallows password-reset, 2FA, and issue-alert emails — was a functional trap, not just a security issue |

`FILESYSTEM_DISK=local` left as-is — docker-compose already persists `storage`/`public` via named volumes, so no S3 needed for the self-hosted path.

Still need real values before use: `DB_PASSWORD`, `REDIS_PASSWORD`, `MAIL_HOST`/`MAIL_USERNAME`/`MAIL_PASSWORD`, `REVERB_APP_ID`/`KEY`/`SECRET`, `APP_URL`/`APP_HOSTNAME`. README updated with a reminder (`README.md` Docker install section).

## 4. Laravel Cloud migration plan

Laravel Cloud replaces the self-hosted `horizon` / `reverb` / `redis` docker-compose services with managed resources. Attach these in the Cloud dashboard per environment — env vars are injected automatically, no manual copying of keys.

### Managed Queue
- Attaching it sets `QUEUE_CONNECTION=cloud` automatically.
- No `queue:work` / Horizon process needed — Cloud runs and autoscales workers.
- **Blocker:** requires `aws/aws-sdk-php` in `composer.json` (not currently present) and Laravel ≥13.11.2.
- Jobs dispatched without an explicit `->onConnection(...)` route to the managed queue once one exists in the environment — fine for this app, nothing currently pins a connection.

### WebSocket cluster (Reverb)
- Attach it → Cloud injects `REVERB_APP_ID/KEY/SECRET`, `REVERB_HOST/PORT/SCHEME`, `REVERB_VERIFY_SSL`, and `VITE_REVERB_*`.
- No `reverb:start` process, no `broadcasting.php`/`config/reverb.php` changes — works out of the box with the existing Reverb driver setup.

### Cache
- Cloud's cache resource is "Laravel Valkey" (Redis-compatible). Attach it → injects `CACHE_STORE`, `REDIS_HOST`, `REDIS_PASSWORD`.
- Same resource can back sessions too (`SESSION_DRIVER=redis`) if desired.

### Scheduler
- Toggle "Scheduler" on for the environment (App compute cluster, or a dedicated Worker cluster to isolate from web traffic).
- Cloud invokes `schedule:run` every minute automatically — replaces the `schedule-worker` docker-compose service entirely.
- Existing `Schedule::command(...)` entries in `routes/console.php` need no changes — **except** add `->onOneServer()` first if compute is scaled past 1 replica (see open items above).

### Pre-migration checklist
- [ ] `composer require aws/aws-sdk-php`
- [ ] Confirm `laravel/framework` resolves to ≥13.11.2 (`composer show laravel/framework`)
- [ ] Add `->onOneServer()` to `routes/console.php` schedule entries
- [ ] Add `trustProxies()` to `bootstrap/app.php`
- [ ] Attach Queue, WebSocket, and Cache resources in Cloud dashboard
- [ ] Enable Scheduler toggle
- [ ] Set app-specific env vars manually: `APP_KEY`, `APP_URL`, `APP_HOSTNAME`, `ALLOW_REGISTRATION=false`, `MAIL_*` (real credentials — currently the only path with no Cloud-managed equivalent)
- [ ] Verify `MEDIA_DISK`/`FILESYSTEM_DISK` — Cloud compute is ephemeral across deploys/instances, so project logo uploads (Spatie Media Library) need an S3-compatible object storage resource attached, not local disk

## 5. Test coverage added this pass

- `tests/Feature/Projects/CrossProjectAccessTest.php` — 6 tests, IDOR regression across issues/alert rules/integrations/thresholds/records
- `tests/Feature/Rules/PublicUrlTest.php` — 6 tests, SSRF rule (loopback, link-local, RFC1918, non-http schemes)
- Full suite: 113 passed, Pint clean at time of writing
