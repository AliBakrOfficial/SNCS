---
title: "Appendix G — Operational Capacity Runbook"
path: docs/appendices/operational-capacity.md
version: "1.3"
summary: "دليل تشغيلي كامل: DB Overload، WS Overload، Horizontal Scale، Session Revoke، Schema Rollback"
tags: [appendix, runbook, operations, capacity, scaling]
---

# Appendix G — Operational Capacity Runbook

**Related Paths:** `runbooks/operational-capacity.md`

<!-- PATH: runbooks/operational-capacity.md -->

```text
# ══════════════════════════════════════════════════════════════
# SNCS Operational Capacity Runbook v1.0
# Smart Nurse Calling System — Enterprise Hardened
# ══════════════════════════════════════════════════════════════

# ══════════════════════════════════════════════════════════════
# Capacity Planning Formula
# ══════════════════════════════════════════════════════════════
#
# QPS = N × (1 / poll_interval_seconds) × queries_per_poll
#
# Environment    | poll_interval | batch_size | Max Users | Est. QPS
# ─────────────────────────────────────────────────────────────
# Staging        | 200ms (0.2s)  | 50         | 300       | ~1,500
# Prod-Small     | 500ms (0.5s)  | 100        | 500       | ~1,000
# Prod-Large     | Internal Bridge (ZeroMQ)    | 500+      | < 500 eff.
#
# Note: > 500 concurrent users → switch to Internal Bridge

# ══════════════════════════════════════════════════════════════
# Scenario: DB Overload
# ══════════════════════════════════════════════════════════════

STEP 1 — تحديد المشكلة:
  mysql> SHOW PROCESSLIST;
  mysql> SHOW STATUS LIKE 'Threads_running';
  mysql> SELECT * FROM information_schema.INNODB_TRX;

STEP 2 — إيقاف Polling مؤقتاً:
  # In application config (config.php or .env):
  POLLING_ENABLED=0
  # Restart php-fpm to pick up change:
  systemctl reload php8.x-fpm

STEP 3 — إنهاء الاستعلامات المعلّقة (> 30 ثانية):
  mysql> SELECT id, time, info FROM information_schema.processlist
         WHERE time > 30 AND command != 'Sleep';
  mysql> KILL <thread_id>;

STEP 4 — فحص الفهارس:
  mysql> EXPLAIN SELECT * FROM calls WHERE status='pending' AND dept_id=?;
  mysql> EXPLAIN SELECT * FROM events WHERE id > ? AND dept_id=? LIMIT 50;

STEP 5 — رفع Buffer Pool مؤقتاً:
  mysql> SET GLOBAL innodb_buffer_pool_size = <70%_of_RAM_in_bytes>;

STEP 6 — استعادة Polling:
  POLLING_ENABLED=1
  systemctl reload php8.x-fpm

# ══════════════════════════════════════════════════════════════
# Scenario: WebSocket Overload
# ══════════════════════════════════════════════════════════════

STEP 1 — فحص الحالة:
  curl -s http://localhost:8080/ws-health | python3 -m json.tool

STEP 2 — إذا connections > 400:
  Option A — رفع الحد مؤقتاً في server.php:
    define('MAX_CONNECTIONS', 600);
    # ثم graceful restart (see below)

  Option B — تفعيل Load Balancer:
    # أضف server ثاني لـ Nginx upstream (ip_hash للـ sticky sessions)

STEP 3 — Graceful Restart:
  cat /var/run/sncs-ws.pid | xargs kill -SIGTERM
  sleep 15  # انتظر shutdown كامل
  nohup php /backend/websocket/server.php > /var/log/sncs-ws.log 2>&1 &
  echo $! > /var/run/sncs-ws.pid

STEP 4 — مراقبة الاتصالات بعد الإعادة:
  watch -n 2 'curl -s http://localhost:8080/ws-health | python3 -m json.tool'

# ══════════════════════════════════════════════════════════════
# Scenario: Horizontal Scaling
# ══════════════════════════════════════════════════════════════

ARCHITECTURE:
  [Nginx Load Balancer]
       │
       ├── [ws1.sncs.io:8080] → MySQL Primary (Writes)
       │                      → MySQL Replica (Reads/Polling)
       │
       └── [ws2.sncs.io:8080] → MySQL Primary (Writes)
                              → MySQL Replica (Reads/Polling)

NGINX CONFIG:
  upstream sncs_ws {
      ip_hash;  # MANDATORY for WebSocket sticky sessions
      server ws1.sncs.io:8080 weight=1;
      server ws2.sncs.io:8080 weight=1;
  }

  server {
      listen 443 ssl;
      location /ws {
          proxy_pass http://sncs_ws;
          proxy_http_version 1.1;
          proxy_set_header Upgrade $http_upgrade;
          proxy_set_header Connection "upgrade";
          proxy_set_header Host $host;
          proxy_read_timeout 3600;  # 1 hour for long-lived WS connections
      }
  }

RULES:
  - Write queries (INSERT/UPDATE calls, nurses, events) → MySQL Primary ONLY
  - Read queries (SELECT FROM events for polling) → MySQL Replica
  - All Ratchet instances share the SAME MySQL state (no local state in PHP)
  - Session files must be on shared storage (NFS) or MySQL session handler

# ══════════════════════════════════════════════════════════════
# Scenario: Session Revoke
# ══════════════════════════════════════════════════════════════

STEP 1 — تحديد الجلسة:
  mysql> SELECT session_id, user_id, created_at
         FROM sessions WHERE user_id = :uid AND revoked = 0;

STEP 2 — إلغاء الجلسة:
  mysql> UPDATE sessions
         SET revoked = 1, revoked_at = NOW()
         WHERE session_id = :sid;

  -- أو إلغاء جميع جلسات المستخدم:
  mysql> UPDATE sessions
         SET revoked = 1, revoked_at = NOW()
         WHERE user_id = :uid AND revoked = 0;

STEP 3 — تأثير على WebSocket:
  - Ratchet يُعيد session_start() عند كل heartbeat (كل 30 ثانية)
  - إذا وجدت الجلسة محذوفة/مُبطَلة → يُغلق الاتصال بكود 4401
  - الوقت الأقصى للاكتشاف: 30 ثانية

STEP 4 — تسجيل:
  mysql> INSERT INTO audit_log (action, actor, reason, created_at)
         VALUES ('session_revoked', 'admin', 'security_incident', NOW());

# ══════════════════════════════════════════════════════════════
# Scenario: Schema Rollback
# ══════════════════════════════════════════════════════════════

STEP 1 — Backup إلزامي قبل أي تغيير:
  mysqldump sncs_db > /backup/sncs_db_$(date +%Y%m%d_%H%M).sql

STEP 2 — تطبيق DOWN migration:
  mysql sncs_db < migrations/XXX_down.sql

  -- مثال: rollback للـ patient_sessions hardening columns:
  ALTER TABLE patient_sessions DROP COLUMN is_used;
  ALTER TABLE patient_sessions DROP COLUMN nonce;
  ALTER TABLE patient_sessions DROP COLUMN last_used_at;

STEP 3 — التحقق:
  mysql> DESCRIBE patient_sessions;
  mysql> SHOW INDEX FROM patient_sessions;

STEP 4 — إعادة تشغيل الخدمات:
  systemctl restart php8.x-fpm
  cat /var/run/sncs-ws.pid | xargs kill -SIGTERM
  sleep 15
  nohup php /backend/websocket/server.php > /var/log/sncs-ws.log 2>&1 &

STEP 5 — التحقق من الصحة:
  curl http://localhost:8080/ws-health
  curl https://app.sncs.io/healthz
```
