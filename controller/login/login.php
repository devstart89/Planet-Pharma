<?php

session_start();
require '../../config/db.php';
require_once '../../includes/doctor_setup_helpers.php';
header('Content-Type: application/json');

function respond(int $httpStatus, array $body): never {
    http_response_code($httpStatus);
    echo json_encode($body);
    exit;
}

/*
|--------------------------------------------------------------------------
| METHOD CHECK
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

/*
|--------------------------------------------------------------------------
| CSRF CHECK
|--------------------------------------------------------------------------
*/
if (
    empty($_SESSION['csrf_token']) ||
    empty($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    respond(403, ['status' => 'error', 'message' => 'Invalid CSRF token']);
}

/*
|--------------------------------------------------------------------------
| RATE LIMIT
|--------------------------------------------------------------------------
*/
const MAX_ATTEMPTS = 5;
const LOCK_TIME_SECONDS = 300;

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_attempt'] = time();
}

if (
    $_SESSION['login_attempts'] >= MAX_ATTEMPTS &&
    (time() - $_SESSION['last_attempt']) < LOCK_TIME_SECONDS
) {
    respond(429, ['status' => 'error', 'message' => 'Too many attempts. Try again later.']);
}

/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/
$login = trim($_POST['login'] ?? '');
$password = (string) ($_POST['password'] ?? ''); // not trimmed on purpose
$remember = isset($_POST['remember_token']);

if ($login === '' || $password === '') {
    respond(400, ['status' => 'error', 'message' => 'Username/email and password required.']);
}

if (mb_strlen($login) > 255 || mb_strlen($password) > 255) {
    respond(400, ['status' => 'error', 'message' => 'Invalid username or password.']);
}

/*
|--------------------------------------------------------------------------
| FIND USER
|--------------------------------------------------------------------------
| LEFT JOIN so accounts with no facility_id (e.g. pharmacy) still log
| in fine with a null facility name. Wrapped in try/catch so a schema
| mismatch on the facilities side can't take down login entirely.
*/
try {
    $stmt = $conn->prepare("
        SELECT u.*, hf.facility_name
        FROM users u
        LEFT JOIN health_facilities hf ON hf.id = u.facility_id
        WHERE (u.email = ? OR u.username = ?)
          AND u.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("login: facility-joined lookup failed — " . $e->getMessage());

    $stmt = $conn->prepare("
        SELECT * FROM users
        WHERE (email = ? OR username = ?) AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $user['facility_name'] = null;
    }
}

/*
|--------------------------------------------------------------------------
| PASSWORD VERIFY
|--------------------------------------------------------------------------
*/
if ($user && password_verify($password, $user['password'])) {

    // Transparently upgrade old/weak hashes now that we know the plaintext.
    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
        $rehash = password_hash($password, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $upd->execute([$rehash, $user['id']]);
    }

    $_SESSION['login_attempts'] = 0;
    session_regenerate_id(true);

    $role = normalizeRole($user['role']);

    $_SESSION['user'] = [
        'id'            => $user['id'],
        'name'          => $user['first_name'] . ' ' . $user['last_name'],
        'username'      => $user['username'],
        'role'          => $role,
        'pharmacy_id'   => $user['pharmacy_id'] ?? null,
        'facility_id'   => $user['facility_id'] ?? null,
        'facility_name' => $user['facility_name'] ?? null,
    ];

    /*
    |----------------------------------------------------------------
    | REMEMBER ME
    |----------------------------------------------------------------
    | Store only a hash of the token server-side. The plaintext token
    | lives solely in the user's cookie, so a database compromise
    | alone can't be used to forge a valid "remember me" session.
    */
    if ($remember) {
        $plainToken = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $plainToken);

        $stmt = $conn->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
        $stmt->execute([$hashedToken, $user['id']]);

        setcookie('remember_token', $plainToken, [
            'expires'  => time() + 86400 * 30,
            'path'     => '/',
            'httponly' => true,
            'secure'   => isset($_SERVER['HTTPS']),
            'samesite' => 'Strict',
        ]);
    }

    $knownRoles = ['doctor', 'health_facility', 'super_admin', 'pharmacy'];
    $redirectRole = in_array($role, $knownRoles, true) ? $role : 'unknown';
    $redirectUrl = "../pages/{$redirectRole}/index.php";

    if ($role === 'doctor') {
        try {
            $setupRow = fetchDoctorSetupRow($conn, (int) $user['id']);
            if ($setupRow && !isDoctorSetupComplete($setupRow, (bool) $setupRow['must_change_password'])) {
                // Route to Account Settings instead of the doctor dashboard
                // until email, username, license number, PTR number,
                // password, and signature are all in place. Relative to
                // public/login.php, since that's the page the browser
                // resolves this redirect against (window.location.href),
                // not this script's own filesystem location.
                $redirectUrl = "../pages/profile/index.php?setup=1";
            }
        } catch (PDOException $e) {
            // Most likely cause: must_change_password migration hasn't
            // been run yet. Fail open (send them to the normal dashboard)
            // rather than blocking login entirely over a missing column.
            error_log("login: doctor setup check failed — " . $e->getMessage());
        }
    }

    respond(200, [
        'status'   => 'success',
        'redirect' => $redirectUrl,
    ]);
}

$_SESSION['login_attempts']++;
$_SESSION['last_attempt'] = time();

respond(401, ['status' => 'error', 'message' => 'Invalid username or password.']);