<?php
// view-file.php
// Просмотр и скачивание загруженных файлов (доказательств)

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Проверка авторизации
if (!isLoggedIn()) {
    redirect('/index.php');
}

$file_path = $_GET['file'] ?? '';

if (empty($file_path)) {
    http_response_code(404);
    die('Файл не найден');
}

// Безопасность: проверяем, что файл находится в папке uploads
$real_path = realpath(__DIR__ . '/' . $file_path);
$uploads_dir = realpath(__DIR__ . '/uploads/');

if ($real_path === false || strpos($real_path, $uploads_dir) !== 0) {
    http_response_code(403);
    die('Доступ запрещён');
}

if (!file_exists($real_path)) {
    http_response_code(404);
    die('Файл не найден');
}

// Получаем информацию о файле из БД для проверки доступа
$pdo = getDB();
$stmt = $pdo->prepare("
    SELECT e.*, c.plaintiff_id, c.defendant_id, c.judge_id
    FROM evidence e
    JOIN court_cases c ON e.case_uid = c.uid
    WHERE e.file_path = ?
");
$stmt->execute([$file_path]);
$evidence = $stmt->fetch();

if ($evidence) {
    // Проверка доступа к файлу
    $user = getCurrentUser();
    $has_access = false;
    
    if ($user['role'] === 'chairman') {
        $has_access = true;
    } elseif ($user['role'] === 'judge') {
        $has_access = ($evidence['judge_id'] == $user['id']);
    } elseif ($user['id'] == $evidence['plaintiff_id'] || $user['id'] == $evidence['defendant_id']) {
        $has_access = true;
    }
    
    if (!$has_access) {
        http_response_code(403);
        die('Доступ запрещён');
    }
}

// Отдаём файл
$mime_type = mime_content_type($real_path);
header('Content-Type: ' . $mime_type);
header('Content-Disposition: inline; filename="' . basename($real_path) . '"');
header('Content-Length: ' . filesize($real_path));
readfile($real_path);
exit;