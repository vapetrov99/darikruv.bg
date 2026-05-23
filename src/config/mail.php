<?php

/**
 * SMTP settings consumed by MailService (PHPMailer).
 *
 * MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS, MAIL_FROM_NAME
 * should be set in the environment (e.g. docker-compose env_file) for real mail delivery.
 */
return [
    'host' => $_ENV['MAIL_HOST'] ?? getenv('MAIL_HOST') ?: 'smtp.gmail.com',
    'port' => (int)($_ENV['MAIL_PORT'] ?? getenv('MAIL_PORT') ?: 587),
    'username' => $_ENV['MAIL_USERNAME'] ?? getenv('MAIL_USERNAME') ?: '',
    'password' => $_ENV['MAIL_PASSWORD'] ?? getenv('MAIL_PASSWORD') ?: '',
    'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? getenv('MAIL_FROM_ADDRESS') ?: '',
    'from_name' => $_ENV['MAIL_FROM_NAME'] ?? getenv('MAIL_FROM_NAME') ?: 'DariKruv',
];
