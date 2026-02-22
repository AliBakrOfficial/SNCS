---
title: "بنية WebSocket — WebSocket Architecture"
path: docs/websocket.md
version: "1.3"
summary: "توثيق كامل لبنية WebSocket في SNCS: المصادقة، قائمة الأحداث، Backpressure، حدود الاتصالات، Graceful Shutdown، وHealth Endpoints"
tags: [websocket, ratchet, real-time, session, backpressure, monitoring, health]
---

# بنية WebSocket — WebSocket Architecture

> يعتمد SNCS على **PHP Ratchet (ReactPHP)** للاتصال ثنائي الاتجاه. المصادقة موحدة مع REST API عبر **PHP Session**.

**Related Paths:** `backend/websocket/server.php`, `backend/websocket/SessionValidator.php`, `backend/websocket/server_hardening.txt`

---

## مبرر اختيار WebSocket

| المعيار | HTTP Polling التقليدي | WebSocket (SNCS) |
|---------|---------------------|-----------------|
| زمن الاستجابة | 500ms–3000ms | < 100ms |
| حمل الشبكة | عالٍ — طلب كل بضع ثوانٍ | منخفض — بيانات عند الحاجة فقط |
| استهلاك الخادم | عالٍ — معالجة طلبات متكررة | منخفض — اتصال مفتوح واحد |
| التحديث الفوري | غير ممكن حقيقياً | حقيقي بالكامل (Real-time) |

---

## قائمة أحداث WebSocket

| اسم الحدث | الاتجاه | البيانات المُرسَلة (Payload) |
|-----------|---------|---------------------------|
| `nurse.activated` | Server→Client | `{ nurse_id, department_id, rooms: [...], activated_at }` |
| `call.initiated` | Device→Server | `{ room_id, department_id, priority, initiated_at }` |
| `call.pending` | Server→Nurse+Manager | `{ call_id, room_id, nurse_id, timeout_seconds }` |
| `call.accepted` | Nurse→Server | `{ call_id, nurse_id, accepted_at }` |
| `call.in_progress` | Server→All | `{ call_id, nurse_id, arrived_at, response_time_ms }` |
| `call.completed` | Nurse→Server | `{ call_id, nurse_id, completed_at, notes, total_duration_ms }` |
| `call.escalated` | Server→Manager+NextNurse | `{ call_id, original_nurse_id, escalation_reason, level }` |
| `nurse.excluded` | Manager→Server | `{ nurse_id, excluded_by, excluded_until, reason }` |
| `nurse.restored` | Manager/System→Server | `{ nurse_id, restored_at, restored_by }` |
| `assignment.created` | Manager→Server | `{ nurse_id, room_id, assignment_type, expires_at }` |
| `dashboard.sync` | Server→Manager | Full department snapshot |
| `patient.verified` | Server→Patient Device | `{ room_id, session_token, next_verification_at, throttle_duration_ms }` |
| `patient.session_expired` | Server→Patient Device | `{ room_id, expired_at, reason: 'timeout' \| 'manual_reset' }` |

---

## مصادقة WebSocket عبر PHP Session

**آلية التحقق عند كل طلب Upgrade:**

1. العميل يُرسِل طلب Upgrade — المتصفح يُرفِق `PHPSESSID` Cookie تلقائياً (`HttpOnly, Secure, SameSite=Lax`).
2. Ratchet يستخرج `PHPSESSID` من رأس Cookie.
3. `SessionValidator.php` يُنفِّذ: `session_id($phpsessid); session_start();`
4. يتحقق من وجود `$_SESSION['user']` مع الحقول: `id, role, hospital_id, department_id`.
5. إذا صالحة: قبول الـ Upgrade → إضافة المستخدم لـ RoomManager.
6. إذا غير صالحة: رفض الاتصال بـ **HTTP 401** قبل إتمام Handshake.

> **تأكيد صريح:** لا يُستخدم JWT في أي مكان. لا Token في HTTP Headers. المصادقة موحدة بين REST وWebSocket.

---

## إبطال الجلسات — Session Revocation

