-- ============================================================
-- SNCS — Smart Nurse Calling System
-- Database Schema v1.3
-- Engine: MySQL 8+
-- Charset: utf8mb4
-- ============================================================

CREATE TABLE hospitals (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(200) NOT NULL,
    name_en      VARCHAR(200) NULL,
    city         VARCHAR(80)  NOT NULL,
    logo_url     VARCHAR(255) NULL,
    is_active    TINYINT(1)   DEFAULT 1,
    settings_json JSON        NULL COMMENT 'Per-hospital overrides for system settings',
    created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------

CREATE TABLE departments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    hospital_id  INT NOT NULL,
    name         VARCHAR(100) NOT NULL,
    name_en      VARCHAR(100) NULL,
    is_active    TINYINT(1)   DEFAULT 1,
    created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (hospital_id) REFERENCES hospitals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------

CREATE TABLE rooms (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    hospital_id  INT          NOT NULL,
    dept_id      INT          NOT NULL,
    room_number  VARCHAR(20)  NOT NULL,
    qr_code      VARCHAR(255) NOT NULL UNIQUE,
    qr_token     VARCHAR(512) NULL COMMENT 'HMAC-signed token for QR verification',
    qr_expires_at DATETIME    NULL,
    is_active    TINYINT(1)   DEFAULT 1,
    created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dept_id)     REFERENCES departments(id),
    FOREIGN KEY (hospital_id) REFERENCES hospitals(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------

CREATE TABLE users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password      VARCHAR(255) NOT NULL COMMENT 'Stored as password_hash() — managed by dbAuth',
    role          ENUM('superadmin','hospital_admin','dept_manager','nurse') NOT NULL,
    full_name     VARCHAR(100) NOT NULL,
    hospital_id   INT UNSIGNED NULL,
    department_id INT UNSIGNED NULL,
    is_active     TINYINT(1)   DEFAULT 1,
    last_login    TIMESTAMP    NULL,
    avatar_url    VARCHAR(255) NULL,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (hospital_id)   REFERENCES hospitals(id),
    FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------

CREATE TABLE nurses (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    hospital_id      INT NOT NULL,
    dept_id          INT NOT NULL,
    user_id          INT UNSIGNED NOT NULL,
    name             VARCHAR(100) NOT NULL,
    status           ENUM('available','busy','offline') DEFAULT 'available',
    last_assigned_at DATETIME NULL,
    FOREIGN KEY (dept_id)     REFERENCES departments(id),
    FOREIGN KEY (hospital_id) REFERENCES hospitals(id),
    FOREIGN KEY (user_id)     REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------

CREATE TABLE nurse_shifts (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    nurse_id      INT  NOT NULL,
    dept_id       INT  NOT NULL,
    hospital_id   INT  NOT NULL,
    started_at    DATETIME NOT NULL,
    ended_at      DATETIME NULL,
    status        ENUM('active','ended','abandoned') DEFAULT 'active',
    total_calls   INT UNSIGNED DEFAULT 0,
    FOREIGN KEY (nurse_id) REFERENCES nurses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------

CREATE TABLE nurse_room_assignments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    nurse_id     INT NOT NULL,
    room_id      INT NOT NULL,
    dept_id      INT NOT NULL,
    assigned_by  INT UNSIGNED NOT NULL,
    is_manual    TINYINT(1) DEFAULT 1,
    expires_at   DATETIME NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (nurse_id) REFERENCES nurses(id),
    FOREIGN KEY (room_id)  REFERENCES rooms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------

CREATE TABLE dispatch_queue (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    dept_id        INT NOT NULL,
    nurse_id       INT NOT NULL,
    hospital_id    INT NOT NULL,
    queue_position INT UNSIGNED DEFAULT 0,
    is_excluded    TINYINT(1)  DEFAULT 0,
    excluded_until DATETIME NULL,
    excluded_by    INT UNSIGNED NULL,
    exclusion_reason VARCHAR(200) NULL,
    FOREIGN KEY (dept_id)  REFERENCES departments(id),
    FOREIGN KEY (nurse_id) REFERENCES nurses(id),
    INDEX idx_queue_dept_pos  (dept_id, queue_position),
    INDEX idx_queue_dept_excl (dept_id, is_excluded)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------

CREATE TABLE calls (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    room_id             INT NOT NULL,
    dept_id             INT NOT NULL,
    hospital_id         INT NOT NULL,
    nurse_id            INT NULL,
    status              ENUM('pending','assigned','in_progress','completed','escalated','cancelled')
                        DEFAULT 'pending',
    priority            TINYINT DEFAULT 1 COMMENT '0=normal, 1=urgent, 2=critical',
    initiated_by        ENUM('patient_app','physical_button','nurse_manual') NOT NULL,
    initiated_at        DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
    accepted_at         DATETIME(3) NULL,
    arrived_at          DATETIME(3) NULL,
    assigned_at         DATETIME(3) NULL,
    completed_at        DATETIME(3) NULL,
    response_time_ms    INT UNSIGNED NULL,
    notes               TEXT NULL,
    patient_session_id  INT NULL,
    is_broadcast        TINYINT(1) DEFAULT 0 COMMENT 'Ratchet polling flag',
    FOREIGN KEY (room_id)    REFERENCES rooms(id),
    FOREIGN KEY (dept_id)    REFERENCES departments(id),
    FOREIGN KEY (hospital_id) REFERENCES hospitals(id),
    INDEX idx_calls_status_nurse (status, nurse_id),
    INDEX idx_calls_dept_status  (dept_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------

CREATE TABLE patient_sessions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    room_id        INT          NOT NULL,
    session_token  CHAR(36)     NOT NULL UNIQUE COMMENT 'UUIDv4',
    nonce          VARCHAR(64)  NULL COMMENT 'Single-Use Nonce',
    is_used        TINYINT(1)   DEFAULT 0,
    last_used_at   DATETIME     NULL,
    expires_at     DATETIME     NOT NULL,
    created_at     DATETIME     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_token    (session_token),
    INDEX idx_room_active   (room_id, is_used, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------

CREATE TABLE escalation_queue (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    call_id      INT NOT NULL,
    level        TINYINT DEFAULT 1,
    reason       VARCHAR(100) NOT NULL,
    escalated_to INT UNSIGNED NULL,
    resolved     TINYINT(1) DEFAULT 0,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------

CREATE TABLE audit_log (
    id         BIGINT AUTO_INCREMENT PRIMARY KEY,
    call_id    INT          NULL,
    nurse_id   INT          NULL,
    action     VARCHAR(50)  NOT NULL,
    actor      VARCHAR(50)  DEFAULT 'system',
    reason     VARCHAR(200) NULL,
    meta_json  JSON         NULL,
    created_at DATETIME     DEFAULT CURRENT_TIMESTAMP
    -- Append-only: NO UPDATE or DELETE allowed
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------

CREATE TABLE events (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    type         VARCHAR(50)  NOT NULL,
    payload      JSON         NOT NULL,
    dept_id      INT          NOT NULL,
    hospital_id  INT          NOT NULL,
    is_broadcast TINYINT(1)   DEFAULT 0 COMMENT 'Ratchet sets to 1 after broadcast',
    created_at   DATETIME(3)  DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_dept_id (dept_id, id),
    INDEX idx_broadcast (is_broadcast, dept_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------

CREATE TABLE system_settings (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    value       TEXT         NOT NULL,
    updated_at  DATETIME     ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default settings
INSERT INTO system_settings (setting_key, value) VALUES
    ('throttle_duration_ms',      '300000'),
    ('verification_interval_min', '60'),
    ('escalation_timeout_sec',    '90'),
    ('max_active_sessions',       '3'),
    ('poll_interval_ms',          '500'),
    ('poll_batch_size',           '50');

-- ------------------------------------------------------------

CREATE TABLE push_subscriptions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    endpoint   TEXT         NOT NULL,
    p256dh     TEXT         NOT NULL,
    auth_key   VARCHAR(255) NOT NULL,
    created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------

CREATE TABLE rate_limits (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip             VARCHAR(45)     NOT NULL,
    endpoint_group VARCHAR(100)    NOT NULL,
    created_at     DATETIME(3)     NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_ip_group_time (ip, endpoint_group, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
