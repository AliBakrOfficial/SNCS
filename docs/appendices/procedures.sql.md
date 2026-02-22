---
title: "Appendix C — Stored Procedures (procedures.sql)"
path: docs/appendices/procedures.sql.md
version: "1.3"
summary: "sp_assign_call_to_next_nurse الكاملة والمحسّنة مع READ COMMITTED، ROW_COUNT check، Deadlock retry (3 محاولات)، ومعالجة nurse pool فارغ"
tags: [appendix, sql, stored-procedure, concurrency, deadlock, mysql]
---

# Appendix C — Stored Procedures (procedures.sql)

**Related Paths:** `backend/db/procedures.sql`

<!-- PATH: backend/db/procedures.sql -->

```sql
-- ============================================================
-- SNCS — Stored Procedures v1.3 (Enterprise Hardened)
-- ============================================================

DELIMITER $$

-- ============================================================
-- sp_assign_call_to_next_nurse
-- الإصدار المحسّن — Enterprise Hardening Pass
--
-- Features:
--   - READ COMMITTED isolation level
--   - ROW_COUNT() check after SELECT FOR UPDATE
--   - Deadlock retry: 3 attempts with 50/100/200ms backoff
--   - Nurse pool empty: escalation_queue with reason='no_available_nurse'
--   - OUT parameters: p_assigned_nurse, p_success, p_error_code
-- ============================================================

DROP PROCEDURE IF EXISTS sp_assign_call_to_next_nurse$$

CREATE PROCEDURE sp_assign_call_to_next_nurse(
    IN  p_call_id        INT,
    IN  p_dept_id        INT,
    OUT p_assigned_nurse INT,
    OUT p_success        TINYINT,
    OUT p_error_code     VARCHAR(50)
)
BEGIN
    DECLARE v_nurse_id   INT     DEFAULT NULL;
    DECLARE v_retries    INT     DEFAULT 0;
    DECLARE v_locked_rows INT    DEFAULT 0;

    -- Handler العام: أي SQLEXCEPTION → ROLLBACK فوري
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_success    = 0;
        SET p_error_code = 'SQL_ERROR';
        SET p_assigned_nurse = NULL;
    END;

    -- مستوى العزل: READ COMMITTED لتجنب Phantom Reads
    SET TRANSACTION ISOLATION LEVEL READ COMMITTED;

    retry_loop: LOOP

        -- التحقق من عدد المحاولات
        IF v_retries >= 3 THEN
            SET p_success        = 0;
            SET p_error_code     = 'MAX_RETRIES_EXCEEDED';
            SET p_assigned_nurse = NULL;
            LEAVE retry_loop;
        END IF;

        BEGIN
            -- Handler لـ Deadlock (MySQL Error 1213)
            DECLARE CONTINUE HANDLER FOR 1213
            BEGIN
                ROLLBACK;
                SET v_retries = v_retries + 1;
                -- Exponential backoff: 50ms, 100ms, 200ms
                DO SLEEP(0.05 * POW(2, v_retries - 1));
            END;

            START TRANSACTION;

            -- ──────────────────────────────────────────────────────
            -- الخطوة 1: Lock سجل النداء لمنع التعيين المزدوج
            -- ──────────────────────────────────────────────────────
            SELECT id INTO v_nurse_id
            FROM calls
            WHERE id = p_call_id
              AND status = 'pending'
              AND nurse_id IS NULL
            FOR UPDATE;

            SET v_locked_rows = ROW_COUNT();

            -- إذا كانت الصفوف المقفلة = 0 → النداء عُيِّن بالفعل
            IF v_locked_rows = 0 THEN
                ROLLBACK;
                SET p_success        = 0;
                SET p_error_code     = 'CALL_ALREADY_ASSIGNED';
                SET p_assigned_nurse = NULL;
                LEAVE retry_loop;
            END IF;

            -- ──────────────────────────────────────────────────────
            -- الخطوة 2: اختيار الممرض التالي مع Lock على الطابور
            -- ──────────────────────────────────────────────────────
            SELECT nurse_id INTO v_nurse_id
            FROM dispatch_queue
            WHERE dept_id     = p_dept_id
              AND is_excluded = 0
            ORDER BY queue_position ASC
            LIMIT 1
            FOR UPDATE;

            -- إذا لم يوجد ممرض متاح → escalation_queue
            IF v_nurse_id IS NULL THEN
                INSERT INTO escalation_queue (call_id, level, reason, created_at)
                VALUES (p_call_id, 1, 'no_available_nurse', NOW());

                INSERT INTO audit_log (call_id, action, actor, reason, created_at)
                VALUES (p_call_id, 'escalation_l1', 'system', 'no_available_nurse', NOW());

                COMMIT;
                SET p_success        = 0;
                SET p_error_code     = 'NO_NURSE_AVAILABLE';
                SET p_assigned_nurse = NULL;
                LEAVE retry_loop;
            END IF;

            -- ──────────────────────────────────────────────────────
            -- الخطوة 3: تعيين الممرض على النداء
            -- ──────────────────────────────────────────────────────
            UPDATE nurses
            SET status = 'busy', last_assigned_at = NOW()
            WHERE id = v_nurse_id;

            UPDATE calls
            SET nurse_id    = v_nurse_id,
                status      = 'assigned',
                assigned_at = NOW()
            WHERE id = p_call_id;

            -- ──────────────────────────────────────────────────────
            -- الخطوة 4: Round Robin — الممرض لنهاية الطابور
            -- ──────────────────────────────────────────────────────
            UPDATE dispatch_queue
            SET queue_position = (
                SELECT MAX(qp.queue_position)
                FROM dispatch_queue qp
                WHERE qp.dept_id = p_dept_id
            ) + 1
            WHERE nurse_id = v_nurse_id
              AND dept_id  = p_dept_id;

            -- ──────────────────────────────────────────────────────
            -- الخطوة 5: Audit Log
            -- ──────────────────────────────────────────────────────
            INSERT INTO audit_log (call_id, nurse_id, action, actor, created_at)
            VALUES (p_call_id, v_nurse_id, 'assigned', 'system', NOW());

            COMMIT;

            -- نجاح — خروج من الـ loop
            SET p_assigned_nurse = v_nurse_id;
            SET p_success        = 1;
            SET p_error_code     = NULL;
            LEAVE retry_loop;

        END; -- BEGIN/END للـ Deadlock handler

    END LOOP retry_loop;

END$$

DELIMITER ;
```
