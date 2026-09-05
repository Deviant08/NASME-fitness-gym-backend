<?php
/* ════════════════════════════════════════
   NASME GYM — Create Admin Accounts
   File: create-admin.php

   HOW TO USE:
   1. Upload this file to your server
   2. Visit: https://your-backend.up.railway.app/create-admin.php
   3. Accounts are created with correct password hashes
   4. DELETE this file immediately after running it
      — it's a security risk if left on the server
════════════════════════════════════════ */

require_once __DIR__ . '/db.php';

header('Content-Type: text/html');

$users = [
    [
        'username'  => 'admin',
        'password'  => 'admin123',
        'full_name' => 'Admin User',
        'role'      => 'superadmin',
    ],
    [
        'username'  => 'staff',
        'password'  => 'staff123',
        'full_name' => 'Staff Member',
        'role'      => 'staff',
    ],
];

$db = getDB();
$results = [];

foreach ($users as $u) {
    /* Generate correct bcrypt hash using PHP — guaranteed to work with password_verify() */
    $hash = password_hash($u['password'], PASSWORD_BCRYPT, ['cost' => 10]);

    /* Delete existing account with same username first */
    $db->prepare('DELETE FROM users WHERE username = ?')->execute([$u['username']]);

    /* Insert fresh with correct hash */
    $stmt = $db->prepare('
        INSERT INTO users (username, password, full_name, role)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$u['username'], $hash, $u['full_name'], $u['role']]);

    $results[] = [
        'username' => $u['username'],
        'password' => $u['password'], /* plain text shown here only — not stored */
        'role'     => $u['role'],
        'status'   => '✅ Created successfully',
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>NASME GYM — Admin Setup</title>
  <style>
    body { font-family: sans-serif; background: #0d0d1a; color: #f4f2f2; padding: 3rem; }
    h1   { color: #7a1010; margin-bottom: 0.5rem; }
    p    { color: #a09fbc; margin-bottom: 2rem; }
    table { border-collapse: collapse; width: 100%; max-width: 600px; }
    th   { background: #1c1c38; color: #a09fbc; padding: 12px 16px; text-align: left; font-size: 12px; letter-spacing: 1px; text-transform: uppercase; }
    td   { padding: 14px 16px; border-bottom: 1px solid #2e2e5a; }
    .warn { background: #3a0606; border: 1px solid #7a1010; border-radius: 6px; padding: 1.2rem 1.6rem; margin-top: 2rem; max-width: 600px; color: #d97070; font-size: 14px; }
    code { background: #1c1c38; padding: 2px 8px; border-radius: 4px; font-family: monospace; color: #9090e0; }
  </style>
</head>
<body>
  <h1>NASME GYM — Account Setup</h1>
  <p>The following accounts have been created in the database:</p>

  <table>
    <tr>
      <th>Username</th>
      <th>Password</th>
      <th>Role</th>
      <th>Status</th>
    </tr>
    <?php foreach ($results as $r): ?>
    <tr>
      <td><code><?= $r['username'] ?></code></td>
      <td><code><?= $r['password'] ?></code></td>
      <td><?= $r['role'] ?></td>
      <td><?= $r['status'] ?></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <div class="warn">
    ⚠️ <strong>IMPORTANT — Delete this file now.</strong><br><br>
    This file resets passwords on every visit. Remove <code>create-admin.php</code>
    from your server immediately after confirming the accounts work.
    <br><br>
    To delete it on Railway: remove the file from your GitHub repo and push.
  </div>
</body>
</html>
