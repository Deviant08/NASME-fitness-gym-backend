<?php
/* ════════════════════════════════════════
   NASME GYM — Products API
   File: api/products.php

   GET    api/products.php           → list all products
   GET    api/products.php?id=1      → single product
   POST   api/products.php           → add product
   PUT    api/products.php?id=1      → update product
   DELETE api/products.php?id=1      → remove product
════════════════════════════════════════ */

require_once __DIR__ . '/helpers.php';

$user = requireAuth();
$db   = getDB();
$id   = getId();
$m    = method();

/* ── LIST ALL ─────────────────────────────────────── */
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

/* ── SINGLE PRODUCT ───────────────────────────────── */
if ($m === 'GET' && $id) {
    $stmt = $db->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) respond(['error' => 'Product not found.'], 404);
    respond(['data' => $row]);
}

/* ── ADD PRODUCT ──────────────────────────────────── */
if ($m === 'POST') {
    $b = body();

    foreach (['name', 'price', 'stock'] as $field) {
        if (!isset($b[$field]) || $b[$field] === '') {
            respond(['error' => "Field '$field' is required."], 400);
        }
    }

    if ((float)$b['price'] <= 0) {
        respond(['error' => 'Price must be greater than zero.'], 400);
    }
    if ((int)$b['stock'] < 0) {
        respond(['error' => 'Stock cannot be negative.'], 400);
    }

    /* Auto-generate product code: PRD-001, PRD-002 … */
    $last = $db->query('SELECT prd_code FROM products ORDER BY id DESC LIMIT 1')->fetch();
    $next = $last ? (int)substr($last['prd_code'], 4) + 1 : 1;
    $code = 'PRD-' . str_pad($next, 3, '0', STR_PAD_LEFT);

    $db->prepare('
        INSERT INTO products (prd_code, name, category, price, stock, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ')->execute([
        $code,
        $b['name'],
        $b['category']    ?? 'Accessories',
        $b['price'],
        $b['stock'],
        $b['description'] ?? '',
    ]);

    logAction($db, $user['id'], "Product $code ({$b['name']}) added to store", 'success');
    respond(['success' => true, 'prd_code' => $code], 201);
}

/* ── UPDATE PRODUCT ───────────────────────────────── */
if ($m === 'PUT' && $id) {
    $b = body();

    $check = $db->prepare('SELECT prd_code FROM products WHERE id = ?');
    $check->execute([$id]);
    $row = $check->fetch();
    if (!$row) respond(['error' => 'Product not found.'], 404);

    $db->prepare('
        UPDATE products
        SET name=?, category=?, price=?, stock=?, description=?, updated_at=NOW()
        WHERE id=?
    ')->execute([
        $b['name'],
        $b['category']    ?? 'Accessories',
        $b['price'],
        $b['stock'],
        $b['description'] ?? '',
        $id,
    ]);

    logAction($db, $user['id'], "Product {$row['prd_code']} ({$b['name']}) updated", 'info');
    respond(['success' => true]);
}

/* ── DELETE PRODUCT ───────────────────────────────── */
if ($m === 'DELETE' && $id) {
    $stmt = $db->prepare('SELECT prd_code, name FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) respond(['error' => 'Product not found.'], 404);

    $db->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);

    logAction($db, $user['id'], "Product {$row['prd_code']} ({$row['name']}) removed from store", 'danger');
    respond(['success' => true]);
}

respond(['error' => 'Method not allowed.'], 405);
