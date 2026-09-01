<?php
include '../../config/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    echo json_encode(['error'=>'Unauthorized']);
    exit;
}

$facility_id = $_SESSION['user']['facility_id'];
$id = $_POST['id'] ?? null;

if (!$id) {
    echo json_encode(['error'=>'Invalid ID']);
    exit;
}

try {

    $stmt = $conn->prepare("
        UPDATE patients 
        SET status = 'ARCHIVED'
        WHERE id = ? AND facility_id = ?
    ");

    $stmt->execute([$id, $facility_id]);

    echo json_encode(['success'=>true]);

} catch(Exception $e){
    echo json_encode(['error'=>$e->getMessage()]);
}