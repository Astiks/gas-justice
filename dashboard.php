<?php
// dashboard.php
// Личный кабинет (дашборд) — разный для разных ролей

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Проверка авторизации
if (!isLoggedIn()) {
    redirect('/index.php');
}

$user = getCurrentUser();
$page_title = 'Дашборд';

// Получение статистики для пользователя
$pdo = getDB();

// Дела, где пользователь является истцом
$stmt = $pdo->prepare("
    SELECT c.*, 
           u1.in_game_name as plaintiff_name, u1.id as plaintiff_id,
           u2.in_game_name as defendant_name, u2.id as defendant_id,
           u3.in_game_name as judge_name, u3.id as judge_id
    FROM court_cases c
    LEFT JOIN users u1 ON c.plaintiff_id = u1.id
    LEFT JOIN users u2 ON c.defendant_id = u2.id
    LEFT JOIN users u3 ON c.judge_id = u3.id
    WHERE c.plaintiff_id = ?
    ORDER BY c.created_at DESC
    LIMIT 10
");
$stmt->execute([$user['id']]);
$cases_as_plaintiff = $stmt->fetchAll();

// Дела, где пользователь является ответчиком
$stmt = $pdo->prepare("
    SELECT c.*, 
           u1.in_game_name as plaintiff_name, u1.id as plaintiff_id,
           u2.in_game_name as defendant_name, u2.id as defendant_id,
           u3.in_game_name as judge_name, u3.id as judge_id
    FROM court_cases c
    LEFT JOIN users u1 ON c.plaintiff_id = u1.id
    LEFT JOIN users u2 ON c.defendant_id = u2.id
    LEFT JOIN users u3 ON c.judge_id = u3.id
    WHERE c.defendant_id = ?
    ORDER BY c.created_at DESC
    LIMIT 10
");
$stmt->execute([$user['id']]);
$cases_as_defendant = $stmt->fetchAll();

// Количество непрочитанных уведомлений
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user['id']]);
$unread_count = $stmt->fetch()['count'];

// Для судей — дела, назначенные им
$cases_as_judge = [];
if (hasRole(['judge', 'chairman'])) {
    $stmt = $pdo->prepare("
        SELECT c.*, 
               u1.in_game_name as plaintiff_name, u1.id as plaintiff_id,
               u2.in_game_name as defendant_name, u2.id as defendant_id
        FROM court_cases c
        LEFT JOIN users u1 ON c.plaintiff_id = u1.id
        LEFT JOIN users u2 ON c.defendant_id = u2.id
        WHERE c.judge_id = ? AND c.status NOT IN ('archived', 'verdict')
        ORDER BY c.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$user['id']]);
    $cases_as_judge = $stmt->fetchAll();
}

// Для прокуроров — дела без прокурора
$cases_for_prosecutor = [];
if (hasRole(['prosecutor', 'chairman'])) {
    $stmt = $pdo->prepare("
        SELECT c.*, 
               u1.in_game_name as plaintiff_name, u1.id as plaintiff_id,
               u2.in_game_name as defendant_name, u2.id as defendant_id
        FROM court_cases c
        LEFT JOIN users u1 ON c.plaintiff_id = u1.id
        LEFT JOIN users u2 ON c.defendant_id = u2.id
        WHERE c.prosecutor_id IS NULL AND c.case_type_code = '1' AND c.status NOT IN ('archived', 'verdict')
        ORDER BY c.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $cases_for_prosecutor = $stmt->fetchAll();
}

// Статистика по делам пользователя
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'verdict' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status IN ('accepted', 'preparing', 'trial') THEN 1 ELSE 0 END) as in_progress
    FROM court_cases 
    WHERE plaintiff_id = ? OR defendant_id = ?
");
$stmt->execute([$user['id'], $user['id']]);
$user_stats = $stmt->fetch();

include __DIR__ . '/includes/header.php';
?>

