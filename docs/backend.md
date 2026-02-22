---
title: "الواجهة الخلفية — Backend Implementation"
path: docs/backend.md
version: "1.3"
summary: "توثيق كامل للـ Backend: Middlewares، Authorization Handlers، Custom Controllers، Ratchet WebSocket، Stored Procedures، وConcurrency Control"
tags: [backend, php, crud-api, ratchet, controllers, middleware, authorization, stored-procedure]
---

# الواجهة الخلفية — Backend Implementation

> يعتمد الـ Backend على **php-crud-api** كنواة REST API، مع طبقة **Custom Controllers** لمنطق الأعمال، و**PHP Ratchet** كمحرك WebSocket.

**Related Paths:** `backend/api.php`, `backend/controllers/`, `backend/websocket/`, `backend/db/procedures.sql`

---

## تكوين الـ Middlewares — api.php

الترتيب إلزامي: **cors → dbAuth → authorization**

| الترتيب | Middleware | الدور والإعداد |
|---------|-----------|---------------|
| 1 | **cors** | يُتيح طلبات Cross-Origin. `allowedOrigins = 'https://app.sncs.io'`. `allowMethods = 'GET,POST,PUT,DELETE,OPTIONS'` |
| 2 | **dbAuth** | المصادقة عبر جدول users. `usersTable = 'users'`, `usernameColumn = 'username'`, `returnedColumns = 'id,username,role,full_name,hospital_id,department_id'` |
| 3 | **authorization** | يُطبِّق قواعد الصلاحيات. يستدعي `columnHandler` لفلترة الحقول و`recordHandler` لفلترة السجلات |

---

## Authorization Handlers — قواعد صلاحيات الجداول

الدور يُستخرج من: `$role = $_SESSION['user']['role']`

### جدول hospitals

| الدور | العملية | القاعدة |
|-------|---------|---------|
| superadmin | CRUD كامل | جميع الحقول والسجلات |
| hospital_admin | READ فقط | يُخفي `settings_json`، `WHERE id = session.hospital_id` |
| dept_manager / nurse | مرفوض | `recordHandler` يُعيد `false` — HTTP 403 |

### جدول calls

| الدور | العملية | القاعدة |
|-------|---------|---------|
| superadmin | READ فقط | جميع السجلات لأغراض التقارير |
| hospital_admin | READ فقط | `WHERE hospital_id = session.hospital_id` |
| dept_manager | READ + UPDATE status | `WHERE department_id = session.department_id` |
| nurse | READ + UPDATE (مقيَّد) | `WHERE assigned_nurse_id = session.id`، `status` و`notes` فقط |
| CREATE نداء جديد | Custom Controller فقط | لا يُسمح بـ POST مباشر على `/calls` |

### جدول users

| الدور | العملية | القاعدة |
|-------|---------|---------|
| superadmin | CRUD كامل | يُخفي `password` دائماً |
| hospital_admin | READ + CREATE + UPDATE | `WHERE hospital_id = session.hospital_id` |
| dept_manager | READ (قسمه فقط) | `WHERE department_id = session.department_id AND role = 'nurse'` |
| nurse | READ (نفسه فقط) | `WHERE id = session.id`، `full_name` فقط للتعديل |

---

## Custom Controllers — خريطة شاملة

النمط: `POST /api/{controller}/{action}`

### CallController.php

| Endpoint | الصلاحية | المنطق |
|---------|---------|--------|
| `POST /api/call/initiate` | patient_sess | تحقق من الغرفة + الجلسة + Throttle → إنشاء call → DispatchEngine → `call.pending` |
| `POST /api/call/accept` | nurse | تحقق pending + ممرض مُستهدَف → `status=accepted` → `call.accepted` |
| `POST /api/call/arrive` | nurse | `status=in_progress`, `arrived_at`, `response_time_ms` → `call.in_progress` |
| `POST /api/call/complete` | nurse | `status=completed`, `notes` → `call.completed` → تحديث dispatch_queue |
| `POST /api/call/escalate` | system/cron | تجاوز `escalation_timeout` → escalation_log → إعادة توجيه → `call.escalated` |
| `GET /api/call/active/{dept_id}` | dept_manager | النداءات النشطة + JOIN للغرف والممرضين |

راجع: [appendices/call-controller.php.md](./appendices/call-controller.php.md)

### NurseController.php

| Endpoint | الصلاحية | المنطق |
|---------|---------|--------|
| `POST /api/nurse/scan-qr` | nurse | تحقق QR → `nurse_shifts` → `dispatch_queue` → `nurse.activated` |
| `POST /api/nurse/shift-end` | nurse | إغلاق الشفت → حذف من dispatch_queue → `nurse.deactivated` |
| `POST /api/nurse/assign-room` | dept_manager | `nurse_room_assignments` → `assignment.created` |
| `POST /api/nurse/exclude` | dept_manager | `is_excluded=1` → `nurse.excluded` |
| `POST /api/nurse/restore` | dept_manager | `is_excluded=0` → `nurse.restored` |
| `GET /api/nurse/department-status/{dept_id}` | dept_manager | حالة الممرضين + عدد نداءاتهم |

### PatientController.php

