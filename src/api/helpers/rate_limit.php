<?php

/**
 * Basic DB-backed rate limiting + honeypot helpers.
 */

if (!function_exists('rate_limit_get_client_ip')) {
    function rate_limit_get_client_ip(): string
    {
        $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwardedFor !== '') {
            $first = trim(explode(',', $forwardedFor)[0]);
            if ($first !== '') {
                return $first;
            }
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        return $remoteAddr !== '' ? $remoteAddr : 'unknown';
    }
}

if (!function_exists('rate_limit_identifier')) {
    function rate_limit_identifier(string $value): string
    {
        $normalized = trim(mb_strtolower($value, 'UTF-8'));
        return $normalized !== '' ? $normalized : 'empty';
    }
}

if (!function_exists('rate_limit_honeypot_filled')) {
    function rate_limit_honeypot_filled(array $input, string $field = 'website'): bool
    {
        $value = trim((string)($input[$field] ?? ''));
        return $value !== '';
    }
}

if (!function_exists('rate_limit_check_and_hit')) {
    function rate_limit_check_and_hit(
        PDO $pdo,
        string $scope,
        string $identifier,
        int $maxAttempts,
        int $windowSeconds
    ): array {
        $identifier = rate_limit_identifier($identifier);
        $nowTs = time();
        $nowSql = date('Y-m-d H:i:s', $nowTs);

        if ($maxAttempts < 1) {
            return ['allowed' => true, 'retry_after' => 0];
        }

        $cleanupStmt = $pdo->prepare("
            DELETE FROM rate_limit_attempts
            WHERE updated_at < DATE_SUB(NOW(), INTERVAL 2 DAY)
            LIMIT 250
        ");
        $cleanupStmt->execute();

        $pdo->beginTransaction();
        try {
            $selectStmt = $pdo->prepare("
                SELECT id, window_started_at, attempts_count
                FROM rate_limit_attempts
                WHERE scope = :scope AND identifier = :identifier
                LIMIT 1
                FOR UPDATE
            ");
            $selectStmt->execute([
                ':scope' => $scope,
                ':identifier' => $identifier,
            ]);
            $row = $selectStmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $insertStmt = $pdo->prepare("
                    INSERT INTO rate_limit_attempts (scope, identifier, window_started_at, attempts_count, updated_at)
                    VALUES (:scope, :identifier, :window_started_at, 1, :updated_at)
                ");
                $insertStmt->execute([
                    ':scope' => $scope,
                    ':identifier' => $identifier,
                    ':window_started_at' => $nowSql,
                    ':updated_at' => $nowSql,
                ]);
                $pdo->commit();
                return ['allowed' => true, 'retry_after' => 0];
            }

            $windowStartTs = strtotime((string)$row['window_started_at']) ?: $nowTs;
            $elapsed = max(0, $nowTs - $windowStartTs);

            if ($elapsed >= $windowSeconds) {
                $resetStmt = $pdo->prepare("
                    UPDATE rate_limit_attempts
                    SET window_started_at = :window_started_at,
                        attempts_count = 1,
                        updated_at = :updated_at
                    WHERE id = :id
                    LIMIT 1
                ");
                $resetStmt->execute([
                    ':window_started_at' => $nowSql,
                    ':updated_at' => $nowSql,
                    ':id' => (int)$row['id'],
                ]);
                $pdo->commit();
                return ['allowed' => true, 'retry_after' => 0];
            }

            $attempts = (int)$row['attempts_count'];
            if ($attempts >= $maxAttempts) {
                $touchStmt = $pdo->prepare("
                    UPDATE rate_limit_attempts
                    SET updated_at = :updated_at
                    WHERE id = :id
                    LIMIT 1
                ");
                $touchStmt->execute([
                    ':updated_at' => $nowSql,
                    ':id' => (int)$row['id'],
                ]);
                $pdo->commit();

                return [
                    'allowed' => false,
                    'retry_after' => max(1, $windowSeconds - $elapsed),
                ];
            }

            $incrementStmt = $pdo->prepare("
                UPDATE rate_limit_attempts
                SET attempts_count = attempts_count + 1,
                    updated_at = :updated_at
                WHERE id = :id
                LIMIT 1
            ");
            $incrementStmt->execute([
                ':updated_at' => $nowSql,
                ':id' => (int)$row['id'],
            ]);
            $pdo->commit();

            return ['allowed' => true, 'retry_after' => 0];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
