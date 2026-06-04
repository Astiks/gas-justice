<?php
// case.php
// Карточка дела — просмотр всех деталей, управление для судей/прокуроров

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Проверка авторизации
if (!isLoggedIn()) {
    redirect('/index.php');
}

$user = getCurrentUser();
$uid = $_GET['uid'] ?? '';

if (empty($uid)) {
    redirect('/dashboard.php');
}

$pdo = getDB();

// ============================================
// ОБРАБОТКА ЗАГРУЗКИ ДОКАЗАТЕЛЬСТВ (ДО ПОЛУЧЕНИЯ ДАННЫХ ДЕЛА)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_evidence') {
    // Получаем дело для проверки прав
    $stmt = $pdo->prepare("SELECT * FROM court_cases WHERE uid = ?");
    $stmt->execute([$uid]);
    $case_data = $stmt->fetch();
    
    if (!$case_data) {
        setFlashMessage('error', 'Дело не найдено');
        redirect('/dashboard.php');
    }
    
    // Проверка прав: истец или ответчик
    $can_upload = ($user['id'] == $case_data['plaintiff_id'] || $user['id'] == $case_data['defendant_id']);
    
    // Для адвоката
    if (!$can_upload && $user['role'] === 'lawyer') {
        if (($case_data['plaintiff_id'] && isLawyerOfClient($user['id'], $case_data['plaintiff_id'])) ||
            ($case_data['defendant_id'] && isLawyerOfClient($user['id'], $case_data['defendant_id']))) {
            $can_upload = true;
        }
    }
    
    if (!$can_upload) {
        setFlashMessage('error', 'У вас нет прав на загрузку доказательств');
        redirect('/case.php?uid=' . urlencode($uid));
    }
    
    if ($case_data['status'] === 'archived') {
        setFlashMessage('error', 'Нельзя добавлять доказательства в архивное дело');
        redirect('/case.php?uid=' . urlencode($uid));
    }
    
    if (empty($_FILES['evidence_file']['name'])) {
        setFlashMessage('error', 'Выберите файл для загрузки');
        redirect('/case.php?uid=' . urlencode($uid));
    }
    
    $upload_dir = __DIR__ . '/uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $file = $_FILES['evidence_file'];
    $original_name = basename($file['name']);
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'webm', 'txt', 'log', 'pdf', 'doc', 'docx', 'rtf'];
    
    if (!in_array($ext, $allowed_ext)) {
        setFlashMessage('error', 'Недопустимый тип файла. Разрешены: ' . implode(', ', $allowed_ext));
        redirect('/case.php?uid=' . urlencode($uid));
    }
    
    if ($file['size'] > 10 * 1024 * 1024) {
        setFlashMessage('error', 'Файл не должен превышать 10 МБ');
        redirect('/case.php?uid=' . urlencode($uid));
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        setFlashMessage('error', 'Ошибка при загрузке файла (код: ' . $file['error'] . ')');
        redirect('/case.php?uid=' . urlencode($uid));
    }
    
    $safe_name = $uid . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $target_path = $upload_dir . $safe_name;
    
    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        $title = trim($_POST['evidence_title'] ?? $original_name);
        if (empty($title)) {
            $title = $original_name;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO evidence (case_uid, evidence_type, title, file_path, uploaded_by)
            VALUES (?, 'document', ?, ?, ?)
        ");
        $stmt->execute([$uid, $title, 'uploads/' . $safe_name, $user['id']]);
        
        auditLog($user['id'], 'upload_evidence', $uid, null, "title: $title, file: $original_name");
        
        addCaseMovement($uid, 'Подготовка дела', 'Добавление доказательства', 
            "Добавлено доказательство: $title", $user['in_game_name']);
        
        if ($case_data['judge_id']) {
            createNotification($case_data['judge_id'], 'evidence_added', 'Новое доказательство', 
                "По делу {$case_data['case_number_full']} добавлено новое доказательство: $title", 
                "/case.php?uid=" . urlencode($uid));
        }
        
        $other_party_id = ($user['id'] == $case_data['plaintiff_id']) ? $case_data['defendant_id'] : $case_data['plaintiff_id'];
        if ($other_party_id) {
            createNotification($other_party_id, 'evidence_added', 'Новое доказательство', 
                "По делу {$case_data['case_number_full']} добавлено новое доказательство: $title", 
                "/case.php?uid=" . urlencode($uid));
        }
        
        setFlashMessage('success', 'Доказательство загружено');
    } else {
        setFlashMessage('error', 'Не удалось сохранить файл. Проверьте права на папку uploads/');
    }
    
    redirect('/case.php?uid=' . urlencode($uid));
}

// ============================================
// ОБРАБОТКА НАЗНАЧЕНИЯ ПРОКУРОРА (ДЛЯ ПРОКУРОРОВ)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_prosecutor_self') {
    $case_uid = $_POST['case_uid'] ?? '';
    if ($case_uid) {
        // Проверяем, что дело существует и прокурор ещё не назначен
        $stmt = $pdo->prepare("SELECT prosecutor_id, case_number_full FROM court_cases WHERE uid = ?");
        $stmt->execute([$case_uid]);
        $case_data = $stmt->fetch();
        
        if (!$case_data) {
            setFlashMessage('error', 'Дело не найдено');
        } elseif ($case_data['prosecutor_id']) {
            setFlashMessage('error', 'Прокурор уже назначен');
        } else {
            $stmt = $pdo->prepare("UPDATE court_cases SET prosecutor_id = ? WHERE uid = ?");
            $stmt->execute([$user['id'], $case_uid]);
            
            auditLog($user['id'], 'assign_prosecutor_self', $case_uid, null, "prosecutor_id: {$user['id']}");
            addCaseMovement($case_uid, 'Подготовка дела', 'Назначение прокурора', 
                "Прокурор: " . $user['in_game_name'], $user['in_game_name']);
            
            // Уведомляем председателя
            $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'chairman' LIMIT 1");
            $stmt->execute();
            $chairman = $stmt->fetch();
            if ($chairman) {
                createNotification($chairman['id'], 'prosecutor_assigned', 'Назначен прокурор', 
                    "Прокурор " . $user['in_game_name'] . " назначен на дело {$case_data['case_number_full']}", 
                    "/case.php?uid=" . urlencode($case_uid));
            }
            
            setFlashMessage('success', 'Вы назначены прокурором по делу');
        }
    }
    redirect('/case.php?uid=' . urlencode($case_uid));
}

