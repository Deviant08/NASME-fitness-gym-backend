<?php
/* ════════════════════════════════════════
   NASME GYM — Audit Log API
   File: api/audit.php

   GET api/audit.php          → last 50 log entries
   GET api/audit.php?limit=20 → custom number of entries
════════════════════════════════════════ */

require_once __DIR__ . '/helpers.php';

requireAuth();
$db = getDB();

if (method() !== 'GET') {
    respond(['error' => 'Method not allowed.'], 405);
}

$limit = min((int)($_GET['limit'] ?? 50), 200); // cap at 200

$stmt = $db->prepare('
    SELECT
        a.id,
        a.action,
        a.color_tag,
        a.logged_at,
        u.full_name AS by_user,
        u.role      AS by_role
    FROM audit_log a
    LEFT JOIN users u ON a.user_id = u.id
    ORDER BY a.logged_at DESC
    LIMIT ?
');
$stmt->execute([$limit]);

respond([
    'data'  => $stmt->fetchAll(),
    'limit' => $limit,
]);
