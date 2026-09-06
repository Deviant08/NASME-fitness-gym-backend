<?php
/* ════════════════════════════════════════
   NASME GYM — Equipment API
   File: api/equipment.php

   GET    api/equipment.php          → list all equipment
   GET    api/equipment.php?id=1     → single item
   POST   api/equipment.php          → add equipment
   PUT    api/equipment.php?id=1     → update equipment
   DELETE api/equipment.php?id=1     → remove equipment
════════════════════════════════════════ */

require_once __DIR__ . '/helpers.php';

$user = requireAuth();
$db   = getDB();
$id   = getId();
$m    = method();

/* ── LIST ALL ─────────────────────────────────────── */
if ($m === 'GET' && !$id) {
    $category = $_GET['category'] ?? '';
    $status   = $_GET['status']   ?? '';

    $sql    = 'SELECT * FROM equipment WHERE 1=1';
    $params = [];

    if ($category) { $sql .= ' AND category = ?'; $params[] = $category; }
    if ($status)   { $sql .= ' AND status = ?';   $params[] = $status; }

    $sql .= ' ORDER BY name ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    respond(['data' => $stmt->fetchAll()]);
}

/* ── SINGLE ITEM ──────────────────────────────────── */
if ($m === 'GET' && $id) {
    $stmt = $db->prepare('SELECT * FROM equipment WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) respond(['error' => 'Equipment not found.'], 404);
    respond(['data' => $row]);
}

/* ── ADD EQUIPMENT ────────────────────────────────── */
if ($m === 'POST') {
    $b = body();

    if (empty($b['name'])) {
        respond(['error' => 'Equipment name is required.'], 400);
    }
    if (empty($b['category'])) {
        respond(['error' => 'Category is required.'], 400);
    }

    /* Auto-generate equipment code: EQ-01, EQ-02 … */
    $last = $db->query('SELECT eq_code FROM equipment ORDER BY id DESC LIMIT 1')->fetch();
    $next = $last ? (int)substr($last['eq_code'], 3) + 1 : 1;
    $code = 'EQ-' . str_pad($next, 2, '0', STR_PAD_LEFT);

    $stmt = $db->prepare('
        INSERT INTO equipment
            (eq_code, name, category, quantity, condition_, status, location)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $code,
        $b['name'],
        $b['category'],
        $b['quantity']   ?? 1,
        $b['condition_'] ?? 'Good',
        $b['status']     ?? 'Available',
        $b['location']   ?? 'Unassigned',
    ]);

    logAction($db, $user['id'], "Equipment $code ({$b['name']}) added to inventory", 'success');
    respond(['success' => true, 'eq_code' => $code], 201);
}

/* ── UPDATE ───────────────────────────────────────── */
if ($m === 'PUT' && $id) {
    $b = body();

    $check = $db->prepare('SELECT eq_code FROM equipment WHERE id = ?');
    $check->execute([$id]);
    $row = $check->fetch();
    if (!$row) respond(['error' => 'Equipment not found.'], 404);

    $db->prepare('
        UPDATE equipment
        SET name=?, category=?, quantity=?, condition_=?, status=?, location=?, updated_at=NOW()
        WHERE id=?
    ')->execute([
        $b['name'],
        $b['category'],
        $b['quantity']   ?? 1,
        $b['condition_'] ?? 'Good',
        $b['status']     ?? 'Available',
        $b['location']   ?? 'Unassigned',
        $id,
    ]);

    logAction($db, $user['id'], "Equipment {$row['eq_code']} ({$b['name']}) updated", 'info');
    respond(['success' => true]);
}

/* ── DELETE ───────────────────────────────────────── */
if ($m === 'DELETE' && $id) {
    $stmt = $db->prepare('SELECT eq_code, name FROM equipment WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) respond(['error' => 'Equipment not found.'], 404);

    $db->prepare('DELETE FROM equipment WHERE id = ?')->execute([$id]);

    logAction($db, $user['id'], "Equipment {$row['eq_code']} ({$row['name']}) removed", 'danger');
    respond(['success' => true]);
}

respond(['error' => 'Method not allowed.'], 405);
