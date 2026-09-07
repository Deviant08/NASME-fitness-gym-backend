<?php
require_once __DIR__ . '/../db.php';

function corsOrigin(): ?string {
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = [
        'https://nasme-fitness-gym-sigma.vercel.app',
        'http://localhost:5500',
        'http://127.0.0.1:5500',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://localhost',
        'http://127.0.0.1',
    ];
    if (in_array($origin, $allowed, true)) return $origin;
    if (preg_match('#^https://nasme-fitness-gym[a-z0-9-]*\.vercel\.app$#', $origin)) return $origin;
    return null;
}

header('Content-Type: application/json');
header('Vary: Origin');
$origin = corsOrigin();
if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => $secure ? 'None' : 'Lax',
    ]);
    session_start();
}

function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function requireAuth(): array {
    if (empty($_SESSION['user'])) respond(['error' => 'Unauthorised. Please log in.'], 401);
    return $_SESSION['user'];
}

function body(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

function logAction(PDO $db, ?int $userId, string $action, string $color = 'info'): void {
    $stmt = $db->prepare('INSERT INTO audit_log (user_id, action, color_tag) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $action, $color]);
}

function getId(): ?int {
    return isset($_GET['id']) ? (int)$_GET['id'] : null;
}

function method(): string {
    return $_SERVER['REQUEST_METHOD'];
}

function nextCode(PDO $db, string $table, string $column, string $prefix, int $width = 3): string {
    $stmt = $db->query("SELECT $column FROM $table ORDER BY id DESC LIMIT 1");
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last && preg_match('/(\d+)$/', (string)$last, $match)) {
        $next = (int)$match[1] + 1;
    }
    return $prefix . str_pad((string)$next, $width, '0', STR_PAD_LEFT);
}
