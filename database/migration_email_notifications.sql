-- Opt-in email alerts for donors (separate from push and is_available).
ALTER TABLE donors
    ADD COLUMN email_notifications BOOLEAN NOT NULL DEFAULT FALSE AFTER is_available;
