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

$stmt = $pdo->prepare('SELECT url FROM crf_notifikasi WHERE id = :id AND pengguna_id = :pengguna_id LIMIT 1');
$stmt->execute(['id' => $id, 'pengguna_id' => $userId]);
$url = $stmt->fetchColumn();

if ($url === false) {
    header('Location: index.php');
    exit;
}

$pdo->prepare('UPDATE crf_notifikasi SET tgl_dibaca = COALESCE(tgl_dibaca, NOW()) WHERE id = :id AND pengguna_id = :pengguna_id')
    ->execute(['id' => $id, 'pengguna_id' => $userId]);

// Hanya tautan relatif internal aplikasi.
$url = (string) $url;
if ($url === '' || preg_match('#^(?:[a-z]+:|//)#i', $url) || str_contains($url, '..')) {
    header('Location: index.php');
    exit;
}

header('Location: ../' . ltrim($url, '/'));
exit;
