<?php
/* ---------- AUTH ---------- */
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

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
            id,
            pharmacy_name,
            address,
            contact_number
        FROM pharmacy
        WHERE status = 'active'
        ORDER BY pharmacy_name ASC
    ");
    $stmt->execute();

    $pharmacies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    respond("success", "Pharmacies fetched", $pharmacies);

} catch (Exception $e) {
    respond("error", "Server error", null, 500);
}