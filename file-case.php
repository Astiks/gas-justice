<?php
// file-case.php
// Форма подачи нового иска/заявления (с привязкой ответчика, выбором доверителя для адвокатов и полем VK)

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Проверка авторизации
if (!isLoggedIn()) {
    redirect('/index.php');
}

$user = getCurrentUser();
$page_title = 'Подача иска';
$pdo = getDB();
$errors = [];
$success = false;

// Получаем список типов дел
$case_types = getCaseTypes();

// Для адвоката — список доверителей
$my_clients = [];
if ($user['role'] === 'lawyer') {
    $my_clients = getLawyerClients($user['id']);
}

// Обработка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'file_case') {
    // CSRF проверка
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Неверный CSRF-токен. Попробуйте снова.';
    } else {
        // Получаем данные формы
        $case_type_code = $_POST['case_type'] ?? '';
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $defendant_search = trim($_POST['defendant_search'] ?? '');
        $article_code = trim($_POST['article_code'] ?? '');
        $article_text = trim($_POST['article_text'] ?? '');
        $vk = trim($_POST['vk'] ?? '');
        
        // Для адвоката — выбор доверителя
        $represent_client_id = null;
        if ($user['role'] === 'lawyer' && !empty($_POST['represent_client'])) {
            $represent_client_id = intval($_POST['represent_client']);
            // Проверяем, что этот пользователь действительно является доверителем
            if (!isLawyerOfClient($user['id'], $represent_client_id)) {
                $represent_client_id = null;
                $errors[] = 'Вы не являетесь адвокатом выбранного доверителя';
            }
        }
        
        // Валидация
        if (!isset($case_types[$case_type_code])) {
            $errors[] = 'Выберите корректный тип дела';
        }
        
        if (strlen($title) < 5) {
            $errors[] = 'Название дела должно содержать минимум 5 символов';
        }
        
        if (strlen($description) < 20) {
            $errors[] = 'Описание дела должно содержать минимум 20 символов';
        }
        
        if (empty($defendant_search)) {
            $errors[] = 'Укажите ответчика (игровой ник)';
        }
        
        // Валидация VK (если указан)
        if (!empty($vk) && !filter_var($vk, FILTER_VALIDATE_URL)) {
            $errors[] = 'Укажите корректную ссылку на страницу ВКонтакте';
        }
        
        // Поиск ответчика по игровому нику
        $defendant_id = null;
        $defendant_name = $defendant_search;
        
        if (!empty($defendant_search)) {
            $stmt = $pdo->prepare("SELECT id, in_game_name FROM users WHERE in_game_name = ? OR username = ?");
            $stmt->execute([$defendant_search, $defendant_search]);
            $defendant = $stmt->fetch();
            if ($defendant) {
                $defendant_id = $defendant['id'];
                $defendant_name = $defendant['in_game_name'];
            }
        }
        
        // Определяем истца
        if ($represent_client_id) {
            $stmt = $pdo->prepare("SELECT in_game_name FROM users WHERE id = ?");
            $stmt->execute([$represent_client_id]);
            $client = $stmt->fetch();
            $plaintiff_id = $represent_client_id;
            $plaintiff_name = $client['in_game_name'] ?? $user['in_game_name'];
            $is_representative = true;
        } else {
            $plaintiff_id = $user['id'];
            $plaintiff_name = $user['in_game_name'];
            $is_representative = false;
        }
        
        // Если ошибок нет — создаём дело
        if (empty($errors)) {
            try {
                // Генерируем УИД и номер дела
                $uid = generateUID($case_type_code);
                $case_number = generateCaseNumber($case_type_code);
                
                $case_type_name = $case_types[$case_type_code];
                $year = date('Y');
                $status = 'draft';
                
                // Вставка дела
                $stmt = $pdo->prepare("
                    INSERT INTO court_cases (
                        uid, case_type_code, case_type_name, 
                        case_number_sequence, case_number_full, case_year,
                        title, description, plaintiff_id, defendant_id,
                        article_code, article_text, vk, status, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $uid,
                    $case_type_code,
                    $case_type_name,
                    $case_number['sequence'],
                    $case_number['full_number'],
                    $year,
                    $title,
                    $description,
                    $plaintiff_id,
                    $defendant_id,
                    $article_code,
                    $article_text,
                    $vk,
                    $status,
                    $user['id']
                ]);
                
                // Если ответчик не найден в БД, сохраняем его имя в description
                if ($defendant_id === null && !empty($defendant_name)) {
                    $stmt = $pdo->prepare("
                        UPDATE court_cases 
                        SET description = CONCAT('[Ответчик по указанию истца: ', ?, ']\n\n', description)
                        WHERE uid = ?
                    ");
                    $stmt->execute([$defendant_name, $uid]);
                }
                
                // Создаём документ (исковое заявление)
                $document_content = "ИСКОВОЕ ЗАЯВЛЕНИЕ\n\n";
                if ($is_representative) {
                    $document_content .= "Истец: " . $plaintiff_name . " (в лице адвоката " . $user['in_game_name'] . ")\n";
                } else {
                    $document_content .= "Истец: " . $user['in_game_name'] . "\n";
                }
                $document_content .= "Ответчик: " . $defendant_name . ($defendant_id ? " (зарегистрирован)" : " (не зарегистрирован)") . "\n";
                $document_content .= "Статья: " . ($article_code ?: 'Не указана') . "\n\n";
                $document_content .= "ОПИСАНИЕ ОБСТОЯТЕЛЬСТВ:\n" . $description . "\n\n";
                if (!empty($vk)) {
                    $document_content .= "Контактная информация (VK): " . $vk . "\n\n";
                }
                $document_content .= "НА ОСНОВАНИИ ВЫШЕИЗЛОЖЕННОГО,\n\n";
                $document_content .= "ПРОШУ:\n";
                $document_content .= "1. Принять обращение к производству.\n";
                $document_content .= "2. Вызвать стороны в судебное заседание.\n";
                $document_content .= "3. Вынести решение в соответствии с законодательством РФ.\n\n";
                $document_content .= "Дата подачи: " . date('d.m.Y H:i:s') . "\n";
                $document_content .= "____________________ /" . ($is_representative ? $plaintiff_name : $user['in_game_name']) . "/";
                
                $stmt = $pdo->prepare("
                    INSERT INTO documents (case_uid, document_type, title, content, author_id)
                    VALUES (?, 'claim', ?, ?, ?)
                ");
                $stmt->execute([$uid, $title, $document_content, $user['id']]);
                
                // Обработка загруженных файлов (доказательства)
                if (!empty($_FILES['evidence']['name'][0])) {
                    $upload_dir = __DIR__ . '/uploads/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                }
    
                $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'webm', 'txt', 'log', 'pdf', 'doc', 'docx', 'rtf'];
    
                foreach ($_FILES['evidence']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['evidence']['error'][$key] === UPLOAD_ERR_OK) {
                        $original_name = $_FILES['evidence']['name'][$key];
                        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            
                        if (!in_array($ext, $allowed_ext)) {
                            continue; // Пропускаем неподдерживаемые файлы
                        }
            
                        $safe_name = $uid . '_' . time() . '_' . $key . '.' . $ext;
                        $target_path = $upload_dir . $safe_name;
            
                        if (move_uploaded_file($tmp_name, $target_path)) {
                            $stmt = $pdo->prepare("
                                INSERT INTO evidence (case_uid, evidence_type, title, file_path, uploaded_by)
                                VALUES (?, 'screenshot', ?, ?, ?)
                            ");
                            $stmt->execute([$uid, $original_name, 'uploads/' . $safe_name, $user['id']]);
                        }
                    }
                }
            }
                
                // Запись в журнал движения дела
                if ($is_representative) {
                    addCaseMovement(
                        $uid,
                        'Поступление дела',
                        'Поступление искового заявления (от имени доверителя)',
                        "Зарегистрировано № {$case_number['full_number']} (истец: $plaintiff_name, адвокат: " . $user['in_game_name'] . ")",
                        $user['in_game_name'] . ' (адвокат)'
                    );
                } else {
                    addCaseMovement(
                        $uid,
                        'Поступление дела',
                        'Поступление искового заявления',
                        "Зарегистрировано № {$case_number['full_number']}",
                        $user['in_game_name'] . ' (истец)'
                    );
                }
                
                // Создаём уведомление для председателя суда (о новом деле)
                $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'chairman' AND is_active = 1 LIMIT 1");
                $stmt->execute();
                $chairman = $stmt->fetch();
                
                if ($chairman) {
                    createNotification(
                        $chairman['id'],
                        'new_case',
                        'Новое дело',
                        "Поступило новое дело {$case_number['full_number']} от " . ($is_representative ? $plaintiff_name . ' (адвокат ' . $user['in_game_name'] . ')' : $user['in_game_name']),
                        "/case.php?uid=" . urlencode($uid)
                    );
                }
                
                // Если ответчик найден в БД — уведомляем его
                if ($defendant_id) {
                    createNotification(
                        $defendant_id,
                        'new_case_defendant',
                        'Вы указаны ответчиком по делу',
                        "В отношении вас подано исковое заявление № {$case_number['full_number']}",
                        "/case.php?uid=" . urlencode($uid)
                    );
                }
                
                // Логируем действие
                auditLog($user['id'], 'case_created', $uid, null, "case_number: {$case_number['full_number']}, defendant_id: " . ($defendant_id ?: 'not_registered') . ", representative: " . ($is_representative ? $plaintiff_id : 'self'));
                
                $_SESSION['case_created_success'] = $case_number['full_number'];
                redirect('/file-case.php?success=1');
                
            } catch (PDOException $e) {
                $errors[] = 'Ошибка при создании дела: ' . $e->getMessage();
            }
        }
    }
}

