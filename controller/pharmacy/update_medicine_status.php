<?php
session_start();
header('Content-Type: application/json');
require '../../config/db.php';

/* Only pharmacy may write to this endpoint — everyone else is rejected
   even if they craft the request manually. */
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'pharmacy') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized.']);
    exit;
}

$medicineId = $_POST['medicine_id'] ?? null;
$newStatus  = $_POST['status'] ?? null;
$allowedStatuses = ['Pending', 'Partial', 'Dispensed', 'Unclaimed'];

if (!$medicineId || !in_array($newStatus, $allowedStatuses, true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
    exit;
}

/* Confirm the medicine exists and belongs to a SIGNED prescription —
   pharmacy should never be able to dispense against an unsigned Rx,
   even if they tamper with the request. */
$stmt = $conn->prepare("
    SELECT pm.id, p.status AS prescription_status
    FROM prescription_medicines pm
    JOIN prescriptions p ON pm.prescription_id = p.id
    WHERE pm.id = ?
");
$stmt->execute([$medicineId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['status' => 'error', 'message' => 'Medicine not found.']);
    exit;
}

if ($row['prescription_status'] !== 'Signed') {
    echo json_encode(['status' => 'error', 'message' => 'Prescription must be signed before dispensing status can be updated.']);
    exit;
}

$update = $conn->prepare("UPDATE prescription_medicines SET pharmacy_status = ? WHERE id = ?");
$update->execute([$newStatus, $medicineId]);

echo json_encode(['status' => 'success', 'message' => 'Medicine status updated.']);