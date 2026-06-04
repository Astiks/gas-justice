<?php
// includes/functions.php
// Вспомогательные функции

// Генерация УИД в формате: RS0052-XX-YYYY-DDMMYY-NN
function generateUID($case_type_code = null) {
    // Фиксированный код судебного органа
    $court_code = 'RS0052';
    
    // Маппинг типа дела -> индекс судопроизводства
    $index_map = [
        '1' => '01',   // уголовное дело
        '2' => '02',   // гражданское дело
        '2а' => '03',  // административное дело
        '3' => '04',   // судебный контроль
        '4' => '05',   // исполнение приговоров
        '5' => '06',   // дело об административном правонарушении
        '12' => '07'   // жалоба на постановление по делу об АП
    ];
    
    $case_index = $index_map[$case_type_code] ?? '00';
    $year = date('Y');
    $date_part = date('dmy');
    
    $pdo = getDB();
    
    $pdo->exec("LOCK TABLES uid_counter WRITE");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS uid_counter (
            id INT PRIMARY KEY AUTO_INCREMENT,
            year INT NOT NULL,
            last_sequence INT DEFAULT 0,
            UNIQUE KEY idx_year (year)
        )
    ");
    
    $stmt = $pdo->prepare("SELECT last_sequence FROM uid_counter WHERE year = ?");
    $stmt->execute([$year]);
    $counter = $stmt->fetch();
    
    if ($counter) {
        $next_num = $counter['last_sequence'] + 1;
        $stmt = $pdo->prepare("UPDATE uid_counter SET last_sequence = ? WHERE year = ?");
        $stmt->execute([$next_num, $year]);
    } else {
        $next_num = 1;
        $stmt = $pdo->prepare("INSERT INTO uid_counter (year, last_sequence) VALUES (?, ?)");
        $stmt->execute([$year, $next_num]);
    }
    
    $pdo->exec("UNLOCK TABLES");
    
    $sequence = str_pad($next_num, 2, '0', STR_PAD_LEFT);
    
    return $court_code . '-' . $case_index . '-' . $year . '-' . $date_part . '-' . $sequence;
}

// Генерация номера дела
function generateCaseNumber($case_type_code) {
    $pdo = getDB();
    $year = date('Y');
    
    $pdo->exec("LOCK TABLES case_counters WRITE");
    
    $stmt = $pdo->prepare("SELECT current_sequence FROM case_counters WHERE case_type_code = ? AND case_year = ?");
    $stmt->execute([$case_type_code, $year]);
    $counter = $stmt->fetch();
    
    if (!$counter) {
        $stmt = $pdo->prepare("INSERT INTO case_counters (case_type_code, case_year, current_sequence) VALUES (?, ?, 0)");
        $stmt->execute([$case_type_code, $year]);
        $current_sequence = 0;
    } else {
        $current_sequence = $counter['current_sequence'];
    }
    
    $new_sequence = $current_sequence + 1;
    $stmt = $pdo->prepare("UPDATE case_counters SET current_sequence = ? WHERE case_type_code = ? AND case_year = ?");
    $stmt->execute([$new_sequence, $case_type_code, $year]);
    
    $pdo->exec("UNLOCK TABLES");
    
    $padded_sequence = str_pad($new_sequence, 3, '0', STR_PAD_LEFT);
    $case_number_full = $case_type_code . '-' . $padded_sequence . '/' . $year;
    
    return [
        'sequence' => $new_sequence,
        'full_number' => $case_number_full
    ];
}

// Типы дел с их названиями (ОБНОВЛЕНО)
function getCaseTypes() {
    return [
        '1' => 'Уголовное дело',
        '2' => 'Гражданское дело',
        '2а' => 'Административное дело',
        '3' => 'Материалы, рассматриваемые в порядке судебного контроля',
        '4' => 'Исполнение приговоров: рассмотрение представлений и ходатайств',
        '5' => 'Дело об административном правонарушении',
        '12' => 'Дело по жалобе на постановление по делу об административном правонарушении'
    ];
}

function getCaseTypeName($code) {
    $types = getCaseTypes();
    return $types[$code] ?? 'Неизвестный тип';
}

