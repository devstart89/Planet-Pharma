<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error) {
        header('Content-Type: application/json');
        echo json_encode([
            "fatal_error" => $error
        ]);
    }
});
require '../../../config/db.php';
$env = require '../../../config/env2.php';

header("Content-Type: application/json");

// ================= ERROR SAFETY (IMPORTANT) =================
ini_set('display_errors', 0);
error_reporting(E_ALL);

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ================= HELPERS =================
function response($code, $data)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function getRequestId()
{
    return bin2hex(random_bytes(8));
}

function getHeadersSafe()
{
    $headers = [];

    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = strtoupper(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = $value;
        }
    }

    return $headers;
}

function nowBody()
{
    return file_get_contents("php://input");
}

// ================= INIT =================
$requestId = getRequestId();
header("X-Request-ID: $requestId");

$headers = getHeadersSafe();

// ================= AUTH VALIDATION =================
function validateApiKey($conn, $apiKey)
{
    $stmt = $conn->prepare("SELECT * FROM api_keys WHERE api_key = ? AND status = 'active'");
    $stmt->execute([$apiKey]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function validateSignature($env, $headers, $method, $uri, $body, $key)
{
    $timestamp = $headers['X-TIMESTAMP'] ?? null;
    $signature = $headers['X-SIGNATURE'] ?? null;

    if (!$timestamp || !$signature) {
        response(401, ["error" => "Missing signature headers"]);
    }

    if (abs(time() - (int)$timestamp) > $env['REQUEST_TTL']) {
        response(401, ["error" => "Request expired"]);
    }

    $payload = $method . "|" . $uri . "|" . $timestamp . "|" . $body;
    $expected = hash_hmac($env['HMAC_ALGO'], $payload, $key['secret_key']);

    if (!hash_equals($expected, $signature)) {
        response(403, ["error" => "Invalid signature"]);
    }
}

// ================= AUTH =================
$apiKey = $headers['X-API-KEY'] ?? '';
$secretKey = $headers['X-SECRET-KEY'] ?? '';

if (!$apiKey || !$secretKey) {
    response(401, ["error" => "Missing API credentials", "request_id" => $requestId]);
}

$key = validateApiKey($conn, $apiKey);

if (!$key) {
    response(403, ["error" => "Invalid API key", "request_id" => $requestId]);
}

if (!hash_equals($key['secret_key'], $secretKey)) {
    response(403, ["error" => "Invalid secret key", "request_id" => $requestId]);
}

// ================= SIGNATURE =================
$useSimpleAuth = $env['ALLOW_SIMPLE_AUTH'] ?? false;

if (!$useSimpleAuth && ($env['REQUIRE_SIGNATURE'] ?? false)) {
    validateSignature(
        $env,
        $headers,
        $_SERVER['REQUEST_METHOD'],
        parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
        nowBody(),
        $key
    );
}

// ================= RATE LIMIT =================
function checkRateLimit($conn, $apiKey, $key, $env)
{
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
        response(429, ["error" => "Rate limit exceeded"]);
    }
}

checkRateLimit($conn, $apiKey, $key, $env);

// ================= PAGINATION =================
$page = max(1, (int)($_GET['page'] ?? 1));
$limitPage = min(
    (int)($_GET['limit'] ?? $env['DEFAULT_PAGE_LIMIT']),
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

// ================= EXECUTION SAFETY =================
try {
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    response(500, [
        "error" => "Database query failed",
        "message" => $e->getMessage()
    ]);
}

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

    if (!empty($row['medicine_name'])) {
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
function markAsPulled($conn, $apiKey, $ids)
{
    if (empty($ids)) return;

    $ids = array_values(array_unique($ids));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $sql = "
        UPDATE prescriptions
        SET pulled = 1,
            pulled_by = ?,
            updated_at = NOW()
        WHERE id IN ($placeholders)
    ";

    $params = array_merge([$apiKey], $ids);

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
}

markAsPulled($conn, $apiKey, $ids);

// ================= LOG =================
function logRequest($conn, $env, $apiKey, $requestId)
{
    if (!($env['LOG_API_REQUESTS'] ?? false)) return;

    try {
        $stmt = $conn->prepare("
            INSERT INTO api_logs (api_key, endpoint, request_id)
            VALUES (?, ?, ?)
        ");

        $stmt->execute([
            $apiKey,
            $_SERVER['REQUEST_URI'] ?? '',
            $requestId
        ]);
    } catch (Exception $e) {
        error_log("API LOG ERROR: " . $e->getMessage());
    }
}

logRequest($conn, $env, $apiKey, $requestId);

// ================= RESPONSE =================
response(200, [
    "status" => "success",
    "count" => count($result),
    "page" => $page,
    "request_id" => $requestId,
    "data" => $result
]);