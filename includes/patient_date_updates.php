<?php
/**
 * updatePatientDatesAfterPrescription()
 * --------------------------------------
 * Decides which of the patient's tracking dates to update after a
 * prescription is submitted, based on role + refill state.
 *
 * Rules (as specified):
 *  - health_facility + loaded from history + nothing new added
 *      => PURE REFILL: only last_refill_date is updated.
 *         last_prescription_date and last_medical_consult are left alone.
 *  - doctor + loaded from history (regardless of whether anything was
 *    added on top)
 *      => update last_prescription_date, last_medical_consult, AND
 *         last_refill_date.
 *  - doctor + NOT loaded from history (brand new prescription)
 *      => update last_prescription_date and last_medical_consult;
 *         last_refill_date is NOT touched.
 *  - health_facility + NOT loaded from history
 *      => shouldn't happen via the UI (health_facility can't add
 *         medicines manually), but falls back to treating it like an
 *         ordinary prescription (updates last_prescription_date only,
 *         no consult/refill) rather than silently doing nothing.
 *
 * Call this AFTER the prescription + prescription_medicines rows have
 * been successfully inserted, ideally inside the same DB transaction.
 *
 * @param PDO    $conn
 * @param int    $patientId
 * @param string $role              'doctor' | 'health_facility'
 * @param bool   $isRefill          from $_POST['is_refill'] === 'YES'
 * @param bool   $pureRefillNoNewMeds from $_POST['pure_refill_no_new_meds'] === '1'
 * @param string $now               app-local timestamp, e.g. date('Y-m-d H:i:s')
 */
function updatePatientDatesAfterPrescription(
    PDO $conn,
    int $patientId,
    string $role,
    bool $isRefill,
    bool $pureRefillNoNewMeds,
    string $now
): void {
    $updateLastPrescription = false;
    $updateLastConsult      = false;
    $updateLastRefill       = false;

    if ($role === 'health_facility' && $isRefill && $pureRefillNoNewMeds) {
        // Pure refill, nothing new: only log the refill.
        $updateLastRefill = true;

    } elseif ($role === 'doctor' && $isRefill) {
        // Doctor reviewed history and (re)issued: this is a real visit.
        $updateLastPrescription = true;
        $updateLastConsult      = true;
        $updateLastRefill       = true;

    } elseif ($role === 'doctor' && !$isRefill) {
        // Brand new prescription, not from history — no refill involved.
        $updateLastPrescription = true;
        $updateLastConsult      = true;

    } else {
        // Fallback (health_facility submitting without ever loading
        // history — not reachable via the current UI, but handled
        // defensively rather than silently skipped).
        $updateLastPrescription = true;
    }

    $setClauses = [];
    $params = [];

    if ($updateLastPrescription) {
        $setClauses[] = 'last_prescription_date = ?';
        $params[] = $now;
    }
    if ($updateLastConsult) {
        $setClauses[] = 'last_medical_consult = ?';
        $params[] = $now;
    }
    if ($updateLastRefill) {
        $setClauses[] = 'last_refill_date = ?';
        $params[] = $now;
    }

    if (empty($setClauses)) {
        return; // nothing to update
    }

    $params[] = $patientId;

    $sql = "UPDATE patients SET " . implode(', ', $setClauses) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
}