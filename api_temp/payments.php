<?php
/* ════════════════════════════════════════
   NASME GYM — Payments API
   File: api/payments.php

   GET  api/payments.php            → list all transactions
   GET  api/payments.php?id=1       → single transaction
   POST api/payments.php            → record new payment
════════════════════════════════════════ */

require_once __DIR__ . '/helpers.php';

$user = requireAuth();
$db   = getDB();
$id   = getId();
$m    = method();

/* ── LIST ALL PAYMENTS (joined with member name) ─── */
if ($m === 'GET' && !$id) {
    $stmt = $db->query('
        SELECT
            p.*,
            m.full_name   AS member_name,
            m.member_code AS member_code
        FROM payments p
        JOIN members m ON p.member_id = m.id
        ORDER BY p.created_at DESC
    ');
    respond(['data' => $stmt->fetchAll()]);
}

/* ── SINGLE PAYMENT ───────────────────────────────── */
if ($m === 'GET' && $id) {
    $stmt = $db->prepare('
        SELECT p.*, m.full_name AS member_name
        FROM payments p
        JOIN members m ON p.member_id = m.id
        WHERE p.id = ?
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) respond(['error' => 'Payment not found.'], 404);
    respond(['data' => $row]);
}

/* ── RECORD PAYMENT ───────────────────────────────── */
if ($m === 'POST') {
    $b = body();

    if (empty($b['member_id']) || empty($b['amount'])) {
        respond(['error' => 'member_id and amount are required.'], 400);
    }

    /* Validate that the member exists */
    $check = $db->prepare('SELECT full_name FROM members WHERE id = ?');
    $check->execute([$b['member_id']]);
    $member = $check->fetch();
    if (!$member) respond(['error' => 'Member not found.'], 404);

    /* Validate amount is positive */
    if ((float)$b['amount'] <= 0) {
        respond(['error' => 'Amount must be greater than zero.'], 400);
    }

    /* Auto-generate transaction code: TXN-001, TXN-002 … */
    $last = $db->query('SELECT txn_code FROM payments ORDER BY id DESC LIMIT 1')->fetch();
    $next = $last ? (int)substr($last['txn_code'], 4) + 1 : 1;
    $code = 'TXN-' . str_pad($next, 3, '0', STR_PAD_LEFT);

    $stmt = $db->prepare('
        INSERT INTO payments
            (txn_code, member_id, plan, amount, method, status, notes, paid_at)
        VALUES (?, ?, ?, ?, ?, "Completed", ?, CURDATE())
    ');
    $stmt->execute([
        $code,
        $b['member_id'],
        $b['plan']   ?? 'Monthly',
        $b['amount'],
        $b['method'] ?? 'Cash',
        $b['notes']  ?? null,
    ]);

    $formatted = number_format((float)$b['amount'], 2);
    logAction(
        $db,
        $user['id'],
        "Payment $code recorded — ₦{$formatted} from {$member['full_name']}",
        'success'
    );

    respond(['success' => true, 'txn_code' => $code], 201);
}

respond(['error' => 'Method not allowed.'], 405);
