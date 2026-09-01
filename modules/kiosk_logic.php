<?php
/*
 * ================= SHARED KIOSK LOGIC =================
 * Used by both kiosk.php (the first page load, and any bookmarked/QR
 * ?mode=... deep link) and kiosk_api.php (every transition AFTER that
 * first load — category selection, Rx lookup, walk-in creation).
 *
 * Why this split exists at all: a real page navigation silently drops
 * browser Fullscreen with no way for JavaScript to prevent or detect
 * it in advance (see includes/kiosk_common.js for the full explanation
 * of that browser behavior). kiosk.php used to do a full server round
 * trip + page reload for every step of the walk-in/E-Pres flow; now
 * only the very first load does that, and everything after runs
 * through kiosk_api.php via fetch() with the screen updated in place
 * by JS, so there is nothing left in the flow that can drop Fullscreen.
 *
 * Every function here takes $conn explicitly rather than touching a
 * global, so it's safe to call from either entry file.
 */

/*
 * ================= PLAIN-FILE DEBUG LOG =================
 * The kiosk is a public touchscreen terminal -- there's no DevTools
 * access, and PHP's error_log() destination depends on server config
 * (log_errors, error_log ini setting) that may not be visible without
 * hosting-panel/SSH access either. This writes to an ordinary text
 * file sitting right next to this script instead, so it can be opened
 * with any FTP client or cPanel File Manager -- no server access
 * needed beyond however you normally upload these PHP files.
 *
 * File: modules/pharmacy/kiosk_cancel_debug.log (same folder as
 * kiosk.php / kiosk_logic.php). Silently no-ops if the folder isn't
 * writable, rather than breaking the cancel flow over a logging
 * failure.
 */
function kioskDebugLog(string $message): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    @file_put_contents(__DIR__ . '/kiosk_cancel_debug.log', $line, FILE_APPEND | LOCK_EX);
}

