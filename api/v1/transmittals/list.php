<?php
/**
 * GET /api/v1/transmittals/list.php
 *
 * Lists transmittals already generated for the health facility linked
 * to this API key. Mirrors modules/facility/transmittal_handler.php's
 * action=list branch exactly (same columns, same optional date filter
 * on date_generated), swapping session auth for API-key auth and the
 * shared response envelope used across api/v1/*.
 *
 * Query params (optional):
 *   date        — filter to a single date_generated (YYYY-MM-DD)
 *   page, limit — pagination, bounded by env2.php MAX_PAGE_LIMIT
 */
require '../../../config/db.php';
$env = require '../../../config/env2.php';
require '../includes/api_common.php';

$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

[$key, $requestId] = requireApiAuth($conn, $env);

// Unlike the queue endpoints, transmittals.facility_id IS
// api_keys.facility_id directly — this is the health-facility side,
// not the pharmacy side, so there's no pharmacy-table join here.
$facilityId = $key['facility_id'] ?? null;
if (!$facilityId) {
    apiResponse(403, ['status' => 'error', 'error' => 'This API key is not linked to a facility_id.', 'request_id' => $requestId]);
}

$filterDate = $_GET['date'] ?? null;

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = min((int) ($_GET['limit'] ?? $env['DEFAULT_PAGE_LIMIT']), $env['MAX_PAGE_LIMIT']);
$offset = ($page - 1) * $limit;

$sql = "
    SELECT
        id,
        date_generated,
        prescription_date,
        delivery_date,
        num_patients,
        status,
        health_facility,
        pharmacist,
        generated_by,
        transmitted
    FROM transmittals
    WHERE facility_id = ?
";
$params = [$facilityId];

if ($filterDate) {
    $sql .= " AND DATE(date_generated) = ? ";
    $params[] = $filterDate;
}

$sql .= " ORDER BY id DESC LIMIT $limit OFFSET $offset";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('[api/v1/transmittals/list] ' . $e->getMessage());
    apiResponse(500, ['status' => 'error', 'error' => 'Database query failed', 'request_id' => $requestId]);
}

logApiRequest($conn, $env, $key['api_key'], $requestId);

apiResponse(200, [
    'status' => 'success',
    'count' => count($rows),
    'page' => $page,
    'request_id' => $requestId,
    'data' => $rows,
]);