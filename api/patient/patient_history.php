<?php
require '../../config/db.php';
header('Content-Type: application/json');

$patient_id = $_GET['patient_id'];

$stmt = $conn->prepare("
    SELECT diagnosis, created_at
    FROM prescriptions
    WHERE patient_id = ?
    ORDER BY created_at DESC
");

$stmt->execute([$patient_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));