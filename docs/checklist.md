---
title: "Final Production Sign-Off Checklist"
path: docs/checklist.md
version: "1.3"
summary: "39 بنداً للتحقق قبل إطلاق SNCS في الإنتاج — Final Enterprise Hardening Pass"
tags: [checklist, production, sign-off, hardening]
---

# Final Production Sign-Off Checklist

> استخدم هذه القائمة للتأكد من اكتمال جميع بنود Enterprise Hardening قبل الإطلاق في الإنتاج.

---

## Capacity Planning

- [ ] **Capacity Planning** — تم تحديد `poll_interval` لكل بيئة (Staging/Prod-Small/Prod-Large)
- [ ] **QPS Calculation** — تم حساب QPS لكل سيناريو وتوثيقه

## Stored Procedure

- [ ] **sp_assign_call** — SP يشمل `READ COMMITTED` + `ROW_COUNT` check + Deadlock retry
- [ ] **Escalation Queue** — SP ينقل النداء لـ `escalation_queue` عند nurse pool فارغ
- [ ] **SP OUT params** — `assigned_nurse_id`, `success_flag`, `error_code` موثّقة ومُختبَرة

## Concurrency Control

- [ ] **GET_LOCK Fallback** — PHP snippet موثّق مع HTTP 423 عند فشل القفل
- [ ] **Deadlock Retry** — 3 محاولات مع 50/100/200ms backoff مُنفَّذة ومُختبَرة

## Polling

- [ ] **Incremental Polling** — Ratchet يستخدم `WHERE id > last_id LIMIT batch_size`

## WebSocket

- [ ] **WS Max Connections** — 500/2GB — 503 عند > 400 — `Retry-After` header
- [ ] **max_pending_messages** — 100 لكل اتصال — drop oldest non-critical
- [ ] **Graceful Shutdown** — SIGTERM → broadcast → 10s drain → exit(0)

## Memory & Performance

- [ ] **Memory Monitoring** — restart عند > 80% RAM — `/healthz` يعرض `memory_pct`

## Security

- [ ] **Row-Level Isolation** — `hospital_id` من SESSION فقط — `recordHandler` مُطبَّق
- [ ] **Column Hiding** — `columnHandler` يُخفي الحقول الحساسة (`password_hash`, etc.)
- [ ] **TLS/WSS** — جميع اتصالات WebSocket تعمل على WSS:// في الإنتاج

## Patient Token

- [ ] **Patient Token** — UUIDv4 + Single-Use Nonce + `last_used_at` + max 3 sessions
- [ ] **Token Cleanup Cron** — كل 5 دقائق — حذف sessions منتهية أو مُستخدَمة

## QR Security

- [ ] **QR HMAC** — `room_id + expiry + HMAC-SHA256` — مثال PHP يعمل
- [ ] **QR Expiry Policy** — 24h عادي، 1h طارئ — مُطبَّق في `generateQRToken()`

## Escalation

- [ ] **Escalation L1** — 90s → ممرضة الطابق التالية — `priority += 1`
- [ ] **Escalation L2** — 180s → مدير القسم — `priority += 2`
- [ ] **Escalation L3** — 300s → إدارة النظام — `priority += 5`
- [ ] **Escalation Audit** — `INSERT INTO audit_log` عند كل تصعيد

## Monitoring

- [ ] **Monitoring Metrics** — 6 metrics موثّقة مع alert thresholds
- [ ] **Health Endpoints** — `/healthz` و`/ws-health` يعملان وتُعيدان JSON صحيح

## Load Testing

- [ ] **Load Test S1** — 150 nurses، 200 calls/min، p95 < 500ms ✅
- [ ] **Load Test S2** — 300 nurses، 600 calls/min، p95 < 800ms ✅
- [ ] **Load Test S3** — 1000 nurses، 2000 calls/min، p95 < 2s ✅

## Audit & Retention

- [ ] **Audit Retention** — سنتان append-only + export before purge مُوثَّق

## Operational Runbooks

- [ ] **DB Overload Runbook** — موثّق في `docs/appendices/operational-capacity.md`
- [ ] **WS Overload Runbook** — موثّق في `docs/appendices/operational-capacity.md`
- [ ] **Horizontal Scale Plan** — موثّق مع sticky sessions وread replica
- [ ] **Session Revoke Procedure** — SQL + Ratchet integration موثّقة
- [ ] **Schema Rollback Plan** — DOWN migrations + backup procedure موثّقة

## Appendix Files (Code Ready)

- [ ] **schema.sql** — `docs/appendices/schema.sql.md` — نسخة كاملة
- [ ] **relations.sql** — `docs/appendices/relations.sql.md` — مع constraints وtriggers
- [ ] **procedures.sql** — `docs/appendices/procedures.sql.md` — SP كاملة قابلة للتطبيق
- [ ] **CallController.php** — `docs/appendices/call-controller.php.md` — مع GET_LOCK
- [ ] **server_hardening.txt** — `docs/appendices/ws-hardening.txt.md` — إعدادات Ratchet
- [ ] **k6 Scripts** — `docs/appendices/k6-scenarios.md` — S1/S2/S3 جاهزة
- [ ] **Operational Runbook** — `docs/appendices/operational-capacity.md` — 5 سيناريوهات

---

## Pre-Launch Final Checks

- [ ] **Demo Data** — حذف `seeds/demo_data.sql` من الإنتاج أو تغيير جميع كلمات المرور (`Sncs@2024`)
- [ ] **VAPID Keys** — مُولَّدة وخُزِّنت في `.env` أو vault آمن
- [ ] **QR_HMAC_SECRET** — مُعيَّن في `.env` الإنتاج (قيمة عشوائية طويلة)
- [ ] **CORS** — `allowedOrigins` يُشير للدومين الإنتاجي الفعلي (لا wildcard)
- [ ] **TLS Certificates** — شهادات SSL صالحة لكل من API و WebSocket

---

> **التوقيع:** ____________________  
> **التاريخ:** ____________________  
> **الإصدار المُطلَق:** v1.3 Production-Ready
