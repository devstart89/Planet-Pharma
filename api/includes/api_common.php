<?php
/**
 * Shared helpers for api/v1/* endpoints.
 *
 * Mirrors the auth/rate-limit/logging pattern already established in
 * api/v1/prescriptions/transmitted.php, factored into one file so every
 * new endpoint (queue module, future modules) shares one copy instead
 * of repeating ~150 lines of auth logic each time.
 *
 * Usage in an endpoint file (3 directories deep, e.g. api/v1/queue/list.php):
 *
 *   require '../../../config/db.php';
 *   $env = require '../../../config/env2.php';
 *   require '../includes/api_common.php';
 *
 *   [$key, $requestId] = requireApiAuth($conn, $env);
 *   $pharmacy = requirePharmacyForKey($conn, $key);
 *   ... endpoint-specific logic ...
 *   logApiRequest($conn, $env, $key['api_key'], $requestId);
 */

// ================= CORS (for local testing tools) =================
// Safe to allow any origin here specifically because auth is via
// X-API-KEY/X-SECRET-KEY headers, not cookies/session — a third-party
// site embedding a request still can't produce a valid key/secret it
// doesn't have. This is NOT the same risk as enabling CORS on a
// cookie-authenticated endpoint.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, X-SECRET-KEY, X-TIMESTAMP, X-SIGNATURE, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Preflight request — browsers send this automatically before the
    // real request when custom headers are involved. No auth needed
    // here, just acknowledge what's allowed and stop.
    http_response_code(204);
    exit;
}

// ================= ERROR SAFETY =================
// Unlike the debug version in transmitted.php (which echoes raw PHP
// fatal-error details into the JSON body via display_errors=1), this
// never surfaces internals to the caller — an externally reachable
// endpoint shouldn't leak file paths / stack info. Fatals are logged
// server-side and the caller gets a generic 500.
ini_set('display_errors', 0);
error_reporting(E_ALL);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[api] Fatal error: ' . json_encode($error));
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['status' => 'error', 'error' => 'Internal server error']);
    }
});

// ================= RESPONSE / REQUEST HELPERS =================

function apiResponse(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function apiRequestId(): string
{
    return bin2hex(random_bytes(8));
}

function apiHeaders(): array
{
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = strtoupper(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = $value;
        }
    }
    return $headers;
}

function apiRawBody(): string
{
    return file_get_contents('php://input');
}

/**
 * Parses the raw JSON request body. Endpoints use this instead of
 * $_POST, since the HMAC signature (when enabled) is computed over the
 * exact raw body bytes — $_POST would only populate for form-encoded
 * bodies, not JSON, and re-encoding JSON to compare against the
 * signature risks byte-for-byte mismatches.
 */
function apiJsonBody(): array
{
    $decoded = json_decode(apiRawBody(), true);
    return is_array($decoded) ? $decoded : [];
}

// ================= AUTH =================

