<?php
require_once __DIR__ . '/helpers.php';

$action = $_GET['action'] ?? '';
$m      = method();

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

    $_SESSION['member'] = [
        'id'          => $member['id'],
        'member_code' => $member['member_code'],
        'full_name'   => $member['full_name'],
        'plan'        => $member['plan'],
        'status'      => $member['status'],
        'checkins'    => $member['checkins'],
        'joined_at'   => $member['joined_at'],
        'phone'       => $member['phone'],
        'email'       => $member['email'],
    ];

    logAction($db, null, "Member {$member['member_code']} ({$member['full_name']}) logged in", 'success');
    respond(['success' => true, 'member' => $_SESSION['member']]);
}

if ($action === 'me' && $m === 'GET') {
    respond(['member' => $_SESSION['member'] ?? null]);
}

if ($action === 'profile' && $m === 'GET') {
    if (empty($_SESSION['member'])) respond(['error' => 'Not logged in.'], 401);
    $db       = getDB();
    $memberId = $_SESSION['member']['id'];
    $payStmt = $db->prepare('SELECT txn_code, plan, amount, method, status, paid_at FROM payments WHERE member_id = ? ORDER BY paid_at DESC');
    $payStmt->execute([$memberId]);
    $ordStmt = $db->prepare('SELECT o.order_code, p.name AS product_name, o.quantity, o.total, o.status, o.ordered_at FROM orders o JOIN products p ON o.product_id = p.id WHERE o.member_id = ? ORDER BY o.ordered_at DESC');
    $ordStmt->execute([$memberId]);
    respond(['member' => $_SESSION['member'], 'payments' => $payStmt->fetchAll(), 'orders' => $ordStmt->fetchAll()]);
}

if ($action === 'payments' && $m === 'GET') {
    if (empty($_SESSION['member'])) respond(['error' => 'Not logged in.'], 401);
    $db   = getDB();
    $stmt = $db->prepare('SELECT txn_code, plan, amount, method, status, paid_at FROM payments WHERE member_id = ? ORDER BY paid_at DESC');
    $stmt->execute([$_SESSION['member']['id']]);
    respond(['data' => $stmt->fetchAll()]);
}

if ($action === 'orders' && $m === 'GET') {
    if (empty($_SESSION['member'])) respond(['error' => 'Not logged in.'], 401);
    $db   = getDB();
    $stmt = $db->prepare('SELECT o.order_code, p.name AS product_name, o.quantity, o.total, o.status, o.ordered_at FROM orders o JOIN products p ON o.product_id = p.id WHERE o.member_id = ? ORDER BY o.ordered_at DESC');
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
