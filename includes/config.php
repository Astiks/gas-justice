<?php
// includes/config.php
// Конфигурация базы данных и основные настройки

// Отключаем ошибки на продакшене (на время разработки можно включить)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================================
// НАСТРОЙКИ СЕССИИ (ДО session_start)
// ============================================

// Продлеваем сессию на 30 дней
ini_set('session.cookie_lifetime', 86400 * 30);
ini_set('session.gc_maxlifetime', 86400 * 30);
session_set_cookie_params(86400 * 30);

// Безопасность сессии
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 1); // Включайте только при HTTPS (у вас HTTPS)

// ============================================
// НАСТРОЙКИ БАЗЫ ДАННЫХ
// ============================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'p984187j_sud');
define('DB_USER', 'p984187j_sud');
define('DB_PASS', 'Google21059');

// ============================================
// НАСТРОЙКИ САЙТА
// ============================================

define('SITE_URL', 'https://motion-ums.ru');
define('SITE_NAME', 'ГАС «Юстиция»');
define('ADMIN_EMAIL', 'admin@justice.motion-project.ru');

// ============================================
// ФУНКЦИИ (ОБЪЯВЛЯЕМ ДО ИХ ВЫЗОВА)
// ============================================

// Подключение к базе данных
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec("SET NAMES utf8mb4");
        } catch (PDOException $e) {
            die('Ошибка подключения к базе данных: ' . $e->getMessage());
        }
    }
    
    return $pdo;
}

// Функция для проверки авторизации
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Функция для получения текущего пользователя
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

// Функция для проверки роли
function hasRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }
    
    if (is_string($roles)) {
        $roles = [$roles];
    }
    
    return in_array($user['role'], $roles);
}

// Функция для редиректа
function redirect($url) {
    header('Location: ' . SITE_URL . $url);
    exit;
}

// Функция для CSRF-токенов
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Функция для вывода сообщений
function setFlashMessage($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// ============================================
// СТАРТ СЕССИИ (ПОСЛЕ ОБЪЯВЛЕНИЯ ФУНКЦИЙ)
// ============================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// ПРОВЕРКА "ЗАПОМНИТЬ МЕНЯ"
// ============================================

// Проверяем, что функция checkRememberMe существует, и нет активной сессии
if (!isLoggedIn() && function_exists('checkRememberMe')) {
    checkRememberMe();
}