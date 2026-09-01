<?php
include '../config/db.php';
session_start();

header('Content-Type: application/json');

/* AUTH */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['health_facility','doctor'])) {
    http_response_code(403);
    echo json_encode(['error'=>'Unauthorized']);
    exit;
}

$facility_id = $_SESSION['user']['facility_id'];

/* FILTER */
$type = $_GET['type'] ?? 'all';

$where = "";
$params = [$facility_id];

if ($type === 'employee') {
    $where = "AND makati_employee = 'YES'";
} elseif ($type === 'non') {
    $where = "AND (makati_employee = 'NO' OR makati_employee IS NULL)";
}

/* QUERY */
$stmt = $conn->prepare("
    SELECT id, his_id, CONCAT(first_name,' ',last_name) AS fullname,
           gender, birthday AS dob
    FROM patients
    WHERE facility_id = ?
    $where
    ORDER BY id DESC
");

$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    "data" => $data
]);