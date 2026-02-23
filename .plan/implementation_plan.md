# SNCS — Full Project Build Implementation Plan

Build the complete Smart Nurse Calling System from `/docs/` specifications. The project currently has only `/docs/` (read-only) and `.git/`.

> [!IMPORTANT]
> **60+ files across 11 phases.** Phases execute sequentially with a git commit after each. `/docs/` is never modified.

---

## Proposed Changes

### Phase 1 — Project Scaffolding

#### [NEW] .gitignore
Standard ignores for PHP (`/vendor/`), Node (`/node_modules/`, `/dist/`), env files (`.env`), IDE files.

#### [NEW] .env.example
All required env vars: `DB_*`, `APP_*`, `WS_*`, `VAPID_*`, `QR_HMAC_SECRET`.

#### [NEW] composer.json
Dependencies: `mevdschee/php-crud-api`, `cboden/ratchet`, `vlucas/phpdotenv`, `endroid/qr-code`, `minishlink/web-push`. PSR-4 autoload for `App\` namespace.

#### [NEW] frontend/ (manual scaffold — no `quasar create`)
Hand-craft `package.json`, `quasar.config.js`, and full `src/` directory tree to avoid conflicts with existing repo structure.

---

### Phase 2 — Database Layer

#### [NEW] backend/db/schema.sql
Extracted verbatim from `docs/appendices/schema.sql.md` — 15 tables.

#### [NEW] backend/db/relations.sql
Extracted from `docs/appendices/relations.sql.md` — constraints, indexes, triggers.

#### [NEW] backend/db/procedures.sql
Extracted from `docs/appendices/procedures.sql.md` — `sp_assign_call_to_next_nurse` with deadlock retry.

---

### Phase 3 — Backend Core

#### [NEW] backend/config.php
Loads `.env` via phpdotenv, creates PDO connection with secure defaults.

#### [NEW] backend/api.php
php-crud-api entry point with `cors → dbAuth → authorization` middleware chain, custom route handler for controllers.

#### [NEW] backend/helpers/ResponseHelper.php
Standard JSON envelope: `{ success, data, error, code }`.

#### [NEW] backend/middleware/AuthMiddleware.php
Validates `PHPSESSID`, populates `$_SESSION['user']`.

#### [NEW] backend/middleware/CsrfMiddleware.php
Validates `X-CSRF-Token` header on mutating requests.

#### [NEW] backend/middleware/RateLimiter.php
Per-IP sliding window using MySQL-backed storage.

#### [NEW] backend/.htaccess
Security headers (CSP, X-Frame-Options, etc.), HTTPS redirect, URL rewriting.

---

### Phase 4 — Controllers

#### [NEW] backend/controllers/CallController.php
Extracted from `docs/appendices/call-controller.php.md` with `strict_types=1` and PHPDoc. **Includes `dispatch_queue` read/write logic** for round-robin nurse assignment via SP and GET_LOCK fallback.

#### [NEW] backend/controllers/AuthController.php
Login/logout/session-check using dbAuth + `session_regenerate_id(true)`.

#### [NEW] backend/controllers/PatientController.php
QR verification, session management, call initiation, throttling.

#### [NEW] backend/controllers/NurseController.php
QR scan, shift management, room assignments, exclude/restore. **Writes to `dispatch_queue`** on shift start/end.

#### [NEW] backend/controllers/AdminController.php
CRUD for hospitals, departments, rooms, staff, settings.

---

### Phase 5 — Services

#### [NEW] backend/services/QrService.php
HMAC-signed QR token generation/validation, nonce management.

#### [NEW] backend/services/PushService.php
Web Push + VAPID using `minishlink/web-push`.

#### [NEW] backend/services/AuditService.php
Append-only audit log writer.

#### [NEW] backend/services/EscalationService.php
Timeout-based escalation logic (L1: 90s, L2: 180s, L3: 300s). Reads pending calls, writes to `escalation_queue`, re-dispatches or notifies managers.

#### [NEW] backend/services/EventService.php
Writes to the `events` table (bridge between REST API and Ratchet polling). Creates broadcast-ready event records with `is_broadcast=0`.

---

### Phase 6 — WebSocket Server

#### [NEW] backend/websocket/server.php
Ratchet IoServer bootstrapping on port 8080 with connection limits, graceful shutdown.

#### [NEW] backend/websocket/MessageHandler.php
`MessageComponentInterface` implementation: auth, call lifecycle events, ping/pong, rate limiting. **Reads `events` table via incremental polling** and marks `is_broadcast=1` after broadcast.

#### [NEW] backend/websocket/server_hardening.txt
Extracted from `docs/appendices/ws-hardening.txt.md`.

---

### Phase 7 — Frontend (Quasar PWA)

**Manually scaffold** `frontend/` with `package.json`, `quasar.config.js`, and full `src/` tree (no `quasar create`).

**Pages:** `LoginPage.vue`, `PatientPage.vue`, `NurseDashboard.vue`, `AdminPanel.vue`
**Composables:** `useWebSocket.js`, `useAuth.js`, `useCalls.js`, `usePush.js`
**Stores:** `authStore.js`, `callStore.js`
**Router:** `index.js` with role-based guards
**Boot:** `axios.js` with CSRF + session cookie
**i18n:** `ar.json` (Arabic primary), `en.json`

---

### Phase 8 — Load Tests

#### [NEW] tests/load/k6/k6-scenario-S1.js
#### [NEW] tests/load/k6/k6-scenario-S2.js
#### [NEW] tests/load/k6/k6-scenario-S3.js

Extracted from `docs/appendices/k6-scenarios.md`.

---

### Phase 9 — Hardening & Security

Cross-cutting security enhancements applied to existing files:
- CSP, X-Frame-Options, HSTS in `.htaccess`
- `session_regenerate_id(true)` on login
- Cookie flags: `HttpOnly`, `Secure`, `SameSite=Strict`
- Verify all SQL uses PDO prepared statements (no raw concat)

---

### Phase 10 — Operations & Runbooks

#### [NEW] runbooks/operational-capacity.md
Extracted from `docs/appendices/operational-capacity.md`.

#### [NEW] README-DEV.md
Developer quickstart: PHP, Composer, Node, MySQL setup, WS server, k6 tests, env vars.

#### [NEW] scripts/start-ws.sh
Bash script (`#!/bin/bash`) to start Ratchet server.

#### [NEW] scripts/db-migrate.sh
Bash script (`#!/bin/bash`) to run schema + relations + procedures.

---

### Phase 11 — Final Validation

- Verify `/docs/` folder is UNCHANGED via `git diff`
- PHP syntax: `find backend/ -name "*.php" -exec php -l {} \;`
- ESLint: `eslint --ext .vue,.js frontend/src/ --max-warnings 0`
- Cross-check every item in `docs/checklist.md` against implemented code
- Verify every table in schema has corresponding model/controller coverage

---

## Verification Plan

### Automated Checks
1. **Docs immutability:** `git diff --name-only HEAD -- docs/` must return empty
2. **PHP syntax:** `find backend/ -name "*.php" -exec php -l {} \;` — zero errors
3. **JS/Vue lint:** `eslint --ext .vue,.js frontend/src/ --max-warnings 0` — zero errors
4. **Checklist:** Cross-check all 39 items in `docs/checklist.md` against code

### Manual Verification
- Review each commit message follows conventional commit format
- Spot-check all SQL uses prepared statements (no string concatenation)
- Verify `strict_types=1` in every PHP file
- Confirm `.env.example` has all required vars with no actual secrets
