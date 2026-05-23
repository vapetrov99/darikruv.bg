<?php

/**
 * Application base URL for links in emails (verification, etc.).
 * Set APP_URL in environment, e.g. http://localhost:8080
 */
return [
    'base_url' => rtrim($_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost:8080', '/'),
    /** Bump when privacy-policy.html changes materially; stored on registration. */
    'terms_version' => '2026-05-18-v2',
];