// Показываем сообщение об успехе
if (isset($_GET['success']) && isset($_SESSION['case_created_success'])) {
    $success = true;
    $created_case_number = $_SESSION['case_created_success'];
    unset($_SESSION['case_created_success']);
}

include __DIR__ . '/includes/header.php';
?>

<div class="file-case-page">
    <div class="page-header">
        <h1><i class="fas fa-file-alt"></i> Подача искового заявления</h1>
        <p>Заполните форму для подачи иска в ГАС «Юстиция»</p>
    </div>
    
    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <div>
                <strong>Иск успешно подан!</strong><br>
                Номер вашего дела: <strong><?php echo h($created_case_number); ?></strong><br>
                Статус дела: <strong>Черновик</strong> — после проверки судьёй дело будет принято к производству.<br>
                <a href="/search.php">Перейти к поиску дел</a> | 
                <a href="/dashboard.php">Вернуться на дашборд</a>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-triangle"></i>
            <div>
                <strong>Ошибка при подаче иска:</strong>
                <ul style="margin: 0.5rem 0 0 1.5rem;">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo h($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="form-card">
        <form method="POST" action="" enctype="multipart/form-data" class="styled-form" id="fileCaseForm">
            <input type="hidden" name="action" value="file_case">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            
            <!-- Тип дела -->
            <div class="form-section">
                <h3><i class="fas fa-tag"></i> Тип дела</h3>
                <div class="form-group">
                    <label for="case_type">Выберите категорию дела <span class="required">*</span></label>
                    <select name="case_type" id="case_type" class="form-control" required>
                        <option value="">-- Выберите тип дела --</option>
                        <?php foreach ($case_types as $code => $name): ?>
                            <option value="<?php echo h($code); ?>" <?php echo isset($_POST['case_type']) && $_POST['case_type'] == $code ? 'selected' : ''; ?>>
                                <?php echo h($code) . ' — ' . h($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>От типа дела зависит порядок рассмотрения и компетенция суда</small>
                </div>
            </div>
            
            <!-- Информация о деле -->
            <div class="form-section">
                <h3><i class="fas fa-info-circle"></i> Информация о деле</h3>
                
                <div class="form-group">
                    <label for="title">Название дела <span class="required">*</span></label>
                    <input type="text" id="title" name="title" class="form-control" 
                           value="<?php echo isset($_POST['title']) ? h($_POST['title']) : ''; ?>"
                           placeholder="Например: Взыскание ущерба после ДТП" required>
                    <small>Краткое описание сути дела (5-100 символов)</small>
                </div>
                
                <div class="form-group">
                    <label for="description">Описание обстоятельств <span class="required">*</span></label>
                    <textarea id="description" name="description" class="form-control" rows="10" 
                              placeholder="Подробно опишите, что произошло, когда, где, кто участвовал, какие последствия...

Пример:
08.03.2026 примерно в 20:30 на пересечении улиц Ленина и Советской, водитель автомобиля Tesla (госномер А777ВВ) под управлением гражданина Петрова А.А., не справился с управлением и совершил наезд на мой автомобиль Toyota (госномер В666СС), причинив механические повреждения.

Прошу взыскать стоимость ремонта в размере 50.000 руб и компенсацию морального вреда в размере 10.000 руб." required><?php echo isset($_POST['description']) ? h($_POST['description']) : ''; ?></textarea>
                    <small>Минимум 20 символов. Будьте максимально подробны. Укажите дату, время, место, участников.</small>
                </div>
            </div>
            
            <!-- Стороны дела -->
            <div class="form-section">
                <h3><i class="fas fa-users"></i> Стороны дела</h3>
                
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Истец</label>
                        <input type="text" class="form-control" value="<?php echo h($user['in_game_name']); ?>" disabled>
                        <small>Вы (автоматически)</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="defendant_search">Ответчик <span class="required">*</span></label>
                        <input type="text" id="defendant_search" name="defendant_search" class="form-control"
                               value="<?php echo isset($_POST['defendant_search']) ? h($_POST['defendant_search']) : ''; ?>"
                               placeholder="Введите игровой ник ответчика" required>
                        <small>Укажите ник ответчика, как он написан в игре</small>
                    </div>
                </div>
                
                <!-- Для адвоката: выбор доверителя -->
                <?php if ($user['role'] === 'lawyer' && !empty($my_clients)): ?>
                <div class="form-group" style="margin-top: 0.75rem;">
                    <label for="represent_client">
                        <i class="fas fa-user-tie"></i> Подать от имени доверителя (опционально)
                    </label>
                    <select name="represent_client" id="represent_client" class="form-control">
                        <option value="">-- От своего имени --</option>
                        <?php foreach ($my_clients as $client): ?>
                            <option value="<?php echo $client['id']; ?>" <?php echo isset($_POST['represent_client']) && $_POST['represent_client'] == $client['id'] ? 'selected' : ''; ?>>
                                <?php echo h($client['in_game_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>Если вы представляете интересы доверителя, выберите его из списка</small>
                </div>
                <?php endif; ?>
                
                <div id="defendantNotFound" class="info-message warning" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Пользователь с таким ником не найден в системе. Ответчик будет указан текстом, но не привязан к аккаунту.</span>
                </div>
                
                <div id="defendantFound" class="info-message success" style="display: none;">
                    <i class="fas fa-check-circle"></i>
                    <span>Пользователь найден! Ответчик будет привязан к аккаунту и получит уведомление.</span>
                </div>
            </div>
            
            <!-- Правовая информация -->
            <div class="form-section">
                <h3><i class="fas fa-balance-scale"></i> Правовая информация</h3>
                
                <div class="form-group">
                    <label for="article_code">Статья УК/КоАП/ГК (опционально)</label>
                    <input type="text" id="article_code" name="article_code" class="form-control"
                           value="<?php echo isset($_POST['article_code']) ? h($_POST['article_code']) : ''; ?>"
                           placeholder="Например: 158 УК РФ, 12.15 КоАП РФ, 1064 ГК РФ">
                    <small>Укажите норму закона, которая, по вашему мнению, нарушена</small>
                </div>
                
                <div class="form-group">
                    <label for="article_text">Текст статьи (опционально)</label>
                    <textarea id="article_text" name="article_text" class="form-control" rows="6"
                              placeholder="Вставьте текст статьи, на которую ссылаетесь..."><?php echo isset($_POST['article_text']) ? h($_POST['article_text']) : ''; ?></textarea>
                    <small>Для удобства судьи вы можете скопировать сюда полный текст статьи</small>
                </div>
            </div>
            
            <!-- Контактные данные -->
            <div class="form-section">
                <h3><i class="fab fa-vk"></i> Контактные данные</h3>
                
                <div class="form-group">
                    <label for="vk">Страница ВКонтакте (опционально)</label>
                    <input type="url" id="vk" name="vk" class="form-control" 
                           value="<?php echo isset($_POST['vk']) ? h($_POST['vk']) : ''; ?>"
                           placeholder="https://vk.com/id123456789 или https://vk.com/username">
                    <small>Укажите ссылку на вашу страницу ВКонтакте для оперативной связи</small>
                </div>
            </div>
            
            <!-- Доказательства -->
            <div class="form-section">
                <h3><i class="fas fa-image"></i> Доказательства</h3>
                
                <div class="form-group">
                    <label for="evidence">Приложите доказательства (скриншоты, видео, логи)</label>
                    <input type="file" id="evidence" name="evidence[]" class="form-control-file" multiple 
                           accept="image/jpeg,image/png,image/gif,video/mp4,text/plain">
                    <small>Можно загрузить несколько файлов. Поддерживаются: JPG, PNG, GIF, MP4, TXT, LOG. Максимальный размер файла — 10 МБ.</small>
                </div>
                
                <div id="fileList" class="file-list"></div>
                
                <div class="info-message">
                    <i class="fas fa-info-circle"></i>
                    <span>Скриншоты чата, видео POV (вид от первого лица) и логи являются наиболее весомыми доказательствами в суде.</span>
                </div>
            </div>
            
            <!-- Отправка -->
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-paper-plane"></i> Подать обращение
                </button>
                <a href="/dashboard.php" class="btn btn-secondary btn-lg">
                    <i class="fas fa-times"></i> Отмена
                </a>
                <button type="reset" class="btn btn-secondary btn-lg">
                    <i class="fas fa-undo"></i> Очистить форму
                </button>
            </div>
            
            <div class="form-warning">
                <i class="fas fa-shield-alt"></i>
                <span>Подача заведомо ложного иска или фальсификация доказательств влечёт ответственность в соответствии с правилами сервера.</span>
            </div>
        </form>
    </div>
</div>

<script>
    // Отображение выбранных файлов
    const fileInput = document.getElementById('evidence');
    const fileList = document.getElementById('fileList');
    
    fileInput.addEventListener('change', function() {
        fileList.innerHTML = '';
        const files = Array.from(this.files);
        
        if (files.length === 0) return;
        
        const list = document.createElement('div');
        list.className = 'selected-files';
        
        let hasOversized = false;
        const maxSize = 10 * 1024 * 1024; // 10 MB
        
        files.forEach(file => {
            const sizeMB = (file.size / (1024 * 1024)).toFixed(2);
            
            if (file.size > maxSize) {
                hasOversized = true;
                list.innerHTML += `<li class="warning">⚠️ ${file.name} (${sizeMB} МБ) — ПРЕВЫШЕН ЛИМИТ 10 МБ</li>`;
            } else {
                list.innerHTML += `<li>📎 ${file.name} (${sizeMB} МБ)</li>`;
            }
        });
        
        if (hasOversized) {
            list.innerHTML = '<div class="alert alert-warning"><strong>Внимание!</strong> Некоторые файлы превышают 10 МБ. Они не будут загружены.</div>' + list.innerHTML;
        }
        
        fileList.appendChild(list);
    });
    
    // Проверка существования ответчика
    const defendantSearch = document.getElementById('defendant_search');
    const defendantNotFound = document.getElementById('defendantNotFound');
    const defendantFound = document.getElementById('defendantFound');
    let defendantExists = false;
    
    defendantSearch.addEventListener('input', function() {
        const searchTerm = this.value.trim();
        if (searchTerm.length < 2) {
            defendantNotFound.style.display = 'none';
            defendantFound.style.display = 'none';
            return;
        }
        
        fetch(`/ajax_check_user.php?nick=${encodeURIComponent(searchTerm)}`)
            .then(response => response.json())
            .then(data => {
                if (data.exists) {
                    defendantNotFound.style.display = 'none';
                    defendantFound.style.display = 'flex';
                    defendantExists = true;
                } else {
                    defendantNotFound.style.display = 'flex';
                    defendantFound.style.display = 'none';
                    defendantExists = false;
                }
            })
            .catch(error => {
                console.error('Ошибка:', error);
            });
    });
    
    // Подтверждение отправки
    document.getElementById('fileCaseForm').addEventListener('submit', function(e) {
        const files = fileInput.files;
        let hasOversized = false;
        const maxSize = 10 * 1024 * 1024;
        
        for (let i = 0; i < files.length; i++) {
            if (files[i].size > maxSize) {
                hasOversized = true;
                break;
            }
        }
        
        if (hasOversized) {
            e.preventDefault();
            alert('Некоторые файлы превышают 10 МБ. Пожалуйста, удалите или сожмите их.');
        }
    });
</script>

<style>
    .info-message {
        border-radius: var(--radius);
        padding: 0.75rem;
        margin-top: 0.75rem;
        font-size: 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .info-message.info {
        background: #e8f0fe;
        color: var(--primary);
    }
    
    .info-message.warning {
        background: #fff3cd;
        color: #856404;
        border: 1px solid #ffeeba;
    }
    
    .info-message.success {
        background: #d1e7dd;
        color: #0a3622;
        border: 1px solid #a3cfbb;
    }
    
    .form-warning {
        background: #fff3cd;
        border-radius: var(--radius);
        padding: 0.75rem;
        margin-top: 1rem;
        font-size: 0.75rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: #856404;
        border: 1px solid #ffeeba;
    }
    
    .selected-files {
        background: var(--gray-50);
        border-radius: var(--radius);
        padding: 0.75rem;
        margin-top: 0.5rem;
        font-size: 0.875rem;
    }
    
    .selected-files ul {
        margin: 0.5rem 0 0 1.25rem;
    }
    
    .selected-files li {
        margin: 0.25rem 0;
        color: var(--gray-700);
    }
    
    .selected-files li.warning {
        color: var(--danger);
        font-weight: 500;
    }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>