<?php
// includes/upload_functions.php
// Функции для безопасной загрузки файлов

function validateUploadedFile($file, $max_size_mb = 10) {
    $allowed_types = [
        'image/jpeg', 'image/png', 'image/gif',
        'video/mp4', 'video/webm',
        'text/plain'
    ];
    
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'webm', 'txt', 'log'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Ошибка при загрузке файла'];
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) {
        return ['success' => false, 'message' => 'Недопустимый тип файла'];
    }
    
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_extensions)) {
        return ['success' => false, 'message' => 'Недопустимое расширение файла'];
    }
    
    $max_size = $max_size_mb * 1024 * 1024;
    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => "Файл не должен превышать {$max_size_mb} МБ"];
    }
    
    return ['success' => true];
}