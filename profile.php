<?php
// profile.php
// Профиль пользователя (личный и публичный просмотр)

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Проверка авторизации
if (!isLoggedIn()) {
    redirect('/index.php');
}

$current_user = getCurrentUser();
$view_user_id = isset($_GET['id']) ? intval($_GET['id']) : $current_user['id'];

// Проверка прав на просмотр
$is_own_profile = ($view_user_id == $current_user['id']);
$is_admin = ($current_user['role'] === 'chairman');

if (!$is_own_profile && !$is_admin) {
    setFlashMessage('error', 'У вас нет доступа к просмотру этого профиля');
    redirect('/profile.php');
}

// Получаем данные просматриваемого пользователя
$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$view_user_id]);
$view_user = $stmt->fetch();

if (!$view_user) {
    setFlashMessage('error', 'Пользователь не найден');
    redirect('/dashboard.php');
}

// Проверяем, является ли текущий пользователь админом для редактирования
$can_edit = ($is_own_profile || $is_admin);

$page_title = $is_own_profile ? 'Мой профиль' : 'Профиль: ' . h($view_user['in_game_name']);
$errors = [];
$success = [];

// Обработка обновления профиля (только для владельца или админа)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Неверный CSRF-токен. Попробуйте снова.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'update_profile':
                $in_game_name = trim($_POST['in_game_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $bio = trim($_POST['bio'] ?? '');
                $position = trim($_POST['position'] ?? '');
                $discord = trim($_POST['discord'] ?? '');
                $vk = trim($_POST['vk'] ?? '');
                
                if (empty($in_game_name)) {
                    $errors[] = 'Игровой ник не может быть пустым';
                }
                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Введите корректный email';
                }
                
                // Для админа: можно менять роль и статус
                if ($is_admin && $view_user_id != $current_user['id']) {
                    $role = $_POST['role'] ?? $view_user['role'];
                    $is_active = isset($_POST['is_active']) ? 1 : 0;
                } else {
                    $role = $view_user['role'];
                    $is_active = $view_user['is_active'];
                }
                
                if (empty($errors)) {
                    try {
                        $stmt = $pdo->prepare("
                            UPDATE users 
                            SET in_game_name = ?, email = ?, bio = ?, position = ?, discord = ?, vk = ?, role = ?, is_active = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$in_game_name, $email, $bio, $position, $discord, $vk, $role, $is_active, $view_user_id]);
                        
                        auditLog($current_user['id'], 'profile_update', null, null, "updated user_id: $view_user_id");
                        $success[] = 'Профиль успешно обновлён';
                        
                        // Обновляем данные в переменной
                        $view_user['in_game_name'] = $in_game_name;
                        $view_user['email'] = $email;
                        $view_user['bio'] = $bio;
                        $view_user['position'] = $position;
                        $view_user['discord'] = $discord;
                        $view_user['vk'] = $vk;
                        $view_user['role'] = $role;
                        $view_user['is_active'] = $is_active;
                        
                        if ($is_own_profile) {
                            $_SESSION['username'] = $view_user['username'];
                        }
                    } catch (PDOException $e) {
                        $errors[] = 'Ошибка при обновлении профиля: ' . $e->getMessage();
                    }
                }
                break;
                
            case 'upload_avatar':
                if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . '/uploads/avatars/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    
                    $file = $_FILES['avatar'];
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    
                    if (!in_array($ext, $allowed_ext)) {
                        $errors[] = 'Недопустимый формат файла. Разрешены: JPG, PNG, GIF, WEBP';
                    } elseif ($file['size'] > 2 * 1024 * 1024) {
                        $errors[] = 'Файл не должен превышать 2 МБ';
                    } else {
                        $avatar_name = 'user_' . $view_user_id . '_' . time() . '.' . $ext;
                        $target_path = $upload_dir . $avatar_name;
                        
                        if (move_uploaded_file($file['tmp_name'], $target_path)) {
                            if ($view_user['avatar'] && file_exists(__DIR__ . '/' . $view_user['avatar'])) {
                                unlink(__DIR__ . '/' . $view_user['avatar']);
                            }
                            
                            $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                            $stmt->execute(['uploads/avatars/' . $avatar_name, $view_user_id]);
                            
                            auditLog($current_user['id'], 'upload_avatar', null, null, "user_id: $view_user_id");
                            $success[] = 'Аватар успешно загружен';
                            $view_user['avatar'] = 'uploads/avatars/' . $avatar_name;
                        } else {
                            $errors[] = 'Ошибка при загрузке файла';
                        }
                    }
                } else {
                    $errors[] = 'Выберите файл для загрузки';
                }
                break;
                
            case 'delete_avatar':
                if ($view_user['avatar'] && file_exists(__DIR__ . '/' . $view_user['avatar'])) {
                    unlink(__DIR__ . '/' . $view_user['avatar']);
                    $stmt = $pdo->prepare("UPDATE users SET avatar = NULL WHERE id = ?");
                    $stmt->execute([$view_user_id]);
                    $view_user['avatar'] = null;
                    $success[] = 'Аватар удалён';
                }
                break;
                
            case 'change_password':
                if (!$is_own_profile) {
                    $errors[] = 'Вы не можете сменить пароль другого пользователя';
                    break;
                }
                
                $current_password = $_POST['current_password'] ?? '';
                $new_password = $_POST['new_password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';
                
                if (empty($current_password)) {
                    $errors[] = 'Введите текущий пароль';
                } elseif (strlen($new_password) < 6) {
                    $errors[] = 'Новый пароль должен быть не менее 6 символов';
                } elseif ($new_password !== $confirm_password) {
                    $errors[] = 'Пароли не совпадают';
                } else {
                    $result = changePassword($view_user_id, $current_password, $new_password);
                    if ($result['success']) {
                        $success[] = $result['message'];
                    } else {
                        $errors[] = $result['message'];
                    }
                }
                break;
        }
    }
}

