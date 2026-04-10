<?php
/**
 * RIAD M2T API Proxy
 * Fetches unpaid billing data from riad-api.m2t.ma
 *
 * Known operator service IDs:
 * - Electricity (ONEE): fe559a53-e921-4677-a8c4-a6d5f55e82db
 * - Water: can be added when discovered
 *
 * searchCriteria:
 * - "6" = nopolice for electricity
 * - "1" = nopolice for water/other
 */

require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

$action = $_GET['action'] ?? '';

// ----- Config: Riad API -----
define('RIAD_API_BASE', 'https://riad-api.m2t.ma/api/v1');

// ----- Riad Settings Helpers -----
function ensureRiadSettingsTable() {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS riad_settings (key TEXT PRIMARY KEY, value TEXT)");
}

function getRiadSetting($key) {
    $db = getDB();
    ensureRiadSettingsTable();
    $stmt = $db->prepare("SELECT value FROM riad_settings WHERE key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : '';
}

function setRiadSetting($key, $value) {
    $db = getDB();
    ensureRiadSettingsTable();
    $stmt = $db->prepare("INSERT INTO riad_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
    $stmt->execute([$key, $value]);
}

function getRiadCodeEs() { return getRiadSetting('code_es'); }
function setRiadCodeEs($code) { setRiadSetting('code_es', $code); }
function getRiadCredentials() {
    return [
        'code_es' => getRiadSetting('code_es'),
        'username' => getRiadSetting('username'),
        'password' => getRiadSetting('password'),
    ];
}

// ----- Riad Session Management -----
// Login to RIAD and get session cookie
function riadLogin() {
    $creds = getRiadCredentials();
    if (empty($creds['code_es']) || empty($creds['username']) || empty($creds['password'])) {
        return ['success' => false, 'error' => 'بيانات الاتصال غير مكتملة. اذهب للإعدادات وأدخل كود المحل + اسم المستخدم + كلمة المرور.'];
    }

    $ch = curl_init(RIAD_API_BASE . '/auth/login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'username' => $creds['username'],
            'password' => $creds['password']
        ]),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json, text/plain, */*',
            'Content-Type: application/json;charset=UTF-8',
            'x-code-es: ' . $creds['code_es'],
            'Origin: https://riad.m2t.ma',
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => 'خطأ في الاتصال: ' . $error];
    }

    // Extract session cookie
    $sessionId = '';
    if (preg_match('/set-cookie:\s*X-SESSIONID=([^;]+)/i', $headers, $m)) {
        $sessionId = $m[1];
    }

    if ($httpCode >= 200 && $httpCode < 300 && $sessionId) {
        // Save session
        setRiadSetting('session_id', $sessionId);
        $data = json_decode($body, true);
        return ['success' => true, 'session_id' => $sessionId, 'data' => $data];
    } elseif ($httpCode === 401) {
        return ['success' => false, 'error' => 'اسم المستخدم أو كلمة المرور غير صحيحة (HTTP 401)'];
    } else {
        return ['success' => false, 'error' => 'خطأ غير متوقع (HTTP ' . $httpCode . ')', 'body' => substr($body, 0, 200)];
    }
}

// Get active session (login if needed)
function getRiadSession() {
    $session = getRiadSetting('session_id');
    if (!empty($session)) {
        // Test if session still valid
        $creds = getRiadCredentials();
        $ch = curl_init(RIAD_API_BASE . '/auth/current');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'x-code-es: ' . $creds['code_es'],
                'Cookie: X-SESSIONID=' . $session,
            ],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) {
            return ['success' => true, 'session_id' => $session];
        }
    }
    // Session expired or doesn't exist - login again
    return riadLogin();
}

// Operator service IDs mapped to our service types
// Admin can configure these in Settings later
$OPERATOR_MAP = getOperatorMap();

function getOperatorMap() {
    $db = getDB();
    // Check if riad_config table exists
    try {
        $stmt = $db->query("SELECT * FROM riad_config");
        $rows = $stmt->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $map[$r['service_type_id']] = [
                'operator_id' => $r['operator_service_id'],
                'search_criteria' => $r['search_criteria'],
                'label' => $r['label']
            ];
        }
        return $map;
    } catch (Exception $e) {
        // Create table if not exists
        $db->exec("
            CREATE TABLE IF NOT EXISTS riad_config (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                service_type_id INTEGER NOT NULL,
                operator_service_id TEXT NOT NULL,
                search_criteria TEXT DEFAULT '6',
                label TEXT DEFAULT '',
                FOREIGN KEY (service_type_id) REFERENCES service_types(id) ON DELETE CASCADE,
                UNIQUE(service_type_id)
            )
        ");
        // Insert default for electricity (service_type_id=1 is typically Electricite)
        $stmt = $db->prepare("SELECT id FROM service_types WHERE name LIKE '%lectri%' LIMIT 1");
        $stmt->execute();
        $elec = $stmt->fetch();
        if ($elec) {
            $db->prepare("INSERT OR IGNORE INTO riad_config (service_type_id, operator_service_id, search_criteria, label) VALUES (?, ?, ?, ?)")
               ->execute([$elec['id'], 'fe559a53-e921-4677-a8c4-a6d5f55e82db', '6', 'ONEE Electricite']);
        }
        return getOperatorMap();
    }
}

switch ($action) {

    // Fetch unpaid bills for a specific meter
    case 'fetch':
        $nopolice = trim($_GET['nopolice'] ?? '');
        $serviceTypeId = (int)($_GET['service_type_id'] ?? 0);
        $operatorId = $_GET['operator_id'] ?? '';
        $searchCriteria = $_GET['search_criteria'] ?? '6';

        if (empty($nopolice)) {
            http_response_code(400);
            echo json_encode(['error' => 'nopolice requis']);
            break;
        }

        // Get operator config from map or use provided values
        if (!empty($operatorId)) {
            // Direct operator ID provided
        } elseif (isset($OPERATOR_MAP[$serviceTypeId])) {
            $operatorId = $OPERATOR_MAP[$serviceTypeId]['operator_id'];
            $searchCriteria = $OPERATOR_MAP[$serviceTypeId]['search_criteria'];
        } else {
            // Default fallback: electricity
            $operatorId = 'fe559a53-e921-4677-a8c4-a6d5f55e82db';
            $searchCriteria = '6';
        }

        $result = callRiadAPI($nopolice, $operatorId, $searchCriteria);
        echo json_encode($result);
        break;

    // Fetch bills for ALL meters of a client that have nopolice
    case 'fetch_client':
        $clientId = (int)($_GET['client_id'] ?? 0);
        if ($clientId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'client_id requis']);
            break;
        }

        $db = getDB();
        $stmt = $db->prepare("
            SELECT m.id as meter_id, m.nopolice, m.service_type_id, m.label, st.name as service_name
            FROM meters m
            JOIN service_types st ON st.id = m.service_type_id
            WHERE m.client_id = ? AND m.nopolice != '' AND m.is_active = 1
        ");
        $stmt->execute([$clientId]);
        $meters = $stmt->fetchAll();

        $results = [];
        foreach ($meters as $meter) {
            $operatorId = '';
            $searchCriteria = '6';
            if (isset($OPERATOR_MAP[$meter['service_type_id']])) {
                $operatorId = $OPERATOR_MAP[$meter['service_type_id']]['operator_id'];
                $searchCriteria = $OPERATOR_MAP[$meter['service_type_id']]['search_criteria'];
            } else {
                // Default
                $operatorId = 'fe559a53-e921-4677-a8c4-a6d5f55e82db';
            }

            $apiResult = callRiadAPI($meter['nopolice'], $operatorId, $searchCriteria);
            $results[] = [
                'meter_id' => $meter['meter_id'],
                'meter_label' => $meter['label'],
                'service_name' => $meter['service_name'],
                'nopolice' => $meter['nopolice'],
                'api_response' => $apiResult
            ];
        }

        echo json_encode(['success' => true, 'results' => $results]);
        break;

    // Get Riad API configuration
    case 'config_list':
        requireAdmin();
        $db = getDB();
        $stmt = $db->query("
            SELECT rc.*, st.name as service_name
            FROM riad_config rc
            JOIN service_types st ON st.id = rc.service_type_id
            ORDER BY st.name
        ");
        echo json_encode($stmt->fetchAll());
        break;

    // Save Riad API configuration
    case 'config_save':
        requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true);
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO riad_config (service_type_id, operator_service_id, search_criteria, label)
            VALUES (?, ?, ?, ?)
            ON CONFLICT(service_type_id)
            DO UPDATE SET operator_service_id=excluded.operator_service_id, search_criteria=excluded.search_criteria, label=excluded.label
        ");
        $stmt->execute([
            (int)$data['service_type_id'],
            $data['operator_service_id'],
            $data['search_criteria'] ?? '6',
            $data['label'] ?? ''
        ]);
        echo json_encode(['success' => true]);
        break;

    // Delete Riad API configuration
    case 'config_delete':
        requireAdmin();
        $id = (int)($_GET['id'] ?? 0);
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM riad_config WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    // Get saved credentials (without password)
    case 'get_credentials':
        requireAdmin();
        $creds = getRiadCredentials();
        echo json_encode([
            'code_es' => $creds['code_es'],
            'username' => $creds['username'],
            'has_password' => !empty($creds['password']),
        ]);
        break;

    // Save all credentials
    case 'save_credentials':
        requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true);
        $code = trim($data['code_es'] ?? '');
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';

        if (empty($code) || empty($username)) {
            http_response_code(400);
            echo json_encode(['error' => 'كود المحل واسم المستخدم مطلوبان']);
            break;
        }

        setRiadSetting('code_es', $code);
        setRiadSetting('username', $username);
        if (!empty($password)) {
            setRiadSetting('password', $password);
        }
        // Clear old session
        setRiadSetting('session_id', '');

        echo json_encode(['success' => true]);
        break;

    // Test connection (full login)
    case 'test':
        requireAdmin();
        $creds = getRiadCredentials();
        if (empty($creds['code_es']) || empty($creds['username']) || empty($creds['password'])) {
            echo json_encode(['success' => false, 'error' => 'أكمل جميع البيانات أولاً (كود المحل + اسم المستخدم + كلمة المرور)']);
            break;
        }
        $result = riadLogin();
        if ($result['success']) {
            echo json_encode(['success' => true, 'message' => 'تسجيل الدخول ناجح! الاتصال يعمل.']);
        } else {
            echo json_encode($result);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action non valide']);
}

// =====================
// Core API call function
// =====================
function callRiadAPI($nopolice, $operatorServiceId, $searchCriteria = '6') {
    // Get active session (auto-login if needed)
    $sessionResult = getRiadSession();
    if (!$sessionResult['success']) {
        return $sessionResult;
    }

    $creds = getRiadCredentials();
    $sessionId = $sessionResult['session_id'];
    $url = RIAD_API_BASE . '/billings/unpaid?operatorServiceId=' . urlencode($operatorServiceId);

    // Generate unique audit number
    $auditNumber = sprintf('%s-%s-%s-%s-%s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(6))
    );

    $body = json_encode([
        'searchData' => ['nopolice' => $nopolice],
        'searchCriteria' => $searchCriteria,
        'auditNumber' => $auditNumber,
        'fileToUpload' => null,
        'collectedData' => (object)[]
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json, text/plain, */*',
            'Content-Type: application/json;charset=UTF-8',
            'x-code-es: ' . $creds['code_es'],
            'Cookie: X-SESSIONID=' . $sessionId,
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => 'Erreur connexion: ' . $error];
    }

    $data = json_decode($response, true);
    if (!$data) {
        return ['success' => false, 'error' => 'Reponse invalide du serveur', 'http_code' => $httpCode];
    }

    if (isset($data['errorCod']) && $data['errorCod'] !== '000') {
        return ['success' => false, 'error' => $data['errorMsg'] ?? 'Erreur API', 'code' => $data['errorCod']];
    }

    // Parse and simplify the response
    $invoices = [];
    if (isset($data['listeFactures'])) {
        foreach ($data['listeFactures'] as $fac) {
            // Parse period: "08/25" => month=8, year=2025
            $period = $fac['dateFacture'] ?? '';
            $month = 0;
            $year = 0;
            if (preg_match('/(\d{2})\/(\d{2})/', $period, $matches)) {
                $month = (int)$matches[1];
                $year = 2000 + (int)$matches[2];
            }

            $invoices[] = [
                'facture_id' => $fac['factureId'] ?? '',
                'label' => $fac['factureLibelle'] ?? '',
                'period' => $period,
                'month' => $month,
                'year' => $year,
                'amount_ttc' => floatval($fac['mntTTC'] ?? 0),
                'amount_ht' => floatval($fac['mntHT'] ?? 0),
                'penalite' => floatval($fac['penalite'] ?? 0),
                'timbre' => floatval($fac['timbre'] ?? 0),
            ];
        }
    }

    return [
        'success' => true,
        'client_name' => $data['nomClient'] ?? '',
        'client_id' => $data['idClient'] ?? '',
        'total_ttc' => floatval($data['montantTTC'] ?? 0),
        'nb_factures' => $data['nombreFactures'] ?? 0,
        'invoices' => $invoices,
        'raw_address' => extractParam($data['paramsGlob'] ?? [], 'address'),
    ];
}

function extractParam($params, $name) {
    foreach ($params as $p) {
        if (($p['dateName'] ?? '') === $name) return $p['dataVal'] ?? '';
    }
    return '';
}
