<?php
// notifications.php
// Центр уведомлений пользователя (исправленная версия)

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Проверка авторизации
if (!isLoggedIn()) {
    redirect('/index.php');
}

$user = getCurrentUser();
$page_title = 'Уведомления';

$pdo = getDB();

// Обработка действий с уведомлениями
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Неверный CSRF-токен');
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'mark_read':
                $notification_id = intval($_POST['notification_id'] ?? 0);
                $stmt = $pdo->prepare("
                    UPDATE notifications 
                    SET is_read = 1 
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$notification_id, $user['id']]);
                setFlashMessage('success', 'Уведомление отмечено как прочитанное');
                break;
                
            case 'mark_all_read':
                $stmt = $pdo->prepare("
                    UPDATE notifications 
                    SET is_read = 1 
                    WHERE user_id = ? AND is_read = 0
                ");
                $stmt->execute([$user['id']]);
                setFlashMessage('success', 'Все уведомления отмечены как прочитанные');
                break;
                
            case 'delete':
                $notification_id = intval($_POST['notification_id'] ?? 0);
                $stmt = $pdo->prepare("
                    DELETE FROM notifications 
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$notification_id, $user['id']]);
                setFlashMessage('success', 'Уведомление удалено');
                break;
                
            case 'delete_all':
                $stmt = $pdo->prepare("
                    DELETE FROM notifications 
                    WHERE user_id = ?
                ");
                $stmt->execute([$user['id']]);
                setFlashMessage('success', 'Все уведомления удалены');
                break;
        }
    }
    
    redirect('/notifications.php');
}

// Пагинация
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Фильтры
$filter = $_GET['filter'] ?? 'all'; // all, unread, read

// Базовый запрос для подсчёта
$count_sql = "SELECT COUNT(*) as total FROM notifications WHERE user_id = ?";
$count_params = [$user['id']];

if ($filter === 'unread') {
    $count_sql .= " AND is_read = 0";
} elseif ($filter === 'read') {
    $count_sql .= " AND is_read = 1";
}

$stmt = $pdo->prepare($count_sql);
$stmt->execute($count_params);
$total = $stmt->fetch()['total'];
$total_pages = ceil($total / $per_page);

// Запрос для получения уведомлений (исправлен для LIMIT и OFFSET)
$sql = "
    SELECT * FROM notifications 
    WHERE user_id = ? 
";

$params = [$user['id']];

if ($filter === 'unread') {
    $sql .= " AND is_read = 0";
} elseif ($filter === 'read') {
    $sql .= " AND is_read = 1";
}

$sql .= " ORDER BY created_at DESC LIMIT " . intval($per_page) . " OFFSET " . intval($offset);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// Статистика уведомлений
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread
    FROM notifications 
    WHERE user_id = ?
");
$stmt->execute([$user['id']]);
$stats = $stmt->fetch();

include __DIR__ . '/includes/header.php';
?>

