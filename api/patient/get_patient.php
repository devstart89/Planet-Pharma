<?php
require '../../config/db.php';

header("Content-Type: application/json");

$id = $_GET['patient_id'] ?? null;

if(!$id){
    echo json_encode(null);
    exit;
}

$stmt = $conn->prepare("
    SELECT * FROM patients WHERE id = ?
");
$stmt->execute([$id]);

echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));