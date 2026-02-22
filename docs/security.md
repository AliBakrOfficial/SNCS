---
title: "الأمان والصلاحيات — Security"
path: docs/security.md
version: "1.3"
summary: "توثيق شامل للأمان: Session Management، Patient Token Hardening، QR HMAC، Row-Level Isolation، Audit Log، وRetention Policy"
tags: [security, session, token, qr, hmac, audit, isolation, csrf, cors]
---

# الأمان والصلاحيات — Security

**Related Paths:** `backend/middleware/Authorization.php`, `backend/controllers/PatientController.php`, `backend/db/schema.sql`

---

## مبادئ الأمان الأساسية

- كل جلسة مصادقة تعتمد على **PHP Session (dbAuth)** مُخزَّنة في MySQL — تنتهي بانتهاء الشفت أو تسجيل الخروج.
- كل طلب WebSocket يُتحقق من هويته وصلاحيته قبل المعالجة.
- **Audit Log** كامل لكل الأحداث — غير قابل للتعديل (Append-Only).
- التشفير الكامل للبيانات أثناء النقل (TLS 1.3) وأثناء التخزين.
- مبدأ الفصل بين الأقسام مُطبَّق على **مستوى قاعدة البيانات** وليس فقط على مستوى الواجهة.
- مبدأ أقل الصلاحيات (Principle of Least Privilege) — كل مستخدم يحصل على الحد الأدنى اللازم.

---

## Row-Level Isolation Enforcement

يجب تطبيق عزل البيانات على مستوى كل طلب API عبر php-crud-api.

**مثال `authorization.recordHandler`:**

```php
// في ملف api.php أو middleware
// ⚠️ hospital_id يُؤخذ من SESSION فقط — لا تقبل أي قيمة من العميل
$record->filter = "hospital_id = " . intval($_SESSION['user']['hospital_id']);
```

**مثال `columnHandler` لإخفاء الحقول الحساسة:**

```php
$columns->remove('password_hash');
$columns->remove('internal_notes');
$columns->remove('audit_raw');
```

> ⚠️ **قاعدة صارمة:** أي `hospital_id` يصل عبر `request body` أو `query params` يجب **تجاهله تماماً**. المصدر الوحيد المعتمد هو `$_SESSION['user']['hospital_id']`.

---

## Patient Token Hardening

**نمط التوثيق المعتمد:** Single-Use Nonce Token (أكثر أماناً من Rotating Token للبيئة الصحية).

| الحقل / الإعداد | التفاصيل |
|----------------|---------|
| `session_token` | UUIDv4 — يُولَّد عند كل جلسة |
| النمط | Single-Use Nonce: يُبطَل بعد أول استخدام (`is_used = 1`) |
| `last_used_at` | DATETIME — محدَّث عند كل طلب |
| Max active sessions | 3 جلسات لكل room |
| Cleanup Cron | كل 5 دقائق — حذف sessions منتهية |

**SQL لتعديل جدول patient_sessions:**

```sql
ALTER TABLE patient_sessions
  ADD COLUMN last_used_at DATETIME NULL,
  ADD COLUMN is_used      TINYINT(1) DEFAULT 0,
  ADD COLUMN nonce        VARCHAR(64) NULL,
  ADD INDEX idx_room_active (room_id, is_used, expires_at);

-- Cron cleanup (كل 5 دقائق):
DELETE FROM patient_sessions
WHERE expires_at < NOW()
   OR (is_used = 1 AND last_used_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE));
```

---

## QR Code Security

**بنية QR:** `room_id + expiry + HMAC-SHA256 signature`

**سياسة انتهاء الصلاحية:**
- الاستخدام العادي: **24 ساعة**
- الحالات الطارئة: **1 ساعة**

**PHP — توليد QR Token:**

```php
<?php
// PATH: backend/controllers/PatientController.php (generateQRToken method)

function generateQRToken(int $room_id, int $ttl_seconds = 86400): string {
    $expiry  = time() + $ttl_seconds;
    $payload = $room_id . '|' . $expiry;
    $secret  = $_ENV['QR_HMAC_SECRET'];
    $sig     = hash_hmac('sha256', $payload, $secret);
    return base64_encode($payload . '|' . $sig);
}
```

**PHP — التحقق من QR Token:**

