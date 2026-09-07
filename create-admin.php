<?php
require_once __DIR__ . '/db.php';
header('Content-Type: text/html; charset=utf-8');
$expected = getenv('CREATE_ADMIN_KEY') ?: ($_ENV['CREATE_ADMIN_KEY'] ?? '');
$given    = $_GET['key'] ?? '';
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;background:#0d0d1a;color:#f4f2f2;padding:3rem">';
    echo '<h1>Setup locked</h1><p>Set CREATE_ADMIN_KEY on Railway, then open this page with ?key=...</p></body></html>';
    exit;
}
$users = [
    ['username'=>'admin','password'=>'admin123','full_name'=>'Admin User','role'=>'superadmin'],
    ['username'=>'staff','password'=>'staff123','full_name'=>'Staff Member','role'=>'staff'],
];
$db = getDB();
$results = [];
foreach ($users as $u) {
    $hash = password_hash($u['password'], PASSWORD_BCRYPT, ['cost' => 10]);
    $db->prepare('DELETE FROM users WHERE username = ?')->execute([$u['username']]);
    $db->prepare('INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)')->execute([$u['username'], $hash, $u['full_name'], $u['role']]);
    $results[] = $u + ['status' => 'Created'];
}
?>
<!DOCTYPE html>
<html><head><title>NASME GYM — Admin Setup</title></head>
<body style="font-family:sans-serif;background:#0d0d1a;color:#f4f2f2;padding:3rem">
<h1>NASME GYM — Account Setup</h1>
<table border="0" cellpadding="8">
<tr><th>Username</th><th>Password</th><th>Role</th></tr>
<?php foreach ($results as $r): ?>
<tr><td><?= htmlspecialchars($r['username']) ?></td><td><?= htmlspecialchars($r['password']) ?></td><td><?= htmlspecialchars($r['role']) ?></td></tr>
<?php endforeach; ?>
</table>
<p>Remove CREATE_ADMIN_KEY from Railway after login works.</p>
</body></html>
