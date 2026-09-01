<?php
session_start();
require '../../config/db.php';

header("Content-Type: application/json");

/* ================= RESPONSE HELPER ================= */
function respond($status, $message, $data = null, $code = 200)
{
    http_response_code($code);

    echo json_encode([
        "status"  => $status,
        "message" => $message,
        "data"    => $data
    ]);

    exit;
}

/* ================= AUTH ================= */
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'super_admin') {
    respond("error", "Unauthorized", null, 403);
}

/* ================= INPUT ================= */
$facility_ids = $_POST['facilities'] ?? [];
$from         = $_POST['from'] ?? null;
$to           = $_POST['to'] ?? null;

/* ================= VALIDATION ================= */
if (empty($facility_ids) || empty($from) || empty($to)) {
    respond("success", "No filters applied", []);
}

$facility_ids = array_map('intval', (array) $facility_ids);

/* ================= QUERY ================= */
$sql = "
SELECT
    pr.id,
    pr.prescription_number,
    pr.created_at,

    CASE
        WHEN pr.transmittal_id IS NULL THEN 'No'
        ELSE 'Yes'
    END AS transmitted,

    CONCAT(pt.first_name,' ',pt.last_name) AS patient_name,
    pt.contact_number AS contact,
    hf.facility_name,
    COALESCE(ph.pharmacy_name,'N/A') AS pharmacy

FROM prescriptions pr

INNER JOIN patients pt
        ON pt.id = pr.patient_id

INNER JOIN health_facilities hf
        ON hf.id = pr.facility_id

LEFT JOIN pharmacy ph
       ON hf.pharmacy_id = ph.id

WHERE pt.is_deleted = 0
";

$params = [];
$conditions = [];

/* ================= FACILITY FILTER ================= */
if (!empty($facility_ids)) {

    $placeholders = implode(',', array_fill(0, count($facility_ids), '?'));

    $conditions[] = "hf.id IN ($placeholders)";

    $params = array_merge($params, $facility_ids);
}

/* ================= DATE FILTER ================= */
$conditions[] = "DATE(pr.created_at) BETWEEN ? AND ?";

$params[] = $from;
$params[] = $to;

/* ================= APPLY CONDITIONS ================= */
if (!empty($conditions)) {
    $sql .= " AND " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY pr.created_at DESC";

/* ================= EXECUTE ================= */
$stmt = $conn->prepare($sql);
$stmt->execute($params);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= FORMAT ================= */
$data = [];

foreach ($rows as $row) {

    $badge = $row['transmitted'] === 'Yes'
        ? "<span class='badge bg-success'>Yes</span>"
        : "<span class='badge bg-secondary'>No</span>";

    $data[] = [

        'date' => date('M d, Y', strtotime($row['created_at'])),

        'id' => $row['prescription_number'],

        'patient_name' => $row['patient_name'],

        'facility_name' => $row['facility_name'],

        'contact' => $row['contact'],

        'pharmacy' => $row['pharmacy'],

        'transmitted' => $row['transmitted'],

        'transmitted_badge' => $badge
    ];
}

/* ================= RESPONSE ================= */
respond("success", "Report fetched", $data);