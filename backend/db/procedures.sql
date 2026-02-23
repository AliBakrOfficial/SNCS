-- ============================================================
-- SNCS — Stored Procedures v1.3 (Enterprise Hardened)
-- ============================================================

DELIMITER $$

-- ============================================================
-- sp_assign_call_to_next_nurse
-- Enterprise Hardened Version
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

    -- General handler: any SQLEXCEPTION → immediate ROLLBACK
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_success    = 0;
        SET p_error_code = 'SQL_ERROR';
        SET p_assigned_nurse = NULL;
    END;

    -- Isolation level: READ COMMITTED to avoid Phantom Reads
    SET TRANSACTION ISOLATION LEVEL READ COMMITTED;

    retry_loop: LOOP

        -- Check retry count
        IF v_retries >= 3 THEN
            SET p_success        = 0;
            SET p_error_code     = 'MAX_RETRIES_EXCEEDED';
            SET p_assigned_nurse = NULL;
            LEAVE retry_loop;
        END IF;

        BEGIN
            -- Deadlock handler (MySQL Error 1213)
            DECLARE CONTINUE HANDLER FOR 1213
            BEGIN
                ROLLBACK;
                SET v_retries = v_retries + 1;
                -- Exponential backoff: 50ms, 100ms, 200ms
                DO SLEEP(0.05 * POW(2, v_retries - 1));
            END;

            START TRANSACTION;

            -- ──────────────────────────────────────────────────────
            -- Step 1: Lock the call record to prevent double assignment
            -- ──────────────────────────────────────────────────────
            SELECT id INTO v_nurse_id
            FROM calls
            WHERE id = p_call_id
              AND status = 'pending'
              AND nurse_id IS NULL
            FOR UPDATE;

            SET v_locked_rows = ROW_COUNT();

            -- If locked rows = 0 → call already assigned
            IF v_locked_rows = 0 THEN
                ROLLBACK;
                SET p_success        = 0;
                SET p_error_code     = 'CALL_ALREADY_ASSIGNED';
                SET p_assigned_nurse = NULL;
                LEAVE retry_loop;
            END IF;

            -- ──────────────────────────────────────────────────────
            -- Step 2: Select next nurse with lock on queue
            -- ──────────────────────────────────────────────────────
            SELECT nurse_id INTO v_nurse_id
            FROM dispatch_queue
            WHERE dept_id     = p_dept_id
              AND is_excluded = 0
            ORDER BY queue_position ASC
            LIMIT 1
            FOR UPDATE;

            -- If no nurse available → escalation_queue
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
            -- Step 3: Assign nurse to call
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
            -- Step 4: Round Robin — move nurse to end of queue
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
            -- Step 5: Audit Log
            -- ──────────────────────────────────────────────────────
            INSERT INTO audit_log (call_id, nurse_id, action, actor, created_at)
            VALUES (p_call_id, v_nurse_id, 'assigned', 'system', NOW());

            COMMIT;

            -- Success — exit loop
            SET p_assigned_nurse = v_nurse_id;
            SET p_success        = 1;
            SET p_error_code     = NULL;
            LEAVE retry_loop;

        END; -- BEGIN/END for Deadlock handler

    END LOOP retry_loop;

END$$

DELIMITER ;
