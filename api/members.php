<?php
require_once __DIR__ . '/helpers.php';

$user = requireAuth();
$db   = getDB();
$id   = getId();
$m    = method();

try { $db->exec("ALTER TABLE members MODIFY status ENUM('Active','Expired','Pending','Suspended','Archived') NOT NULL DEFAULT 'Active'"); } catch (Throwable $e) {}
try { $db->exec('ALTER TABLE members ADD COLUMN archive_reason TEXT NULL'); } catch (Throwable $e) {}
try { $db->exec('ALTER TABLE members ADD COLUMN archived_at DATETIME NULL'); } catch (Throwable $e) {}

function nextMemberCode(PDO $db): string {
    $last = $db->query('SELECT member_code FROM members ORDER BY id DESC LIMIT 1')->fetch();
    $next = 1;
    if ($last && preg_match('/(\d+)$/', $last['member_code'], $match)) {
        $next = (int)$match[1] + 1;
    }
    return 'MBR-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

function archiveMember(PDO $db, array $user, int $id, string $reason): void {
    $reason = trim($reason);
    if ($reason === '') respond(['error' => 'Please enter a reason for archiving this member.'], 400);
    $check = $db->prepare('SELECT * FROM members WHERE id = ?');
    $check->execute([$id]);
    $current = $check->fetch();
    if (!$current) respond(['error' => 'Member not found.'], 404);
    if ($current['status'] === 'Archived') respond(['error' => 'This member is already archived.'], 409);
    $db->prepare('UPDATE members SET status = "Archived", archive_reason = ?, archived_at = NOW(), updated_at = NOW() WHERE id = ?')
       ->execute([$reason, $id]);
    logAction($db, $user['id'], "Member {$current['member_code']} ({$current['full_name']}) archived: $reason", 'danger');
    respond(['success' => true]);
}

if ($m === 'GET' && !$id) {
    $q      = '%' . ($_GET['q'] ?? '') . '%';
    $plan   = $_GET['plan']   ?? '';
    $status = $_GET['status'] ?? '';
    $sql    = 'SELECT * FROM members WHERE (full_name LIKE ? OR member_code LIKE ? OR phone LIKE ?)';
    $params = [$q, $q, $q];
    if ($status) {
        $sql .= ' AND status = ?';
        $params[] = $status;
    } else {
        $sql .= ' AND status <> "Archived"';
    }
    if ($plan) { $sql .= ' AND plan = ?'; $params[] = $plan; }
    $sql .= ' ORDER BY created_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    respond(['data' => $rows, 'total' => count($rows)]);
}

if ($m === 'GET' && $id) {
    $stmt = $db->prepare('SELECT * FROM members WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) respond(['error' => 'Member not found.'], 404);
    respond(['data' => $row]);
}

if ($m === 'POST') {
    $b = body();
    if (!empty($b['archive']) && !empty($b['id'])) {
        archiveMember($db, $user, (int)$b['id'], $b['archive_reason'] ?? '');
    }
    foreach (['full_name', 'phone', 'plan'] as $field) {
        if (empty($b[$field])) respond(['error' => "Field '$field' is required."], 400);
    }
    $dup = $db->prepare('SELECT id FROM members WHERE full_name = ? AND phone = ?');
    $dup->execute([$b['full_name'], $b['phone']]);
    if ($dup->fetch()) respond(['error' => 'A member with this name and phone number already exists.'], 409);
    $code = nextMemberCode($db);
    $stmt = $db->prepare('INSERT INTO members (member_code, full_name, phone, email, date_of_birth, gender, plan, status, emergency_contact, fitness_goal, medical_notes, joined_at) VALUES (?, ?, ?, ?, ?, ?, ?, "Active", ?, ?, ?, CURDATE())');
    $stmt->execute([
        $code, $b['full_name'], $b['phone'], $b['email'] ?? null, $b['date_of_birth'] ?? null,
        $b['gender'] ?? null, $b['plan'], $b['emergency_contact'] ?? null,
        $b['fitness_goal'] ?? null, $b['medical_notes'] ?? null,
    ]);
    logAction($db, $user['id'], "Member $code ({$b['full_name']}) registered", 'success');
    respond(['success' => true, 'member_code' => $code, 'id' => $db->lastInsertId()], 201);
}

if ($m === 'PUT' && $id) {
    $b = body();
    if (!empty($b['archive']) || (($b['status'] ?? '') === 'Archived')) {
        archiveMember($db, $user, $id, $b['archive_reason'] ?? '');
    }
    $check = $db->prepare('SELECT * FROM members WHERE id = ?');
    $check->execute([$id]);
    $current = $check->fetch();
    if (!$current) respond(['error' => 'Member not found.'], 404);
    $allowed = ['full_name','phone','email','date_of_birth','gender','plan','status','emergency_contact','fitness_goal','medical_notes','checkins'];
    $sets = []; $params = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $b)) { $sets[] = "$field = ?"; $params[] = $b[$field]; }
    }
    if (!$sets) respond(['error' => 'No fields to update.'], 400);
    $params[] = $id;
    $db->prepare('UPDATE members SET ' . implode(', ', $sets) . ', updated_at=NOW() WHERE id=?')->execute($params);
    $label = $b['full_name'] ?? $current['full_name'];
    logAction($db, $user['id'], "Member {$current['member_code']} ($label) updated", 'info');
    respond(['success' => true]);
}

if ($m === 'DELETE' && $id) {
    $b = body();
    archiveMember($db, $user, $id, $b['archive_reason'] ?? ($_GET['reason'] ?? ''));
}

respond(['error' => 'Method not allowed.'], 405);
