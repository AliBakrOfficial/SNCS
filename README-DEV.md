# SNCS — Developer Guide

## Quick Start

### Prerequisites

- **PHP 8.2+** with extensions: `pdo_mysql`, `mbstring`, `openssl`, `json`
- **MySQL 8.0+**
- **Node.js 18+** with npm
- **Composer 2+**
- **k6** (for load testing)

### Setup

```bash
# 1. Clone and install dependencies
git clone <repo-url> && cd sncs

# 2. Backend dependencies
composer install

# 3. Frontend dependencies
cd frontend && npm install && cd ..

# 4. Environment
cp .env.example .env
# Edit .env with your database credentials and secrets

# 5. Database
mysql -u root -p < backend/db/schema.sql
mysql -u root -p sncs_db < backend/db/relations.sql
mysql -u root -p sncs_db < backend/db/procedures.sql

# 6. Generate VAPID keys
npx web-push generate-vapid-keys

# 7. Generate APP_SECRET and QR_HMAC_SECRET
openssl rand -hex 32  # APP_SECRET
openssl rand -hex 32  # QR_HMAC_SECRET
```

### Running

```bash
# Terminal 1: PHP API Server
php -S localhost:8000 -t backend/ backend/api.php

# Terminal 2: WebSocket Server
php backend/websocket/server.php

# Terminal 3: Frontend Dev Server
cd frontend && npm run dev
```

### Architecture

```
SNCS/
├── backend/
│   ├── api.php              ← PHP-CRUD-API entry point
│   ├── config.php           ← Environment + PDO factory
│   ├── .htaccess            ← Security headers + rewriting
│   ├── controllers/         ← Domain controllers (Call, Auth, Patient, Nurse, Admin)
│   ├── middleware/           ← Auth, CSRF, RateLimiter
│   ├── services/            ← QR, Push, Audit, Escalation, Events
│   ├── helpers/             ← ResponseHelper
│   ├── websocket/           ← Ratchet WS server + MessageHandler
│   └── db/                  ← SQL schema, relations, procedures
├── frontend/
│   ├── src/
│   │   ├── pages/           ← Login, Patient, Nurse, Admin
│   │   ├── composables/     ← useWebSocket, useAuth, useCalls, usePush
│   │   ├── stores/          ← Pinia (authStore, callStore)
│   │   ├── router/          ← Vue Router with guards
│   │   ├── boot/            ← axios config
│   │   ├── i18n/            ← ar.json, en.json
│   │   └── css/             ← Global styles
│   └── quasar.config.js     ← PWA + RTL + Dark Mode
├── tests/load/k6/           ← k6 scenarios S1, S2, S3
├── runbooks/                ← Operational procedures
├── scripts/                 ← Utility scripts
└── docs/                    ← Project documentation (read-only)
```

### Key Design Decisions

| Decision      | Choice                      | Rationale                            |
| ------------- | --------------------------- | ------------------------------------ |
| Auth          | Session-based (PHPSESSID)   | Simpler, no JWT library needed       |
| Real-time     | Ratchet + event polling     | Sub-second updates, no external deps |
| Assignment    | SP + GET_LOCK fallback      | Deadlock-safe nurse assignment       |
| Escalation    | L1(90s)/L2(180s)/L3(300s)   | Configurable timeout tiers           |
| Rate Limiting | MySQL-backed sliding window | Shared across API instances          |

### Security Checklist

- [x] PDO prepared statements only (no string interpolation)
- [x] `htmlspecialchars()` on all output
- [x] CSRF token via X-CSRF-Token header
- [x] Session: `httponly`, `secure`, `SameSite=Strict`
- [x] HMAC-signed QR tokens with expiry
- [x] Single-use nonces for patient sessions
- [x] Row-level isolation via `hospital_id`
- [x] Rate limiting on auth endpoints
- [x] Security headers (HSTS, CSP, X-Frame-Options)
- [x] Password hashing: bcrypt cost 12

### Load Testing

```bash
# S1 — Staging (150 VUs, 200 calls/min)
k6 run -e BASE_URL=https://staging.sncs.io tests/load/k6/k6-scenario-S1.js

# S2 — Production (300 VUs, 600 calls/min)
k6 run -e BASE_URL=https://app.sncs.io tests/load/k6/k6-scenario-S2.js

# S3 — Stress (ramp to 1000 VUs, 2000 calls/min)
k6 run -e BASE_URL=https://app.sncs.io tests/load/k6/k6-scenario-S3.js
```