// Базовые статусы дел (технические названия)
function getCaseStatuses() {
    return [
        'draft' => 'Черновик',
        'accepted' => 'Принято к производству',
        'preparing' => 'Подготовка к заседанию',
        'trial' => 'Судебное разбирательство',
        'verdict' => 'Решение вынесено',
        'appealed' => 'Обжаловано',
        'archived' => 'Архив'
    ];
}

// Получить название финального статуса в зависимости от типа дела (ОБНОВЛЕНО)
function getFinalStatusName($case_type_code) {
    $labels = [
        '1' => 'Приговор вынесен',
        '2' => 'Решение вынесено',
        '2а' => 'Решение вынесено',
        '3' => 'Постановление вынесено',
        '4' => 'Определение вынесено',
        '5' => 'Постановление вынесено',
        '12' => 'Решение вынесено'
    ];
    return $labels[$case_type_code] ?? 'Решение вынесено';
}

// Получить название документа (приговор/решение/постановление/определение) (ОБНОВЛЕНО)
function getVerdictDocumentName($case_type_code) {
    $labels = [
        '1' => 'Приговор',
        '2' => 'Решение',
        '2а' => 'Решение',
        '3' => 'Постановление',
        '4' => 'Определение',
        '5' => 'Постановление',
        '12' => 'Решение'
    ];
    return $labels[$case_type_code] ?? 'Решение';
}

// Получить название статуса (с учётом типа дела для финального статуса)
function getStatusName($status, $case_type_code = null) {
    $statuses = getCaseStatuses();
    
    // Для финального статуса используем динамическое название
    if ($status === 'verdict' && $case_type_code) {
        return getFinalStatusName($case_type_code);
    }
    
    return $statuses[$status] ?? $status;
}

// Получить название действия для кнопки (вынести приговор/решение/постановление)
function getVerdictActionName($case_type_code) {
    $document_name = getVerdictDocumentName($case_type_code);
    return 'Вынести ' . mb_strtolower($document_name);
}

// Получить название для кнопки публикации
function getPublishButtonName($case_type_code) {
    $document_name = getVerdictDocumentName($case_type_code);
    return 'Опубликовать ' . mb_strtolower($document_name);
}

// Получить название роли участника в зависимости от типа дела (ОБНОВЛЕНО)
function getParticipantLabel($case_type_code, $participant_role) {
    $labels = [
        '1' => [  // Уголовное дело
            'plaintiff' => 'Потерпевший',
            'defendant' => 'Обвиняемый'
        ],
        '2' => [  // Гражданское дело
            'plaintiff' => 'Истец',
            'defendant' => 'Ответчик'
        ],
        '2а' => [ // Административное дело
            'plaintiff' => 'Административный истец',
            'defendant' => 'Административный ответчик'
        ],
        '3' => [  // Судебный контроль
            'plaintiff' => 'Заявитель',
            'defendant' => null
        ],
        '4' => [  // Исполнение приговоров
            'plaintiff' => 'Осуждённый',
            'defendant' => null
        ],
        '5' => [  // Дело об административном правонарушении
            'plaintiff' => 'Заявитель',
            'defendant' => 'Лицо, привлекаемое к адм.ответственности'
        ],
        '12' => [ // Жалоба на постановление по делу об АП
            'plaintiff' => 'Заявитель',
            'defendant' => 'Должностное лицо'
        ]
    ];
    
    return $labels[$case_type_code][$participant_role] ?? ($participant_role == 'plaintiff' ? 'Истец' : 'Ответчик');
}

function auditLog($user_id, $action, $case_uid = null, $old_value = null, $new_value = null) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO audit_log (user_id, action, case_uid, old_value, new_value, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt->execute([
        $user_id,
        $action,
        $case_uid,
        $old_value,
        $new_value,
        $ip,
        $user_agent
    ]);
}

function createNotification($user_id, $type, $title, $message, $link = null) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, type, title, message, link)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $type, $title, $message, $link]);
}

function getUsersByRole($role) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, username, in_game_name FROM users WHERE role = ? AND is_active = 1 ORDER BY in_game_name");
    $stmt->execute([$role]);
    return $stmt->fetchAll();
}

