<?php
require_once __DIR__ . '/helpers.php';

$user = requireAuth();
$db   = getDB();
$id   = getId();
$m    = method();

try { $db->exec("ALTER TABLE members MODIFY status ENUM('Active','Expired','Pending','Suspended','Archived') NOT NULL DEFAULT 'Active'"); } catch (Throwable $e) {}
foreach ([
    'ALTER TABLE members ADD COLUMN archive_reason TEXT NULL',
    'ALTER TABLE members ADD COLUMN archived_at DATETIME NULL',
    'ALTER TABLE members ADD COLUMN age INT NULL',
    'ALTER TABLE members ADD COLUMN address TEXT NULL',
    'ALTER TABLE members ADD COLUMN emergency_name VARCHAR(120) NULL',
    'ALTER TABLE members ADD COLUMN emergency_phone VARCHAR(20) NULL',
    'ALTER TABLE members ADD COLUMN emergency_relationship VARCHAR(80) NULL',
    'ALTER TABLE members ADD COLUMN has_medical_condition VARCHAR(10) NULL',
    'ALTER TABLE members ADD COLUMN has_previous_injury VARCHAR(10) NULL',
    'ALTER TABLE members ADD COLUMN previous_injury_details TEXT NULL',
    'ALTER TABLE members ADD COLUMN taking_medication VARCHAR(10) NULL',
    'ALTER TABLE members ADD COLUMN previous_gym_experience VARCHAR(10) NULL',
    'ALTER TABLE members ADD COLUMN start_date DATE NULL',
    'ALTER TABLE members ADD COLUMN payment_info VARCHAR(120) NULL',
] as $sql) {
    try { $db->exec($sql); } catch (Throwable $e) {}
}

function nextMemberCode(PDO $db): string {
    $last = $db->query('SELECT member_code FROM members ORDER BY id DESC LIMIT 1')->fetch();
    $next = 1;
    if ($last && preg_match('/(\d+)$/', $last['member_code'], $match)) {
        $next = (int)$match[1] + 1;
    }
    return 'MBR-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

function memberFields(): array {
    return [
        'full_name','phone','email','age','gender','address',
        'emergency_name','emergency_phone','emergency_relationship','emergency_contact',
        'has_medical_condition','medical_notes',
        'has_previous_injury','previous_injury_details','taking_medication',
        'fitness_goal','previous_gym_experience',
        'plan','start_date','joined_at','payment_info','status','checkins','date_of_birth',
    ];
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
    if ($status) { $sql .= ' AND status = ?'; $params[] = $status; }
    else { $sql .= ' AND status <> "Archived"'; }
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
    $start = $b['start_date'] ?? date('Y-m-d');
    $emergency = $b['emergency_contact'] ?? trim(($b['emergency_name'] ?? '') . ' ' . ($b['emergency_phone'] ?? ''));
    $stmt = $db->prepare('INSERT INTO members (member_code, full_name, phone, email, age, gender, address, date_of_birth, plan, status, emergency_contact, emergency_name, emergency_phone, emergency_relationship, has_medical_condition, medical_notes, has_previous_injury, previous_injury_details, taking_medication, fitness_goal, previous_gym_experience, start_date, payment_info, joined_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "Active", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $code,
        $b['full_name'],
        $b['phone'],
        $b['email'] ?? null,
        $b['age'] ?? null,
        $b['gender'] ?? null,
        $b['address'] ?? null,
        $b['date_of_birth'] ?? null,
        $b['plan'],
        $emergency ?: null,
        $b['emergency_name'] ?? null,
        $b['emergency_phone'] ?? null,
        $b['emergency_relationship'] ?? null,
        $b['has_medical_condition'] ?? null,
        $b['medical_notes'] ?? null,
        $b['has_previous_injury'] ?? null,
        $b['previous_injury_details'] ?? null,
        $b['taking_medication'] ?? null,
        $b['fitness_goal'] ?? null,
        $b['previous_gym_experience'] ?? null,
        $start,
        $b['payment_info'] ?? null,
        $start,
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
    $sets = []; $params = [];
    foreach (memberFields() as $field) {
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
