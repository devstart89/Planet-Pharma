<?php
require '../../../config/db.php';

header("Content-Type: application/json");

try {

    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
    $input = json_decode(file_get_contents("php://input"), true);

    $id = $input['id'] ?? null;

    if (!$apiKey || !$id) {
        throw new Exception("Missing API key or transmission ID");
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

    $stmt = $conn->prepare("
        UPDATE transmittals
        SET status = 'Received'
        WHERE id = ?
    ");

    $stmt->execute([$id]);

    echo json_encode([
        "status" => "success",
        "message" => "Transmission acknowledged"
    ]);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}