<?php
session_start();
require '../../config/db.php';


if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'pharmacy') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$pharmacyId = (int) ($_SESSION['user']['pharmacy_id'] ?? 0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

set_time_limit(0);
ignore_user_abort(false);

if (ob_get_level() === 0) {
    ob_start();
}

function queueLabel(string $category, int $number): string {
    $prefix = $category === 'Priority' ? 'P' : 'R';
    return $prefix . str_pad((string) $number, 2, '0', STR_PAD_LEFT);
}

function fetchQueueRows(PDO $conn, int $pharmacyId): array {
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
        error_log('Queue stream pharmacy scoping query failed: ' . $e->getMessage());
        $stmt = $conn->prepare($baseSql . " ORDER BY FIELD(q.status, 'Now Serving', 'Waiting', 'Completed'), q.category ASC, q.queue_number ASC");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return array_map(function ($q) {
        $who = $q['source'] === 'E-Pres'
            ? (trim(($q['first_name'] ?? '') . ' ' . ($q['last_name'] ?? '')) ?: 'Unknown patient')
            : ($q['walk_in_name'] ?: 'Walk-in patient');

        return [
            'id'                  => (int) $q['id'],
            'label'               => queueLabel($q['category'], (int) $q['queue_number']),
            'category'            => $q['category'],
            'source'              => $q['source'],
            'who'                 => $who,
            'diagnosis'           => $q['diagnosis'] ?: '—',
            'status'              => $q['status'],
            'prescription_id'     => $q['prescription_id'] ? (int) $q['prescription_id'] : null,
            'prescription_number' => $q['prescription_number'],
        ];
    }, $rows);
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

const CHECK_INTERVAL_SECONDS = 2;      // how often we re-query the DB for changes
const HEARTBEAT_EVERY_SECONDS = 15;    // keep-alive comment cadence
const MAX_STREAM_SECONDS = 1800;       // 30 min — then close and let EventSource auto-reconnect

$startedAt = time();
$lastHeartbeat = $startedAt;
$lastHash = null;
try {
    $rows = fetchQueueRows($conn, $pharmacyId);
    $lastHash = md5(json_encode($rows));
    sendEvent(['status' => 'success', 'rows' => $rows, 'updated_at' => date('c')]);
} catch (\Throwable $e) {
    error_log('[queue_stream] initial fetch failed: ' . $e->getMessage());
    echo "event: error\n";
    echo "data: " . json_encode(['status' => 'error', 'message' => 'Could not load queue data.']) . "\n\n";
    @ob_flush();
    @flush();
    exit;
}

while (true) {
    if (connection_aborted()) {
        break; // tab closed / navigated away
    }
    if (time() - $startedAt >= MAX_STREAM_SECONDS) {
        break; // planned recycle — client reconnects automatically
    }

    sleep(CHECK_INTERVAL_SECONDS);

    if (connection_aborted()) {
        break;
    }

    try {
        $rows = fetchQueueRows($conn, $pharmacyId);
        $hash = md5(json_encode($rows));

        if ($hash !== $lastHash) {
            sendEvent(['status' => 'success', 'rows' => $rows, 'updated_at' => date('c')]);
            $lastHash = $hash;
            $lastHeartbeat = time();
        } elseif (time() - $lastHeartbeat >= HEARTBEAT_EVERY_SECONDS) {
            sendHeartbeat();
            $lastHeartbeat = time();
        }
    } catch (\Throwable $e) {
        error_log('[queue_stream] fetch cycle failed: ' . $e->getMessage());
        if (time() - $lastHeartbeat >= HEARTBEAT_EVERY_SECONDS) {
            sendHeartbeat();
            $lastHeartbeat = time();
        }
    }
}