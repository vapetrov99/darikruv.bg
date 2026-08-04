<?php

/**
 * Application base URL for links in emails (verification, etc.).
 * Set APP_URL in environment, e.g. http://localhost:8080
 */
return [
    'base_url' => rtrim($_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost:8080', '/'),
    /** Bump when privacy-policy.html changes materially; stored on registration. */
    'terms_version' => '2026-05-18-v2',
    'security' => [
        /**
         * Keep false in production. Enable only for local/manual testing.
         * Example: APP_EXPOSE_VERIFICATION_LINK=1
         */
        'expose_verification_link_in_register_response' => filter_var(
            $_ENV['APP_EXPOSE_VERIFICATION_LINK'] ?? getenv('APP_EXPOSE_VERIFICATION_LINK') ?: '0',
            FILTER_VALIDATE_BOOLEAN
        ),
    ],
    'auth' => [
        /**
         * Use a strong random value in production.
         * Example: APP_JWT_SECRET=<64+ random chars>
         */
        'jwt_secret' => $_ENV['APP_JWT_SECRET'] ?? getenv('APP_JWT_SECRET') ?: '',
        'jwt_ttl_seconds' => (int)($_ENV['APP_JWT_TTL_SECONDS'] ?? getenv('APP_JWT_TTL_SECONDS') ?: 43200),
        'jwt_issuer' => $_ENV['APP_JWT_ISSUER'] ?? getenv('APP_JWT_ISSUER') ?: 'darikruv-api',
    ],
];
