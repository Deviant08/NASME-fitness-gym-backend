<?php
require_once __DIR__ . '/helpers.php';

$user = requireAuth();
$db   = getDB();
$id   = getId();
$m    = method();

if ($m === 'GET' && !$id) {
    $status = $_GET['status'] ?? '';
    $sql    = 'SELECT o.*, m.full_name AS member_name, m.member_code AS member_code, p.name AS product_name, p.prd_code AS product_code FROM orders o JOIN members m ON o.member_id = m.id JOIN products p ON o.product_id = p.id WHERE 1=1';
    $params = [];
    if ($status) { $sql .= ' AND o.status = ?'; $params[] = $status; }
    $sql .= ' ORDER BY o.created_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    respond(['data' => $stmt->fetchAll()]);
}

if ($m === 'GET' && $id) {
    $stmt = $db->prepare('SELECT o.*, m.full_name AS member_name, p.name AS product_name FROM orders o JOIN members m ON o.member_id = m.id JOIN products p ON o.product_id = p.id WHERE o.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) respond(['error' => 'Order not found.'], 404);
    respond(['data' => $row]);
}

if ($m === 'POST') {
    $b = body();
    if (empty($b['member_id']) || empty($b['product_id'])) respond(['error' => 'member_id and product_id are required.'], 400);
    $qty = max(1, (int)($b['quantity'] ?? 1));
    try {
        $db->beginTransaction();
        $mStmt = $db->prepare('SELECT full_name FROM members WHERE id = ?');
        $mStmt->execute([$b['member_id']]);
        $member = $mStmt->fetch();
        if (!$member) { $db->rollBack(); respond(['error' => 'Member not found.'], 404); }
        $pStmt = $db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
        $pStmt->execute([$b['product_id']]);
        $prod = $pStmt->fetch();
        if (!$prod) { $db->rollBack(); respond(['error' => 'Product not found.'], 404); }
        if ((int)$prod['stock'] < $qty) { $db->rollBack(); respond(['error' => "Only {$prod['stock']} unit(s) left in stock."], 400); }
        $code  = nextCode($db, 'orders', 'order_code', 'ORD-', 3);
        $total = (float)$prod['price'] * $qty;
        $db->prepare('INSERT INTO orders (order_code, member_id, product_id, quantity, total) VALUES (?, ?, ?, ?, ?)')->execute([$code, $b['member_id'], $b['product_id'], $qty, $total]);
        $db->prepare('UPDATE products SET stock = stock - ? WHERE id = ?')->execute([$qty, $b['product_id']]);
        logAction($db, $user['id'], "Order $code placed — {$prod['name']} x{$qty} for {$member['full_name']}", 'success');
        $db->commit();
        respond(['success' => true, 'order_code' => $code, 'total' => $total], 201);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        respond(['error' => 'Could not place order.'], 500);
    }
}

if ($m === 'PUT' && $id) {
    $b = body();
    $allowed = ['Pending', 'Shipped', 'Delivered', 'Cancelled'];
    if (!in_array($b['status'] ?? '', $allowed, true)) respond(['error' => 'Invalid status. Must be: Pending, Shipped, Delivered, or Cancelled.'], 400);
    $check = $db->prepare('SELECT order_code, status, product_id, quantity FROM orders WHERE id = ?');
    $check->execute([$id]);
    $row = $check->fetch();
    if (!$row) respond(['error' => 'Order not found.'], 404);
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$b['status'], $id]);
        if ($b['status'] === 'Cancelled' && $row['status'] !== 'Cancelled') {
            $db->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$row['quantity'], $row['product_id']]);
        }
        logAction($db, $user['id'], "Order {$row['order_code']} status changed to {$b['status']}", 'info');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        respond(['error' => 'Could not update order.'], 500);
    }
    respond(['success' => true]);
}

respond(['error' => 'Method not allowed.'], 405);
