<?php
// admin/index.php
// Расширенная админ-панель для председателя суда

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Проверка авторизации и прав
if (!isLoggedIn()) {
    redirect('/index.php');
}

$user = getCurrentUser();
if ($user['role'] !== 'chairman') {
    setFlashMessage('error', 'Доступ запрещён. Только для председателя суда.');
    redirect('/dashboard.php');
}

$page_title = 'Админ-панель';
$pdo = getDB();
$active_tab = $_GET['tab'] ?? 'dashboard';

// Обработка действий
$action_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $action_result = ['success' => false, 'message' => 'Неверный CSRF-токен'];
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'change_user_role':
                $user_id = intval($_POST['user_id'] ?? 0);
                $new_role = $_POST['role'] ?? '';
                $valid_roles = ['citizen', 'lawyer', 'prosecutor', 'judge', 'chairman'];
                
                if ($user_id === $user['id']) {
                    $action_result = ['success' => false, 'message' => 'Нельзя изменить свою собственную роль'];
                } elseif (in_array($new_role, $valid_roles)) {
                    $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                    $stmt->execute([$new_role, $user_id]);
                    auditLog($user['id'], 'change_user_role', null, null, "user_id: $user_id, new_role: $new_role");
                    $action_result = ['success' => true, 'message' => 'Роль пользователя изменена'];
                } else {
                    $action_result = ['success' => false, 'message' => 'Неверная роль'];
                }
                break;
                
            case 'toggle_user_status':
                $user_id = intval($_POST['user_id'] ?? 0);
                if ($user_id === $user['id']) {
                    $action_result = ['success' => false, 'message' => 'Нельзя заблокировать самого себя'];
                } else {
                    $stmt = $pdo->prepare("SELECT is_active FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $current = $stmt->fetch();
                    if ($current) {
                        $new_status = $current['is_active'] ? 0 : 1;
                        $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                        $stmt->execute([$new_status, $user_id]);
                        auditLog($user['id'], 'toggle_user_status', null, null, "user_id: $user_id, new_status: $new_status");
                        $action_result = ['success' => true, 'message' => $new_status ? 'Пользователь активирован' : 'Пользователь заблокирован'];
                    } else {
                        $action_result = ['success' => false, 'message' => 'Пользователь не найден'];
                    }
                }
                break;
                
            case 'reset_password':
                $user_id = intval($_POST['user_id'] ?? 0);
                if ($user_id === $user['id']) {
                    $action_result = ['success' => false, 'message' => 'Используйте профиль для смены своего пароля'];
                } else {
                    $new_password = bin2hex(random_bytes(4));
                    $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $stmt->execute([$new_hash, $user_id]);
                    auditLog($user['id'], 'reset_password', null, null, "user_id: $user_id");
                    
                    $stmt = $pdo->prepare("SELECT email, in_game_name FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $target_user = $stmt->fetch();
                    
                    $action_result = ['success' => true, 'message' => "Пароль сброшен. Новый пароль: $new_password (сообщите пользователю)"];
                }
                break;
                
            case 'reassign_case':
                $case_uid = $_POST['case_uid'] ?? '';
                $new_judge_id = intval($_POST['judge_id'] ?? 0);
                
                if ($new_judge_id) {
                    $stmt = $pdo->prepare("UPDATE court_cases SET judge_id = ? WHERE uid = ?");
                    $stmt->execute([$new_judge_id, $case_uid]);
                    auditLog($user['id'], 'reassign_case', $case_uid, null, "new_judge_id: $new_judge_id");
                    $action_result = ['success' => true, 'message' => 'Дело переназначено'];
                } else {
                    $action_result = ['success' => false, 'message' => 'Выберите судью'];
                }
                break;
                
            case 'mass_case_action':
                $mass_action = $_POST['mass_action_type'] ?? '';
                $case_uids = $_POST['case_uids'] ?? [];
                
                if (!is_array($case_uids) || empty($case_uids)) {
                    $action_result = ['success' => false, 'message' => 'Не выбрано ни одного дела'];
                    break;
                }
                
                $case_uids = array_map('trim', $case_uids);
                $case_uids = array_filter($case_uids);
                
                if (empty($case_uids)) {
                    $action_result = ['success' => false, 'message' => 'Не выбрано ни одного дела'];
                    break;
                }
                
                $placeholders = implode(',', array_fill(0, count($case_uids), '?'));
                $success_count = 0;
                
                try {
                    if ($mass_action === 'change_status') {
                        $new_status = $_POST['new_status'] ?? '';
                        if ($new_status) {
                            $stmt = $pdo->prepare("UPDATE court_cases SET status = ? WHERE uid IN ($placeholders)");
                            $stmt->execute(array_merge([$new_status], $case_uids));
                            $success_count = $stmt->rowCount();
                            auditLog($user['id'], 'mass_status_change', null, null, "status: $new_status, cases: " . implode(',', $case_uids));
                            $action_result = ['success' => true, 'message' => "Статус изменён для $success_count дел"];
                        } else {
                            $action_result = ['success' => false, 'message' => 'Не выбран новый статус'];
                        }
                    } elseif ($mass_action === 'assign_judge') {
                        $new_judge_id = intval($_POST['new_judge_id'] ?? 0);
                        if ($new_judge_id) {
                            $stmt = $pdo->prepare("UPDATE court_cases SET judge_id = ? WHERE uid IN ($placeholders)");
                            $stmt->execute(array_merge([$new_judge_id], $case_uids));
                            $success_count = $stmt->rowCount();
                            auditLog($user['id'], 'mass_assign_judge', null, null, "judge_id: $new_judge_id, cases: " . implode(',', $case_uids));
                            $action_result = ['success' => true, 'message' => "Судья назначен для $success_count дел"];
                        } else {
                            $action_result = ['success' => false, 'message' => 'Не выбран судья'];
                        }
                    } elseif ($mass_action === 'delete_cases') {
                        $stmt = $pdo->prepare("DELETE FROM court_cases WHERE uid IN ($placeholders)");
                        $stmt->execute($case_uids);
                        $success_count = $stmt->rowCount();
                        auditLog($user['id'], 'mass_delete_cases', null, null, "cases: " . implode(',', $case_uids));
                        $action_result = ['success' => true, 'message' => "Удалено $success_count дел"];
                    } else {
                        $action_result = ['success' => false, 'message' => 'Неизвестное действие'];
                    }
                } catch (PDOException $e) {
                    $action_result = ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
                }
                break;
                
            case 'delete_user':
                $user_id = intval($_POST['user_id'] ?? 0);
                if ($user_id === $user['id']) {
                    $action_result = ['success' => false, 'message' => 'Нельзя удалить самого себя'];
                } else {
                    $stmt = $pdo->prepare("SELECT in_game_name FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $target = $stmt->fetch();
                    
                    if ($target) {
                        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                        $stmt->execute([$user_id]);
                        auditLog($user['id'], 'delete_user', null, null, "user_id: $user_id, name: {$target['in_game_name']}");
                        $action_result = ['success' => true, 'message' => "Пользователь {$target['in_game_name']} удалён"];
                    } else {
                        $action_result = ['success' => false, 'message' => 'Пользователь не найден'];
                    }
                }
                break;
                
            case 'delete_case':
                $case_uid = $_POST['case_uid'] ?? '';
                $stmt = $pdo->prepare("SELECT case_number_full FROM court_cases WHERE uid = ?");
                $stmt->execute([$case_uid]);
                $case = $stmt->fetch();
                
                if ($case) {
                    $stmt = $pdo->prepare("DELETE FROM court_cases WHERE uid = ?");
                    $stmt->execute([$case_uid]);
                    auditLog($user['id'], 'delete_case', $case_uid, null, "case_number: {$case['case_number_full']}");
                    $action_result = ['success' => true, 'message' => "Дело {$case['case_number_full']} удалено"];
                } else {
                    $action_result = ['success' => false, 'message' => 'Дело не найдено'];
                }
                break;
        }
    }
}