function kioskNextQueueNumber(PDO $conn, int $pharmacyId, string $category): int {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt FROM queues
        WHERE pharmacy_id = ? AND category = ? AND DATE(created_at) = CURDATE()
    ");
    $stmt->execute([$pharmacyId, $category]);
    return ((int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt']) + 1;
}

function kioskQueueLabel(string $category, int $number): string {
    $prefix = $category === 'Priority' ? 'P' : 'R';
    return $prefix . str_pad((string) $number, 2, '0', STR_PAD_LEFT);
}

/*
 * Fetches this branch's printer config fresh from the DB. Called once
 * per real page load and then held in a JS variable for the rest of
 * that browser session, since there are no more reloads to re-fetch
 * it on. KNOWN TRADE-OFF: if staff update Printer Settings while a
 * kiosk tab has been sitting open for a while, that tab won't pick up
 * the change until it's actually restarted (fullscreen toggle exit +
 * re-enter doesn't reload the page, so it won't refresh this either).
 */
function kioskPrinterConfig(PDO $conn, int $pharmacyId): array {
    $paperWidth = '80mm';
    $stmt = $conn->prepare("
        SELECT printer_paper_width, printer_ip, printer_port, printer_protocol,
               printer_connection_type, printer_settings_updated_at
        FROM pharmacy WHERE id = ?
    ");
    $stmt->execute([$pharmacyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && $row['printer_paper_width'] === '58mm') {
        $paperWidth = '58mm';
    }
    $connectionType = $row['printer_connection_type'] ?? 'wired';
    $hasSaved = !empty($row['printer_settings_updated_at']);
    $isConfigured = $hasSaved && (
        ($connectionType === 'wireless' && ($row['printer_protocol'] ?? '') === 'epos_xml' && !empty($row['printer_ip']))
        || $connectionType === 'wired'
    );

    return [
        'paperWidth' => $paperWidth,
        'connectionType' => $connectionType,
        'ip' => $row['printer_ip'] ?? null,
        'port' => $row['printer_port'] ?? null,
        'protocol' => $row['printer_protocol'] ?? null,
        'isConfigured' => $isConfigured,
    ];
}

/*
 * Validates a scanned/typed Rx number against every rule this used to
 * enforce inline in kiosk.php: exists, routed to this pharmacy, not
 * already dispensed, actually signed + transmitted.
 * Returns ['ok' => false, 'error' => '...'] or ['ok' => true, 'prescription' => [...]].
 */
function kioskValidateRx(PDO $conn, int $pharmacyId, string $rxNo): array {
    $rxNo = strtoupper(trim($rxNo));
    if ($rxNo === '') {
        return ['ok' => false, 'error' => 'Please enter a valid Prescription Number.'];
    }

    $stmt = $conn->prepare("
        SELECT pr.id, pr.prescription_number, pr.status, pr.transmitted_at,
               pr.medicine_status, pr.dispensed_at,
               hf.pharmacy_id AS routed_pharmacy_id, pat.first_name, pat.last_name
        FROM prescriptions pr
        LEFT JOIN health_facilities hf ON pr.facility_id = hf.id
        LEFT JOIN patients pat ON pr.patient_id = pat.id
        WHERE pr.prescription_number = ?
    ");
    $stmt->execute([$rxNo]);
    $prescription = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$prescription) {
        return ['ok' => false, 'error' => "No prescription found for No. {$rxNo}."];
    }
    if ((int) ($prescription['routed_pharmacy_id'] ?? 0) !== $pharmacyId) {
        return ['ok' => false, 'error' => 'This prescription was not transmitted to this pharmacy location. Please check the correct branch.'];
    }
    if ($prescription['medicine_status'] === 'Dispensed') {
        $dispensedWhen = $prescription['dispensed_at']
            ? (new DateTime($prescription['dispensed_at']))->format('M j, Y g:i A')
            : null;
        return ['ok' => false, 'error' => $dispensedWhen
            ? "This prescription was already dispensed on {$dispensedWhen}."
            : "This prescription has already been dispensed."];
    }
    if ($prescription['status'] !== 'Signed' || !$prescription['transmitted_at']) {
        return ['ok' => false, 'error' => 'This prescription is not yet signed and transmitted. Please check with your health facility.'];
    }

    return ['ok' => true, 'prescription' => $prescription];
}

/*
 * Looks for an already-open (not Completed/Unclaimed, today) queue row
 * for this prescription at this pharmacy — re-scanning the same Rx
 * twice should hand back the same ticket, not create a duplicate.
 */
function kioskExistingQueueForPrescription(PDO $conn, int $pharmacyId, int $prescriptionId): ?array {
    $stmt = $conn->prepare("
        SELECT * FROM queues
        WHERE pharmacy_id = ? AND prescription_id = ? AND status NOT IN ('Completed', 'Unclaimed') AND DATE(created_at) = CURDATE()
        LIMIT 1
    ");
    $stmt->execute([$pharmacyId, $prescriptionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/*
 * Inserts a new queue row (walk-in or E-Pres) and returns it fully
 * hydrated, same shape either way, so the caller doesn't need to
 * branch on source afterward.
 */
function kioskCreateQueueEntry(PDO $conn, int $pharmacyId, string $category, string $source, ?int $prescriptionId = null, ?string $walkInName = null): array {
    $number = kioskNextQueueNumber($conn, $pharmacyId, $category);

    $stmt = $conn->prepare("
        INSERT INTO queues (pharmacy_id, prescription_id, walk_in_name, source, category, queue_number, status)
        VALUES (?, ?, ?, ?, ?, ?, 'Waiting')
    ");
    $stmt->execute([$pharmacyId, $prescriptionId, $walkInName, $source, $category, $number]);

    $stmt = $conn->prepare("SELECT * FROM queues WHERE id = ?");
    $stmt->execute([$conn->lastInsertId()]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/*
 * Cancels a still-'Waiting' queue entry initiated from the kiosk
 * itself — the patient tapped Cancel on their ticket before printing
 * it. Mirrors markCancelled() in queue.php's pharmacy-side Cancel
 * action (same three effects: queues.status -> Cancelled, cascades to
 * prescriptions.medicine_status when a prescription is attached, and
 * an audit row in queue_status_log), scoped here to what a kiosk
 * session could plausibly have just created itself:
 *
 *   - must belong to this pharmacy (multi-tenant guard, same pattern
 *     used everywhere else in this app)
 *   - must still be 'Waiting' — if it's already Now Serving or beyond,
 *     staff have already acted on it and a stale/replayed cancel
 *     request from the kiosk must not undo that
 *
 * Returns true on success, false if the row wasn't found/eligible, so
 * the caller (kiosk_api.php) can respond accordingly rather than
 * reporting success for something that didn't actually happen.
 */
function kioskCancelQueueEntry(PDO $conn, int $pharmacyId, int $queueId): bool {
    /*
     * ================= DIAGNOSTIC LOGGING =================
     * The kiosk is a public touchscreen terminal with no DevTools
     * access in practice, so the usual "check the browser console"
     * debugging loop doesn't work here. Every branch of this function
     * logs to kiosk_cancel_debug.log (via kioskDebugLog() above)
     * instead -- open it with any FTP client or cPanel File Manager,
     * no server/SSH access needed. Look for lines starting with
     * "[kiosk cancel]".
     */
    kioskDebugLog("[kiosk cancel] called with queueId={$queueId} pharmacyId={$pharmacyId}");

    try {
        // Widened from status = 'Waiting' only to "not already a
        // terminal state" -- mirrors queue.php's own Cancel button,
        // which allows cancelling from Waiting OR Now Serving (its
        // isTerminal check only blocks Completed/Unclaimed/Cancelled).
        // A kiosk cancel should succeed under the same conditions a
        // staff-initiated cancel would, not a stricter one.
        $stmt = $conn->prepare("
            SELECT * FROM queues
            WHERE id = ? AND pharmacy_id = ?
              AND status NOT IN ('Completed', 'Unclaimed', 'Cancelled')
        ");
        $stmt->execute([$queueId, $pharmacyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            // Tells us WHY it wasn't found: wrong pharmacy scope,
            // already-terminal status, or the id simply doesn't exist.
            $check = $conn->prepare("SELECT pharmacy_id, status FROM queues WHERE id = ?");
            $check->execute([$queueId]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                kioskDebugLog("[kiosk cancel] FAILED: no queue row with id={$queueId} exists at all.");
            } else {
                kioskDebugLog("[kiosk cancel] FAILED: row {$queueId} exists but didn't match -- its pharmacy_id={$existing['pharmacy_id']} (expected {$pharmacyId}), status={$existing['status']}.");
            }
            return false;
        }

        $reason = 'Cancelled by patient at kiosk before printing.';
        $oldStatus = $row['status'];

        $stmt = $conn->prepare("UPDATE queues SET status = 'Cancelled', cancelled_at = NOW(), cancelled_reason = ? WHERE id = ?");
        $stmt->execute([$reason, $queueId]);
        kioskDebugLog("[kiosk cancel] queues row {$queueId} updated to Cancelled (rowCount=" . $stmt->rowCount() . ").");

        if (!empty($row['prescription_id'])) {
            $stmt = $conn->prepare("UPDATE prescriptions SET medicine_status = 'Cancelled' WHERE id = ?");
            $stmt->execute([$row['prescription_id']]);
            kioskDebugLog("[kiosk cancel] prescription {$row['prescription_id']} medicine_status set to Cancelled.");
        }

        // Same audit-trail table queue.php's pharmacy-side Cancel writes
        // to (see markCancelled() there). changed_by is NULL here — the
        // kiosk has no staff session, only a patient at a public terminal.
        $stmt = $conn->prepare("
            INSERT INTO queue_status_log (queue_id, old_status, new_status, reason, changed_by, changed_at)
            VALUES (?, ?, 'Cancelled', ?, NULL, NOW())
        ");
        $stmt->execute([$queueId, $oldStatus, $reason]);
        kioskDebugLog("[kiosk cancel] SUCCESS: queue_status_log row written for queueId={$queueId}.");

        return true;
    } catch (PDOException $e) {
        // A DB-level failure (missing column, missing table, etc.)
        // would otherwise throw and get swallowed by kiosk_api.php's
        // JSON response -- log the real reason instead of a silent 500.
        kioskDebugLog("[kiosk cancel] EXCEPTION for queueId={$queueId}: " . $e->getMessage());
        return false;
    }
}

/*
 * Shapes a queue row into the flat JSON payload the client needs both
 * to render the on-screen ticket and to build the printed receipt
 * (see ticketData() in kiosk.php's script — same field names).
 *
 * Includes the row's own id as 'queue_id' so the client can reference
 * this exact row later — specifically, so a kiosk-initiated Cancel
 * (see kioskCancelQueueEntry above) knows which row to cancel, since
 * the frontend otherwise has no way to know a queue row's database id.
 */
function kioskTicketPayload(array $pharmacy, array $queueRow, ?string $prescriptionNumber = null): array {
    return [
        'queue_id' => (int) $queueRow['id'],
        'category' => $queueRow['category'],
        'label' => kioskQueueLabel($queueRow['category'], (int) $queueRow['queue_number']),
        'prescription_id' => $queueRow['prescription_id'] ? (int) $queueRow['prescription_id'] : null,
        'prescription_number' => $prescriptionNumber,
        'branch_name' => $pharmacy['pharmacy_name'],
        'datetime' => date('M d, Y h:i A'),
    ];
}