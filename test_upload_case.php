<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!isLoggedIn()) {
    die('Не авторизован');
}

$uid = $_GET['uid'] ?? '';
if (!$uid) {
    die('Нет UID дела');
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM court_cases WHERE uid = ?");
$stmt->execute([$uid]);
$case = $stmt->fetch();

if (!$case) {
    die('Дело не найдено');
}

$user = getCurrentUser();
$can_upload = ($user['id'] == $case['plaintiff_id'] || $user['id'] == $case['defendant_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<pre>";
    echo "POST received\n";
    echo "can_upload: " . ($can_upload ? 'true' : 'false') . "\n";
    echo "FILES: " . print_r($_FILES, true) . "\n";
    
    if ($can_upload && !empty($_FILES['evidence_file']['name'])) {
        $upload_dir = __DIR__ . '/uploads/';
        $file = $_FILES['evidence_file'];
        $safe_name = $uid . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
        $target = $upload_dir . $safe_name;
        
        if (move_uploaded_file($file['tmp_name'], $target)) {
            $stmt = $pdo->prepare("
                INSERT INTO evidence (case_uid, evidence_type, title, file_path, uploaded_by)
                VALUES (?, 'document', ?, ?, ?)
            ");
            $stmt->execute([$uid, $file['name'], 'uploads/' . $safe_name, $user['id']]);
            echo "✅ Файл загружен!\n";
        } else {
            echo "❌ Ошибка перемещения файла\n";
        }
    }
    echo "</pre>";
    echo '<a href="/test_upload_case.php?uid=' . urlencode($uid) . '">Назад</a>';
    exit;
}
?>

<h2>Тест загрузки доказательств</h2>
<p>Дело: <?php echo htmlspecialchars($case['case_number_full']); ?></p>
<p>Вы <?php echo $can_upload ? 'можете' : 'НЕ можете'; ?> загружать доказательства</p>

<form method="POST" enctype="multipart/form-data">
    <input type="file" name="evidence_file" required>
    <button type="submit">Загрузить</button>
</form>

<a href="/case.php?uid=<?php echo urlencode($uid); ?>">Вернуться к делу</a>