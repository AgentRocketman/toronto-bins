-- Domain Name Generator Database Schema

CREATE DATABASE IF NOT EXISTS domain_generator CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE domain_generator;

-- Generation queue table
CREATE TABLE IF NOT EXISTS generation_queue (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    generation_id VARCHAR(32) NOT NULL UNIQUE,
    session_id VARCHAR(32) NOT NULL,
    description TEXT NOT NULL,
    tld VARCHAR(10) NOT NULL,
    status ENUM('pending', 'processing', 'complete', 'error') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    INDEX idx_session (session_id),
    INDEX idx_generation (generation_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generated domains table
CREATE TABLE IF NOT EXISTS generated_domains (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    generation_id VARCHAR(32) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    status ENUM('pending', 'available', 'taken', 'error') DEFAULT 'pending',
    checked_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_generation (generation_id),
    INDEX idx_domain (domain),
    FOREIGN KEY (generation_id) REFERENCES generation_queue(generation_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Domain availability cache table
CREATE TABLE IF NOT EXISTS domain_cache (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL UNIQUE,
    status ENUM('available', 'taken', 'error') NOT NULL,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_domain (domain),
    INDEX idx_checked (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Favorites table (optional - for server-side storage)
CREATE TABLE IF NOT EXISTS favorites (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(32) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_favorite (session_id, domain),
    INDEX idx_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cleanup old generations (optional stored procedure for maintenance)
DELIMITER $$

CREATE PROCEDURE IF NOT EXISTS cleanup_old_data()
BEGIN
    -- Delete generations older than 7 days
    DELETE FROM generation_queue
    WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY);

    -- Delete cache entries older than 24 hours
    DELETE FROM domain_cache
    WHERE checked_at < DATE_SUB(NOW(), INTERVAL 24 HOUR);
END$$

DELIMITER ;

-- Optional: Create event to run cleanup daily
-- Uncomment if you want automatic cleanup
-- SET GLOBAL event_scheduler = ON;
-- CREATE EVENT IF NOT EXISTS daily_cleanup
-- ON SCHEDULE EVERY 1 DAY
-- STARTS CURRENT_DATE + INTERVAL 1 DAY
-- DO CALL cleanup_old_data();
