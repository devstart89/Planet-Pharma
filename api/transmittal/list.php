<?php
session_start();
require '../../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'health_facility') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$facilityId = $_SESSION['user']['facility_id'];

try {

    $stmt = $conn->prepare("
        SELECT 
            t.id,
            t.date_generated,
            t.prescription_date,
            t.num_patients,
            t.pharmacist,
            t.generated_by,
            hf.facility_name
        FROM transmittals t
        LEFT JOIN health_facilities hf ON hf.id = t.facility_id
        WHERE t.facility_id = ?
        ORDER BY t.id DESC
    ");

    $stmt->execute([$facilityId]);

    echo json_encode([
        "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);

} catch (Exception $e) {

    http_response_code(500);
    echo json_encode([
        "error" => "Server error"
    ]);
}