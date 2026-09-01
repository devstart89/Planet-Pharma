<?php
session_start();
require '../../config/db.php';
require '../../includes/pharmacy_helpers.php';
header('Content-Type: application/json');

/*
 * ================= PRESCRIPTION DISPENSE ACTIONS =================
 * Backs the "View & Dispense Rx" modal on queue.php. Two actions:
 *
 *   action=complete  — pharmacy has gone through every medicine and
 *   marked each Dispensed / Partial / Not Dispensed (with a reason for
 *   anything short of fully Dispensed). Requires the queue to currently
 *   be "Now Serving" — you can't complete a dispense for a patient who
 *   hasn't been called up yet. Updates every prescription_medicines row,
 *   rolls the outcome up to prescriptions.medicine_status, and closes
 *   the queue entry as Completed.
 *
 *   action=unclaimed — patient was called (queue is "Now Serving") but
 *   never came to the counter. Closes the queue entry as Unclaimed
 *   without touching any medicine rows or prescriptions.medicine_status,
 *   so the prescription is still valid for them to queue again later.
 *
 * MULTI-PHARMACY: every lookup is scoped through the queue row's own
 * pharmacy_id, exactly like the rest of queue.php.
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

$action = $_POST['action'] ?? '';
$queueId = (int) ($_POST['queue_id'] ?? 0);

if (!$queueId || !in_array($action, ['complete', 'unclaimed'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request.']);
    exit;
}

$stmt = $conn->prepare("
    SELECT q.id, q.status, q.source, q.prescription_id
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

if ($queue['status'] !== 'Now Serving') {
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'This patient must be "Now Serving" before you can complete or mark this unclaimed.']);
    exit;
}

$prescriptionId = (int) $queue['prescription_id'];

/* ---------- ACTION: UNCLAIMED ---------- */
if ($action === 'unclaimed') {
    $reason = trim($_POST['reason'] ?? '');

    $stmt = $conn->prepare("UPDATE queues SET status = 'Unclaimed', unclaimed_at = NOW(), reason = ? WHERE id = ?");
    $stmt->execute([$reason ?: null, $queueId]);

    echo json_encode(['status' => 'success', 'outcome' => 'Unclaimed']);
    exit;
}

/* ---------- ACTION: COMPLETE (dispense / partial per medicine) ---------- */
$allowedStatuses = ['Dispensed', 'Partial', 'Not Dispensed'];
$medicinesInput = json_decode($_POST['medicines'] ?? '[]', true);

if (!is_array($medicinesInput) || !count($medicinesInput)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No medicine data submitted.']);
    exit;
}

// Load the real medicine rows for this prescription so we only ever
// touch rows that actually belong to it (never trust client-sent IDs
// blindly).
$stmt = $conn->prepare("SELECT id, quantity FROM prescription_medicines WHERE prescription_id = ?");
$stmt->execute([$prescriptionId]);
$validMedicines = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $validMedicines[(int) $m['id']] = (int) $m['quantity'];
}

$updates = [];
foreach ($medicinesInput as $row) {
    $medId = (int) ($row['id'] ?? 0);
    $pharmacyStatus = $row['pharmacy_status'] ?? '';
    $reason = trim($row['pharmacy_reason'] ?? '');
    $dispensedQty = isset($row['dispensed_quantity']) ? (int) $row['dispensed_quantity'] : 0;

    if (!isset($validMedicines[$medId])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "Medicine id $medId does not belong to this prescription."]);
        exit;
    }
    if (!in_array($pharmacyStatus, $allowedStatuses, true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "Missing/invalid status for medicine id $medId."]);
        exit;
    }
    if ($pharmacyStatus !== 'Dispensed' && $reason === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "A reason is required for any medicine not fully Dispensed."]);
        exit;
    }

    $maxQty = $validMedicines[$medId];
    $dispensedQty = max(0, min($dispensedQty, $maxQty));

    $updates[] = [
        'id' => $medId,
        'pharmacy_status' => $pharmacyStatus,
        'reason' => $pharmacyStatus === 'Dispensed' ? null : $reason,
        'dispensed_quantity' => $dispensedQty,
        // Legacy coarse status column, kept in sync for any other part
        // of the app that still reads prescription_medicines.status.
        'legacy_status' => $pharmacyStatus === 'Dispensed' ? 'dispensed' : 'not-dispensed',
    ];
}
// Every medicine on the prescription must be accounted for before we
// allow Complete — partial submissions would leave stale 'Pending' rows.
if (count($updates) !== count($validMedicines)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Every medicine must have a status before completing.']);
    exit;
}

$conn->beginTransaction();
try {
    $upd = $conn->prepare("
        UPDATE prescription_medicines
        SET pharmacy_status = ?, pharmacy_reason = ?, dispensed_quantity = ?, status = ?, served_at = NOW()
        WHERE id = ?
    ");
    $allDispensed = true;
    foreach ($updates as $u) {
        $upd->execute([$u['pharmacy_status'], $u['reason'], $u['dispensed_quantity'], $u['legacy_status'], $u['id']]);
        if ($u['pharmacy_status'] !== 'Dispensed') {
            $allDispensed = false;
        }
    }

    $medicineStatus = $allDispensed ? 'Dispensed' : 'Partial';
    $stmt = $conn->prepare("UPDATE prescriptions SET medicine_status = ?, dispensed_at = NOW() WHERE id = ?");
    $stmt->execute([$medicineStatus, $prescriptionId]);

    $stmt = $conn->prepare("UPDATE queues SET status = 'Completed', completed_at = NOW() WHERE id = ?");
    $stmt->execute([$queueId]);

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Could not save dispense details. Please try again.']);
    exit;
}

echo json_encode(['status' => 'success', 'outcome' => $medicineStatus]);
$conn = null;