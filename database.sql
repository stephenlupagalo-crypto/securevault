-- ============================================================
--  SecureVault – Secure File Management System
--  Database: MySQL 5.7+ / MariaDB 10+
-- ============================================================

CREATE DATABASE IF NOT EXISTS securevault
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE securevault;

-- ──────────────────────────────────────────────────────────
-- USERS
-- ──────────────────────────────────────────────────────────
CREATE TABLE users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(120)  NOT NULL,
    email         VARCHAR(180)  NOT NULL UNIQUE,
    username      VARCHAR(60)   NOT NULL UNIQUE,
    password_hash VARCHAR(255)  NOT NULL,          -- bcrypt
    role          ENUM('admin','user') NOT NULL DEFAULT 'user',
    avatar_color  VARCHAR(7)    NOT NULL DEFAULT '#4f46e5', -- hex for initials badge
    storage_used  BIGINT UNSIGNED NOT NULL DEFAULT 0,      -- bytes
    storage_quota BIGINT UNSIGNED NOT NULL DEFAULT 5368709120, -- 5 GB default
    is_active     TINYINT(1)    NOT NULL DEFAULT 1,
    last_login    DATETIME      NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ──────────────────────────────────────────────────────────
-- FILES
-- ──────────────────────────────────────────────────────────
CREATE TABLE files (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    original_name   VARCHAR(255) NOT NULL,
    stored_name     VARCHAR(255) NOT NULL UNIQUE,  -- UUID-based, on disk
    mime_type       VARCHAR(120) NOT NULL,
    file_size       BIGINT UNSIGNED NOT NULL,
    file_category   ENUM('document','image','video','audio','archive','other') NOT NULL DEFAULT 'other',
    encryption_iv   VARCHAR(64)  NOT NULL,          -- AES-256-GCM IV (hex)
    encryption_tag  VARCHAR(64)  NOT NULL,          -- GCM auth tag (hex)
    description     VARCHAR(500) NULL,
    is_deleted      TINYINT(1)   NOT NULL DEFAULT 0,
    deleted_at      DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_files_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_deleted (user_id, is_deleted),
    INDEX idx_category    (file_category)
) ENGINE=InnoDB;

-- ──────────────────────────────────────────────────────────
-- ALERTS / NOTIFICATIONS
-- ──────────────────────────────────────────────────────────
CREATE TABLE alerts (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    type        ENUM('info','warning','danger','success') NOT NULL DEFAULT 'info',
    title       VARCHAR(160) NOT NULL,
    message     TEXT         NOT NULL,
    is_read     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_alerts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_unread (user_id, is_read)
) ENGINE=InnoDB;

-- ──────────────────────────────────────────────────────────
-- ACTIVITY LOG
-- ──────────────────────────────────────────────────────────
CREATE TABLE activity_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(80)  NOT NULL,
    detail      VARCHAR(500) NULL,
    ip_address  VARCHAR(45)  NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_log_user (user_id),
    INDEX idx_log_action (action)
) ENGINE=InnoDB;

-- ──────────────────────────────────────────────────────────
-- PASSWORD RESET TOKENS
-- ──────────────────────────────────────────────────────────
CREATE TABLE password_resets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  VARCHAR(255) NOT NULL,
    expires_at  DATETIME     NOT NULL,
    used        TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ──────────────────────────────────────────────────────────
-- DEFAULT ADMIN USER  (password: Admin@1234  – change immediately)
-- ──────────────────────────────────────────────────────────
INSERT INTO users (full_name, email, username, password_hash, role, avatar_color)
VALUES (
    'System Admin',
    'admin@securevault.local',
    'admin',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- Admin@1234
    'admin',
    '#7c3aed'
);
