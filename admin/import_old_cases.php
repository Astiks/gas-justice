<?php
// admin/import_old_cases.php
// Импорт исторических дел

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
$message = '';
$message_type = '';

// Обработка формы импорта
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Неверный CSRF-токен';
        $message_type = 'error';
    } else {
        $case_data = $_POST['case_data'] ?? '';
        $lines = explode("\n", $case_data);
        $imported = 0;
        $errors = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Формат: номер_дела|тип|название|истец|ответчик|судья|статья|решение|дата
            $parts = explode('|', $line);
            if (count($parts) < 5) {
                $errors[] = "Неверный формат: $line";
                continue;
            }
            
            // Генерируем УИД
            $case_type_code = $parts[1];
            $uid = generateUID($case_type_code);
            $case_number = generateCaseNumber($case_type_code);
            $case_type_name = getCaseTypeName($case_type_code);
            
            // Поиск ID участников
            $stmt = $pdo->prepare("SELECT id FROM users WHERE in_game_name = ? OR username = ?");
            $stmt->execute([$parts[3], $parts[3]]);
            $plaintiff = $stmt->fetch();
            
            $stmt = $pdo->prepare("SELECT id FROM users WHERE in_game_name = ? OR username = ?");
            $stmt->execute([$parts[4], $parts[4]]);
            $defendant = $stmt->fetch();
            
            $stmt = $pdo->prepare("SELECT id FROM users WHERE in_game_name = ? OR username = ?");
            $stmt->execute([$parts[5], $parts[5]]);
            $judge = $stmt->fetch();
            
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO court_cases (
                        uid, case_type_code, case_type_name,
                        case_number_sequence, case_number_full, case_year,
                        title, description,
                        plaintiff_id, defendant_id, judge_id,
                        article_code, verdict_text, status, created_at, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'archived', ?, ?)
                ");
                
                $stmt->execute([
                    $uid,
                    $case_type_code,
                    $case_type_name,
                    $case_number['sequence'],
                    $case_number['full_number'],
                    date('Y', strtotime($parts[8])),
                    $parts[2],
                    $parts[9] ?? 'Дело из архива',
                    $plaintiff['id'] ?? null,
                    $defendant['id'] ?? null,
                    $judge['id'] ?? null,
                    $parts[6] ?? null,
                    $parts[7] ?? null,
                    $parts[8],
                    $user['id']
                ]);
                
                $imported++;
                
            } catch (PDOException $e) {
                $errors[] = "Ошибка при импорте: " . $e->getMessage();
            }
        }
        
        $message = "Импортировано: $imported дел";
        if (!empty($errors)) {
            $message .= "\nОшибки: " . implode("\n", $errors);
        }
        $message_type = 'success';
    }
}

$page_title = 'Импорт исторических дел';
include __DIR__ . '/../includes/header.php';
?>

<div class="admin-page">
    <div class="page-header">
        <h1><i class="fas fa-database"></i> Импорт исторических дел</h1>
        <p>Добавление дел, которые были до создания сайта</p>
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <pre><?php echo h($message); ?></pre>
        </div>
    <?php endif; ?>
    
    <div class="admin-card">
        <h3><i class="fas fa-info-circle"></i> Инструкция</h3>
        <div class="info-content">
            <p>Для импорта дел используйте формат (каждое дело на новой строке):</p>
            <pre>
номер_дела|тип|название|истец|ответчик|судья|статья|решение|дата|описание
            </pre>
            <p><strong>Пример:</strong></p>
            <pre>
2-001/2025|2|Спор о ДТП|Ivanov|Petrov|Sidorov|1064 ГК РФ|Взыскать 50000$|2025-01-15|ДТП на перекрёстке...
            </pre>
            <p><strong>Типы дел:</strong> 1-уголовное, 2-гражданское, 2а-административное, 3-судебный контроль, 4-исполнение приговоров, 5-административное особое</p>
        </div>
    </div>
    
    <div class="admin-card">
        <h3><i class="fas fa-upload"></i> Форма импорта</h3>
        <form method="POST" class="styled-form">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            
            <div class="form-group">
                <label>Данные для импорта</label>
                <textarea name="case_data" class="form-control" rows="15" 
                          placeholder="2-001/2025|2|Спор о ДТП|Ivanov|Petrov|Sidorov|1064 ГК РФ|Взыскать 50000$|2025-01-15|Описание..."></textarea>
                <small>Каждое дело на новой строке. Поля разделяются символом | (вертикальная черта)</small>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-upload"></i> Импортировать дела
                </button>
                <a href="/admin/" class="btn btn-secondary">Отмена</a>
            </div>
        </form>
    </div>
    
    <div class="admin-card">
        <h3><i class="fas fa-database"></i> Текущая статистика</h3>
        <?php
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM court_cases");
        $total = $stmt->fetch()['total'];
        
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM court_cases WHERE status = 'archived'");
        $archived = $stmt->fetch()['total'];
        ?>
        <div class="stats-mini">
            <div class="stat-mini-item">
                <div class="stat-mini-number"><?php echo $total; ?></div>
                <div class="stat-mini-label">Всего дел</div>
            </div>
            <div class="stat-mini-item">
                <div class="stat-mini-number"><?php echo $archived; ?></div>
                <div class="stat-mini-label">Архивных дел</div>
            </div>
        </div>
    </div>
</div>

<style>
.info-content pre {
    background: var(--gray-100);
    padding: 0.75rem;
    border-radius: var(--radius);
    overflow-x: auto;
    font-size: 0.75rem;
}

.stats-mini {
    display: flex;
    gap: 1rem;
    justify-content: center;
}

.stat-mini-item {
    text-align: center;
    padding: 1rem;
    background: var(--gray-50);
    border-radius: var(--radius);
    flex: 1;
}

.stat-mini-number {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--primary);
}

.stat-mini-label {
    font-size: 0.7rem;
    color: var(--gray-600);
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>