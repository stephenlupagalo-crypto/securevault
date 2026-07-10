-- ============================================================
--  SecureVault – Migration 002: Security & Feature Upgrade
--  Run this AFTER database.sql (the original schema).
--  MySQL 5.7+ / MariaDB 10+
-- ============================================================
USE securevault;

-- ──────────────────────────────────────────────────────────
-- LOGIN ATTEMPTS  (brute-force / rate limiting)
-- ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS login_attempts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier    VARCHAR(180) NOT NULL,   -- username/email attempted
    ip_address    VARCHAR(45)  NOT NULL,
    success       TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_identifier_time (identifier, attempted_at),
    INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB;

-- ──────────────────────────────────────────────────────────
-- ACCOUNT LOCKOUT STATE
-- ──────────────────────────────────────────────────────────
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL AFTER is_active,
    ADD COLUMN IF NOT EXISTS failed_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER locked_until,
    ADD COLUMN IF NOT EXISTS phone VARCHAR(20) NULL AFTER email;

-- ──────────────────────────────────────────────────────────
-- TWO-FACTOR AUTHENTICATION
-- ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS two_factor (
    user_id            INT UNSIGNED PRIMARY KEY,
    method             ENUM('none','totp','sms') NOT NULL DEFAULT 'none',
    totp_secret        VARCHAR(64)  NULL,          -- base32 secret
    enabled            TINYINT(1)   NOT NULL DEFAULT 0,
    backup_codes       TEXT         NULL,           -- JSON array of bcrypt-hashed one-time codes
    admin_code_hash    VARCHAR(255) NULL,           -- fixed admin-issued verification code (hashed)
    admin_code_expires DATETIME     NULL,
    admin_code_note    VARCHAR(255) NULL,           -- why admin issued it (e.g. "lost phone")
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_2fa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Pending 2FA challenge issued during login (SMS OTP one-time codes)
CREATE TABLE IF NOT EXISTS two_factor_otp (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    code_hash   VARCHAR(255) NOT NULL,
    expires_at  DATETIME     NOT NULL,
    used        TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ──────────────────────────────────────────────────────────
-- FOLDERS
-- ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS folders (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    name        VARCHAR(120) NOT NULL,
    parent_id   INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_folder_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_folder_parent FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE,
    INDEX idx_folder_user (user_id)
) ENGINE=InnoDB;

ALTER TABLE files
    ADD COLUMN IF NOT EXISTS folder_id INT UNSIGNED NULL AFTER user_id,
    ADD CONSTRAINT fk_files_folder FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL;

-- Simple tags (comma-free, many-to-many)
CREATE TABLE IF NOT EXISTS tags (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name    VARCHAR(60) NOT NULL,
    CONSTRAINT fk_tag_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_tag (user_id, name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS file_tags (
    file_id INT UNSIGNED NOT NULL,
    tag_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (file_id, tag_id),
    CONSTRAINT fk_ft_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    CONSTRAINT fk_ft_tag  FOREIGN KEY (tag_id)  REFERENCES tags(id)  ON DELETE CASCADE
) ENGINE=InnoDB;

-- ──────────────────────────────────────────────────────────
-- FILE SHARING (shareable links)
-- ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS share_links (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_id        INT UNSIGNED NOT NULL,
    user_id        INT UNSIGNED NOT NULL,       -- owner who created the share
    token_hash     VARCHAR(255) NOT NULL,       -- sha256 of the public token
    password_hash  VARCHAR(255) NULL,           -- optional extra password (bcrypt)
    expires_at     DATETIME     NULL,
    max_downloads  INT UNSIGNED NULL,
    download_count INT UNSIGNED NOT NULL DEFAULT 0,
    revoked        TINYINT(1)   NOT NULL DEFAULT 0,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_share_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    CONSTRAINT fk_share_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_share_file (file_id)
) ENGINE=InnoDB;

-- ──────────────────────────────────────────────────────────
-- DOWNLOAD / ACCESS AUDIT TRAIL
-- ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS download_log (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_id     INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NULL,          -- NULL when accessed anonymously via share link
    share_id    INT UNSIGNED NULL,
    ip_address  VARCHAR(45) NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dl_file  FOREIGN KEY (file_id)  REFERENCES files(id) ON DELETE CASCADE,
    CONSTRAINT fk_dl_share FOREIGN KEY (share_id) REFERENCES share_links(id) ON DELETE SET NULL,
    INDEX idx_dl_file (file_id)
) ENGINE=InnoDB;
