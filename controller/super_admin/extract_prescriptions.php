<?php
session_start();
require_once '../../config/db.php';

/* =====================================
   AUTH
===================================== */
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'super_admin') {
    http_response_code(403);
    exit;
}

/* =====================================
   CSV HEADERS
===================================== */
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="prescriptions_' . date('Ymd_His') . '.csv"');

/* UTF-8 BOM (Fixes Excel mobile encoding) */
echo "\xEF\xBB\xBF";

/* =====================================
   OPEN OUTPUT
===================================== */
$output = fopen('php://output', 'w');

/* =====================================
   CSV HEADERS
===================================== */
fputcsv($output, [
    'Date',
    'Prescription No',
    'Last Name',
    'First Name',
    'Middle Name',
    'Health Facility',
    'Contact No.',
    'Planet Pharma',
    'Transmitted'
]);

/* =====================================
   QUERY
===================================== */

$sql = "
SELECT
    pr.created_at,
    pr.prescription_number,

    CASE
        WHEN pr.transmittal_id IS NULL THEN 'No'
        ELSE 'Yes'
    END AS transmitted,

    pt.first_name,
    pt.middle_name,
    pt.last_name,
    pt.contact_number,

    hf.facility_name,

    COALESCE(ph.pharmacy_name,'N/A') AS pharmacy_name

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

/* =====================================
   FACILITY FILTER
===================================== */

if (!empty($_GET['facilities'])) {

    $facilityIds = explode(',', $_GET['facilities']);

    $facilityIds = array_filter($facilityIds, 'is_numeric');

    if (!empty($facilityIds)) {

        $placeholders = implode(',', array_fill(0, count($facilityIds), '?'));

        $conditions[] = "pr.facility_id IN ($placeholders)";

        $params = array_merge($params, $facilityIds);
    }
}

/* =====================================
   DATE FILTER
===================================== */

if (!empty($_GET['from']) && !empty($_GET['to'])) {

    $conditions[] = "DATE(pr.created_at) BETWEEN ? AND ?";

    $params[] = $_GET['from'];
    $params[] = $_GET['to'];
}

/* =====================================
   APPLY FILTERS
===================================== */

if (!empty($conditions)) {
    $sql .= " AND " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY pr.created_at DESC";

/* =====================================
   EXECUTE
===================================== */

$stmt = $conn->prepare($sql);
$stmt->execute($params);

/* =====================================
   EXPORT
===================================== */

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

    fputcsv($output, [

        date('Y-m-d', strtotime($row['created_at'])),

        'PR-' . str_pad($row['prescription_number'], 5, '0', STR_PAD_LEFT),

        $row['last_name'],

        $row['first_name'],

        $row['middle_name'] ?: '-',

        $row['facility_name'],

        $row['contact_number'],

        $row['pharmacy_name'],

        $row['transmitted']

    ]);
}

fclose($output);
exit;