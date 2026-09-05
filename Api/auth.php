<?php
/* ════════════════════════════════════════
   NASME GYM — Auth API
   File: api/auth.php

   GET  api/auth.php?action=me      → who is logged in
   POST api/auth.php?action=login   → sign in
   POST api/auth.php?action=logout  → sign out
════════════════════════════════════════ */

require_once __DIR__ . '/helpers.php';

$action = $_GET['action'] ?? '';
$m      = method();

/* ── WHO AM I ─────────────────────────────────────── */
if ($action === 'me' && $m === 'GET') {
    respond(['user' => $_SESSION['user'] ?? null]);
}

/* ── LOGIN ────────────────────────────────────────── */
if ($action === 'login' && $m === 'POST') {
    $b        = body();
    $username = strtolower(trim($b['username'] ?? ''));
    $password = $b['password'] ?? '';

    if (!$username || !$password) {
        respond(['error' => 'Username and password are required.'], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        respond(['error' => 'Invalid username or password.'], 401);
    }

    /* Store minimal user info in session */
    $_SESSION['user'] = [
        'id'        => $user['id'],
        'username'  => $user['username'],
        'full_name' => $user['full_name'],
        'role'      => $user['role'],
    ];

    logAction($db, $user['id'], "User {$user['full_name']} logged in", 'success');

    respond(['success' => true, 'user' => $_SESSION['user']]);
}

/* ── LOGOUT ───────────────────────────────────────── */
if ($action === 'logout' && $m === 'POST') {
    $userId = $_SESSION['user']['id'] ?? null;
    $name   = $_SESSION['user']['full_name'] ?? 'Unknown';

    session_destroy();

    /* Log even after session destroy — use a fresh DB connection */
    if ($userId) {
        $db = getDB();
        logAction($db, $userId, "User $name logged out", 'info');
    }

    respond(['success' => true, 'message' => 'Logged out successfully.']);
}

/* ── FALLBACK ─────────────────────────────────────── */
respond(['error' => 'Invalid auth action.'], 400);
