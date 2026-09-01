<?php
session_start();
require '../../config/db.php';
header('Content-Type: application/json');

// Get logged-in user's facility ID
$facility_id = $_SESSION['user']['facility_id'] ?? null;
if (!$facility_id) {
    echo json_encode(['patients'=>[]]);
    exit;
}

// Get search keyword
$keyword = $_GET['keyword'] ?? '';
if (!$keyword) {
    echo json_encode(['patients'=>[]]);
    exit;
}

// Prepare query: search only patients in the same facility
$stmt = $conn->prepare("
    SELECT id, his_id, makati_health_plus_no, first_name, last_name, gender, birthday, barangay, contact_number, house_no_street, makati_employee
    FROM patients
    WHERE is_deleted = 0 
      AND facility_id = ? 
      AND status = 'ACTIVE'
      AND (makati_health_plus_no LIKE ? OR first_name LIKE ? OR last_name LIKE ?)
    LIMIT 10
");

$like = "%$keyword%";
$stmt->execute([$facility_id, $like, $like, $like]);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['patients' => $patients]);