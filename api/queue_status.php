<?php
require '../config/db.php';
require '../includes/pharmacy_helpers.php';
header('Content-Type: application/json');

/*
 * ================= PUBLIC QUEUE STATUS (multi-branch) =================
 * Backs the public monitor (modules/public/queue_display.php). No auth,
 * no PII — only queue labels ("R01", "P03") and counts per category.
 *
 * MULTI-PHARMACY: requires ?branch=slug to identify which location's
 * queue to return. Without a valid branch there is no sane "default"
 * branch to fall back to, so this returns an explicit error instead of
 * silently showing someone else's queue.
 *
 * RATE-LIMITING / CONNECTION PROTECTION:
 * This endpoint can be polled every ~4s from many simultaneous kiosk/
 * display tabs, across multiple branches. Without protection, each poll
 * opens its own DB connection, and enough concurrent tabs will exhaust
 * MySQL's max_connections, causing "database connection failed" errors
 * for everyone. To prevent that:
 *   1. Results are cached per-branch for CACHE_TTL_SECONDS. Repeated
 *      polls within that window are served from cache and never touch
 *      the database at all.
 *   2. If the DB connection/query does fail, one retry is attempted
 *      after a short delay (handles transient "MySQL server has gone
 *      away" mid-request drops).
 *   3. On persistent failure, we return a clean 503 JSON error instead
 *      of a fatal PHP error/crash, and — if we have a stale cached
 *      copy — we serve that instead of hard-failing the display.
 */

const CACHE_TTL_SECONDS = 3;   // how often we actually hit the DB, per branch
const CACHE_DIR = __DIR__ . '/../cache/queue_status';

function cacheGet(string $key): ?array {
    // Prefer APCu (in-memory, fast) if available
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

$branchSlug = $_GET['branch'] ?? '';
$pharmacy = resolvePharmacyBySlug($conn, $branchSlug);
if (!$pharmacy) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown or missing pharmacy branch.']);
    exit;
}
$pharmacyId = (int) $pharmacy['id'];

$cacheKey = 'queue_status_' . $branchSlug;
$cached = cacheGet($cacheKey);
if ($cached && $cached['expires'] >= time()) {
    // Fresh cache hit — no DB touched at all.
    echo json_encode($cached['data']);
    exit;
}

function queueLabel(string $category, int $number): string {
    $prefix = $category === 'Priority' ? 'P' : 'R';
    return $prefix . str_pad((string)$number, 2, '0', STR_PAD_LEFT);
}

/*
 * NOTE: date filtering uses a >= / < range on created_at instead of
 * DATE(created_at) = CURDATE(). Wrapping the column in DATE() prevents
 * MySQL from using an index on created_at, forcing a full table scan
 * on every poll (this endpoint is hit every 4s per open display/kiosk
 * tab). The range form lets an index on (pharmacy_id, category,
 * status, created_at) be used instead. See idx_queues_lookup.
 */
function fetchQueueData(PDO $conn, int $pharmacyId): array {
    $todayStart = date('Y-m-d 00:00:00');
    $todayEnd   = date('Y-m-d 00:00:00', strtotime('+1 day'));
    $categories = ['Regular', 'Priority'];
    $result = [];
    foreach ($categories as $category) {
        $stmt = $conn->prepare("
            SELECT queue_number, status FROM queues
            WHERE pharmacy_id = ? AND category = ? AND status = 'Now Serving'
              AND created_at >= ? AND created_at < ?
            ORDER BY called_at DESC LIMIT 1
        ");
        $stmt->execute([$pharmacyId, $category, $todayStart, $todayEnd]);
        $nowServing = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("
            SELECT queue_number FROM queues
            WHERE pharmacy_id = ? AND category = ? AND status = 'Waiting'
              AND created_at >= ? AND created_at < ?
            ORDER BY queue_number ASC
        ");
        $stmt->execute([$pharmacyId, $category, $todayStart, $todayEnd]);
        $waitingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result[$category] = [
            'now_serving' => $nowServing ? ['label' => queueLabel($category, (int)$nowServing['queue_number'])] : null,
            'waiting_count' => count($waitingRows),
            'waiting' => array_map(
                fn($w) => ['label' => queueLabel($category, (int)$w['queue_number'])],
                $waitingRows
            ),
        ];
    }
    return $result;
}

$maxAttempts = 2; // 1 initial try + 1 retry on transient failure
$attempt = 0;
$result = null;
$lastError = null;

while ($attempt < $maxAttempts && $result === null) {
    $attempt++;
    try {
        $result = fetchQueueData($conn, $pharmacyId);
    } catch (PDOException $e) {
        $lastError = $e;
        // Transient drop mid-request ("MySQL server has gone away", broken pipe, etc.)
        // Try to re-establish the connection once before giving up.
        if ($attempt < $maxAttempts) {
            usleep(200000); // 200ms backoff
            try {
                require '../config/db.php'; // re-initializes $conn
            } catch (\Throwable $reconnectError) {
                // fall through, loop will exit and we handle below
            }
        }
    }
}

if ($result === null) {
    // Persistent failure — serve stale cache if we have one rather than
    // showing a broken display, otherwise return a clean error.
    if ($cached) {
        echo json_encode($cached['data']);
        exit;
    }
    http_response_code(503);
    echo json_encode([
        'status' => 'error',
        'message' => 'Queue data temporarily unavailable. Please retry shortly.',
    ]);
    error_log('[queue_status] DB failure for branch=' . $branchSlug . ': ' . ($lastError ? $lastError->getMessage() : 'unknown'));
    exit;
}

$responseData = [
    'status' => 'success',
    'pharmacy' => ['name' => $pharmacy['pharmacy_name'], 'slug' => $pharmacy['slug']],
    'categories' => $result,
    'updated_at' => date('c'),
];

cacheSet($cacheKey, $responseData, CACHE_TTL_SECONDS);
echo json_encode($responseData);