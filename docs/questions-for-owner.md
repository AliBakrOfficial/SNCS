---
title: "أسئلة للمالك — Questions for Owner"
path: docs/questions-for-owner.md
version: "1.3"
summary: "أسئلة مفتوحة تحتاج قرارات من مالك المشروع قبل الإطلاق النهائي"
tags: [questions, decisions, owner, open-issues]
---

# أسئلة للمالك — Questions for Owner

> هذه الأسئلة رصدها التوثيق التقني وتحتاج إجابات صريحة قبل الإطلاق في الإنتاج.

---

## الأسئلة المفتوحة

### 1. بنية الجلسات — Session Storage
**السؤال:** هل يُستخدم PHP file-based session handler أم MySQL session handler لجلسات الممرضين والمديرين؟  
**الأثر:** إذا كان file-based وتُستخدم horizontal scaling، ستُفشل الجلسات عبر الخوادم.  
**الخيارات:**
- `file` (افتراضي) — يعمل على خادم واحد فقط.
- `mysql` — يُمكِّن horizontal scaling ويُتيح session revocation مباشرة.

---

### 2. ZeroMQ Internal Bridge
**السؤال:** هل يتم تنفيذ Internal Bridge (ZeroMQ) عند تجاوز 500 مستخدم متزامن؟  
**الأثر:** بدونه، MySQL Polling كل 200-500ms يُصبح عنق الزجاجة.  
**القرار الافتراضي المُتخذ:** MySQL Polling يكفي للـ Production-Small (< 500 مستخدم). Internal Bridge مُوصى به للـ Production-Large.

---

### 3. SSL Certificates للـ WebSocket
**السؤال:** هل تُدار شهادات TLS يدوياً أم عبر Let's Encrypt/Certbot؟  
**الأثر:** Ratchet يحتاج إعداد خاص لـ TLS (IoServer vs ReactPHP HTTPS).

---

### 4. Backup Strategy لقاعدة البيانات
**السؤال:** ما هو جدول الـ backup لـ MySQL في الإنتاج؟  
**المقترح:** Full backup يومي + Incremental كل ساعة + Audit Log export شهري.

---

### 5. VAPID Keys Management
**السؤال:** أين تُخزَّن VAPID Keys في الإنتاج؟  
**الخيارات:**
- في `config.php` (أبسط لكن في الكود)
- في `.env` file (أفضل)
- في vault/secrets manager (الأفضل للمستشفيات الكبيرة)

---

### 6. Demo Data في الإنتاج
**السؤال:** هل تم حذف `backend/db/seeds/demo_data.sql` من بيئة الإنتاج؟  
**⚠️ تحذير:** كلمة مرور Demo هي `Sncs@2024` — يجب حذف بيانات التجربة وتغيير جميع كلمات المرور قبل الإطلاق.

---

### 7. Monitoring External Tool
**السؤال:** هل يُستخدم Prometheus/Grafana أم أداة خارجية أخرى لرصد الـ metrics؟  
**الموثّق:** Health Endpoints `/healthz` و`/ws-health` جاهزة — تحتاج integration مع أداة المراقبة.

---

### 8. Multi-Hospital Deployment
**السؤال:** هل يُنشر نظام واحد لجميع المستشفيات أم نسخة مستقلة لكل مستشفى؟  
**الأثر:** يؤثر على بنية `hospital_id` في الجداول ومستوى العزل المطلوب.

---

### 9. QR Code الطباعة
**السؤال:** كيف تُولَّد وتُطبع رموز QR للغرف؟ هل هناك واجهة إدارية لذلك؟  
**الموثّق:** `HospitalRooms.vue` يشمل "QR Code viewer وطباعة" — هل هذا مُنفَّذ؟

---

### 10. Escalation Notifications Channel
**السؤال:** عند Level 2 (180s) وLevel 3 (300s)، كيف يصل الإشعار لمدير القسم/إدارة النظام؟  
**الخيارات:**
- WebSocket (إذا كان المدير متصلاً)
- Web Push (حتى لو المتصفح مُغلق)
- SMS/Email (خارج النطاق الحالي)

---

## قرارات تصميمية اتُّخذت (للإشارة)

| القرار | الاختيار | السبب |
|--------|---------|-------|
| Patient Token | Single-Use Nonce (لا Rotating) | أعلى أماناً في البيئة الصحية |
| Auth | PHP Session (لا JWT) | جلسة موحدة بين REST وWebSocket |
| State Broker | MySQL Polling (لا Redis) | لا وسيط خارجي — تبسيط البنية |
| Deployment | Apache/Nginx مباشر (لا Docker) | تبسيط إدارة IT في المستشفيات |
| QR Expiry | 24h عادي / 1h طارئ | توازن بين UX والأمان |
