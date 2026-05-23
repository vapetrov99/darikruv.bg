<?php

/**
 * Proxy for NCTH Agile Store Locator – blood donation points in Bulgaria.
 * @see https://ncth.bg/contacts/
 */

return function (PDO $pdo): void {
    $payload = http_build_query(['action' => 'asl_load_stores']);

    $ch = curl_init('https://ncth.bg/wp-admin/admin-ajax.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        http_response_code(502);
        echo json_encode([
            'status' => 'error',
            'message' => 'Неуспешно зареждане на локациите от НЦТХ.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stores = json_decode($response, true);
    if (!is_array($stores)) {
        http_response_code(502);
        echo json_encode([
            'status' => 'error',
            'message' => 'Невалиден отговор от НЦТХ.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $normalized = [];
    foreach ($stores as $store) {
        if (!is_array($store) || empty($store['lat']) || empty($store['lng'])) {
            continue;
        }

        $street = html_entity_decode(strip_tags((string) ($store['street'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $city = html_entity_decode(strip_tags((string) ($store['city'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $state = html_entity_decode(strip_tags((string) ($store['state'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = html_entity_decode(strip_tags((string) ($store['title'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $description = html_entity_decode(strip_tags((string) ($store['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $normalized[] = [
            'id' => (int) ($store['id'] ?? 0),
            'title' => $title,
            'street' => $street,
            'city' => $city,
            'state' => $state,
            'address' => trim(implode(', ', array_filter([$street, $city, $state]))),
            'lat' => (float) $store['lat'],
            'lng' => (float) $store['lng'],
            'phone' => trim((string) ($store['phone'] ?? '')),
            'email' => trim((string) ($store['email'] ?? '')),
            'description' => $description,
        ];
    }

    echo json_encode([
        'status' => 'success',
        'count' => count($normalized),
        'stores' => $normalized,
        'source' => 'https://ncth.bg/contacts/',
    ], JSON_UNESCAPED_UNICODE);
    exit;
};
