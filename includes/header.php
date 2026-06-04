<?php
// includes/header.php
// Общий заголовок для всех страниц

$current_user = getCurrentUser();
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? h($page_title) . ' | ' : ''; ?><?php echo SITE_NAME; ?></title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>/assets/css/style.css">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo SITE_URL; ?>/assets/favicon.ico">
</head>
<body>
    <div class="app-wrapper">
        <?php if (isLoggedIn()): ?>
        <!-- Навигационная панель -->
        <nav class="navbar">
            <div class="nav-container">
                <a href="<?php echo SITE_URL; ?>/dashboard.php" class="nav-brand">
                    <i class="fas fa-gavel"></i>
                    <span>ГАС «Юстиция»</span>
                </a>
                
                <div class="nav-menu">
                    <a href="<?php echo SITE_URL; ?>/dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Дашборд</span>
                    </a>
                    <a href="<?php echo SITE_URL; ?>/file-case.php" class="nav-link <?php echo $current_page == 'file-case.php' ? 'active' : ''; ?>">
                        <i class="fas fa-file-alt"></i>
                        <span>Подать обращение</span>
                    </a>
                    <a href="<?php echo SITE_URL; ?>/search.php" class="nav-link <?php echo $current_page == 'search.php' ? 'active' : ''; ?>">
                        <i class="fas fa-search"></i>
                        <span>Поиск дел</span>
                    </a>
                    <a href="<?php echo SITE_URL; ?>/notifications.php" class="nav-link <?php echo $current_page == 'notifications.php' ? 'active' : ''; ?>">
                        <i class="fas fa-bell"></i>
                        <span>Уведомления</span>
                    </a>
                    
                    <!-- Ссылка на доверителей для адвокатов -->
                    <?php if ($current_user && $current_user['role'] == 'lawyer'): ?>
                    <a href="<?php echo SITE_URL; ?>/clients.php" class="nav-link <?php echo $current_page == 'clients.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user-tie"></i>
                        <span>Доверители</span>
                    </a>
                    <?php endif; ?>
                    
                    <!-- Админка — видна только председателю суда -->
                    <?php if ($current_user && $current_user['role'] == 'chairman'): ?>
                    <a href="<?php echo SITE_URL; ?>/admin/" class="nav-link admin-link">
                        <i class="fas fa-shield-alt"></i>
                        <span>Админ-панель</span>
                    </a>
                    <?php endif; ?>
                </div>
                
                <div class="nav-user">
                    <div class="user-dropdown">
                        <button class="user-dropdown-btn">
                            <?php if ($current_user['avatar'] && file_exists(__DIR__ . '/' . $current_user['avatar'])): ?>
                                <img src="<?php echo SITE_URL . '/' . $current_user['avatar']; ?>" alt="Аватар" style="width: 24px; height: 24px; border-radius: 50%; object-fit: cover;">
                            <?php else: ?>
                                <i class="fas fa-user-circle"></i>
                            <?php endif; ?>
                            <span><?php echo h($current_user['in_game_name'] ?? $current_user['username']); ?></span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="user-dropdown-menu">
                            <a href="<?php echo SITE_URL; ?>/profile.php">
                                <i class="fas fa-id-card"></i> Профиль
                            </a>
                            <a href="<?php echo SITE_URL; ?>/logout.php">
                                <i class="fas fa-sign-out-alt"></i> Выйти
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </nav>
        <?php endif; ?>
        
        <main class="main-content">
            <div class="container">
                <?php
                // Вывод flash-сообщений
                $flash = getFlashMessage();
                if ($flash):
                ?>
                <div class="alert alert-<?php echo h($flash['type']); ?> alert-dismissible">
                    <span><?php echo h($flash['message']); ?></span>
                    <button class="alert-close">&times;</button>
                </div>
                <?php endif; ?>