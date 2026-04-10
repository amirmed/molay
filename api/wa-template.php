<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

$db = getDB();

// Ensure table exists
$db->exec("CREATE TABLE IF NOT EXISTS app_settings (key TEXT PRIMARY KEY, value TEXT)");

$action = $_GET['action'] ?? '';

switch ($action) {

    case 'get':
        $stmt = $db->prepare("SELECT value FROM app_settings WHERE key = 'wa_header'");
        $stmt->execute();
        $header = $stmt->fetch();

        $stmt = $db->prepare("SELECT value FROM app_settings WHERE key = 'wa_footer'");
        $stmt->execute();
        $footer = $stmt->fetch();

        echo json_encode([
            'header' => $header ? $header['value'] : '',
            'footer' => $footer ? $footer['value'] : '',
        ]);
        break;

    case 'save':
        requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true);

        $stmt = $db->prepare("INSERT INTO app_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");

        if (isset($data['header'])) {
            $stmt->execute(['wa_header', $data['header']]);
        }
        if (isset($data['footer'])) {
            $stmt->execute(['wa_footer', $data['footer']]);
        }

        echo json_encode(['success' => true]);
        break;

    case 'reset':
        requireAdmin();
        $db->exec("DELETE FROM app_settings WHERE key IN ('wa_header', 'wa_footer')");
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non valide']);
}
