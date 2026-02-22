---
title: "Change Log — Final Hardening Pass"
path: docs/change-log.md
version: "1.3"
summary: "سجل كامل لتغييرات Enterprise Hardening Pass من v1.2 إلى v1.3"
tags: [changelog, hardening, history, version]
---

# Change Log — Final Hardening Pass

> **الإصدار:** v1.3 — Enterprise Hardening Pass  
> **تاريخ الإصدار:** 2025-02-22  
> **الحالة:** Production-Ready ✅

---

## تاريخ الإصدارات (SOP الأصلي)

| الإصدار | التاريخ | التغييرات | المعتمد من |
|---------|---------|-----------|-----------|
| 1.0 | 2024 | إصدار أولي شامل | إدارة مشروع SNCS |
| 1.1 | 2024 | إضافة Section 16: رحلة المريض (Guest Access, Presence Verification, Call Throttling). تحديث Sections 4 و11 | إدارة مشروع SNCS |
| 1.2 | 2024 | إضافة خريطة التنفيذ: المكدس التقني (Quasar + Ratchet + php-crud-api)، بنية DB، Backend Controllers، Authorization، Frontend Pages (Sections 17–20) | إدارة مشروع SNCS |
| **1.3** | **2025-02-22** | **Enterprise Hardening Pass — 17 تحسين** | **Claude + مالك المشروع** |

---

## تفاصيل Enterprise Hardening (v1.3)

| رقم | التغيير | السبب |
|-----|---------|-------|
| H1 | **Capacity Planning & Polling Tuning** — جداول QPS، 3 إعدادات بيئة (Staging/Prod-Small/Prod-Large) | تحديد حدود واضحة للمستخدمين المتزامنين قبل الإنتاج |
| H2 | **sp_assign_call_to_next_nurse Hardening** — READ COMMITTED، ROW_COUNT check، Deadlock retry (3x)، escalation_queue | منع Race Condition في توزيع النداءات المتزامنة |
| H3 | **GET_LOCK PHP Fallback** — snippet كامل مع HTTP 423 | بديل آمن للـ SP في حالات التزامن الخفيف |
| H4 | **Incremental Polling** — `WHERE id > last_id LIMIT batch_size` | تقليل QPS بنسبة 60-80% على قاعدة البيانات |
| H5 | **WebSocket Backpressure & Limits** — 500 conn/2GB، 503 عند 80%، `max_pending_messages=100` | منع انهيار الخادم عند الأحمال العالية |
| H6 | **Memory Strategy** — 3-6KB/conn، Graceful Restart عند 80% RAM | ضمان استقرار الخادم في الجلسات الطويلة |
| H7 | **Row-Level Isolation** — SESSION-only `hospital_id`، `columnHandler` للحقول الحساسة | منع تسرب بيانات بين المستشفيات |
| H8 | **Patient Token Hardening** — UUIDv4 Single-Use Nonce، `last_used_at`، cron كل 5 دقائق، max 3 sessions | رفع مستوى أمان جلسات المرضى |
| H9 | **QR Security** — HMAC-SHA256 signature، expiry 24h/1h، مثال PHP كامل | منع تزوير أو إعادة استخدام رموز QR |
| H10 | **Escalation Logic** — 3 مستويات: 90/180/300 ثانية مع audit log | توثيق واضح لمسار التصعيد |
| H11 | **Deadlock Retry Policy** — pseudocode موثّق مع exponential backoff | مرجع للمطورين لمعالجة Deadlocks |
| H12 | **Monitoring & Metrics** — 6 metrics، `/healthz`، `/ws-health`، alert thresholds | قابلية المراقبة في الإنتاج |
| H13 | **Load Testing k6** — 3 سيناريوهات S1/S2/S3 مع pass criteria وملفات كاملة | ضمان الأداء قبل الإطلاق |
| H14 | **Audit Log Retention** — سنتان append-only، export before purge | الامتثال للمتطلبات القانونية والتنظيمية |
| H15 | **Operational Runbooks** — 5 سيناريوهات تشغيلية كاملة | تمكين فريق IT من إدارة الإنتاج |
| H16 | **Appendix Files** — 7 ملفات كاملة قابلة للنسخ والتطبيق مباشرة | ملفات جاهزة للـ deployment |
| H17 | **Production Sign-Off Checklist** — 39 بنداً للتحقق | gate نهائي قبل الإطلاق |

---

## ما لم يتغير (بالتصميم)

- ✅ المعمارية الأساسية: Quasar + php-crud-api + PHP Ratchet + MySQL
- ✅ نظام المصادقة: dbAuth Session-based (لا JWT)
- ✅ لا Docker — نشر مباشر على Apache/Nginx
- ✅ لا Redis أو Firebase — MySQL هو المحور الوحيد
- ✅ محتوى الأقسام 1–20 الأصلي — لم يُحذف أي شيء
