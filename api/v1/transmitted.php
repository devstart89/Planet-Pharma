<?php
require '../../config/db.php';
$env = require '../../config/env.php';

header("Content-Type: application/json");

// ================= RESPONSE =================
function response($code, $data) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ================= VERSION =================
define('API_VERSION', 'v1');

// ================= REQUEST ID =================
$requestId = bin2hex(random_bytes(8));
header("X-Request-ID: $requestId");

// ================= BASIC AUTH =================
$apiKey = $_SERVER['PHP_AUTH_USER'] ?? '';
$secretKey = $_SERVER['PHP_AUTH_PW'] ?? '';

// Fallback for servers that don’t support PHP_AUTH_*
if (!$apiKey && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (strpos($_SERVER['HTTP_AUTHORIZATION'], 'Basic ') === 0) {
        $decoded = base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6));
        [$apiKey, $secretKey] = explode(':', $decoded, 2);
    }
}

if (!$apiKey || !$secretKey) {
    header('WWW-Authenticate: Basic realm="API Access"');
    response(401, [
        "error" => "Missing Basic Authentication",
        "request_id" => $requestId
    ]);
}

// ================= VALIDATE KEY =================
$stmt = $conn->prepare("
    SELECT * FROM api_keys 
    WHERE api_key = ? AND status = 'active'
");
$stmt->execute([$apiKey]);
$key = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$key) {
    response(403, ["error" => "Invalid API key", "request_id" => $requestId]);
}

if (!hash_equals($key['secret_key'], $secretKey)) {
    response(403, ["error" => "Invalid secret key", "request_id" => $requestId]);
}

// ================= RATE LIMIT =================
$limit = $key['rate_limit'] ?? $env['RATE_LIMIT'];
$window = $key['rate_window'] ?? $env['RATE_WINDOW'];

$stmt = $conn->prepare("
    SELECT COUNT(*) 
    FROM api_logs 
    WHERE api_key = ?
    AND created_at > (NOW() - INTERVAL ? SECOND)
");
$stmt->execute([$apiKey, $window]);

if ((int)$stmt->fetchColumn() >= $limit) {
    response(429, ["error" => "Rate limit exceeded", "request_id" => $requestId]);
}

// ================= PAGINATION =================
$page = max(1, intval($_GET['page'] ?? 1));
$limitPage = min(
    intval($_GET['limit'] ?? $env['DEFAULT_PAGE_LIMIT']),
    $env['MAX_PAGE_LIMIT']
);
$offset = ($page - 1) * $limitPage;

// ================= QUERY =================
$sql = "
SELECT 
    p.id as prescription_id,
    p.prescription_number,
    p.diagnosis,
    p.signed_at,

    pat.first_name,
    pat.last_name,
    pat.gender,
    pat.birthday,

    hf.facility_name,

    doc.first_name as doctor_first,
    doc.last_name as doctor_last,

    pm.medicine_name,
    pm.dosage,
    pm.frequency,
    pm.duration,
    pm.quantity,
    pm.status

FROM transmittals t
JOIN transmittal_prescriptions tp ON tp.transmittal_id = t.id
JOIN prescriptions p ON p.id = tp.prescription_id
JOIN patients pat ON pat.id = p.patient_id
LEFT JOIN health_facilities hf ON hf.id = p.facility_id
LEFT JOIN users doc ON doc.id = p.signed_by
LEFT JOIN prescription_medicines pm ON pm.prescription_id = p.id

WHERE 
    t.transmitted = 0
    AND p.status = 'Signed'
    AND (p.pulled = 0 OR p.pulled IS NULL)

ORDER BY p.id DESC
LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($sql);
$stmt->bindValue(':limit', (int)$limitPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ================= FORMAT =================
$result = [];
$ids = [];

foreach ($rows as $row) {

    $pid = $row['prescription_id'];
    $ids[] = $pid;

    if (!isset($result[$pid])) {
        $result[$pid] = [
            "prescription_number" => $row['prescription_number'],
            "date" => $row['signed_at'],
            "facility" => $row['facility_name'],
            "doctor" => trim(($row['doctor_first'] ?? '') . ' ' . ($row['doctor_last'] ?? '')),
            "patient" => [
                "name" => $row['first_name'] . ' ' . $row['last_name'],
                "gender" => $row['gender'],
                "birthday" => $row['birthday']
            ],
            "diagnosis" => $row['diagnosis'],
            "medicines" => []
        ];
    }

    if ($row['medicine_name']) {
        $result[$pid]['medicines'][] = [
            "name" => $row['medicine_name'],
            "dosage" => $row['dosage'],
            "frequency" => $row['frequency'],
            "duration" => $row['duration'],
            "quantity" => (int)$row['quantity'],
            "status" => $row['status']
        ];
    }
}

$result = array_values($result);

// ================= AUTO MARK AS PULLED =================
if (!empty($ids)) {

    $ids = array_values(array_unique($ids));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $conn->prepare("
        UPDATE prescriptions 
        SET pulled = 1, updated_at = NOW()
        WHERE id IN ($placeholders)
    ");

    $stmt->execute($ids);
}

// ================= LOG =================
if ($env['LOG_API_REQUESTS']) {
    $conn->prepare("
        INSERT INTO api_logs (api_key)
        VALUES (?)
    ")->execute([$apiKey]);
}

// ================= RESPONSE =================
response(200, [
    "version" => API_VERSION,
    "status" => "success",
    "count" => count($result),
    "page" => $page,
    "request_id" => $requestId,
    "data" => $result
]);