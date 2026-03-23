<?php

// All responses from this file are JSON (UTF-8), regardless of route.
header('Content-Type: application/json; charset=utf-8');

// Creates $pdo (PDO connection object) from src/config/database.php.
require_once __DIR__ . '/../config/database.php';

// Basic router inputs:
// - $method comes from HTTP verb (GET/POST/...).
// - $route comes from query string: /api/index.php?route=...
$method = $_SERVER['REQUEST_METHOD'];
$route = $_GET['route'] ?? '';

// GET /api/?route=users
// Returns a list of users ordered by newest first.
if ($method === 'GET' && $route === 'users') {
    try {
        // Direct SELECT query (no external parameters in this query).
        $stmt = $pdo->query("
            SELECT id, first_name, last_name, email, phone, role, created_at
            FROM users
            ORDER BY id DESC
        ");

        // fetchAll() returns an array of associative arrays:
        // [ ['id' => ..., 'first_name' => ...], ... ]
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'data' => $users
        ], JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to fetch users',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

// POST /api/?route=register
// Reads JSON body, validates input, creates a new user, returns created user info.
if ($method === 'POST' && $route === 'register') {
    try {
        // php://input = raw request body; json_decode(..., true) => associative array.
        $input = json_decode(file_get_contents('php://input'), true);

        // Normalize input:
        // - trim() removes extra spaces around text fields.
        // - ?? sets default value when key is missing.
        $firstName = trim($input['first_name'] ?? '');
        $lastName = trim($input['last_name'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $phone = trim($input['phone'] ?? '');
        $role = trim($input['role'] ?? 'donor');

        // Required field validation.
        if (
            $firstName === '' ||
            $lastName === '' ||
            $email === '' ||
            $password === ''
        ) {
            http_response_code(400);

            echo json_encode([
                'status' => 'error',
                'message' => 'Required fields are missing'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        // Validate email format.
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);

            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid email format'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        // Simple password length rule.
        if (strlen($password) < 6) {
            http_response_code(400);

            echo json_encode([
                'status' => 'error',
                'message' => 'Password must be at least 6 characters long'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        // Restrict role to known values.
        $allowedRoles = ['admin', 'donor', 'requester'];

        if (!in_array($role, $allowedRoles, true)) {
            http_response_code(400);

            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid role'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        // Prepared statement + named parameter (:email) to safely query by email.
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $checkStmt->execute([':email' => $email]);
        $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);

        // Conflict if email already exists.
        if ($existingUser) {
            http_response_code(409);

            echo json_encode([
                'status' => 'error',
                'message' => 'User with this email already exists'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        // Store hashed password, never plain text.
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Insert user record.
        $insertStmt = $pdo->prepare("
            INSERT INTO users (first_name, last_name, email, password, phone, role)
            VALUES (:first_name, :last_name, :email, :password, :phone, :role)
        ");

        $insertStmt->execute([
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':email' => $email,
            ':password' => $hashedPassword,
            ':phone' => $phone !== '' ? $phone : null,
            ':role' => $role
        ]);

        http_response_code(201);

        echo json_encode([
            'status' => 'success',
            'message' => 'User registered successfully',
            'data' => [
                // lastInsertId() is the auto-generated ID of the inserted row.
                'id' => $pdo->lastInsertId(),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'role' => $role
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        http_response_code(500);

        echo json_encode([
            'status' => 'error',
            'message' => 'Registration failed',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

// POST /api/?route=login
// Validates credentials and returns user data (without password hash).
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

        // Find user by email (at most one due to unique email constraint).
        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name, email, password, phone, role, created_at
            FROM users
            WHERE email = :email
            LIMIT 1
        ");

        $stmt->execute([
            ':email' => $email
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(401);

            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid email or password'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        // Compare plain password with hashed password from DB.
        if (!password_verify($password, $user['password'])) {
            http_response_code(401);

            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid email or password'
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        // Remove password hash before returning user object.
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
                required_donors_count,
                created_by,
                created_at
            FROM blood_requests
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