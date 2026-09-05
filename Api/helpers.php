<?php
/* ════════════════════════════════════════
   NASME GYM — Shared API Helpers
   File: api/helpers.php
   Required by every API file.
   Never accessed directly by the browser.
════════════════════════════════════════ */

require_once __DIR__ . '/../db.php';

/* ── Set JSON headers ─────────────────────────────── */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/* ── Start session once ────────────────────────────── */
if (session_status() === PHP_SESSION_NONE) {
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
