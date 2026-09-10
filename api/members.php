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
    'ALTER TABLE members ADD COLUMN expires_at DATE NULL',
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

/**
 * Subscription end from registration/start date + plan.
 * Daily   → same day as start
 * Weekly  → start + 7 days
 * Monthly → start + 1 month
 * Yearly  → start + 1 year
 */
function computeExpiresAt(?string $plan, ?string $startDate): string {
    if (!$startDate) $startDate = date('Y-m-d');
    $startDate = substr(trim((string)$startDate), 0, 10);
    $plan = strtolower(trim((string)$plan));
    try {
        $dt = new DateTime($startDate);
    } catch (Throwable $e) {
        $dt = new DateTime('today');
    }
    if (strpos($plan, 'day') !== false) {
        return $dt->format('Y-m-d');
    }
    if (strpos($plan, 'week') !== false) {
        $dt->modify('+7 days');
    } elseif (strpos($plan, 'year') !== false) {
        $dt->modify('+1 year');
    } else {
        $dt->modify('+1 month');
    }
    return $dt->format('Y-m-d');
}

function memberFields(): array {
    return [
        'full_name','phone','email','age','gender','address',
        'emergency_name','emergency_phone','emergency_relationship','emergency_contact',
        'has_medical_condition','medical_notes',
        'has_previous_injury','previous_injury_details','taking_medication',
        'fitness_goal','previous_gym_experience',
        'plan','start_date','joined_at','payment_info','status','checkins','date_of_birth','expires_at',
    ];
}

/**
 * Create a payment record for a member.
 * Called on registration and every time payment details are updated.
 * Returns the txn_code or null if amount is missing/invalid.
 */
