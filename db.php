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

define('DB_HOST', envFirst(['MYSQLHOST', 'DB_HOST', 'MYSQL_HOST'], 'localhost'));
define('DB_USER', envFirst(['MYSQLUSER', 'DB_USER', 'MYSQL_USER'], 'root'));
define('DB_PASS', envFirst(['MYSQLPASSWORD', 'DB_PASS', 'MYSQL_PASSWORD'], ''));
define('DB_NAME', envFirst(['MYSQLDATABASE', 'DB_NAME', 'MYSQL_DATABASE'], 'nasme_gym'));
define('DB_PORT', envFirst(['MYSQLPORT', 'DB_PORT', 'MYSQL_PORT'], '3306'));

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Database connection failed.',
                'hint'  => 'Check MYSQLHOST / DB_HOST and related environment variables.',
            ]);
            exit;
        }
    }
    return $pdo;
}
