<?php

/**
 * Lightweight JWT auth helper for API routes.
 * - Issues HS256 bearer tokens on login
 * - Validates bearer token on every request when provided
 * - Hydrates authenticated user context for route handlers
 */

if (!function_exists('auth_get_config')) {
    function auth_get_config(): array
    {
        static $config = null;
        if ($config !== null) {
            return $config;
        }

        $appConfig = require __DIR__ . '/../../config/app.php';
        $authConfig = $appConfig['auth'] ?? [];

        $config = [
            'jwt_secret' => (string)($authConfig['jwt_secret'] ?? ''),
            'jwt_ttl_seconds' => (int)($authConfig['jwt_ttl_seconds'] ?? 43200),
            'jwt_issuer' => (string)($authConfig['jwt_issuer'] ?? 'darikruv-api'),
        ];

        return $config;
    }
}

if (!function_exists('auth_is_secret_configured')) {
    function auth_is_secret_configured(): bool
    {
        $config = auth_get_config();
        return $config['jwt_secret'] !== '';
    }
}

if (!function_exists('auth_json_error')) {
    function auth_json_error(int $statusCode, string $message): void
    {
        http_response_code($statusCode);
        echo json_encode([
            'status' => 'error',
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('auth_base64url_encode')) {
    function auth_base64url_encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

if (!function_exists('auth_base64url_decode')) {
    function auth_base64url_decode(string $value): string|false
    {
        $padded = strtr($value, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding > 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($padded, true);
    }
}

if (!function_exists('auth_extract_bearer_token')) {
    function auth_extract_bearer_token(): ?string
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if (!$authHeader) {
            return null;
        }

        if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $authHeader, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }
}

if (!function_exists('auth_is_uuid_v4')) {
    function auth_is_uuid_v4(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }
}

if (!function_exists('auth_generate_uuid_v4')) {
    function auth_generate_uuid_v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}

if (!function_exists('auth_issue_token')) {
    function auth_issue_token(array $user): array
    {
        $config = auth_get_config();
        $secret = $config['jwt_secret'];
        if ($secret === '') {
            throw new RuntimeException('JWT secret is missing');
        }

        $issuedAt = time();
        $expiresAt = $issuedAt + max(60, $config['jwt_ttl_seconds']);
        $subject = (string)($user['public_id'] ?? '');
        if (!auth_is_uuid_v4($subject)) {
            // Backward compatibility if a user is missing public_id during migration.
            $subject = (string)($user['id'] ?? '');
        }

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => $config['jwt_issuer'],
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'sub' => $subject,
            'email' => (string)($user['email'] ?? ''),
            'role' => (string)($user['role'] ?? ''),
        ];

        $encodedHeader = auth_base64url_encode((string)json_encode($header, JSON_UNESCAPED_UNICODE));
        $encodedPayload = auth_base64url_encode((string)json_encode($payload, JSON_UNESCAPED_UNICODE));
        $signature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $secret, true);
        $encodedSignature = auth_base64url_encode($signature);

        return [
            'token' => $encodedHeader . '.' . $encodedPayload . '.' . $encodedSignature,
            'token_type' => 'Bearer',
            'expires_in' => $expiresAt - $issuedAt,
            'expires_at' => gmdate('c', $expiresAt),
        ];
    }
}

if (!function_exists('auth_decode_and_verify_token')) {
    function auth_decode_and_verify_token(string $token): ?array
    {
        if (!auth_is_secret_configured()) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $decodedHeader = auth_base64url_decode($encodedHeader);
        $decodedPayload = auth_base64url_decode($encodedPayload);
        $decodedSignature = auth_base64url_decode($encodedSignature);

        if ($decodedHeader === false || $decodedPayload === false || $decodedSignature === false) {
            return null;
        }

        $header = json_decode($decodedHeader, true);
        $payload = json_decode($decodedPayload, true);
        if (!is_array($header) || !is_array($payload)) {
            return null;
        }

        if (($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'JWT') {
            return null;
        }

        $config = auth_get_config();
        $expectedSignature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $config['jwt_secret'], true);
        if (!hash_equals($expectedSignature, $decodedSignature)) {
            return null;
        }

        $now = time();
        $exp = (int)($payload['exp'] ?? 0);
        $iat = (int)($payload['iat'] ?? 0);
        if ($exp <= 0 || $exp <= $now || $iat <= 0 || $iat > ($now + 60)) {
            return null;
        }

        $issuer = (string)($payload['iss'] ?? '');
        if ($issuer !== $config['jwt_issuer']) {
            return null;
        }

        $subject = (string)($payload['sub'] ?? '');
        if ($subject === '' || (!auth_is_uuid_v4($subject) && !ctype_digit($subject))) {
            return null;
        }

        return $payload;
    }
}

if (!function_exists('auth_set_current_user')) {
    function auth_set_current_user(?array $user): void
    {
        $GLOBALS['auth_user'] = $user;
    }
}

if (!function_exists('auth_current_user')) {
    function auth_current_user(): ?array
    {
        $user = $GLOBALS['auth_user'] ?? null;
        return is_array($user) ? $user : null;
    }
}

if (!function_exists('auth_require_user')) {
    function auth_require_user(): array
    {
        $user = auth_current_user();
        if (!$user) {
            auth_json_error(401, 'Authentication required');
        }

        return $user;
    }
}

if (!function_exists('auth_require_role')) {
    function auth_require_role(string $role): array
    {
        $user = auth_require_user();
        if (($user['role'] ?? '') !== $role) {
            auth_json_error(403, 'Insufficient permissions');
        }

        return $user;
    }
}

if (!function_exists('auth_hydrate_request_user')) {
    function auth_hydrate_request_user(PDO $pdo): void
    {
        auth_set_current_user(null);

        $token = auth_extract_bearer_token();
        if ($token === null) {
            return;
        }

        if (!auth_is_secret_configured()) {
            auth_json_error(500, 'Auth configuration error: APP_JWT_SECRET is missing');
        }

        $payload = auth_decode_and_verify_token($token);
        if (!$payload) {
            auth_json_error(401, 'Invalid or expired bearer token');
        }

        $subject = (string)$payload['sub'];
        if (auth_is_uuid_v4($subject)) {
            $stmt = $pdo->prepare("
                SELECT
                    u.id,
                    u.public_id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    u.phone,
                    u.city,
                    u.role,
                    u.is_verified
                FROM users u
                WHERE u.public_id = :public_id
                LIMIT 1
            ");
            $stmt->execute([':public_id' => strtolower($subject)]);
        } else {
            // Legacy token support (numeric subject).
            $userId = (int)$subject;
            $stmt = $pdo->prepare("
                SELECT
                    u.id,
                    u.public_id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    u.phone,
                    u.city,
                    u.role,
                    u.is_verified
                FROM users u
                WHERE u.id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $userId]);
        }
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || (int)($user['is_verified'] ?? 0) !== 1) {
            auth_json_error(401, 'Invalid session user');
        }

        auth_set_current_user($user);
    }
}
