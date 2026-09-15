<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/admin_bootstrap.php';
requireAdmin();

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($token) || !hash_equals(csrf(), $token)) {
    http_response_code(403);
    exit;
}

$query = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
    header('Content-Type: application/json; charset=utf-8');
    echo '[]';
    exit;
}

$statement = $pdo->prepare('SELECT id, name FROM authors WHERE name LIKE :query ORDER BY name LIMIT 10');
$statement->execute([':query' => '%' . $query . '%']);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($statement->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
