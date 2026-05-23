-- Run on existing databases: store terms acceptance per user.
ALTER TABLE users
    ADD COLUMN terms_accepted_at TIMESTAMP NULL DEFAULT NULL AFTER verified_at,
    ADD COLUMN terms_version VARCHAR(32) NULL DEFAULT NULL AFTER terms_accepted_at;
