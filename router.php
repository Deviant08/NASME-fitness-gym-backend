<?php
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = $uri ? urldecode($uri) : '/';

if ($uri === '/' || $uri === '/health' || $uri === '/health/') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['ok' => true, 'service' => 'NASME GYM API']);
    return true;
}

$blocked = [
    '/api/helpers.php',
    '/db.php',
    '/.env',
    '/nginx.conf',
    '/start.sh',
    '/Dockerfile',
    '/router.php',
];
if (in_array($uri, $blocked, true) || str_starts_with($uri, '/.git')) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']);
    return true;
}

$file = __DIR__ . $uri;
if (is_file($file)) {
    return false;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'Not found']);
return true;