// Получаем информацию о деле
$stmt = $pdo->prepare("
    SELECT c.*,
           u1.in_game_name as plaintiff_name, u1.id as plaintiff_id,
           u2.in_game_name as defendant_name, u2.id as defendant_id,
           u3.in_game_name as prosecutor_name, u3.id as prosecutor_id,
           u4.in_game_name as judge_name, u4.id as judge_id,
           u5.in_game_name as created_by_name,
           oc.case_number_full as original_case_number
    FROM court_cases c
    LEFT JOIN users u1 ON c.plaintiff_id = u1.id
    LEFT JOIN users u2 ON c.defendant_id = u2.id
    LEFT JOIN users u3 ON c.prosecutor_id = u3.id
    LEFT JOIN users u4 ON c.judge_id = u4.id
    LEFT JOIN users u5 ON c.created_by = u5.id
    LEFT JOIN court_cases oc ON c.original_case_uid = oc.uid
    WHERE c.uid = ?
");
$stmt->execute([$uid]);
$case = $stmt->fetch();

if (!$case) {
    setFlashMessage('error', 'Дело не найдено');
    redirect('/dashboard.php');
}

// Проверка прав доступа к делу
$can_view = false;
$can_manage = false;
$can_prosecute = false;
$can_edit = false;

if ($user['role'] === 'chairman') {
    $can_view = true;
    $can_manage = true;
    $can_prosecute = true;
    $can_edit = true;
} elseif ($user['role'] === 'judge') {
    $can_view = true;
    $can_manage = ($case['judge_id'] == $user['id']);
    $can_edit = ($case['judge_id'] == $user['id']);
} elseif ($user['role'] === 'prosecutor') {
    $can_view = true;
    $can_prosecute = ($case['prosecutor_id'] == $user['id'] || $case['prosecutor_id'] === null);
} elseif ($user['role'] === 'lawyer') {
    $can_view = ($case['plaintiff_id'] == $user['id'] || $case['defendant_id'] == $user['id']);
    if (!$can_view) {
        if ($case['plaintiff_id'] && isLawyerOfClient($user['id'], $case['plaintiff_id'])) {
            $can_view = true;
        }
        if ($case['defendant_id'] && isLawyerOfClient($user['id'], $case['defendant_id'])) {
            $can_view = true;
        }
    }
} else {
    $can_view = ($case['plaintiff_id'] == $user['id'] || $case['defendant_id'] == $user['id']);
}

if (!$can_view) {
    setFlashMessage('error', 'У вас нет доступа к этому делу');
    redirect('/dashboard.php');
}

// Проверка права на загрузку доказательств
$can_upload_evidence = false;
if ($user['id'] == $case['plaintiff_id'] || $user['id'] == $case['defendant_id']) {
    $can_upload_evidence = true;
} elseif ($user['role'] === 'lawyer') {
    if (($case['plaintiff_id'] && isLawyerOfClient($user['id'], $case['plaintiff_id'])) ||
        ($case['defendant_id'] && isLawyerOfClient($user['id'], $case['defendant_id']))) {
        $can_upload_evidence = true;
    }
}

// Получаем документы
$stmt = $pdo->prepare("
    SELECT d.*, u.in_game_name as author_name
    FROM documents d
    LEFT JOIN users u ON d.author_id = u.id
    WHERE d.case_uid = ?
    ORDER BY d.created_at DESC
");
$stmt->execute([$uid]);
$documents = $stmt->fetchAll();

// Получаем судейские файлы
$stmt = $pdo->prepare("
    SELECT cd.*, u.in_game_name as author_name
    FROM court_documents cd
    LEFT JOIN users u ON cd.author_id = u.id
    WHERE cd.case_uid = ?
    ORDER BY cd.created_at DESC
");
$stmt->execute([$uid]);
$court_files = $stmt->fetchAll();

// Получаем доказательства
$stmt = $pdo->prepare("
    SELECT e.*, u1.in_game_name as uploaded_by_name, u2.in_game_name as verified_by_name
    FROM evidence e
    LEFT JOIN users u1 ON e.uploaded_by = u1.id
    LEFT JOIN users u2 ON e.verified_by = u2.id
    WHERE e.case_uid = ?
    ORDER BY e.created_at DESC
");
$stmt->execute([$uid]);
$evidence_list = $stmt->fetchAll();

// Получаем заседания
$stmt = $pdo->prepare("
    SELECT cs.*, u.in_game_name as judge_name
    FROM court_sessions cs
    LEFT JOIN users u ON cs.judge_id = u.id
    WHERE cs.case_uid = ?
    ORDER BY cs.scheduled_at ASC
");
$stmt->execute([$uid]);
$sessions = $stmt->fetchAll();

// Получаем реестр обременений
$stmt = $pdo->prepare("SELECT * FROM public_registry WHERE case_uid = ?");
$stmt->execute([$uid]);
$registry = $stmt->fetch();

// Получаем журнал движения дела
$stmt = $pdo->prepare("
    SELECT * FROM case_movement 
    WHERE case_uid = ? 
    ORDER BY movement_date ASC, id ASC
");
$stmt->execute([$uid]);
$movements = $stmt->fetchAll();

// Получаем информацию об апелляции
$appeal_info = getAppealInfo($case['uid']);
$can_appeal = canAppeal($case);
$is_participant = ($case['plaintiff_id'] == $user['id'] || $case['defendant_id'] == $user['id']);

// Получаем список пользователей для выпадающих списков (для админа)
$all_users = [];
if ($user['role'] === 'chairman') {
    $stmt = $pdo->query("SELECT id, in_game_name FROM users ORDER BY in_game_name");
    $all_users = $stmt->fetchAll();
}

// Обработка остальных действий
$action_result = null;
$should_redirect = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($can_manage || $can_edit)) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $action_result = ['success' => false, 'message' => 'Неверный CSRF-токен'];
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'update_indictment':
                $indictment_text = trim($_POST['indictment_text'] ?? '');
                
                if (strlen($indictment_text) < 20) {
                    $action_result = ['success' => false, 'message' => 'Обвинительное заключение слишком короткое'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE court_cases 
                        SET indictment_text = ?, indictment_approved = 1 
                        WHERE uid = ? AND prosecutor_id = ?
                    ");
                    $stmt->execute([$indictment_text, $uid, $user['id']]);
                    
                    auditLog($user['id'], 'update_indictment', $uid, null, "indictment approved");
                    addCaseMovement($uid, 'Подготовка дела', 'Утверждение обвинительного заключения', 
                        "Обвинительное заключение утверждено", $user['in_game_name'] . ' (прокурор)');
                    
                    if ($case['judge_id']) {
                        createNotification($case['judge_id'], 'indictment_ready', 'Обвинительное заключение готово', 
                            "Прокурор утвердил обвинительное заключение по делу {$case['case_number_full']}", 
                            "/case.php?uid=" . urlencode($uid));
                    }
                    
                    $action_result = ['success' => true, 'message' => 'Обвинительное заключение утверждено'];
                    $should_redirect = true;
                }
                break;
                
            case 'edit_case':
                if (!$can_edit) {
                    $action_result = ['success' => false, 'message' => 'У вас нет прав на редактирование этого дела'];
                    break;
                }
                
                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $article_code = trim($_POST['article_code'] ?? '');
                $article_text = trim($_POST['article_text'] ?? '');
                $vk = trim($_POST['vk'] ?? '');
                
                if ($user['role'] === 'chairman') {
                    $plaintiff_id = !empty($_POST['plaintiff_id']) ? intval($_POST['plaintiff_id']) : null;
                    $defendant_id = !empty($_POST['defendant_id']) ? intval($_POST['defendant_id']) : null;
                    $judge_id = !empty($_POST['judge_id']) ? intval($_POST['judge_id']) : null;
                    $prosecutor_id = !empty($_POST['prosecutor_id']) ? intval($_POST['prosecutor_id']) : null;
                }
                
                if (empty($title)) {
                    $action_result = ['success' => false, 'message' => 'Название дела не может быть пустым'];
                    break;
                }
                
                $sql = "UPDATE court_cases SET title = ?, description = ?, article_code = ?, article_text = ?, vk = ?";
                $params = [$title, $description, $article_code, $article_text, $vk];
                
                if ($user['role'] === 'chairman') {
                    $sql .= ", plaintiff_id = ?, defendant_id = ?, judge_id = ?, prosecutor_id = ?";
                    $params[] = $plaintiff_id;
                    $params[] = $defendant_id;
                    $params[] = $judge_id;
                    $params[] = $prosecutor_id;
                }
                
                $sql .= " WHERE uid = ?";
                $params[] = $uid;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                auditLog($user['id'], 'edit_case', $uid, null, "title: $title");
                addCaseMovement($uid, 'Подготовка дела', 'Редактирование карточки дела', 
                    "Данные дела обновлены", $user['in_game_name']);
                
                $action_result = ['success' => true, 'message' => 'Данные дела обновлены'];
                $should_redirect = true;
                break;
                
            case 'change_status':
                $new_status = $_POST['status'] ?? '';
                $valid_statuses = ['draft', 'accepted', 'preparing', 'trial', 'verdict', 'appealed', 'archived'];
                
                if (in_array($new_status, $valid_statuses)) {
                    $old_status = $case['status'];
                    $stmt = $pdo->prepare("UPDATE court_cases SET status = ? WHERE uid = ?");
                    $stmt->execute([$new_status, $uid]);
                    
                    auditLog($user['id'], 'status_change', $uid, $old_status, $new_status);
                    
                    $old_status_name = getStatusName($old_status, $case['case_type_code']);
                    $new_status_name = getStatusName($new_status, $case['case_type_code']);
                    addCaseMovement($uid, 'Подготовка дела', 'Изменение статуса дела', "$old_status_name → $new_status_name", $user['in_game_name']);
                    
                    if ($case['plaintiff_id']) {
                        createNotification($case['plaintiff_id'], 'status_change', 'Изменение статуса дела', 
                            "Статус дела {$case['case_number_full']} изменён на: " . getStatusName($new_status, $case['case_type_code']), 
                            "/case.php?uid=" . urlencode($uid));
                    }
                    if ($case['defendant_id']) {
                        createNotification($case['defendant_id'], 'status_change', 'Изменение статуса дела', 
                            "Статус дела {$case['case_number_full']} изменён на: " . getStatusName($new_status, $case['case_type_code']), 
                            "/case.php?uid=" . urlencode($uid));
                    }
                    
                    $action_result = ['success' => true, 'message' => 'Статус дела изменён'];
                    $case['status'] = $new_status;
                    $should_redirect = true;
                } else {
                    $action_result = ['success' => false, 'message' => 'Неверный статус'];
                }
                break;
                
            case 'assign_judge':
                $judge_id = $_POST['judge_id'] ?? '';
                if ($judge_id) {
                    $stmt = $pdo->prepare("SELECT in_game_name FROM users WHERE id = ?");
                    $stmt->execute([$judge_id]);
                    $judge = $stmt->fetch();
                    $judge_name = $judge['in_game_name'] ?? 'Unknown';
                    
                    $stmt = $pdo->prepare("UPDATE court_cases SET judge_id = ? WHERE uid = ?");
                    $stmt->execute([$judge_id, $uid]);
                    
                    auditLog($user['id'], 'assign_judge', $uid, null, "judge_id: $judge_id");
                    
                    addCaseMovement($uid, 'Подготовка дела', 'Назначение судьи', "Судья: $judge_name", $user['in_game_name']);
                    
                    createNotification($judge_id, 'case_assigned', 'Назначено дело', 
                        "Вам назначено дело {$case['case_number_full']}", 
                        "/case.php?uid=" . urlencode($uid));
                    
                    $action_result = ['success' => true, 'message' => 'Судья назначен'];
                    $case['judge_name'] = $judge_name;
                    $case['judge_id'] = $judge_id;
                    $should_redirect = true;
                }
                break;
                
            case 'assign_prosecutor':
                $prosecutor_id = $_POST['prosecutor_id'] ?? '';
                if ($prosecutor_id) {
                    $stmt = $pdo->prepare("SELECT in_game_name FROM users WHERE id = ?");
                    $stmt->execute([$prosecutor_id]);
                    $prosecutor = $stmt->fetch();
                    $prosecutor_name = $prosecutor['in_game_name'] ?? 'Unknown';
                    
                    $stmt = $pdo->prepare("UPDATE court_cases SET prosecutor_id = ? WHERE uid = ?");
                    $stmt->execute([$prosecutor_id, $uid]);
                    
                    auditLog($user['id'], 'assign_prosecutor', $uid, null, "prosecutor_id: $prosecutor_id");
                    
                    addCaseMovement($uid, 'Подготовка дела', 'Назначение прокурора', "Прокурор: $prosecutor_name", $user['in_game_name']);
                    
                    createNotification($prosecutor_id, 'case_assigned', 'Назначено дело для обвинения', 
                        "Вам назначено дело {$case['case_number_full']} для поддержания обвинения", 
                        "/case.php?uid=" . urlencode($uid));
                    
                    $action_result = ['success' => true, 'message' => 'Прокурор назначен'];
                    $case['prosecutor_name'] = $prosecutor_name;
                    $case['prosecutor_id'] = $prosecutor_id;
                    $should_redirect = true;
                }
                break;
                
            case 'add_session':
                $scheduled_at = $_POST['scheduled_at'] ?? '';
                $duration = isset($_POST['duration']) ? intval($_POST['duration']) : 60;
                if ($duration < 15) $duration = 15;
                if ($duration > 480) $duration = 480;
                
                if (empty($scheduled_at)) {
                    $action_result = ['success' => false, 'message' => 'Укажите дату и время заседания'];
                } else {
                    $stmt = $pdo->prepare("SELECT id FROM court_sessions WHERE case_uid = ? AND scheduled_at = ?");
                    $stmt->execute([$uid, $scheduled_at]);
                    if ($stmt->fetch()) {
                        $action_result = ['success' => false, 'message' => 'Заседание на это время уже назначено'];
                    } else {
                        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM court_sessions WHERE case_uid = ?");
                        $stmt->execute([$uid]);
                        $session_num = $stmt->fetch()['cnt'] + 1;
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO court_sessions (case_uid, session_number, scheduled_at, duration_minutes, judge_id)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$uid, $session_num, $scheduled_at, $duration, $user['id']]);
                        
                        auditLog($user['id'], 'add_session', $uid, null, "scheduled_at: $scheduled_at, duration: $duration");
                        
                        addCaseMovement($uid, 'Судебное разбирательство', 'Назначение дела к судебному разбирательству', 
                            date('d.m.Y H:i', strtotime($scheduled_at)) . ' (' . $duration . ' мин.)', $user['in_game_name'] . ' (судья)');
                        
                        if ($case['plaintiff_id']) {
                            createNotification($case['plaintiff_id'], 'session_scheduled', 'Назначено заседание', 
                                "По делу {$case['case_number_full']} назначено заседание на " . date('d.m.Y H:i', strtotime($scheduled_at)) . " (продолжительность: {$duration} мин.)", 
                                "/case.php?uid=" . urlencode($uid));
                        }
                        if ($case['defendant_id']) {
                            createNotification($case['defendant_id'], 'session_scheduled', 'Назначено заседание', 
                                "По делу {$case['case_number_full']} назначено заседание на " . date('d.m.Y H:i', strtotime($scheduled_at)) . " (продолжительность: {$duration} мин.)", 
                                "/case.php?uid=" . urlencode($uid));
                        }
                        
                        $action_result = ['success' => true, 'message' => 'Заседание назначено'];
                        $should_redirect = true;
                    }
                }
                break;
                
            case 'update_session_status':
                $session_id = intval($_POST['session_id'] ?? 0);
                $new_status = $_POST['session_status'] ?? '';
                $status_comment = trim($_POST['status_comment'] ?? '');
                $valid_statuses = ['scheduled', 'in_progress', 'postponed', 'completed', 'cancelled'];
                
                if (!$session_id || !in_array($new_status, $valid_statuses)) {
                    $action_result = ['success' => false, 'message' => 'Неверные параметры'];
                    break;
                }
                
                $stmt = $pdo->prepare("SELECT * FROM court_sessions WHERE id = ? AND case_uid = ?");
                $stmt->execute([$session_id, $uid]);
                $session = $stmt->fetch();
                
                if (!$session) {
                    $action_result = ['success' => false, 'message' => 'Заседание не найдено'];
                    break;
                }
                
                $old_status = $session['status'];
                $status_labels = ['scheduled' => 'Запланировано', 'in_progress' => 'В процессе', 'postponed' => 'Перенесено', 'completed' => 'Завершено', 'cancelled' => 'Отменено'];
                
                $stmt = $pdo->prepare("UPDATE court_sessions SET status = ?, status_comment = ? WHERE id = ? AND case_uid = ?");
                $stmt->execute([$new_status, $status_comment, $session_id, $uid]);
                
                auditLog($user['id'], 'update_session_status', $uid, null, "session_id: $session_id, status: $old_status → $new_status");
                
                $movement_text = "Статус заседания №{$session['session_number']} изменён: " . ($status_labels[$old_status] ?? $old_status) . " → " . ($status_labels[$new_status] ?? $new_status);
                if ($status_comment) $movement_text .= " (" . $status_comment . ")";
                addCaseMovement($uid, 'Судебное разбирательство', 'Изменение статуса заседания', $movement_text, $user['in_game_name'] . ' (судья)');
                
                if ($case['plaintiff_id']) {
                    createNotification($case['plaintiff_id'], 'session_update', 'Статус заседания изменён', 
                        "По делу {$case['case_number_full']} статус заседания №{$session['session_number']} изменён на: " . ($status_labels[$new_status] ?? $new_status),
                        "/case.php?uid=" . urlencode($uid));
                }
                if ($case['defendant_id']) {
                    createNotification($case['defendant_id'], 'session_update', 'Статус заседания изменён', 
                        "По делу {$case['case_number_full']} статус заседания №{$session['session_number']} изменён на: " . ($status_labels[$new_status] ?? $new_status),
                        "/case.php?uid=" . urlencode($uid));
                }
                
                $action_result = ['success' => true, 'message' => 'Статус заседания обновлён'];
                $should_redirect = true;
                break;
                
            case 'reschedule_session':
                $session_id = intval($_POST['session_id'] ?? 0);
                $new_datetime = $_POST['new_datetime'] ?? '';
                $reschedule_reason = trim($_POST['reschedule_reason'] ?? '');
                
                if (!$session_id || empty($new_datetime)) {
                    $action_result = ['success' => false, 'message' => 'Укажите новую дату и время'];
                    break;
                }
                
                $stmt = $pdo->prepare("SELECT * FROM court_sessions WHERE id = ? AND case_uid = ?");
                $stmt->execute([$session_id, $uid]);
                $session = $stmt->fetch();
                
                if (!$session) {
                    $action_result = ['success' => false, 'message' => 'Заседание не найдено'];
                    break;
                }
                
                $old_datetime = $session['scheduled_at'];
                
                $stmt = $pdo->prepare("UPDATE court_sessions SET scheduled_at = ?, status = 'postponed', status_comment = ? WHERE id = ? AND case_uid = ?");
                $stmt->execute([$new_datetime, $reschedule_reason, $session_id, $uid]);
                
                auditLog($user['id'], 'reschedule_session', $uid, null, "session_id: $session_id, old: $old_datetime, new: $new_datetime");
                
                addCaseMovement($uid, 'Судебное разбирательство', 'Перенос заседания', 
                    "Заседание №{$session['session_number']} перенесено с " . date('d.m.Y H:i', strtotime($old_datetime)) . " на " . date('d.m.Y H:i', strtotime($new_datetime)) . ($reschedule_reason ? " (Причина: $reschedule_reason)" : ""),
                    $user['in_game_name'] . ' (судья)');
                
                if ($case['plaintiff_id']) {
                    createNotification($case['plaintiff_id'], 'session_rescheduled', 'Заседание перенесено', 
                        "Заседание по делу {$case['case_number_full']} перенесено на " . date('d.m.Y H:i', strtotime($new_datetime)),
                        "/case.php?uid=" . urlencode($uid));
                }
                if ($case['defendant_id']) {
                    createNotification($case['defendant_id'], 'session_rescheduled', 'Заседание перенесено', 
                        "Заседание по делу {$case['case_number_full']} перенесено на " . date('d.m.Y H:i', strtotime($new_datetime)),
                        "/case.php?uid=" . urlencode($uid));
                }
                
                $action_result = ['success' => true, 'message' => 'Заседание перенесено'];
                $should_redirect = true;
                break;
                
            case 'delete_session':
                $session_id = intval($_POST['session_id'] ?? 0);
                $delete_reason = trim($_POST['delete_reason'] ?? '');
                
                if (!$session_id) {
                    $action_result = ['success' => false, 'message' => 'Неверный идентификатор заседания'];
                    break;
                }
                
                $stmt = $pdo->prepare("SELECT * FROM court_sessions WHERE id = ? AND case_uid = ?");
                $stmt->execute([$session_id, $uid]);
                $session = $stmt->fetch();
                
                if (!$session) {
                    $action_result = ['success' => false, 'message' => 'Заседание не найдено'];
                    break;
                }
                
                $stmt = $pdo->prepare("DELETE FROM court_sessions WHERE id = ? AND case_uid = ?");
                $stmt->execute([$session_id, $uid]);
                
                auditLog($user['id'], 'delete_session', $uid, null, "session_id: $session_id, reason: $delete_reason");
                
                addCaseMovement($uid, 'Судебное разбирательство', 'Удаление заседания', 
                    "Заседание №{$session['session_number']} удалено" . ($delete_reason ? " (Причина: $delete_reason)" : ""),
                    $user['in_game_name'] . ' (судья)');
                
                if ($case['plaintiff_id']) {
                    createNotification($case['plaintiff_id'], 'session_deleted', 'Заседание отменено', 
                        "Заседание по делу {$case['case_number_full']} отменено" . ($delete_reason ? " Причина: $delete_reason" : ""),
                        "/case.php?uid=" . urlencode($uid));
                }
                if ($case['defendant_id']) {
                    createNotification($case['defendant_id'], 'session_deleted', 'Заседание отменено', 
                        "Заседание по делу {$case['case_number_full']} отменено" . ($delete_reason ? " Причина: $delete_reason" : ""),
                        "/case.php?uid=" . urlencode($uid));
                }
                
                $action_result = ['success' => true, 'message' => 'Заседание удалено'];
                $should_redirect = true;
                break;
                
            case 'submit_verdict':
                $verdict_text = trim($_POST['verdict_text'] ?? '');
                $punishment_fine = intval($_POST['punishment_fine'] ?? 0);
                $punishment_arrest = intval($_POST['punishment_arrest'] ?? 0);
                
                if (strlen($verdict_text) < 10) {
                    $action_result = ['success' => false, 'message' => 'Текст приговора слишком короткий'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE court_cases 
                        SET verdict_text = ?, punishment_fine = ?, punishment_arrest_hours = ?, status = 'verdict'
                        WHERE uid = ?
                    ");
                    $stmt->execute([$verdict_text, $punishment_fine, $punishment_arrest, $uid]);
                    
                    auditLog($user['id'], 'submit_verdict', $uid, null, "fine: $punishment_fine, arrest: $punishment_arrest");
                    
                    $document_name = getVerdictDocumentName($case['case_type_code']);
                    addCaseMovement($uid, 'Вынесение решения', "Вынесение $document_name", 
                        substr($verdict_text, 0, 200) . (strlen($verdict_text) > 200 ? '...' : ''), $user['in_game_name'] . ' (судья)');
                    
                    if ($punishment_fine > 0 || $punishment_arrest > 0) {
                        $obligation = [];
                        if ($punishment_fine > 0) $obligation[] = "Штраф: {$punishment_fine}$";
                        if ($punishment_arrest > 0) $obligation[] = "Арест: {$punishment_arrest} часов";
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO public_registry (case_uid, citizen_id, obligation_text, amount, due_date)
                            VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))
                            ON DUPLICATE KEY UPDATE
                            obligation_text = VALUES(obligation_text), amount = VALUES(amount), declared_status = 'pending'
                        ");
                        $stmt->execute([$uid, $case['defendant_id'], implode('; ', $obligation), $punishment_fine]);
                    }
                    
                    if ($case['plaintiff_id']) {
                        createNotification($case['plaintiff_id'], 'verdict', 'Вынесен приговор', 
                            "По делу {$case['case_number_full']} вынесен приговор", 
                            "/case.php?uid=" . urlencode($uid));
                    }
                    if ($case['defendant_id']) {
                        createNotification($case['defendant_id'], 'verdict', 'Вынесен приговор', 
                            "По делу {$case['case_number_full']} вынесен приговор. " . ($punishment_fine > 0 ? "Штраф: {$punishment_fine}$" : ""), 
                            "/case.php?uid=" . urlencode($uid));
                    }
                    
                    $action_result = ['success' => true, 'message' => 'Приговор вынесен и опубликован'];
                    $should_redirect = true;
                }
                break;
                
            case 'upload_court_document':
                $document_title = trim($_POST['document_title'] ?? '');
                $document_content = trim($_POST['document_content'] ?? '');
                
                if (empty($document_title)) {
                    $action_result = ['success' => false, 'message' => 'Укажите название документа'];
                } else {
                    $full_content = "ДОКУМЕНТ СУДА\n\n";
                    $full_content .= "Дело №: " . $case['case_number_full'] . "\n";
                    $full_content .= "Судья: " . $user['in_game_name'] . "\n";
                    $full_content .= "Дата: " . date('d.m.Y H:i:s') . "\n\n";
                    $full_content .= $document_content . "\n\n";
                    $full_content .= "____________________\n";
                    $full_content .= "Судья " . $user['in_game_name'];
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO documents (case_uid, document_type, title, content, author_id)
                        VALUES (?, 'court_document', ?, ?, ?)
                    ");
                    $stmt->execute([$uid, $document_title, $full_content, $user['id']]);
                    
                    auditLog($user['id'], 'upload_court_document', $uid, null, "title: $document_title");
                    
                    addCaseMovement($uid, 'Подготовка дела', 'Прикрепление судебного документа', $document_title, $user['in_game_name'] . ' (судья)');
                    
                    if ($case['plaintiff_id']) {
                        createNotification($case['plaintiff_id'], 'evidence_added', 'Новый документ по делу', 
                            "Судья прикрепил документ: $document_title", 
                            "/case.php?uid=" . urlencode($uid));
                    }
                    if ($case['defendant_id']) {
                        createNotification($case['defendant_id'], 'evidence_added', 'Новый документ по делу', 
                            "Судья прикрепил документ: $document_title", 
                            "/case.php?uid=" . urlencode($uid));
                    }
                    
                    $action_result = ['success' => true, 'message' => 'Текстовый документ прикреплён'];
                    $should_redirect = true;
                }
                break;
                
            case 'upload_court_document_file':
                $document_title = trim($_POST['document_title'] ?? '');
                
                if (empty($document_title)) {
                    $action_result = ['success' => false, 'message' => 'Укажите название документа'];
                } elseif (empty($_FILES['document_file']['name'])) {
                    $action_result = ['success' => false, 'message' => 'Выберите файл для загрузки'];
                } else {
                    $upload_dir = __DIR__ . '/uploads/court_documents/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    $file = $_FILES['document_file'];
                    $original_name = basename($file['name']);
                    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                    $allowed_ext = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'txt', 'rtf'];
                    
                    if (!in_array($ext, $allowed_ext)) {
                        $action_result = ['success' => false, 'message' => 'Недопустимый тип файла'];
                    } elseif ($file['size'] > 10 * 1024 * 1024) {
                        $action_result = ['success' => false, 'message' => 'Файл не должен превышать 10 МБ'];
                    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                        $action_result = ['success' => false, 'message' => 'Ошибка при загрузке файла'];
                    } else {
                        $safe_name = $uid . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                        $target_path = $upload_dir . $safe_name;
                        
                        if (move_uploaded_file($file['tmp_name'], $target_path)) {
                            $stmt = $pdo->prepare("
                                INSERT INTO court_documents (case_uid, title, file_name, file_path, file_size, file_mime, author_id)
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $uid,
                                $document_title,
                                $original_name,
                                'uploads/court_documents/' . $safe_name,
                                $file['size'],
                                $file['type'],
                                $user['id']
                            ]);
                            
                            auditLog($user['id'], 'upload_court_document_file', $uid, null, "title: $document_title");
                            
                            addCaseMovement($uid, 'Подготовка дела', 'Прикрепление судебного документа (файл)', 
                                "$document_title ($original_name)", $user['in_game_name'] . ' (судья)');
                            
                            if ($case['plaintiff_id']) {
                                createNotification($case['plaintiff_id'], 'evidence_added', 'Новый документ по делу', 
                                    "Судья прикрепил документ: $document_title", 
                                    "/case.php?uid=" . urlencode($uid));
                            }
                            if ($case['defendant_id']) {
                                createNotification($case['defendant_id'], 'evidence_added', 'Новый документ по делу', 
                                    "Судья прикрепил документ: $document_title", 
                                    "/case.php?uid=" . urlencode($uid));
                            }
                            
                            $action_result = ['success' => true, 'message' => 'Файл прикреплён'];
                            $should_redirect = true;
                        } else {
                            $action_result = ['success' => false, 'message' => 'Не удалось сохранить файл'];
                        }
                    }
                }
                break;
                
            case 'review_appeal':
                $appeal_id = $_POST['appeal_id'] ?? '';
                $decision = $_POST['decision'] ?? '';
                $decision_text = trim($_POST['decision_text'] ?? '');
                
                if (!$appeal_id || !in_array($decision, ['satisfied', 'rejected'])) {
                    $action_result = ['success' => false, 'message' => 'Неверные параметры'];
                } elseif (empty($decision_text)) {
                    $action_result = ['success' => false, 'message' => 'Введите текст решения по апелляции'];
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE appeals 
                        SET status = ?, decision_text = ?, reviewed_by = ?, reviewed_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$decision, $decision_text, $user['id'], $appeal_id]);
                    
                    if ($decision === 'satisfied') {
                        $stmt = $pdo->prepare("UPDATE court_cases SET appeal_status = 'approved' WHERE uid = ?");
                    } else {
                        $stmt = $pdo->prepare("UPDATE court_cases SET appeal_status = 'rejected' WHERE uid = ?");
                    }
                    $stmt->execute([$case['original_case_uid'] ?? $uid]);
                    
                    addCaseMovement($uid, 'Апелляционное производство', 'Решение по апелляции', 
                        $decision === 'satisfied' ? 'Апелляция удовлетворена' : 'Апелляция отклонена', 
                        $user['in_game_name']);
                    
                    $stmt = $pdo->prepare("SELECT appellant_id FROM appeals WHERE id = ?");
                    $stmt->execute([$appeal_id]);
                    $appeal_data = $stmt->fetch();
                    
                    if ($appeal_data) {
                        createNotification($appeal_data['appellant_id'], 'appeal_result', 'Решение по апелляции', 
                            "По вашей апелляции по делу {$case['case_number_full']} вынесено решение: " . 
                            ($decision === 'satisfied' ? 'Удовлетворена' : 'Отклонена'), 
                            "/case.php?uid=" . urlencode($uid));
                    }
                    
                    $action_result = ['success' => true, 'message' => 'Решение по апелляции вынесено'];
                    $should_redirect = true;
                }
                break;
                
            case 'add_evidence_verification':
                $evidence_id = $_POST['evidence_id'] ?? '';
                if ($evidence_id) {
                    $stmt = $pdo->prepare("
                        UPDATE evidence 
                        SET verified_by = ?, verified_at = NOW()
                        WHERE id = ? AND case_uid = ?
                    ");
                    $stmt->execute([$user['id'], $evidence_id, $uid]);
                    
                    auditLog($user['id'], 'verify_evidence', $uid, null, "evidence_id: $evidence_id");
                    
                    addCaseMovement($uid, 'Подготовка дела', 'Проверка доказательства', 
                        "Доказательство проверено судьёй", $user['in_game_name'] . ' (судья)');
                    
                    $action_result = ['success' => true, 'message' => 'Доказательство проверено'];
                    $should_redirect = true;
                }
                break;
        }
    }
}

