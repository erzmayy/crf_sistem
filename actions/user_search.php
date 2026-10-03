<?php
/**
 * actions/user_search.php
 * JSON pencarian user SIAP untuk memilih Handler / PIC (Admin).
 */

require_once __DIR__ . '/../includes/auth.php';

requireAdmin();

header('Content-Type: application/json; charset=utf-8');

try {
    $users = searchCrfUsers(getConnection(), (string) ($_GET['q'] ?? ''));
    echo json_encode(array_map(static fn($user) => [
        'id'     => (int) $user['id'],
        'nama'   => (string) ($user['nama'] ?? ''),
        'userid' => (string) ($user['userid'] ?? ''),
        'dept'   => (string) ($user['dept'] ?? ''),
        'email'  => (string) ($user['email'] ?? ''),
    ], $users), JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('user_search error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Gagal mencari user.']);
}
