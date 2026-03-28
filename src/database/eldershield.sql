-- ============================================================
-- ElderShield — Complete Database Schema (Simplified)
-- Single file. Run once. No separate migrations needed.
-- ============================================================
--
-- ⚠️  IMPORTANT SETUP INSTRUCTIONS — READ BEFORE IMPORTING
-- ============================================================
-- Minimum requirements:
--     MySQL 5.7.8+ or MariaDB 10.2.7+
--     (Required for JSON column type used in the analysis table)
--     MAMP ships with MySQL 5.7+ by default from v4 onwards.
--     WAMP ships with MySQL 5.7+ by default from v3.1 onwards.
--     Check your version in phpMyAdmin: Home → Server → Variables → version
--
-- Step 1: Import this file into MySQL to create the schema.
--         In phpMyAdmin: Import > choose this file > Go
--         In terminal:   mysql -u root -p < eldershield.sql
--
-- Step 2: Optionally run seed.php to populate full demo data
--         (incidents, caregiver links, analytics data).
--         Run from CLI: php seed.php
--
-- Default login credentials (three starter accounts below):
--     Admin:     admin@eldershield.com  / password: password123
--     Elder:     dorothy@example.com   / password: password123
--     Caregiver: sarah@example.com     / password: password123
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
-- Requires MySQL 5.7.8+ or MariaDB 10.2.7+ for JSON column type.
-- ai_error is NULL on success; populated with a short reason string
-- when Ollama is unreachable or returns an unreadable response.
-- ============================================================
CREATE TABLE analysis (
    incident_id        INT          NOT NULL PRIMARY KEY, -- 1-to-1 with incidents
    scam_probability   TINYINT      NOT NULL DEFAULT 0,   -- 0-100, TINYINT saves space
    scam_category      VARCHAR(50)  DEFAULT NULL,
    manipulation_tactics JSON       DEFAULT NULL,         -- requires MySQL 5.7.8+
    explanation_simple TEXT         DEFAULT NULL,
    recommended_action TEXT         DEFAULT NULL,
    ai_error           VARCHAR(500) DEFAULT NULL,         -- NULL = success; set on Ollama failure
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
-- SEED DATA — Three starter accounts (password: password123)
-- Real bcrypt hashes — login works immediately after import.
-- Run database/seed.php via CLI to add full demo data.
-- ============================================================
INSERT INTO users (full_name, email, password_hash, role, plan) VALUES
    ('Admin User',      'admin@eldershield.com', '$2y$12$/4HX6Nca5rY5pFPFsvl7e.DySLjawiG4yQeqZ5PTHpvfEbJG.DaxS', 'admin',     'premium'),
    ('Dorothy Johnson', 'dorothy@example.com',   '$2y$12$pkik9Xmi6VWnycaZyQH0/eP1tqrVT8uvIX2Pxz94fWW6F81Lolce.', 'elder',     'free'),
    ('Sarah Johnson',   'sarah@example.com',     '$2y$12$6O8f3sn5ZxWKuK.uYOyz/e/M4frnfv4uPAnaj4XqL9OOlLdNrl896', 'caregiver', 'free');
