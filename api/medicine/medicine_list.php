<?php
session_start();
require '../../config/db.php';
header('Content-Type: application/json');

/* ---------- AUTH ---------- */
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

/*
|--------------------------------------------------------------------------
| SERVER-SIDE DATATABLES PROCESSING
|--------------------------------------------------------------------------
| This is the difference between "loads fine with 50 test rows" and
| "still loads instantly at 100,000+ rows": pagination, search, and sort
| all happen in SQL (LIMIT/OFFSET, WHERE, ORDER BY) instead of fetching
| every row and letting DataTables page through them client-side. Only
| the current page's worth of rows (e.g. 10-25) is ever sent over the
| wire or held in the browser, regardless of how large the medicines
| table grows.
|--------------------------------------------------------------------------
*/

$draw   = isset($_GET['draw'])   ? (int)$_GET['draw']   : 1;
$start  = isset($_GET['start'])  ? (int)$_GET['start']  : 0;
$length = isset($_GET['length']) ? (int)$_GET['length'] : 10;

// Hard cap page size so a crafted request can't force-load huge pages.
$length = ($length > 0 && $length <= 100) ? $length : 10;
$start  = max($start, 0);

$searchValue = trim($_GET['search']['value'] ?? '');

/*
 * Whitelist of sortable columns — MUST match the column order defined
 * in medicine_list.php's DataTable `columns` config on the client.
 * Never interpolate $_GET['order'] directly into SQL.
 * Column 0 (#) is the row number, not a real sortable field.
 * Column 6 (Action) is not sortable either.
 */
$sortable = [
    1 => 'generic_name',
    2 => 'dosage',
    3 => 'uom',
    4 => 'duration',
    5 => 'signa',
    6 => 'status',
];

$orderColIndex = (int)($_GET['order'][0]['column'] ?? 1);
$orderDir      = (strtolower($_GET['order'][0]['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
$orderCol      = $sortable[$orderColIndex] ?? 'generic_name';

/* ---------- WHERE (shared by count + data queries) ---------- */
$where  = [];
$params = [];

if ($searchValue !== '') {
    $where[]  = "(generic_name LIKE ? OR brand_name LIKE ? OR signa LIKE ? OR description LIKE ?)";
    $like     = "%{$searchValue}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

/* ---------- recordsTotal (unfiltered) ---------- */
$recordsTotal = (int) $conn->query("SELECT COUNT(*) FROM medicines")->fetchColumn();

/* ---------- recordsFiltered (with search applied) ---------- */
$countStmt = $conn->prepare("SELECT COUNT(*) FROM medicines $whereSQL");
$countStmt->execute($params);
$recordsFiltered = (int) $countStmt->fetchColumn();

/* ---------- DATA (one page only) ---------- */
$sql = "
    SELECT id, generic_name, brand_name, dosage, uom, duration, signa, description, status
    FROM medicines
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