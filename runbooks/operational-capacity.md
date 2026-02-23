# SNCS — Operational Runbook

## Capacity Planning

### Current Thresholds (from load testing)

| Scenario      | VUs  | Calls/min | p95 Target | Error Rate |
| ------------- | ---- | --------- | ---------- | ---------- |
| S1 Staging    | 150  | 200       | < 500ms    | < 1%       |
| S2 Production | 300  | 600       | < 800ms    | < 2%       |
| S3 Stress     | 1000 | 2000      | < 2000ms   | < 5%       |

### Scaling Triggers

| Metric         | Warning           | Critical        | Action                |
| -------------- | ----------------- | --------------- | --------------------- |
| CPU            | > 70%             | > 85%           | Add worker            |
| Memory         | > 70%             | > 85%           | Restart + investigate |
| DB connections | > 80% pool        | > 90% pool      | Increase pool         |
| WS connections | > 400             | > 450           | Start 2nd WS instance |
| Response p95   | > threshold × 1.5 | > threshold × 2 | Scale horizontally    |

---

## Monitoring

### Health Endpoints

- `GET /healthz` → API health (returns 200 if MySQL reachable)
- WS server logs: `logs/ws-server.log`

### Key Metrics to Monitor

- API response time (p50, p95, p99)
- WebSocket connection count
- Active calls count
- Escalation queue depth
- Error rate per endpoint
- Database query time
- PHP memory usage

---

## Incident Response

### WebSocket Server Down

1. Check `logs/ws-server.log` for errors
2. Check PHP memory usage: `ps aux | grep server.php`
3. Restart: `bash scripts/start-ws.sh`
4. If recurring: check for memory leaks (connection not cleaned up)

### Database Deadlock

1. The stored procedure `sp_assign_call_to_next_nurse` has retry logic (3 attempts)
2. If persistent: check `SHOW ENGINE INNODB STATUS\G`
3. Verify `GET_LOCK` fallback is working in `CallController`

### High Escalation Rate

1. Check if enough nurses are on shift
2. Verify dispatch_queue is being populated on shift start
3. Check if nurses are being excluded inadvertently
4. Review escalation timeouts (L1: 90s, L2: 180s, L3: 300s)

---

## Rollback Procedure

### Code Rollback

```bash
# 1. Identify last good commit
git log --oneline -10

# 2. Revert to last good commit
git revert HEAD

# 3. Restart services
bash scripts/start-ws.sh
```

### Database Rollback

```bash
# MySQL point-in-time recovery from backup
mysql -u root -p sncs_db < /backups/sncs_db_YYYYMMDD.sql
```

---

## Maintenance Windows

### Recommended Schedule

- **Database cleanup**: Daily at 03:00 (events table, expired sessions)
- **Log rotation**: Weekly
- **Security updates**: Monthly
- **Load testing**: Before each release
