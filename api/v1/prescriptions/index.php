<?php
require '../../../config/db.php';

header("Content-Type: application/json");

/* ================= AUTH ================= */
$headers = getallheaders();

$apiKey    = $headers['X-API-KEY']    ?? null;
$apiSecret = $headers['X-API-SECRET'] ?? null;

if (!$apiKey || !$apiSecret) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Missing API credentials"]);
    exit;
}

$stmt = $conn->prepare("
    SELECT facility_id 
    FROM api_keys 
    WHERE api_key = ? 
      AND secret_key = ? 
      AND status = 'active'
    LIMIT 1
");
$stmt->execute([$apiKey, $apiSecret]);
$keyData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$keyData) {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Invalid API credentials"]);
    exit;
}

$facilityId = $keyData['facility_id'];

/* ================= FILTERS ================= */
$limit  = isset($_GET['limit'])  ? (int)$_GET['limit']  : 10;
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

$dateFrom = $_GET['date_from'] ?? null;
$dateTo   = $_GET['date_to']   ?? null;

/* ================= MAIN QUERY ================= */
$sql = "
SELECT 
    p.id,
    p.prescription_number,
    p.diagnosis,
    p.signed_at,

    hf.facility_name,

    pa.his_id,
    pa.first_name,
    pa.last_name,
    pa.gender,
    pa.birthday,
    pa.makati_employee,

    u.first_name AS doctor_first,
    u.last_name  AS doctor_last

FROM prescriptions p
JOIN patients pa ON pa.id = p.patient_id
LEFT JOIN health_facilities hf ON hf.id = p.facility_id
LEFT JOIN users u ON u.id = p.doctor_id

WHERE 
    p.status = 'Signed'
    AND p.pulled = 0
    AND p.facility_id = :facility_id
";

/* Date filters */
if ($dateFrom) {
    $sql .= " AND DATE(p.signed_at) >= :date_from";
}
if ($dateTo) {
    $sql .= " AND DATE(p.signed_at) <= :date_to";
}

$sql .= " ORDER BY p.signed_at DESC LIMIT :limit OFFSET :offset";

$stmt = $conn->prepare($sql);

$stmt->bindValue(':facility_id', $facilityId, PDO::PARAM_INT);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

if ($dateFrom) $stmt->bindValue(':date_from', $dateFrom);
if ($dateTo)   $stmt->bindValue(':date_to', $dateTo);

$stmt->execute();
$prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= FETCH MEDICINES ================= */
$result = [];

foreach ($prescriptions as $row) {

    $medStmt = $conn->prepare("
        SELECT 
            id,
            medicine_name,
            quantity,
            pharmacy_status,
            pharmacy_reason
        FROM prescription_medicines
        WHERE prescription_id = ?
    ");
    $medStmt->execute([$row['id']]);
    $meds = $medStmt->fetchAll(PDO::FETCH_ASSOC);

    $medicines = array_map(function($m) {
        return [
            "medicine_id"     => (int)$m['id'],
            "name"            => $m['medicine_name'],
            "quantity"        => (int)$m['quantity'],
            "status"          => $m['pharmacy_status'],
            "pharmacy_reason" => $m['pharmacy_reason']
        ];
    }, $meds);

    $result[] = [
        "prescription_id"     => (int)$row['id'],
        "prescription_number" => $row['prescription_number'],
        "diagnosis"           => $row['diagnosis'],
        "signed_at"           => $row['signed_at'],
        "facility"            => $row['facility_name'],

        "patient" => [
            "his_id"           => $row['his_id'],
            "name"             => trim($row['first_name'] . ' ' . $row['last_name']),
            "gender"           => $row['gender'],
            "birthday"         => $row['birthday'],
            "makati_employee"  => $row['makati_employee']
        ],

        "doctor" => [
            "name" => trim($row['doctor_first'] . ' ' . $row['doctor_last'])
        ],

        "medicines" => $medicines
    ];
}

/* ================= RESPONSE ================= */
echo json_encode([
    "status" => "success",
    "count"  => count($result),
    "prescriptions" => $result
]);