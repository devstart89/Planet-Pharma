<?php
require '../../../config/db.php';

header("Content-Type: application/json");

try {

    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
    $id = $_GET['id'] ?? null;

    if (!$apiKey || !$id) {
        throw new Exception("Missing API key or ID");
    }

    $stmt = $conn->prepare("
        SELECT facility_id 
        FROM api_keys 
        WHERE api_key = ? AND status='active'
        LIMIT 1
    ");
    $stmt->execute([$apiKey]);
    $api = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$api) {
        throw new Exception("Invalid API Key");
    }

    $facilityId = $api['facility_id'];

    // fetch full details
    $stmt = $conn->prepare("
        SELECT 
            t.*,
            p.id AS prescription_id,
            p.prescription_number,
            p.diagnosis,

            pat.first_name,
            pat.last_name,

            pm.medicine_name,
            pm.quantity

        FROM transmittals t
        JOIN prescriptions p ON p.transmittal_id = t.id
        JOIN patients pat ON pat.id = p.patient_id
        JOIN prescription_medicines pm ON pm.prescription_id = p.id

        WHERE t.id = ?
          AND t.facility_id = ?
    ");

    $stmt->execute([$id, $facilityId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "data" => $rows
    ]);

} catch (Exception $e) {

    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}