if ($should_redirect && $action_result && $action_result['success']) {
    redirect('/case.php?uid=' . urlencode($uid));
}

// Обновляем статусы заседаний
foreach ($sessions as &$session) {
    if ($session['status'] === 'scheduled' && strtotime($session['scheduled_at']) < time()) {
        $stmt = $pdo->prepare("UPDATE court_sessions SET status = 'completed' WHERE id = ?");
        $stmt->execute([$session['id']]);
        $session['status'] = 'completed';
        
        addCaseMovement($uid, 'Судебное разбирательство', 'Заседание проведено', 
            "Заседание №{$session['session_number']} от " . date('d.m.Y H:i', strtotime($session['scheduled_at'])), 'Система');
    }
}

// Списки для назначений
$judges = [];
if ($can_manage && empty($case['judge_id'])) {
    $stmt = $pdo->prepare("SELECT id, in_game_name FROM users WHERE role IN ('judge', 'chairman') AND is_active = 1 ORDER BY in_game_name");
    $stmt->execute();
    $judges = $stmt->fetchAll();
}

$prosecutors = [];
if ($can_prosecute && empty($case['prosecutor_id']) && $case['case_type_code'] === '1') {
    $stmt = $pdo->prepare("SELECT id, in_game_name FROM users WHERE role = 'prosecutor' AND is_active = 1 ORDER BY in_game_name");
    $stmt->execute();
    $prosecutors = $stmt->fetchAll();
}

