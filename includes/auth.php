<?php
// includes/auth.php
// Функции регистрации, входа и выхода (с поддержкой "Запомнить меня")

// Генерация токена для "запомнить меня"
function generateRememberToken() {
    return bin2hex(random_bytes(32));
}

// Установка cookie "запомнить меня"
function setRememberMeCookie($user_id, $token) {
    $pdo = getDB();
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    
    $stmt = $pdo->prepare("UPDATE users SET remember_token = ?, token_expires = ? WHERE id = ?");
    $stmt->execute([$token, $expires, $user_id]);
    
    // Устанавливаем cookie на 30 дней
    setcookie('remember_token', $token, time() + 86400 * 30, '/', '', true, true);
}

// Проверка cookie "запомнить меня"
function checkRememberMe() {
    if (isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        $pdo = getDB();
        
        $stmt = $pdo->prepare("
            SELECT id FROM users 
            WHERE remember_token = ? AND token_expires > NOW() AND is_active = 1
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            
            // Получаем данные пользователя для сессии
            $stmt = $pdo->prepare("SELECT role, username, in_game_name FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $user_data = $stmt->fetch();
            
            $_SESSION['user_role'] = $user_data['role'];
            $_SESSION['username'] = $user_data['username'];
            
            // Обновляем время последнего входа
            $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$user['id']]);
            
            // Обновляем токен (новый срок)
            $new_token = generateRememberToken();
            setRememberMeCookie($user['id'], $new_token);
            
            return true;
        }
    }
    return false;
}

// Очистка "запомнить меня" при выходе
function clearRememberMe($user_id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL, token_expires = NULL WHERE id = ?");
    $stmt->execute([$user_id]);
    setcookie('remember_token', '', time() - 3600, '/');
}

// Регистрация нового пользователя
function registerUser($username, $email, $password, $in_game_name) {
    $pdo = getDB();
    
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Пользователь с таким именем или email уже существует'];
    }
    
    $password_hash = password_hash($password, PASSWORD_BCRYPT);
    
    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, password_hash, in_game_name, role)
        VALUES (?, ?, ?, ?, 'citizen')
    ");
    
    try {
        $stmt->execute([$username, $email, $password_hash, $in_game_name]);
        $user_id = $pdo->lastInsertId();
        
        auditLog($user_id, 'user_register', null, null, "username: $username, role: citizen");
        
        return ['success' => true, 'message' => 'Регистрация успешна! Теперь вы можете войти.', 'user_id' => $user_id];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Ошибка при регистрации: ' . $e->getMessage()];
    }
}

// Вход пользователя (с поддержкой "запомнить меня")
function loginUser($login, $password, $remember = false) {
    $pdo = getDB();
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1");
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch();
    
    if (!$user) {
        return ['success' => false, 'message' => 'Неверное имя пользователя или пароль'];
    }
    
    if (!password_verify($password, $user['password_hash'])) {
        auditLog($user['id'], 'login_failed', null, "attempted login: $login");
        return ['success' => false, 'message' => 'Неверное имя пользователя или пароль'];
    }
    
    $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);
    
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['username'] = $user['username'];
    
    if ($remember) {
        $token = generateRememberToken();
        setRememberMeCookie($user['id'], $token);
    }
    
    auditLog($user['id'], 'login_success');
    
    return ['success' => true, 'message' => 'Добро пожаловать, ' . $user['in_game_name'] . '!', 'user' => $user];
}

// Выход пользователя
function logoutUser() {
    if (isLoggedIn()) {
        clearRememberMe($_SESSION['user_id']);
        auditLog($_SESSION['user_id'], 'logout');
    }
    
    $_SESSION = [];
    session_destroy();
    
    return ['success' => true, 'message' => 'Вы вышли из системы'];
}

// Смена пароля
function changePassword($user_id, $old_password, $new_password) {
    $pdo = getDB();
    
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!password_verify($old_password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Неверный текущий пароль'];
    }
    
    if (strlen($new_password) < 6) {
        return ['success' => false, 'message' => 'Новый пароль должен быть не менее 6 символов'];
    }
    
    $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->execute([$new_hash, $user_id]);
    
    auditLog($user_id, 'password_change');
    
    return ['success' => true, 'message' => 'Пароль успешно изменён'];
}