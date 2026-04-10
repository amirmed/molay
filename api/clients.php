<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {

    // Search clients by name or meter number
    case 'search':
        $q = '%' . trim($_GET['q'] ?? '') . '%';
        $stmt = $db->prepare("
            SELECT DISTINCT c.* FROM clients c
            LEFT JOIN meters m ON m.client_id = c.id
            WHERE c.full_name LIKE ? OR m.meter_number LIKE ? OR m.label LIKE ? OR m.nopolice LIKE ?
            ORDER BY c.full_name
            LIMIT 20
        ");
        $stmt->execute([$q, $q, $q, $q]);
        echo json_encode($stmt->fetchAll());
        break;

    // Get all clients
    case 'list':
        $stmt = $db->query("
            SELECT c.*, COUNT(m.id) as meter_count
            FROM clients c
            LEFT JOIN meters m ON m.client_id = c.id
            GROUP BY c.id
            ORDER BY c.full_name
        ");
        echo json_encode($stmt->fetchAll());
        break;

    // Get single client with meters
    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM clients WHERE id = ?");
        $stmt->execute([$id]);
        $client = $stmt->fetch();
        if (!$client) {
            http_response_code(404);
            echo json_encode(['error' => 'Client introuvable']);
            break;
        }
        // Get meters with service info
        $stmt = $db->prepare("
            SELECT m.*, st.name as service_name, st.icon as service_icon, st.color as service_color
            FROM meters m
            JOIN service_types st ON st.id = m.service_type_id
            WHERE m.client_id = ?
            ORDER BY st.name, m.label
        ");
        $stmt->execute([$id]);
        $client['meters'] = $stmt->fetchAll();
        echo json_encode($client);
        break;

    // Create or Update client
    case 'save':
        requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);

        try {
            $db->beginTransaction();

            if ($id > 0) {
                // Update
                $stmt = $db->prepare("UPDATE clients SET full_name=?, phone=?, address=?, notes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
                $stmt->execute([$data['full_name'], $data['phone'] ?? '', $data['address'] ?? '', $data['notes'] ?? '', $id]);
            } else {
                // Insert
                $stmt = $db->prepare("INSERT INTO clients (full_name, phone, address, notes) VALUES (?, ?, ?, ?)");
                $stmt->execute([$data['full_name'], $data['phone'] ?? '', $data['address'] ?? '', $data['notes'] ?? '']);
                $id = $db->lastInsertId();
            }

            // Handle meters
            if (isset($data['meters'])) {
                $existingIds = [];
                foreach ($data['meters'] as $meter) {
                    $meterId = (int)($meter['id'] ?? 0);
                    if ($meterId > 0) {
                        $stmt = $db->prepare("UPDATE meters SET service_type_id=?, label=?, meter_number=?, nopolice=?, is_active=? WHERE id=? AND client_id=?");
                        $stmt->execute([$meter['service_type_id'], $meter['label'], $meter['meter_number'] ?? '', $meter['nopolice'] ?? '', $meter['is_active'] ?? 1, $meterId, $id]);
                        $existingIds[] = $meterId;
                    } else {
                        $stmt = $db->prepare("INSERT INTO meters (client_id, service_type_id, label, meter_number, nopolice) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$id, $meter['service_type_id'], $meter['label'], $meter['meter_number'] ?? '', $meter['nopolice'] ?? '']);
                        $existingIds[] = $db->lastInsertId();
                    }
                }
                // Delete removed meters
                if (!empty($existingIds)) {
                    $placeholders = implode(',', array_fill(0, count($existingIds), '?'));
                    $stmt = $db->prepare("DELETE FROM meters WHERE client_id = ? AND id NOT IN ($placeholders)");
                    $stmt->execute(array_merge([$id], $existingIds));
                }
            }

            $db->commit();
            echo json_encode(['success' => true, 'id' => $id]);
        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Delete client
    case 'delete':
        requireAdmin();
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM clients WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non valide']);
}
