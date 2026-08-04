<?php

/**
 * POST register — creates user (and optional donor row), stores hashed password, sends verification email.
 * Users start with is_verified = 0 until GET verify_email is hit with the token.
 */
return static function (PDO $pdo): void {
    require_once __DIR__ . '/../helpers/rate_limit.php';

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }

        if (rate_limit_honeypot_filled($input)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid request payload'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $firstName = trim($input['first_name'] ?? '');
        $lastName = trim($input['last_name'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $phone = trim($input['phone'] ?? '');
        $city = trim($input['city'] ?? '');
        $isDonor = (bool)($input['is_donor'] ?? false);
        $acceptTerms = (bool)($input['accept_terms'] ?? false);

        $bloodType = trim($input['blood_type'] ?? '');
        $lastDonationDate = trim($input['last_donation_date'] ?? '');
        $isAvailable = isset($input['is_available']) ? (bool)$input['is_available'] : true;
        $emailNotifications = isset($input['email_notifications'])
            ? (bool)$input['email_notifications']
            : ($isDonor && $isAvailable);

        if (
            $firstName === '' ||
            $lastName === '' ||
            $email === '' ||
            $password === '' ||
            $city === ''
        ) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Липсват задължителни полета'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (!$acceptTerms) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Трябва да приемете правилата и поверителността на сайта'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $appConfig = require __DIR__ . '/../../config/app.php';
        $termsVersion = $appConfig['terms_version'] ?? '2026-05-18';
        $shouldExposeVerificationLink = (bool)($appConfig['security']['expose_verification_link_in_register_response'] ?? false);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid email format'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $ipLimit = rate_limit_check_and_hit($pdo, 'register_ip', rate_limit_get_client_ip(), 5, 900);
        if (!$ipLimit['allowed']) {
            http_response_code(429);
            echo json_encode([
                'status' => 'error',
                'message' => 'Твърде много регистрации за кратко време. Опитай отново след малко.',
                'retry_after' => (int)$ipLimit['retry_after']
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $emailLimit = rate_limit_check_and_hit($pdo, 'register_email', $email, 3, 900);
        if (!$emailLimit['allowed']) {
            http_response_code(429);
            echo json_encode([
                'status' => 'error',
                'message' => 'Твърде много регистрации с този имейл. Опитай отново след малко.',
                'retry_after' => (int)$emailLimit['retry_after']
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $hasMinLength = strlen($password) >= 8;
        $hasLowercase = preg_match('/[a-z]/', $password) === 1;
        $hasUppercase = preg_match('/[A-Z]/', $password) === 1;
        $hasDigit = preg_match('/\d/', $password) === 1;
        $isStrongPassword = $hasMinLength && $hasLowercase && $hasUppercase && $hasDigit;

        if (!$isStrongPassword) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Password must be at least 8 characters and include lowercase, uppercase, and a number'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $allowedBloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
        if ($isDonor && !in_array($bloodType, $allowedBloodTypes, true)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Valid blood type is required for donors'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $checkStmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $checkStmt->execute([':email' => $email]);
        $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingUser) {
            http_response_code(409);
            echo json_encode([
                'status' => 'error',
                'message' => 'User with this email already exists'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo->beginTransaction();

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $verificationToken = bin2hex(random_bytes(32));
        $publicId = auth_generate_uuid_v4();
        $role = $isDonor ? 'donor' : 'requester';

        $insertUserStmt = $pdo->prepare("
            INSERT INTO users (
                public_id, first_name, last_name, email, password, phone, city, role,
                is_verified, verification_token, terms_accepted_at, terms_version
            )
            VALUES (
                :public_id, :first_name, :last_name, :email, :password, :phone, :city, :role,
                :is_verified, :verification_token, :terms_accepted_at, :terms_version
            )
        ");
        $insertUserStmt->execute([
            ':public_id' => $publicId,
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':email' => $email,
            ':password' => $hashedPassword,
            ':phone' => $phone !== '' ? $phone : null,
            ':city' => $city,
            ':role' => $role,
            ':is_verified' => 0,
            ':verification_token' => $verificationToken,
            ':terms_accepted_at' => date('Y-m-d H:i:s'),
            ':terms_version' => $termsVersion
        ]);

        $userId = (int)$pdo->lastInsertId();

        $verificationLink = $appConfig['base_url'] . '/html/email-verified.html?token=' . $verificationToken;
        $mailService = new MailService();
        $mailService->sendVerificationEmail($email, $firstName . ' ' . $lastName, $verificationLink);

        if ($isDonor) {
            if ($lastDonationDate === '') {
                $lastDonationDate = null;
            }

            $insertDonorStmt = $pdo->prepare("
                INSERT INTO donors (user_id, blood_type, last_donation_date, is_available, email_notifications)
                VALUES (:user_id, :blood_type, :last_donation_date, :is_available, :email_notifications)
            ");
            $insertDonorStmt->execute([
                ':user_id' => $userId,
                ':blood_type' => $bloodType,
                ':last_donation_date' => $lastDonationDate,
                ':is_available' => $isAvailable ? 1 : 0,
                ':email_notifications' => ($isAvailable && $emailNotifications) ? 1 : 0
            ]);
        }

        $pdo->commit();

        $responseData = [
            'public_id' => $publicId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
            'city' => $city,
            'role' => $role,
            'is_donor' => $isDonor,
            'terms_accepted_at' => date('Y-m-d H:i:s'),
            'terms_version' => $termsVersion,
        ];
        if ($shouldExposeVerificationLink) {
            $responseData['verification_link'] = $verificationLink;
        }

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'User registered successfully',
            'data' => $responseData
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Registration failed',
        ], JSON_UNESCAPED_UNICODE);
    }
};
