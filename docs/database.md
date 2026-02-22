---
title: "بنية قاعدة البيانات — Database Schema"
path: docs/database.md
version: "1.3"
summary: "توثيق كامل لـ 12 جدول MySQL: DDL overview، ملاحظات التصميم، الفهارس، والعلاقات"
tags: [database, mysql, schema, ddl, indexes, relations]
---

# بنية قاعدة البيانات — Database Schema

> قاعدة البيانات تعتمد على **MySQL 8+** وتحتوي على 12 جدولاً رئيسياً مُصمَّمة لتغطية كل متطلبات SNCS.

**Related Paths:** `backend/db/schema.sql`, `backend/db/relations.sql`, `backend/db/procedures.sql`

---

## قائمة الجداول والعلاقات

| الجدول | الاختصار | الوصف والعلاقات الرئيسية |
|--------|---------|--------------------------|
| `hospitals` | H | المستشفيات — الجذر الأعلى للهرمية |
| `departments` | D | الأقسام — `hospital_id FK` |
| `rooms` | R | الغرف — `department_id FK` + QR Code فريد |
| `users` | U | جميع المستخدمين (superadmin, hospital_admin, dept_manager, nurse) |
| `nurse_shifts` | NS | سجل شفتات الممرضين |
| `nurse_room_assignments` | NRA | تعيين الممرض لغرف محددة |
| `dispatch_queue` | DQ | طابور Round Robin النشط لكل قسم |
| `calls` | C | جميع النداءات — دورة حياة كاملة |
| `patient_sessions` | PS | جلسات المرضى — Guest Access |
| `escalation_log` | EL | سجل التصعيدات |
| `system_settings` | SS | إعدادات النظام — key/value store |
| `push_subscriptions` | PUS | اشتراكات Web Push Notification |

---

## تعريف الجداول الرئيسية

### جدول المستشفيات — hospitals

| الحقل | النوع | قيود | الوصف |
|-------|-------|------|-------|
| id | INT UNSIGNED | PK, AI | المعرف الفريد |
| name | VARCHAR(120) | NOT NULL | اسم المستشفى |
| name_en | VARCHAR(120) | NULL | الاسم الإنجليزي اختياري |
| city | VARCHAR(80) | NOT NULL | المدينة |
| logo_url | VARCHAR(255) | NULL | رابط شعار المستشفى |
| is_active | TINYINT(1) | DEFAULT 1 | تفعيل/إيقاف المستشفى |
| settings_json | JSON | NULL | إعدادات خاصة تُغلِّب العامة |
| created_at | TIMESTAMP | DEFAULT NOW() | |
| updated_at | TIMESTAMP | ON UPDATE NOW() | |

### جدول المستخدمين — users

| الحقل | النوع | قيود | الوصف |
|-------|-------|------|-------|
| id | INT UNSIGNED | PK, AI | dbAuth يستخدم هذا الحقل تلقائياً |
| username | VARCHAR(50) | UNIQUE, NOT NULL | اسم المستخدم لتسجيل الدخول |
| password | VARCHAR(255) | NOT NULL | يُخزَّن كـ `password_hash()` |
| role | ENUM(...) | NOT NULL | superadmin \| hospital_admin \| dept_manager \| nurse |
| full_name | VARCHAR(100) | NOT NULL | الاسم الكامل |
| hospital_id | INT UNSIGNED | FK → hospitals.id | NULL لـ superadmin فقط |
| department_id | INT UNSIGNED | FK → departments.id | NULL للأدوار العليا |
| is_active | TINYINT(1) | DEFAULT 1 | |
| last_login | TIMESTAMP | NULL | آخر تسجيل دخول |
| created_at | TIMESTAMP | DEFAULT NOW() | |

### جدول النداءات — calls