// Статистика
$stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
$total_users = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'judge'");
$total_judges = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE role = 'prosecutor'");
$total_prosecutors = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM court_cases");
$total_cases = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM court_cases WHERE status IN ('accepted', 'preparing', 'trial')");
$active_cases = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM court_cases WHERE status IN ('verdict', 'appealed')");
$completed_cases = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM court_cases WHERE judge_id IS NULL AND status != 'archived'");
$cases_without_judge = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM appeals WHERE status IN ('pending', 'under_review')");
$pending_appeals_count = $stmt->fetch()['total'];

// Статистика по типам дел
$stmt = $pdo->query("
    SELECT case_type_code, case_type_name, COUNT(*) as cnt 
    FROM court_cases 
    GROUP BY case_type_code, case_type_name
    ORDER BY cnt DESC
");
$case_type_stats = $stmt->fetchAll();

// Статистика по месяцам (текущий год)
$stmt = $pdo->query("
    SELECT 
        MONTH(created_at) as month,
        COUNT(*) as cnt
    FROM court_cases
    WHERE YEAR(created_at) = YEAR(CURDATE())
    GROUP BY MONTH(created_at)
    ORDER BY month
");
$monthly_stats = $stmt->fetchAll();

// Последние действия из аудит-лога
$stmt = $pdo->query("
    SELECT al.*, u.in_game_name as user_name
    FROM audit_log al
    LEFT JOIN users u ON al.user_id = u.id
    ORDER BY al.created_at DESC
    LIMIT 50
");
$audit_logs = $stmt->fetchAll();

// Список судей для переназначения
$stmt = $pdo->query("SELECT id, in_game_name FROM users WHERE role IN ('judge', 'chairman') AND is_active = 1 ORDER BY in_game_name");
$judges_list = $stmt->fetchAll();

// Апелляции на рассмотрении
$stmt = $pdo->query("
    SELECT a.*, 
           c.case_number_full as original_case_number,
           ac.case_number_full as appeal_case_number,
           u.in_game_name as appellant_name
    FROM appeals a
    JOIN court_cases c ON a.case_uid = c.uid
    JOIN court_cases ac ON a.appeal_case_uid = ac.uid
    JOIN users u ON a.appellant_id = u.id
    WHERE a.status IN ('pending', 'under_review')
    ORDER BY a.created_at ASC
");
$pending_appeals = $stmt->fetchAll();

// Дела без судьи
$stmt = $pdo->query("
    SELECT c.uid, c.case_number_full, c.case_type_name, c.title, c.created_at,
           u.in_game_name as plaintiff_name
    FROM court_cases c
    LEFT JOIN users u ON c.plaintiff_id = u.id
    WHERE c.judge_id IS NULL AND c.status != 'archived'
    ORDER BY c.created_at DESC
    LIMIT 20
");
$cases_without_judge_list = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="admin-page">
    <div class="page-header">
        <h1><i class="fas fa-shield-alt"></i> Админ-панель</h1>
        <p>Управление системой ГАС «Юстиция»</p>
    </div>
    
    <?php if ($action_result): ?>
        <div class="alert alert-<?php echo $action_result['success'] ? 'success' : 'error'; ?>">
            <i class="fas <?php echo $action_result['success'] ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
            <span><?php echo h($action_result['message']); ?></span>
        </div>
    <?php endif; ?>
    
    <!-- Вкладки -->
    <div class="admin-tabs">
        <a href="?tab=dashboard" class="admin-tab <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i> Дашборд
        </a>
        <a href="?tab=users" class="admin-tab <?php echo $active_tab === 'users' ? 'active' : ''; ?>">
            <i class="fas fa-users"></i> Пользователи
        </a>
        <a href="?tab=cases" class="admin-tab <?php echo $active_tab === 'cases' ? 'active' : ''; ?>">
            <i class="fas fa-folder-open"></i> Дела
        </a>
        <a href="?tab=appeals" class="admin-tab <?php echo $active_tab === 'appeals' ? 'active' : ''; ?>">
            <i class="fas fa-gavel"></i> Апелляции
            <?php if ($pending_appeals_count > 0): ?>
                <span class="tab-badge"><?php echo $pending_appeals_count; ?></span>
            <?php endif; ?>
        </a>
        <a href="?tab=audit" class="admin-tab <?php echo $active_tab === 'audit' ? 'active' : ''; ?>">
            <i class="fas fa-history"></i> Логи аудита
        </a>
        <a href="?tab=settings" class="admin-tab <?php echo $active_tab === 'settings' ? 'active' : ''; ?>">
            <i class="fas fa-cog"></i> Настройки
        </a>
    </div>
    
    <!-- Дашборд -->
    <?php if ($active_tab === 'dashboard'): ?>
    <div class="tab-content">
        <div class="stats-grid-admin">
            <div class="stat-card-admin"><div class="stat-icon"><i class="fas fa-users"></i></div><div class="stat-info"><div class="stat-value"><?php echo $total_users; ?></div><div class="stat-label">Пользователей</div></div></div>
            <div class="stat-card-admin"><div class="stat-icon"><i class="fas fa-gavel"></i></div><div class="stat-info"><div class="stat-value"><?php echo $total_judges; ?></div><div class="stat-label">Судей</div></div></div>
            <div class="stat-card-admin"><div class="stat-icon"><i class="fas fa-balance-scale"></i></div><div class="stat-info"><div class="stat-value"><?php echo $total_prosecutors; ?></div><div class="stat-label">Прокуроров</div></div></div>
            <div class="stat-card-admin"><div class="stat-icon"><i class="fas fa-folder-open"></i></div><div class="stat-info"><div class="stat-value"><?php echo $total_cases; ?></div><div class="stat-label">Всего дел</div></div></div>
            <div class="stat-card-admin"><div class="stat-icon"><i class="fas fa-clock"></i></div><div class="stat-info"><div class="stat-value"><?php echo $active_cases; ?></div><div class="stat-label">В производстве</div></div></div>
            <div class="stat-card-admin"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div class="stat-info"><div class="stat-value"><?php echo $completed_cases; ?></div><div class="stat-label">Завершено</div></div></div>
            <div class="stat-card-admin warning"><div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-info"><div class="stat-value"><?php echo $cases_without_judge; ?></div><div class="stat-label">Без судьи</div></div></div>
            <div class="stat-card-admin appeal"><div class="stat-icon"><i class="fas fa-gavel"></i></div><div class="stat-info"><div class="stat-value"><?php echo $pending_appeals_count; ?></div><div class="stat-label">Апелляций на рассмотрении</div></div></div>
        </div>
        
        <div class="admin-card">
            <h3><i class="fas fa-chart-pie"></i> Распределение дел по типам</h3>
            <div class="type-bars">
                <?php foreach ($case_type_stats as $stat): ?>
                <div class="type-bar-item">
                    <span class="type-label"><?php echo h($stat['case_type_code']); ?></span>
                    <div class="bar-container"><div class="bar" style="width: <?php echo $total_cases > 0 ? ($stat['cnt'] / $total_cases * 100) : 0; ?>%"></div></div>
                    <span class="type-count"><?php echo $stat['cnt']; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="admin-card">
            <h3><i class="fas fa-calendar-alt"></i> Дела по месяцам (<?php echo date('Y'); ?>)</h3>
            <div class="month-bars">
                <?php
                $months = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];
                $month_data = array_fill(1, 12, 0);
                foreach ($monthly_stats as $stat) {
                    $month_data[$stat['month']] = $stat['cnt'];
                }
                $max_month = max($month_data) ?: 1;
                foreach ($months as $i => $name):
                    $month_num = $i + 1;
                    $height = ($month_data[$month_num] / $max_month) * 100;
                ?>
                <div class="month-bar-item">
                    <div class="bar" style="height: <?php echo $height; ?>%"></div>
                    <div class="label"><?php echo $name; ?></div>
                    <div class="count"><?php echo $month_data[$month_num]; ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <?php if (!empty($cases_without_judge_list)): ?>
        <div class="admin-card">
            <h3><i class="fas fa-exclamation-triangle"></i> Дела без назначенного судьи</h3>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead><tr><th>Номер дела</th><th>Тип</th><th>Название</th><th>Истец</th><th>Дата</th><th>Действие</th></tr></thead>
                    <tbody>
                        <?php foreach ($cases_without_judge_list as $case): ?>
                        <tr>
                            <td class="case-number"><?php echo h($case['case_number_full']); ?></td>
                            <td><?php echo h($case['case_type_name']); ?></td>
                            <td><?php echo h($case['title']); ?></td>
                            <td><?php echo h($case['plaintiff_name'] ?? '—'); ?></td>
                            <td><?php echo date('d.m.Y', strtotime($case['created_at'])); ?></td>
                            <td>
                                <form method="POST" style="display: inline-flex; gap: 0.5rem;">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="reassign_case">
                                    <input type="hidden" name="case_uid" value="<?php echo h($case['uid']); ?>">
                                    <select name="judge_id" class="form-control-sm" required>
                                        <option value="">Назначить судью</option>
                                        <?php foreach ($judges_list as $judge): ?>
                                            <option value="<?php echo $judge['id']; ?>"><?php echo h($judge['in_game_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn-sm btn-primary">Назначить</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Управление пользователями с поиском -->
    <?php if ($active_tab === 'users'): ?>
    <div class="tab-content">
        <div class="admin-card">
            <div class="card-header-actions">
                <h3><i class="fas fa-users-cog"></i> Управление пользователями</h3>
                <div class="header-buttons">
                    <button class="btn-sm btn-secondary" onclick="toggleUserSearch()">
                        <i class="fas fa-search"></i> Поиск
                    </button>
                    <button class="btn-sm btn-secondary" onclick="exportUsersCSV()">
                        <i class="fas fa-download"></i> Экспорт CSV
                    </button>
                </div>
            </div>
            
            <!-- Панель поиска пользователей -->
            <div id="userSearchPanel" class="filter-panel" style="display: none;">
                <form method="GET" action="" class="filter-form">
                    <input type="hidden" name="tab" value="users">
                    <div class="filter-grid">
                        <div class="form-group">
                            <label>Поиск по нику, логину или email</label>
                            <input type="text" name="user_search" class="form-control-sm" 
                                   placeholder="Введите игровой ник, логин или email..."
                                   value="<?php echo h($_GET['user_search'] ?? ''); ?>" style="width: 100%;">
                        </div>
                        <div class="form-group">
                            <label>Роль</label>
                            <select name="user_filter_role" class="form-control-sm">
                                <option value="">Все роли</option>
                                <option value="citizen" <?php echo ($_GET['user_filter_role'] ?? '') == 'citizen' ? 'selected' : ''; ?>>Гражданин</option>
                                <option value="lawyer" <?php echo ($_GET['user_filter_role'] ?? '') == 'lawyer' ? 'selected' : ''; ?>>Адвокат</option>
                                <option value="prosecutor" <?php echo ($_GET['user_filter_role'] ?? '') == 'prosecutor' ? 'selected' : ''; ?>>Прокурор</option>
                                <option value="judge" <?php echo ($_GET['user_filter_role'] ?? '') == 'judge' ? 'selected' : ''; ?>>Судья</option>
                                <option value="chairman" <?php echo ($_GET['user_filter_role'] ?? '') == 'chairman' ? 'selected' : ''; ?>>Председатель</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Статус</label>
                            <select name="user_filter_status" class="form-control-sm">
                                <option value="">Все</option>
                                <option value="active" <?php echo ($_GET['user_filter_status'] ?? '') == 'active' ? 'selected' : ''; ?>>Активен</option>
                                <option value="blocked" <?php echo ($_GET['user_filter_status'] ?? '') == 'blocked' ? 'selected' : ''; ?>>Заблокирован</option>
                            </select>
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn-sm btn-primary">Найти</button>
                        <a href="?tab=users" class="btn-sm btn-secondary">Сбросить</a>
                    </div>
                </form>
            </div>
            
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Логин</th>
                            <th>Игровой ник</th>
                            <th>Email</th>
                            <th>Роль</th>
                            <th>Статус</th>
                            <th>Регистрация</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Строим запрос с поиском и фильтрацией
                        $sql_users = "SELECT * FROM users WHERE 1=1";
                        $params_users = [];
                        
                        // Поиск по нику, логину или email
                        if (!empty($_GET['user_search'])) {
                            $sql_users .= " AND (in_game_name LIKE ? OR username LIKE ? OR email LIKE ?)";
                            $search = '%' . $_GET['user_search'] . '%';
                            $params_users[] = $search;
                            $params_users[] = $search;
                            $params_users[] = $search;
                        }
                        
                        if (!empty($_GET['user_filter_role'])) {
                            $sql_users .= " AND role = ?";
                            $params_users[] = $_GET['user_filter_role'];
                        }
                        if (!empty($_GET['user_filter_status'])) {
                            $sql_users .= " AND is_active = ?";
                            $params_users[] = $_GET['user_filter_status'] == 'active' ? 1 : 0;
                        }
                        
                        $sql_users .= " ORDER BY role, in_game_name";
                        
                        $stmt = $pdo->prepare($sql_users);
                        $stmt->execute($params_users);
                        $users_list_filtered = $stmt->fetchAll();
                        ?>
                        
                        <?php if (empty($users_list_filtered)): ?>
                            <tr>
                                <td colspan="8" class="empty-row">Пользователи не найдены</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users_list_filtered as $u): ?>
                            <tr>
                                <td><?php echo $u['id']; ?></td>
                                <td><?php echo h($u['username']); ?></td>
                                <td><strong><?php echo h($u['in_game_name']); ?></strong></td>
                                <td><?php echo h($u['email']); ?></td>
                                <td>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                        <input type="hidden" name="action" value="change_user_role">
                                        <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                        <select name="role" class="form-control-sm role-select" onchange="this.form.submit()" <?php echo $u['id'] == $user['id'] ? 'disabled' : ''; ?>>
                                            <option value="citizen" <?php echo $u['role'] == 'citizen' ? 'selected' : ''; ?>>Гражданин</option>
                                            <option value="lawyer" <?php echo $u['role'] == 'lawyer' ? 'selected' : ''; ?>>Адвокат</option>
                                            <option value="prosecutor" <?php echo $u['role'] == 'prosecutor' ? 'selected' : ''; ?>>Прокурор</option>
                                            <option value="judge" <?php echo $u['role'] == 'judge' ? 'selected' : ''; ?>>Судья</option>
                                            <option value="chairman" <?php echo $u['role'] == 'chairman' ? 'selected' : ''; ?>>Председатель</option>
                                        </select>
                                    </form>
                                </td>
                                <td><span class="status-badge <?php echo $u['is_active'] ? 'status-active' : 'status-inactive'; ?>"><?php echo $u['is_active'] ? 'Активен' : 'Заблокирован'; ?></span></td>
                                <td><?php echo date('d.m.Y', strtotime($u['created_at'])); ?></td>
                                <td class="actions-cell">
                                    <a href="/profile.php?id=<?php echo $u['id']; ?>" class="action-btn" title="Просмотр профиля">
                                        <i class="fas fa-user-circle"></i>
                                    </a>
                                    <?php if ($u['id'] != $user['id']): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="toggle_user_status">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <button type="submit" class="action-btn" title="<?php echo $u['is_active'] ? 'Заблокировать' : 'Активировать'; ?>">
                                                <i class="fas <?php echo $u['is_active'] ? 'fa-ban' : 'fa-check-circle'; ?>"></i>
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Сбросить пароль пользователю <?php echo h($u['in_game_name']); ?>?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <button type="submit" class="action-btn" title="Сбросить пароль"><i class="fas fa-key"></i></button>
                                        </form>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить пользователя <?php echo h($u['in_game_name']); ?>? Все его данные также будут удалены.');">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <button type="submit" class="action-btn delete-btn" title="Удалить пользователя"><i class="fas fa-trash-alt"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
    function toggleUserSearch() {
        const panel = document.getElementById('userSearchPanel');
        if (panel.style.display === 'none') {
            panel.style.display = 'block';
        } else {
            panel.style.display = 'none';
        }
    }
    
    function exportUsersCSV() {
        let url = '/admin/export_users.php?';
        const params = new URLSearchParams(window.location.search);
        params.forEach((value, key) => {
            if (key !== 'tab') {
                url += `${key}=${encodeURIComponent(value)}&`;
            }
        });
        window.location.href = url;
    }
    </script>
    <?php endif; ?>
    
    <!-- Список всех дел с расширенным управлением -->
    <?php if ($active_tab === 'cases'): ?>
    <div class="tab-content">
        <div class="admin-card">
            <div class="card-header-actions">
                <h3><i class="fas fa-folder-open"></i> Все дела</h3>
                <div class="header-buttons">
                    <button class="btn-sm btn-secondary" onclick="toggleFilters()">
                        <i class="fas fa-filter"></i> Фильтры
                    </button>
                    <button class="btn-sm btn-secondary" onclick="exportCasesCSV()">
                        <i class="fas fa-download"></i> Экспорт CSV
                    </button>
                </div>
            </div>
            
            <!-- Панель фильтрации -->
            <div id="filterPanel" class="filter-panel" style="display: none;">
                <form method="GET" action="" class="filter-form">
                    <input type="hidden" name="tab" value="cases">
                    <div class="filter-grid">
                        <div class="form-group">
                            <label>Тип дела</label>
                            <select name="filter_type" class="form-control-sm">
                                <option value="">Все типы</option>
                                <?php foreach (getCaseTypes() as $code => $name): ?>
                                    <option value="<?php echo $code; ?>" <?php echo ($_GET['filter_type'] ?? '') == $code ? 'selected' : ''; ?>>
                                        <?php echo h($code) . ' - ' . h($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Статус</label>
                            <select name="filter_status" class="form-control-sm">
                                <option value="">Все статусы</option>
                                <?php foreach (getCaseStatuses() as $code => $name): ?>
                                    <option value="<?php echo $code; ?>" <?php echo ($_GET['filter_status'] ?? '') == $code ? 'selected' : ''; ?>>
                                        <?php echo h($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Судья</label>
                            <select name="filter_judge" class="form-control-sm">
                                <option value="">Все судьи</option>
                                <?php foreach ($judges_list as $judge): ?>
                                    <option value="<?php echo $judge['id']; ?>" <?php echo ($_GET['filter_judge'] ?? '') == $judge['id'] ? 'selected' : ''; ?>>
                                        <?php echo h($judge['in_game_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Год</label>
                            <select name="filter_year" class="form-control-sm">
                                <option value="">Все годы</option>
                                <?php
                                $stmt = $pdo->query("SELECT DISTINCT case_year FROM court_cases ORDER BY case_year DESC");
                                $years = $stmt->fetchAll();
                                foreach ($years as $y):
                                ?>
                                    <option value="<?php echo $y['case_year']; ?>" <?php echo ($_GET['filter_year'] ?? '') == $y['case_year'] ? 'selected' : ''; ?>>
                                        <?php echo $y['case_year']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Дата от</label>
                            <input type="date" name="filter_date_from" class="form-control-sm" value="<?php echo h($_GET['filter_date_from'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Дата до</label>
                            <input type="date" name="filter_date_to" class="form-control-sm" value="<?php echo h($_GET['filter_date_to'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Поиск по номеру/УИД/участнику</label>
                            <input type="text" name="filter_search" class="form-control-sm" placeholder="Номер, УИД или ФИО" value="<?php echo h($_GET['filter_search'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn-sm btn-primary">Применить фильтр</button>
                        <a href="?tab=cases" class="btn-sm btn-secondary">Сбросить</a>
                    </div>
                </form>
            </div>
            
            <!-- Форма массовых операций -->
            <form method="POST" id="massActionForm" onsubmit="return confirmMassAction()">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="mass_case_action">
                <input type="hidden" name="mass_action_type" id="massActionType">
                
                <div class="mass-actions-bar">
                    <span class="selected-count" id="selectedCount">Выбрано: 0</span>
                    <div class="mass-buttons">
                        <select name="new_status" id="massStatus" class="form-control-sm" style="display: none;">
                            <option value="">-- Новый статус --</option>
                            <option value="draft">Черновик</option>
                            <option value="accepted">Принято к производству</option>
                            <option value="preparing">Подготовка к заседанию</option>
                            <option value="trial">Судебное разбирательство</option>
                            <option value="verdict">Решение вынесено</option>
                            <option value="archived">Архив</option>
                        </select>
                        <select name="new_judge_id" id="massJudge" class="form-control-sm" style="display: none;">
                            <option value="">-- Назначить судью --</option>
                            <?php foreach ($judges_list as $judge): ?>
                                <option value="<?php echo $judge['id']; ?>"><?php echo h($judge['in_game_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn-sm btn-primary" onclick="showMassStatus()" id="btnMassStatus" style="display: none;">
                            <i class="fas fa-tasks"></i> Сменить статус
                        </button>
                        <button type="button" class="btn-sm btn-primary" onclick="showMassJudge()" id="btnMassJudge" style="display: none;">
                            <i class="fas fa-gavel"></i> Назначить судью
                        </button>
                        <button type="button" class="btn-sm btn-danger" onclick="showMassDelete()" id="btnMassDelete" style="display: none;">
                            <i class="fas fa-trash-alt"></i> Удалить
                        </button>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th width="30"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                                <th>Номер</th>
                                <th>Тип</th>
                                <th>Название</th>
                                <th>Истец</th>
                                <th>Ответчик</th>
                                <th>Судья</th>
                                <th>Статус</th>
                                <th>Дата</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Пагинация
                            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
                            $per_page = 20;
                            $offset = ($page - 1) * $per_page;
                            
                            // Строим запрос с фильтрацией
                            $sql_cases = "
                                SELECT c.*, 
                                       u1.in_game_name as plaintiff_name,
                                       u2.in_game_name as defendant_name,
                                       u3.in_game_name as judge_name
                                FROM court_cases c
                                LEFT JOIN users u1 ON c.plaintiff_id = u1.id
                                LEFT JOIN users u2 ON c.defendant_id = u2.id
                                LEFT JOIN users u3 ON c.judge_id = u3.id
                                WHERE 1=1
                            ";
                            $params_cases = [];
                            
                            if (!empty($_GET['filter_type'])) {
                                $sql_cases .= " AND c.case_type_code = ?";
                                $params_cases[] = $_GET['filter_type'];
                            }
                            if (!empty($_GET['filter_status'])) {
                                $sql_cases .= " AND c.status = ?";
                                $params_cases[] = $_GET['filter_status'];
                            }
                            if (!empty($_GET['filter_judge'])) {
                                $sql_cases .= " AND c.judge_id = ?";
                                $params_cases[] = $_GET['filter_judge'];
                            }
                            if (!empty($_GET['filter_year'])) {
                                $sql_cases .= " AND c.case_year = ?";
                                $params_cases[] = $_GET['filter_year'];
                            }
                            if (!empty($_GET['filter_date_from'])) {
                                $sql_cases .= " AND DATE(c.created_at) >= ?";
                                $params_cases[] = $_GET['filter_date_from'];
                            }
                            if (!empty($_GET['filter_date_to'])) {
                                $sql_cases .= " AND DATE(c.created_at) <= ?";
                                $params_cases[] = $_GET['filter_date_to'];
                            }
                            if (!empty($_GET['filter_search'])) {
                                $sql_cases .= " AND (c.case_number_full LIKE ? OR c.uid LIKE ? OR u1.in_game_name LIKE ? OR u2.in_game_name LIKE ?)";
                                $search = '%' . $_GET['filter_search'] . '%';
                                $params_cases[] = $search;
                                $params_cases[] = $search;
                                $params_cases[] = $search;
                                $params_cases[] = $search;
                            }
                            
                            $sql_cases .= " ORDER BY c.created_at DESC LIMIT " . intval($per_page) . " OFFSET " . intval($offset);
                            
                            $stmt = $pdo->prepare($sql_cases);
                            $stmt->execute($params_cases);
                            $all_cases = $stmt->fetchAll();
                            
                            // Подсчёт для пагинации
                            $sql_count = "SELECT COUNT(*) as total FROM court_cases c WHERE 1=1";
                            $count_params = [];
                            if (!empty($_GET['filter_type'])) {
                                $sql_count .= " AND c.case_type_code = ?";
                                $count_params[] = $_GET['filter_type'];
                            }
                            if (!empty($_GET['filter_status'])) {
                                $sql_count .= " AND c.status = ?";
                                $count_params[] = $_GET['filter_status'];
                            }
                            if (!empty($_GET['filter_judge'])) {
                                $sql_count .= " AND c.judge_id = ?";
                                $count_params[] = $_GET['filter_judge'];
                            }
                            if (!empty($_GET['filter_year'])) {
                                $sql_count .= " AND c.case_year = ?";
                                $count_params[] = $_GET['filter_year'];
                            }
                            if (!empty($_GET['filter_date_from'])) {
                                $sql_count .= " AND DATE(c.created_at) >= ?";
                                $count_params[] = $_GET['filter_date_from'];
                            }
                            if (!empty($_GET['filter_date_to'])) {
                                $sql_count .= " AND DATE(c.created_at) <= ?";
                                $count_params[] = $_GET['filter_date_to'];
                            }
                            if (!empty($_GET['filter_search'])) {
                                $sql_count .= " AND (c.case_number_full LIKE ? OR c.uid LIKE ?)";
                                $search = '%' . $_GET['filter_search'] . '%';
                                $count_params[] = $search;
                                $count_params[] = $search;
                            }
                            
                            $stmt = $pdo->prepare($sql_count);
                            $stmt->execute($count_params);
                            $total_cases_count = $stmt->fetch()['total'];
                            $total_pages = ceil($total_cases_count / $per_page);
                            ?>
                            
                            <?php if (empty($all_cases)): ?>
                                <tr>
                                    <td colspan="10" class="empty-row">Нет дел по указанным критериям</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($all_cases as $case): ?>
                                <tr>
                                    <td><input type="checkbox" name="case_uids[]" value="<?php echo h($case['uid']); ?>" class="case-checkbox" onchange="updateSelectedCount()"></td>
                                    <td class="case-number"><?php echo h($case['case_number_full']); ?></td>
                                    <td><?php echo h($case['case_type_name']); ?></td>
                                    <td class="case-title" title="<?php echo h($case['title']); ?>"><?php echo h(mb_substr($case['title'], 0, 40)) . (mb_strlen($case['title']) > 40 ? '...' : ''); ?></td>
                                    <td><?php echo h($case['plaintiff_name'] ?? '—'); ?></td>
                                    <td><?php echo h($case['defendant_name'] ?? '—'); ?></td>
                                    <td><?php echo h($case['judge_name'] ?? 'Не назначен'); ?></td>
                                    <td><span class="status-badge status-<?php echo $case['status']; ?>"><?php echo getStatusName($case['status'], $case['case_type_code']); ?></span></td>
                                    <td><?php echo date('d.m.Y', strtotime($case['created_at'])); ?></td>
                                    <td class="actions-cell">
                                        <a href="/case.php?uid=<?php echo urlencode($case['uid']); ?>" class="action-btn" title="Просмотреть"><i class="fas fa-eye"></i></a>
                                        <button type="button" class="action-btn" onclick="quickAssignJudge('<?php echo $case['uid']; ?>')" title="Назначить судью"><i class="fas fa-gavel"></i></button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить дело <?php echo h($case['case_number_full']); ?>?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                            <input type="hidden" name="action" value="delete_case">
                                            <input type="hidden" name="case_uid" value="<?php echo h($case['uid']); ?>">
                                            <button type="submit" class="action-btn delete-btn" title="Удалить дело"><i class="fas fa-trash-alt"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?tab=cases&page=<?php echo $page - 1; ?>&<?php echo http_build_query(array_filter($_GET, function($key) { return $key != 'page' && $key != 'tab'; }, ARRAY_FILTER_USE_KEY)); ?>" class="page-link"><i class="fas fa-chevron-left"></i> Назад</a>
                    <?php endif; ?>
                    <span class="page-info">Страница <?php echo $page; ?> из <?php echo $total_pages; ?></span>
                    <?php if ($page < $total_pages): ?>
                        <a href="?tab=cases&page=<?php echo $page + 1; ?>&<?php echo http_build_query(array_filter($_GET, function($key) { return $key != 'page' && $key != 'tab'; }, ARRAY_FILTER_USE_KEY)); ?>" class="page-link">Вперёд <i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </form>
        </div>
        
        <!-- Дополнительные блоки статистики -->
        <div class="stats-additional">
            <div class="stat-block">
                <h4><i class="fas fa-hourglass-half"></i> Дела без движения &gt;30 дней</h4>
                <?php
                $stmt = $pdo->query("
                    SELECT c.case_number_full, c.uid, c.updated_at
                    FROM court_cases c
                    WHERE c.status NOT IN ('verdict', 'archived')
                      AND c.updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
                    ORDER BY c.updated_at ASC
                    LIMIT 5
                ");
                $stale_cases = $stmt->fetchAll();
                ?>
                <?php if (empty($stale_cases)): ?>
                    <p class="no-data">Нет дел без движения более 30 дней</p>
                <?php else: ?>
                    <ul class="stale-list">
                        <?php foreach ($stale_cases as $stale): ?>
                            <li>
                                <a href="/case.php?uid=<?php echo urlencode($stale['uid']); ?>"><?php echo h($stale['case_number_full']); ?></a>
                                <span class="stale-date">(последнее изменение: <?php echo date('d.m.Y', strtotime($stale['updated_at'])); ?>)</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            
            <div class="stat-block">
                <h4><i class="fas fa-chart-line"></i> Нагрузка на судей</h4>
                <?php
                $stmt = $pdo->query("
                    SELECT u.id, u.in_game_name, 
                           COUNT(c.id) as active_cases
                    FROM users u
                    LEFT JOIN court_cases c ON c.judge_id = u.id AND c.status NOT IN ('verdict', 'archived')
                    WHERE u.role IN ('judge', 'chairman')
                    GROUP BY u.id
                    ORDER BY active_cases DESC
                ");
                $judge_load = $stmt->fetchAll();
                ?>
                <div class="judge-load-list">
                    <?php foreach ($judge_load as $judge): ?>
                        <div class="judge-load-item">
                            <span class="judge-name"><?php echo h($judge['in_game_name']); ?></span>
                            <div class="load-bar-container">
                                <div class="load-bar" style="width: <?php echo min(100, $judge['active_cases'] * 5); ?>%"></div>
                            </div>
                            <span class="load-count"><?php echo $judge['active_cases']; ?> дел</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Модальное окно быстрого назначения судьи -->
    <div id="quickAssignModal" class="modal" style="display: none;">
        <div class="modal-content small">
            <div class="modal-header">
                <h3>Назначить судью</h3>
                <button class="modal-close" onclick="closeQuickAssignModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="quickAssignForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="reassign_case">
                    <input type="hidden" name="case_uid" id="quickAssignCaseUid">
                    <div class="form-group">
                        <label>Выберите судью</label>
                        <select name="judge_id" class="form-control" required>
                            <option value="">-- Выберите судью --</option>
                            <?php foreach ($judges_list as $judge): ?>
                                <option value="<?php echo $judge['id']; ?>"><?php echo h($judge['in_game_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Назначить</button>
                        <button type="button" class="btn btn-secondary" onclick="closeQuickAssignModal()">Отмена</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Апелляции -->
    <?php if ($active_tab === 'appeals'): ?>
    <div class="tab-content">
        <div class="admin-card">
            <h3><i class="fas fa-gavel"></i> Апелляции на рассмотрении</h3>
            <?php if (empty($pending_appeals)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <p>Нет апелляций, ожидающих рассмотрения</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Оригинальное дело</th>
                                <th>Апелляционное дело</th>
                                <th>Заявитель</th>
                                <th>Причина обжалования</th>
                                <th>Дата подачи</th>
                                <th>Действие</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_appeals as $appeal): ?>
                            <tr>
                                <td class="case-number">
                                    <a href="/case.php?uid=<?php echo urlencode($appeal['case_uid']); ?>">
                                        <?php echo h($appeal['original_case_number']); ?>
                                    </a>
                                </td>
                                <td class="case-number">
                                    <a href="/case.php?uid=<?php echo urlencode($appeal['appeal_case_uid']); ?>">
                                        <?php echo h($appeal['appeal_case_number']); ?>
                                    </a>
                                </td>
                                <td><?php echo h($appeal['appellant_name']); ?></td>
                                <td class="log-details"><?php echo h(substr($appeal['appeal_reason'], 0, 100)) . '...'; ?></td>
                                <td><?php echo date('d.m.Y', strtotime($appeal['created_at'])); ?></td>
                                <td>
                                    <a href="/case.php?uid=<?php echo urlencode($appeal['appeal_case_uid']); ?>" class="btn-sm btn-primary">
                                        Рассмотреть
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Логи аудита -->
    <?php if ($active_tab === 'audit'): ?>
    <div class="tab-content">
        <div class="admin-card">
            <h3><i class="fas fa-history"></i> Журнал действий</h3>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr><th>Время</th><th>Пользователь</th><th>Действие</th><th>Дело</th><th>Детали</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($audit_logs as $log): ?>
                        <tr>
                            <td><?php echo date('d.m.Y H:i:s', strtotime($log['created_at'])); ?></td>
                            <td><?php echo h($log['user_name'] ?? 'Система'); ?></td>
                            <td><?php echo h($log['action']); ?></td>
                            <td><?php echo $log['case_uid'] ? h($log['case_uid']) : '—'; ?></td>
                            <td class="log-details"><?php echo h($log['new_value'] ?? $log['old_value'] ?? '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Настройки -->
    <?php if ($active_tab === 'settings'): ?>
    <div class="tab-content">
        <div class="admin-card">
            <h3><i class="fas fa-database"></i> Информация о системе</h3>
            <div class="info-table">
                <div class="info-row"><span class="info-label">PHP версия:</span><span class="info-value"><?php echo phpversion(); ?></span></div>
                <div class="info-row"><span class="info-label">MySQL версия:</span><span class="info-value"><?php echo $pdo->getAttribute(PDO::ATTR_SERVER_VERSION); ?></span></div>
                <div class="info-row"><span class="info-label">Время сервера:</span><span class="info-value"><?php echo date('d.m.Y H:i:s'); ?></span></div>
                <div class="info-row"><span class="info-label">Загружено файлов:</span><span class="info-value"><?php 
                    $upload_dir = __DIR__ . '/../uploads/';
                    $court_dir = __DIR__ . '/../uploads/court_documents/';
                    $files_count = 0;
                    if (is_dir($upload_dir)) $files_count += count(glob($upload_dir . '*')) - 1;
                    if (is_dir($court_dir)) $files_count += count(glob($court_dir . '*')) - 1;
                    echo max(0, $files_count);
                ?></span></div>
            </div>
        </div>
        
        <div class="admin-card">
            <h3><i class="fas fa-tools"></i> Быстрые действия</h3>
            <div class="actions-grid">
                <a href="/dashboard.php" class="action-card"><i class="fas fa-tachometer-alt"></i> Перейти в дашборд</a>
                <a href="/file-case.php" class="action-card"><i class="fas fa-file-alt"></i> Создать тестовое дело</a>
                <a href="/search.php" class="action-card"><i class="fas fa-search"></i> Поиск дел</a>
                <a href="/admin/?tab=appeals" class="action-card"><i class="fas fa-gavel"></i> Апелляции к рассмотрению</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
/* Admin Page Styles */
.admin-page { max-width: 1400px; margin: 0 auto; }
.admin-tabs { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; border-bottom: 1px solid var(--gray-200); padding-bottom: 0.5rem; }
.admin-tab { padding: 0.5rem 1rem; background: var(--gray-100); border-radius: var(--radius); text-decoration: none; color: var(--gray-700); font-size: 0.875rem; transition: all 0.2s; display: inline-flex; align-items: center; gap: 0.5rem; }
.admin-tab:hover { background: var(--gray-200); }
.admin-tab.active { background: var(--primary); color: white; }
.tab-badge { background: var(--danger); color: white; font-size: 0.7rem; padding: 0.125rem 0.375rem; border-radius: 1rem; margin-left: 0.25rem; }
.stats-grid-admin { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.stat-card-admin { background: white; border-radius: var(--radius-lg); padding: 1rem 1.25rem; display: flex; align-items: center; gap: 1rem; box-shadow: var(--shadow); transition: transform 0.2s; }
.stat-card-admin:hover { transform: translateY(-2px); }
.stat-card-admin.warning .stat-icon { background: var(--warning); }
.stat-card-admin.appeal .stat-icon { background: #6f42c1; }
.stat-icon { width: 48px; height: 48px; background: var(--primary-light); border-radius: 12px; display: flex; align-items: center; justify-content: center; }
.stat-icon i { font-size: 1.5rem; color: white; }
.stat-info { flex: 1; }
.stat-value { font-size: 1.5rem; font-weight: 700; color: var(--primary); }
.stat-label { font-size: 0.7rem; color: var(--gray-600); }
.admin-card { background: white; border-radius: var(--radius-lg); box-shadow: var(--shadow); margin-bottom: 1.5rem; overflow: hidden; }
.admin-card h3 { padding: 1rem 1.5rem; background: var(--gray-50); border-bottom: 1px solid var(--gray-200); font-size: 1rem; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
.table-responsive { overflow-x: auto; }
.admin-table { width: 100%; border-collapse: collapse; }
.admin-table th { padding: 0.75rem 1rem; text-align: left; font-size: 0.75rem; font-weight: 600; color: var(--gray-600); background: var(--gray-50); border-bottom: 1px solid var(--gray-200); }
.admin-table td { padding: 0.75rem 1rem; font-size: 0.875rem; border-bottom: 1px solid var(--gray-100); vertical-align: middle; }
.admin-table tr:hover { background: var(--gray-50); }
.form-control-sm { padding: 0.375rem 0.5rem; font-size: 0.75rem; border-radius: 6px; border: 1px solid var(--gray-300); font-family: inherit; }
.role-select { min-width: 120px; }
.status-active { background: #d1e7dd; color: #0a3622; display: inline-block; padding: 0.25rem 0.5rem; border-radius: 2rem; font-size: 0.7rem; font-weight: 500; }
.status-inactive { background: #f8d7da; color: #58151c; display: inline-block; padding: 0.25rem 0.5rem; border-radius: 2rem; font-size: 0.7rem; font-weight: 500; }
.actions-cell { white-space: nowrap; }
.action-btn { background: none; border: none; cursor: pointer; padding: 0.25rem 0.5rem; color: var(--gray-600); font-size: 1rem; transition: color 0.2s; }
.action-btn:hover { color: var(--primary); }
.delete-btn:hover { color: var(--danger); }
.btn-sm { padding: 0.375rem 0.75rem; font-size: 0.75rem; border-radius: 6px; cursor: pointer; border: none; font-family: inherit; }
.btn-primary { background: var(--primary); color: white; }
.btn-primary:hover { background: var(--primary-dark); }
.btn-secondary { background: var(--gray-100); color: var(--gray-700); border: 1px solid var(--gray-300); }
.btn-secondary:hover { background: var(--gray-200); }
.pagination { display: flex; justify-content: center; align-items: center; gap: 1rem; margin-top: 1.5rem; padding: 1rem; }
.page-link { padding: 0.5rem 1rem; background: var(--gray-100); border-radius: var(--radius); text-decoration: none; color: var(--gray-700); font-size: 0.875rem; }
.page-link:hover { background: var(--primary); color: white; }
.page-info { font-size: 0.875rem; color: var(--gray-600); }
.type-bars { display: flex; flex-direction: column; gap: 0.75rem; padding: 1rem; }
.type-bar-item { display: flex; align-items: center; gap: 0.75rem; }
.type-label { width: 40px; font-family: monospace; font-weight: 600; }
.bar-container { flex: 1; height: 8px; background: var(--gray-200); border-radius: 4px; overflow: hidden; }
.bar { height: 100%; background: var(--primary); border-radius: 4px; transition: width 0.3s; }
.type-count { width: 40px; font-size: 0.75rem; color: var(--gray-600); text-align: right; }
.month-bars { display: flex; justify-content: space-around; align-items: flex-end; gap: 0.5rem; padding: 1rem; height: 200px; }
.month-bar-item { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 0.5rem; }
.month-bar-item .bar { width: 100%; background: var(--primary); border-radius: 4px 4px 0 0; min-height: 2px; transition: height 0.3s; }
.month-bar-item .label { font-size: 0.7rem; color: var(--gray-600); }
.month-bar-item .count { font-size: 0.7rem; font-weight: 600; color: var(--primary); }
.actions-grid { display: flex; gap: 1rem; flex-wrap: wrap; padding: 1rem; }
.action-card { display: flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.25rem; background: var(--gray-50); border-radius: var(--radius); text-decoration: none; color: var(--gray-700); transition: all 0.2s; }
.action-card:hover { background: var(--gray-100); transform: translateY(-2px); }
.info-table { display: flex; flex-direction: column; gap: 0.5rem; padding: 1rem; }
.log-details { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.7rem; color: var(--gray-600); }
.empty-state { text-align: center; padding: 3rem; color: var(--gray-500); }
.empty-state i { font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.5; }

/* Стили для расширенного управления делами */
.card-header-actions {
    padding: 1rem 1.5rem;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}
.card-header-actions h3 {
    margin: 0;
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.header-buttons {
    display: flex;
    gap: 0.5rem;
}
.filter-panel {
    padding: 1rem 1.5rem;
    background: #f8f9fa;
    border-bottom: 1px solid var(--gray-200);
}
.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 0.75rem;
    margin-bottom: 1rem;
}
.filter-grid .form-group {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}
.filter-grid label {
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--gray-600);
}
.filter-actions {
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
}
.mass-actions-bar {
    padding: 0.75rem 1.5rem;
    background: #e8f0fe;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}
.selected-count {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--primary);
}
.mass-buttons {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}
.admin-table .case-title {
    max-width: 200px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.empty-row {
    text-align: center;
    padding: 2rem;
    color: var(--gray-500);
}
.stats-additional {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1rem;
    padding: 1.5rem;
    background: var(--gray-50);
    border-top: 1px solid var(--gray-200);
}
.stat-block {
    background: white;
    border-radius: var(--radius);
    padding: 1rem;
}
.stat-block h4 {
    font-size: 0.875rem;
    margin-bottom: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.stat-block .no-data {
    font-size: 0.75rem;
    color: var(--gray-500);
    text-align: center;
    padding: 0.5rem;
}
.stale-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.stale-list li {
    padding: 0.375rem 0;
    border-bottom: 1px solid var(--gray-100);
    font-size: 0.75rem;
}
.stale-list li a {
    color: var(--primary);
    text-decoration: none;
    font-family: monospace;
}
.stale-list li a:hover {
    text-decoration: underline;
}
.stale-date {
    font-size: 0.65rem;
    color: var(--gray-500);
    margin-left: 0.5rem;
}
.judge-load-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}
.judge-load-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
}
.judge-name {
    min-width: 120px;
    font-weight: 500;
}
.load-bar-container {
    flex: 1;
    height: 6px;
    background: var(--gray-200);
    border-radius: 3px;
    overflow: hidden;
}
.load-bar {
    height: 100%;
    background: var(--primary);
    border-radius: 3px;
    transition: width 0.3s;
}
.load-count {
    min-width: 60px;
    text-align: right;
    color: var(--gray-600);
}
.modal.small .modal-content {
    max-width: 400px;
}
.modal-body {
    padding: 1rem 1.5rem;
}
.modal .form-actions {
    margin-top: 1rem;
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
}

@media (max-width: 768px) {
    .stats-grid-admin { grid-template-columns: repeat(2, 1fr); }
    .admin-table th, .admin-table td { padding: 0.5rem; font-size: 0.75rem; }
    .role-select { min-width: 100px; }
    .admin-tab { padding: 0.375rem 0.75rem; font-size: 0.75rem; }
    .month-bars { height: 150px; }
    .card-header-actions { flex-direction: column; align-items: stretch; }
    .header-buttons { justify-content: center; }
    .filter-grid { grid-template-columns: 1fr; }
    .mass-actions-bar { flex-direction: column; }
    .admin-table .case-title { max-width: 120px; }
}
</style>

<script>
// Фильтры для дел
function toggleFilters() {
    const panel = document.getElementById('filterPanel');
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
    } else {
        panel.style.display = 'none';
    }
}

// Массовые операции с делами
function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.case-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = selectAllCheckbox.checked;
    });
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.case-checkbox:checked');
    const count = checkboxes.length;
    const selectedCountSpan = document.getElementById('selectedCount');
    const massStatusSelect = document.getElementById('massStatus');
    const massJudgeSelect = document.getElementById('massJudge');
    const btnMassStatus = document.getElementById('btnMassStatus');
    const btnMassJudge = document.getElementById('btnMassJudge');
    const btnMassDelete = document.getElementById('btnMassDelete');
    
    selectedCountSpan.textContent = `Выбрано: ${count}`;
    
    if (count > 0) {
        massStatusSelect.style.display = 'inline-block';
        massJudgeSelect.style.display = 'inline-block';
        btnMassStatus.style.display = 'inline-block';
        btnMassJudge.style.display = 'inline-block';
        btnMassDelete.style.display = 'inline-block';
    } else {
        massStatusSelect.style.display = 'none';
        massJudgeSelect.style.display = 'none';
        btnMassStatus.style.display = 'none';
        btnMassJudge.style.display = 'none';
        btnMassDelete.style.display = 'none';
    }
}

function showMassStatus() {
    const select = document.getElementById('massStatus');
    const status = select.value;
    if (!status) {
        alert('Выберите новый статус');
        return;
    }
    
    const checked = document.querySelectorAll('.case-checkbox:checked');
    if (checked.length === 0) {
        alert('Не выбрано ни одного дела');
        return;
    }
    
    if (confirm(`Изменить статус для ${checked.length} дел?`)) {
        document.getElementById('massActionType').value = 'change_status';
        document.getElementById('massActionForm').submit();
    }
}

function showMassJudge() {
    const select = document.getElementById('massJudge');
    const judgeId = select.value;
    if (!judgeId) {
        alert('Выберите судью');
        return;
    }
    
    const checked = document.querySelectorAll('.case-checkbox:checked');
    if (checked.length === 0) {
        alert('Не выбрано ни одного дела');
        return;
    }
    
    if (confirm(`Назначить судью для ${checked.length} дел?`)) {
        document.getElementById('massActionType').value = 'assign_judge';
        document.getElementById('massActionForm').submit();
    }
}

function showMassDelete() {
    const checked = document.querySelectorAll('.case-checkbox:checked');
    if (checked.length === 0) {
        alert('Не выбрано ни одного дела');
        return;
    }
    
    if (confirm(`Удалить ${checked.length} дел? Это действие необратимо!`)) {
        document.getElementById('massActionType').value = 'delete_cases';
        document.getElementById('massActionForm').submit();
    }
}

function confirmMassAction() {
    const actionType = document.getElementById('massActionType').value;
    const checkboxes = document.querySelectorAll('.case-checkbox:checked');
    
    if (checkboxes.length === 0) {
        alert('Не выбрано ни одного дела');
        return false;
    }
    
    // Добавляем все выбранные UID в форму (на всякий случай, если чекбоксы не отправились)
    const form = document.getElementById('massActionForm');
    const oldInputs = form.querySelectorAll('input[name="case_uids[]"]');
    oldInputs.forEach(input => input.remove());
    
    checkboxes.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'case_uids[]';
        input.value = cb.value;
        form.appendChild(input);
    });
    
    if (actionType === 'change_status') {
        const status = document.getElementById('massStatus').value;
        if (!status) {
            alert('Выберите новый статус');
            return false;
        }
    }
    
    if (actionType === 'assign_judge') {
        const judgeId = document.getElementById('massJudge').value;
        if (!judgeId) {
            alert('Выберите судью');
            return false;
        }
    }
    
    return true;
}

// Быстрое назначение судьи
function quickAssignJudge(caseUid) {
    document.getElementById('quickAssignCaseUid').value = caseUid;
    document.getElementById('quickAssignModal').style.display = 'flex';
}

function closeQuickAssignModal() {
    document.getElementById('quickAssignModal').style.display = 'none';
}

// Экспорт CSV
function exportCasesCSV() {
    let url = '/admin/export_cases.php?';
    const params = new URLSearchParams(window.location.search);
    params.forEach((value, key) => {
        if (key !== 'tab' && key !== 'page') {
            url += `${key}=${encodeURIComponent(value)}&`;
        }
    });
    window.location.href = url;
}

// Закрытие модалок по клику вне окна
window.onclick = function(event) {
    const modal = document.getElementById('quickAssignModal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>