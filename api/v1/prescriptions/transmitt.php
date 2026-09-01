<?php
/**
 * GET /api/v1/prescriptions/transmitted.php
 *
 * BC pulls unclaimed transmitted prescriptions from us via this
 * endpoint. Rewritten so the JSON shape matches
 * includes/ClientPrescriptionApi.php's buildPayload() field-for-field —
 * same key names, same prescriptionLines structure, same lineNo
 * scheme — so whatever consumes this endpoint sees the identical shape
 * BC already receives from our push sync. Previously this used
 * different field names entirely (prescription_number vs
 * prescriptionID, patient.name vs patientFirstName/patientLastName,
 * medicines[].name vs prescriptionLines[].medicine, etc.), which meant
 * two representations of the same data to keep in sync by hand.
 *
 * Eligibility criteria is unchanged from the original: only pulls rows
 * from transmittals where transmitted = 0, prescriptions.status =
 * 'Signed', and not already pulled — see the WHERE clause below.
 *
 * Auth/rate-limit/logging now come from the shared
 * api/v1/includes/api_common.php instead of being duplicated inline
 * (same helpers used by the queue and transmittals endpoints).
 */
require '../../../config/db.php';
$env = require '../../../config/env2.php';
require '../includes/api_common.php';

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

[$key, $requestId] = requireApiAuth($conn, $env);
$apiKey = $key['api_key'];

// ================= PAGINATION =================
$page = max(1, (int) ($_GET['page'] ?? 1));
$limitPage = min((int) ($_GET['limit'] ?? $env['DEFAULT_PAGE_LIMIT']), $env['MAX_PAGE_LIMIT']);
$offset = ($page - 1) * $limitPage;

// ================= QUERY =================
// Same columns ClientPrescriptionApi::buildPayload() selects (patient
// address parts, member_card_no, makati_health_plus_no, age, the
// created_by user for healthcareAssistant, signa instead of
// frequency), plus the transmittal-eligibility WHERE clause this
// endpoint has always used.
$sql = "
SELECT
    p.id,
    p.prescription_number,
    p.created_at,

    pat.member_card_no,
    pat.makati_health_plus_no,
    pat.first_name  AS patient_first_name,
    pat.middle_name AS patient_middle_name,
    pat.last_name   AS patient_last_name,
    pat.age,
    pat.gender,
    pat.house_no_street,
    pat.barangay,

    doc.first_name AS doc_first_name,
    doc.last_name  AS doc_last_name,

    cb.first_name AS created_by_first_name,
    cb.last_name  AS created_by_last_name,

    pm.medicine_name,
    pm.dosage,
    pm.signa,
    pm.duration,
    pm.quantity,
    pm.notes

FROM transmittals t
JOIN transmittal_prescriptions tp ON tp.transmittal_id = t.id
JOIN prescriptions p ON p.id = tp.prescription_id
JOIN patients pat ON pat.id = p.patient_id
LEFT JOIN users doc ON doc.id = p.doctor_id
LEFT JOIN users cb  ON cb.id = p.created_by
LEFT JOIN prescription_medicines pm ON pm.prescription_id = p.id

WHERE
    t.transmitted = 0
    AND p.status = 'Signed'
    AND (p.pulled = 0 OR p.pulled IS NULL)

ORDER BY p.id DESC
LIMIT $limitPage OFFSET $offset
";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('[api/v1/prescriptions/transmitted] ' . $e->getMessage());
    apiResponse(500, ['status' => 'error', 'error' => 'Database query failed', 'request_id' => $requestId]);
}

// ================= FORMAT (matches ClientPrescriptionApi::buildPayload) =================
$result = [];
$lineCounters = []; // per-prescription lineNo counter, same 1000/2000/3000... scheme as buildPayload()
$ids = [];

foreach ($rows as $row) {
    $pid = $row['id'];
    $ids[] = $pid;

    if (!isset($result[$pid])) {
        $address = trim(
            ($row['house_no_street'] ?? '') .
            (!empty($row['barangay']) ? ', ' . $row['barangay'] : '')
        );

        $healthcareAssistant = trim(
            ($row['created_by_first_name'] ?? '') . ' ' . ($row['created_by_last_name'] ?? '')
        );

        $result[$pid] = [
            'prescriptionID'       => $row['prescription_number'],
            'memberCardNo'         => $row['member_card_no'] ?? '',
            'healthPlusNo'         => $row['makati_health_plus_no'] ?? '',
            'prescriptionDate'     => date('Y-m-d', strtotime($row['created_at'])),
            'patientFirstName'     => $row['patient_first_name'] ?? '',
            'patientMiddleName'    => $row['patient_middle_name'] ?? '',
            'patientLastName'      => $row['patient_last_name'] ?? '',
            'age'                  => (int) ($row['age'] ?? 0),
            'gender'               => $row['gender'] ?? '',
            'address'              => $address,
            'prescribingDoctor'    => trim(($row['doc_first_name'] ?? '') . ' ' . ($row['doc_last_name'] ?? '')),
            'healthcareAssistant'  => $healthcareAssistant ?: 'Unknown',
            // Same known gap as buildPayload() — no equivalent field in
            // `pharmacy` (only id/slug/pharmacy_name, no "number").
            'pharmacyNo'           => '',
            'prescriptionLines'    => [],
        ];
        $lineCounters[$pid] = 0;
    }

    if (!empty($row['medicine_name'])) {
        $lineCounters[$pid] += 1000;
        $result[$pid]['prescriptionLines'][] = [
            'prescriptionID' => $row['prescription_number'],
            'lineNo'         => $lineCounters[$pid],
            'medicine'       => $row['medicine_name'],
            'dosage'         => $row['dosage'],
            'signa'          => $row['signa'],
            'duration'       => is_numeric($row['duration']) ? (int) $row['duration'] : 0,
            'qty'            => (int) $row['quantity'],
            'notes'          => $row['notes'] ?? '',
        ];
    }
}

$result = array_values($result);

// ================= MARK AS PULLED =================
function markAsPulled(PDO $conn, string $apiKey, array $ids): void
{
    if (empty($ids)) return;

    $ids = array_values(array_unique($ids));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $conn->prepare("
        UPDATE prescriptions
        SET pulled = 1, pulled_by = ?, updated_at = NOW()
        WHERE id IN ($placeholders)
    ");
    $stmt->execute(array_merge([$apiKey], $ids));
}

markAsPulled($conn, $apiKey, $ids);

logApiRequest($conn, $env, $apiKey, $requestId);

apiResponse(200, [
    'status' => 'success',
    'count' => count($result),
    'page' => $page,
    'request_id' => $requestId,
    'data' => $result,
]);