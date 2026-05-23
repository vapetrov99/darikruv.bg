-- Add password reset support to users table.
ALTER TABLE users
    ADD COLUMN password_reset_token VARCHAR(255) NULL DEFAULT NULL AFTER verification_token,
    ADD COLUMN password_reset_expires_at TIMESTAMP NULL DEFAULT NULL AFTER password_reset_token,
    ADD COLUMN password_reset_requested_at TIMESTAMP NULL DEFAULT NULL AFTER password_reset_expires_at;
