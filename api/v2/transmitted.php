<?php
require '../../config/db.php';
$env = require '../../config/env2.php';

header("Content-Type: application/json");

// ================= RESPONSE =================
function response($code, $data) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ================= REQUEST ID =================
$requestId = bin2hex(random_bytes(8));
header("X-Request-ID: $requestId");

// ================= HEADERS =================
function getHeadersSafe() {
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = str_replace('_', '-', substr($key, 5));
            $headers[strtoupper($name)] = $value;
        }
    }
    return $headers;
}

$headers = getHeadersSafe();

// ================= AUTH =================
$apiKey = $headers['X-API-KEY'] ?? '';
$secretKeyHeader = $headers['X-SECRET-KEY'] ?? '';

if (!$apiKey || !$secretKeyHeader) {
    response(401, ["error" => "Missing API credentials", "request_id" => $requestId]);
}

// Fetch API key
$stmt = $conn->prepare("
    SELECT * FROM api_keys 
    WHERE api_key = ? AND status = 'active'
");
$stmt->execute([$apiKey]);
$key = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$key) {
    response(403, ["error" => "Invalid API key", "request_id" => $requestId]);
}

if (!hash_equals($key['secret_key'], $secretKeyHeader)) {
    response(403, ["error" => "Invalid secret key", "request_id" => $requestId]);
}

// ================= AUTH MODE =================
$useSimpleAuth = $env['ALLOW_SIMPLE_AUTH'] ?? false;

if (!$useSimpleAuth && $env['REQUIRE_SIGNATURE']) {

    $timestamp = $headers['X-TIMESTAMP'] ?? '';
    $signature = $headers['X-SIGNATURE'] ?? '';

    if (!$timestamp || !$signature) {
        response(401, ["error" => "Missing signature headers", "request_id" => $requestId]);
    }

    if (abs(time() - (int)$timestamp) > $env['REQUEST_TTL']) {
        response(401, ["error" => "Request expired", "request_id" => $requestId]);
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $body = file_get_contents("php://input");

    $payload = $method . "|" . $uri . "|" . $timestamp . "|" . $body;
    $expected = hash_hmac($env['HMAC_ALGO'], $payload, $key['secret_key']);

    if (!hash_equals($expected, $signature)) {
        response(403, ["error" => "Invalid signature", "request_id" => $requestId]);
    }
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
    p.id,
    p.prescription_number,
    p.diagnosis,
    p.signed_at,

    pat.first_name,
    pat.last_name,
    pat.gender,
    pat.birthday,

    hf.facility_name,

    doc.first_name AS doctor_first,
    doc.last_name AS doctor_last,

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
LIMIT $limitPage OFFSET $offset
";

$stmt = $conn->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ================= FORMAT =================
$result = [];
$ids = [];

foreach ($rows as $row) {

    $pid = $row['id'];
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

// ================= MARK AS PULLED =================
if (!empty($ids)) {

    $ids = array_values(array_unique($ids));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $sqlUpdate = "
        UPDATE prescriptions
        SET
            pulled = 1,
            pulled_by = ?,
            updated_at = NOW()
        WHERE id IN ($placeholders)
    ";

    $params = array_merge([$apiKey], $ids);

    $stmt = $conn->prepare($sqlUpdate);
    $stmt->execute($params);
}

// ================= LOG =================
if ($env['LOG_API_REQUESTS']) {
    $conn->prepare("
        INSERT INTO api_logs (api_key, endpoint, request_id)
        VALUES (?, ?, ?)
    ")->execute([$apiKey, $_SERVER['REQUEST_URI'], $requestId]);
}

// ================= RESPONSE =================
response(200, [
    "status" => "success",
    "count" => count($result),
    "page" => $page,
    "request_id" => $requestId,
    "data" => $result
]);