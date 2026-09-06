<?php
/* ════════════════════════════════════════
   NASME GYM — Dashboard Stats API
   File: api/stats.php

   GET api/stats.php   → all dashboard summary numbers
════════════════════════════════════════ */

require_once __DIR__ . '/helpers.php';

requireAuth();
$db = getDB();

// No problems here

if (method() !== 'GET') {
    respond(['error' => 'Method not allowed.'], 405);
}

/* Run all counts in one shot */
respond([
    'data' => [

        /* ── Members ── */
        'total_members'    => (int)$db->query(
            'SELECT COUNT(*) FROM members'
        )->fetchColumn(),

        'active_members'   => (int)$db->query(
            "SELECT COUNT(*) FROM members WHERE status = 'Active'"
        )->fetchColumn(),

        'expired_members'  => (int)$db->query(
            "SELECT COUNT(*) FROM members WHERE status = 'Expired'"
        )->fetchColumn(),

        'new_this_month'   => (int)$db->query(
            "SELECT COUNT(*) FROM members WHERE MONTH(joined_at) = MONTH(NOW()) AND YEAR(joined_at) = YEAR(NOW())"
        )->fetchColumn(),

        /* ── Revenue ── */
        'monthly_revenue'  => (float)$db->query(
            "SELECT COALESCE(SUM(amount), 0)
             FROM payments
             WHERE MONTH(paid_at) = MONTH(NOW())
               AND YEAR(paid_at)  = YEAR(NOW())
               AND status = 'Completed'"
        )->fetchColumn(),

        'total_revenue'    => (float)$db->query(
            "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'Completed'"
        )->fetchColumn(),

        'pending_payments' => (int)$db->query(
            "SELECT COUNT(*) FROM payments WHERE status = 'Pending'"
        )->fetchColumn(),

        /* ── Equipment ── */
        'equipment_total'  => (int)$db->query(
            'SELECT COUNT(*) FROM equipment'
        )->fetchColumn(),

        'equipment_maintenance' => (int)$db->query(
            "SELECT COUNT(*) FROM equipment WHERE status = 'Under Maintenance'"
        )->fetchColumn(),

        /* ── Store ── */
        'total_products'   => (int)$db->query(
            'SELECT COUNT(*) FROM products'
        )->fetchColumn(),

        'low_stock'        => (int)$db->query(
            'SELECT COUNT(*) FROM products WHERE stock < 10'
        )->fetchColumn(),

        'out_of_stock'     => (int)$db->query(
            'SELECT COUNT(*) FROM products WHERE stock = 0'
        )->fetchColumn(),

        /* ── Orders ── */
        'pending_orders'   => (int)$db->query(
            "SELECT COUNT(*) FROM orders WHERE status = 'Pending'"
        )->fetchColumn(),

        'orders_this_month' => (int)$db->query(
            "SELECT COUNT(*) FROM orders
             WHERE MONTH(ordered_at) = MONTH(NOW())
               AND YEAR(ordered_at)  = YEAR(NOW())"
        )->fetchColumn(),
    ]
]);
