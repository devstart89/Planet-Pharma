<?php
session_start();
require '../../config/db.php';

// Only super admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$stmt = $conn->prepare("SELECT id, facility_name FROM health_facilities WHERE status='active' ORDER BY facility_name ASC");
$stmt->execute();
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['data' => $data]);