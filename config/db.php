<?php
// session_start();
// Database configuration
// Database configuration
$host     = "148.222.53.50";
$dbname   = "u279530684_e_pres";
$username = "u279530684_e_pres";
$password = "Ep&cript2026";
/*
 * ================= CONNECTION CIRCUIT BREAKER (self-healing) =================
 * max_connections_per_hour is a per-clock-hour quota on the MySQL user
 * account. Once tripped, blindly retrying on every request just wastes
 * requests and can prolong the outage — so we remember "we're locked
 * out" and skip real connection attempts for a short cooldown.
 *
 * IMPORTANT DIFFERENCE FROM THE PREVIOUS VERSION: that version locked
 * out unconditionally until the literal top of the next clock hour,
 * with no way to detect that the problem had already gone away (e.g.
 * you raised the quota in hPanel, or the host's counter reset early).
 * That caused it to keep reporting "throttled" long after the database
 * was actually reachable again.
 *
 * This version uses a HALF-OPEN pattern instead:
 *   - On failure, trip the breaker for a SHORT cooldown (starts at 15s,
 *     doubles on repeated failures, capped at 5 minutes) — not a full
 *     hour.
 *   - Once the cooldown elapses, the NEXT request is allowed to attempt
 *     a real connection (the "half-open" test) instead of failing fast.
 *   - If that test succeeds, the breaker clears immediately and normal
 *     traffic resumes — no waiting out the clock hour.
 *   - If it still fails, the cooldown doubles and we wait again.
 *
 * This means recovery is automatic and fast (worst case a few minutes)
 * whether the underlying fix was "quota reset naturally" or "someone
 * raised the limit," instead of depending on a fixed timestamp that
 * has no way to know the real state changed.
 */
const DB_BREAKER_KEY = 'db_conn_breaker_state';
const DB_BREAKER_INITIAL_COOLDOWN = 15;   // seconds
const DB_BREAKER_MAX_COOLDOWN     = 300;  // 5 minutes — never wait longer than this between recovery tests
const DB_BREAKER_FILE = null; // set below, since sys_get_temp_dir() needs runtime

function dbBreakerFilePath(): string {
    return sys_get_temp_dir() . '/epscript_db_breaker.json';
}

/** @return array{until:int, cooldown:int} */
function dbBreakerGet(): array {
    $default = ['until' => 0, 'cooldown' => DB_BREAKER_INITIAL_COOLDOWN];
    if (function_exists('apcu_fetch')) {
        $val = apcu_fetch(DB_BREAKER_KEY, $ok);
        return $ok && is_array($val) ? $val : $default;
    }
    $file = dbBreakerFilePath();
    if (!is_file($file)) return $default;
    $raw = @file_get_contents($file);
    $data = $raw ? json_decode($raw, true) : null;
    return is_array($data) ? $data : $default;
}

function dbBreakerSet(int $until, int $cooldown): void {
    $state = ['until' => $until, 'cooldown' => $cooldown];
    if (function_exists('apcu_store')) {
        apcu_store(DB_BREAKER_KEY, $state, $cooldown + 60);
        return;
    }
    @file_put_contents(dbBreakerFilePath(), json_encode($state), LOCK_EX);
}

function dbBreakerClear(): void {
    if (function_exists('apcu_delete')) {
        apcu_delete(DB_BREAKER_KEY);
    }
    $file = dbBreakerFilePath();
    if (is_file($file)) @unlink($file);
}

// Trip (or re-trip with a longer cooldown) after a failed attempt.
function dbBreakerTrip(): void {
    $state = dbBreakerGet();
    $nextCooldown = min($state['cooldown'] * 2, DB_BREAKER_MAX_COOLDOWN);
    dbBreakerSet(time() + $nextCooldown, $nextCooldown);
}

function dbFailJson(string $message): void {
    http_response_code(503);
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

/*
 * MANUAL RESET: if you ever need to clear the breaker immediately after
 * fixing something on the hosting side (e.g. right after raising
 * max_connections_per_hour), you don't need to wait even the short
 * cooldown out — hit any script that requires this file with
 * ?db_reset_breaker=1 once. Remove or protect this in production if
 * you don't want it publicly triggerable; scoping it to admin-only
 * pages is enough for most setups since it only clears a cooldown
 * timer, it doesn't expose or change anything sensitive.
 */
if (isset($_GET['db_reset_breaker'])) {
    dbBreakerClear();
}

$breakerState = dbBreakerGet();
$breakerIsOpen = $breakerState['until'] > time();

if ($breakerIsOpen) {
    $waitSecs = $breakerState['until'] - time();
    dbFailJson("Database is temporarily throttled. Retrying automatically in " . $waitSecs . " second(s).");
}

// Either the breaker was never tripped, or its cooldown just elapsed —
// this is the "half-open" test: attempt a real connection now.
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 3,
    PDO::ATTR_PERSISTENT         => true, // reuse connections across requests instead of opening a new one every time
];

$dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

$maxAttempts = 2;
$conn = null;
$lastError = null;

for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
    try {
        $conn = new PDO($dsn, $username, $password, $options);
        break;
    } catch (PDOException $e) {
        $lastError = $e;
        error_log("[db.php] Connection attempt {$attempt}/{$maxAttempts} failed: " . $e->getMessage());

        // MySQL error 1226 = resource limit exceeded (max_connections_per_hour,
        // max_user_connections, etc). Trip the breaker with a short cooldown
        // and stop retrying immediately — retrying right now just wastes the
        // request and possibly the quota. The NEXT request after the cooldown
        // will test again automatically.
        if (str_contains($e->getMessage(), '1226') || str_contains($e->getMessage(), 'exceeded')) {
            dbBreakerTrip();
            $state = dbBreakerGet();
            dbFailJson("Database is temporarily throttled due to high traffic. Retrying automatically in " . $state['cooldown'] . " second(s).");
        }

        if ($attempt < $maxAttempts) {
            usleep(150000 * $attempt);
        }
    }
}

if ($conn === null) {
    dbBreakerTrip();
    dbFailJson('Database connection failed. Please try again shortly.');
}

// Connection succeeded — if the breaker had any stale state (e.g. this
// was a half-open recovery test), clear it so we're back to a clean
// "never tripped" state instead of an armed-but-not-yet-due cooldown.
if ($breakerState['until'] > 0 || $breakerState['cooldown'] > DB_BREAKER_INITIAL_COOLDOWN) {
    dbBreakerClear();
}