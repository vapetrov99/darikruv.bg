-- Run on existing databases after pulling the respond / waiting feature.
ALTER TABLE blood_requests
    MODIFY status ENUM('active', 'waiting', 'fulfilled', 'closed') DEFAULT 'active',
    ADD COLUMN waiting_until TIMESTAMP NULL DEFAULT NULL AFTER status;
