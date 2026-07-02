-- ============================================================
--  HCP Monitor — MySQL schema (PHP 8 + PDO)
--  Ishlatish: mysql -u root -p < schema.sql
-- ============================================================

-- 1) DB va foydalanuvchi yaratish (root sifatida ishlatiladi)
CREATE DATABASE IF NOT EXISTS hcp_monitor
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'hcp_user'@'localhost' IDENTIFIED BY 'your_password_here';
GRANT ALL PRIVILEGES ON hcp_monitor.* TO 'hcp_user'@'localhost';
FLUSH PRIVILEGES;

USE hcp_monitor;

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS branches (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(200) NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(200) DEFAULT '',
    role          VARCHAR(50) DEFAULT 'branch_admin',
    is_active     TINYINT(1) DEFAULT 1,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_branches (
    user_id   INT NOT NULL,
    branch_id INT NOT NULL,
    PRIMARY KEY (user_id, branch_id),
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS nvrs (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    hik_code       VARCHAR(200) NOT NULL UNIQUE,
    name           VARCHAR(200) DEFAULT '',
    ip             VARCHAR(50)  DEFAULT '',
    branch_id      INT NULL,
    name_overridden TINYINT(1) DEFAULT 0,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cameras (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    hik_code           VARCHAR(200) NOT NULL UNIQUE,
    name               VARCHAR(200) DEFAULT '',
    channel_ip         VARCHAR(50)  DEFAULT '',
    nvr_id             INT NULL,
    current_status     INT DEFAULT 0,
    last_status_change DATETIME DEFAULT CURRENT_TIMESTAMP,
    name_overridden    TINYINT(1) DEFAULT 0,
    FOREIGN KEY (nvr_id) REFERENCES nvrs(id) ON DELETE SET NULL,
    INDEX idx_cam_nvr (nvr_id),
    INDEX idx_cam_status (current_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS camera_events (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    camera_id    INT NOT NULL,
    event_type   VARCHAR(20) NOT NULL,
    started_at   DATETIME NOT NULL,
    ended_at     DATETIME NULL,
    duration_sec FLOAT NULL,
    FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE CASCADE,
    INDEX idx_ev_started (started_at),
    INDEX idx_ev_camera (camera_id),
    INDEX idx_ev_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_nvrs (
    user_id INT NOT NULL,
    nvr_id  INT NOT NULL,
    PRIMARY KEY (user_id, nvr_id),
    FOREIGN KEY (user_id) REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (nvr_id)  REFERENCES nvrs(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