// Получение статистики пользователя
$stmt = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN plaintiff_id = ? THEN 1 END) as as_plaintiff,
        COUNT(CASE WHEN defendant_id = ? THEN 1 END) as as_defendant,
        COUNT(CASE WHEN judge_id = ? THEN 1 END) as as_judge
    FROM court_cases
");
$stmt->execute([$view_user_id, $view_user_id, $view_user_id]);
$case_stats = $stmt->fetch();

// Дела, где пользователь - истец (последние 5)
$stmt = $pdo->prepare("
    SELECT case_number_full, case_type_name, status, created_at, uid, case_type_code
    FROM court_cases 
    WHERE plaintiff_id = ?
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->execute([$view_user_id]);
$plaintiff_cases = $stmt->fetchAll();

// Дела, где пользователь - ответчик (последние 5)
$stmt = $pdo->prepare("
    SELECT case_number_full, case_type_name, status, created_at, uid, case_type_code
    FROM court_cases 
    WHERE defendant_id = ?
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->execute([$view_user_id]);
$defendant_cases = $stmt->fetchAll();

// Дела, где пользователь - судья (последние 5)
$stmt = $pdo->prepare("
    SELECT case_number_full, case_type_name, status, created_at, uid, case_type_code
    FROM court_cases 
    WHERE judge_id = ?
    ORDER BY created_at DESC
    LIMIT 5
");
$stmt->execute([$view_user_id]);
$judge_cases = $stmt->fetchAll();

// Реестр обременений (если есть, только для владельца или админа)
$obligations = [];
if ($is_own_profile || $is_admin) {
    $stmt = $pdo->prepare("
        SELECT pr.*, c.case_number_full
        FROM public_registry pr
        JOIN court_cases c ON pr.case_uid = c.uid
        WHERE pr.citizen_id = ? AND pr.declared_status = 'pending'
    ");
    $stmt->execute([$view_user_id]);
    $obligations = $stmt->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<div class="profile-page">
    <div class="page-header">
        <h1>
            <i class="fas fa-user-circle"></i> 
            <?php echo $is_own_profile ? 'Мой профиль' : 'Профиль: ' . h($view_user['in_game_name']); ?>
        </h1>
        <?php if (!$is_own_profile && $is_admin): ?>
            <a href="/admin/?tab=users" class="btn-sm btn-secondary">
                <i class="fas fa-arrow-left"></i> Назад к списку
            </a>
        <?php endif; ?>
    </div>
    
    <!-- Сообщения -->
    <?php if (!empty($success)): ?>
        <?php foreach ($success as $msg): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo h($msg); ?></span>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-triangle"></i>
            <ul style="margin: 0 0 0 1.5rem;">
                <?php foreach ($errors as $err): ?>
                    <li><?php echo h($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <!-- Предупреждение об обременениях (только для владельца или админа) -->
    <?php if (!empty($obligations) && ($is_own_profile || $is_admin)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-circle"></i>
            <div>
                <strong>Внимание! У пользователя есть неисполненные обязательства по решению суда:</strong>
                <ul style="margin: 0.5rem 0 0 1.5rem;">
                    <?php foreach ($obligations as $obl): ?>
                        <li>Дело № <?php echo h($obl['case_number_full']); ?> — <?php echo h($obl['obligation_text']); ?> 
                            (срок до <?php echo date('d.m.Y', strtotime($obl['due_date'])); ?>)
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="profile-grid">
        <!-- Аватар и основная информация -->
        <div class="profile-card avatar-card">
            <div class="avatar-container">
                <?php if ($view_user['avatar'] && file_exists(__DIR__ . '/' . $view_user['avatar'])): ?>
                    <img src="<?php echo SITE_URL . '/' . $view_user['avatar']; ?>" alt="Аватар" class="avatar-img">
                <?php else: ?>
                    <div class="avatar-placeholder">
                        <i class="fas fa-user-circle"></i>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($can_edit): ?>
            <div class="avatar-actions">
                <form method="POST" enctype="multipart/form-data" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="upload_avatar">
                    <label class="btn-sm btn-secondary file-label">
                        <i class="fas fa-upload"></i> Загрузить
                        <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" style="display: none;" onchange="this.form.submit()">
                    </label>
                </form>
                
                <?php if ($view_user['avatar']): ?>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить аватар?');">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="action" value="delete_avatar">
                        <button type="submit" class="btn-sm btn-danger">
                            <i class="fas fa-trash-alt"></i> Удалить
                        </button>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <div class="profile-role">
                <span class="role-badge role-<?php echo $view_user['role']; ?>">
                    <i class="fas <?php 
                        echo $view_user['role'] == 'judge' ? 'fa-gavel' : 
                            ($view_user['role'] == 'prosecutor' ? 'fa-balance-scale' : 
                            ($view_user['role'] == 'lawyer' ? 'fa-user-tie' : 
                            ($view_user['role'] == 'chairman' ? 'fa-crown' : 'fa-user'))); 
                    ?>"></i>
                    <?php 
                        echo $view_user['role'] == 'judge' ? 'Судья' : 
                            ($view_user['role'] == 'prosecutor' ? 'Прокурор' : 
                            ($view_user['role'] == 'lawyer' ? 'Адвокат' : 
                            ($view_user['role'] == 'chairman' ? 'Председатель суда' : 'Гражданин'))); 
                    ?>
                </span>
                <?php if (!$view_user['is_active']): ?>
                    <span class="status-badge status-inactive" style="margin-left: 0.5rem;">Заблокирован</span>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Личная информация -->
        <div class="profile-card">
            <h3><i class="fas fa-id-card"></i> Личная информация</h3>
            <div class="profile-info">
                <div class="info-row">
                    <span class="info-label">Логин:</span>
                    <span class="info-value"><?php echo h($view_user['username']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Игровой ник:</span>
                    <span class="info-value"><?php echo h($view_user['in_game_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Должность/звание:</span>
                    <span class="info-value">
                        <?php echo h($view_user['position'] ?? '—'); ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">О себе:</span>
                    <span class="info-value">
                        <?php echo nl2br(h($view_user['bio'] ?? '—')); ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Discord:</span>
                    <span class="info-value">
                        <?php echo $view_user['discord'] ? '<i class="fab fa-discord"></i> ' . h($view_user['discord']) : '—'; ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">ВКонтакте:</span>
                    <span class="info-value">
                        <?php if ($view_user['vk']): ?>
                            <i class="fab fa-vk"></i> <a href="<?php echo h($view_user['vk']); ?>" target="_blank" rel="noopener noreferrer"><?php echo h($view_user['vk']); ?></a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Дата регистрации:</span>
                    <span class="info-value"><?php echo date('d.m.Y', strtotime($view_user['created_at'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Последний вход:</span>
                    <span class="info-value">
                        <?php echo $view_user['last_login'] ? date('d.m.Y H:i', strtotime($view_user['last_login'])) : '—'; ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Форма редактирования профиля (только для владельца или админа) -->
        <?php if ($can_edit): ?>
        <div class="profile-card">
            <h3><i class="fas fa-edit"></i> Редактировать профиль</h3>
            <form method="POST" action="" class="profile-form">
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="form-group">
                    <label for="in_game_name">Игровой ник</label>
                    <input type="text" id="in_game_name" name="in_game_name" class="form-control" 
                           value="<?php echo h($view_user['in_game_name']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control" 
                           value="<?php echo h($view_user['email']); ?>" required>
                    <small>На этот адрес будут приходить уведомления о статусе дел</small>
                </div>
                
                <div class="form-group">
                    <label for="position">Должность / Звание</label>
                    <input type="text" id="position" name="position" class="form-control" 
                           value="<?php echo h($view_user['position'] ?? ''); ?>" 
                           placeholder="Например: Мировой судья, Старший прокурор, Адвокат">
                </div>
                
                <div class="form-group">
                    <label for="bio">О себе</label>
                    <textarea id="bio" name="bio" class="form-control" rows="4" 
                              placeholder="Расскажите о себе..."><?php echo h($view_user['bio'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="discord">Discord</label>
                        <input type="text" id="discord" name="discord" class="form-control" 
                               value="<?php echo h($view_user['discord'] ?? ''); ?>" placeholder="username#0000">
                    </div>
                    
                    <div class="form-group">
                        <label for="vk">ВКонтакте (ссылка)</label>
                        <input type="url" id="vk" name="vk" class="form-control" 
                               value="<?php echo h($view_user['vk'] ?? ''); ?>" placeholder="https://vk.com/id123456789">
                    </div>
                </div>
                
                <?php if ($is_admin && $view_user_id != $current_user['id']): ?>
                <div class="form-row">
                    <div class="form-group">
                        <label for="role">Роль</label>
                        <select id="role" name="role" class="form-control">
                            <option value="citizen" <?php echo $view_user['role'] == 'citizen' ? 'selected' : ''; ?>>Гражданин</option>
                            <option value="lawyer" <?php echo $view_user['role'] == 'lawyer' ? 'selected' : ''; ?>>Адвокат</option>
                            <option value="prosecutor" <?php echo $view_user['role'] == 'prosecutor' ? 'selected' : ''; ?>>Прокурор</option>
                            <option value="judge" <?php echo $view_user['role'] == 'judge' ? 'selected' : ''; ?>>Судья</option>
                            <option value="chairman" <?php echo $view_user['role'] == 'chairman' ? 'selected' : ''; ?>>Председатель</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="is_active">Статус</label>
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem;">
                            <input type="checkbox" id="is_active" name="is_active" value="1" <?php echo $view_user['is_active'] ? 'checked' : ''; ?>>
                            <label for="is_active" style="margin: 0;">Активен</label>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="username">Логин (изменение невозможно)</label>
                    <input type="text" id="username" class="form-control" 
                           value="<?php echo h($view_user['username']); ?>" disabled>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Сохранить изменения
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- Смена пароля (только для владельца) -->
        <?php if ($is_own_profile): ?>
        <div class="profile-card">
            <h3><i class="fas fa-lock"></i> Сменить пароль</h3>
            <form method="POST" action="" class="profile-form">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="form-group">
                    <label for="current_password">Текущий пароль</label>
                    <input type="password" id="current_password" name="current_password" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="new_password">Новый пароль</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required>
                    <small>Минимум 6 символов</small>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Подтверждение пароля</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                </div>
                
                <button type="submit" class="btn btn-secondary">
                    <i class="fas fa-key"></i> Сменить пароль
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- Статистика -->
        <div class="profile-card">
            <h3><i class="fas fa-chart-pie"></i> Статистика участия</h3>
            <div class="stats-mini">
                <div class="stat-mini-item">
                    <div class="stat-mini-number"><?php echo $case_stats['as_plaintiff']; ?></div>
                    <div class="stat-mini-label">Истец</div>
                </div>
                <div class="stat-mini-item">
                    <div class="stat-mini-number"><?php echo $case_stats['as_defendant']; ?></div>
                    <div class="stat-mini-label">Ответчик</div>
                </div>
                <div class="stat-mini-item">
                    <div class="stat-mini-number"><?php echo $case_stats['as_judge']; ?></div>
                    <div class="stat-mini-label">Судья</div>
                </div>
            </div>
        </div>
        
        <!-- Последние дела (истец) -->
        <div class="profile-card">
            <h3><i class="fas fa-gavel"></i> Дела в роли истца (последние 5)</h3>
            <?php if (empty($plaintiff_cases)): ?>
                <div class="empty-state-small">
                    <i class="fas fa-file-alt"></i>
                    <p>Нет дел</p>
                </div>
            <?php else: ?>
                <div class="compact-list">
                    <?php foreach ($plaintiff_cases as $case): ?>
                        <div class="compact-item">
                            <div class="compact-info">
                                <a href="/case.php?uid=<?php echo urlencode($case['uid']); ?>" class="compact-number">
                                    <?php echo h($case['case_number_full']); ?>
                                </a>
                                <span class="compact-type"><?php echo h($case['case_type_name']); ?></span>
                                <span class="status-badge status-<?php echo $case['status']; ?>">
                                    <?php echo getStatusName($case['status'], $case['case_type_code'] ?? null); ?>
                                </span>
                            </div>
                            <div class="compact-date"><?php echo date('d.m.Y', strtotime($case['created_at'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Последние дела (ответчик) -->
        <div class="profile-card">
            <h3><i class="fas fa-user-shield"></i> Дела в роли ответчика (последние 5)</h3>
            <?php if (empty($defendant_cases)): ?>
                <div class="empty-state-small">
                    <i class="fas fa-check-circle"></i>
                    <p>Нет дел</p>
                </div>
            <?php else: ?>
                <div class="compact-list">
                    <?php foreach ($defendant_cases as $case): ?>
                        <div class="compact-item">
                            <div class="compact-info">
                                <a href="/case.php?uid=<?php echo urlencode($case['uid']); ?>" class="compact-number">
                                    <?php echo h($case['case_number_full']); ?>
                                </a>
                                <span class="compact-type"><?php echo h($case['case_type_name']); ?></span>
                                <span class="status-badge status-<?php echo $case['status']; ?>">
                                    <?php echo getStatusName($case['status'], $case['case_type_code'] ?? null); ?>
                                </span>
                            </div>
                            <div class="compact-date"><?php echo date('d.m.Y', strtotime($case['created_at'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Дела как судья -->
        <?php if (in_array($view_user['role'], ['judge', 'chairman']) || $is_admin): ?>
        <div class="profile-card">
            <h3><i class="fas fa-gavel"></i> Дела в роли судьи (последние 5)</h3>
            <?php if (empty($judge_cases)): ?>
                <div class="empty-state-small">
                    <i class="fas fa-folder-open"></i>
                    <p>Нет дел</p>
                </div>
            <?php else: ?>
                <div class="compact-list">
                    <?php foreach ($judge_cases as $case): ?>
                        <div class="compact-item">
                            <div class="compact-info">
                                <a href="/case.php?uid=<?php echo urlencode($case['uid']); ?>" class="compact-number">
                                    <?php echo h($case['case_number_full']); ?>
                                </a>
                                <span class="compact-type"><?php echo h($case['case_type_name']); ?></span>
                                <span class="status-badge status-<?php echo $case['status']; ?>">
                                    <?php echo getStatusName($case['status'], $case['case_type_code'] ?? null); ?>
                                </span>
                            </div>
                            <div class="compact-date"><?php echo date('d.m.Y', strtotime($case['created_at'])); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* Profile Page Styles */
.profile-page {
    max-width: 1200px;
    margin: 0 auto;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    gap: 1rem;
}

.page-header h1 {
    margin: 0;
    font-size: 1.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.profile-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.profile-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    padding: 1.5rem;
}

.profile-card h3 {
    font-size: 1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* Аватар */
.avatar-card {
    text-align: center;
}

.avatar-container {
    margin-bottom: 1rem;
}

.avatar-img {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid var(--primary);
}

.avatar-placeholder {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: var(--gray-100);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
}

.avatar-placeholder i {
    font-size: 4rem;
    color: var(--gray-400);
}

.avatar-actions {
    display: flex;
    gap: 0.5rem;
    justify-content: center;
    margin-bottom: 1rem;
}

.file-label {
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.profile-role {
    margin-top: 0.5rem;
}

/* Информация */
.profile-info {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.profile-info .info-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--gray-100);
}

.profile-info .info-label {
    font-weight: 500;
    color: var(--gray-600);
    font-size: 0.875rem;
    min-width: 120px;
}

.profile-info .info-value {
    font-size: 0.875rem;
    text-align: right;
    flex: 1;
    word-break: break-word;
}

.profile-info .info-value a {
    color: var(--primary);
    text-decoration: none;
}

.profile-info .info-value a:hover {
    text-decoration: underline;
}

/* Статистика */
.stats-mini {
    display: flex;
    gap: 1rem;
    justify-content: space-around;
}

.stat-mini-item {
    text-align: center;
    flex: 1;
    padding: 0.75rem;
    background: var(--gray-50);
    border-radius: var(--radius);
}

.stat-mini-number {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--primary);
}

.stat-mini-label {
    font-size: 0.7rem;
    color: var(--gray-600);
    margin-top: 0.25rem;
}

/* Формы */
.profile-form {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

/* Компактные списки */
.compact-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.compact-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--gray-100);
}

.compact-info {
    flex: 1;
}

.compact-number {
    font-weight: 600;
    color: var(--primary);
    text-decoration: none;
    font-family: monospace;
    font-size: 0.875rem;
}

.compact-number:hover {
    text-decoration: underline;
}

.compact-type {
    font-size: 0.7rem;
    color: var(--gray-500);
    margin-left: 0.5rem;
}

.compact-date {
    font-size: 0.7rem;
    color: var(--gray-500);
    white-space: nowrap;
}

.empty-state-small {
    text-align: center;
    padding: 1.5rem;
    color: var(--gray-500);
}

.empty-state-small i {
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
    opacity: 0.5;
}

/* Responsive */
@media (max-width: 768px) {
    .profile-grid {
        grid-template-columns: 1fr;
    }
    
    .compact-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.25rem;
    }
    
    .compact-date {
        font-size: 0.65rem;
    }
    
    .form-row {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .profile-info .info-row {
        flex-direction: column;
        gap: 0.25rem;
    }
    
    .profile-info .info-value {
        text-align: left;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>