function recordPayment(PDO $db, array $user, int $memberId, string $memberName, string $plan, $amount, ?string $method = null, ?string $notes = null): ?string {
    $amount = (float) preg_replace('/[^0-9.]/', '', (string)$amount);
    if ($amount <= 0) return null;

    $allowedMethods = ['Cash', 'Card', 'Mobile Money', 'Bank Transfer'];
    $method = $method && in_array($method, $allowedMethods, true) ? $method : 'Cash';

    $last = $db->query('SELECT txn_code FROM payments ORDER BY id DESC LIMIT 1')->fetch();
    $next = $last ? (int)substr($last['txn_code'], 4) + 1 : 1;
    $code = 'TXN-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);

    $stmt = $db->prepare('
        INSERT INTO payments
            (txn_code, member_id, plan, amount, method, status, notes, paid_at)
        VALUES (?, ?, ?, ?, ?, "Completed", ?, CURDATE())
    ');
    $stmt->execute([
        $code,
        $memberId,
        $plan ?: 'Monthly',
        $amount,
        $method,
        $notes,
    ]);

    $formatted = number_format($amount, 2);
    logAction(
        $db,
        $user['id'],
        "Payment $code recorded — ₦{$formatted} from {$memberName} ({$plan})",
        'success'
    );

    return $code;
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

function restoreMember(PDO $db, array $user, int $id): void {
    $check = $db->prepare('SELECT * FROM members WHERE id = ?');
    $check->execute([$id]);
    $current = $check->fetch();
    if (!$current) respond(['error' => 'Member not found.'], 404);
    if ($current['status'] !== 'Archived') respond(['error' => 'This member is not archived.'], 409);
    $db->prepare('UPDATE members SET status = "Active", archive_reason = NULL, archived_at = NULL, updated_at = NOW() WHERE id = ?')
       ->execute([$id]);
    logAction($db, $user['id'], "Member {$current['member_code']} ({$current['full_name']}) restored to Active", 'success');
    respond(['success' => true]);
}

if ($m === 'GET' && !$id) {
    $q      = '%' . ($_GET['q'] ?? '') . '%';
    $plan   = $_GET['plan']   ?? '';
    $status = $_GET['status'] ?? '';
    $sql    = 'SELECT * FROM members WHERE (full_name LIKE ? OR member_code LIKE ? OR phone LIKE ?)';
    $params = [$q, $q, $q];
    if ($status && strtolower($status) !== 'all') {
        $sql .= ' AND status = ?';
        $params[] = $status;
    } elseif (!$status) {
        $sql .= ' AND status <> "Archived"';
    }
    if ($plan) { $sql .= ' AND plan = ?'; $params[] = $plan; }
    $sql .= ' ORDER BY created_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $today = date('Y-m-d');
    foreach ($rows as &$row) {
        $start = $row['start_date'] ?? $row['joined_at'] ?? $today;
        $start = $start ? substr((string)$start, 0, 10) : $today;
        if (!empty($row['plan'])) {
            $computed = computeExpiresAt($row['plan'], $start);
            if (empty($row['expires_at']) || $row['expires_at'] !== $computed) {
                $planL = strtolower((string)$row['plan']);
                $shouldFix = empty($row['expires_at']);
                if (!$shouldFix && strpos($planL, 'day') !== false && $row['expires_at'] !== $start) {
                    $shouldFix = true;
                }
                if ($shouldFix) {
                    try {
                        $db->prepare('UPDATE members SET expires_at = ? WHERE id = ?')->execute([$computed, $row['id']]);
                        $row['expires_at'] = $computed;
                    } catch (Throwable $e) {
                        $row['expires_at'] = $computed;
                    }
                }
            }
        }
        if (($row['status'] ?? '') === 'Active' && !empty($row['expires_at']) && $row['expires_at'] < $today) {
            try {
                $db->prepare('UPDATE members SET status = "Expired", updated_at = NOW() WHERE id = ? AND status = "Active"')
                   ->execute([$row['id']]);
                $row['status'] = 'Expired';
            } catch (Throwable $e) {}
        }
    }
    unset($row);
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
    if (!empty($b['restore']) && !empty($b['id'])) {
        restoreMember($db, $user, (int)$b['id']);
    }
    foreach (['full_name', 'phone', 'plan'] as $field) {
        if (empty($b[$field])) respond(['error' => "Field '$field' is required."], 400);
    }
    $dup = $db->prepare('SELECT id FROM members WHERE full_name = ? AND phone = ?');
    $dup->execute([$b['full_name'], $b['phone']]);
    if ($dup->fetch()) respond(['error' => 'A member with this name and phone number already exists.'], 409);
    $code   = nextMemberCode($db);
    $start  = !empty($b['start_date']) ? substr((string)$b['start_date'], 0, 10) : date('Y-m-d');
    $status = $b['status'] ?? 'Active';
    $allowedStatus = ['Active','Expired','Pending','Suspended'];
    if (!in_array($status, $allowedStatus, true)) $status = 'Active';
    $expires = computeExpiresAt($b['plan'], $start);
    $emergency = $b['emergency_contact'] ?? trim(($b['emergency_name'] ?? '') . ' ' . ($b['emergency_phone'] ?? ''));

    $amountRaw = $b['amount'] ?? $b['payment_amount'] ?? null;
    $method    = $b['payment_method'] ?? $b['method'] ?? null;
    $paymentInfo = $b['payment_info'] ?? null;
    if ($amountRaw !== null && $amountRaw !== '') {
        $paymentInfo = trim(($method ? $method . ' — ' : '') . '₦' . number_format((float)$amountRaw, 2));
    }

    $stmt = $db->prepare('INSERT INTO members (member_code, full_name, phone, email, age, gender, address, date_of_birth, plan, status, emergency_contact, emergency_name, emergency_phone, emergency_relationship, has_medical_condition, medical_notes, has_previous_injury, previous_injury_details, taking_medication, fitness_goal, previous_gym_experience, start_date, expires_at, payment_info, joined_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
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
        $status,
        $emergency ?: null,
        $b['emergency_name'] ?? null,
        $b['emergency_phone'] ?? null,
        $b['emergency_relationship'] ?? null,
        $b['has_medical_condition'] ?? null,
        $b['medical_notes'] ?? null,
        $b['previous_injury_details'] ?? null,
        $b['has_previous_injury'] ?? null,
        $b['taking_medication'] ?? null,
        $b['fitness_goal'] ?? null,
        $b['previous_gym_experience'] ?? null,
        $start,
        $expires,
        $paymentInfo,
        $start,
    ]);

    $newId = (int)$db->lastInsertId();
    logAction($db, $user['id'], "Member $code ({$b['full_name']}) registered", 'success');

    $txnCode = null;
    if ($amountRaw !== null && $amountRaw !== '') {
        $txnCode = recordPayment(
            $db,
            $user,
            $newId,
            $b['full_name'],
            $b['plan'],
            $amountRaw,
            $method,
            'Registration payment'
        );
    }

    respond([
        'success'     => true,
        'member_code' => $code,
        'id'          => $newId,
        'expires_at'  => $expires,
        'start_date'  => $start,
        'txn_code'    => $txnCode,
    ], 201);
}

if ($m === 'PUT' && $id) {
    $b = body();
    if (!empty($b['archive']) || (($b['status'] ?? '') === 'Archived')) {
        archiveMember($db, $user, $id, $b['archive_reason'] ?? '');
    }
    if (!empty($b['restore'])) {
        restoreMember($db, $user, $id);
    }
    $check = $db->prepare('SELECT * FROM members WHERE id = ?');
    $check->execute([$id]);
    $current = $check->fetch();
    if (!$current) respond(['error' => 'Member not found.'], 404);

    $plan  = $b['plan'] ?? $current['plan'];
    $start = $b['start_date'] ?? $current['start_date'] ?? date('Y-m-d');
    $start = substr((string)$start, 0, 10);
    $b['expires_at'] = computeExpiresAt($plan, $start);
    if (!isset($b['start_date'])) $b['start_date'] = $start;

    $amountRaw = $b['amount'] ?? $b['payment_amount'] ?? null;
    $method    = $b['payment_method'] ?? $b['method'] ?? null;
    if ($amountRaw !== null && $amountRaw !== '') {
        $b['payment_info'] = trim(($method ? $method . ' — ' : '') . '₦' . number_format((float)$amountRaw, 2));
    }

    $sets = []; $params = [];
    foreach (memberFields() as $field) {
        if (array_key_exists($field, $b)) {
            if ($field === 'status') {
                $allowed = ['Active','Expired','Pending','Suspended','Archived'];
                if (!in_array($b['status'], $allowed, true)) continue;
            }
            $sets[] = "$field = ?";
            $params[] = $b[$field];
        }
    }
    if (!$sets) respond(['error' => 'No fields to update.'], 400);
    $params[] = $id;
    $db->prepare('UPDATE members SET ' . implode(', ', $sets) . ', updated_at=NOW() WHERE id=?')->execute($params);
    $label = $b['full_name'] ?? $current['full_name'];
    logAction($db, $user['id'], "Member {$current['member_code']} ($label) updated", 'info');

    $txnCode = null;
    if ($amountRaw !== null && $amountRaw !== '') {
        $txnCode = recordPayment(
            $db,
            $user,
            $id,
            $label,
            $plan,
            $amountRaw,
            $method,
            'Payment update / renewal'
        );
    }

    respond(['success' => true, 'expires_at' => $b['expires_at'], 'txn_code' => $txnCode]);
}

if ($m === 'DELETE' && $id) {
    $b = body();
    archiveMember($db, $user, $id, $b['archive_reason'] ?? ($_GET['reason'] ?? ''));
}

respond(['error' => 'Method not allowed.'], 405);
