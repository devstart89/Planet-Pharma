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

    $sql = "
        SELECT 
            hf.id,
            hf.facility_name,
            hf.pharmacy_id,
            p.pharmacy_name
        FROM health_facilities hf
        LEFT JOIN pharmacy p ON hf.pharmacy_id = p.id
        WHERE hf.status = 'active'
    ";

    $params = [];

    // Optional scoping: only facilities belonging to a specific pharmacy
    if (!empty($_GET['pharmacy_id'])) {
        $sql .= " AND hf.pharmacy_id = ?";
        $params[] = $_GET['pharmacy_id'];
    }

    $sql .= " ORDER BY hf.facility_name ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    respond("success", "Facilities fetched", $facilities);

} catch (Exception $e) {
    respond("error", "Server error", null, 500);
}