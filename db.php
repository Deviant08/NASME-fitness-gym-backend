<?php
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\"'");
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

function envFirst(array $keys, string $fallback = ''): string {
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value !== false && $value !== '') return $value;
        if (!empty($_ENV[$key])) return (string)$_ENV[$key];
    }
    return $fallback;
}

function parseMysqlUrl(string $url): ?array {
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) return null;
    return [
        'host' => $parts['host'],
        'port' => (string)($parts['port'] ?? '3306'),
        'user' => urldecode($parts['user'] ?? ''),
        'pass' => urldecode($parts['pass'] ?? ''),
        'name' => ltrim($parts['path'] ?? '', '/'),
    ];
}

function dbConfig(): array {
    $url = envFirst(['MYSQL_URL', 'DATABASE_URL', 'MYSQL_PRIVATE_URL', 'MYSQL_PUBLIC_URL']);
    $fromUrl = $url !== '' ? parseMysqlUrl($url) : null;
    return [
        'host' => envFirst(['MYSQLHOST', 'DB_HOST', 'MYSQL_HOST'], $fromUrl['host'] ?? 'localhost'),
        'port' => envFirst(['MYSQLPORT', 'DB_PORT', 'MYSQL_PORT'], $fromUrl['port'] ?? '3306'),
        'user' => envFirst(['MYSQLUSER', 'DB_USER', 'MYSQL_USER'], $fromUrl['user'] ?? 'root'),
        'pass' => envFirst(['MYSQLPASSWORD', 'DB_PASS', 'MYSQL_PASSWORD'], $fromUrl['pass'] ?? ''),
        'name' => envFirst(['MYSQLDATABASE', 'DB_NAME', 'MYSQL_DATABASE'], $fromUrl['name'] ?? 'railway'),
    ];
}

function dbEnvReport(): array {
    $keys = ['MYSQLHOST','MYSQLPORT','MYSQLUSER','MYSQLPASSWORD','MYSQLDATABASE','MYSQL_URL','DATABASE_URL','MYSQL_PRIVATE_URL','DB_HOST','DB_USER','DB_NAME'];
    $present = [];
    foreach ($keys as $key) {
        $value = envFirst([$key]);
        $present[$key] = $value !== '';
    }
    $cfg = dbConfig();
    return [
        'vars_present' => $present,
        'using' => [
            'host' => $cfg['host'],
            'port' => $cfg['port'],
            'user' => $cfg['user'],
            'name' => $cfg['name'],
            'password_set' => $cfg['pass'] !== '',
        ],
    ];
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = dbConfig();
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $cfg['name']);
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