$page_title = 'Дело ' . $case['case_number_full'];
include __DIR__ . '/includes/header.php';
?>

<div class="case-page">
    <!-- Заголовок дела -->
    <div class="case-header">
        <div class="case-number-section">
            <span class="case-uid">УИД: <?php echo h($case['uid']); ?></span>
            <h1>Дело № <?php echo h($case['case_number_full']); ?></h1>
            <div class="case-meta">
                <span class="case-type"><?php echo h($case['case_type_name']); ?></span>
                <span class="case-status status-<?php echo $case['status']; ?>">
                    <?php echo getStatusName($case['status'], $case['case_type_code']); ?>
                </span>
            </div>
        </div>
        
        <div class="case-actions">
            <?php if ($can_manage && $case['status'] !== 'archived'): ?>
                <button class="btn btn-primary" onclick="togglePanel('manage-panel')">
                    <i class="fas fa-cog"></i> Управление делом
                </button>
            <?php endif; ?>
            <button class="btn btn-secondary" onclick="openMovementModal()">
                <i class="fas fa-history"></i> Движение дела
            </button>
        </div>
    </div>
    
    <!-- Панель управления -->
    <?php if ($can_manage && $case['status'] !== 'archived'): ?>
    <div id="manage-panel" class="manage-panel" style="display: none;">
        <div class="panel-header">
            <h3><i class="fas fa-sliders-h"></i> Управление делом</h3>
            <button class="panel-close" onclick="togglePanel('manage-panel')">&times;</button>
        </div>
        <div class="panel-body">
            <?php if ($action_result && !$should_redirect): ?>
                <div class="alert alert-<?php echo $action_result['success'] ? 'success' : 'error'; ?>">
                    <?php echo h($action_result['message']); ?>
                </div>
            <?php endif; ?>
            
            <div class="manage-grid">
                <!-- Изменение статуса -->
                <div class="manage-card">
                    <h4><i class="fas fa-tasks"></i> Изменить статус</h4>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="change_status">
                        <select name="status" class="form-control">
                            <option value="draft" <?php echo $case['status'] == 'draft' ? 'selected' : ''; ?>>Черновик</option>
                            <option value="accepted" <?php echo $case['status'] == 'accepted' ? 'selected' : ''; ?>>Принято к производству</option>
                            <option value="preparing" <?php echo $case['status'] == 'preparing' ? 'selected' : ''; ?>>Подготовка к заседанию</option>
                            <option value="trial" <?php echo $case['status'] == 'trial' ? 'selected' : ''; ?>>Судебное разбирательство</option>
                            <option value="verdict" <?php echo $case['status'] == 'verdict' ? 'selected' : ''; ?>><?php echo getFinalStatusName($case['case_type_code']); ?></option>
                            <option value="appealed" <?php echo $case['status'] == 'appealed' ? 'selected' : ''; ?>>Обжаловано</option>
                            <option value="archived" <?php echo $case['status'] == 'archived' ? 'selected' : ''; ?>>Архив</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary">Применить</button>
                    </form>
                </div>
                
                <?php if (empty($case['judge_id']) && !empty($judges)): ?>
                <div class="manage-card">
                    <h4><i class="fas fa-gavel"></i> Назначить судью</h4>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="assign_judge">
                        <select name="judge_id" class="form-control" required>
                            <option value="">-- Выберите судью --</option>
                            <?php foreach ($judges as $judge): ?>
                                <option value="<?php echo $judge['id']; ?>"><?php echo h($judge['in_game_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary">Назначить</button>
                    </form>
                </div>
                <?php endif; ?>
                
                <?php if ($case['case_type_code'] === '1' && empty($case['prosecutor_id']) && !empty($prosecutors)): ?>
                <div class="manage-card">
                    <h4><i class="fas fa-balance-scale"></i> Назначить прокурора</h4>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="assign_prosecutor">
                        <select name="prosecutor_id" class="form-control" required>
                            <option value="">-- Выберите прокурора --</option>
                            <?php foreach ($prosecutors as $prosecutor): ?>
                                <option value="<?php echo $prosecutor['id']; ?>"><?php echo h($prosecutor['in_game_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary">Назначить</button>
                    </form>
                </div>
                <?php endif; ?>
                
                <!-- Назначение заседания -->
                <?php if ($case['judge_id'] && $case['status'] !== 'verdict' && $case['status'] !== 'archived'): ?>
                <div class="manage-card">
                    <h4><i class="fas fa-calendar-plus"></i> Назначить заседание</h4>
                    <form method="POST" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="add_session">
                        <input type="datetime-local" name="scheduled_at" class="form-control" required>
                        <select name="duration" class="form-control">
                            <option value="30">30 минут</option>
                            <option value="60" selected>1 час</option>
                            <option value="90">1.5 часа</option>
                            <option value="120">2 часа</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary">Назначить</button>
                    </form>
                </div>
                <?php endif; ?>
                
                <!-- Текстовый документ -->
                <?php if ($case['judge_id'] == $user['id'] && $case['status'] !== 'archived'): ?>
                <div class="manage-card full-width">
                    <h4><i class="fas fa-file-alt"></i> Прикрепить текстовый документ</h4>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="upload_court_document">
                        <div class="form-group">
                            <label>Название документа</label>
                            <input type="text" name="document_title" class="form-control" placeholder="Например: Постановление о назначении заседания" required>
                        </div>
                        <div class="form-group">
                            <label>Содержание документа</label>
                            <textarea name="document_content" class="form-control" rows="5" placeholder="Полный текст..."></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Прикрепить текстовый документ</button>
                    </form>
                </div>
                
                <!-- Файловый документ -->
                <div class="manage-card full-width">
                    <h4><i class="fas fa-file-upload"></i> Загрузить файл документа</h4>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="upload_court_document_file">
                        <div class="form-group">
                            <label>Название документа</label>
                            <input type="text" name="document_title" class="form-control" placeholder="Например: Постановление, Определение, Решение" required>
                        </div>
                        <div class="form-group">
                            <label>Файл документа</label>
                            <input type="file" name="document_file" class="form-control-file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt,.rtf" required>
                            <small>Поддерживаются: PDF, DOC, DOCX, JPG, PNG, TXT, RTF. Максимум 10 МБ</small>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Загрузить файл</button>
                    </form>
                </div>
                <?php endif; ?>
                
                <!-- Вынесение приговора -->
                <?php if ($case['judge_id'] == $user['id'] && $case['status'] !== 'verdict' && $case['status'] !== 'archived'): ?>
                <div class="manage-card full-width">
                    <h4><i class="fas fa-scroll"></i> <?php echo getVerdictActionName($case['case_type_code']); ?></h4>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="submit_verdict">
                        <div class="form-group">
                            <label>Текст <?php echo mb_strtolower(getVerdictDocumentName($case['case_type_code'])); ?></label>
                            <textarea name="verdict_text" class="form-control" rows="5" placeholder="ОПИСАТЕЛЬНАЯ И РЕЗОЛЮТИВНАЯ ЧАСТИ..." required></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Штраф (игровая валюта)</label>
                                <input type="number" name="punishment_fine" class="form-control" value="0" min="0">
                            </div>
                            <div class="form-group">
                                <label>Арест (часов)</label>
                                <input type="number" name="punishment_arrest" class="form-control" value="0" min="0">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-danger"><?php echo getPublishButtonName($case['case_type_code']); ?></button>
                    </form>
                </div>
                <?php endif; ?>
                
                <!-- Рассмотрение апелляции -->
                <?php
                $pending_appeals = [];
                if ($case['original_case_uid']) {
                    $stmt = $pdo->prepare("
                        SELECT a.*, u.in_game_name as appellant_name,
                               c.case_number_full as original_case_number
                        FROM appeals a
                        JOIN court_cases c ON a.case_uid = c.uid
                        JOIN users u ON a.appellant_id = u.id
                        WHERE a.appeal_case_uid = ? AND a.status IN ('pending', 'under_review')
                    ");
                    $stmt->execute([$uid]);
                    $pending_appeals = $stmt->fetchAll();
                }
                ?>
                
                <?php if (!empty($pending_appeals)): ?>
                    <?php foreach ($pending_appeals as $appeal): ?>
                    <div class="manage-card full-width">
                        <h4><i class="fas fa-gavel"></i> Рассмотрение апелляционной жалобы</h4>
                        <div class="appeal-preview">
                            <p><strong>Заявитель:</strong> <?php echo h($appeal['appellant_name']); ?></p>
                            <p><strong>Причина обжалования:</strong></p>
                            <div class="appeal-reason-preview"><?php echo nl2br(h($appeal['appeal_reason'])); ?></div>
                            <p><strong>Требования:</strong></p>
                            <div class="appeal-requirements-preview"><?php echo nl2br(h($appeal['appeal_requirements'] ?: 'Не указаны')); ?></div>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="review_appeal">
                            <input type="hidden" name="appeal_id" value="<?php echo $appeal['id']; ?>">
                            <div class="form-group">
                                <label>Решение по апелляции</label>
                                <select name="decision" class="form-control" required>
                                    <option value="">-- Выберите решение --</option>
                                    <option value="satisfied">Удовлетворить апелляцию</option>
                                    <option value="rejected">Отклонить апелляцию</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Текст решения</label>
                                <textarea name="decision_text" class="form-control" rows="4" 
                                          placeholder="Обоснование принятого решения..." required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Вынести решение</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php elseif ($action_result && !$should_redirect): ?>
        <div class="alert alert-<?php echo $action_result['success'] ? 'success' : 'error'; ?>">
            <?php echo h($action_result['message']); ?>
        </div>
    <?php endif; ?>
    
    <!-- Основная информация -->
    <div class="case-grid">
        <div class="case-info-card">
            <div class="card-header-with-button">
                <h3><i class="fas fa-info-circle"></i> Информация о деле</h3>
                <?php if ($can_edit && $case['status'] !== 'archived'): ?>
                    <button class="btn-icon" onclick="openEditModal()" title="Редактировать дело">
                        <i class="fas fa-pen"></i>
                    </button>
                <?php endif; ?>
            </div>
            <div class="info-table">
                <div class="info-row">
                    <span class="info-label">Номер дела:</span>
                    <span class="info-value"><?php echo h($case['case_number_full']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">УИД:</span>
                    <span class="info-value"><code><?php echo h($case['uid']); ?></code></span>
                </div>
                <?php if ($case['original_case_number']): ?>
                <div class="info-row">
                    <span class="info-label">Оригинальное дело:</span>
                    <span class="info-value"><a href="/case.php?uid=<?php echo urlencode($case['original_case_uid']); ?>"><?php echo h($case['original_case_number']); ?></a></span>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <span class="info-label">Тип дела:</span>
                    <span class="info-value"><?php echo h($case['case_type_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Дата создания:</span>
                    <span class="info-value"><?php echo date('d.m.Y H:i', strtotime($case['created_at'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label"><?php echo getParticipantLabel($case['case_type_code'], 'plaintiff'); ?>:</span>
                    <span class="info-value">
                        <?php if ($case['plaintiff_id']): ?>
                            <a href="/profile.php?id=<?php echo $case['plaintiff_id']; ?>"><?php echo h($case['plaintiff_name']); ?></a>
                        <?php else: ?>
                            <?php echo h($case['plaintiff_name'] ?? 'Не указан'); ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label"><?php echo getParticipantLabel($case['case_type_code'], 'defendant'); ?>:</span>
                    <span class="info-value">
                        <?php if ($case['defendant_id']): ?>
                            <a href="/profile.php?id=<?php echo $case['defendant_id']; ?>"><?php echo h($case['defendant_name']); ?></a>
                        <?php elseif (strpos($case['description'], '[Ответчик по указанию истца:') !== false): ?>
                            <?php preg_match('/\[Ответчик по указанию истца: (.*?)\]/', $case['description'], $matches); ?>
                            <?php echo h($matches[1] ?? 'Не указан'); ?> <span class="unregistered-badge">(не зарегистрирован)</span>
                        <?php else: ?>
                            <?php echo h($case['defendant_name'] ?? 'Не указан'); ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ($case['prosecutor_name']): ?>
                <div class="info-row">
                    <span class="info-label">Прокурор:</span>
                    <span class="info-value">
                        <?php if ($case['prosecutor_id']): ?>
                            <a href="/profile.php?id=<?php echo $case['prosecutor_id']; ?>"><?php echo h($case['prosecutor_name']); ?></a>
                        <?php else: ?>
                            <?php echo h($case['prosecutor_name']); ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <span class="info-label">Судья:</span>
                    <span class="info-value">
                        <?php if ($case['judge_id']): ?>
                            <a href="/profile.php?id=<?php echo $case['judge_id']; ?>"><?php echo h($case['judge_name']); ?></a>
                        <?php else: ?>
                            <?php echo h($case['judge_name'] ?? 'Не назначен'); ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ($case['article_code']): ?>
                <div class="info-row">
                    <span class="info-label">Статья:</span>
                    <span class="info-value"><?php echo h($case['article_code']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (isset($case['vk']) && !empty($case['vk'])): ?>
                <div class="info-row">
                    <span class="info-label">ВКонтакте:</span>
                    <span class="info-value">
                        <a href="<?php echo h($case['vk']); ?>" target="_blank" rel="noopener noreferrer">
                            <i class="fab fa-vk"></i> <?php echo h($case['vk']); ?>
                        </a>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="case-description-card">
            <h3><i class="fas fa-align-left"></i> Описание обстоятельств</h3>
            <div class="description-content"><?php echo nl2br(h($case['description'])); ?></div>
            <?php if ($case['article_text']): ?>
            <div class="article-text">
                <strong>Текст статьи:</strong>
                <div class="article-content"><?php echo nl2br(h($case['article_text'])); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Прокурорские действия -->
    <?php if ($user['role'] === 'prosecutor' && $case['prosecutor_id'] == $user['id'] && $case['status'] !== 'verdict' && $case['status'] !== 'archived'): ?>
    <div class="prosecutor-actions-card">
        <h3><i class="fas fa-balance-scale"></i> Действия прокурора</h3>
        <div class="prosecutor-actions">
            <?php if (!$case['indictment_approved']): ?>
                <button class="btn btn-primary" onclick="openIndictmentModal()">
                    <i class="fas fa-file-alt"></i> Утвердить обвинительное заключение
                </button>
            <?php else: ?>
                <span class="info-message success">
                    <i class="fas fa-check-circle"></i> Обвинительное заключение утверждено
                </span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Приговор -->
    <?php if ($case['verdict_text']): ?>
    <div class="verdict-card">
        <h3><i class="fas fa-scroll"></i> <?php echo getVerdictDocumentName($case['case_type_code']); ?> суда</h3>
        <div class="verdict-content"><?php echo nl2br(h($case['verdict_text'])); ?></div>
        <?php if ($case['punishment_fine'] > 0 || $case['punishment_arrest_hours'] > 0): ?>
        <div class="punishment-info">
            <strong>Наказание:</strong>
            <?php if ($case['punishment_fine'] > 0): ?>
                <span class="punishment-fine">Штраф: <?php echo number_format($case['punishment_fine'], 0, '', ' '); ?>$</span>
            <?php endif; ?>
            <?php if ($case['punishment_arrest_hours'] > 0): ?>
                <span class="punishment-arrest">Арест: <?php echo $case['punishment_arrest_hours']; ?> часов</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Апелляция -->
    <?php if ($case['status'] === 'verdict' || ($case['status'] === 'appealed' && $case['original_case_uid'])): ?>
    <div class="appeal-card">
        <h3><i class="fas fa-gavel"></i> Апелляция</h3>
        
        <?php if ($appeal_info): ?>
            <div class="appeal-info">
                <div class="appeal-status status-<?php echo $appeal_info['status']; ?>">
                    <?php
                    $status_labels = [
                        'pending' => 'На рассмотрении',
                        'under_review' => 'Рассматривается',
                        'satisfied' => 'Удовлетворена',
                        'rejected' => 'Отклонена'
                    ];
                    echo $status_labels[$appeal_info['status']] ?? 'Подана';
                    ?>
                </div>
                <div class="appeal-details">
                    <div class="appeal-row">
                        <span class="appeal-label">Апелляционное дело:</span>
                        <a href="/case.php?uid=<?php echo urlencode($appeal_info['appeal_case_uid']); ?>">
                            № <?php echo h($appeal_info['appeal_case_number']); ?>
                        </a>
                    </div>
                    <div class="appeal-row">
                        <span class="appeal-label">Заявитель:</span>
                        <span><?php echo h($appeal_info['appellant_name']); ?></span>
                    </div>
                    <div class="appeal-row">
                        <span class="appeal-label">Причина обжалования:</span>
                        <div class="appeal-reason"><?php echo nl2br(h($appeal_info['appeal_reason'])); ?></div>
                    </div>
                    <?php if ($appeal_info['decision_text']): ?>
                    <div class="appeal-row">
                        <span class="appeal-label">Решение по апелляции:</span>
                        <div class="appeal-decision"><?php echo nl2br(h($appeal_info['decision_text'])); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($appeal_info['reviewed_by']): ?>
                    <div class="appeal-row">
                        <span class="appeal-label">Рассмотрел:</span>
                        <span><?php echo h($appeal_info['reviewer_name']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($can_appeal && $is_participant): ?>
            <div class="appeal-button-container">
                <p>Вы можете обжаловать данное решение в течение 30 дней с момента вынесения приговора.</p>
                <button class="btn btn-warning" onclick="openAppealModal()">
                    <i class="fas fa-gavel"></i> Подать апелляцию
                </button>
            </div>
            
            <!-- Модальное окно подачи апелляции -->
            <div id="appealModal" class="modal" style="display: none;">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Подача апелляционной жалобы</h3>
                        <button class="modal-close" onclick="closeAppealModal()">&times;</button>
                    </div>
                    <form method="POST" action="/appeal.php">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="case_uid" value="<?php echo h($case['uid']); ?>">
                        
                        <div style="padding: 1rem 1.5rem;">
                            <div class="form-group">
                                <label>Причина обжалования <span class="required">*</span></label>
                                <textarea name="appeal_reason" class="form-control" rows="5" 
                                          placeholder="Укажите, с чем вы не согласны и почему..." required></textarea>
                                <small>Опишите, какие нормы права нарушены, какие обстоятельства не учтены</small>
                            </div>
                            
                            <div class="form-group">
                                <label>Требования апеллянта</label>
                                <textarea name="appeal_requirements" class="form-control" rows="3" 
                                          placeholder="Чего вы добиваетесь? Отмена приговора, изменение наказания, новое рассмотрение..."></textarea>
                            </div>
                        </div>
                        
                        <div class="form-actions" style="padding: 1rem 1.5rem; border-top: 1px solid var(--gray-200); margin: 0;">
                            <button type="submit" class="btn btn-primary">Подать апелляцию</button>
                            <button type="button" class="btn btn-secondary" onclick="closeAppealModal()">Отмена</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php elseif ($case['status'] === 'verdict'): ?>
            <div class="appeal-info">
                <p class="appeal-expired">Срок обжалования истёк или вы не имеете права на апелляцию.</p>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Заседания -->
    <div class="sessions-card">
        <h3><i class="fas fa-calendar-alt"></i> Заседания по делу</h3>
        <?php if (empty($sessions)): ?>
            <div class="empty-state"><i class="fas fa-calendar-times"></i><p>Заседания ещё не назначены</p></div>
        <?php else: ?>
            <div class="sessions-list">
                <?php foreach ($sessions as $session): ?>
                <div class="session-item" data-session-id="<?php echo $session['id']; ?>">
                    <div class="session-number">Заседание №<?php echo $session['session_number']; ?></div>
                    <div class="session-datetime">
                        <i class="fas fa-clock"></i> 
                        <?php echo date('d.m.Y H:i', strtotime($session['scheduled_at'])); ?>
                    </div>
                    <div class="session-duration">
                        <i class="fas fa-hourglass-half"></i> 
                        <?php echo $session['duration_minutes']; ?> минут
                    </div>
                    <div class="session-judge">
                        <i class="fas fa-gavel"></i> 
                        Судья: <?php if ($session['judge_id']): ?><a href="/profile.php?id=<?php echo $session['judge_id']; ?>"><?php echo h($session['judge_name']); ?></a><?php else: ?><?php echo h($session['judge_name']); ?><?php endif; ?>
                    </div>
                    <div class="session-status status-<?php echo $session['status']; ?>">
                        <?php 
                        $status_labels = [
                            'scheduled' => 'Запланировано',
                            'in_progress' => 'В процессе',
                            'postponed' => 'Перенесено',
                            'completed' => 'Завершено',
                            'cancelled' => 'Отменено'
                        ];
                        echo $status_labels[$session['status']] ?? $session['status'];
                        ?>
                    </div>
                    <?php if ($session['status_comment']): ?>
                        <div class="session-comment">
                            <i class="fas fa-comment"></i> <?php echo h($session['status_comment']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($can_manage && $case['status'] !== 'archived'): ?>
                    <div class="session-actions">
                        <button class="btn-icon" onclick="openSessionModal(<?php echo $session['id']; ?>, 'status')" title="Изменить статус">
                            <i class="fas fa-tasks"></i>
                        </button>
                        <button class="btn-icon" onclick="openSessionModal(<?php echo $session['id']; ?>, 'reschedule')" title="Перенести">
                            <i class="fas fa-calendar-alt"></i>
                        </button>
                        <button class="btn-icon delete" onclick="openSessionModal(<?php echo $session['id']; ?>, 'delete')" title="Удалить">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Доказательства -->
    <div class="evidence-card">
        <h3><i class="fas fa-image"></i> Доказательства</h3>
        
        <!-- Форма загрузки новых доказательств -->
        <?php if ($can_upload_evidence && $case['status'] !== 'archived'): ?>
        <div class="upload-evidence-section">
            <h4><i class="fas fa-upload"></i> Добавить новое доказательство</h4>
            <form method="POST" enctype="multipart/form-data" class="upload-evidence-form" action="/case.php?uid=<?php echo urlencode($uid); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="upload_evidence">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="evidence_title">Название доказательства</label>
                        <input type="text" id="evidence_title" name="evidence_title" class="form-control" 
                               placeholder="Например: Скриншот переписки, Документ, Видео">
                    </div>
                    <div class="form-group">
                        <label for="evidence_file">Файл</label>
                        <input type="file" id="evidence_file" name="evidence_file" class="form-control-file" 
                               accept=".jpg,.jpeg,.png,.gif,.mp4,.webm,.txt,.log,.pdf,.doc,.docx,.rtf" required>
                        <small>Поддерживаются: JPG, PNG, GIF, MP4, TXT, LOG, PDF, DOC, DOCX, RTF. Максимум 10 МБ</small>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-upload"></i> Загрузить доказательство
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
        
        <?php if (empty($evidence_list)): ?>
            <div class="empty-state"><i class="fas fa-folder-open"></i><p>Доказательства ещё не приложены</p></div>
        <?php else: ?>
            <div class="evidence-grid">
                <?php foreach ($evidence_list as $evidence): ?>
                <div class="evidence-item">
                    <div class="evidence-icon"><i class="fas fa-file-image"></i></div>
                    <div class="evidence-info">
                        <div class="evidence-title"><?php echo h($evidence['title']); ?></div>
                        <div class="evidence-meta">Загружено: <?php echo h($evidence['uploaded_by_name']); ?> (<?php echo date('d.m.Y', strtotime($evidence['created_at'])); ?>)</div>
                        <?php if ($evidence['verified_by']): ?>
                            <div class="evidence-verified"><i class="fas fa-check-circle"></i> Проверено судьёй <?php echo h($evidence['verified_by_name']); ?></div>
                        <?php else: ?>
                            <div class="evidence-not-verified"><i class="fas fa-clock"></i> Не проверено
                                <?php if ($can_manage && $case['status'] !== 'verdict'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="add_evidence_verification">
                                        <input type="hidden" name="evidence_id" value="<?php echo $evidence['id']; ?>">
                                        <button type="submit" class="btn-link">Подтвердить</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($evidence['file_path']): ?>
                        <a href="/view-file.php?file=<?php echo urlencode($evidence['file_path']); ?>" class="evidence-link" target="_blank"><i class="fas fa-download"></i> Скачать</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Текстовые документы -->
    <div class="documents-card">
        <h3><i class="fas fa-file-alt"></i> Текстовые документы</h3>
        <?php if (empty($documents)): ?>
            <div class="empty-state"><i class="fas fa-file"></i><p>Документов пока нет</p></div>
        <?php else: ?>
            <div class="documents-list">
                <?php foreach ($documents as $doc): ?>
                <div class="document-item <?php echo $doc['document_type'] == 'court_document' ? 'court-doc' : ''; ?>">
                    <div class="doc-icon">
                        <?php if ($doc['document_type'] == 'court_document'): ?>
                            <i class="fas fa-gavel"></i>
                        <?php elseif ($doc['document_type'] == 'claim'): ?>
                            <i class="fas fa-file-signature"></i>
                        <?php else: ?>
                            <i class="fas fa-file-alt"></i>
                        <?php endif; ?>
                    </div>
                    <div class="doc-info">
                        <div class="doc-title">
                            <?php echo h($doc['title']); ?>
                            <?php if ($doc['document_type'] == 'court_document'): ?>
                                <span class="court-badge">Судебный документ</span>
                            <?php elseif ($doc['document_type'] == 'claim'): ?>
                                <span class="claim-badge">Исковое заявление</span>
                            <?php endif; ?>
                        </div>
                        <div class="doc-meta">Автор: <?php echo h($doc['author_name']); ?> | <?php echo date('d.m.Y H:i', strtotime($doc['created_at'])); ?></div>
                        <details class="doc-content-details">
                            <summary>Просмотр содержимого</summary>
                            <pre><?php echo h($doc['content']); ?></pre>
                        </details>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Судейские файлы -->
    <div class="documents-card">
        <h3><i class="fas fa-file-pdf"></i> Судейские документы (файлы)</h3>
        <?php if (empty($court_files)): ?>
            <div class="empty-state"><i class="fas fa-file"></i><p>Судейские документы ещё не загружены</p></div>
        <?php else: ?>
            <div class="documents-list">
                <?php foreach ($court_files as $file): ?>
                    <div class="document-item court-file">
                        <div class="doc-icon">
                            <?php
                            $ext = pathinfo($file['file_name'], PATHINFO_EXTENSION);
                            if ($ext == 'pdf') echo '<i class="fas fa-file-pdf"></i>';
                            elseif (in_array($ext, ['doc', 'docx'])) echo '<i class="fas fa-file-word"></i>';
                            elseif (in_array($ext, ['jpg', 'jpeg', 'png'])) echo '<i class="fas fa-file-image"></i>';
                            else echo '<i class="fas fa-file-alt"></i>';
                            ?>
                        </div>
                        <div class="doc-info">
                            <div class="doc-title">
                                <?php echo h($file['title']); ?>
                                <span class="court-badge">Судебный документ</span>
                                <span class="file-badge"><?php echo strtoupper($ext); ?></span>
                            </div>
                            <div class="doc-meta">
                                Автор: <?php echo h($file['author_name']); ?>
                                | <?php echo date('d.m.Y H:i', strtotime($file['created_at'])); ?>
                                | Размер: <?php echo round($file['file_size'] / 1024, 1); ?> KB
                            </div>
                            <a href="/view-court-file.php?id=<?php echo $file['id']; ?>" class="evidence-link" target="_blank">
                                <i class="fas fa-download"></i> Скачать <?php echo h($file['file_name']); ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Реестр обременений -->
    <?php if ($registry): ?>
    <div class="registry-card">
        <h3><i class="fas fa-list"></i> Реестр обременений</h3>
        <div class="registry-info">
            <div class="registry-obligation">Обязательство: <?php echo h($registry['obligation_text']); ?></div>
            <?php if ($registry['amount'] > 0): ?>
                <div class="registry-amount">Сумма: <?php echo number_format($registry['amount'], 0, '', ' '); ?>$</div>
            <?php endif; ?>
            <div class="registry-date">Срок исполнения: <?php echo date('d.m.Y', strtotime($registry['due_date'])); ?></div>
            <div class="registry-status">Статус: <span class="status-<?php echo $registry['declared_status']; ?>"><?php echo $registry['declared_status'] == 'pending' ? 'Ожидает исполнения' : ($registry['declared_status'] == 'fulfilled' ? 'Исполнено' : 'Просрочено'); ?></span></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Модальное окно движения дела -->
<div id="movementModal" class="modal" style="display: none;">
    <div class="modal-content wide">
        <div class="modal-header">
            <h3><i class="fas fa-history"></i> Журнал движения дела № <?php echo h($case['case_number_full']); ?></h3>
            <button class="modal-close" onclick="closeMovementModal()">&times;</button>
        </div>
        <div class="modal-body">
            <?php if (empty($movements)): ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <p>Журнал движения дела пуст</p>
                </div>
            <?php else: ?>
                <div class="movement-table-wrapper">
                    <table class="movement-table">
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Стадия</th>
                                <th>Процессуальное действие</th>
                                <th>Результат</th>
                                <th>Ответственное лицо</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($movements as $movement): ?>
                            <tr>
                                <td class="movement-date"><?php echo date('d.m.Y', strtotime($movement['movement_date'])); ?></td>
                                <td class="movement-stage"><?php echo h($movement['stage']); ?></td>
                                <td class="movement-action"><?php echo nl2br(h($movement['action_text'])); ?></td>
                                <td class="movement-result"><?php echo h($movement['result_text']); ?></td>
                                <td class="movement-person"><?php echo h($movement['responsible_person']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeMovementModal()">Закрыть</button>
        </div>
    </div>
</div>

<!-- Модальное окно обвинительного заключения -->
<div id="indictmentModal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Обвинительное заключение</h3>
            <button class="modal-close" onclick="closeIndictmentModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="update_indictment">
            
            <div class="modal-body">
                <div class="form-group">
                    <label>Текст обвинительного заключения</label>
                    <textarea name="indictment_text" class="form-control" rows="12" 
                              placeholder="Изложение обвинения, квалификация деяния, доказательства..."><?php echo h($case['indictment_text'] ?? ''); ?></textarea>
                    <small>После утверждения обвинения дело будет передано в суд</small>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Утвердить обвинение</button>
                <button type="button" class="btn btn-secondary" onclick="closeIndictmentModal()">Отмена</button>
            </div>
        </form>
    </div>
</div>

<!-- Модальное окно редактирования дела -->
<div id="editCaseModal" class="modal" style="display: none;">
    <div class="modal-content wide">
        <div class="modal-header">
            <h3>Редактирование дела № <?php echo h($case['case_number_full']); ?></h3>
            <button class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="POST" id="editCaseForm">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="edit_case">
            
            <div class="modal-body">
                <div class="form-section">
                    <h4><i class="fas fa-info-circle"></i> Основная информация</h4>
                    
                    <div class="form-group">
                        <label for="edit_title">Название дела <span class="required">*</span></label>
                        <input type="text" id="edit_title" name="title" class="form-control" 
                               value="<?php echo h($case['title']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_description">Описание обстоятельств</label>
                        <textarea id="edit_description" name="description" class="form-control" rows="8"><?php echo h($case['description']); ?></textarea>
                    </div>
                </div>
                
                <div class="form-section">
                    <h4><i class="fas fa-balance-scale"></i> Правовая информация</h4>
                    
                    <div class="form-group">
                        <label for="edit_article_code">Статья</label>
                        <input type="text" id="edit_article_code" name="article_code" class="form-control" 
                               value="<?php echo h($case['article_code'] ?? ''); ?>" placeholder="Например: 158 УК РФ">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_article_text">Текст статьи</label>
                        <textarea id="edit_article_text" name="article_text" class="form-control" rows="5" 
                                  placeholder="Полный текст статьи..."><?php echo h($case['article_text'] ?? ''); ?></textarea>
                    </div>
                </div>
                
                <?php if ($user['role'] === 'chairman'): ?>
                <div class="form-section">
                    <h4><i class="fas fa-users"></i> Участники дела (только для администратора)</h4>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_plaintiff_id"><?php echo getParticipantLabel($case['case_type_code'], 'plaintiff'); ?></label>
                            <select id="edit_plaintiff_id" name="plaintiff_id" class="form-control">
                                <option value="">-- Не указан --</option>
                                <?php foreach ($all_users as $u): ?>
                                    <option value="<?php echo $u['id']; ?>" <?php echo $case['plaintiff_id'] == $u['id'] ? 'selected' : ''; ?>>
                                        <?php echo h($u['in_game_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_defendant_id"><?php echo getParticipantLabel($case['case_type_code'], 'defendant'); ?></label>
                            <select id="edit_defendant_id" name="defendant_id" class="form-control">
                                <option value="">-- Не указан --</option>
                                <?php foreach ($all_users as $u): ?>
                                    <option value="<?php echo $u['id']; ?>" <?php echo $case['defendant_id'] == $u['id'] ? 'selected' : ''; ?>>
                                        <?php echo h($u['in_game_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_judge_id">Судья</label>
                            <select id="edit_judge_id" name="judge_id" class="form-control">
                                <option value="">-- Не назначен --</option>
                                <?php foreach ($all_users as $u): ?>
                                    <option value="<?php echo $u['id']; ?>" <?php echo $case['judge_id'] == $u['id'] ? 'selected' : ''; ?>>
                                        <?php echo h($u['in_game_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_prosecutor_id">Прокурор</label>
                            <select id="edit_prosecutor_id" name="prosecutor_id" class="form-control">
                                <option value="">-- Не назначен --</option>
                                <?php foreach ($all_users as $u): ?>
                                    <option value="<?php echo $u['id']; ?>" <?php echo $case['prosecutor_id'] == $u['id'] ? 'selected' : ''; ?>>
                                        <?php echo h($u['in_game_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_vk">ВКонтакте (ссылка)</label>
                        <input type="url" id="edit_vk" name="vk" class="form-control" 
                               value="<?php echo h($case['vk'] ?? ''); ?>" placeholder="https://vk.com/id...">
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Сохранить изменения</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Отмена</button>
            </div>
        </form>
    </div>
</div>

<!-- Модальное окно управления заседанием -->
<div id="sessionModal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="sessionModalTitle">Управление заседанием</h3>
            <button class="modal-close" onclick="closeSessionModal()">&times;</button>
        </div>
        <div id="sessionModalBody"></div>
    </div>
</div>

<style>
/* Стили (оставлены без изменений) */
/* Журнал движения дела */
.movement-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.movement-card h3 {
    font-size: 1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid var(--primary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
}

.movement-table-wrapper {
    overflow-x: auto;
}

.movement-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.movement-table th {
    background: var(--gray-50);
    padding: 0.75rem 1rem;
    text-align: left;
    font-weight: 600;
    color: var(--gray-700);
    border-bottom: 2px solid var(--gray-200);
    font-size: 0.75rem;
}

.movement-table td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--gray-100);
    vertical-align: top;
}

.movement-table tr:hover {
    background: var(--gray-50);
}

.movement-date {
    font-family: monospace;
    white-space: nowrap;
    font-weight: 500;
    color: var(--primary);
}

.movement-stage {
    font-weight: 500;
    color: var(--gray-700);
}

.movement-action {
    color: var(--gray-800);
}

.movement-result {
    color: var(--gray-600);
}

.movement-person {
    color: var(--gray-600);
    font-size: 0.8rem;
    white-space: nowrap;
}

/* Апелляции */
.appeal-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.appeal-card h3 {
    font-size: 1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid var(--warning);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--warning);
}

.appeal-status {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 2rem;
    font-size: 0.75rem;
    font-weight: 500;
    margin-bottom: 1rem;
}

.appeal-status.status-pending {
    background: #fff3cd;
    color: #856404;
}

.appeal-status.status-under_review {
    background: #cff4fc;
    color: #055160;
}

.appeal-status.status-satisfied {
    background: #d1e7dd;
    color: #0a3622;
}

.appeal-status.status-rejected {
    background: #f8d7da;
    color: #58151c;
}

.appeal-details {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.appeal-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.appeal-label {
    font-weight: 600;
    color: var(--gray-600);
    min-width: 140px;
}

.appeal-reason,
.appeal-decision {
    background: var(--gray-50);
    padding: 0.5rem 0.75rem;
    border-radius: var(--radius);
    font-size: 0.875rem;
    flex: 1;
}

.appeal-button-container {
    text-align: center;
    padding: 1rem;
}

.btn-warning {
    background: var(--warning);
    color: white;
}

.btn-warning:hover {
    background: #c49a00;
}

.appeal-expired {
    color: var(--gray-500);
    text-align: center;
    padding: 1rem;
}

.appeal-preview {
    background: var(--gray-50);
    border-radius: var(--radius);
    padding: 1rem;
    margin-bottom: 1rem;
}

.appeal-reason-preview,
.appeal-requirements-preview {
    background: white;
    padding: 0.5rem;
    border-radius: var(--radius);
    margin: 0.5rem 0;
}

/* Заседания */
.sessions-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.sessions-card h3 {
    font-size: 1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid var(--info);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.sessions-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.session-item {
    background: var(--gray-50);
    border-radius: var(--radius);
    padding: 1rem;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 1rem;
    position: relative;
}

.session-number {
    font-weight: 600;
    color: var(--primary);
    min-width: 120px;
}

.session-status.status-scheduled { color: var(--info); font-weight: 500; }
.session-status.status-in_progress { color: var(--primary); font-weight: 500; }
.session-status.status-postponed { color: var(--warning); font-weight: 500; }
.session-status.status-completed { color: var(--success); font-weight: 500; }
.session-status.status-cancelled { color: var(--danger); font-weight: 500; }

.session-comment {
    font-size: 0.7rem;
    color: var(--gray-500);
    margin-top: 0.25rem;
    width: 100%;
}

.session-actions {
    display: flex;
    gap: 0.5rem;
    margin-left: auto;
}

.btn-icon {
    background: var(--gray-200);
    border: none;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--gray-600);
}

.btn-icon:hover {
    background: var(--gray-300);
    color: var(--primary);
}

.btn-icon.delete:hover {
    background: #f8d7da;
    color: var(--danger);
}

/* Карточка с кнопкой редактирования */
.card-header-with-button {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--gray-200);
}

.card-header-with-button h3 {
    margin: 0;
    padding: 0;
    border: none;
}

/* Прокурорские действия */
.prosecutor-actions-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.prosecutor-actions-card h3 {
    font-size: 1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid var(--secondary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--secondary);
}

.prosecutor-actions {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

/* Форма загрузки доказательств */
.upload-evidence-section {
    background: var(--gray-50);
    border-radius: var(--radius);
    padding: 1rem;
    margin-bottom: 1rem;
    border: 1px solid var(--gray-200);
}

.upload-evidence-section h4 {
    font-size: 0.875rem;
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.upload-evidence-form .form-row {
    display: flex;
    gap: 1rem;
    align-items: flex-end;
}

.upload-evidence-form .form-group {
    flex: 1;
}

.upload-evidence-form .form-actions {
    margin-top: 0;
}

/* Модальное окно */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal-content {
    background: white;
    border-radius: var(--radius-lg);
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: var(--shadow-lg);
}

.modal-content.wide {
    max-width: 1000px;
}

.modal-header {
    padding: 1rem 1.5rem;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--gray-600);
}

.modal-close:hover {
    color: var(--danger);
}

.modal-body {
    padding: 1rem 1.5rem;
    max-height: 60vh;
    overflow-y: auto;
}

.modal-footer {
    padding: 1rem 1.5rem;
    background: var(--gray-50);
    border-top: 1px solid var(--gray-200);
    display: flex;
    justify-content: flex-end;
}

.modal-body .form-section {
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--gray-200);
}

.modal-body .form-section:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.modal-body .form-section h4 {
    font-size: 0.875rem;
    margin-bottom: 1rem;
    color: var(--primary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.session-form {
    padding: 1rem;
}

.session-form .form-actions {
    margin-top: 1rem;
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
}

/* Документы */
.document-item.court-doc {
    background: linear-gradient(135deg, #e8f0fe 0%, #fff 100%);
    border-left: 3px solid var(--primary);
}

.document-item.court-file {
    background: linear-gradient(135deg, #f0f4f8 0%, #fff 100%);
    border-left: 3px solid #2c5a8c;
}

.court-badge {
    display: inline-block;
    background: var(--primary);
    color: white;
    font-size: 0.6rem;
    font-weight: 500;
    padding: 0.125rem 0.5rem;
    border-radius: 1rem;
    margin-left: 0.5rem;
}

.claim-badge {
    display: inline-block;
    background: var(--secondary);
    color: white;
    font-size: 0.6rem;
    font-weight: 500;
    padding: 0.125rem 0.5rem;
    border-radius: 1rem;
    margin-left: 0.5rem;
}

.file-badge {
    display: inline-block;
    background: #6c757d;
    color: white;
    font-size: 0.6rem;
    font-weight: 600;
    padding: 0.125rem 0.375rem;
    border-radius: 0.25rem;
    margin-left: 0.5rem;
    font-family: monospace;
}

.unregistered-badge {
    display: inline-block;
    background: #ffc107;
    color: #212529;
    font-size: 0.6rem;
    font-weight: 500;
    padding: 0.125rem 0.375rem;
    border-radius: 0.25rem;
    margin-left: 0.5rem;
}

.form-control-file {
    padding: 0.5rem;
    border: 1px dashed var(--gray-300);
    border-radius: var(--radius);
    background: var(--gray-50);
}

.form-control-file::-webkit-file-upload-button {
    background: var(--primary);
    color: white;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: var(--radius);
    cursor: pointer;
    margin-right: 1rem;
}

.form-control-file::-webkit-file-upload-button:hover {
    background: var(--primary-dark);
}

/* Дополнительно */
.case-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.case-info-card,
.case-description-card,
.verdict-card,
.evidence-card,
.documents-card,
.registry-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.case-info-card h3,
.case-description-card h3,
.verdict-card h3,
.evidence-card h3,
.documents-card h3,
.registry-card h3 {
    font-size: 1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.info-table {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.info-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--gray-100);
}

.info-label {
    font-weight: 500;
    color: var(--gray-600);
    font-size: 0.875rem;
}

.info-value {
    font-size: 0.875rem;
    word-break: break-word;
}

.info-value a {
    color: var(--primary);
    text-decoration: none;
}

.info-value a:hover {
    text-decoration: underline;
}

@media (max-width: 768px) {
    .case-grid {
        grid-template-columns: 1fr;
    }
    
    .movement-table th,
    .movement-table td {
        padding: 0.5rem;
        font-size: 0.75rem;
    }
    
    .movement-person {
        white-space: normal;
    }
    
    .appeal-row {
        flex-direction: column;
    }
    
    .appeal-label {
        min-width: auto;
    }
    
    .session-item {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .session-actions {
        margin-left: 0;
        margin-top: 0.5rem;
    }
    
    .modal-content.wide {
        width: 95%;
    }
    
    .upload-evidence-form .form-row {
        flex-direction: column;
        align-items: stretch;
    }
    
    .prosecutor-actions {
        flex-direction: column;
    }
}
</style>

<script>
// Блокировка прокрутки body при открытом модальном окне
function disableBodyScroll() {
    document.body.style.overflow = 'hidden';
    document.body.style.paddingRight = '17px';
}

function enableBodyScroll() {
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
}

function togglePanel(panelId) {
    const panel = document.getElementById(panelId);
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
    } else {
        panel.style.display = 'none';
    }
}

function openAppealModal() {
    document.getElementById('appealModal').style.display = 'flex';
    disableBodyScroll();
}

function closeAppealModal() {
    document.getElementById('appealModal').style.display = 'none';
    enableBodyScroll();
}

function openEditModal() {
    document.getElementById('editCaseModal').style.display = 'flex';
    disableBodyScroll();
}

function closeEditModal() {
    document.getElementById('editCaseModal').style.display = 'none';
    enableBodyScroll();
}

function openMovementModal() {
    document.getElementById('movementModal').style.display = 'flex';
    disableBodyScroll();
}

function closeMovementModal() {
    document.getElementById('movementModal').style.display = 'none';
    enableBodyScroll();
}

function openIndictmentModal() {
    document.getElementById('indictmentModal').style.display = 'flex';
    disableBodyScroll();
}

function closeIndictmentModal() {
    document.getElementById('indictmentModal').style.display = 'none';
    enableBodyScroll();
}

function openSessionModal(sessionId, action) {
    const modal = document.getElementById('sessionModal');
    const modalTitle = document.getElementById('sessionModalTitle');
    const modalBody = document.getElementById('sessionModalBody');
    
    if (action === 'status') {
        modalTitle.innerHTML = 'Изменить статус заседания';
        modalBody.innerHTML = `
            <form method="POST" class="session-form">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="update_session_status">
                <input type="hidden" name="session_id" value="${sessionId}">
                
                <div class="form-group">
                    <label>Статус заседания</label>
                    <select name="session_status" class="form-control" required>
                        <option value="scheduled">Запланировано</option>
                        <option value="in_progress">В процессе</option>
                        <option value="postponed">Перенесено</option>
                        <option value="completed">Завершено</option>
                        <option value="cancelled">Отменено</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Комментарий (опционально)</label>
                    <textarea name="status_comment" class="form-control" rows="3" placeholder="Причина изменения статуса..."></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Сохранить</button>
                    <button type="button" class="btn btn-secondary" onclick="closeSessionModal()">Отмена</button>
                </div>
            </form>
        `;
    } else if (action === 'reschedule') {
        modalTitle.innerHTML = 'Перенос заседания';
        modalBody.innerHTML = `
            <form method="POST" class="session-form">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="reschedule_session">
                <input type="hidden" name="session_id" value="${sessionId}">
                
                <div class="form-group">
                    <label>Новая дата и время</label>
                    <input type="datetime-local" name="new_datetime" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Причина переноса</label>
                    <textarea name="reschedule_reason" class="form-control" rows="3" placeholder="Укажите причину переноса..." required></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Перенести</button>
                    <button type="button" class="btn btn-secondary" onclick="closeSessionModal()">Отмена</button>
                </div>
            </form>
        `;
    } else if (action === 'delete') {
        modalTitle.innerHTML = 'Удаление заседания';
        modalBody.innerHTML = `
            <form method="POST" class="session-form" onsubmit="return confirm('Вы уверены, что хотите удалить это заседание?');">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="delete_session">
                <input type="hidden" name="session_id" value="${sessionId}">
                
                <div class="form-group">
                    <label>Причина удаления</label>
                    <textarea name="delete_reason" class="form-control" rows="3" placeholder="Укажите причину удаления..." required></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-danger">Удалить</button>
                    <button type="button" class="btn btn-secondary" onclick="closeSessionModal()">Отмена</button>
                </div>
            </form>
        `;
    }
    
    modal.style.display = 'flex';
    disableBodyScroll();
}

function closeSessionModal() {
    document.getElementById('sessionModal').style.display = 'none';
    enableBodyScroll();
}

window.onclick = function(event) {
    const sessionModal = document.getElementById('sessionModal');
    const appealModal = document.getElementById('appealModal');
    const editModal = document.getElementById('editCaseModal');
    const movementModal = document.getElementById('movementModal');
    const indictmentModal = document.getElementById('indictmentModal');
    
    if (event.target == sessionModal) {
        closeSessionModal();
    }
    if (event.target == appealModal) {
        closeAppealModal();
    }
    if (event.target == editModal) {
        closeEditModal();
    }
    if (event.target == movementModal) {
        closeMovementModal();
    }
    if (event.target == indictmentModal) {
        closeIndictmentModal();
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>