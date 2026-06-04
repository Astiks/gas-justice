<?php
// appeal.php
// Обработка подачи апелляционной жалобы

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!isLoggedIn()) {
    redirect('/index.php');
}

$user = getCurrentUser();
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/dashboard.php');
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('error', 'Неверный CSRF-токен');
    redirect('/dashboard.php');
}

$case_uid = $_POST['case_uid'] ?? '';
$appeal_reason = trim($_POST['appeal_reason'] ?? '');
$appeal_requirements = trim($_POST['appeal_requirements'] ?? '');

if (empty($case_uid) || empty($appeal_reason)) {
    setFlashMessage('error', 'Заполните все обязательные поля');
    redirect('/case.php?uid=' . urlencode($case_uid));
}

// Получаем информацию об оригинальном деле
$stmt = $pdo->prepare("
    SELECT c.*, u1.in_game_name as plaintiff_name, u2.in_game_name as defendant_name
    FROM court_cases c
    LEFT JOIN users u1 ON c.plaintiff_id = u1.id
    LEFT JOIN users u2 ON c.defendant_id = u2.id
    WHERE c.uid = ?
");
$stmt->execute([$case_uid]);
$original_case = $stmt->fetch();

if (!$original_case) {
    setFlashMessage('error', 'Дело не найдено');
    redirect('/dashboard.php');
}

// Проверяем, можно ли подать апелляцию
if (!canAppeal($original_case)) {
    setFlashMessage('error', 'Апелляция не может быть подана. Возможно, срок истёк или апелляция уже подана.');
    redirect('/case.php?uid=' . urlencode($case_uid));
}

// Проверяем, что пользователь является стороной дела
$is_participant = ($original_case['plaintiff_id'] == $user['id'] || $original_case['defendant_id'] == $user['id']);
if (!$is_participant && $user['role'] !== 'chairman') {
    setFlashMessage('error', 'Только стороны дела могут подать апелляцию');
    redirect('/case.php?uid=' . urlencode($case_uid));
}

try {
    // Создаём апелляционное дело
    $appeal_uid = generateUID($original_case['case_type_code']);
    $appeal_number = generateCaseNumber($original_case['case_type_code']);
    
    $appeal_title = "Апелляционная жалоба на дело № {$original_case['case_number_full']}";
    $appeal_description = "Апелляционная жалоба от " . $user['in_game_name'] . "\n\n";
    $appeal_description .= "Причина обжалования:\n" . $appeal_reason . "\n\n";
    $appeal_description .= "Требования:\n" . ($appeal_requirements ?: 'Не указаны');
    
    $stmt = $pdo->prepare("
        INSERT INTO court_cases (
            uid, case_type_code, case_type_name, 
            case_number_sequence, case_number_full, case_year,
            title, description, plaintiff_id, defendant_id,
            status, created_by, original_case_uid, appeal_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'accepted', ?, ?, 'pending')
    ");
    
    $stmt->execute([
        $appeal_uid,
        $original_case['case_type_code'],
        $original_case['case_type_name'],
        $appeal_number['sequence'],
        $appeal_number['full_number'],
        date('Y'),
        $appeal_title,
        $appeal_description,
        $original_case['plaintiff_id'],
        $original_case['defendant_id'],
        $user['id'],
        $case_uid
    ]);
    
    // Создаём запись в таблице appeals
    $stmt = $pdo->prepare("
        INSERT INTO appeals (case_uid, appeal_case_uid, appellant_id, appeal_reason, appeal_requirements)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$case_uid, $appeal_uid, $user['id'], $appeal_reason, $appeal_requirements]);
    
    // Обновляем статус оригинального дела
    $stmt = $pdo->prepare("UPDATE court_cases SET status = 'appealed' WHERE uid = ?");
    $stmt->execute([$case_uid]);
    
    // Запись в журнал движения дела (оригинал)
    addCaseMovement(
        $case_uid,
        'Апелляционное обжалование',
        'Подана апелляционная жалоба',
        "Апелляционное дело № {$appeal_number['full_number']}",
        $user['in_game_name']
    );
    
    // Запись в журнал движения дела (апелляция)
    addCaseMovement(
        $appeal_uid,
        'Апелляционное производство',
        'Поступление апелляционной жалобы',
        "На решение по делу № {$original_case['case_number_full']}",
        $user['in_game_name']
    );
    
    // Уведомление председателю суда (апелляционная инстанция)
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'chairman' AND is_active = 1");
    $stmt->execute();
    $chairmen = $stmt->fetchAll();
    
    foreach ($chairmen as $chairman) {
        createNotification(
            $chairman['id'],
            'appeal_filed',
            'Новая апелляционная жалоба',
            "Поступила апелляция на дело № {$original_case['case_number_full']}. Апелляционное дело № {$appeal_number['full_number']}",
            "/case.php?uid=" . urlencode($appeal_uid)
        );
    }
    
    auditLog($user['id'], 'appeal_filed', $case_uid, null, "appeal_case: $appeal_uid");
    
    setFlashMessage('success', "Апелляционная жалоба подана. Апелляционное дело № {$appeal_number['full_number']}");
    redirect('/case.php?uid=' . urlencode($appeal_uid));
    
} catch (PDOException $e) {
    setFlashMessage('error', 'Ошибка при подаче апелляции: ' . $e->getMessage());
    redirect('/case.php?uid=' . urlencode($case_uid));
}