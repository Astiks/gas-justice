<?php
// view-court-file.php
// Просмотр и скачивание судейских документов

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Проверка авторизации
if (!isLoggedIn()) {
    redirect('/index.php');
}

$user = getCurrentUser();
$file_id = intval($_GET['id'] ?? 0);

if (!$file_id) {
    http_response_code(404);
    die('Файл не найден');
}

$pdo = getDB();

// Получаем информацию о файле и проверяем доступ
$stmt = $pdo->prepare("
    SELECT cd.*, c.plaintiff_id, c.defendant_id, c.judge_id, c.case_number_full
    FROM court_documents cd
    JOIN court_cases c ON cd.case_uid = c.uid
    WHERE cd.id = ?
");
$stmt->execute([$file_id]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    die('Файл не найден');
}

// Проверка доступа
$has_access = false;

if ($user['role'] === 'chairman') {
    $has_access = true;
} elseif ($user['role'] === 'judge') {
    $has_access = true;
} elseif ($user['id'] == $file['plaintiff_id'] || $user['id'] == $file['defendant_id']) {
    $has_access = true;
}

if (!$has_access) {
    http_response_code(403);
    die('Доступ запрещён');
}

// Отдаём файл
$file_path = __DIR__ . '/' . $file['file_path'];

if (!file_exists($file_path)) {
    http_response_code(404);
    die('Файл не найден на сервере');
}

header('Content-Type: ' . $file['file_mime']);
header('Content-Disposition: inline; filename="' . $file['file_name'] . '"');
header('Content-Length: ' . $file['file_size']);
header('Cache-Control: private, max-age=3600');
readfile($file_path);
exit;