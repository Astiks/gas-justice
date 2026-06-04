<?php
// search.php
// Поиск дел по номеру, УИД, участникам и типу дела

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Проверка авторизации
if (!isLoggedIn()) {
    redirect('/index.php');
}

$user = getCurrentUser();
$page_title = 'Поиск дел';

$pdo = getDB();
$search_results = null;
$search_performed = false;

// Получаем список типов дел для фильтра
$case_types = getCaseTypes();

// Обработка поискового запроса
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['search']) || isset($_GET['filter']))) {
    $search_performed = true;
    
    // Параметры поиска
    $case_number = trim($_GET['case_number'] ?? '');
    $uid = trim($_GET['uid'] ?? '');
    $participant = trim($_GET['participant'] ?? '');
    $case_type = $_GET['case_type'] ?? '';
    $status = $_GET['status'] ?? '';
    $year = $_GET['year'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    
    // Базовый запрос
    $sql = "
        SELECT c.*, 
               u1.in_game_name as plaintiff_name, u1.id as plaintiff_id,
               u2.in_game_name as defendant_name, u2.id as defendant_id,
               u3.in_game_name as judge_name, u3.id as judge_id,
               u4.in_game_name as prosecutor_name, u4.id as prosecutor_id,
               (SELECT COUNT(*) FROM court_sessions WHERE case_uid = c.uid) as sessions_count
        FROM court_cases c
        LEFT JOIN users u1 ON c.plaintiff_id = u1.id
        LEFT JOIN users u2 ON c.defendant_id = u2.id
        LEFT JOIN users u3 ON c.judge_id = u3.id
        LEFT JOIN users u4 ON c.prosecutor_id = u4.id
        WHERE 1=1
    ";
    
    $params = [];
    
    // Поиск по номеру дела
    if (!empty($case_number)) {
        $sql .= " AND c.case_number_full LIKE ?";
        $params[] = "%$case_number%";
    }
    
    // Поиск по УИД
    if (!empty($uid)) {
        $sql .= " AND c.uid LIKE ?";
        $params[] = "%$uid%";
    }
    
    // Поиск по участнику (истец, ответчик, автор)
    if (!empty($participant)) {
        $sql .= " AND (u1.in_game_name LIKE ? OR u2.in_game_name LIKE ? OR c.created_by IN (SELECT id FROM users WHERE in_game_name LIKE ?))";
        $params[] = "%$participant%";
        $params[] = "%$participant%";
        $params[] = "%$participant%";
    }
    
    // Фильтр по типу дела
    if (!empty($case_type)) {
        $sql .= " AND c.case_type_code = ?";
        $params[] = $case_type;
    }
    
    // Фильтр по статусу
    if (!empty($status)) {
        $sql .= " AND c.status = ?";
        $params[] = $status;
    }
    
    // Фильтр по году
    if (!empty($year)) {
        $sql .= " AND c.case_year = ?";
        $params[] = $year;
    }
    
    // Фильтр по дате создания (от)
    if (!empty($date_from)) {
        $sql .= " AND DATE(c.created_at) >= ?";
        $params[] = $date_from;
    }
    
    // Фильтр по дате создания (до)
    if (!empty($date_to)) {
        $sql .= " AND DATE(c.created_at) <= ?";
        $params[] = $date_to;
    }
    
    // Сортировка
    $sql .= " ORDER BY c.created_at DESC";
    
    // Выполняем запрос
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $search_results = $stmt->fetchAll();
}

// Получение доступных лет для фильтра
$stmt = $pdo->query("SELECT DISTINCT case_year FROM court_cases ORDER BY case_year DESC");
$available_years = $stmt->fetchAll();

// Получение статусов
$statuses = getCaseStatuses();

include __DIR__ . '/includes/header.php';
?>

<div class="search-page">
    <div class="page-header">
        <h1><i class="fas fa-search"></i> Поиск дел</h1>
        <p>Поиск по номеру дела, УИД, участникам или с использованием расширенных фильтров</p>
    </div>
    
    <!-- Быстрый поиск -->
    <div class="search-card quick-search">
        <h3><i class="fas fa-bolt"></i> Быстрый поиск</h3>
        <form method="GET" action="" class="quick-search-form">
            <div class="form-row">
                <div class="form-group">
                    <label for="case_number">Номер дела</label>
                    <input type="text" id="case_number" name="case_number" class="form-control" 
                           placeholder="Пример: 2-001/2026"
                           value="<?php echo isset($_GET['case_number']) ? h($_GET['case_number']) : ''; ?>">
                </div>
                <div class="form-group">
                    <label for="uid">УИД</label>
                    <input type="text" id="uid" name="uid" class="form-control" 
                           placeholder="Уникальный идентификатор дела"
                           value="<?php echo isset($_GET['uid']) ? h($_GET['uid']) : ''; ?>">
                </div>
                <div class="form-group">
                    <label for="participant">Участник дела</label>
                    <input type="text" id="participant" name="participant" class="form-control" 
                           placeholder="Истец, ответчик или автор"
                           value="<?php echo isset($_GET['participant']) ? h($_GET['participant']) : ''; ?>">
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="submit" name="search" value="1" class="btn btn-primary">
                        <i class="fas fa-search"></i> Найти
                    </button>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Расширенный поиск (скрываемый блок) -->
    <div class="search-card advanced-search">
        <div class="advanced-header" onclick="toggleAdvanced()">
            <h3><i class="fas fa-sliders-h"></i> Расширенный поиск</h3>
            <i class="fas fa-chevron-down" id="advancedIcon"></i>
        </div>
        <div id="advancedPanel" style="display: none;">
            <form method="GET" action="" class="advanced-form">
                <input type="hidden" name="filter" value="1">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="case_type">Тип дела</label>
                        <select id="case_type" name="case_type" class="form-control">
                            <option value="">Все типы</option>
                            <?php foreach ($case_types as $code => $name): ?>
                                <option value="<?php echo h($code); ?>" 
                                    <?php echo (isset($_GET['case_type']) && $_GET['case_type'] == $code) ? 'selected' : ''; ?>>
                                    <?php echo h($code) . ' — ' . h($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="status">Статус дела</label>
                        <select id="status" name="status" class="form-control">
                            <option value="">Все статусы</option>
                            <?php foreach ($statuses as $code => $name): ?>
                                <option value="<?php echo h($code); ?>"
                                    <?php echo (isset($_GET['status']) && $_GET['status'] == $code) ? 'selected' : ''; ?>>
                                    <?php echo h($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="year">Год</label>
                        <select id="year" name="year" class="form-control">
                            <option value="">Все годы</option>
                            <?php foreach ($available_years as $y): ?>
                                <option value="<?php echo $y['case_year']; ?>"
                                    <?php echo (isset($_GET['year']) && $_GET['year'] == $y['case_year']) ? 'selected' : ''; ?>>
                                    <?php echo $y['case_year']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="date_from">Дата от</label>
                        <input type="date" id="date_from" name="date_from" class="form-control"
                               value="<?php echo isset($_GET['date_from']) ? h($_GET['date_from']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="date_to">Дата до</label>
                        <input type="date" id="date_to" name="date_to" class="form-control"
                               value="<?php echo isset($_GET['date_to']) ? h($_GET['date_to']) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Применить фильтры
                    </button>
                    <a href="/search.php" class="btn btn-secondary">
                        <i class="fas fa-eraser"></i> Сбросить
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Результаты поиска -->
    <?php if ($search_performed): ?>
        <div class="results-card">
            <div class="results-header">
                <h3><i class="fas fa-list"></i> Результаты поиска</h3>
                <span class="results-count">Найдено: <?php echo count($search_results); ?> дел</span>
            </div>
            
            <?php if (empty($search_results)): ?>
                <div class="empty-state">
                    <i class="fas fa-search"></i>
                    <p>По вашему запросу ничего не найдено</p>
                    <p class="empty-hint">Попробуйте изменить параметры поиска или использовать более общие ключевые слова</p>
                </div>
            <?php else: ?>
                <div class="results-table-wrapper">
                    <table class="results-table">
                        <thead>
                            <tr>
                                <th>Номер дела</th>
                                <th>Тип</th>
                                <th>Истец / Потерпевший</th>
                                <th>Ответчик / Обвиняемый</th>
                                <th>Судья</th>
                                <th>Статус</th>
                                <th>Дата</th>
                                <th>Засед.</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($search_results as $case): ?>
                                <tr class="<?php echo $case['plaintiff_id'] == $user['id'] || $case['defendant_id'] == $user['id'] ? 'my-case' : ''; ?>">
                                    <td class="case-number"><?php echo h($case['case_number_full']); ?></td>
                                    <td>
                                        <span class="case-type-badge" title="<?php echo h($case['case_type_name']); ?>">
                                            <?php echo h($case['case_type_code']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($case['plaintiff_id']): ?>
                                            <a href="/profile.php?id=<?php echo $case['plaintiff_id']; ?>"><?php echo h($case['plaintiff_name'] ?? '—'); ?></a>
                                        <?php else: ?>
                                            <?php echo h($case['plaintiff_name'] ?? '—'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($case['defendant_id']): ?>
                                            <a href="/profile.php?id=<?php echo $case['defendant_id']; ?>"><?php echo h($case['defendant_name'] ?? '—'); ?></a>
                                        <?php else: ?>
                                            <?php echo h($case['defendant_name'] ?? '—'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($case['judge_id']): ?>
                                            <a href="/profile.php?id=<?php echo $case['judge_id']; ?>"><?php echo h($case['judge_name'] ?? 'Не назначен'); ?></a>
                                        <?php else: ?>
                                            <?php echo h($case['judge_name'] ?? 'Не назначен'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $case['status']; ?>">
                                            <?php echo getStatusName($case['status'], $case['case_type_code']); ?>
                                        </span>
                                    </td>
                                    <td class="date-cell"><?php echo date('d.m.Y', strtotime($case['created_at'])); ?></td>
                                    <td class="sessions-cell">
                                        <?php if ($case['sessions_count'] > 0): ?>
                                            <span class="sessions-count"><?php echo $case['sessions_count']; ?></span>
                                        <?php else: ?>
                                            <span class="sessions-none">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="actions-cell">
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
    <?php endif; ?>
    
    <!-- Статистика системы (всегда видна, если не было поиска) -->
    <?php if (!$search_performed): ?>
    <div class="stats-card">
        <h3><i class="fas fa-chart-bar"></i> Статистика судебной системы</h3>
        <div class="stats-grid">
            <?php
            // Общая статистика
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM court_cases");
            $total_cases = $stmt->fetch()['total'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM court_cases WHERE status = 'trial'");
            $in_trial = $stmt->fetch()['total'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM court_cases WHERE status = 'verdict'");
            $completed = $stmt->fetch()['total'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM court_cases WHERE YEAR(created_at) = YEAR(CURDATE())");
            $this_year = $stmt->fetch()['total'];
            
            $stmt = $pdo->query("SELECT case_type_code, COUNT(*) as cnt FROM court_cases GROUP BY case_type_code");
            $type_stats = $stmt->fetchAll();
            ?>
            
            <div class="stat-item">
                <div class="stat-number"><?php echo $total_cases; ?></div>
                <div class="stat-label">Всего дел</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $in_trial; ?></div>
                <div class="stat-label">В производстве</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $completed; ?></div>
                <div class="stat-label">Завершено</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $this_year; ?></div>
                <div class="stat-label">В этом году</div>
            </div>
        </div>
        
        <div class="type-stats">
            <h4>Распределение по типам дел</h4>
            <div class="type-bars">
                <?php foreach ($type_stats as $stat): ?>
                    <div class="type-bar-item">
                        <span class="type-label"><?php echo h($stat['case_type_code']); ?></span>
                        <div class="bar-container">
                            <div class="bar" style="width: <?php echo $total_cases > 0 ? ($stat['cnt'] / $total_cases * 100) : 0; ?>%"></div>
                        </div>
                        <span class="type-count"><?php echo $stat['cnt']; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleAdvanced() {
    const panel = document.getElementById('advancedPanel');
    const icon = document.getElementById('advancedIcon');
    
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        icon.classList.remove('fa-chevron-down');
        icon.classList.add('fa-chevron-up');
    } else {
        panel.style.display = 'none';
        icon.classList.remove('fa-chevron-up');
        icon.classList.add('fa-chevron-down');
    }
}

// Если есть расширенные параметры в URL — автоматически раскрываем панель
<?php if (isset($_GET['filter']) || isset($_GET['case_type']) || isset($_GET['status']) || isset($_GET['year']) || isset($_GET['date_from']) || isset($_GET['date_to'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('advancedPanel').style.display = 'block';
    document.getElementById('advancedIcon').classList.remove('fa-chevron-down');
    document.getElementById('advancedIcon').classList.add('fa-chevron-up');
});
<?php endif; ?>
</script>

<style>
/* Search Page Styles */
.search-page {
    max-width: 1400px;
    margin: 0 auto;
}

.search-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    margin-bottom: 1.5rem;
    overflow: hidden;
}

.quick-search {
    padding: 1.5rem;
}

.quick-search h3 {
    font-size: 1rem;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.quick-search-form .form-row {
    display: grid;
    grid-template-columns: 2fr 2fr 2fr auto;
    gap: 1rem;
    align-items: flex-end;
}

.quick-search-form .form-group {
    margin-bottom: 0;
}

.advanced-search .advanced-header {
    padding: 1rem 1.5rem;
    background: var(--gray-50);
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: background 0.2s;
}

.advanced-search .advanced-header:hover {
    background: var(--gray-100);
}

.advanced-search .advanced-header h3 {
    font-size: 1rem;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.advanced-form {
    padding: 1.5rem;
    border-top: 1px solid var(--gray-200);
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.results-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    overflow: hidden;
}

.results-header {
    padding: 1rem 1.5rem;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.results-header h3 {
    font-size: 1rem;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.results-count {
    font-size: 0.875rem;
    color: var(--gray-600);
    background: var(--gray-200);
    padding: 0.25rem 0.75rem;
    border-radius: 2rem;
}

.results-table-wrapper {
    overflow-x: auto;
}

.results-table {
    width: 100%;
    border-collapse: collapse;
}

.results-table th {
    padding: 0.875rem 1rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--gray-600);
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
}

.results-table td {
    padding: 0.875rem 1rem;
    font-size: 0.875rem;
    border-bottom: 1px solid var(--gray-100);
    vertical-align: middle;
}

.results-table tr:hover {
    background: var(--gray-50);
}

.results-table tr.my-case {
    background: rgba(26, 58, 92, 0.03);
}

.case-type-badge {
    display: inline-block;
    background: var(--gray-100);
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    font-family: monospace;
    font-weight: 600;
    font-size: 0.75rem;
}

.date-cell {
    font-family: monospace;
    font-size: 0.75rem;
    white-space: nowrap;
}

.sessions-cell {
    text-align: center;
}

.sessions-count {
    display: inline-block;
    background: var(--primary);
    color: white;
    font-size: 0.7rem;
    padding: 0.125rem 0.375rem;
    border-radius: 1rem;
    min-width: 24px;
    text-align: center;
}

.sessions-none {
    color: var(--gray-400);
}

.actions-cell {
    text-align: center;
    white-space: nowrap;
}

/* Stats Card */
.stats-card {
    background: white;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow);
    padding: 1.5rem;
}

.stats-card h3 {
    font-size: 1rem;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid var(--gray-200);
}

.stat-item {
    text-align: center;
    padding: 0.75rem;
    background: var(--gray-50);
    border-radius: var(--radius);
}

.stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: var(--primary);
}

.stat-label {
    font-size: 0.75rem;
    color: var(--gray-600);
    margin-top: 0.25rem;
}

.type-stats h4 {
    font-size: 0.875rem;
    margin-bottom: 1rem;
    color: var(--gray-700);
}

.type-bars {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.type-bar-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.type-label {
    width: 40px;
    font-family: monospace;
    font-weight: 600;
    font-size: 0.875rem;
}

.bar-container {
    flex: 1;
    height: 8px;
    background: var(--gray-200);
    border-radius: 4px;
    overflow: hidden;
}

.bar {
    height: 100%;
    background: var(--primary);
    border-radius: 4px;
    transition: width 0.3s ease;
}

.type-count {
    width: 40px;
    font-size: 0.75rem;
    color: var(--gray-600);
    text-align: right;
}

.empty-hint {
    font-size: 0.75rem;
    color: var(--gray-500);
    margin-top: 0.5rem;
}

.results-table td a {
    color: var(--primary);
    text-decoration: none;
}

.results-table td a:hover {
    text-decoration: underline;
}

/* Responsive */
@media (max-width: 768px) {
    .quick-search-form .form-row {
        grid-template-columns: 1fr;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .results-table th,
    .results-table td {
        padding: 0.5rem;
    }
    
    /* Скрываем некоторые столбцы на мобильных */
    .results-table th:nth-child(4),
    .results-table td:nth-child(4),
    .results-table th:nth-child(7),
    .results-table td:nth-child(7) {
        display: none;
    }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>