function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// ============================================
// ЗАПИСЬ В ЖУРНАЛ ДВИЖЕНИЯ ДЕЛА
// ============================================
function addCaseMovement($case_uid, $stage, $action_text, $result_text = null, $responsible_person = null, $document_link = null) {
    $pdo = getDB();
    $movement_date = date('Y-m-d');
    $user_id = $_SESSION['user_id'] ?? null;
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS case_movement (
            id INT PRIMARY KEY AUTO_INCREMENT,
            case_uid VARCHAR(50) NOT NULL,
            movement_date DATE NOT NULL,
            stage VARCHAR(100) NOT NULL,
            action_text TEXT NOT NULL,
            result_text VARCHAR(500),
            responsible_person VARCHAR(255),
            document_link VARCHAR(500),
            user_id INT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (case_uid) REFERENCES court_cases(uid) ON DELETE CASCADE,
            INDEX idx_case (case_uid),
            INDEX idx_date (movement_date)
        )
    ");
    
    $stmt = $pdo->prepare("
        INSERT INTO case_movement (case_uid, movement_date, stage, action_text, result_text, responsible_person, document_link, user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$case_uid, $movement_date, $stage, $action_text, $result_text, $responsible_person, $document_link, $user_id]);
}

// ============================================
// ФУНКЦИИ ДЛЯ АПЕЛЛЯЦИЙ
// ============================================

// Проверка, можно ли подать апелляцию на дело
function canAppeal($case) {
    if ($case['status'] !== 'verdict') {
        return false;
    }
    
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, status FROM appeals WHERE case_uid = ? AND status NOT IN ('rejected')");
    $stmt->execute([$case['uid']]);
    if ($stmt->fetch()) {
        return false;
    }
    
    $verdict_date = strtotime($case['updated_at']);
    $days_passed = (time() - $verdict_date) / 86400;
    
    return $days_passed <= 30;
}

// Получить информацию об апелляции по делу
function getAppealInfo($case_uid) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT a.*, u.in_game_name as appellant_name, 
               u2.in_game_name as reviewer_name,
               ac.case_number_full as appeal_case_number
        FROM appeals a
        LEFT JOIN users u ON a.appellant_id = u.id
        LEFT JOIN users u2 ON a.reviewed_by = u2.id
        LEFT JOIN court_cases ac ON a.appeal_case_uid = ac.uid
        WHERE a.case_uid = ?
    ");
    $stmt->execute([$case_uid]);
    return $stmt->fetch();
}

// ============================================
// ФУНКЦИИ ДЛЯ АДВОКАТОВ
// ============================================

// Получить список доверителей адвоката
function getLawyerClients($lawyer_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT u.id, u.in_game_name, u.username, u.email, u.avatar,
               (SELECT COUNT(*) FROM court_cases WHERE plaintiff_id = u.id OR defendant_id = u.id) as cases_count
        FROM lawyer_clients lc
        JOIN users u ON lc.client_id = u.id
        WHERE lc.lawyer_id = ?
        ORDER BY u.in_game_name
    ");
    $stmt->execute([$lawyer_id]);
    return $stmt->fetchAll();
}

// Получить список адвокатов для гражданина
function getClientLawyers($client_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT u.id, u.in_game_name, u.username
        FROM lawyer_clients lc
        JOIN users u ON lc.lawyer_id = u.id
        WHERE lc.client_id = ?
        ORDER BY u.in_game_name
    ");
    $stmt->execute([$client_id]);
    return $stmt->fetchAll();
}

// Проверить, является ли пользователь адвокатом доверителя
function isLawyerOfClient($lawyer_id, $client_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT id FROM lawyer_clients 
        WHERE lawyer_id = ? AND client_id = ?
    ");
    $stmt->execute([$lawyer_id, $client_id]);
    return (bool)$stmt->fetch();
}

