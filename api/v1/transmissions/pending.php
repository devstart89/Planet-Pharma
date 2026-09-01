<?php
require '../../../config/db.php';

header("Content-Type: application/json");

try {

    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;

    if (!$apiKey) {
        throw new Exception("API Key required");
    }

    // validate API key
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

    $stmt = $conn->prepare("
        SELECT 
            t.id,
            t.date_generated,
            t.prescription_date,
            t.num_patients,
            t.status,
            t.transmitted,
            t.transmitted_at
        FROM transmittals t
        WHERE t.facility_id = ?
        ORDER BY t.id DESC
    ");

    $stmt->execute([$facilityId]);
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