<div class="notifications-page">
    <div class="page-header">
        <h1><i class="fas fa-bell"></i> Уведомления</h1>
        <p>История всех уведомлений системы</p>
    </div>
    
    <!-- Статистика и действия -->
    <div class="notifications-toolbar">
        <div class="notifications-stats">
            <span class="stat-badge total">Всего: <?php echo $stats['total']; ?></span>
            <?php if ($stats['unread'] > 0): ?>
                <span class="stat-badge unread">Непрочитанных: <?php echo $stats['unread']; ?></span>
            <?php endif; ?>
        </div>
        
        <div class="notifications-actions">
            <?php if ($stats['unread'] > 0): ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="btn-sm btn-secondary">
                        <i class="fas fa-check-double"></i> Прочитать всё
                    </button>
                </form>
            <?php endif; ?>
            
            <?php if ($stats['total'] > 0): ?>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить все уведомления?');">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="delete_all">
                    <button type="submit" class="btn-sm btn-danger">
                        <i class="fas fa-trash-alt"></i> Удалить всё
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Фильтры -->
    <div class="notifications-filters">
        <a href="?filter=all" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">
            <i class="fas fa-list"></i> Все
        </a>
        <a href="?filter=unread" class="filter-btn <?php echo $filter === 'unread' ? 'active' : ''; ?>">
            <i class="fas fa-envelope"></i> Непрочитанные
            <?php if ($stats['unread'] > 0): ?>
                <span class="count"><?php echo $stats['unread']; ?></span>
            <?php endif; ?>
        </a>
        <a href="?filter=read" class="filter-btn <?php echo $filter === 'read' ? 'active' : ''; ?>">
            <i class="fas fa-check-circle"></i> Прочитанные
        </a>
    </div>
    
    <!-- Список уведомлений -->
    <div class="notifications-list">
        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <i class="fas fa-bell-slash"></i>
                <p>У вас пока нет уведомлений</p>
                <p class="empty-hint">Когда по вашим делам будут изменения, вы увидите их здесь</p>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $notif): ?>
                <div class="notification-item <?php echo !$notif['is_read'] ? 'unread' : ''; ?>">
                    <div class="notification-icon">
                        <?php
                        $icon_map = [
                            'status_change' => 'fa-exchange-alt',
                            'session_scheduled' => 'fa-calendar-alt',
                            'verdict' => 'fa-gavel',
                            'new_case' => 'fa-file-alt',
                            'case_assigned' => 'fa-user-check',
                            'evidence_added' => 'fa-image'
                        ];
                        $icon = $icon_map[$notif['type']] ?? 'fa-bell';
                        ?>
                        <i class="fas <?php echo $icon; ?>"></i>
                    </div>
                    
                    <div class="notification-content">
                        <div class="notification-title">
                            <?php echo h($notif['title']); ?>
                            <?php if (!$notif['is_read']): ?>
                                <span class="new-badge">Новое</span>
                            <?php endif; ?>
                        </div>
                        <div class="notification-message">
                            <?php echo nl2br(h($notif['message'])); ?>
                        </div>
                        <div class="notification-time">
                            <i class="far fa-clock"></i>
                            <?php 
                                $time = strtotime($notif['created_at']);
                                $now = time();
                                $diff = $now - $time;
                                
                                if ($diff < 60) {
                                    echo 'только что';
                                } elseif ($diff < 3600) {
                                    echo floor($diff / 60) . ' минут назад';
                                } elseif ($diff < 86400) {
                                    echo floor($diff / 3600) . ' часов назад';
                                } else {
                                    echo date('d.m.Y H:i', $time);
                                }
                            ?>
                        </div>
                    </div>
                    
                    <div class="notification-actions">
                        <?php if ($notif['link']): ?>
                            <a href="<?php echo h($notif['link']); ?>" class="action-link" title="Перейти">
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        <?php endif; ?>
                        
                        <?php if (!$notif['is_read']): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="notification_id" value="<?php echo $notif['id']; ?>">
                                <button type="submit" class="action-btn" title="Отметить прочитанным">
                                    <i class="fas fa-check"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                        
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить уведомление?');">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="notification_id" value="<?php echo $notif['id']; ?>">
                            <button type="submit" class="action-btn delete-btn" title="Удалить">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Пагинация -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?>&filter=<?php echo urlencode($filter); ?>" class="page-link">
                    <i class="fas fa-chevron-left"></i> Назад
                </a>
            <?php endif; ?>
            
            <span class="page-info">Страница <?php echo $page; ?> из <?php echo $total_pages; ?></span>
            
            <?php if ($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?>&filter=<?php echo urlencode($filter); ?>" class="page-link">
                    Вперёд <i class="fas fa-chevron-right"></i>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<style>
/* Notifications Page Styles */
.notifications-page {
    max-width: 900px;
    margin: 0 auto;
}

.notifications-toolbar {
    background: white;
    border-radius: var(--radius-lg);
    padding: 1rem 1.5rem;
    margin-bottom: 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    box-shadow: var(--shadow);
}

.notifications-stats {
    display: flex;
    gap: 0.75rem;
}

.stat-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 2rem;
    font-size: 0.75rem;
    font-weight: 500;
}

.stat-badge.total {
    background: var(--gray-100);
    color: var(--gray-700);
}

