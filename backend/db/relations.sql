-- ============================================================
-- SNCS Database Relations & Constraints
-- ============================================================
-- Note: Most Foreign Keys are defined inside schema.sql
-- This file documents relationships visually and adds extra constraints

-- ============================================================
-- Relationship Diagram:
--
-- hospitals (1)
--    ├── departments (N)       hospital_id FK
--    │      ├── rooms (N)      dept_id FK
--    │      ├── nurses (N)     dept_id FK
--    │      ├── dispatch_queue dept_id FK
--    │      └── calls (N)      dept_id FK
--    └── users (N)             hospital_id FK (nullable for superadmin)
--
-- calls (1)
--    ├── room_id FK → rooms.id
--    ├── nurse_id FK → nurses.id (nullable until assigned)
--    └── patient_session_id FK → patient_sessions.id (nullable)
--
-- audit_log — references calls, nurses (no FK to allow orphan records)
-- events — references dept_id, hospital_id (no FK for performance)
-- ============================================================

-- Constraint: one nurse per department in queue
ALTER TABLE dispatch_queue
    ADD CONSTRAINT uq_dept_nurse UNIQUE (dept_id, nurse_id);

-- Index for improved Ratchet Polling
CREATE INDEX idx_events_broadcast_dept
    ON events (is_broadcast, dept_id, id);

-- Index for active calls per nurse
CREATE INDEX idx_calls_nurse_active
    ON calls (nurse_id, status);

-- Escalation foreign key
ALTER TABLE escalation_queue
    ADD CONSTRAINT fk_escalation_call
        FOREIGN KEY (call_id) REFERENCES calls(id) ON DELETE CASCADE;

-- Trigger: max 3 active sessions per room
DELIMITER $$
CREATE TRIGGER trg_max_patient_sessions
BEFORE INSERT ON patient_sessions
FOR EACH ROW
BEGIN
    DECLARE session_count INT;
    SELECT COUNT(*) INTO session_count
    FROM patient_sessions
    WHERE room_id = NEW.room_id
      AND is_used = 0
      AND expires_at > NOW();

    IF session_count >= 3 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'MAX_SESSIONS_PER_ROOM: limit is 3 active sessions';
    END IF;
END$$
DELIMITER ;
