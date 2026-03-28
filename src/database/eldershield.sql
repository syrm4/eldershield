-- ============================================================
-- ElderShield — Complete Database Schema (Simplified)
-- Single file. Run once. No separate migrations needed.
-- ============================================================
--
-- ⚠️  IMPORTANT SETUP INSTRUCTIONS — READ BEFORE IMPORTING
-- ============================================================
-- Step 1: Import this file into MySQL to create the schema.
--         In phpMyAdmin: Import > choose this file > Go
--         In terminal:   mysql -u root -p < eldershield.sql
--
-- Step 2: Immediately after importing, run seed.php to replace
--         the placeholder password hashes with real bcrypt hashes.
--         In terminal:   php src/database/seed.php
--         In browser:    http://localhost/eldershield/src/database/seed.php
--
-- ⛔  DO NOT skip Step 2. The placeholder hashes in this file
--     are NOT valid. Without running seed.php, nobody will be
--     able to log in.
--
-- Default login credentials after running seed.php:
--     Admin:     admin@eldershield.com  / password: admin123
--     Elder:     dorothy@example.com   / password: elder123
--     Caregiver: sarah@example.com     / password: care123
-- ============================================================

CREATE DATABASE IF NOT EXISTS eldershield
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE eldershield;

-- ============================================================
-- USERS
-- ============================================================
CREATE TABLE users (
    user_id       INT AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(150) NOT NULL,
    email         VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('elder','caregiver','admin') NOT NULL DEFAULT 'elder',
    -- Caregiver plan: 'free' = 2 elder links, 'premium' = unlimited
    plan          ENUM('free','premium') NOT NULL DEFAULT 'free',
    plan_expires  DATETIME NULL DEFAULT NULL,    -- NULL = no expiry (free tier)
    plan_paused   TINYINT(1) NOT NULL DEFAULT 0, -- 1 = admin paused, blocks premium features
    is_active     TINYINT(1)  NOT NULL DEFAULT 1,
    created_at    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- INCIDENTS
-- ============================================================
CREATE TABLE incidents (
    incident_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT  NOT NULL,
    content      TEXT NOT NULL,
    image_path   VARCHAR(500) DEFAULT NULL,
    status       ENUM('pending','cleared','analyzed','reviewed','dismissed') NOT NULL DEFAULT 'pending',
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- ANALYSIS
-- One row per incident. Populated by background Ollama worker.
-- ============================================================
CREATE TABLE analysis (
    incident_id        INT          NOT NULL PRIMARY KEY, -- 1-to-1 with incidents
    scam_probability   TINYINT      NOT NULL DEFAULT 0,   -- 0-100, TINYINT saves space
    scam_category      VARCHAR(50)  DEFAULT NULL,
    manipulation_tactics JSON       DEFAULT NULL,
    explanation_simple TEXT         DEFAULT NULL,
    recommended_action TEXT         DEFAULT NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (incident_id) REFERENCES incidents(incident_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- ACCOUNT_LINKS  (caregiver <-> elder)
-- ============================================================
CREATE TABLE account_links (
    link_id           INT AUTO_INCREMENT PRIMARY KEY,
    elder_user_id     INT  NOT NULL,
    caregiver_user_id INT  NOT NULL,
    status            ENUM('pending','active','revoked') NOT NULL DEFAULT 'pending',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_link (elder_user_id, caregiver_user_id),
    FOREIGN KEY (elder_user_id)     REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (caregiver_user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- NOTIFICATIONS
-- incident_id is nullable — admin broadcasts have no incident.
-- ============================================================
CREATE TABLE notifications (
    notification_id   INT AUTO_INCREMENT PRIMARY KEY,
    incident_id       INT  NULL DEFAULT NULL,
    recipient_user_id INT  NOT NULL,
    message_text      TEXT NOT NULL,
    notification_type ENUM('high_risk','medium_risk','info','admin_action') NOT NULL DEFAULT 'info',
    is_read           TINYINT(1) NOT NULL DEFAULT 0,
    created_at        DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (incident_id)       REFERENCES incidents(incident_id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- INVOICES  (caregiver billing — one row per billing month)
-- ============================================================
CREATE TABLE invoices (
    invoice_id     INT AUTO_INCREMENT PRIMARY KEY,
    caregiver_id   INT  NOT NULL,
    billing_month  DATE NOT NULL,              -- always 1st of month, e.g. 2026-03-01
    amount_cents   INT  NOT NULL DEFAULT 0,    -- e.g. 999 = $9.99
    status         ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
    paid_at        DATETIME DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invoice (caregiver_id, billing_month),
    FOREIGN KEY (caregiver_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- SEED DATA
-- ⚠️  These placeholder hashes are NOT valid for login.
--     Run database/seed.php immediately after importing this file.
-- ============================================================
INSERT INTO users (full_name, email, password_hash, role, plan) VALUES
    ('Admin User',      'admin@eldershield.com', '$2y$12$placeholder', 'admin',     'premium'),
    ('Dorothy Johnson', 'dorothy@example.com',   '$2y$12$placeholder', 'elder',     'free'),
    ('Sarah Johnson',   'sarah@example.com',     '$2y$12$placeholder', 'caregiver', 'free');
