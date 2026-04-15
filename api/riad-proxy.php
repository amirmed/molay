<?php
/**
 * RIAD M2T API Proxy
 * Fetches unpaid billing data from riad-api.m2t.ma
 *
 * Authentication: 3 cookies required:
 *   - token (JWT) - expires every 2 hours
 *   - X-SESSIONID - server session reference
 *   - device_XXXXX - device identifier (lasts 3 months)
 * + Header: x-code-es
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

// ----- JWT Decoder -----
function decodeJwtPayload($jwt) {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    $payload = $parts[1];
    // Base64url decode
    $payload = str_replace(['-', '_'], ['+', '/'], $payload);
    $payload = base64_decode($payload);
    return json_decode($payload, true);
}

function getJwtExpiry($jwt) {
    $payload = decodeJwtPayload($jwt);
    if ($payload && isset($payload['exp'])) {
        return [
            'exp' => (int)$payload['exp'],
            'iat' => (int)($payload['iat'] ?? 0),
            'sub' => $payload['sub'] ?? '',
            'remaining' => max(0, (int)$payload['exp'] - time()),
            'expired' => time() > (int)$payload['exp'],
        ];
    }
    return null;
}

// ----- Build Cookie String -----
function buildRiadCookies() {
    $token = getRiadSetting('jwt_token');
    $sessionId = getRiadSetting('session_id');
    $deviceName = getRiadSetting('device_cookie_name');
    $deviceValue = getRiadSetting('device_cookie_value');

    $cookies = [];
    if ($sessionId) $cookies[] = 'X-SESSIONID=' . $sessionId;
    if ($token) $cookies[] = 'token=' . $token;
    if ($deviceName && $deviceValue) $cookies[] = $deviceName . '=' . $deviceValue;

    return implode('; ', $cookies);
}

// ----- Riad Session Validation -----
function getRiadSession() {
    $codeEs = getRiadSetting('code_es');
    $token = getRiadSetting('jwt_token');
    $sessionId = getRiadSetting('session_id');

    if (empty($codeEs)) {
        return ['success' => false, 'error' => 'كود المحل (x-code-es) غير مُدخل. اذهب للإعدادات.'];
    }

    if (empty($token) && empty($sessionId)) {
        return ['success' => false, 'error' => 'بيانات الاتصال غير مكتملة. أدخل بيانات الجلسة في الإعدادات.'];
    }

    // Check JWT expiry
    if ($token) {
        $jwtInfo = getJwtExpiry($token);
        if ($jwtInfo && $jwtInfo['expired']) {
            return ['success' => false, 'error' => 'جلسة RIAD منتهية (JWT expired). أعد المزامنة.', 'code' => '401'];
        }
    }

    return [
        'success' => true,
        'code_es' => $codeEs,
        'cookie_string' => buildRiadCookies(),
    ];
}

// Operator service IDs mapped to our service types
$OPERATOR_MAP = getOperatorMap();

function getOperatorMap() {
    $db = getDB();
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

    // ============= FETCH single meter =============
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

        if (!empty($operatorId)) {
            // Direct
        } elseif (isset($OPERATOR_MAP[$serviceTypeId])) {
            $operatorId = $OPERATOR_MAP[$serviceTypeId]['operator_id'];
            $searchCriteria = $OPERATOR_MAP[$serviceTypeId]['search_criteria'];
        } else {
            $operatorId = 'fe559a53-e921-4677-a8c4-a6d5f55e82db';
            $searchCriteria = '6';
        }

        $result = callRiadAPI($nopolice, $operatorId, $searchCriteria);
        echo json_encode($result);
        break;

    // ============= FETCH all meters of a client =============
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

    // ============= Config CRUD =============
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

    case 'config_delete':
        requireAdmin();
        $id = (int)($_GET['id'] ?? 0);
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM riad_config WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    // ============= Session Management =============
    case 'get_session':
        requireAdmin();
        $token = getRiadSetting('jwt_token');
        $jwtInfo = $token ? getJwtExpiry($token) : null;

        echo json_encode([
            'code_es' => getRiadSetting('code_es'),
            'session_id' => getRiadSetting('session_id'),
            'jwt_token' => $token,
            'device_cookie_name' => getRiadSetting('device_cookie_name'),
            'device_cookie_value' => getRiadSetting('device_cookie_value'),
            'jwt_info' => $jwtInfo,
            'last_sync' => getRiadSetting('last_sync'),
        ]);
        break;

    case 'save_session':
        requireAdmin();
        $data = json_decode(file_get_contents('php://input'), true);

        // Support both old format (code_es + session_id) and new format (all fields)
        if (isset($data['code_es'])) setRiadSetting('code_es', trim($data['code_es']));
        if (isset($data['session_id'])) setRiadSetting('session_id', trim($data['session_id']));
        if (isset($data['jwt_token'])) setRiadSetting('jwt_token', trim($data['jwt_token']));
        if (isset($data['device_cookie_name'])) setRiadSetting('device_cookie_name', trim($data['device_cookie_name']));
        if (isset($data['device_cookie_value'])) setRiadSetting('device_cookie_value', trim($data['device_cookie_value']));
        setRiadSetting('last_sync', date('Y-m-d H:i:s'));

        // Return JWT info if token was provided
        $token = getRiadSetting('jwt_token');
        $jwtInfo = $token ? getJwtExpiry($token) : null;

        echo json_encode(['success' => true, 'jwt_info' => $jwtInfo]);
        break;

    // ============= Auto-Sync from Tampermonkey =============
    case 'auto_sync':
        requireLogin(); // Any logged-in user can sync (they need to be on riad.m2t.ma)
        $data = json_decode(file_get_contents('php://input'), true);

        $synced = [];

        // Extract and save all credentials
        if (!empty($data['code_es'])) {
            setRiadSetting('code_es', trim($data['code_es']));
            $synced[] = 'code_es';
        }
        if (!empty($data['jwt_token'])) {
            setRiadSetting('jwt_token', trim($data['jwt_token']));
            $synced[] = 'jwt_token';
        }
        if (!empty($data['session_id'])) {
            setRiadSetting('session_id', trim($data['session_id']));
            $synced[] = 'session_id';
        }
        if (!empty($data['device_cookie_name']) && !empty($data['device_cookie_value'])) {
            setRiadSetting('device_cookie_name', trim($data['device_cookie_name']));
            setRiadSetting('device_cookie_value', trim($data['device_cookie_value']));
            $synced[] = 'device_cookie';
        }

        // Auto-save operator config if provided
        if (!empty($data['operator_service_id']) && !empty($data['service_type_id'])) {
            $db = getDB();
            $db->prepare("
                INSERT INTO riad_config (service_type_id, operator_service_id, search_criteria, label)
                VALUES (?, ?, ?, ?)
                ON CONFLICT(service_type_id) DO UPDATE SET
                    operator_service_id=excluded.operator_service_id,
                    search_criteria=excluded.search_criteria
            ")->execute([
                (int)$data['service_type_id'],
                $data['operator_service_id'],
                $data['search_criteria'] ?? '6',
                $data['label'] ?? 'Auto-synced'
            ]);
            $synced[] = 'operator_config';
        }

        setRiadSetting('last_sync', date('Y-m-d H:i:s'));

        $token = getRiadSetting('jwt_token');
        $jwtInfo = $token ? getJwtExpiry($token) : null;

        logAudit('riad_auto_sync', 'riad', null, 'synced: ' . implode(', ', $synced));

        echo json_encode([
            'success' => true,
            'synced' => $synced,
            'jwt_info' => $jwtInfo,
            'message' => 'تمت المزامنة بنجاح (' . count($synced) . ' عناصر)',
        ]);
        break;

    // ============= Test Connection =============
    case 'test':
        requireAdmin();
        $session = getRiadSession();

        if (!$session['success']) {
            echo json_encode($session);
            break;
        }

        $ch = curl_init(RIAD_API_BASE . '/ceilings?codeEs=' . urlencode($session['code_es']));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'x-code-es: ' . $session['code_es'],
                'Cookie: ' . $session['cookie_string'],
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            echo json_encode(['success' => false, 'error' => 'خطأ في الاتصال: ' . $error]);
        } elseif ($httpCode === 401 || $httpCode === 403) {
            echo json_encode(['success' => false, 'error' => 'الجلسة منتهية أو غير صالحة (HTTP ' . $httpCode . '). أعد المزامنة.']);
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            $data = json_decode($response, true);
            $balance = '';
            if (isset($data['content'][0]['balance'])) {
                $balance = ' | الرصيد: ' . number_format($data['content'][0]['balance'], 2) . ' DH';
            }
            // Get JWT remaining time
            $token = getRiadSetting('jwt_token');
            $jwtInfo = $token ? getJwtExpiry($token) : null;
            $remaining = '';
            if ($jwtInfo && isset($jwtInfo['remaining'])) {
                $mins = floor($jwtInfo['remaining'] / 60);
                $remaining = " | الجلسة صالحة لمدة: {$mins} دقيقة";
            }
            echo json_encode([
                'success' => true,
                'message' => 'الاتصال ناجح! الجلسة صالحة.' . $balance . $remaining,
                'jwt_info' => $jwtInfo,
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'استجابة غير متوقعة (HTTP ' . $httpCode . ')']);
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
    $session = getRiadSession();
    if (!$session['success']) {
        return $session;
    }

    $url = RIAD_API_BASE . '/billings/unpaid?operatorServiceId=' . urlencode($operatorServiceId);

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
            'x-code-es: ' . $session['code_es'],
            'Cookie: ' . $session['cookie_string'],
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

    if ($httpCode === 401 || $httpCode === 403) {
        return ['success' => false, 'error' => 'جلسة RIAD منتهية (HTTP ' . $httpCode . ')', 'code' => (string)$httpCode];
    }

    $data = json_decode($response, true);
    if (!$data) {
        return ['success' => false, 'error' => 'Reponse invalide du serveur', 'http_code' => $httpCode];
    }

    if (isset($data['errorCod']) && $data['errorCod'] !== '000') {
        return ['success' => false, 'error' => $data['errorMsg'] ?? 'Erreur API', 'code' => $data['errorCod']];
    }

    // Parse invoices
    $invoices = [];
    if (isset($data['listeFactures'])) {
        foreach ($data['listeFactures'] as $fac) {
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
