<?php
require '../config/db.php';
require '../includes/pharmacy_helpers.php';

/*
 * ================= PUBLIC QUEUE STREAM (SSE, multi-branch) =================
 * Push-based replacement for polling api/queue_status.php every 4s.
 * Backs modules/public/queue_display.php — the TV/monitor screens that
 * sit open in each branch's waiting area, typically 24/7.
 *
 * These are the highest-volume, longest-lived pollers in the system:
 * unlike a staff tab that closes at end of shift, a branch monitor can
 * run for days or weeks straight. Converting it from polling to SSE
 * turns "one HTTP request (and DB query) every 4s, forever, per screen"
 * into "one held-open connection per screen, DB touched only when the
 * queue actually changes."
 *
 * No auth, no PII — identical data contract to api/queue_status.php:
 * just queue labels ("R01", "P03") and counts per category, scoped by
 * ?branch=slug exactly the same way.
 */

$branchSlug = $_GET['branch'] ?? '';
$pharmacy = resolvePharmacyBySlug($conn, $branchSlug);
if (!$pharmacy) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unknown or missing pharmacy branch.']);
    exit;
}
$pharmacyId = (int) $pharmacy['id'];

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // required on nginx or the response gets buffered and nothing streams

set_time_limit(0);
ignore_user_abort(false);

if (ob_get_level() === 0) {
    ob_start();
}

function queueLabel(string $category, int $number): string {
    $prefix = $category === 'Priority' ? 'P' : 'R';
    return $prefix . str_pad((string) $number, 2, '0', STR_PAD_LEFT);
}

/*
 * Same query shape as api/queue_status.php: date-range filter (not
 * DATE(created_at)) so the (pharmacy_id, category, status, created_at)
 * index still applies on every check cycle.
 */
function fetchQueueStatus(PDO $conn, int $pharmacyId): array {
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
            'now_serving' => $nowServing ? ['label' => queueLabel($category, (int) $nowServing['queue_number'])] : null,
            'waiting_count' => count($waitingRows),
            'waiting' => array_map(
                fn($w) => ['label' => queueLabel($category, (int) $w['queue_number'])],
                $waitingRows
            ),
        ];
    }

    return $result;
}

function sendEvent(array $data): void {
    echo "data: " . json_encode($data) . "\n\n";
    @ob_flush();
    @flush();
}

function sendHeartbeat(): void {
    echo ": heartbeat " . time() . "\n\n";
    @ob_flush();
    @flush();
}

const CHECK_INTERVAL_SECONDS = 2;
const HEARTBEAT_EVERY_SECONDS = 15;
const MAX_STREAM_SECONDS = 1800; // 30 min, then close — the client reconnects automatically

$startedAt = time();
$lastHeartbeat = $startedAt;
$lastHash = null;

try {
    $categories = fetchQueueStatus($conn, $pharmacyId);
    $lastHash = md5(json_encode($categories));
    sendEvent([
        'status' => 'success',
        'pharmacy' => ['name' => $pharmacy['pharmacy_name'], 'slug' => $pharmacy['slug']],
        'categories' => $categories,
        'updated_at' => date('c'),
    ]);
} catch (\Throwable $e) {
    error_log('[queue_stream_public] initial fetch failed: ' . $e->getMessage());
    echo "event: error\n";
    echo "data: " . json_encode(['status' => 'error', 'message' => 'Could not load queue data.']) . "\n\n";
    @ob_flush();
    @flush();
    exit;
}

while (true) {
    if (connection_aborted()) {
        break;
    }
    if (time() - $startedAt >= MAX_STREAM_SECONDS) {
        break; // planned recycle; client reconnects
    }

    sleep(CHECK_INTERVAL_SECONDS);

    if (connection_aborted()) {
        break;
    }

    try {
        $categories = fetchQueueStatus($conn, $pharmacyId);
        $hash = md5(json_encode($categories));

        if ($hash !== $lastHash) {
            sendEvent([
                'status' => 'success',
                'pharmacy' => ['name' => $pharmacy['pharmacy_name'], 'slug' => $pharmacy['slug']],
                'categories' => $categories,
                'updated_at' => date('c'),
            ]);
            $lastHash = $hash;
            $lastHeartbeat = time();
        } elseif (time() - $lastHeartbeat >= HEARTBEAT_EVERY_SECONDS) {
            sendHeartbeat();
            $lastHeartbeat = time();
        }
    } catch (\Throwable $e) {
        error_log('[queue_stream_public] fetch cycle failed: ' . $e->getMessage());
        if (time() - $lastHeartbeat >= HEARTBEAT_EVERY_SECONDS) {
            sendHeartbeat();
            $lastHeartbeat = time();
        }
    }
}