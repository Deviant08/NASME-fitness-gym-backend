<?php
/* ════════════════════════════════════════
   NASME GYM — Members API
   File: api/members.php

   GET    api/members.php            → list all members
   GET    api/members.php?id=1       → single member
   GET    api/members.php?q=amara    → search members
   GET    api/members.php?plan=Monthly → filter by plan
   POST   api/members.php            → create member
   PUT    api/members.php?id=1       → update member
   DELETE api/members.php?id=1       → delete member
════════════════════════════════════════ */

require_once __DIR__ . '/helpers.php';

$user = requireAuth();
$db   = getDB();
$id   = getId();
$m    = method();

/* ── LIST / SEARCH ────────────────────────────────── */
if ($m === 'GET' && !$id) {
    $q      = '%' . ($_GET['q'] ?? '') . '%';
    $plan   = $_GET['plan']   ?? '';
    $status = $_GET['status'] ?? '';

    $sql    = 'SELECT * FROM members WHERE (full_name LIKE ? OR member_code LIKE ? OR phone LIKE ?)';
    $params = [$q, $q, $q];

    if ($plan)   { $sql .= ' AND plan = ?';   $params[] = $plan; }
    if ($status) { $sql .= ' AND status = ?'; $params[] = $status; }

    $sql .= ' ORDER BY created_at DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    respond(['data' => $rows, 'total' => count($rows)]);
}

/* ── SINGLE MEMBER ────────────────────────────────── */
if ($m === 'GET' && $id) {
    $stmt = $db->prepare('SELECT * FROM members WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) respond(['error' => 'Member not found.'], 404);
    respond(['data' => $row]);
}

/* ── CREATE ───────────────────────────────────────── */
if ($m === 'POST') {
    $b = body();

    /* Validate required fields */
    foreach (['full_name', 'phone', 'plan'] as $field) {
        if (empty($b[$field])) {
            respond(['error' => "Field '$field' is required."], 400);
        }
    }

    /* Duplicate check — same name + phone */
    $dup = $db->prepare('SELECT id FROM members WHERE full_name = ? AND phone = ?');
    $dup->execute([$b['full_name'], $b['phone']]);
    if ($dup->fetch()) {
        respond(['error' => 'A member with this name and phone number already exists.'], 409);
    }

    /* Auto-generate member code: MBR-001, MBR-002 … */
    $last = $db->query('SELECT member_code FROM members ORDER BY id DESC LIMIT 1')->fetch();
    $next = $last ? (int)substr($last['member_code'], 4) + 1 : 1;
    $code = 'MBR-' . str_pad($next, 3, '0', STR_PAD_LEFT);

    $stmt = $db->prepare('
        INSERT INTO members
            (member_code, full_name, phone, email, date_of_birth, gender,
             plan, status, emergency_contact, fitness_goal, medical_notes, joined_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, "Active", ?, ?, ?, CURDATE())
    ');
    $stmt->execute([
        $code,
        $b['full_name'],
        $b['phone'],
        $b['email']             ?? null,
        $b['date_of_birth']     ?? null,
        $b['gender']            ?? null,
        $b['plan'],
        $b['emergency_contact'] ?? null,
        $b['fitness_goal']      ?? null,
        $b['medical_notes']     ?? null,
    ]);

    $newId = $db->lastInsertId();
    logAction($db, $user['id'], "Member $code ({$b['full_name']}) registered", 'success');

    respond(['success' => true, 'member_code' => $code, 'id' => $newId], 201);
}

/* ── UPDATE ───────────────────────────────────────── */
if ($m === 'PUT' && $id) {
    $b = body();

    /* Confirm member exists */
    $check = $db->prepare('SELECT id FROM members WHERE id = ?');
    $check->execute([$id]);
    if (!$check->fetch()) respond(['error' => 'Member not found.'], 404);

    $stmt = $db->prepare('
        UPDATE members
        SET full_name=?, phone=?, email=?, plan=?, status=?,
            fitness_goal=?, medical_notes=?, updated_at=NOW()
        WHERE id=?
    ');
    $stmt->execute([
        $b['full_name']    ?? '',
        $b['phone']        ?? '',
        $b['email']        ?? '',
        $b['plan']         ?? 'Monthly',
        $b['status']       ?? 'Active',
        $b['fitness_goal'] ?? '',
        $b['medical_notes'] ?? '',
        $id,
    ]);

    logAction($db, $user['id'], "Member ID $id ({$b['full_name']}) updated", 'info');
    respond(['success' => true]);
}

/* ── DELETE ───────────────────────────────────────── */
if ($m === 'DELETE' && $id) {
    $stmt = $db->prepare('SELECT member_code, full_name FROM members WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) respond(['error' => 'Member not found.'], 404);

    /* Remove member (payments/orders will be blocked by FK — intentional) */
    $db->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);

    logAction($db, $user['id'], "Member {$row['member_code']} ({$row['full_name']}) deleted", 'danger');
    respond(['success' => true]);
}

respond(['error' => 'Method not allowed.'], 405);
