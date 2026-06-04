<?php
// admin/export_cases.php
// Экспорт списка дел в CSV

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Проверка прав
if (!isLoggedIn()) {
    redirect('/index.php');
}

$user = getCurrentUser();
if ($user['role'] !== 'chairman') {
    setFlashMessage('error', 'Доступ запрещён');
    redirect('/dashboard.php');
}

$pdo = getDB();

// Строим запрос с фильтрацией (аналогично основному)
$sql = "
    SELECT c.case_number_full, c.case_type_name, c.title,
           u1.in_game_name as plaintiff_name,
           u2.in_game_name as defendant_name,
           u3.in_game_name as judge_name,
           c.status, c.created_at, c.updated_at
    FROM court_cases c
    LEFT JOIN users u1 ON c.plaintiff_id = u1.id
    LEFT JOIN users u2 ON c.defendant_id = u2.id
    LEFT JOIN users u3 ON c.judge_id = u3.id
    WHERE 1=1
";

$params = [];

if (!empty($_GET['filter_type'])) {
    $sql .= " AND c.case_type_code = ?";
    $params[] = $_GET['filter_type'];
}
if (!empty($_GET['filter_status'])) {
    $sql .= " AND c.status = ?";
    $params[] = $_GET['filter_status'];
}
if (!empty($_GET['filter_judge'])) {
    $sql .= " AND c.judge_id = ?";
    $params[] = $_GET['filter_judge'];
}
if (!empty($_GET['filter_year'])) {
    $sql .= " AND c.case_year = ?";
    $params[] = $_GET['filter_year'];
}
if (!empty($_GET['filter_date_from'])) {
    $sql .= " AND DATE(c.created_at) >= ?";
    $params[] = $_GET['filter_date_from'];
}
if (!empty($_GET['filter_date_to'])) {
    $sql .= " AND DATE(c.created_at) <= ?";
    $params[] = $_GET['filter_date_to'];
}
if (!empty($_GET['filter_search'])) {
    $sql .= " AND (c.case_number_full LIKE ? OR c.uid LIKE ? OR u1.in_game_name LIKE ? OR u2.in_game_name LIKE ?)";
    $search = '%' . $_GET['filter_search'] . '%';
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$sql .= " ORDER BY c.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cases = $stmt->fetchAll();

// Устанавливаем заголовки для CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="cases_export_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM для UTF-8

// Заголовки
fputcsv($output, [
    'Номер дела',
    'Тип дела',
    'Название',
    'Истец',
    'Ответчик',
    'Судья',
    'Статус',
    'Дата создания'
]);

// Данные
foreach ($cases as $case) {
    fputcsv($output, [
        $case['case_number_full'],
        $case['case_type_name'],
        $case['title'],
        $case['plaintiff_name'] ?? '',
        $case['defendant_name'] ?? '',
        $case['judge_name'] ?? '',
        getStatusName($case['status'], $case['case_type_code']),
        date('d.m.Y', strtotime($case['created_at']))
    ]);
}

fclose($output);
exit;