function apiValidateKey(PDO $conn, string $apiKey): ?array
{
    $stmt = $conn->prepare("SELECT * FROM api_keys WHERE api_key = ? AND status = 'active'");
    $stmt->execute([$apiKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function apiValidateSignature(array $env, array $headers, string $method, string $uri, string $body, array $key): void
{
    $timestamp = $headers['X-TIMESTAMP'] ?? null;
    $signature = $headers['X-SIGNATURE'] ?? null;

    if (!$timestamp || !$signature) {
        apiResponse(401, ['status' => 'error', 'error' => 'Missing signature headers']);
    }

    if (abs(time() - (int) $timestamp) > $env['REQUEST_TTL']) {
        apiResponse(401, ['status' => 'error', 'error' => 'Request expired']);
    }

    $payload = $method . '|' . $uri . '|' . $timestamp . '|' . $body;
    $expected = hash_hmac($env['HMAC_ALGO'], $payload, $key['secret_key']);

    if (!hash_equals($expected, $signature)) {
        apiResponse(403, ['status' => 'error', 'error' => 'Invalid signature']);
    }
}

function apiCheckRateLimit(PDO $conn, string $apiKey, array $key, array $env): void
{
    $limit = $key['rate_limit'] ?? $env['RATE_LIMIT'];
    $window = $key['rate_window'] ?? $env['RATE_WINDOW'];

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM api_logs
        WHERE api_key = ?
        AND created_at > (NOW() - INTERVAL ? SECOND)
    ");
    $stmt->execute([$apiKey, $window]);

    if ((int) $stmt->fetchColumn() >= $limit) {
        apiResponse(429, ['status' => 'error', 'error' => 'Rate limit exceeded']);
    }
}

/**
 * Full auth pipeline: API key + secret, optional HMAC signature, rate
 * limit. Returns [$key, $requestId] on success or halts the request
 * (via apiResponse) on any failure.
 */
function requireApiAuth(PDO $conn, array $env): array
{
    $requestId = apiRequestId();
    header("X-Request-ID: $requestId");

    $headers = apiHeaders();

    $apiKey = $headers['X-API-KEY'] ?? '';
    $secretKey = $headers['X-SECRET-KEY'] ?? '';

    if (!$apiKey || !$secretKey) {
        apiResponse(401, ['status' => 'error', 'error' => 'Missing API credentials', 'request_id' => $requestId]);
    }

    $key = apiValidateKey($conn, $apiKey);
    if (!$key) {
        apiResponse(403, ['status' => 'error', 'error' => 'Invalid API key', 'request_id' => $requestId]);
    }

    if (!hash_equals($key['secret_key'], $secretKey)) {
        apiResponse(403, ['status' => 'error', 'error' => 'Invalid secret key', 'request_id' => $requestId]);
    }

    $useSimpleAuth = $env['ALLOW_SIMPLE_AUTH'] ?? false;
    if (!$useSimpleAuth && ($env['REQUIRE_SIGNATURE'] ?? false)) {
        apiValidateSignature(
            $env,
            $headers,
            $_SERVER['REQUEST_METHOD'],
            parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
            apiRawBody(),
            $key
        );
    }

    apiCheckRateLimit($conn, $apiKey, $key, $env);

    return [$key, $requestId];
}

function logApiRequest(PDO $conn, array $env, string $apiKey, string $requestId): void
{
    if (!($env['LOG_API_REQUESTS'] ?? false)) {
        return;
    }
    try {
        $stmt = $conn->prepare("
            INSERT INTO api_logs (api_key, endpoint, request_id)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$apiKey, $_SERVER['REQUEST_URI'] ?? '', $requestId]);
    } catch (Exception $e) {
        error_log('API LOG ERROR: ' . $e->getMessage());
    }
}

// ================= PHARMACY RESOLUTION =================

/**
 * Resolves which pharmacy branch an API key is allowed to act on.
 *
 * api_keys.facility_id -> pharmacy.facility_id (per confirmed mapping
 * decision). NOTE: as of the current data dump, only one pharmacy row
 * (id 1) has facility_id populated — the rest are NULL. Any API key
 * whose facility_id doesn't match a pharmacy row will be rejected here
 * until pharmacy.facility_id is filled in for that branch. This isn't
 * a bug in this code — it's a data/config gap to close per-branch
 * before issuing that branch's device its API key.
 */
function requirePharmacyForKey(PDO $conn, array $key, ?string $requestId = null): array
{
    $facilityId = $key['facility_id'] ?? null;

    if (!$facilityId) {
        apiResponse(403, [
            'status' => 'error',
            'error' => 'This API key is not linked to a facility_id.',
            'request_id' => $requestId,
        ]);
    }

    $stmt = $conn->prepare("SELECT * FROM pharmacy WHERE facility_id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$facilityId]);
    $pharmacy = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pharmacy) {
        apiResponse(403, [
            'status' => 'error',
            'error' => 'No active pharmacy branch is linked to this API key\'s facility_id.',
            'request_id' => $requestId,
        ]);
    }

    return $pharmacy;
}