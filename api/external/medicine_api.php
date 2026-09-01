<?php
require '../../config/db.php';
header("Content-Type: application/json");

$term = $_GET['term'] ?? '';

if (!$term) {
    echo json_encode([]);
    exit;
}

try {
    /*
     * FIX (Item 2 — Inactive medicines selectable):
     * This query previously had NO status filter at all, and didn't
     * even select a status column — every medicine, Active or
     * Inactive, was returned identically, and the frontend had no
     * data to distinguish them even if it tried. That's the actual
     * root cause of inactive medicines being selectable.
     *
     * Filtering here (not just on the frontend) is the real fix:
     * a client-side-only filter can't stop someone from calling this
     * endpoint directly and getting an inactive medicine's ID anyway.
     * `status` is also still included in the response so the
     * frontend's defense-in-depth check has real data to act on.
     *
     * Assumes a `status` column on `medicines` with values 'Active' /
     * 'Inactive' (matching the Edit Medicine screen's Status dropdown).
     * If your column/values differ (e.g. is_active TINYINT), adjust
     * the WHERE clause and the "status" key below accordingly.
     */
    $sql = "
        SELECT
            id,
            generic_name,
            brand_name,
            dosage,
            uom,
            signa,
            duration,
            status
        FROM medicines
        WHERE status = 'Active'
          AND (generic_name LIKE ? OR brand_name LIKE ?)
        ORDER BY
            CASE
                WHEN generic_name LIKE ? THEN 1
                WHEN brand_name LIKE ? THEN 2
                ELSE 3
            END
        LIMIT 10
    ";

    $like = "%{$term}%";
    $params = [$like, $like, $like, $like];

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // FIX: most likely cause is that the `uom` column doesn't exist
        // yet on this database (the migration for it hasn't been run) —
        // this was crashing the frontend entirely (TypeError on .filter)
        // because the caught error below used to return a JSON object
        // instead of an array, which the autocomplete's response()
        // callback can't work with. Retry without uom so search results
        // still work — they just won't have a UOM value until the
        // column actually exists.
        error_log('medicine_api.php: query with uom failed, retrying without it: ' . $e->getMessage());

        $fallbackSql = "
            SELECT
                id,
                generic_name,
                brand_name,
                dosage,
                signa,
                duration,
                status
            FROM medicines
            WHERE status = 'Active'
              AND (generic_name LIKE ? OR brand_name LIKE ?)
            ORDER BY
                CASE
                    WHEN generic_name LIKE ? THEN 1
                    WHEN brand_name LIKE ? THEN 2
                    ELSE 3
                END
            LIMIT 10
        ";

        try {
            $stmt = $conn->prepare($fallbackSql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
            // Even the fallback failed — still return a valid EMPTY
            // ARRAY, not an error object, so the frontend never crashes
            // no matter what's wrong with the query/table.
            error_log('medicine_api.php: fallback query also failed: ' . $e2->getMessage());
            echo json_encode([]);
            exit;
        }
    }

    $data = [];

    foreach ($results as $row) {
        $label = $row['generic_name'];
        if (!empty($row['brand_name'])) {
            $label .= " (" . $row['brand_name'] . ")";
        }
        // FIX (Item 2): show UOM directly in the search results dropdown,
        // so the prescriber can identify the correct medicine (e.g. two
        // entries with the same name but different forms/UOM) before
        // ever selecting one.
        if (!empty($row['uom'])) {
            $label .= " — " . $row['uom'];
        }

        $data[] = [
            "id"       => $row['id'], // REQUIRED: frontend autocomplete's select handler
                                       // reads ui.item.id to fill the hidden medicine_id
                                       // field. Without it, "Save Medicine" always fails
                                       // validation even when a real suggestion was clicked.
            "label"    => $label,
            "value"    => $row['generic_name'],
            "dosage"   => $row['dosage'] ?? '',
            "uom"      => $row['uom'] ?? '', // read by the frontend to auto-populate the UOM field on selection
            "signa"    => $row['signa'] ?? '',
            "duration" => $row['duration'] ?? '',
            "status"   => $row['status'] ?? 'Active', // read by the frontend's inactive-medicine guard
        ];
    }

    echo json_encode($data);

} catch (Throwable $e) {
    // FIX: return a valid empty array here too, not an error object —
    // this outer catch is now just a final safety net (the actual query
    // failure is already handled above), but if anything else in this
    // file ever throws, the frontend must still get something it can
    // safely call .filter() on.
    error_log('medicine_api.php: unexpected error: ' . $e->getMessage());
    echo json_encode([]);
}