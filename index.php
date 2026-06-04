<?php
// index.php
// Страница входа и регистрации (полностью переработанная, широкая форма)

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Если уже авторизован — перенаправляем на дашборд
if (isLoggedIn()) {
    redirect('/dashboard.php');
}

$page_title = 'Вход в систему';
$active_tab = $_GET['tab'] ?? 'login';

// Обработка формы входа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'login':
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Неверный CSRF-токен');
    } else {
        $remember = isset($_POST['remember']) ? true : false;
        $result = loginUser($_POST['login'], $_POST['password'], $remember);
        setFlashMessage($result['success'] ? 'success' : 'error', $result['message']);
        
        if ($result['success']) {
            redirect('/dashboard.php');
        }
    }
    break;
                
            case 'register':
                if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                    setFlashMessage('error', 'Неверный CSRF-токен');
                } else {
                    if (strlen($_POST['password']) < 6) {
                        setFlashMessage('error', 'Пароль должен быть не менее 6 символов');
                    } elseif ($_POST['password'] !== $_POST['password_confirm']) {
                        setFlashMessage('error', 'Пароли не совпадают');
                    } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $_POST['username'])) {
                        setFlashMessage('error', 'Логин может содержать только латиницу, цифры, _ и -');
                    } else {
                        $result = registerUser(
                            $_POST['username'],
                            $_POST['email'],
                            $_POST['password'],
                            $_POST['in_game_name']
                        );
                        setFlashMessage($result['success'] ? 'success' : 'error', $result['message']);
                        if ($result['success']) {
                            $active_tab = 'login';
                        }
                    }
                }
                break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($page_title); ?> | <?php echo SITE_NAME; ?></title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        /* Сброс стилей для страницы авторизации */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0a2a3e 0%, #1a3a5c 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        
        /* Контейнер - широкая карточка */
        .auth-container {
            width: 100%;
            max-width: 680px;
            margin: 0 auto;
        }
        
        .auth-card {
            background: white;
            border-radius: 28px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        
        /* Шапка */
        .auth-header {
            padding: 2rem;
            text-align: center;
            background: linear-gradient(135deg, #0e2a44 0%, #1a3a5c 100%);
            color: white;
        }
        
        .auth-logo {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.12);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
        }
        
        .auth-logo i {
            font-size: 2.5rem;
            color: #c4a747;
        }
        
        .auth-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            letter-spacing: -0.5px;
        }
        
        .auth-header p {
            font-size: 0.875rem;
            opacity: 0.8;
        }
        
        /* Вкладки */
        .auth-tabs {
            display: flex;
            border-bottom: 1px solid #e9ecef;
            background: white;
        }
        
        .auth-tab {
            flex: 1;
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            font-size: 1rem;
            font-weight: 600;
            color: #6c757d;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .auth-tab i {
            font-size: 1rem;
        }
        
        .auth-tab:hover {
            color: #1a3a5c;
            background: #f8f9fa;
        }
        
        .auth-tab.active {
            color: #1a3a5c;
            border-bottom: 2px solid #1a3a5c;
        }
        
        /* Панели форм */
        .auth-panel {
            display: none;
            padding: 2rem;
        }
        
        .auth-panel.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Формы */
        .auth-form {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .form-group label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #343a40;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-group label i {
            width: 1.25rem;
            color: #adb5bd;
            font-size: 0.875rem;
        }
        
        .form-control {
            padding: 0.875rem 1rem;
            border: 1px solid #dee2e6;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.2s;
            font-family: inherit;
            background: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #1a3a5c;
            box-shadow: 0 0 0 3px rgba(26, 58, 92, 0.1);
        }
        
        .form-control::placeholder {
            color: #ced4da;
        }
        
        .form-group small {
            font-size: 0.7rem;
            color: #6c757d;
            margin-top: -0.25rem;
        }
        
        /* Две колонки */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        /* Кнопки */
        .btn {
            padding: 0.875rem 1.5rem;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-family: inherit;
        }
        
        .btn-primary {
            background: #1a3a5c;
            color: white;
        }
        
        .btn-primary:hover {
            background: #0e2a44;
            transform: translateY(-1px);
        }
        
        .btn-block {
            width: 100%;
        }
        
        /* Сообщения */
        .alert {
            padding: 0.875rem 1rem;
            border-radius: 12px;
            margin-bottom: 1.25rem;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .alert-success {
            background: #d1e7dd;
            color: #0a3622;
            border: 1px solid #a3cfbb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #58151c;
            border: 1px solid #f1aeb5;
        }
        
        .alert i {
            font-size: 1.125rem;
        }
        
        /* Подвал */
        .auth-footer {
            text-align: center;
            padding: 1rem 2rem;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            font-size: 0.75rem;
            color: #6c757d;
        }
        
        /* Адаптивность */
        @media (max-width: 680px) {
            body {
                padding: 1rem;
            }
            
            .auth-header {
                padding: 1.5rem;
            }
            
            .auth-header h1 {
                font-size: 1.5rem;
            }
            
            .auth-panel {
                padding: 1.5rem;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .auth-tab {
                padding: 0.75rem;
                font-size: 0.875rem;
            }
        }
        
        @media (min-width: 681px) and (max-width: 900px) {
            .auth-container {
                max-width: 560px;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="auth-logo">
                    <i class="fas fa-gavel"></i>
                </div>
                <h1><?php echo SITE_NAME; ?></h1>
                <p>Государственная автоматизированная система правосудия</p>
            </div>
            
            <div class="auth-tabs">
                <button class="auth-tab <?php echo $active_tab === 'login' ? 'active' : ''; ?>" data-tab="login">
                    <i class="fas fa-sign-in-alt"></i> Вход
                </button>
                <button class="auth-tab <?php echo $active_tab === 'register' ? 'active' : ''; ?>" data-tab="register">
                    <i class="fas fa-user-plus"></i> Регистрация
                </button>
            </div>
            
            <!-- Форма входа -->
<div class="auth-panel <?php echo $active_tab === 'login' ? 'active' : ''; ?>" id="login-panel">
    <?php
    $flash = getFlashMessage();
    if ($flash):
    ?>
    <div class="alert alert-<?php echo h($flash['type']); ?>">
        <i class="fas <?php echo $flash['type'] === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
        <span><?php echo h($flash['message']); ?></span>
    </div>
    <?php endif; ?>
    
    <form method="POST" action="" class="auth-form">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        
        <div class="form-group">
            <label for="login"><i class="fas fa-user"></i> Логин или Email</label>
            <input type="text" id="login" name="login" class="form-control" 
                   placeholder="Введите ваш логин или email" required autofocus>
        </div>
        
        <div class="form-group">
            <label for="password"><i class="fas fa-lock"></i> Пароль</label>
            <input type="password" id="password" name="password" class="form-control" 
                   placeholder="Введите пароль" required>
        </div>
        
        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox" name="remember" value="1">
                <span><i class="fas fa-check-circle"></i> Запомнить меня</span>
            </label>
        </div>
        
        <button type="submit" class="btn btn-primary btn-block">
            <i class="fas fa-sign-in-alt"></i> Войти
        </button>
    </form>
</div>
            
            <!-- Форма регистрации -->
            <div class="auth-panel <?php echo $active_tab === 'register' ? 'active' : ''; ?>" id="register-panel">
                <form method="POST" action="" class="auth-form">
                    <input type="hidden" name="action" value="register">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="username"><i class="fas fa-user-circle"></i> Логин</label>
                            <input type="text" id="username" name="username" class="form-control" 
                                   placeholder="Только латиница" required>
                            <small>Только латиница, цифры, _ и -</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="in_game_name"><i class="fas fa-gamepad"></i> Игровой ник</label>
                            <input type="text" id="in_game_name" name="in_game_name" class="form-control" 
                                   placeholder="Ваш игровой ник" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="email"><i class="fas fa-envelope"></i> Email</label>
                        <input type="email" id="email" name="email" class="form-control" 
                               placeholder="example@mail.ru" required>
                        <small>На этот адрес будут приходить уведомления</small>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="password"><i class="fas fa-lock"></i> Пароль</label>
                            <input type="password" id="password" name="password" class="form-control" 
                               placeholder="Минимум 6 символов" required>
                            <small>Минимум 6 символов</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="password_confirm"><i class="fas fa-check-circle"></i> Подтверждение</label>
                            <input type="password" id="password_confirm" name="password_confirm" class="form-control" 
                                   placeholder="Повторите пароль" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-block">
                        <i class="fas fa-user-plus"></i> Зарегистрироваться
                    </button>
                </form>
            </div>
            
            <div class="auth-footer">
                <p>ГАС «Юстиция» — официальная судебная система Motion Project</p>
            </div>
        </div>
    </div>
    
    <script>
        // Переключение между вкладками
        document.querySelectorAll('.auth-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const tabName = this.dataset.tab;
                
                document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.auth-panel').forEach(p => p.classList.remove('active'));
                
                this.classList.add('active');
                document.getElementById(tabName + '-panel').classList.add('active');
                
                const url = new URL(window.location.href);
                url.searchParams.set('tab', tabName);
                window.history.pushState({}, '', url);
            });
        });
    </script>
</body>
</html>