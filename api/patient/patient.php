<?php
include '../../config/db.php';
session_start();
header('Content-Type: application/json');

/* ---------- AUTH ---------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['health_facility','doctor','super_admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user        = $_SESSION['user'];
$role        = $user['role'];
$facility_id = $user['facility_id'];

/* ==========================================================
   DATATABLES SERVER-SIDE PARAMS
   (DataTables sends these automatically once serverSide:true
   is set on the client — see JS snippet)
   ========================================================== */
$draw   = isset($_GET['draw'])   ? (int)$_GET['draw']   : 1;
$start  = isset($_GET['start'])  ? (int)$_GET['start']  : 0;
$length = isset($_GET['length']) ? (int)$_GET['length'] : 25;

// Hard cap page size so a crafted request can't force-load huge pages
$length = ($length > 0 && $length <= 100) ? $length : 25;
$start  = max($start, 0);

$searchValue = trim($_GET['search']['value'] ?? '');

/* Status computed once, reused in SELECT + ORDER BY.
   Uses the denormalized p.last_prescription_date column instead
   of MAX(prescriptions.created_at) — avoids joining/aggregating
   the entire prescriptions table on every request. */
$statusExpr = "
    CASE
        WHEN p.status = 'ARCHIVED' THEN 'ARCHIVED'
        WHEN p.last_prescription_date IS NULL THEN 'ACTIVE'
        WHEN DATEDIFF(NOW(), p.last_prescription_date) > 60 THEN 'INACTIVE'
        ELSE 'ACTIVE'
    END
";

/* Whitelist of sortable columns — MUST match the column order
   defined in the DataTable `columns` config on the client.
   Never interpolate $_GET['order'] directly into SQL. */
$sortable = [
    1 => 'fullname',
    2 => 'p.birthday',
    3 => 'p.birthday',
    4 => $statusExpr,
    5 => 'p.last_prescription_date',
    6 => 'p.last_medical_consult',
];

$orderColIndex = (int)($_GET['order'][0]['column'] ?? 1);
$orderDir      = (strtolower($_GET['order'][0]['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
$orderCol      = $sortable[$orderColIndex] ?? 'p.id';

/* ---------- WHERE (shared by count + data queries) ---------- */
$where  = [];
$params = [];

if ($role !== 'super_admin') {
    $where[]  = "p.facility_id = ?";
    $params[] = $facility_id;
}

$where[] = "p.status != 'ARCHIVED'";

if ($searchValue !== '') {
    $where[]  = "(CONCAT(p.first_name,' ',p.last_name) LIKE ?
                  OR p.member_card_no LIKE ?
                  OR p.makati_health_plus_no LIKE ?)";
    $like     = "%{$searchValue}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

/* ---------- recordsTotal (scope only, no search filter) ---------- */
$totalWhere  = [];
$totalParams = [];
if ($role !== 'super_admin') {
    $totalWhere[]  = "p.facility_id = ?";
    $totalParams[] = $facility_id;
}
$totalWhere[] = "p.status != 'ARCHIVED'";

$totalStmt = $conn->prepare("SELECT COUNT(*) FROM patients p WHERE " . implode(' AND ', $totalWhere));
$totalStmt->execute($totalParams);
$recordsTotal = (int)$totalStmt->fetchColumn();

/* ---------- recordsFiltered (scope + search) ---------- */
$countStmt = $conn->prepare("SELECT COUNT(*) FROM patients p $whereSQL");
$countStmt->execute($params);
$recordsFiltered = (int)$countStmt->fetchColumn();

/* ---------- DATA (one page only) ---------- */
$sql = "
    SELECT
        p.id,
        p.member_card_no,
        p.makati_health_plus_no,
        p.last_prescription_date,
        p.last_medical_consult,
        p.priority_type,
        CONCAT(p.first_name,' ',p.last_name) AS fullname,
        p.gender,
        p.birthday AS dob,
        p.house_no_street,
        ac.cluster_name AS cluster_description,
        b.name AS health_center,
        $statusExpr AS computed_status
    FROM patients p
    LEFT JOIN clusters ac        ON ac.cluster_name = p.cluster
    LEFT JOIN hf_description b   ON b.name = p.barangay
    $whereSQL
    ORDER BY $orderCol $orderDir
    LIMIT $length OFFSET $start
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    "draw"            => $draw,
    "recordsTotal"    => $recordsTotal,
    "recordsFiltered" => $recordsFiltered,
    "data"            => $data,
]);