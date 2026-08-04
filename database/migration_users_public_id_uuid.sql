ALTER TABLE users
    ADD COLUMN public_id CHAR(36) NULL AFTER id;

UPDATE users
SET public_id = LOWER(CONCAT(
    HEX(RANDOM_BYTES(4)), '-',
    HEX(RANDOM_BYTES(2)), '-',
    '4', SUBSTRING(HEX(RANDOM_BYTES(2)), 2, 3), '-',
    SUBSTRING('89ab', 1 + FLOOR(RAND() * 4), 1),
    SUBSTRING(HEX(RANDOM_BYTES(2)), 2, 3), '-',
    HEX(RANDOM_BYTES(6))
))
WHERE public_id IS NULL;

ALTER TABLE users
    MODIFY COLUMN public_id CHAR(36) NOT NULL;

ALTER TABLE users
    ADD UNIQUE KEY uniq_users_public_id (public_id);