| Endpoint | الصلاحية | المنطق |
|---------|---------|--------|
| `POST /api/patient/verify-qr` | public | QR تحقق → `session_token` UUID → `patient_sessions` → config |
| `POST /api/patient/call` | patient | تحقق session_token + Throttle → إنشاء النداء → `last_call_at` |
| `GET /api/patient/session-status` | patient | حالة الجلسة + الوقت المتبقي + throttle |
| `POST /api/patient/session-refresh` | patient | إبطال القديمة → إنشاء جديدة |

### DashboardController.php

| Endpoint | الصلاحية | البيانات |
|---------|---------|---------|
| `GET /api/dashboard/system` | superadmin | كل المستشفيات + متوسط الاستجابة + Nurses Online |
| `GET /api/dashboard/hospital/{id}` | superadmin | نطاق مستشفى + تفصيل الأقسام |
| `GET /api/dashboard/department/{id}` | dept_manager | النداءات + الممرضون + الطابور + آخر 10 أحداث |
| `GET /api/dashboard/snapshot/{dept_id}` | dept_manager | Snapshot كامل عند reconnect |

### SettingsController.php

| Endpoint | الصلاحية | الوصف |
|---------|---------|-------|
| `GET /api/settings/global` | superadmin | جميع system_settings |
| `PUT /api/settings/global` | superadmin | تحديث `throttle_duration_ms`, `escalation_timeout_sec`... |
| `PUT /api/settings/hospital/{id}` | superadmin | إعدادات خاصة بمستشفى |
| `GET /api/settings/public` | عام | بدون مصادقة: throttle_duration_ms, verification_interval |

---

## WebSocket Server — PHP Ratchet

| المكوّن | المسؤولية |
|---------|----------|
| `server.php` | `IoServer` → `HttpServer` → `WsServer` → `NursingApp`. Port 8080. `nohup php server.php > /var/log/sncs-ws.log 2>&1 &` |
| `SessionValidator.php` | استخراج `session_id` من query string → `session_start()` → تحديد الدور → إضافة للـ RoomManager |
| `RoomManager.php` | خريطة: `dept_id → SplObjectStorage`. يُوفِّر `broadcastToDept()`, `broadcastToUser()`, `broadcastToAll()` |
| `NursingApp::onMessage()` | JSON message → تحديد النوع → Handler المناسب → كتابة MySQL → Broadcast |
| `NursingApp::onClose()` | إزالة من RoomManager → grace period 60s للممرض → تصعيد النداءات المفتوحة |
| **MySQL Polling Bridge** | كل 200ms: `SELECT WHERE is_broadcast=0` → Broadcast → `is_broadcast=1` (يحل محل Redis Pub/Sub) |

---

## التعيين الذري — Atomic Call Assignment & Concurrency Control

### مشكلة Race Condition

عند وصول نداءين بفارق 10ms، قد يُعيَّن نفس الممرض للنداءين معاً. الحل الإلزامي: **جميع عمليات التوزيع تمر عبر** `sp_assign_call_to_next_nurse` **حصراً**.

> قاعدة إلزامية: لا يُنفَّذ أي Assign خارج Transaction. لا Assign عبر CRUD مباشر.

### Stored Procedure — sp_assign_call_to_next_nurse

النسخة الكاملة المحسّنة (v1.3) تشمل:
- `SET TRANSACTION ISOLATION LEVEL READ COMMITTED`
- `ROW_COUNT()` check بعد `SELECT FOR UPDATE`
- Deadlock retry: 3 محاولات مع backoff 50ms / 100ms / 200ms
- Nurse pool empty: نقل لـ `escalation_queue` بـ `reason='no_available_nurse'`
- OUT parameters: `p_assigned_nurse`, `p_success`, `p_error_code`

راجع النص الكامل: [appendices/procedures.sql.md](./appendices/procedures.sql.md)

### GET_LOCK Fallback (PHP)

يُستخدم بديلاً عن SP في حالات:
- عمليات إدارية لا تستدعي SP كاملة
- تزامن خفيف (< 50 concurrent requests per dept)
- عند الحاجة لـ HTTP 423 Locked صريح للعميل

```php
// استدعاء القفل
SELECT GET_LOCK('dispatch_dept_{dept_id}', 3)
// ... transaction + SELECT FOR UPDATE ...
SELECT RELEASE_LOCK('dispatch_dept_{dept_id}')
// عند فشل القفل: HTTP 423 + Retry-After: 5
```

راجع الكود الكامل: [appendices/call-controller.php.md](./appendices/call-controller.php.md)

---

## Push Notifications — PWA Web Push

- **المكتبة:** `web-push/web-push` (PHP)
- **VAPID Keys:** يُولَّدان مرة واحدة ويُخزَّنان في `config.php`
- **متى يُرسَل الإشعار؟** عند `call.pending`: Push Notification للممرض المُستهدَف — رقم الغرفة + الأولوية
- **المفتاح العام:** يُرسَل للـ Frontend عبر `/api/settings/public`

---

## Related Paths

```
backend/api.php
backend/controllers/CallController.php
backend/controllers/NurseController.php
backend/controllers/PatientController.php
backend/controllers/DashboardController.php
backend/controllers/SettingsController.php
backend/websocket/server.php
backend/websocket/NursingApp.php
backend/websocket/RoomManager.php
backend/websocket/SessionValidator.php
backend/db/procedures.sql
```
