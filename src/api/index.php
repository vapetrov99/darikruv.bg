<?php

/**
 * Single JSON API entrypoint.
 *
 * Routing: HTTP method + query parameter ?route=<name>, e.g. POST index.php?route=login
 * Each route file returns a closure (PDO $pdo): void that reads input, runs SQL, echoes JSON.
 *
 * @see src/api/routes/ for individual handlers
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/../services/MailServices.php';
require_once __DIR__ . '/../services/NotificationService.php';

$method = $_SERVER['REQUEST_METHOD'];
$route = $_GET['route'] ?? '';
$routeKey = $method . ' ' . $route;

$handlers = [
    'GET users' => require __DIR__ . '/routes/users_list.php',
    'POST register' => require __DIR__ . '/routes/register.php',
    'GET verify_email' => require __DIR__ . '/routes/verify_email.php',
    'POST login' => require __DIR__ . '/routes/login.php',
    'POST request_password_reset' => require __DIR__ . '/routes/request_password_reset.php',
    'POST reset_password' => require __DIR__ . '/routes/reset_password.php',
    'GET donors' => require __DIR__ . '/routes/donors_list.php',
    'POST create_request' => require __DIR__ . '/routes/create_request.php',
    'POST update_request' => require __DIR__ . '/routes/update_request.php',
    'POST save_push_token' => require __DIR__ . '/routes/save_push_token.php',
    'GET push_public_config' => require __DIR__ . '/routes/push_public_config.php',
    'GET requests' => require __DIR__ . '/routes/requests_list.php',
    'GET request_details' => require __DIR__ . '/routes/request_details.php',
    'POST respond_to_request' => require __DIR__ . '/routes/respond_to_request.php',
    'GET request_comments' => require __DIR__ . '/routes/request_comments_list.php',
    'POST create_request_comment' => require __DIR__ . '/routes/create_request_comment.php',
    'POST create_campaign' => require __DIR__ . '/routes/create_campaign.php',
    'POST process_email_queue' => require __DIR__ . '/routes/process_email_queue.php',
    'POST update_last_donation' => require __DIR__ . '/routes/update_last_donation.php',
    'POST update_profile' => require __DIR__ . '/routes/update_profile.php',
    'POST delete_account' => require __DIR__ . '/routes/delete_account.php',
    'GET my_requests' => require __DIR__ . '/routes/my_requests.php',
    'GET my_responses' => require __DIR__ . '/routes/my_responses.php',
    'GET ncth_stores' => require __DIR__ . '/routes/ncth_stores.php',
];

if (!isset($handlers[$routeKey])) {
    http_response_code(404);
    echo json_encode([
        'status' => 'error',
        'message' => 'Route not found'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$handler = $handlers[$routeKey];
auth_hydrate_request_user($pdo);
$handler($pdo);
exit;
