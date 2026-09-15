<?php

declare(strict_types=1);

require_once __DIR__ . '/db.inc.php';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
ini_set('session.use_strict_mode', '1');
session_start();

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

function db(): PDO
{
    global $dbConfig;
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $dbConfig['host'], $dbConfig['port'], $dbConfig['database'], $dbConfig['charset']);
    return new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

$pdo = db();

function requireAdmin(): void
{
    global $pdo;
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(403);
        exit('Zugriff verweigert.');
    }
    $statement = $pdo->prepare('SELECT id FROM admin_users WHERE id = :id AND is_active = 1 LIMIT 1');
    $statement->execute([':id' => (int) $_SESSION['admin_id']]);
    if (!$statement->fetchColumn()) {
        $_SESSION = [];
        session_destroy();
        http_response_code(403);
        exit('Zugriff verweigert.');
    }
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf(): string
{
    return $_SESSION['admin_csrf'] ??= bin2hex(random_bytes(32));
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf(), $token)) {
        http_response_code(403);
        exit('Ungültige Anfrage.');
    }
}

function postText(string $key, int $maxLength): string
{
    $value = $_POST[$key] ?? '';
    $value = is_string($value) ? trim($value) : '';
    if (mb_strlen($value) > $maxLength) throw new InvalidArgumentException('Eingabe zu lang.');
    return $value;
}

function redirectAdmin(): never
{
    header('Location: admin.php');
    exit;
}
