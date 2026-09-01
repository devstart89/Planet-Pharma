<?php
session_start();
require '../../config/db.php';

header('Content-Type: application/json');

// Ensure only doctors can approve
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

try {
    // Begin transaction
    $conn->beginTransaction();

    // Collect POST data
    $prescription_id = $_POST['prescription_id'] ?? null;
    $diagnosis = trim($_POST['diagnosis'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $signature = $_POST['signature'] ?? '';
    $status = $_POST['status'] ?? 'Signed';
    $doctor_id = $_SESSION['user']['id'];

    if (!$prescription_id) {
        throw new Exception("Prescription ID is required.");
    }

    if (!$signature) {
        throw new Exception("Signature is required.");
    }

    // Decode and save signature
    $signatureData = base64_decode(str_replace(' ', '+', str_replace('data:image/png;base64,', '', $signature)));
    $signatureDir = '../../assets/uploads/signatures/';
    if (!is_dir($signatureDir)) mkdir($signatureDir, 0777, true);
    $signatureFile = $signatureDir . uniqid('sig_') . '.png';
    if (!file_put_contents($signatureFile, $signatureData)) {
        throw new Exception("Failed to save signature.");
    }

    /*
     * Update prescription.
     *
     * IMPORTANT: this now also writes doctor_id, not just signed_by.
     * A prescription created by a health_facility starts with
     * doctor_id = NULL (see prescriptions_process.php — doctor_id is
     * only set there if the CREATOR was already a doctor). Previously
     * this query only ever wrote signed_by, so doctor_id stayed NULL
     * forever for facility-created prescriptions — and the PDF
     * generator (generate_prescription_pdf.php) looks up the doctor's
     * name/license specifically via prescription.doctor_id, so it had
     * nothing to look up and silently rendered a blank name for every
     * role viewing/printing it.
     *
     * Keeping BOTH signed_by and doctor_id populated (same value) in
     * case signed_by is relied on elsewhere in the app already.
     */
    $stmt = $conn->prepare("
        UPDATE prescriptions
        SET diagnosis = ?, 
            status = ?, 
            remarks = ?, 
            signed_by = ?, 
            doctor_id = ?,
            signed_at = NOW(), 
            signature_path = ?
        WHERE id = ?
    ");
    $stmt->execute([$diagnosis, $status, $remarks, $doctor_id, $doctor_id, $signatureFile, $prescription_id]);

    // Handle medicines
    $existing_ids = $_POST['medicine_id'] ?? [];
    $names       = $_POST['medicine_name'] ?? [];
    $dosages     = $_POST['dosage'] ?? [];
    $frequencies = $_POST['frequency'] ?? [];
    $durations   = $_POST['duration'] ?? [];
    $quantities  = $_POST['quantity'] ?? [];
    $notes       = $_POST['notes'] ?? [];

    // Fetch current medicines in DB
    $dbStmt = $conn->prepare("SELECT id FROM prescription_medicines WHERE prescription_id = ?");
    $dbStmt->execute([$prescription_id]);
    $db_ids = $dbStmt->fetchAll(PDO::FETCH_COLUMN);

    $submitted_ids = [];

    for ($i = 0; $i < count($names); $i++) {
        if (empty($names[$i])) continue;

        $med_id = $existing_ids[$i] ?? null;

        if ($med_id) {
            // Update existing medicine
            $update = $conn->prepare("
                UPDATE prescription_medicines
                SET medicine_name = ?, dosage = ?, frequency = ?, duration = ?, quantity = ?, notes = ?
                WHERE id = ?
            ");
            $update->execute([
                $names[$i],
                $dosages[$i] ?? '',
                $frequencies[$i] ?? '',
                $durations[$i] ?? '',
                $quantities[$i] ?? 0,
                $notes[$i] ?? '',
                $med_id
            ]);
            $submitted_ids[] = $med_id;
        } else {
            // Insert new medicine
            $insert = $conn->prepare("
                INSERT INTO prescription_medicines
                (prescription_id, medicine_name, dosage, frequency, duration, quantity, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([
                $prescription_id,
                $names[$i],
                $dosages[$i] ?? '',
                $frequencies[$i] ?? '',
                $durations[$i] ?? '',
                $quantities[$i] ?? 0,
                $notes[$i] ?? ''
            ]);
        }
    }

    // Delete removed medicines
    foreach ($db_ids as $db_id) {
        if (!in_array($db_id, $submitted_ids)) {
            $delete = $conn->prepare("DELETE FROM prescription_medicines WHERE id = ?");
            $delete->execute([$db_id]);
        }
    }

    $conn->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'Prescription signed and saved successfully.'
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}