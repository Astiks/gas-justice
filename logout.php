<?php
// logout.php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

logoutUser();
setFlashMessage('success', 'Вы успешно вышли из системы');
redirect('/index.php');