---
title: "التشغيل والرصد — Operations & Monitoring"
path: docs/operations.md
version: "1.3"
summary: "Runbooks تشغيلية، Capacity Planning، Health Endpoints، Monitoring Metrics، وخطوات النشر"
tags: [operations, runbooks, monitoring, capacity, metrics, health, deployment, scaling]
---

# التشغيل والرصد — Operations & Monitoring

**Related Paths:** `runbooks/operational-capacity.md`, `backend/websocket/server_hardening.txt`

---

## Capacity Planning & Polling Tuning

**المعادلة الأساسية:**

```
QPS = N × (1 / poll_interval_seconds) × queries_per_poll
```

حيث: `N` = عدد المستخدمين المتزامنين، `queries_per_poll = 1` في معظم الحالات.

| البيئة | poll_interval | batch_size | Max Concurrent Users | Estimated QPS |
|--------|-------------|----------|---------------------|--------------|
| Staging | 200ms | 50 | 300 | ~1,500 QPS |
| Production-Small | 500ms | 100 | 500 | ~1,000 QPS |
| Production-Large | N/A — Internal Bridge | — | 500+ | < 500 QPS effective |

> **ملاحظة:** عند تجاوز 500 مستخدم متزامن يُوصى بالانتقال إلى Internal Bridge (ZeroMQ) بدلاً من HTTP polling.

---

## Monitoring Metrics

| المقياس | الوصف | حد التنبيه |
|---------|-------|----------|
| `ws_connections` | WebSocket connections نشطة | > 400 → ALERT |
| `memory_usage_percent` | استخدام RAM | > 80% → RESTART |
| `pending_calls_count` | نداءات بانتظار تعيين | > 50 → ALERT |
| `avg_assign_time_ms` | متوسط وقت التعيين | > 500ms → ALERT |
| `failed_assignments_rate` | معدل فشل التعيين | > 5% → ALERT |
| `poll_duration_avg` | متوسط مدة كل poll | > 100ms → ALERT |

**Health Endpoints:**

```
GET /healthz    → { status, db_ok, memory_pct, ts }
GET /ws-health  → { connections, pending_msgs, memory_mb, uptime_s }
```

---

## معايير الأداء — KPIs

| المؤشر | الهدف المثالي | الحد الأدنى المقبول | إجراء عند الخرق |
|--------|-------------|------------------|----------------|
| متوسط وقت الاستجابة للنداء | < 60 ثانية | < 120 ثانية | مراجعة فورية |
| معدل النداءات المُصعَّدة | < 5% | < 15% | تحقيق في السبب |
| مؤشر عدالة التوزيع | انحراف < 10% | انحراف < 20% | مراجعة إعدادات الطابور |
| نسبة الممرضين المُفعَّلين | > 95% خلال 5 دقائق | > 80% خلال 10 دقائق | تدريب إضافي |
| زمن تسليم حدث WebSocket | < 100ms | < 500ms | مراجعة البنية التقنية |
| معدل انقطاع الاتصالات | < 0.1% من الجلسات | < 1% من الجلسات | فحص الشبكة |

---

## Runbook: DB Overload

```bash
# 1. تحقق من slow query log
SHOW PROCESSLIST;

# 2. أوقف polling مؤقتاً
POLLING_ENABLED=0  # عبر config flag في التطبيق

# 3. تنفيذ KILL على الاستعلامات المعلّقة أكثر من 30 ثانية
KILL <thread_id>;

# 4. راجع index coverage
EXPLAIN SELECT ...;

# 5. رفع innodb_buffer_pool_size إلى 70% RAM
SET GLOBAL innodb_buffer_pool_size = <value>;
```

---

## Runbook: WebSocket Overload

```bash
# 1. راقب /ws-health
curl http://localhost:8080/ws-health

# 2. إذا connections > 400:
#    - رفع حد max_connections مؤقتاً في server.php
#    - أو تفعيل sticky load balancer

# 3. Graceful Restart
kill -SIGTERM <ratchet_pid>
# الـ server يُرسل server_shutdown للعملاء ثم يُغلق خلال 10s
```

