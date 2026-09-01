<?php
/**
 * Returns full details for transmitted prescriptions: the transmittal
 * batch itself, plus for each prescription in it — the patient, the
 * prescription record, and its prescribed medicines.
 *
 * Auth: NTLM (Windows domain), not the app's PHP-session login — meant
 * for machine-to-machine / internal-network integrations (e.g. a
 * facility's own domain-joined system polling for transmitted data),
 * not for browser access from the app's regular UI.
 *
 * Usage:
 *   GET ?transmittal_id=123        -> whole batch + all its prescriptions
 *   GET ?prescription_id=456       -> just that one prescription's batch
 *                                      context + its own details
 */

require '../../config/db.php';
require_once '../../includes/ntlm_auth.php';

header('Content-Type: application/json');

/* ================= AUTH (NTLM) ================= */
$ntlmUser = ntlm_authenticate_via_webserver();

function response($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$transmittalId  = $_GET['transmittal_id'] ?? null;
$prescriptionId = $_GET['prescription_id'] ?? null;

if (!$transmittalId && !$prescriptionId) {
    response(['status' => 'error', 'message' => 'transmittal_id or prescription_id is required'], 422);
}

try {

    /* ---------- RESOLVE THE TRANSMITTAL ---------- */
    if ($prescriptionId) {

        $stmt = $conn->prepare("
            SELECT t.*
            FROM transmittals t
            JOIN prescriptions p ON p.transmittal_id = t.id
            WHERE p.id = ?
        ");
        $stmt->execute([$prescriptionId]);
        $transmittal = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transmittal) {
            response(['status' => 'error', 'message' => 'Prescription not found or not yet transmitted'], 404);
        }

        $prescriptionIds = [$prescriptionId];

    } else {

        $stmt = $conn->prepare("SELECT * FROM transmittals WHERE id = ?");
        $stmt->execute([$transmittalId]);
        $transmittal = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transmittal) {
            response(['status' => 'error', 'message' => 'Transmittal not found'], 404);
        }

        $stmt = $conn->prepare("SELECT prescription_id FROM transmittal_prescriptions WHERE transmittal_id = ?");
        $stmt->execute([$transmittalId]);
        $prescriptionIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'prescription_id');
    }

    if (empty($prescriptionIds)) {
        response([
            'status' => 'success',
            'data' => [
                'transmittal'   => $transmittal,
                'prescriptions' => []
            ]
        ]);
    }

    $placeholders = implode(',', array_fill(0, count($prescriptionIds), '?'));

    /* ---------- PRESCRIPTION + PATIENT ---------- */
    $stmt = $conn->prepare("
        SELECT
            p.id                 AS prescription_id,
            p.prescription_number,
            p.diagnosis,
            p.status             AS prescription_status,
            p.created_at         AS prescription_created_at,
            p.transmitted_at,
            pt.id                AS patient_id,
            pt.his_id,
            pt.first_name,
            pt.last_name,
            pt.gender,
            pt.birthday,
            pt.makati_employee,
            pt.makati_health_plus_no,
            pt.member_card_no
        FROM prescriptions p
        JOIN patients pt ON pt.id = p.patient_id
        WHERE p.id IN ($placeholders)
        ORDER BY p.id ASC
    ");
    $stmt->execute($prescriptionIds);
    $prescriptionRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ---------- PRESCRIBED MEDICINES ----------
       NOTE: adjust table/column names below if your schema differs —
       this assumes a `prescription_medicines` table keyed by
       `prescription_id`. */
    $stmt = $conn->prepare("
        SELECT
            prescription_id,
            medicine_name,
            dosage,
            frequency,
            duration,
            quantity,
            instructions
        FROM prescription_medicines
        WHERE prescription_id IN ($placeholders)
        ORDER BY id ASC
    ");
    $stmt->execute($prescriptionIds);
    $medicineRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $medicinesByPrescription = [];
    foreach ($medicineRows as $m) {
        $medicinesByPrescription[$m['prescription_id']][] = [
            'medicine_name' => $m['medicine_name'],
            'dosage'        => $m['dosage'],
            'frequency'     => $m['frequency'],
            'duration'      => $m['duration'],
            'quantity'      => $m['quantity'],
            'instructions'  => $m['instructions'],
        ];
    }

    /* ---------- ASSEMBLE RESPONSE ---------- */
    $prescriptions = array_map(function ($row) use ($medicinesByPrescription) {
        return [
            'prescription' => [
                'id'                  => $row['prescription_id'],
                'prescription_number' => $row['prescription_number'],
                'diagnosis'           => $row['diagnosis'],
                'status'              => $row['prescription_status'],
                'created_at'          => $row['prescription_created_at'],
                'transmitted_at'      => $row['transmitted_at'],
            ],
            'patient' => [
                'id'                    => $row['patient_id'],
                'his_id'                => $row['his_id'],
                'full_name'             => trim($row['first_name'] . ' ' . $row['last_name']),
                'gender'                => $row['gender'],
                'birthday'              => $row['birthday'],
                'makati_employee'       => $row['makati_employee'],
                'makati_health_plus_no' => $row['makati_health_plus_no'],
                'member_card_no'        => $row['member_card_no'],
            ],
            'medicines' => $medicinesByPrescription[$row['prescription_id']] ?? [],
        ];
    }, $prescriptionRows);

    response([
        'status' => 'success',
        'authenticated_as' => $ntlmUser,
        'data' => [
            'transmittal'   => $transmittal,
            'prescriptions' => $prescriptions
        ]
    ]);

} catch (Exception $e) {
    response(['status' => 'error', 'message' => 'Server error'], 500);
}
