<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $stmt = $db->query("SELECT * FROM service_types ORDER BY name");
        echo json_encode($stmt->fetchAll());
        break;

    case 'save':
        requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE service_types SET name=?, icon=?, color=? WHERE id=?");
            $stmt->execute([$data['name'], $data['icon'] ?? '', $data['color'] ?? '#F38E21', $id]);
        } else {
            $stmt = $db->prepare("INSERT INTO service_types (name, icon, color) VALUES (?, ?, ?)");
            $stmt->execute([$data['name'], $data['icon'] ?? '', $data['color'] ?? '#F38E21']);
            $id = $db->lastInsertId();
        }
        echo json_encode(['success' => true, 'id' => $id]);
        break;

    case 'delete':
        requireAdmin();
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM service_types WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non valide']);
}
