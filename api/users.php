<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireAdmin();

$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $stmt = $db->query("SELECT id, username, full_name, role, created_at FROM users ORDER BY full_name");
        echo json_encode($stmt->fetchAll());
        break;

    case 'save':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        $newRole = $data['role'] ?? 'assistant';

        // Prevent demoting the last admin
        if ($id > 0 && $newRole !== 'admin') {
            $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $current = $stmt->fetch();
            if ($current && $current['role'] === 'admin') {
                $adminCount = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
                if ($adminCount <= 1) {
                    http_response_code(400);
                    echo json_encode(['error' => 'لا يمكن تغيير صلاحية آخر مدير. أضف مدير آخر أولاً.']);
                    break;
                }
            }
        }

        if ($id > 0) {
            if (!empty($data['password'])) {
                $stmt = $db->prepare("UPDATE users SET full_name=?, username=?, password=?, role=? WHERE id=?");
                $stmt->execute([$data['full_name'], $data['username'], password_hash($data['password'], PASSWORD_DEFAULT), $newRole, $id]);
            } else {
                $stmt = $db->prepare("UPDATE users SET full_name=?, username=?, role=? WHERE id=?");
                $stmt->execute([$data['full_name'], $data['username'], $newRole, $id]);
            }
        } else {
            $stmt = $db->prepare("INSERT INTO users (full_name, username, password, role) VALUES (?, ?, ?, ?)");
            $stmt->execute([$data['full_name'], $data['username'], password_hash($data['password'], PASSWORD_DEFAULT), $newRole]);
            $id = $db->lastInsertId();
        }
        echo json_encode(['success' => true, 'id' => $id]);
        break;

    case 'delete':
        $id = (int)($_GET['id'] ?? 0);
        // Cannot delete yourself
        if ($id == currentUserId()) {
            http_response_code(400);
            echo json_encode(['error' => 'لا يمكنك حذف حسابك الخاص']);
            break;
        }
        // Cannot delete the last admin
        $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if ($target && $target['role'] === 'admin') {
            $adminCount = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($adminCount <= 1) {
                http_response_code(400);
                echo json_encode(['error' => 'لا يمكن حذف آخر مدير في النظام']);
                break;
            }
        }
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non valide']);
}
