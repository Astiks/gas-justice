<?php
// ajax_check_user.php
// Проверка существования пользователя по нику

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');

$nick = $_GET['nick'] ?? '';
if (strlen($nick) < 2) {
    echo json_encode(['exists' => false]);
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT id FROM users WHERE in_game_name = ? OR username = ?");
$stmt->execute([$nick, $nick]);
$user = $stmt->fetch();

echo json_encode(['exists' => (bool)$user, 'in_game_name' => $user ? $user['in_game_name'] : null]);