// Добавить доверителя
function addClient($lawyer_id, $client_id) {
    $pdo = getDB();
    // Проверяем, существует ли уже связь
    $stmt = $pdo->prepare("
        SELECT id FROM lawyer_clients WHERE lawyer_id = ? AND client_id = ?
    ");
    $stmt->execute([$lawyer_id, $client_id]);
    if ($stmt->fetch()) {
        return false;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO lawyer_clients (lawyer_id, client_id)
        VALUES (?, ?)
    ");
    return $stmt->execute([$lawyer_id, $client_id]);
}

// Удалить доверителя
function removeClient($lawyer_id, $client_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        DELETE FROM lawyer_clients 
        WHERE lawyer_id = ? AND client_id = ?
    ");
    return $stmt->execute([$lawyer_id, $client_id]);
}

// Получить все дела доверителя
function getClientCases($client_id, $limit = null) {
    $pdo = getDB();
    $sql = "
        SELECT c.*, 
               u1.in_game_name as plaintiff_name, u1.id as plaintiff_id,
               u2.in_game_name as defendant_name, u2.id as defendant_id,
               u3.in_game_name as judge_name, u3.id as judge_id
        FROM court_cases c
        LEFT JOIN users u1 ON c.plaintiff_id = u1.id
        LEFT JOIN users u2 ON c.defendant_id = u2.id
        LEFT JOIN users u3 ON c.judge_id = u3.id
        WHERE c.plaintiff_id = ? OR c.defendant_id = ?
        ORDER BY c.created_at DESC
    ";
    if ($limit) {
        $sql .= " LIMIT " . intval($limit);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$client_id, $client_id]);
    return $stmt->fetchAll();
}

// Получить всех пользователей (для поиска при добавлении доверителя)
function getAllUsers($exclude_id = null) {
    $pdo = getDB();
    $sql = "SELECT id, in_game_name, username FROM users WHERE role = 'citizen'";
    if ($exclude_id) {
        $sql .= " AND id != ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$exclude_id]);
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }
    return $stmt->fetchAll();
}

// ============================================
// ФУНКЦИИ ДЛЯ ПРОКУРОРА
// ============================================

// Получить дела, ожидающие прокурора
function getCasesWaitingForProsecutor() {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT c.*, 
               u1.in_game_name as plaintiff_name, u1.id as plaintiff_id,
               u2.in_game_name as defendant_name, u2.id as defendant_id
        FROM court_cases c
        LEFT JOIN users u1 ON c.plaintiff_id = u1.id
        LEFT JOIN users u2 ON c.defendant_id = u2.id
        WHERE c.case_type_code = '1' 
          AND c.prosecutor_id IS NULL 
          AND c.status NOT IN ('archived', 'verdict')
        ORDER BY c.created_at ASC
        LIMIT 20
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

// Получить дела прокурора
function getProsecutorCases($prosecutor_id, $limit = null) {
    $pdo = getDB();
    $sql = "
        SELECT c.*, 
               u1.in_game_name as plaintiff_name,
               u2.in_game_name as defendant_name,
               u3.in_game_name as judge_name
        FROM court_cases c
        LEFT JOIN users u1 ON c.plaintiff_id = u1.id
        LEFT JOIN users u2 ON c.defendant_id = u2.id
        LEFT JOIN users u3 ON c.judge_id = u3.id
        WHERE c.prosecutor_id = ? AND c.status NOT IN ('archived')
        ORDER BY c.created_at DESC
    ";
    if ($limit) {
        $sql .= " LIMIT " . intval($limit);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$prosecutor_id]);
    return $stmt->fetchAll();
}

// Получить статистику прокурора
function getProsecutorStats($prosecutor_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_cases,
            SUM(CASE WHEN status = 'verdict' THEN 1 ELSE 0 END) as completed_cases,
            SUM(CASE WHEN verdict_text LIKE '%оправдать%' OR verdict_text LIKE '%оправдан%' THEN 1 ELSE 0 END) as acquittals
        FROM court_cases 
        WHERE prosecutor_id = ?
    ");
    $stmt->execute([$prosecutor_id]);
    $stats = $stmt->fetch();
    
    $convictions = $stats['completed_cases'] - $stats['acquittals'];
    $stats['convictions'] = $convictions;
    $stats['win_rate'] = $stats['completed_cases'] > 0 ? round($convictions / $stats['completed_cases'] * 100) : 0;
    
    return $stats;
}