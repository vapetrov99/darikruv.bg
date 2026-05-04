<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ .'/../services/MailServices.php';

$method = $_SERVER['REQUEST_METHOD'];
$route = $_GET['route'] ?? '';

if ($method === 'GET' && $route === 'users') {
    try {
        $stmt = $pdo->query("
            SELECT id, first_name, last_name, email, phone, city, role, created_at
            FROM users
            ORDER BY id DESC
        ");

        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'data' => $users
        ], JSON_UNESCAPED_UNICODE);

        exit;
    } catch (PDOException $e) {
        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to fetch users',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}

if ($method === 'POST' && $route === 'register') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        $firstName = trim($input['first_name'] ?? '');
        $lastName = trim($input['last_name'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $phone = trim($input['phone'] ?? '');
        $city = trim($input['city'] ?? '');
        $isDonor = (bool)($input['is_donor'] ?? false);
        $verificationToken = bin2hex(random_bytes(32)); //Mail verification

        $bloodType = trim($input['blood_type'] ?? '');
        $lastDonationDate = trim($input['last_donation_date'] ?? '');
        $isAvailable = isset($input['is_available']) ? (bool)$input['is_available'] : true;

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
                'message' => 'Required fields are missing'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);

            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid email format'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if (strlen($password) < 6) {
            http_response_code(400);

            echo json_encode([
                'status' => 'error',
                'message' => 'Password must be at least 6 characters long'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        $allowedBloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

        if ($isDonor && !in_array($bloodType, $allowedBloodTypes, true)) {
            http_response_code(400);

            echo json_encode([
                'status' => 'error',
                'message' => 'Valid blood type is required for donors'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        $checkStmt = $pdo->prepare("
            SELECT id
            FROM users
            WHERE email = :email
            LIMIT 1
        ");

        $checkStmt->execute([
            ':email' => $email
        ]);

        $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingUser) {
            http_response_code(409);

            echo json_encode([
                'status' => 'error',
                'message' => 'User with this email already exists'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        $pdo->beginTransaction();

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT); //Password hash
        $verificationToken = bin2hex(random_bytes(32)); //Code gen for the verif token
        $role = $isDonor ? 'donor' : 'requester';
        

        $insertUserStmt = $pdo->prepare("
            INSERT INTO users (first_name, last_name, email, password, phone, city, role, is_verified, verification_token)
            VALUES (:first_name, :last_name, :email, :password, :phone, :city, :role, :is_verified, :verification_token)
        ");

        $insertUserStmt->execute([
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':email' => $email,
            ':password' => $hashedPassword,
            ':phone' => $phone !== '' ? $phone : null,
            ':city' => $city,
            ':role' => $role,
            ':is_verified' => 0,
            ':verification_token' => $verificationToken
        ]);
        

        $userId = (int)$pdo->lastInsertId();

        //Cuz I don't have mail i will set this link for testing only
        $verificationLink = "http://localhost:8080/api/index.php?route=verify_email&token=" . $verificationToken;
        $mailService = new MailService();
        $mailSent = $mailService->sendVerificationEmail($email, $firstName . ' ' . $lastName, $verificationLink);
        

        if ($isDonor) {
            if ($lastDonationDate === '') {
                $lastDonationDate = null;
            }

            $insertDonorStmt = $pdo->prepare("
                INSERT INTO donors (user_id, blood_type, last_donation_date, is_available)
                VALUES (:user_id, :blood_type, :last_donation_date, :is_available)
            ");

            $insertDonorStmt->execute([
                ':user_id' => $userId,
                ':blood_type' => $bloodType,
                ':last_donation_date' => $lastDonationDate,
                ':is_available' => $isAvailable ? 1 : 0
            ]);
        }

        $pdo->commit();

        http_response_code(201);

        echo json_encode([
            'status' => 'success',
            'message' => 'User registered successfully',
            'data' => [
                'id' => $userId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'city' => $city,
                'role' => $role,
                'is_donor' => $isDonor,
                'verification_link' => $verificationLink
            ]
        ], JSON_UNESCAPED_UNICODE);

        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'message' => 'Registration failed',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}

//Mail verification 
if ($method === 'GET' && $route === 'verify_email') {
    try {
        $token = trim($_GET['token'] ?? '');

        if ($token === '') {
            http_response_code(400);

            echo json_encode([
                'status' => 'error',
                'message' => 'Verification token is required'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        $stmt = $pdo->prepare("
            SELECT id, email, is_verified
            FROM users
            WHERE verification_token = :token
            LIMIT 1
        ");

        $stmt->execute([
            ':token' => $token
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);

            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid or expired verification token'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ((int)$user['is_verified'] === 1) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Email is already verified'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        $updateStmt = $pdo->prepare("
            UPDATE users
            SET is_verified = 1,
                verification_token = NULL,
                verified_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $updateStmt->execute([
            ':id' => $user['id']
        ]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Email verified successfully'
        ], JSON_UNESCAPED_UNICODE);

        exit;

    } catch (PDOException $e) {
        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'message' => 'Email verification failed',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}

if ($method === 'POST' && $route === 'login') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';

        if ($email === '' || $password === '') {
            http_response_code(400);

            echo json_encode([
                'status' => 'error',
                'message' => 'Email and password are required'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);

            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid email format'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name, email, password, phone, city, role, created_at, is_verified
            FROM users
            WHERE email = :email
            LIMIT 1
        ");

        $stmt->execute([
            ':email' => $email
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            http_response_code(401);

            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid email or password'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        //Mail verification check
        if ((int)$user['is_verified'] !== 1) {
            http_response_code(403);

            echo json_encode([
                'status' => 'error',
                'message' => 'Please verify your email before logging in'
            ], JSON_UNESCAPED_UNICODE);

             exit;
        }

        unset($user['password']);

        echo json_encode([
            'status' => 'success',
            'message' => 'Login successful',
            'data' => $user
        ], JSON_UNESCAPED_UNICODE);

        exit;
    } catch (PDOException $e) {
        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'message' => 'Login failed',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}

if ($method === 'GET' && $route === 'donors') {
    try {
        $stmt = $pdo->query("
            SELECT
                d.id,
                d.user_id,
                d.blood_type,
                d.last_donation_date,
                d.is_available,
                d.created_at,
                u.first_name,
                u.last_name,
                u.email,
                u.phone,
                u.city,
                u.role
            FROM donors d
            INNER JOIN users u ON d.user_id = u.id
            ORDER BY d.created_at DESC
        ");

        $donors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'data' => $donors
        ], JSON_UNESCAPED_UNICODE);

        exit;
    } catch (PDOException $e) {
        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to fetch donors',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}

// POST /api/?route=create_request
// Creates a blood request after validating body fields.
if ($method === 'POST' && $route === 'create_request') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        $patientName = trim($input['patient_name'] ?? '');
        $bloodType = trim($input['blood_type'] ?? '');
        $city = trim($input['city'] ?? '');
        $hospital = trim($input['hospital'] ?? '');
        #$neededByDate = trim($input['needed_by_date'] ?? '');
        $contactName = trim($input['contact_name'] ?? '');
        $contactPhone = trim($input['contact_phone'] ?? '');
        $description = trim($input['description'] ?? '');
        $requiredUnitsCount = (int)($input['required_units_count'] ?? 1);
        // Optional foreign key to users.id (who created the request).
        $createdBy = isset($input['created_by']) ? (int)$input['created_by'] : null;

        // Required fields for creating request.
        if (
            $patientName === '' ||
            $bloodType === '' ||
            $city === '' ||
            $hospital === '' ||
            $contactName === '' ||
            $contactPhone === ''
        ) {
            http_response_code(400);

            echo json_encode([
                'status' => 'error',
                'message' => 'Required fields are missing'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        // Restrict blood type to known groups.
        $allowedBloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

        if (!in_array($bloodType, $allowedBloodTypes, true)) {
            http_response_code(400);

            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid blood type'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        // Numeric guard for units needed.
        if ($requiredUnitsCount < 1) {
            http_response_code(400);

            echo json_encode([
                'status' => 'error',
                'message' => 'Required units count must be at least 1'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

#        if ($neededByDate === '') {
#            $neededByDate = null;
#        }

        // Insert request into blood_requests table.
        $stmt = $pdo->prepare("
            INSERT INTO blood_requests (
                patient_name,
                blood_type,
                city,
                hospital,
                contact_name,
                contact_phone,
                description,
                required_units_count,
                created_by
            ) VALUES (
                :patient_name,
                :blood_type,
                :city,
                :hospital,
                :contact_name,
                :contact_phone,
                :description,
                :required_units_count,
                :created_by
            )
        ");

        $stmt->execute([
            ':patient_name' => $patientName,
            ':blood_type' => $bloodType,
            ':city' => $city,
            ':hospital' => $hospital,
            ':contact_name' => $contactName,
            ':contact_phone' => $contactPhone,
            // Empty description becomes NULL in DB.
            ':description' => $description !== '' ? $description : null,
            ':required_units_count' => $requiredUnitsCount,
            ':created_by' => $createdBy
        ]);

        http_response_code(201);

        echo json_encode([
            'status' => 'success',
            'message' => 'Blood request created successfully',
            'data' => [
                'id' => $pdo->lastInsertId(),
                'patient_name' => $patientName,
                'blood_type' => $bloodType,
                'city' => $city,
                'hospital' => $hospital,
#                'needed_by_date' => $neededByDate,
                'contact_name' => $contactName,
                'contact_phone' => $contactPhone,
                'description' => $description,
                'required_units_count' => $requiredUnitsCount,
                'fulfilled_units_count' => 0,
                'created_by' => $createdBy
            ]
        ], JSON_UNESCAPED_UNICODE);

        exit;

    } catch (PDOException $e) {
        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to create blood request',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}

// GET /api/?route=requests
// Returns all blood requests ordered by newest first.
if ($method === 'GET' && $route === 'requests') {
    try {
        $stmt = $pdo->query("
            SELECT 
                id,
                patient_name,
                blood_type,
                city,
                hospital,
                contact_name,
                contact_phone,
                description,
                status,
                required_units_count,
                fulfilled_units_count,
                created_by,
                created_at
            FROM blood_requests
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
            ORDER BY created_at DESC
        ");

        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'data' => $requests
        ], JSON_UNESCAPED_UNICODE);

        exit;

    } catch (PDOException $e) {
        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to fetch blood requests',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}

// GET /api/?route=request_details&id=123
// Returns one request by ID.
if ($method === 'GET' && $route === 'request_details') {
    try {
        // Cast to int; invalid/missing ID becomes 0.
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if ($id < 1) {
            http_response_code(400);

            echo json_encode([
                'status' => 'error',
                'message' => 'Valid request id is required'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        // Prepared query by request ID.
        $stmt = $pdo->prepare("
            SELECT
                id,
                patient_name,
                blood_type,
                city,
                hospital,
                contact_name,
                contact_phone,
                description,
                status,
                required_units_count,
                fulfilled_units_count,
                created_by,
                created_at
            FROM blood_requests
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            ':id' => $id
        ]);

        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            http_response_code(404);

            echo json_encode([
                'status' => 'error',
                'message' => 'Blood request not found'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        echo json_encode([
            'status' => 'success',
            'data' => $request
        ], JSON_UNESCAPED_UNICODE);

        exit;

    } catch (PDOException $e) {
        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to fetch blood request details',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}

// POST /api/?route=respond_to_request
// Allows donor user to respond to a specific blood request.
if ($method === 'POST' && $route === 'respond_to_request') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);

        $requestId = isset($input['request_id']) ? (int)$input['request_id'] : 0;
        $donorUserId = isset($input['donor_user_id']) ? (int)$input['donor_user_id'] : 0;

        if ($requestId < 1 || $donorUserId < 1) {
            http_response_code(400);

            echo json_encode([
                'status' => 'error',
                'message' => 'Valid request_id and donor_user_id are required'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        // 1) Verify request exists and is active.
        $requestStmt = $pdo->prepare("
            SELECT id, status
            FROM blood_requests
            WHERE id = :id
            LIMIT 1
        ");

        $requestStmt->execute([
            ':id' => $requestId
        ]);

        $request = $requestStmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            http_response_code(404);

            echo json_encode([
                'status' => 'error',
                'message' => 'Blood request not found'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($request['status'] !== 'active') {
            http_response_code(400);

            echo json_encode([
                'status' => 'error',
                'message' => 'This blood request is not active'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        // 2) Verify donor user exists.
        $userStmt = $pdo->prepare("
            SELECT id, first_name, last_name, email, role
            FROM users
            WHERE id = :id
            LIMIT 1
        ");

        $userStmt->execute([
            ':id' => $donorUserId
        ]);

        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);

            echo json_encode([
                'status' => 'error',
                'message' => 'Donor user not found'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        // 3) Prevent duplicate response from same donor to same request.
        $checkStmt = $pdo->prepare("
            SELECT id
            FROM request_responses
            WHERE request_id = :request_id AND donor_user_id = :donor_user_id
            LIMIT 1
        ");

        $checkStmt->execute([
            ':request_id' => $requestId,
            ':donor_user_id' => $donorUserId
        ]);

        $existingResponse = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingResponse) {
            http_response_code(409);

            echo json_encode([
                'status' => 'error',
                'message' => 'This donor has already responded to the request'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        // 4) Insert response with initial status "pending".
        $insertStmt = $pdo->prepare("
            INSERT INTO request_responses (
                request_id,
                donor_user_id,
                response_status
            ) VALUES (
                :request_id,
                :donor_user_id,
                :response_status
            )
        ");

        $insertStmt->execute([
            ':request_id' => $requestId,
            ':donor_user_id' => $donorUserId,
            ':response_status' => 'pending'
        ]);

        http_response_code(201);

        echo json_encode([
            'status' => 'success',
            'message' => 'Response to blood request submitted successfully',
            'data' => [
                'id' => $pdo->lastInsertId(),
                'request_id' => $requestId,
                'donor_user_id' => $donorUserId,
                'response_status' => 'pending'
            ]
        ], JSON_UNESCAPED_UNICODE);

        exit;

    } catch (PDOException $e) {
        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to respond to blood request',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}

// Fallback when no route/method combination matches above handlers.
http_response_code(404);

echo json_encode([
    'status' => 'error',
    'message' => 'Route not found'
], JSON_UNESCAPED_UNICODE);