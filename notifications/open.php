<?php
/**
 * notifications/open.php
 * Tandai notifikasi milik user sebagai dibaca lalu buka tautannya.
 */
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$pdo = getConnection();
$id = (int) ($_GET['id'] ?? 0);
$userId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare('SELECT url FROM notifications WHERE id = :id AND user_id = :user_id LIMIT 1');
$stmt->execute(['id' => $id, 'user_id' => $userId]);
$url = $stmt->fetchColumn();

if ($url === false) {
    header('Location: index.php');
    exit;
}

$pdo->prepare('UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = :id AND user_id = :user_id')
    ->execute(['id' => $id, 'user_id' => $userId]);

// Hanya tautan relatif internal aplikasi.
$url = (string) $url;
if ($url === '' || preg_match('#^(?:[a-z]+:|//)#i', $url) || str_contains($url, '..')) {
    header('Location: index.php');
    exit;
}

header('Location: ../' . ltrim($url, '/'));
exit;
