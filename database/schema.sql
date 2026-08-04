CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    public_id CHAR(36) NOT NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    city VARCHAR(100) NOT NULL,
    role ENUM('admin', 'donor', 'requester') DEFAULT 'requester',
    is_verified BOOLEAN DEFAULT FALSE, /*Mail verif*/
    verification_token VARCHAR(255) NULL,
    password_reset_token VARCHAR(255) NULL,
    password_reset_expires_at TIMESTAMP NULL,
    password_reset_requested_at TIMESTAMP NULL,
    verified_at TIMESTAMP NULL,
    terms_accepted_at TIMESTAMP NULL,
    terms_version VARCHAR(32) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE donors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    blood_type ENUM('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-') NOT NULL,
    last_donation_date DATE NULL,
    is_available BOOLEAN DEFAULT TRUE,
    email_notifications BOOLEAN DEFAULT FALSE,
    campaign_email_notifications BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE blood_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_name VARCHAR(150) NOT NULL,
    blood_type ENUM('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-') NOT NULL,
    city VARCHAR(100) NOT NULL,
    hospital VARCHAR(150) NOT NULL,
    contact_name VARCHAR(150) NOT NULL,
    contact_phone VARCHAR(20) NOT NULL,
    description TEXT NULL,
    status ENUM('active', 'waiting', 'fulfilled', 'closed') DEFAULT 'active',
    waiting_until TIMESTAMP NULL,
    required_units_count INT NOT NULL DEFAULT 1,
    fulfilled_units_count INT NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE request_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    donor_user_id INT NOT NULL,
    response_status ENUM('pending', 'confirmed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES blood_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (donor_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE request_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    author_name VARCHAR(150) NOT NULL,
    comment_text TEXT NOT NULL,
    is_donor BOOLEAN DEFAULT FALSE,
    contact_phone VARCHAR(20) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES blood_requests(id) ON DELETE CASCADE
);

CREATE TABLE donor_push_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    donor_user_id INT NOT NULL,
    fcm_token VARCHAR(255) NOT NULL UNIQUE,
    is_active BOOLEAN DEFAULT TRUE,
    last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (donor_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE notification_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    donor_user_id INT NOT NULL,
    channel ENUM('push', 'email') NOT NULL,
    status ENUM('sent', 'failed') NOT NULL,
    error_message VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES blood_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (donor_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE email_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    donor_user_id INT NOT NULL,
    to_email VARCHAR(255) NOT NULL,
    to_name VARCHAR(255) NOT NULL,
    blood_type ENUM('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-') NOT NULL,
    city VARCHAR(100) NOT NULL,
    hospital VARCHAR(150) NOT NULL,
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    next_attempt_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    last_error VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_request_donor (request_id, donor_user_id),
    INDEX idx_queue_next_attempt (status, next_attempt_at),
    FOREIGN KEY (request_id) REFERENCES blood_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (donor_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE rate_limit_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(100) NOT NULL,
    identifier VARCHAR(255) NOT NULL,
    window_started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    attempts_count INT NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_scope_identifier (scope, identifier),
    INDEX idx_updated_at (updated_at)
);
