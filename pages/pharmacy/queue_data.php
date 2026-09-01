<?php
session_start();
require '../../config/db.php';
header('Content-Type: application/json');

/*
 * ================= PHARMACY QUEUE DATA (internal) =================
 * Same data queue.php renders server-side, exposed as JSON so the
 * page can refresh itself in place instead of doing a full reload.
 *
 * Unlike api/queue_status.php (public, no auth, no PII), this one
 * requires a logged-in pharmacy session and includes patient name +
 * diagnosis — so it must NOT be reachable without auth.
 *
 * MULTI-PHARMACY SCOPE — mirrors list/prescription.php's pattern exactly:
 *   - Walk-in rows are scoped by q.pharmacy_id (set directly at kiosk
 *     insert time for that branch — there's no prescription to derive
 *     it from).
 *   - E-Pres rows are scoped by COALESCE(q.pharmacy_id, hf.pharmacy_id)
 *     — prefer the value stored on the queue row, but fall back to the
 *     pharmacy the prescription's health facility routes to if that's
 *     missing, exactly like list/prescription.php already does via its
 *     hf.pharmacy_id join.
 *   - If the logged-in pharmacy account has no pharmacy_id at all yet
 *     (not re-saved since that column was added, or session predates
 *     it), we fall back to showing ALL of today's queue rather than
 *     silently blanking the page — same fallback list/prescription.php
 *     uses, so behavior is consistent across both pages.
 *
 * CACHING / CONNECTION PROTECTION:
 * queue.php polls this endpoint every 4s per open staff tab, on top of
 * the kiosk and public monitor hitting their own endpoints. To stay
 * well under the DB's max_connections_per_hour, results are cached per
 * pharmacy for CACHE_TTL_SECONDS. Repeated polls within that window
 * are served from cache without touching the database.
 *
 * TRADE-OFF: after a staff action (status change, complete, call next),
 * queue.php's JS reloads the table immediately for instant feedback.
 * That immediate reload passes ?fresh=1, which bypasses the cache —
 * so actions still feel instant — while routine 4s polling stays
 * cached. Every write also clears that pharmacy's cache entry so the
 * next read (fresh or not) picks up the change right away.
 */

const CACHE_TTL_SECONDS = 2;
const CACHE_DIR = __DIR__ . '/../../cache/queue_data';

function cacheGet(string $key): ?array {
    if (function_exists('apcu_fetch')) {
        $val = apcu_fetch($key, $ok);
        return $ok ? $val : null;
    }
    $file = CACHE_DIR . '/' . md5($key) . '.json';
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function cacheSet(string $key, array $value, int $ttl): void {
    if (function_exists('apcu_store')) {
        apcu_store($key, ['data' => $value, 'expires' => time() + $ttl], $ttl + 5);
        return;
    }
    if (!is_dir(CACHE_DIR)) {
        @mkdir(CACHE_DIR, 0775, true);
    }
    $file = CACHE_DIR . '/' . md5($key) . '.json';
    @file_put_contents($file, json_encode(['data' => $value, 'expires' => time() + $ttl]), LOCK_EX);
}

/*
 * Called from queue.php after any write (status change, call next,
 * complete, unclaimed) so the next read reflects it immediately
 * instead of waiting out the TTL. Exposed here so queue.php can
 * `require` this file's functions without duplicating the cache logic.
 */
function invalidateQueueDataCache(int $pharmacyId): void {
    $key = 'queue_data_' . $pharmacyId;
    if (function_exists('apcu_delete')) {
        apcu_delete($key);
        return;
    }
    $file = CACHE_DIR . '/' . md5($key) . '.json';
    if (is_file($file)) @unlink($file);
}

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'pharmacy') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$pharmacyId = (int) ($_SESSION['user']['pharmacy_id'] ?? 0);
$forceFresh = !empty($_GET['fresh']);

$cacheKey = 'queue_data_' . $pharmacyId;
if (!$forceFresh) {
    $cached = cacheGet($cacheKey);
    if ($cached && $cached['expires'] >= time()) {
        echo json_encode($cached['data']);
        exit;
    }
}

function queueLabel(string $category, int $number): string {
    $prefix = $category === 'Priority' ? 'P' : 'R';
    return $prefix . str_pad((string)$number, 2, '0', STR_PAD_LEFT);
}

$baseSql = "
    SELECT q.id, q.source, q.category, q.queue_number, q.status,
           q.created_at, q.called_at, q.completed_at,
           q.prescription_id, q.walk_in_name,
           pat.first_name, pat.last_name, p.diagnosis, p.prescription_number
    FROM queues q
    LEFT JOIN prescriptions p ON q.prescription_id = p.id
    LEFT JOIN patients pat ON p.patient_id = pat.id
    LEFT JOIN health_facilities hf ON p.facility_id = hf.id
    WHERE DATE(q.created_at) = CURDATE()
";

try {
    $sql = $baseSql;
    $params = [];

    if (!empty($pharmacyId)) {
        $sql .= " AND COALESCE(q.pharmacy_id, hf.pharmacy_id) = ?";
        $params[] = $pharmacyId;
    }

    $sql .= " ORDER BY FIELD(q.status, 'Now Serving', 'Waiting', 'Completed'), q.category ASC, q.queue_number ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Defensive fallback, same reasoning as list/prescription.php: if
    // q.pharmacy_id doesn't exist yet on this install, don't 500 the
    // whole queue — just show it unscoped instead.
    error_log('Queue data pharmacy scoping query failed: ' . $e->getMessage());

    try {
        $stmt = $conn->prepare($baseSql . " ORDER BY FIELD(q.status, 'Now Serving', 'Waiting', 'Completed'), q.category ASC, q.queue_number ASC");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e2) {
        // Both queries failed — likely a genuine DB/connection problem,
        // not just a missing column. Serve stale cache if we have any,
        // rather than breaking the live table.
        error_log('Queue data fallback query also failed: ' . $e2->getMessage());
        $stale = cacheGet($cacheKey);
        if ($stale) {
            echo json_encode($stale['data']);
            exit;
        }
        http_response_code(503);
        echo json_encode(['status' => 'error', 'message' => 'Queue data temporarily unavailable.']);
        exit;
    }
}

$data = array_map(function ($q) {
    // Rx No. now has its own dedicated column in the table, so "who"
    // is just the patient's name (or the walk-in name) — no more
    // "(Rx #123)" tacked on here.
    $who = $q['source'] === 'E-Pres'
        ? (trim(($q['first_name'] ?? '') . ' ' . ($q['last_name'] ?? '')) ?: 'Unknown patient')
        : ($q['walk_in_name'] ?: 'Walk-in patient');

    return [
        'id'                   => (int) $q['id'],
        'label'                => queueLabel($q['category'], (int) $q['queue_number']),
        'category'             => $q['category'],
        'source'               => $q['source'],
        'who'                  => $who,
        'diagnosis'            => $q['diagnosis'] ?: '—',
        'status'               => $q['status'],
        'prescription_id'      => $q['prescription_id'] ? (int) $q['prescription_id'] : null,
        'prescription_number'  => $q['prescription_number'],
    ];
}, $rows);

$responseData = ['status' => 'success', 'rows' => $data, 'updated_at' => date('c')];

cacheSet($cacheKey, $responseData, CACHE_TTL_SECONDS);
echo json_encode($responseData);