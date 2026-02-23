# SNCS — Smart Nurse Calling System — Build Tasks

## Phase 1 — Project Scaffolding
- [ ] Create `.gitignore` (PHP, Node, env, vendor, dist)
- [ ] Create `.env.example` with all required vars
- [ ] Create `composer.json` with dependencies
- [ ] Manually scaffold `frontend/` directory structure (no `quasar create`)
- [ ] Create initial backend directory structure
- [ ] Commit: `chore(scaffold): initialize project structure and config files`

## Phase 2 — Database Layer
- [ ] Extract `schema.sql` from [docs/appendices/schema.sql.md](file:///f:/Dev/SNCS/docs/appendices/schema.sql.md)
- [ ] Extract `relations.sql` from [docs/appendices/relations.sql.md](file:///f:/Dev/SNCS/docs/appendices/relations.sql.md)
- [ ] Extract `procedures.sql` from [docs/appendices/procedures.sql.md](file:///f:/Dev/SNCS/docs/appendices/procedures.sql.md)
- [ ] Validate timestamps, FKs, and indexes
- [ ] Commit: `feat(db): add complete schema, relations, and stored procedures`

## Phase 3 — Backend Core
- [ ] `config.php` — load .env, define DB PDO connection
- [ ] `api.php` — configure php-crud-api with dbAuth middleware
- [ ] `ResponseHelper.php` — standard JSON envelope
- [ ] `AuthMiddleware.php` — validate PHPSESSID
- [ ] `CsrfMiddleware.php` — X-CSRF-Token header check
- [ ] `RateLimiter.php` — per-IP sliding window (MySQL-backed)
- [ ] `.htaccess` — security headers + HTTPS redirect
- [ ] Commit: `feat(backend): bootstrap php-crud-api with auth and middleware`

## Phase 4 — Controllers
- [ ] Extract `CallController.php` from [docs/appendices/call-controller.php.md](file:///f:/Dev/SNCS/docs/appendices/call-controller.php.md) (includes `dispatch_queue` logic)
- [ ] `AuthController.php` — login/logout/session-check
- [ ] `PatientController.php` — patient info by nonce token
- [ ] `NurseController.php` — nurse profile, scan QR, shift → `dispatch_queue` write
- [ ] `AdminController.php` — CRUD for rooms, wards, staff
- [ ] Commit: `feat(controllers): implement all domain controllers with validation`

## Phase 5 — Services
- [ ] `QrService.php` — nonce generation + QR code + validation
- [ ] `PushService.php` — Web Push + VAPID
- [ ] `AuditService.php` — Audit log writer
- [ ] `EscalationService.php` — timeout escalation (L1/L2/L3), writes `escalation_queue`
- [ ] `EventService.php` — writes `events` table (REST→Ratchet bridge)
- [ ] Commit: `feat(services): implement QR nonce, Web Push, audit, escalation, and events`

## Phase 6 — WebSocket Server
- [ ] `server.php` — Ratchet WebSocket server
- [ ] `MessageHandler.php` — handle all message types + poll `events` table
- [ ] Extract `server_hardening.txt` from [docs/appendices/ws-hardening.txt.md](file:///f:/Dev/SNCS/docs/appendices/ws-hardening.txt.md)
- [ ] Commit: `feat(ws): implement hardened Ratchet WebSocket server`

## Phase 7 — Frontend (Quasar PWA)
- [ ] Manually scaffold `frontend/` (package.json, quasar.config.js, src/ tree)
- [ ] Configure `quasar.config.js` for PWA + RTL + Axios
- [ ] `LoginPage.vue` — session-based login
- [ ] `PatientPage.vue` — QR scan → call nurse
- [ ] `NurseDashboard.vue` — live call queue
- [ ] `AdminPanel.vue` — system management
- [ ] `useWebSocket.js` composable
- [ ] `useAuth.js`, `useCalls.js`, `usePush.js` composables
- [ ] Auth/call stores (Pinia)
- [ ] Router with guards
- [ ] `axios.js` boot file
- [ ] i18n: `ar.json` + `en.json`
- [ ] Commit: `feat(frontend): implement all Quasar PWA pages and composables`

## Phase 8 — Load Tests
- [ ] Extract k6 scenarios S1, S2, S3 from [docs/appendices/k6-scenarios.md](file:///f:/Dev/SNCS/docs/appendices/k6-scenarios.md)
- [ ] Commit: `feat(tests): add k6 load test scenarios S1, S2, S3`

## Phase 9 — Hardening & Security
- [ ] Security headers in `.htaccess`
- [ ] Rate limiting on auth endpoints
- [ ] Verify PDO prepared statements everywhere
- [ ] Session hardening
- [ ] Cookie flags
- [ ] QR nonce single-use verification
- [ ] Commit: `feat(security): apply full hardening checklist from security.md`

## Phase 10 — Operations & Runbooks
- [ ] Extract [operational-capacity.md](file:///f:/Dev/SNCS/docs/appendices/operational-capacity.md) to `runbooks/`
- [ ] Create `README-DEV.md`
- [ ] Create bash scripts (`scripts/start-ws.sh`, `scripts/db-migrate.sh`)
- [ ] Commit: `chore(ops): add runbooks, dev README, and utility scripts`

## Phase 11 — Final Validation
- [ ] Verify `/docs/` folder is UNCHANGED (`git diff`)
- [ ] PHP syntax check: `find backend/ -name "*.php" -exec php -l {} \;`
- [ ] ESLint check: `eslint --ext .vue,.js frontend/src/ --max-warnings 0`
- [ ] Cross-check all 39 items in `docs/checklist.md` against code
- [ ] Verify schema coverage (every table has controller/service)
- [ ] Commit: `chore(final): project complete — all phases implemented and verified`