- **Endpoint:** `POST /api/auth/revoke-session` — يقبل `{ user_id }` — صلاحية: superadmin | hospital_admin.
- **WebSocket:** عند أول heartbeat بعد الإبطال، `SessionValidator` يُغلق الاتصال بكود `4401` (Custom Close Code).
- **تسجيل الخروج الذاتي:** `POST /api/logout` → `session_destroy()` → Frontend يُغلق WS يدوياً.
- **Heartbeat Interval:** كل 30 ثانية — يُعيد التحقق من صلاحية الجلسة. اكتشاف الإبطال خلال 30s كحد أقصى.

---

## Polling Strategy — Incremental Polling

```sql
-- استعلام Ratchet كل 200–500ms
SELECT * FROM events
WHERE id > :last_id
  AND dept_id = :dept_id
ORDER BY id ASC
LIMIT :batch_size;
```

| الإعداد | القيمة |
|---------|-------|
| `batch_size` افتراضي | 50 سجل |
| `poll_interval` (production) | 500ms |
| `poll_interval` (staging) | 200ms |

**الأثر على الأداء:** تقليل QPS بنسبة 60–80% مقارنة بـ `SELECT *` الكاملة.

---

## WebSocket Backpressure & Connection Limits

| الإعداد | القيمة / السلوك |
|---------|---------------|
| Max Connections | 500 لكل 2GB RAM |
| عتبة التحذير (80%) | 400 اتصال → منع اتصالات جديدة |
| HTTP Response عند الحد | `503 Service Unavailable` + `Retry-After: 30` |
| `max_pending_messages` | 100 رسالة لكل اتصال |
| Overflow Policy | Drop oldest non-critical messages |

---

## Graceful Shutdown Procedure

```
1. استقبال إشارة SIGTERM
2. إيقاف قبول اتصالات جديدة: $server->close()
3. Broadcast لجميع العملاء: {"type": "server_shutdown", "reconnect_in": 5}
4. انتظار 10 ثوانٍ لإتمام الرسائل المعلّقة
5. قطع جميع الاتصالات وإنهاء العملية: exit(0)
```

---

## Memory Strategy

| البند | القيمة |
|-------|-------|
| تقدير RAM لكل اتصال | 3–6 KB |
| 500 اتصال | ~3 MB |
| حد إعادة التشغيل | RSS > 80% من إجمالي RAM |

**خطوات Graceful Restart:**
1. رصد `memory_usage_percent` عبر `/healthz`.
2. عند تجاوز 80%: إنشاء عملية Ratchet جديدة تستمع على نفس المنفذ.
3. إرسال إشارة reconnect للعملاء عبر WebSocket.
4. إنهاء العملية القديمة بعد 30 ثانية من الاستنزاف.

---

## Health Endpoints

```
GET /healthz    → { status, db_ok, memory_pct, ts }
GET /ws-health  → { connections, pending_msgs, memory_mb, uptime_s }
```

---

## إدارة الاتصالات المنقطعة

### انقطاع اتصال الممرض
1. اكتشاف الانقطاع خلال 5 ثوانٍ (Heartbeat).
2. الممرض ينتقل لحالة 'غير متصل' في لوحة المدير.
3. إذا كان `IN PROGRESS`: انتظار 60 ثانية لإعادة الاتصال.
4. بعد 60 ثانية: تصعيد النداء وحذف الممرض من الطابور.
5. عند إعادة الاتصال: استعادة الحالة تلقائياً إذا كان الشفت نشطاً.

### انقطاع اتصال المدير
- Reconnection Buffer: 3 دقائق.
- عند إعادة الاتصال: يُرسَل `dashboard.sync` كامل.
- الأحداث المفوَّتة تُعرَض كـ Notification Queue مرتبة زمنياً.

---

## جلسة المريض عبر WebSocket

| الجانب | التفاصيل |
|--------|---------|
| نوع الاتصال | WebSocket بدون حساب — `session_token` مؤقت في `X-Patient-Token` header |
| مدة الجلسة | `presence_verification_interval` (افتراضي: 60 دقيقة) |
| تجديد الجلسة | إعادة مسح QR → session_token جديد → WS جديد |
| رفض بدون جلسة | `call.initiated` بدون token صالح يُرفض بـ 401 |

---

## Related Paths

```
backend/websocket/server.php
backend/websocket/NursingApp.php
backend/websocket/RoomManager.php
backend/websocket/SessionValidator.php
backend/websocket/server_hardening.txt
```
