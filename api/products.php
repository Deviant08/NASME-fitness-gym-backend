<?php
require_once __DIR__ . '/helpers.php';

$user = requireAuth();
$db   = getDB();
$id   = getId();
$m    = method();

if ($m === 'GET' && !$id) {
    $category = $_GET['category'] ?? '';
    $lowStock = $_GET['low_stock'] ?? '';
    $sql    = 'SELECT * FROM products WHERE 1=1';
    $params = [];
    if ($category) { $sql .= ' AND category = ?'; $params[] = $category; }
    if ($lowStock)  { $sql .= ' AND stock < 10'; }
    $sql .= ' ORDER BY name ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    respond(['data' => $stmt->fetchAll()]);
}

if ($m === 'GET' && $id) {
    $stmt = $db->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) respond(['error' => 'Product not found.'], 404);
    respond(['data' => $row]);
}

if ($m === 'POST') {
    $b = body();
    foreach (['name', 'price', 'stock'] as $field) {
        if (!isset($b[$field]) || $b[$field] === '') respond(['error' => "Field '$field' is required."], 400);
    }
    if ((float)$b['price'] <= 0) respond(['error' => 'Price must be greater than zero.'], 400);
    if ((int)$b['stock'] < 0) respond(['error' => 'Stock cannot be negative.'], 400);
    $code = nextCode($db, 'products', 'prd_code', 'PRD-', 3);
    $db->prepare('INSERT INTO products (prd_code, name, category, price, stock, description) VALUES (?, ?, ?, ?, ?, ?)')->execute([
        $code, $b['name'], $b['category'] ?? 'Accessories', $b['price'], $b['stock'], $b['description'] ?? '',
    ]);
    logAction($db, $user['id'], "Product $code ({$b['name']}) added to store", 'success');
    respond(['success' => true, 'prd_code' => $code], 201);
}

if ($m === 'PUT' && $id) {
    $b = body();
    $check = $db->prepare('SELECT prd_code FROM products WHERE id = ?');
    $check->execute([$id]);
    $row = $check->fetch();
    if (!$row) respond(['error' => 'Product not found.'], 404);
    $db->prepare('UPDATE products SET name=?, category=?, price=?, stock=?, description=?, updated_at=NOW() WHERE id=?')->execute([
        $b['name'], $b['category'] ?? 'Accessories', $b['price'], $b['stock'], $b['description'] ?? '', $id,
    ]);
    logAction($db, $user['id'], "Product {$row['prd_code']} ({$b['name']}) updated", 'info');
    respond(['success' => true]);
}

if ($m === 'DELETE' && $id) {
    $stmt = $db->prepare('SELECT prd_code, name FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) respond(['error' => 'Product not found.'], 404);
    try {
        $db->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    } catch (PDOException $e) {
        respond(['error' => 'This product has existing orders, so it cannot be deleted.'], 409);
    }
    logAction($db, $user['id'], "Product {$row['prd_code']} ({$row['name']}) removed from store", 'danger');
    respond(['success' => true]);
}

respond(['error' => 'Method not allowed.'], 405);