.stat-badge.unread {
    background: var(--primary);
    color: white;
}

.notifications-actions {
    display: flex;
    gap: 0.5rem;
}

.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: 0.75rem;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    border: none;
    font-family: inherit;
    font-weight: 500;
}

.btn-secondary {
    background: var(--gray-100);
    color: var(--gray-700);
}

.btn-secondary:hover {
    background: var(--gray-200);
}

.btn-danger {
    background: var(--danger);
    color: white;
}

.btn-danger:hover {
    background: #a00;
}

.notifications-filters {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

.filter-btn {
    padding: 0.5rem 1rem;
    background: white;
    border-radius: 2rem;
    text-decoration: none;
    font-size: 0.875rem;
    color: var(--gray-600);
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    box-shadow: var(--shadow-sm);
}

.filter-btn:hover {
    background: var(--gray-100);
    color: var(--primary);
}

.filter-btn.active {
    background: var(--primary);
    color: white;
}

.filter-btn .count {
    background: rgba(255, 255, 255, 0.25);
    padding: 0.125rem 0.375rem;
    border-radius: 1rem;
    font-size: 0.7rem;
}

.notifications-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.notification-item {
    background: white;
    border-radius: var(--radius);
    padding: 1rem 1.5rem;
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    box-shadow: var(--shadow-sm);
    transition: all 0.2s;
    position: relative;
}

.notification-item:hover {
    box-shadow: var(--shadow);
    transform: translateY(-1px);
}

.notification-item.unread {
    background: linear-gradient(135deg, #fff, #e8f0fe);
    border-left: 4px solid var(--primary);
}

.notification-icon {
    width: 40px;
    height: 40px;
    background: var(--gray-100);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.notification-icon i {
    font-size: 1.125rem;
    color: var(--primary);
}

.notification-item.unread .notification-icon {
    background: var(--primary);
}

.notification-item.unread .notification-icon i {
    color: white;
}

.notification-content {
    flex: 1;
}

.notification-title {
    font-weight: 600;
    font-size: 0.875rem;
    margin-bottom: 0.25rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.new-badge {
    background: var(--danger);
    color: white;
    font-size: 0.6rem;
    font-weight: 500;
    padding: 0.125rem 0.5rem;
    border-radius: 1rem;
}

.notification-message {
    font-size: 0.875rem;
    color: var(--gray-700);
    line-height: 1.5;
    margin-bottom: 0.5rem;
}

.notification-time {
    font-size: 0.7rem;
    color: var(--gray-500);
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.notification-actions {
    display: flex;
    gap: 0.5rem;
    flex-shrink: 0;
}

.action-link,
.action-btn {
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    background: var(--gray-100);
    color: var(--gray-600);
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
}

.action-link:hover,
.action-btn:hover {
    background: var(--gray-200);
    color: var(--primary);
}

.delete-btn:hover {
    background: #f8d7da;
    color: var(--danger);
}

/* Пагинация */
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 1rem;
    margin-top: 1.5rem;
    padding: 1rem;
    background: white;
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
}

.page-link {
    padding: 0.5rem 1rem;
    background: var(--gray-100);
    border-radius: var(--radius);
    text-decoration: none;
    color: var(--gray-700);
    font-size: 0.875rem;
    transition: all 0.2s;
}

.page-link:hover {
    background: var(--primary);
    color: white;
}

.page-info {
    font-size: 0.875rem;
    color: var(--gray-600);
}

.empty-state {
    text-align: center;
    padding: 3rem;
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
}

.empty-state i {
    font-size: 3rem;
    color: var(--gray-400);
    margin-bottom: 1rem;
}

.empty-state p {
    color: var(--gray-500);
}

.empty-hint {
    font-size: 0.75rem;
    margin-top: 0.5rem;
}

/* Responsive */
@media (max-width: 768px) {
    .notifications-page {
        max-width: 100%;
    }
    
    .notification-item {
        flex-direction: column;
    }
    
    .notification-actions {
        align-self: flex-end;
    }
    
    .notifications-toolbar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .notifications-stats {
        justify-content: center;
    }
    
    .notifications-actions {
        justify-content: center;
    }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>