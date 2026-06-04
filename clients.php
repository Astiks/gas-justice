<?php
// clients.php
// Управление доверителями для адвоката

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Проверка авторизации
if (!isLoggedIn()) {
    redirect('/index.php');
}

$user = getCurrentUser();

// Только адвокат или председатель имеют доступ
if ($user['role'] !== 'lawyer' && $user['role'] !== 'chairman') {
    setFlashMessage('error', 'Доступ запрещён. Только для адвокатов.');
    redirect('/dashboard.php');
}

$page_title = 'Мои доверители';
$pdo = getDB();
$errors = [];
$success = [];

// Обработка добавления доверителя
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Неверный CSRF-токен';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'add_client') {
            $client_id = intval($_POST['client_id'] ?? 0);
            if ($client_id) {
                // Проверяем, что пользователь существует и не является самим собой
                $stmt = $pdo->prepare("SELECT id, in_game_name FROM users WHERE id = ? AND role = 'citizen'");
                $stmt->execute([$client_id]);
                $client = $stmt->fetch();
                
                if (!$client) {
                    $errors[] = 'Пользователь не найден или не является гражданином';
                } elseif ($client_id == $user['id']) {
                    $errors[] = 'Нельзя добавить самого себя в доверители';
                } else {
                    if (addClient($user['id'], $client_id)) {
                        $success[] = "Доверитель {$client['in_game_name']} добавлен";
                        auditLog($user['id'], 'add_client', null, null, "client_id: $client_id");
                    } else {
                        $errors[] = 'Этот доверитель уже добавлен';
                    }
                }
            } else {
                $errors[] = 'Выберите пользователя';
            }
        } elseif ($action === 'remove_client') {
            $client_id = intval($_POST['client_id'] ?? 0);
            if ($client_id) {
                $stmt = $pdo->prepare("SELECT in_game_name FROM users WHERE id = ?");
                $stmt->execute([$client_id]);
                $client = $stmt->fetch();
                
                if (removeClient($user['id'], $client_id)) {
                    $success[] = "Доверитель {$client['in_game_name']} удалён";
                    auditLog($user['id'], 'remove_client', null, null, "client_id: $client_id");
                } else {
                    $errors[] = 'Ошибка при удалении';
                }
            }
        }
    }
}

// Получаем список доверителей
$clients = getLawyerClients($user['id']);

// Получаем список всех граждан для добавления
$all_citizens = getAllUsers($user['id']);

include __DIR__ . '/includes/header.php';
?>

<div class="clients-page">
    <div class="page-header">
        <h1><i class="fas fa-user-tie"></i> Мои доверители</h1>
        <p>Управление списком граждан, которых вы представляете</p>
    </div>
    
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
    
    <div class="clients-grid">
        <!-- Список доверителей -->
        <div class="clients-card">
            <h3><i class="fas fa-users"></i> Мои доверители</h3>
            <?php if (empty($clients)): ?>
                <div class="empty-state">
                    <i class="fas fa-user-friends"></i>
                    <p>У вас пока нет доверителей</p>
                    <p class="empty-hint">Добавьте граждан, которых вы представляете</p>
                </div>
            <?php else: ?>
                <div class="clients-list">
                    <?php foreach ($clients as $client): ?>
                        <div class="client-item">
                            <div class="client-avatar">
                                <?php if ($client['avatar'] && file_exists(__DIR__ . '/' . $client['avatar'])): ?>
                                    <img src="<?php echo SITE_URL . '/' . $client['avatar']; ?>" alt="Аватар">
                                <?php else: ?>
                                    <i class="fas fa-user-circle"></i>
                                <?php endif; ?>
                            </div>
                            <div class="client-info">
                                <div class="client-name">
                                    <a href="/profile.php?id=<?php echo $client['id']; ?>"><?php echo h($client['in_game_name']); ?></a>
                                </div>
                                <div class="client-meta">
                                    <span class="client-login"><?php echo h($client['username']); ?></span>
                                    <span class="client-cases">📋 <?php echo $client['cases_count']; ?> дел</span>
                                </div>
                            </div>
                            <div class="client-actions">
                                <a href="/search.php?participant=<?php echo urlencode($client['in_game_name']); ?>" class="btn-icon" title="Дела доверителя">
                                    <i class="fas fa-folder-open"></i>
                                </a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить доверителя <?php echo h($client['in_game_name']); ?>?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="remove_client">
                                    <input type="hidden" name="client_id" value="<?php echo $client['id']; ?>">
                                    <button type="submit" class="btn-icon delete" title="Удалить">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Добавление доверителя -->
        <div class="clients-card">
            <h3><i class="fas fa-user-plus"></i> Добавить доверителя</h3>
            <form method="POST" class="add-client-form">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="add_client">
                
                <div class="form-group">
                    <label for="client_id">Выберите гражданина</label>
                    <select name="client_id" id="client_id" class="form-control" required>
                        <option value="">-- Выберите пользователя --</option>
                        <?php foreach ($all_citizens as $citizen): ?>
                            <option value="<?php echo $citizen['id']; ?>">
                                <?php echo h($citizen['in_game_name']) . ' (' . h($citizen['username']) . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Добавить доверителя
                </button>
            </form>
            
            <div class="info-note">
                <i class="fas fa-info-circle"></i>
                <span>После добавления доверителя вам станут доступны все его дела для ознакомления и представительства.</span>
            </div>
        </div>
    </div>
    
    <!-- Дела доверителей -->
    <div class="clients-card full-width">
        <h3><i class="fas fa-gavel"></i> Дела моих доверителей</h3>
        <?php
        $all_client_cases = [];
        foreach ($clients as $client) {
            $client_cases = getClientCases($client['id'], 5);
            foreach ($client_cases as $case) {
                $case['client_name'] = $client['in_game_name'];
                $case['client_id'] = $client['id'];
                $all_client_cases[] = $case;
            }
        }
        // Сортируем по дате
        usort($all_client_cases, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        $all_client_cases = array_slice($all_client_cases, 0, 20);
        ?>
        
        <?php if (empty($all_client_cases)): ?>
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <p>У ваших доверителей пока нет дел</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="clients-cases-table">
                    <thead>
                        <tr>
                            <th>Доверитель</th>
                            <th>Номер дела</th>
                            <th>Тип</th>
                            <th>Статус</th>
                            <th>Дата</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_client_cases as $case): ?>
                            <tr>
                                <td>
                                    <a href="/profile.php?id=<?php echo $case['client_id']; ?>"><?php echo h($case['client_name']); ?></a>
                                 </td>
                                <td class="case-number"><?php echo h($case['case_number_full']); ?> </td>
                                <td class="case-type"><?php echo h($case['case_type_name']); ?> </td>
                                <td>
                                    <span class="status-badge status-<?php echo $case['status']; ?>">
                                        <?php echo getStatusName($case['status'], $case['case_type_code']); ?>
                                    </span>
                                </td>
                                <td class="date-cell"><?php echo date('d.m.Y', strtotime($case['created_at'])); ?> </td>
                                <td class="action-cell">
                                    <a href="/case.php?uid=<?php echo urlencode($case['uid']); ?>" class="btn-link" title="Просмотреть дело">
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

