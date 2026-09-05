<?php
/* ════════════════════════════════════════
   NASME GYM — Orders API
   File: api/orders.php

   GET  api/orders.php               → list all orders
   GET  api/orders.php?id=1          → single order
   POST api/orders.php               → place new order
   PUT  api/orders.php?id=1          → update order status
════════════════════════════════════════ */

require_once __DIR__ . '/helpers.php';

$user = requireAuth();
$db   = getDB();
$id   = getId();
$m    = method();

/* ── LIST ALL ORDERS (joined with member + product) ─ */
if ($m === 'GET' && !$id) {
    $status = $_GET['status'] ?? '';

    $sql    = '
        SELECT
            o.*,
            m.full_name   AS member_name,
            m.member_code AS member_code,
            p.name        AS product_name,
            p.prd_code    AS product_code
        FROM orders o
        JOIN members  m ON o.member_id  = m.id
        JOIN products p ON o.product_id = p.id
        WHERE 1=1
    ';
    $params = [];

    if ($status) { $sql .= ' AND o.status = ?'; $params[] = $status; }
    $sql .= ' ORDER BY o.created_at DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    respond(['data' => $stmt->fetchAll()]);
}

/* ── SINGLE ORDER ─────────────────────────────────── */
if ($m === 'GET' && $id) {
    $stmt = $db->prepare('
        SELECT o.*, m.full_name AS member_name, p.name AS product_name
        FROM orders o
        JOIN members  m ON o.member_id  = m.id
        JOIN products p ON o.product_id = p.id
        WHERE o.id = ?
    ');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) respond(['error' => 'Order not found.'], 404);
    respond(['data' => $row]);
}

/* ── PLACE ORDER ──────────────────────────────────── */
if ($m === 'POST') {
    $b = body();

    if (empty($b['member_id']) || empty($b['product_id'])) {
        respond(['error' => 'member_id and product_id are required.'], 400);
    }

    /* Validate member exists */
    $mStmt = $db->prepare('SELECT full_name FROM members WHERE id = ?');
    $mStmt->execute([$b['member_id']]);
    $member = $mStmt->fetch();
    if (!$member) respond(['error' => 'Member not found.'], 404);

    /* Validate product exists and is in stock */
    $pStmt = $db->prepare('SELECT * FROM products WHERE id = ?');
    $pStmt->execute([$b['product_id']]);
    $prod = $pStmt->fetch();
    if (!$prod) respond(['error' => 'Product not found.'], 404);

    $qty = max(1, (int)($b['quantity'] ?? 1));
    if ($prod['stock'] < $qty) {
        respond(['error' => "Only {$prod['stock']} unit(s) left in stock."], 400);
    }

    /* Auto-generate order code: ORD-001, ORD-002 … */
    $last = $db->query('SELECT order_code FROM orders ORDER BY id DESC LIMIT 1')->fetch();
    $next = $last ? (int)substr($last['order_code'], 4) + 1 : 1;
    $code = 'ORD-' . str_pad($next, 3, '0', STR_PAD_LEFT);

    $total = (float)$prod['price'] * $qty;

    /* Insert order */
    $db->prepare('
        INSERT INTO orders (order_code, member_id, product_id, quantity, total)
        VALUES (?, ?, ?, ?, ?)
    ')->execute([$code, $b['member_id'], $b['product_id'], $qty, $total]);

    /* Decrement product stock */
    $db->prepare('UPDATE products SET stock = stock - ? WHERE id = ?')
       ->execute([$qty, $b['product_id']]);

    $formatted = number_format($total, 2);
    logAction(
        $db,
        $user['id'],
        "Order $code placed — {$prod['name']} x{$qty} for {$member['full_name']} (₦{$formatted})",
        'success'
    );

    respond(['success' => true, 'order_code' => $code, 'total' => $total], 201);
}

/* ── UPDATE ORDER STATUS ──────────────────────────── */
if ($m === 'PUT' && $id) {
    $b = body();

    $allowed = ['Pending', 'Shipped', 'Delivered', 'Cancelled'];
    if (!in_array($b['status'] ?? '', $allowed)) {
        respond(['error' => 'Invalid status. Must be: Pending, Shipped, Delivered, or Cancelled.'], 400);
    }

    $check = $db->prepare('SELECT order_code FROM orders WHERE id = ?');
    $check->execute([$id]);
    $row = $check->fetch();
    if (!$row) respond(['error' => 'Order not found.'], 404);

    $db->prepare('UPDATE orders SET status = ? WHERE id = ?')
       ->execute([$b['status'], $id]);

    logAction($db, $user['id'], "Order {$row['order_code']} status changed to {$b['status']}", 'info');
    respond(['success' => true]);
}

respond(['error' => 'Method not allowed.'], 405);
