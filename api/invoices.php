<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

$db = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {

    // Save or update invoice
    case 'save':
        $data = json_decode(file_get_contents('php://input'), true);
        $meterId = (int)($data['meter_id'] ?? 0);
        $month = (int)($data['month'] ?? 0);
        $year = (int)($data['year'] ?? 0);
        $amount = floatval($data['amount'] ?? 0);
        $notes = $data['notes'] ?? '';

        if ($meterId <= 0 || $month < 1 || $month > 12 || $year < 2020) {
            http_response_code(400);
            echo json_encode(['error' => 'Donnees invalides']);
            break;
        }

        try {
            $stmt = $db->prepare("
                INSERT INTO invoices (meter_id, month, year, amount, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT(meter_id, month, year)
                DO UPDATE SET amount=excluded.amount, notes=excluded.notes
            ");
            $stmt->execute([$meterId, $month, $year, $amount, $notes, currentUserId()]);
            echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Save batch invoices
    case 'save_batch':
        $data = json_decode(file_get_contents('php://input'), true);
        $invoices = $data['invoices'] ?? [];
        $saved = 0;

        try {
            $db->beginTransaction();
            $stmt = $db->prepare("
                INSERT INTO invoices (meter_id, month, year, amount, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT(meter_id, month, year)
                DO UPDATE SET amount=excluded.amount, notes=excluded.notes
            ");
            foreach ($invoices as $inv) {
                if (floatval($inv['amount']) > 0) {
                    $stmt->execute([
                        (int)$inv['meter_id'],
                        (int)$inv['month'],
                        (int)$inv['year'],
                        floatval($inv['amount']),
                        $inv['notes'] ?? '',
                        currentUserId()
                    ]);
                    $saved++;
                }
            }
            $db->commit();
            echo json_encode(['success' => true, 'saved' => $saved]);
        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // Get invoices for a client in a period
    case 'get_client':
        $clientId = (int)($_GET['client_id'] ?? 0);
        $month = (int)($_GET['month'] ?? date('n'));
        $year = (int)($_GET['year'] ?? date('Y'));

        $stmt = $db->prepare("
            SELECT i.*, m.label as meter_label, m.meter_number, st.name as service_name, st.icon as service_icon, st.color as service_color
            FROM invoices i
            JOIN meters m ON m.id = i.meter_id
            JOIN service_types st ON st.id = m.service_type_id
            WHERE m.client_id = ? AND i.month = ? AND i.year = ?
            ORDER BY st.name, m.label
        ");
        $stmt->execute([$clientId, $month, $year]);
        echo json_encode($stmt->fetchAll());
        break;

    // Get invoices for a client across multiple months
    case 'get_client_range':
        $clientId = (int)($_GET['client_id'] ?? 0);
        $months = $_GET['months'] ?? ''; // format: "1-2025,2-2025,3-2025"

        $conditions = [];
        $params = [$clientId];
        foreach (explode(',', $months) as $period) {
            $parts = explode('-', trim($period));
            if (count($parts) === 2) {
                $conditions[] = "(i.month = ? AND i.year = ?)";
                $params[] = (int)$parts[0];
                $params[] = (int)$parts[1];
            }
        }
        if (empty($conditions)) {
            echo json_encode([]);
            break;
        }

        $where = implode(' OR ', $conditions);
        $stmt = $db->prepare("
            SELECT i.*, m.label as meter_label, m.meter_number, st.name as service_name, st.icon as service_icon, st.color as service_color
            FROM invoices i
            JOIN meters m ON m.id = i.meter_id
            JOIN service_types st ON st.id = m.service_type_id
            WHERE m.client_id = ? AND ($where)
            ORDER BY i.year, i.month, st.name
        ");
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
        break;

    // Mark invoice as paid
    case 'mark_paid':
        $id = (int)($_GET['id'] ?? 0);
        $isPaid = (int)($_GET['paid'] ?? 1);
        $stmt = $db->prepare("UPDATE invoices SET is_paid = ?, paid_at = CASE WHEN ? = 1 THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id = ?");
        $stmt->execute([$isPaid, $isPaid, $id]);
        echo json_encode(['success' => true]);
        break;

    // Monthly summary
    case 'summary':
        $month = (int)($_GET['month'] ?? date('n'));
        $year = (int)($_GET['year'] ?? date('Y'));

        // Total per service
        $stmt = $db->prepare("
            SELECT st.name, st.icon, st.color, COUNT(i.id) as invoice_count, COALESCE(SUM(i.amount),0) as total
            FROM service_types st
            LEFT JOIN meters m ON m.service_type_id = st.id
            LEFT JOIN invoices i ON i.meter_id = m.id AND i.month = ? AND i.year = ?
            GROUP BY st.id
            ORDER BY st.name
        ");
        $stmt->execute([$month, $year]);
        $byService = $stmt->fetchAll();

        // Grand total
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) as grand_total, COUNT(*) as total_invoices FROM invoices WHERE month=? AND year=?");
        $stmt->execute([$month, $year]);
        $totals = $stmt->fetch();

        // Recent entries
        $stmt = $db->prepare("
            SELECT i.*, c.full_name, m.label as meter_label, st.name as service_name, st.icon as service_icon
            FROM invoices i
            JOIN meters m ON m.id = i.meter_id
            JOIN clients c ON c.id = m.client_id
            JOIN service_types st ON st.id = m.service_type_id
            WHERE i.month = ? AND i.year = ?
            ORDER BY i.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$month, $year]);
        $recent = $stmt->fetchAll();

        echo json_encode([
            'by_service' => $byService,
            'grand_total' => $totals['grand_total'],
            'total_invoices' => $totals['total_invoices'],
            'recent' => $recent
        ]);
        break;

    // Full report
    case 'report':
        $month = (int)($_GET['month'] ?? date('n'));
        $year = (int)($_GET['year'] ?? date('Y'));
        $serviceId = $_GET['service_id'] ?? 'all';

        $params = [$month, $year];
        $serviceFilter = '';
        if ($serviceId !== 'all') {
            $serviceFilter = 'AND st.id = ?';
            $params[] = (int)$serviceId;
        }

        $stmt = $db->prepare("
            SELECT c.full_name, c.phone, m.label as meter_label, m.meter_number,
                   st.name as service_name, st.icon as service_icon, st.color as service_color,
                   i.amount, i.is_paid, i.notes
            FROM invoices i
            JOIN meters m ON m.id = i.meter_id
            JOIN clients c ON c.id = m.client_id
            JOIN service_types st ON st.id = m.service_type_id
            WHERE i.month = ? AND i.year = ? $serviceFilter
            ORDER BY c.full_name, st.name
        ");
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
        break;

    // Delete invoice
    case 'delete':
        requireAdmin();
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM invoices WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non valide']);
}