---

## Runbook: Horizontal Scaling

المعمارية الحالية تدعم horizontal scaling محدوداً:

```nginx
# Nginx upstream — sticky sessions للـ WebSocket
upstream sncs_ws {
    ip_hash;  # sticky sessions إلزامية للـ WebSocket
    server ws1.sncs.io:8080;
    server ws2.sncs.io:8080;
}
```

- **MySQL** يبقى مركزياً — استخدم read replicas للـ polling queries.
- نشر نسخ متعددة من Ratchet مع shared MySQL state.
- Write queries (INSERT calls, UPDATE nurses) → primary only.
- Read queries (SELECT FROM events) → read replica.

---

## Runbook: Session Revoke

```sql
-- إلغاء جلسة مستخدم فوراً
UPDATE sessions SET revoked = 1, revoked_at = NOW()
WHERE session_id = :sid;

-- إلغاء جميع جلسات مستخدم
UPDATE sessions SET revoked = 1
WHERE user_id = :uid AND revoked = 0;

-- Ratchet يكتشف الإبطال عند أول heartbeat → يُغلق بكود 4401
```

---

## Runbook: Schema Rollback

```bash
# 1. Backup قبل أي ALTER TABLE
mysqldump sncs_db > backup_$(date +%Y%m%d).sql

# 2. Apply DOWN migration
mysql sncs_db < migrations/XXX_down.sql

# 3. التحقق
DESCRIBE table_name;

# 4. إعادة تشغيل PHP-FPM وRatchet
systemctl restart php8-fpm
kill -SIGTERM <ratchet_pid> && nohup php server.php > /var/log/sncs-ws.log 2>&1 &
```

**مثال rollback:**

```sql
ALTER TABLE patient_sessions DROP COLUMN is_used;
ALTER TABLE patient_sessions DROP COLUMN nonce;
```

---

## Deadlock Retry Policy

**سياسة:** 3 محاولات مع Exponential Backoff.

```
for attempt in [1, 2, 3]:
    try:
        BEGIN TRANSACTION
        result = CALL sp_assign_call_to_next_nurse(...)
        COMMIT
        break
    catch DEADLOCK (MySQL Error 1213):
        ROLLBACK
        sleep(50ms × 2^(attempt-1))  // 50ms, 100ms, 200ms
        if attempt == 3:
            raise HTTP 503
```

---

## Escalation Logic

| الوقت | المستوى | المستلم | تأثير Priority | audit_action |
|-------|---------|---------|--------------|------------|
| 90 ثانية | Level 1 | ممرضة الطابق التالية | priority += 1 | escalation_l1 |
| 180 ثانية | Level 2 | مدير القسم | priority += 2 | escalation_l2 |
| 300 ثانية | Level 3 | إدارة النظام | priority += 5 | escalation_l3 |

---

## Memory Strategy

| البند | القيمة |
|-------|-------|
| تقدير RAM لكل اتصال | 3–6 KB |
| 500 اتصال | ~3 MB |
| حد إعادة التشغيل | RSS > 80% RAM |

**خطوات Graceful Restart:**
1. رصد `memory_usage_percent` عبر `/healthz`.
2. عند تجاوز 80%: إنشاء عملية Ratchet جديدة على نفس المنفذ.
3. إرسال إشارة reconnect للعملاء عبر WebSocket.
4. إنهاء العملية القديمة بعد 30 ثانية.

---

## تشغيل الخوادم

```bash
# تشغيل Ratchet WebSocket Server
nohup php /backend/websocket/server.php > /var/log/sncs-ws.log 2>&1 &

# التحقق من حالته
curl http://localhost:8080/ws-health

# إيقاف Graceful
kill -SIGTERM $(pgrep -f "server.php")
```

---

## Related Paths

```
runbooks/operational-capacity.md
backend/websocket/server_hardening.txt
backend/websocket/server.php
backend/db/procedures.sql
```
