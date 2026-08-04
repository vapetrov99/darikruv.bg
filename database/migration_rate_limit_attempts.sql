-- Add DB-backed rate limit storage.
CREATE TABLE IF NOT EXISTS rate_limit_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(100) NOT NULL,
    identifier VARCHAR(255) NOT NULL,
    window_started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    attempts_count INT NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_scope_identifier (scope, identifier),
    INDEX idx_updated_at (updated_at)
);
