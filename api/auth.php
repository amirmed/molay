<?php
require_once __DIR__ . '/../includes/auth.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'logout':
        logout();
        header('Location: ../index.php');
        exit;

    case 'login':
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);
        if (login($data['username'] ?? '', $data['password'] ?? '')) {
            echo json_encode(['success' => true, 'role' => $_SESSION['role']]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Identifiants incorrects']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non valide']);
}
