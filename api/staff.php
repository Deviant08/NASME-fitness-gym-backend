<?php
require_once __DIR__ . '/helpers.php';

$user = requireAuth();
if (!in_array($user['role'] ?? '', ['admin', 'superadmin'], true)) {
    respond(['error' => 'Only an admin can manage staff.'], 403);
}

$db = getDB();
$id = getId();
$m  = method();

if ($m === 'GET') {
    $stmt = $db->query('SELECT id, username, full_name, role, created_at FROM users ORDER BY created_at DESC');
    respond(['data' => $stmt->fetchAll()]);
}

if ($m === 'POST') {
    $b = body();
    foreach (['username', 'password', 'full_name'] as $field) {
        if (empty($b[$field])) respond(['error' => "Field '$field' is required."], 400);
    }
    $username = strtolower(trim($b['username']));
    $role = $b['role'] ?? 'staff';
    if (!in_array($role, ['staff', 'admin'], true)) {
        respond(['error' => 'Role must be staff or admin.'], 400);
    }
    $dup = $db->prepare('SELECT id FROM users WHERE username = ?');
    $dup->execute([$username]);
    if ($dup->fetch()) respond(['error' => 'That username is already taken.'], 409);

    $hash = password_hash($b['password'], PASSWORD_BCRYPT);
    $db->prepare('INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)')
       ->execute([$username, $hash, $b['full_name'], $role]);
    logAction($db, $user['id'], "Staff account {$username} ({$b['full_name']}) created", 'success');
    respond(['success' => true, 'id' => $db->lastInsertId()], 201);
}

if ($m === 'PUT' && $id) {
    $b = body();
    $check = $db->prepare('SELECT * FROM users WHERE id = ?');
    $check->execute([$id]);
    $row = $check->fetch();
    if (!$row) respond(['error' => 'Staff account not found.'], 404);

    $name = $b['full_name'] ?? $row['full_name'];
    $role = $b['role'] ?? $row['role'];
    if (!in_array($role, ['staff', 'admin', 'superadmin'], true)) {
        respond(['error' => 'Invalid role.'], 400);
    }
    if (!empty($b['password'])) {
        $db->prepare('UPDATE users SET full_name = ?, role = ?, password = ? WHERE id = ?')
           ->execute([$name, $role, password_hash($b['password'], PASSWORD_BCRYPT), $id]);
    } else {
        $db->prepare('UPDATE users SET full_name = ?, role = ? WHERE id = ?')
           ->execute([$name, $role, $id]);
    }
    logAction($db, $user['id'], "Staff account {$row['username']} updated", 'info');
    respond(['success' => true]);
}

if ($m === 'DELETE' && $id) {
    if ((int)$id === (int)$user['id']) respond(['error' => 'You cannot remove your own account.'], 400);
    $check = $db->prepare('SELECT username, role FROM users WHERE id = ?');
    $check->execute([$id]);
    $row = $check->fetch();
    if (!$row) respond(['error' => 'Staff account not found.'], 404);
    if ($row['role'] === 'superadmin') respond(['error' => 'The main admin account cannot be removed.'], 403);
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    logAction($db, $user['id'], "Staff account {$row['username']} removed", 'danger');
    respond(['success' => true]);
}

respond(['error' => 'Method not allowed.'], 405);
