<?php
require_once '../../config/db.php';

header("Content-Type: application/json");

/* ---------- RESPONSE HELPER ---------- */
function respond($status, $message, $data = null, $code = 200) {
    http_response_code($code);
    echo json_encode([
        "status"  => $status,
        "message" => $message,
        "data"    => $data
    ]);
    exit;
}

try {

    $stmt = $conn->prepare("
        SELECT 
            hf.id,
            hf.facility_name,
            hf.pharmacy_id,
            p.pharmacy_name
        FROM health_facilities hf
        LEFT JOIN pharmacy p ON hf.pharmacy_id = p.id
        WHERE hf.status = 'active'
        ORDER BY hf.facility_name ASC
    ");

    $stmt->execute();
    $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    respond("success", "Facilities fetched", $facilities);

} catch (Exception $e) {
    respond("error", "Server error", null, 500);
}