<div class="dashboard">
    <!-- Приветственная секция -->
    <div class="dashboard-header">
        <div class="dashboard-welcome">
            <div class="welcome-avatar">
                <?php if ($user['avatar'] && file_exists(__DIR__ . '/' . $user['avatar'])): ?>
                    <img src="<?php echo SITE_URL . '/' . $user['avatar']; ?>" alt="Аватар" class="welcome-avatar-img">
                <?php else: ?>
                    <i class="fas fa-user-circle"></i>
                <?php endif; ?>
            </div>
            <div class="welcome-text">
                <h1>Добро пожаловать, <?php echo h($user['in_game_name']); ?>!</h1>
                <p class="role-badge role-<?php echo $user['role']; ?>">
                    <i class="fas <?php 
                        echo $user['role'] == 'judge' ? 'fa-gavel' : 
                            ($user['role'] == 'prosecutor' ? 'fa-balance-scale' : 
                            ($user['role'] == 'lawyer' ? 'fa-user-tie' : 
                            ($user['role'] == 'chairman' ? 'fa-crown' : 'fa-user'))); 
                    ?>"></i>
                    <?php 
                        echo $user['role'] == 'judge' ? 'Судья' : 
                            ($user['role'] == 'prosecutor' ? 'Прокурор' : 
                            ($user['role'] == 'lawyer' ? 'Адвокат' : 
                            ($user['role'] == 'chairman' ? 'Председатель суда' : 'Гражданин'))); 
                    ?>
                </p>
            </div>
        </div>
        
        <div class="dashboard-stats">
            <div class="stat-card">
                <div class="stat-value"><?php echo $user_stats['total'] ?? 0; ?></div>
                <div class="stat-label">Всего дел</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $user_stats['in_progress'] ?? 0; ?></div>
                <div class="stat-label">В процессе</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $user_stats['completed'] ?? 0; ?></div>
                <div class="stat-label">Завершено</div>
            </div>
            <div class="stat-card clickable" onclick="window.location.href='/notifications.php'">
                <div class="stat-value">
                    <?php echo $unread_count; ?>
                    <?php if ($unread_count > 0): ?>
                        <span class="stat-badge">новых</span>
                    <?php endif; ?>
                </div>
                <div class="stat-label">Уведомления</div>
            </div>
        </div>
    </div>
    
    <div class="dashboard-grid">
        <!-- Дела, где пользователь истец -->
        <div class="dashboard-card">
            <div class="card-header">
                <h2><i class="fas fa-user-friends"></i> Дела, где я истец</h2>
                <a href="/file-case.php" class="btn btn-sm btn-primary">
                    <i class="fas fa-plus"></i> Новый иск
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($cases_as_plaintiff)): ?>
                    <div class="empty-state">
                        <i class="fas fa-balance-scale"></i>
                        <p>Вы ещё не подавали исков</p>
                        <a href="/file-case.php" class="btn btn-primary">Подать иск</a>
                    </div>
                <?php else: ?>
                    <div class="case-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Номер дела</th>
                                    <th>Тип</th>
                                    <th>Ответчик</th>
                                    <th>Статус</th>
                                    <th>Дата создания</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cases_as_plaintiff as $case): ?>
                                <tr>
                                    <td class="case-number"><?php echo h($case['case_number_full']); ?></td>
                                    <td><?php echo h($case['case_type_name']); ?></td>
                                    <td>
                                        <?php if ($case['defendant_id']): ?>
                                            <a href="/profile.php?id=<?php echo $case['defendant_id']; ?>"><?php echo h($case['defendant_name'] ?? 'Не указан'); ?></a>
                                        <?php else: ?>
                                            <?php echo h($case['defendant_name'] ?? 'Не указан'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $case['status']; ?>">
                                            <?php echo getStatusName($case['status'], $case['case_type_code']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d.m.Y', strtotime($case['created_at'])); ?></td>
                                    <td>
                                        <a href="/case.php?uid=<?php echo urlencode($case['uid']); ?>" class="btn-link">
                                            <i class="fas fa-eye"></i>
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
        
        <!-- Дела, где пользователь ответчик -->
        <div class="dashboard-card">
            <div class="card-header">
                <h2><i class="fas fa-user-shield"></i> Дела, где я ответчик</h2>
            </div>
            <div class="card-body">
                <?php if (empty($cases_as_defendant)): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>Вас ещё не привлекали к суду</p>
                    </div>
                <?php else: ?>
                    <div class="case-table">
                        <td>
                            <thead>
                                <tr>
                                    <th>Номер дела</th>
                                    <th>Тип</th>
                                    <th>Истец</th>
                                    <th>Статус</th>
                                    <th>Дата создания</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cases_as_defendant as $case): ?>
                                <tr>
                                    <td class="case-number"><?php echo h($case['case_number_full']); ?></td>
                                    <td><?php echo h($case['case_type_name']); ?></td>
                                    <td>
                                        <?php if ($case['plaintiff_id']): ?>
                                            <a href="/profile.php?id=<?php echo $case['plaintiff_id']; ?>"><?php echo h($case['plaintiff_name'] ?? 'Не указан'); ?></a>
                                        <?php else: ?>
                                            <?php echo h($case['plaintiff_name'] ?? 'Не указан'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $case['status']; ?>">
                                            <?php echo getStatusName($case['status'], $case['case_type_code']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d.m.Y', strtotime($case['created_at'])); ?></td>
                                    <td>
                                        <a href="/case.php?uid=<?php echo urlencode($case['uid']); ?>" class="btn-link">
                                            <i class="fas fa-eye"></i>
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
        
        <!-- Для судей: назначенные дела -->
        <?php if (hasRole(['judge', 'chairman']) && !empty($cases_as_judge)): ?>
        <div class="dashboard-card highlight">
            <div class="card-header">
                <h2><i class="fas fa-gavel"></i> Назначенные мне дела</h2>
            </div>
            <div class="card-body">
                <div class="case-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Номер дела</th>
                                <th>Тип</th>
                                <th>Истец</th>
                                <th>Ответчик</th>
                                <th>Статус</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cases_as_judge as $case): ?>
                            <tr>
                                <td class="case-number"><?php echo h($case['case_number_full']); ?></td>
                                <td><?php echo h($case['case_type_name']); ?></td>
                                <td>
                                    <?php if ($case['plaintiff_id']): ?>
                                        <a href="/profile.php?id=<?php echo $case['plaintiff_id']; ?>"><?php echo h($case['plaintiff_name'] ?? 'Не указан'); ?></a>
                                    <?php else: ?>
                                        <?php echo h($case['plaintiff_name'] ?? 'Не указан'); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($case['defendant_id']): ?>
                                        <a href="/profile.php?id=<?php echo $case['defendant_id']; ?>"><?php echo h($case['defendant_name'] ?? 'Не указан'); ?></a>
                                    <?php else: ?>
                                        <?php echo h($case['defendant_name'] ?? 'Не указан'); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $case['status']; ?>">
                                        <?php echo getStatusName($case['status'], $case['case_type_code']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="/case.php?uid=<?php echo urlencode($case['uid']); ?>" class="btn-link">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </tr>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Для прокуроров: дела без прокурора и статистика -->
        <?php if ($user['role'] === 'prosecutor' || $user['role'] === 'chairman'): ?>
        <?php
        $waiting_cases = getCasesWaitingForProsecutor();
        $my_prosecutor_cases = getProsecutorCases($user['id'], 10);
        $prosecutor_stats = getProsecutorStats($user['id']);
        ?>
        
        <!-- Статистика прокурора -->
        <div class="dashboard-card">
            <div class="card-header">
                <h2><i class="fas fa-chart-bar"></i> Моя статистика</h2>
            </div>
            <div class="card-body">
                <div class="stats-mini">
                    <div class="stat-mini-item">
                        <div class="stat-mini-number"><?php echo $prosecutor_stats['total_cases']; ?></div>
                        <div class="stat-mini-label">Всего дел</div>
                    </div>
                    <div class="stat-mini-item">
                        <div class="stat-mini-number"><?php echo $prosecutor_stats['convictions']; ?></div>
                        <div class="stat-mini-label">Обвинительных</div>
                    </div>
                    <div class="stat-mini-item">
                        <div class="stat-mini-number"><?php echo $prosecutor_stats['acquittals']; ?></div>
                        <div class="stat-mini-label">Оправдательных</div>
                    </div>
                    <div class="stat-mini-item">
                        <div class="stat-mini-number"><?php echo $prosecutor_stats['win_rate']; ?>%</div>
                        <div class="stat-mini-label">Успешность</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Дела, ожидающие прокурора -->
        <div class="dashboard-card highlight-warning">
            <div class="card-header">
                <h2><i class="fas fa-clock"></i> Дела, ожидающие прокурора</h2>
                <span class="badge">Требуется назначение</span>
            </div>
            <div class="card-body">
                <?php if (empty($waiting_cases)): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p>Нет дел, ожидающих прокурора</p>
                    </div>
                <?php else: ?>
                    <div class="case-table">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Номер дела</th>
                                    <th>Обвиняемый</th>
                                    <th>Потерпевший</th>
                                    <th>Статус</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($waiting_cases as $case): ?>
                                    <tr>
                                        <td class="case-number"><?php echo h($case['case_number_full']); ?></td>
                                        <td><?php echo h($case['defendant_name'] ?? 'Не указан'); ?></td>
                                        <td><?php echo h($case['plaintiff_name'] ?? 'Не указан'); ?></td>
                                        <td><span class="status-badge status-<?php echo $case['status']; ?>"><?php echo getStatusName($case['status'], $case['case_type_code']); ?></span></td>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                                <input type="hidden" name="action" value="assign_prosecutor_self">
                                                <input type="hidden" name="case_uid" value="<?php echo h($case['uid']); ?>">
                                                <button type="submit" class="btn-sm btn-primary">Назначить себя</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="compact-more">
                        <a href="/search.php?filter_status=accepted&filter_type=1" class="btn-link">Все дела без прокурора →</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Мои дела (прокурор) -->
        <div class="dashboard-card">
            <div class="card-header">
                <h2><i class="fas fa-gavel"></i> Мои дела</h2>
            </div>
            <div class="card-body">
                <?php if (empty($my_prosecutor_cases)): ?>
                    <div class="empty-state">
                        <i class="fas fa-folder-open"></i>
                        <p>У вас нет назначенных дел</p>
                    </div>
                <?php else: ?>
                    <div class="case-table">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Номер дела</th>
                                    <th>Обвиняемый</th>
                                    <th>Судья</th>
                                    <th>Статус</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($my_prosecutor_cases as $case): ?>
                                    <tr>
                                        <td class="case-number"><?php echo h($case['case_number_full']); ?></td>
                                        <td><?php echo h($case['defendant_name'] ?? 'Не указан'); ?></td>
                                        <td><?php echo h($case['judge_name'] ?? 'Не назначен'); ?></td>
                                        <td><span class="status-badge status-<?php echo $case['status']; ?>"><?php echo getStatusName($case['status'], $case['case_type_code']); ?></span></td>
                                        <td><a href="/case.php?uid=<?php echo urlencode($case['uid']); ?>" class="btn-link"><i class="fas fa-eye"></i></a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="compact-more">
                        <a href="/search.php?participant=<?php echo urlencode($user['in_game_name']); ?>" class="btn-link">Все дела →</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Для адвоката: мои доверители -->
        <?php if ($user['role'] === 'lawyer'): ?>
        <?php
        $my_clients = getLawyerClients($user['id']);
        ?>
        <div class="dashboard-card">
            <div class="card-header">
                <h2><i class="fas fa-user-tie"></i> Мои доверители</h2>
                <a href="/clients.php" class="btn btn-sm btn-secondary">
                    <i class="fas fa-cog"></i> Управление
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($my_clients)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <p>У вас пока нет доверителей</p>
                        <a href="/clients.php" class="btn btn-primary btn-sm">Добавить доверителя</a>
                    </div>
                <?php else: ?>
                    <div class="clients-compact-list">
                        <?php foreach ($my_clients as $client): ?>
                            <div class="client-compact-item">
                                <div class="client-compact-avatar">
                                    <?php if ($client['avatar'] && file_exists(__DIR__ . '/' . $client['avatar'])): ?>
                                        <img src="<?php echo SITE_URL . '/' . $client['avatar']; ?>" alt="Аватар">
                                    <?php else: ?>
                                        <i class="fas fa-user-circle"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="client-compact-info">
                                    <div class="client-compact-name">
                                        <a href="/profile.php?id=<?php echo $client['id']; ?>"><?php echo h($client['in_game_name']); ?></a>
                                    </div>
                                    <div class="client-compact-stats">
                                        <span class="stat">📋 <?php echo $client['cases_count']; ?> дел</span>
                                    </div>
                                </div>
                                <div class="client-compact-actions">
                                    <a href="/search.php?participant=<?php echo urlencode($client['in_game_name']); ?>" class="btn-icon" title="Дела доверителя">
                                        <i class="fas fa-folder-open"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="compact-more">
                        <a href="/clients.php" class="btn-link">Управление доверителями →</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Дела доверителей (последние) -->
        <?php if (!empty($my_clients)): ?>
        <?php
        $all_client_cases = [];
        foreach ($my_clients as $client) {
            $client_cases = getClientCases($client['id'], 3);
            foreach ($client_cases as $case) {
                $case['client_name'] = $client['in_game_name'];
                $case['client_id'] = $client['id'];
                $all_client_cases[] = $case;
            }
        }
        usort($all_client_cases, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        $all_client_cases = array_slice($all_client_cases, 0, 10);
        ?>
        
        <?php if (!empty($all_client_cases)): ?>
        <div class="dashboard-card">
            <div class="card-header">
                <h2><i class="fas fa-gavel"></i> Последние дела доверителей</h2>
            </div>
            <div class="card-body">
                <div class="case-table">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Доверитель</th>
                                <th>Номер дела</th>
                                <th>Статус</th>
                                <th>Дата</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_client_cases as $case): ?>
                                <tr>
                                    <td><a href="/profile.php?id=<?php echo $case['client_id']; ?>"><?php echo h($case['client_name']); ?></a></td>
                                    <td class="case-number"><?php echo h($case['case_number_full']); ?></td>
                                    <td><span class="status-badge status-<?php echo $case['status']; ?>"><?php echo getStatusName($case['status'], $case['case_type_code']); ?></span></td>
                                    <td class="date-cell"><?php echo date('d.m.Y', strtotime($case['created_at'])); ?></td>
                                    <td><a href="/case.php?uid=<?php echo urlencode($case['uid']); ?>" class="btn-link"><i class="fas fa-eye"></i></a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <!-- Быстрые действия -->
    <div class="quick-actions">
        <h3><i class="fas fa-bolt"></i> Быстрые действия</h3>
        <div class="actions-grid">
            <a href="/file-case.php" class="action-card">
                <i class="fas fa-file-alt"></i>
                <span>Подать иск</span>
            </a>
            <a href="/search.php" class="action-card">
                <i class="fas fa-search"></i>
                <span>Поиск дел</span>
            </a>
            <?php if (hasRole(['judge', 'chairman'])): ?>
            <a href="/admin/" class="action-card">
                <i class="fas fa-shield-alt"></i>
                <span>Управление</span>
            </a>
            <?php endif; ?>
            <a href="/profile.php" class="action-card">
                <i class="fas fa-id-card"></i>
                <span>Мой профиль</span>
            </a>
        </div>
    </div>
</div>

<style>
/* Dashboard Styles */
.dashboard {
    animation: fadeIn 0.3s ease;
}

.dashboard-header {
    background: white;
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    margin-bottom: 2rem;
    box-shadow: var(--shadow);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1.5rem;
}

.dashboard-welcome {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.welcome-avatar i,
.welcome-avatar-img {
    width: 60px;
    height: 60px;
    font-size: 3rem;
    color: var(--gray-500);
    border-radius: 50%;
    object-fit: cover;
}

.welcome-text h1 {
    font-size: 1.5rem;
    margin-bottom: 0.25rem;
}

.role-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.25rem 0.75rem;
    border-radius: 2rem;
    font-size: 0.75rem;
    font-weight: 500;
}

.role-citizen { background: var(--gray-100); color: var(--gray-700); }
.role-judge { background: #e8f0fe; color: var(--primary); }
.role-prosecutor { background: #fef3e8; color: var(--secondary); }
.role-lawyer { background: #e8f5e9; color: var(--success); }
.role-chairman { background: linear-gradient(135deg, #c4a747, #8b4513); color: white; }

.dashboard-stats {
    display: flex;
    gap: 1rem;
}

.stat-card {
    background: var(--gray-50);
    border-radius: var(--radius);
    padding: 0.75rem 1.25rem;
    text-align: center;
    min-width: 100px;
    cursor: default;
    transition: all 0.2s;
}

.stat-card.clickable {
    cursor: pointer;
}

.stat-card.clickable:hover {
    background: var(--gray-100);
    transform: translateY(-2px);
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--primary);
    position: relative;
}

.stat-badge {
    position: absolute;
    top: -8px;
    right: -20px;
    background: var(--danger);
    color: white;
    font-size: 0.625rem;
    padding: 0.125rem 0.375rem;
    border-radius: 1rem;
}

.stat-label {
    font-size: 0.75rem;
    color: var(--gray-600);
}

.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.dashboard-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    overflow: hidden;
}

.dashboard-card.highlight {
    border-left: 4px solid var(--primary);
}

.dashboard-card.highlight-warning {
    border-left: 4px solid var(--warning);
}

.card-header {
    padding: 1rem 1.5rem;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.card-header h2 {
    font-size: 1rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.card-header h2 i {
    color: var(--primary);
}

.badge {
    background: var(--warning);
    color: var(--gray-900);
    padding: 0.25rem 0.5rem;
    border-radius: 2rem;
    font-size: 0.7rem;
    font-weight: 500;
}

.card-body {
    padding: 1rem 1.5rem;
}

.case-table {
    overflow-x: auto;
}

.case-table table {
    width: 100%;
    border-collapse: collapse;
}

.case-table th {
    text-align: left;
    padding: 0.75rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.case-table td {
    padding: 0.75rem 0.5rem;
    font-size: 0.875rem;
    border-bottom: 1px solid var(--gray-100);
}

.case-table tr:hover {
    background: var(--gray-50);
}

.case-number {
    font-family: monospace;
    font-weight: 600;
    color: var(--primary);
    white-space: nowrap;
}

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 2rem;
    font-size: 0.7rem;
    font-weight: 500;
}

.status-draft { background: var(--gray-200); color: var(--gray-600); }
.status-accepted { background: #e3f2fd; color: #1565c0; }
.status-preparing { background: #fff3e0; color: #e65100; }
.status-trial { background: #fce4ec; color: #c62828; }
.status-verdict { background: #e8f5e9; color: #2e7d32; }
.status-appealed { background: #f3e5f5; color: #6a1b9a; }
.status-archived { background: var(--gray-200); color: var(--gray-500); }

.btn-link {
    color: var(--primary);
    text-decoration: none;
    padding: 0.25rem 0.5rem;
}

.btn-link:hover {
    text-decoration: underline;
}

.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: 0.75rem;
}

.empty-state {
    text-align: center;
    padding: 2rem;
    color: var(--gray-500);
}

.empty-state i {
    font-size: 2rem;
    margin-bottom: 0.5rem;
    opacity: 0.5;
}

.empty-state p {
    margin-bottom: 1rem;
}

.quick-actions {
    background: white;
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    box-shadow: var(--shadow);
}

.quick-actions h3 {
    font-size: 1rem;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.actions-grid {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.action-card {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    background: var(--gray-50);
    border-radius: var(--radius);
    color: var(--gray-700);
    text-decoration: none;
    transition: all 0.2s;
}

.action-card:hover {
    background: var(--gray-100);
    transform: translateY(-2px);
}

.action-card i {
    font-size: 1rem;
}

/* Статистика прокурора */
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
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--primary);
}

.stat-mini-label {
    font-size: 0.7rem;
    color: var(--gray-600);
    margin-top: 0.25rem;
}

.compact-more {
    margin-top: 0.75rem;
    padding-top: 0.5rem;
    text-align: right;
    border-top: 1px solid var(--gray-200);
}

/* Компактный список доверителей */
.clients-compact-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.client-compact-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem;
    background: var(--gray-50);
    border-radius: var(--radius);
    transition: all 0.2s;
}

.client-compact-item:hover {
    background: var(--gray-100);
}

.client-compact-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    overflow: hidden;
    background: var(--gray-200);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.client-compact-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.client-compact-avatar i {
    font-size: 1.5rem;
    color: var(--gray-500);
}

.client-compact-info {
    flex: 1;
}

.client-compact-name {
    font-weight: 600;
    margin-bottom: 0.125rem;
}

.client-compact-name a {
    color: var(--primary);
    text-decoration: none;
    font-size: 0.875rem;
}

.client-compact-name a:hover {
    text-decoration: underline;
}

.client-compact-stats {
    font-size: 0.65rem;
    color: var(--gray-500);
}

.client-compact-actions {
    display: flex;
    gap: 0.5rem;
}

.date-cell {
    font-family: monospace;
    font-size: 0.75rem;
    white-space: nowrap;
}

/* Responsive */
@media (max-width: 768px) {
    .dashboard-grid {
        grid-template-columns: 1fr;
    }
    
    .dashboard-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .dashboard-stats {
        width: 100%;
        justify-content: space-between;
    }
    
    .stat-card {
        flex: 1;
        min-width: auto;
    }
    
    .stats-mini {
        flex-direction: column;
        gap: 0.5rem;
    }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>