<style>
.clients-page {
    max-width: 1200px;
    margin: 0 auto;
}

.clients-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.clients-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    padding: 1.5rem;
}

.clients-card.full-width {
    grid-column: 1 / -1;
}

.clients-card h3 {
    font-size: 1rem;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid var(--primary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.clients-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    max-height: 400px;
    overflow-y: auto;
}

.client-item {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem;
    background: var(--gray-50);
    border-radius: var(--radius);
    transition: all 0.2s;
}

.client-item:hover {
    background: var(--gray-100);
}

.client-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    overflow: hidden;
    background: var(--gray-200);
    display: flex;
    align-items: center;
    justify-content: center;
}

.client-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.client-avatar i {
    font-size: 2rem;
    color: var(--gray-500);
}

.client-info {
    flex: 1;
}

.client-name {
    font-weight: 600;
    margin-bottom: 0.25rem;
}

.client-name a {
    color: var(--primary);
    text-decoration: none;
}

.client-name a:hover {
    text-decoration: underline;
}

.client-meta {
    display: flex;
    gap: 1rem;
    font-size: 0.7rem;
    color: var(--gray-500);
}

.client-actions {
    display: flex;
    gap: 0.5rem;
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

.add-client-form {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.info-note {
    margin-top: 1rem;
    padding: 0.75rem;
    background: #e8f0fe;
    border-radius: var(--radius);
    font-size: 0.75rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--primary);
}

.clients-cases-table {
    width: 100%;
    border-collapse: collapse;
}

.clients-cases-table th {
    text-align: left;
    padding: 0.75rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.clients-cases-table td {
    padding: 0.75rem 0.5rem;
    font-size: 0.875rem;
    border-bottom: 1px solid var(--gray-100);
}

.clients-cases-table tr:hover {
    background: var(--gray-50);
}

.case-number {
    font-family: monospace;
    font-weight: 600;
    color: var(--primary);
    white-space: nowrap;
}

.case-type {
    max-width: 250px;
    white-space: normal;
    word-break: break-word;
}

.date-cell {
    font-family: monospace;
    font-size: 0.75rem;
    white-space: nowrap;
}

.action-cell {
    text-align: center;
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

.empty-hint {
    font-size: 0.75rem;
    margin-top: 0.5rem;
}

@media (max-width: 768px) {
    .clients-grid {
        grid-template-columns: 1fr;
    }
    
    .client-item {
        flex-wrap: wrap;
    }
    
    .client-actions {
        width: 100%;
        justify-content: flex-end;
    }
    
    .clients-cases-table th:nth-child(3),
    .clients-cases-table td:nth-child(3) {
        display: none;
    }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>