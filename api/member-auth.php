<?php
require_once __DIR__ . '/helpers.php';

$action = $_GET['action'] ?? '';
$m      = method();

function ensureCheckinsTable(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS checkins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        member_id INT NOT NULL,
        checked_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX (member_id),
        INDEX (checked_in_at)
    )');
}

function loadMemberRow(PDO $db, int $id): ?array {
    $stmt = $db->prepare('SELECT * FROM members WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function memberSessionPayload(array $member): array {
    return [
        'id'          => $member['id'],
        'member_code' => $member['member_code'],
        'full_name'   => $member['full_name'],
        'plan'        => $member['plan'],
        'status'      => $member['status'],
        'checkins'    => (int)($member['checkins'] ?? 0),
        'joined_at'   => $member['joined_at'] ?? $member['start_date'] ?? null,
        'start_date'  => $member['start_date'] ?? null,
        'expires_at'  => $member['expires_at'] ?? null,
        'phone'       => $member['phone'],
        'email'       => $member['email'] ?? null,
    ];
}

if ($action === 'login' && $m === 'POST') {
    $b          = body();
    $memberCode = strtoupper(trim($b['member_code'] ?? ''));
    $phone      = trim($b['phone'] ?? '');

    if (!$memberCode || !$phone) {
        respond(['error' => 'Member code and phone number are required.'], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM members WHERE member_code = ?');
    $stmt->execute([$memberCode]);
    $member = $stmt->fetch();

    if (!$member) {
        respond(['error' => 'Member ID not found. Please check your member code.'], 401);
    }

    $cleanInput  = preg_replace('/[\s\-+]/', '', $phone);
    $cleanStored = preg_replace('/[\s\-+]/', '', $member['phone']);
    if ($cleanInput !== $cleanStored) {
        respond(['error' => 'Incorrect phone number. Please try again.'], 401);
    }

    // Auto-expire if past expires_at
    if (($member['status'] ?? '') === 'Active' && !empty($member['expires_at']) && $member['expires_at'] < date('Y-m-d')) {
        $db->prepare('UPDATE members SET status = "Expired", updated_at = NOW() WHERE id = ?')->execute([$member['id']]);
        $member['status'] = 'Expired';
    }

    if ($member['status'] === 'Archived') {
        $reason = $member['archive_reason'] ?? '';
        respond([
            'error' => 'This membership has been archived and can no longer be used to log in.' . ($reason ? ' Reason: ' . $reason : ''),
            'archived' => true,
        ], 403);
    }
    if ($member['status'] === 'Suspended') {
        respond(['error' => 'Your membership has been suspended. Please contact the gym.'], 403);
    }
    if ($member['status'] === 'Expired') {
        respond(['error' => 'Your membership has expired. Please renew at the front desk.', 'expired' => true], 403);
    }

    $_SESSION['member'] = memberSessionPayload($member);

    logAction($db, null, "Member {$member['member_code']} ({$member['full_name']}) logged in", 'success');
    respond(['success' => true, 'member' => $_SESSION['member']]);
}

if ($action === 'me' && $m === 'GET') {
    respond(['member' => $_SESSION['member'] ?? null]);
}

if ($action === 'profile' && $m === 'GET') {
    if (empty($_SESSION['member'])) respond(['error' => 'Not logged in.'], 401);
    $db       = getDB();
    $memberId = (int)$_SESSION['member']['id'];
    $member   = loadMemberRow($db, $memberId);
    if (!$member) respond(['error' => 'Member not found.'], 404);
    $_SESSION['member'] = memberSessionPayload($member);

    $payStmt = $db->prepare('SELECT txn_code, plan, amount, method, status, paid_at FROM payments WHERE member_id = ? ORDER BY paid_at DESC');
    $payStmt->execute([$memberId]);

    ensureCheckinsTable($db);
    $cinStmt = $db->prepare('SELECT id, checked_in_at FROM checkins WHERE member_id = ? ORDER BY checked_in_at DESC LIMIT 20');
    $cinStmt->execute([$memberId]);

    respond([
        'member'   => $_SESSION['member'],
        'payments' => $payStmt->fetchAll(),
        'checkins' => $cinStmt->fetchAll(),
    ]);
}

/** Member self check-in */
if ($action === 'checkin' && $m === 'POST') {
    if (empty($_SESSION['member'])) respond(['error' => 'Not logged in.'], 401);
    $db       = getDB();
    $memberId = (int)$_SESSION['member']['id'];
    $member   = loadMemberRow($db, $memberId);
    if (!$member) respond(['error' => 'Member not found.'], 404);

    if (($member['status'] ?? '') === 'Active' && !empty($member['expires_at']) && $member['expires_at'] < date('Y-m-d')) {
        $db->prepare('UPDATE members SET status = "Expired", updated_at = NOW() WHERE id = ?')->execute([$memberId]);
        $member['status'] = 'Expired';
    }

    if (in_array($member['status'], ['Archived', 'Suspended', 'Expired', 'Pending'], true)) {
        respond(['error' => 'You cannot check in while membership status is ' . $member['status'] . '.'], 403);
    }

    ensureCheckinsTable($db);

    // One check-in per calendar day
    $dup = $db->prepare('SELECT id FROM checkins WHERE member_id = ? AND DATE(checked_in_at) = CURDATE() LIMIT 1');
    $dup->execute([$memberId]);
    if ($dup->fetch()) {
        respond(['error' => 'You already checked in today.', 'already' => true], 409);
    }

    $db->prepare('INSERT INTO checkins (member_id, checked_in_at) VALUES (?, NOW())')->execute([$memberId]);
    $db->prepare('UPDATE members SET checkins = COALESCE(checkins, 0) + 1, updated_at = NOW() WHERE id = ?')->execute([$memberId]);

    $member = loadMemberRow($db, $memberId);
    $_SESSION['member'] = memberSessionPayload($member);

    logAction($db, null, "Member {$member['member_code']} ({$member['full_name']}) checked in", 'success');
    respond([
        'success'  => true,
        'message'  => 'Checked in successfully.',
        'member'   => $_SESSION['member'],
        'checked_in_at' => date('Y-m-d H:i:s'),
    ]);
}

if ($action === 'payments' && $m === 'GET') {
    if (empty($_SESSION['member'])) respond(['error' => 'Not logged in.'], 401);
    $db   = getDB();
    $stmt = $db->prepare('SELECT txn_code, plan, amount, method, status, paid_at FROM payments WHERE member_id = ? ORDER BY paid_at DESC');
    $stmt->execute([$_SESSION['member']['id']]);
    respond(['data' => $stmt->fetchAll()]);
}

if ($action === 'logout' && $m === 'POST') {
    $name = $_SESSION['member']['full_name']   ?? 'Unknown';
    $code = $_SESSION['member']['member_code'] ?? '';
    unset($_SESSION['member']);
    $db = getDB();
    logAction($db, null, "Member $code ($name) logged out", 'info');
    respond(['success' => true]);
}

respond(['error' => 'Invalid action.'], 400);
