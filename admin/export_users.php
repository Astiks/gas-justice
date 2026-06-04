<?php
// admin/export_users.php
// Экспорт списка пользователей в CSV

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Проверка авторизации и прав
if (!isLoggedIn()) {
    redirect('/index.php');
}

$user = getCurrentUser();
if ($user['role'] !== 'chairman') {
    setFlashMessage('error', 'Доступ запрещён');
    redirect('/dashboard.php');
}

$pdo = getDB();

// Строим запрос с поиском и фильтрацией (как в админке)
$sql = "SELECT * FROM users WHERE 1=1";
$params = [];

// Поиск по нику, логину или email
if (!empty($_GET['user_search'])) {
    $sql .= " AND (in_game_name LIKE ? OR username LIKE ? OR email LIKE ?)";
    $search = '%' . $_GET['user_search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

if (!empty($_GET['user_filter_role'])) {
    $sql .= " AND role = ?";
    $params[] = $_GET['user_filter_role'];
}

if (!empty($_GET['user_filter_status'])) {
    $sql .= " AND is_active = ?";
    $params[] = $_GET['user_filter_status'] == 'active' ? 1 : 0;
}

$sql .= " ORDER BY role, in_game_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Устанавливаем заголовки для CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="users_export_' . date('Y-m-d_H-i-s') . '.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM для UTF-8

// Заголовки
fputcsv($output, [
    'ID',
    'Логин',
    'Игровой ник',
    'Email',
    'Роль',
    'Статус',
    'Дата регистрации',
    'Последний вход'
]);

// Роли для отображения
$role_labels = [
    'citizen' => 'Гражданин',
    'lawyer' => 'Адвокат',
    'prosecutor' => 'Прокурор',
    'judge' => 'Судья',
    'chairman' => 'Председатель'
];

// Данные
foreach ($users as $u) {
    fputcsv($output, [
        $u['id'],
        $u['username'],
        $u['in_game_name'],
        $u['email'],
        $role_labels[$u['role']] ?? $u['role'],
        $u['is_active'] ? 'Активен' : 'Заблокирован',
        date('d.m.Y H:i', strtotime($u['created_at'])),
        $u['last_login'] ? date('d.m.Y H:i', strtotime($u['last_login'])) : ''
    ]);
}

fclose($output);
exit;