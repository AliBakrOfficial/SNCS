---
title: "Appendix E — WebSocket Server Hardening Config"
path: docs/appendices/ws-hardening.txt.md
version: "1.3"
summary: "إعدادات Ratchet WebSocket Server المُصلَّبة: حدود الاتصالات، Polling، Memory، وGraceful Shutdown"
tags: [appendix, websocket, ratchet, hardening, config]
---

# Appendix E — WebSocket Server Hardening Config

**Related Paths:** `backend/websocket/server_hardening.txt`

<!-- PATH: backend/websocket/server_hardening.txt -->

```text
# ═══════════════════════════════════════════════════════════
# SNCS — Ratchet WebSocket Server Hardening Configuration
# Version: 1.3 — Enterprise Hardened
# ═══════════════════════════════════════════════════════════

# ── Connection Limits ─────────────────────────────────────
MAX_CONNECTIONS=500              # per 2GB RAM
WARN_THRESHOLD=400               # 80% — reject new connections with HTTP 503
                                 # Response: 503 Service Unavailable
                                 # Header: Retry-After: 30

MAX_PENDING_MESSAGES=100         # per connection
OVERFLOW_POLICY=drop_oldest_non_critical
                                 # Non-critical: dashboard updates, nurse status
                                 # Critical (never drop): call.pending, call.escalated

# ── Polling Configuration ─────────────────────────────────
POLL_INTERVAL_MS=500             # production default (200ms for staging)
POLL_BATCH_SIZE=50               # incremental poll limit

# Incremental Poll Query (inside NursingApp.php):
# SELECT id, type, payload, dept_id
# FROM events
# WHERE id > :last_id
#   AND dept_id = :dept_id
# ORDER BY id ASC
# LIMIT :batch_size;

# ── Memory Management ──────────────────────────────────────
MEMORY_PER_CONNECTION_KB=6       # estimate: 3–6 KB per WebSocket connection
MEMORY_RESTART_THRESHOLD=80      # % of total RAM — trigger graceful restart

# ── Session & Authentication ──────────────────────────────
SESSION_CHECK_INTERVAL_SEC=30    # heartbeat interval — re-validate PHP session
SESSION_REVOKE_CLOSE_CODE=4401   # custom WebSocket close code for revoked sessions
SESSION_RECONNECT_BUFFER_SEC=180 # manager reconnect buffer (3 minutes)
NURSE_DISCONNECT_GRACE_SEC=60    # grace period before escalating nurse's calls

# ── Escalation Timeouts ───────────────────────────────────
ESCALATION_L1_SEC=90             # → next nurse in queue         (priority += 1)
ESCALATION_L2_SEC=180            # → dept manager                (priority += 2)
ESCALATION_L3_SEC=300            # → system admin                (priority += 5)

# ── TLS / WSS ─────────────────────────────────────────────
REQUIRE_WSS=true                 # MANDATORY in all environments — no plain WS
TLS_MIN_VERSION=TLSv1.2          # minimum TLS 1.2 (prefer 1.3)

# ═══════════════════════════════════════════════════════════
# Graceful Shutdown Procedure
# ═══════════════════════════════════════════════════════════
# 1. catch SIGTERM signal
# 2. $server->close()  — stop accepting new connections immediately
# 3. broadcast to all clients:
#    { "type": "server_shutdown", "reconnect_in": 5 }
# 4. sleep(10)         — drain pending messages (10 second window)
# 5. close all remaining connections
# 6. exit(0)

# ═══════════════════════════════════════════════════════════
# Graceful Restart (Memory Threshold Exceeded)
# ═══════════════════════════════════════════════════════════
# 1. Monitor RSS via /healthz → memory_pct
# 2. If memory_pct > 80%:
#    a. Spawn new Ratchet process on same port (with SO_REUSEPORT)
#    b. Broadcast reconnect signal to all connected clients
#    c. Old process enters graceful shutdown (steps above)
#    d. Old process exits after 30 second drain

# ═══════════════════════════════════════════════════════════
# Health Endpoints (implement as sidecar or within Ratchet)
# ═══════════════════════════════════════════════════════════
# GET /healthz
#   → { "status": "ok|degraded", "db_ok": true, "memory_pct": 42, "ts": 1700000000 }
#
# GET /ws-health
#   → { "connections": 234, "pending_msgs": 45, "memory_mb": 84, "uptime_s": 86400 }

# ═══════════════════════════════════════════════════════════
# Server Startup Command
# ═══════════════════════════════════════════════════════════
# nohup php /backend/websocket/server.php > /var/log/sncs-ws.log 2>&1 &
# echo $! > /var/run/sncs-ws.pid

# ═══════════════════════════════════════════════════════════
# Alert Thresholds (for external monitoring: Prometheus/Grafana)
# ═══════════════════════════════════════════════════════════
# ws_connections          > 400  → ALERT
# memory_usage_percent    > 80   → RESTART
# pending_calls_count     > 50   → ALERT
# avg_assign_time_ms      > 500  → ALERT
# failed_assignments_rate > 0.05 → ALERT
# poll_duration_avg       > 100  → ALERT
```