```php
<?php
// PATH: backend/controllers/PatientController.php (verifyQRToken method)

function verifyQRToken(string $token): ?array {
    $decoded = base64_decode($token, true);
    if (!$decoded) return null;

    [$room_id, $expiry, $sig] = explode('|', $decoded, 3);

    if (time() > (int)$expiry) return null; // منتهي الصلاحية

    $payload  = $room_id . '|' . $expiry;
    $expected = hash_hmac('sha256', $payload, $_ENV['QR_HMAC_SECRET']);

    if (!hash_equals($expected, $sig)) return null; // توقيع خاطئ

    return ['room_id' => (int)$room_id, 'expiry' => (int)$expiry];
}
```

---

## Session Revocation

**إبطال جلسة مستخدم فوراً:**

```sql
-- إلغاء جلسة محددة
UPDATE sessions SET revoked = 1, revoked_at = NOW()
WHERE session_id = :sid;

-- إلغاء جميع جلسات مستخدم
UPDATE sessions SET revoked = 1
WHERE user_id = :uid AND revoked = 0;
```

**آلية الانعكاس على WebSocket:** عند أول heartbeat بعد الإبطال، `SessionValidator` يُغلق الاتصال بكود `4401`.

**صلاحيات الطوارئ — Break-Glass Access:** في حالات الطوارئ القصوى، مدير النظام يمنح وصولاً مؤقتاً عبر بروتوكول خاص مع تسجيل كامل للسبب والمدة.

---

## Audit Log — السياسة والاحتفاظ

| البند | التفاصيل |
|-------|---------|
| مدة الاحتفاظ | **سنتان (24 شهراً)** |
| السياسة | **Append-Only** — لا حذف، لا تعديل |
| قبل الحذف | Export إلى CSV/JSON مضغوط على storage خارجي |
| Purge Schedule | شهري — بعد التحقق من Export |

**Export before purge:**

```sql
-- تصدير سجلات قديمة
SELECT * FROM audit_log
WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 YEAR)
INTO OUTFILE '/backup/audit_YYYY_MM.csv'
FIELDS TERMINATED BY ',';

-- الحذف بعد التحقق من الـ backup
DELETE FROM audit_log
WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 YEAR);
```

**مدخل Audit Log عند كل تصعيد:**

```sql
INSERT INTO audit_log (call_id, action, actor, reason, created_at)
VALUES (:call_id, 'escalation_l2', 'system', 'no_response_180s', NOW());
```

---

## CORS/CSRF

- CORS مُعيَّن في `cors` middleware: `allowedOrigins = 'https://app.sncs.io'` — لا wildcards في الإنتاج.
- CSRF محمي ضمنياً بآلية `SameSite=Lax` على Cookie + التحقق من Origin header في WebSocket Upgrade.
- كل طلب REST يمر عبر `dbAuth` قبل أي معالجة.

---

## إعدادات Call Throttling (Patient)

| المعامل | القيمة الافتراضية | الوصف |
|---------|----------------|-------|
| `throttle_duration_ms` | 300,000 ms (5 دقائق) | المدة بين نداء وآخر |
| `throttle_max_calls` | 1 نداء لكل فترة | نداء واحد لكل throttle window |
| نطاق الضبط | 60,000ms — 1,800,000ms | دقيقة إلى 30 دقيقة |
| التطبيق | جميع المرضى | إعداد مركزي — لا تعديل من القسم |

> **مبدأ الجهاز لا اليوزر:** Throttling مُطبَّق على مستوى الجهاز الفيزيائي (Local Storage) وليس الحساب — تغيير المتصفح لا يتجاوز القيد.

---

## Presence Verification (إثبات حضور المريض)

| العنصر | التفاصيل |
|--------|---------|
| المدة الافتراضية | 60 دقيقة من آخر مسح QR |
| قابلية الضبط | System Admin — النطاق: 15 دقيقة إلى 8 ساعات |
| التنبيه المسبق | شريط تحذيري قبل 5 دقائق من الانتهاء |
| انتهاء الجلسة | `patient.session_expired` — تعطيل زر النداء وعرض شاشة إعادة المسح |
| لا تجديد تلقائي | يجب مسح QR يدوياً لضمان الحضور الفعلي |

---

## Related Paths

```
backend/middleware/Authorization.php
backend/middleware/DbAuth.php
backend/controllers/PatientController.php
backend/controllers/AuthController.php
backend/db/schema.sql (patient_sessions, audit_log)
```
