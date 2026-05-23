ALTER TABLE donors
    ADD COLUMN campaign_email_notifications BOOLEAN NOT NULL DEFAULT FALSE AFTER email_notifications;

