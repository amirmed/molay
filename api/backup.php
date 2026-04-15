<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$dbPath = DB_PATH;

if (!file_exists($dbPath)) {
    http_response_code(404);
    echo json_encode(['error' => 'قاعدة البيانات غير موجودة']);
    exit;
}

$filename = 'molay_backup_' . date('Y-m-d_H-i') . '.db';

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($dbPath));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Log the backup
logAudit('backup_download', 'database', null, "file=$filename");

readfile($dbPath);
exit;
