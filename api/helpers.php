<?php
/* ════════════════════════════════
   NASME GYM — Shared API Helpers
   File: api/helpers.php
   Required by every API file.
   Never accessed directly by the browser.
════════════════════════════════ */

require_once __DIR__ . '/../db.php';

/* ── CORS for Vercel frontend + local dev ─────────── */
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
    if (in_array($origin, $allowed, true)) {
        return $origin;
    }
    /* Vercel preview deployments for this project */
    if (preg_match('#^https://nasme-fitness-gym[a-z0-9-]*\\.vercel\\.app$#', $origin)) {
        return $origin;
    }
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

/* ── Session cookie must work Vercel → Railway (cross-site) ── */
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

/* ── Send a JSON response and stop execution ────────── */
function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/* ── Guard: require a logged-in session ─────────────── */
function requireAuth(): array {
    if (empty($_SESSION['user'])) {
        respond(['error' => 'Unauthorised. Please log in.'], 401);
    }
    return $_SESSION['user'];
}

/* ── Read and decode JSON request body ──────────────── */
function body(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

/* ── Write a row to the audit_log table ─────────────── */
function logAction(PDO $db, ?int $userId, string $action, string $color = 'info'): void {
    $stmt = $db->prepare(
        'INSERT INTO audit_log (user_id, action, color_tag) VALUES (?, ?, ?)'
    );
    $stmt->execute([$userId, $action, $color]);
}

/* ── Parse ?id= from query string ───────────────────── */
function getId(): ?int {
    return isset($_GET['id']) ? (int)$_GET['id'] : null;
}

/* ── Current HTTP method ─────────────────────────────── */
function method(): string {
    return $_SERVER['REQUEST_METHOD'];
}