| الحقل | النوع | قيود | الوصف |
|-------|-------|------|-------|
| id | INT UNSIGNED | PK, AI | |
| room_id | INT UNSIGNED | FK → rooms.id | الغرفة التي أطلقت النداء |
| department_id | INT UNSIGNED | FK → departments.id | مُخزَّن مباشرة للأداء |
| hospital_id | INT UNSIGNED | FK → hospitals.id | مُخزَّن مباشرة للـ Dashboard |
| assigned_nurse_id | INT UNSIGNED | FK → users.id, NULL | الممرض المكلَّف |
| initiated_by | ENUM(...) | NOT NULL | patient_app \| physical_button \| nurse_manual |
| status | ENUM(...) | NOT NULL | pending \| accepted \| in_progress \| completed \| escalated \| cancelled |
| priority | TINYINT | DEFAULT 0 | 0=عادي، 1=عاجل، 2=حرج |
| initiated_at | TIMESTAMP(3) | NOT NULL | بدقة الميلي ثانية |
| accepted_at | TIMESTAMP(3) | NULL | |
| arrived_at | TIMESTAMP(3) | NULL | |
| completed_at | TIMESTAMP(3) | NULL | |
| response_time_ms | INT UNSIGNED | NULL | `accepted_at - initiated_at` بالـ ms |
| notes | TEXT | NULL | ملاحظة الممرض عند الإغلاق |
| patient_session_id | INT UNSIGNED | FK → patient_sessions.id, NULL | |

### جدول جلسات المريض — patient_sessions (بعد Hardening)

| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | INT AUTO_INCREMENT PK | |
| room_id | INT NOT NULL | |
| session_token | CHAR(36) UNIQUE | UUIDv4 |
| nonce | VARCHAR(64) NULL | Single-Use Nonce |
| is_used | TINYINT(1) DEFAULT 0 | يُبطل بعد أول استخدام |
| last_used_at | DATETIME NULL | محدَّث عند كل طلب |
| expires_at | DATETIME NOT NULL | |
| created_at | DATETIME DEFAULT NOW() | |

---

## ملاحظات تصميم قاعدة البيانات

**عزل البيانات:** `hospital_id` مُخزَّن مباشرة في جداول `calls`, `rooms`, `nurses` لأداء استعلامات أسرع بدلاً من JOIN متسلسلة.

**دقة التوقيت:** `initiated_at`, `accepted_at`, `arrived_at` من نوع `TIMESTAMP(3)` (دقة الميلي ثانية) لترتيب النداءات المتزامنة.

**جدول events:** يُستخدم كـ Bridge بين REST API وRatchet — كل حدث يُكتب هنا ثم يُقرأ بـ Polling.

**بيانات التجربة:** راجع `backend/db/seeds/demo_data.sql`:
- مستشفيان: 'مستشفى القدس التخصصي' و'مستشفى النور العام'
- 4 أقسام لكل مستشفى: طوارئ، باطنة، جراحة، أطفال
- 5 غرف لكل قسم + 3 ممرضين لكل قسم
- 50 نداء تاريخي للاختبار
- ⚠️ كلمة مرور Demo: `Sncs@2024` — **يجب تغييرها قبل الإنتاج**

---

## الفهارس الموصى بها (Performance Indexes)

```sql
-- تسريع اختيار الممرض التالي في طابور Round Robin
CREATE INDEX idx_queue_dept_pos
  ON dispatch_queue (department_id, queue_position);

-- تسريع فلترة الممرضين المُقصَين
CREATE INDEX idx_queue_dept_excl
  ON dispatch_queue (department_id, is_excluded);

-- تسريع استعلام قفل النداء في Stored Procedure
CREATE INDEX idx_calls_status_nurse
  ON calls (status, assigned_nurse_id);

-- تسريع Incremental Polling على جدول events
CREATE INDEX idx_events_dept_id
  ON events (dept_id, id);

-- تسريع cleanup جلسات المريض
CREATE INDEX idx_patient_sessions_room_active
  ON patient_sessions (room_id, is_used, expires_at);
```

---

## DDL الكامل

راجع Appendix للنصوص الكاملة:
- **[appendices/schema.sql.md](./appendices/schema.sql.md)** — `CREATE TABLE` statements
- **[appendices/relations.sql.md](./appendices/relations.sql.md)** — Foreign Keys وعلاقات
- **[appendices/procedures.sql.md](./appendices/procedures.sql.md)** — `sp_assign_call_to_next_nurse` المحسّنة

---

## Related Paths

```
backend/db/schema.sql
backend/db/relations.sql
backend/db/procedures.sql
backend/db/seeds/demo_data.sql
```
