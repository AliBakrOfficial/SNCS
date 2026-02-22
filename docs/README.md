---
title: "SNCS — Smart Nurse Calling System | فهرس التوثيق"
path: docs/README.md
version: "1.3"
summary: "الفهرس الرئيسي لجميع ملفات توثيق نظام نداء التمريض الذكي — SNCS SOP v1.3 Final Hardened"
tags: [index, readme, toc, sncs, sop]
---

# SNCS — Smart Nurse Calling System | فهرس التوثيق

> **الإصدار:** v1.3 — Final Enterprise Hardening Pass  
> **التاريخ:** 2025-02-22  
> **الحالة:** معتمدة | Production-Ready  
> **رقم الوثيقة:** SNCS-SOP-2024-001

---

## Design Decisions & Rationale

| القرار | الاختيار | البديل | السبب |
|--------|----------|--------|-------|
| WebSocket Engine | PHP Ratchet (ReactPHP) | Node.js Socket.io | التزام بالمكدس PHP الكامل وتبسيط النشر |
| Auth Strategy | PHP Session (dbAuth) + Cookie | JWT Bearer Token | الجلسة الموحدة بين REST وWebSocket، لا تسرب Token في Headers |
| State Broker | MySQL Polling (200–500ms) | Redis Pub/Sub | لا وسيط خارجي، MySQL يكفي لـ < 500 مستخدم متزامن |
| Patient Token | Single-Use Nonce | Rotating Token | أعلى أماناً في البيئة الصحية — لا إمكانية إعادة الاستخدام |
| Deployment | Apache/Nginx Direct | Docker | تبسيط عمليات IT في المستشفيات، لا بنية تحتية معقدة |

---

## جدول المحتويات (TOC)

### الملفات الرئيسية

| # | الملف | الوصف | الأقسام المصدر |
|---|-------|-------|----------------|
| 1 | [README.md](./README.md) | هذا الملف — الفهرس الشامل | — |
| 2 | [architecture.md](./architecture.md) | الخريطة المعمارية + Data Flow | Section 17 |
| 3 | [database.md](./database.md) | بنية قاعدة البيانات + DDL | Section 18 |
| 4 | [backend.md](./backend.md) | الواجهة الخلفية + Controllers + SP | Section 19 |
| 5 | [websocket.md](./websocket.md) | WebSocket Architecture + Security | Section 11 |
| 6 | [frontend.md](./frontend.md) | Quasar PWA + Pages + Composables | Section 20 |
| 7 | [security.md](./security.md) | الأمان + Token Hardening + QR | Sections 15, 16 + Hardening 7–9 |
| 8 | [operations.md](./operations.md) | Runbooks + Monitoring + Capacity | Hardening 1, 5, 6, 12–15 |
| 9 | [load-testing.md](./load-testing.md) | k6 Scenarios S1/S2/S3 | Hardening 13 |
| 10 | [change-log.md](./change-log.md) | سجل التغييرات — Final Hardening Pass | — |
| 11 | [questions-for-owner.md](./questions-for-owner.md) | أسئلة للمالك تحتاج قرارات | — |
| 12 | [checklist.md](./checklist.md) | Final Production Sign-Off Checklist | Hardening 17 |

### Appendices (ملفات الكود الكاملة)

| # | الملف | المسار الفعلي في الريبو |
|---|-------|------------------------|
| A | [appendices/schema.sql.md](./appendices/schema.sql.md) | `backend/db/schema.sql` |
| B | [appendices/relations.sql.md](./appendices/relations.sql.md) | `backend/db/relations.sql` |
| C | [appendices/procedures.sql.md](./appendices/procedures.sql.md) | `backend/db/procedures.sql` |
| D | [appendices/call-controller.php.md](./appendices/call-controller.php.md) | `backend/controllers/CallController.php` |
| E | [appendices/ws-hardening.txt.md](./appendices/ws-hardening.txt.md) | `backend/websocket/server_hardening.txt` |
| F | [appendices/k6-scenarios.md](./appendices/k6-scenarios.md) | `tests/load/k6/` |
| G | [appendices/operational-capacity.md](./appendices/operational-capacity.md) | `runbooks/operational-capacity.md` |

---

## المكدس التقني (Quick Reference)

```
Frontend:  Quasar Framework v2 (Vue 3 + Composition API) — SPA/PWA — RTL
Backend:   php-crud-api (PHP 8+) + Custom Controllers
Real-Time: PHP Ratchet (ReactPHP) — WebSocket Server (port 8080)
Database:  MySQL 8+ — Single source of truth
Auth:      dbAuth (Session-based) — PHPSESSID Cookie — لا JWT
Hosting:   Apache / Nginx — No Docker
Push:      Web Push Protocol + VAPID
```

---

## Related Paths

```
backend/api.php
backend/config.php
backend/db/schema.sql
backend/db/relations.sql
backend/db/procedures.sql
backend/controllers/CallController.php
backend/websocket/server.php
tests/load/k6/k6-scenario-S1.js
runbooks/operational-capacity.md
```
