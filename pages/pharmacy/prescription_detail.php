<?php
session_start();
require '../../config/db.php';
require '../../includes/pharmacy_helpers.php';
header('Content-Type: application/json');

/*
 * ================= PRESCRIPTION DETAIL (for dispense modal) =================
 * Powers the "View & Dispense Rx" modal on queue.php. Given a queue_id,
 * returns the full prescription header + its medicines so pharmacy staff
 * can review everything in one place instead of opening a separate page.
 *
 * MULTI-PHARMACY: the lookup is scoped through the queue row's own
 * pharmacy_id (not just the prescription id), so a queue_id belonging to
 * another branch simply won't resolve here.
 */
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'pharmacy') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$pharmacyId = (int) ($_SESSION['user']['pharmacy_id'] ?? 0);
$pharmacy = resolvePharmacyById($conn, $pharmacyId);
if (!$pharmacy) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Account is not assigned to an active pharmacy location.']);
    exit;
}

$queueId = (int) ($_GET['queue_id'] ?? 0);
if (!$queueId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing queue_id.']);
    exit;
}

$stmt = $conn->prepare("
    SELECT q.id AS queue_id, q.status AS queue_status, q.source, q.queue_number, q.category,
           q.prescription_id
    FROM queues q
    WHERE q.id = ? AND q.pharmacy_id = ?
");
$stmt->execute([$queueId, $pharmacyId]);
$queue = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$queue || $queue['source'] !== 'E-Pres' || !$queue['prescription_id']) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No E-Pres prescription found for this queue entry.']);
    exit;
}

$stmt = $conn->prepare("
    SELECT pr.id, pr.prescription_number, pr.diagnosis, pr.is_refill, pr.remarks,
           pr.status, pr.medicine_status, pr.transmitted_at, pr.signed_by, pr.signed_at,
           pat.first_name, pat.last_name,
           doc.first_name AS doctor_first_name, doc.last_name AS doctor_last_name,
           hf.facility_name
    FROM prescriptions pr
    LEFT JOIN patients pat ON pr.patient_id = pat.id
    LEFT JOIN users doc ON pr.doctor_id = doc.id
    LEFT JOIN health_facilities hf ON pr.facility_id = hf.id
    WHERE pr.id = ?
");
$stmt->execute([$queue['prescription_id']]);
$prescription = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prescription) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Prescription record not found.']);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, medicine_name, dosage, frequency, duration, quantity,
           dispensed_quantity, pharmacy_status, pharmacy_reason, served_at
    FROM prescription_medicines
    WHERE prescription_id = ?
    ORDER BY id ASC
");
$stmt->execute([$prescription['id']]);
$medicines = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'status' => 'success',
    'queue' => [
        'id' => (int) $queue['queue_id'],
        'status' => $queue['queue_status'],
        'category' => $queue['category'],
        'queue_number' => (int) $queue['queue_number'],
    ],
    'prescription' => [
        'id' => (int) $prescription['id'],
        'prescription_number' => $prescription['prescription_number'],
        'patient_name' => trim($prescription['first_name'] . ' ' . $prescription['last_name']),
        'doctor_name' => trim(($prescription['doctor_first_name'] ?? '') . ' ' . ($prescription['doctor_last_name'] ?? '')),
        'facility_name' => $prescription['facility_name'],
        'diagnosis' => $prescription['diagnosis'],
        'is_refill' => $prescription['is_refill'],
        'remarks' => $prescription['remarks'],
        'status' => $prescription['status'],
        'medicine_status' => $prescription['medicine_status'],
        'transmitted_at' => $prescription['transmitted_at'],
        'signed_by' => $prescription['signed_by'],
        'signed_at' => $prescription['signed_at'],
    ],
    'medicines' => array_map(fn($m) => [
        'id' => (int) $m['id'],
        'medicine_name' => $m['medicine_name'],
        'dosage' => $m['dosage'],
        'frequency' => $m['frequency'],
        'duration' => $m['duration'],
        'quantity' => (int) $m['quantity'],
        'dispensed_quantity' => $m['dispensed_quantity'] !== null ? (int) $m['dispensed_quantity'] : null,
        'pharmacy_status' => $m['pharmacy_status'] ?: 'Pending',
        'pharmacy_reason' => $m['pharmacy_reason'],
        'served_at' => $m['served_at'],
    ], $medicines),
]);
$conn = null;