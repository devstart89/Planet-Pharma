<?php
/**
 * Account Settings page
 *
 * Refactor notes vs. original:
 *  - Added CSRF protection. None of the three POST handlers (profile
 *    update, password change, signature upload) checked a token
 *    before — meaning any page that could get a logged-in doctor's
 *    browser to fire a cross-site POST to this endpoint could change
 *    their password or profile. A per-session token is now generated,
 *    embedded as a hidden field in all three forms, and verified
 *    before any POST branch runs.
 *  - Signature upload no longer trusts $_FILES[...]['type']. That
 *    value is the client-reported Content-Type of the upload and is
 *    trivially spoofable (e.g. rename a .php file to .png and set the
 *    form field's MIME by hand). The server now inspects the actual
 *    file bytes with finfo/getimagesize and derives the extension
 *    from what it detects, not from the client-supplied filename.
 *  - Upload directory now created with 0755 instead of 0777 — 0777
 *    grants world write access to the signature folder, which isn't
 *    needed for the web server to write its own uploads.
 *  - When a doctor replaces an existing signature, the old file is now
 *    deleted after the new one is confirmed saved in the DB, instead
 *    of being left behind indefinitely.
 *  - Role handling normalized through one normalizeRole() helper used
 *    for both the dashboard-URL lookup and the display label — the
 *    original had two separate role checks (one keyed on
 *    'health-facility', the other on both 'health_facility' and
 *    'health-facility') that could silently disagree if a role string
 *    used one separator instead of the other.
 *  - Password-change branch now checks that the current user row
 *    still exists before calling password_verify(), instead of
 *    assuming $dbUser is non-null.
 *  - $conn->prepare()/execute() calls are unchanged in shape (already
 *    parameterized) — kept as-is since they were already safe from
 *    SQL injection.
 *
 *  - FIX (Dashboard 404): getDashboardUrl()'s health_facility case
 *    pointed at '../health-facility/index.php' (hyphen) while every
 *    other page in this app (list/patient.php, add/patient.php,
 *    list/prescription.php, and many others) consistently uses the
 *    underscore folder name '../health_facility/'. Any health_facility
 *    (or nurse, which logs in under this same role) user clicking
 *    "Dashboard" from this page landed on a URL that doesn't exist on
 *    disk -> 404. Corrected to match the underscore convention used
 *    everywhere else.
 *
 *  - FIX (Doctor/Nurse Profile — Health Facility + Pharmacy): the
 *    facility lookup previously queried `facilities.name`, but this
 *    project's actual schema (confirmed across many other files this
 *    session) is `health_facilities.facility_name` — a table/column
 *    name that doesn't exist, silently caught by the try/catch below,
 *    meant $user['facility_name'] was ALWAYS null regardless of the
 *    user's real assigned facility. Corrected to the real table/column,
 *    and added a second lookup resolving the facility's assigned
 *    Pharmacy (via health_facilities.pharmacy_id -> pharmacy table,
 *    same join pattern used in the pharmacy lookup elsewhere in this
 *    app). Both are now also shown as their own read-only fields inside
 *    the Profile Information card itself (previously Health Facility
 *    only appeared as a small chip in the hero banner, and Pharmacy
 *    didn't appear anywhere on this page at all).
 *
 * Doctor first-login setup (tickets 5–7):
 *  - Successfully changing your password clears must_change_password.
 *  - While setup is incomplete, this page renders in a locked-open
 *    state: profile fields start editable (no Edit/Cancel toggle),
 *    a checklist banner shows exactly what's missing, and "Back" is
 *    disabled — see isDoctorSetupComplete() in doctor_setup_helpers.php
 *    for the full definition of "complete".
 *  - PTR Number is OPTIONAL — not part of the first-login requirement,
 *    unlike License Number.
 *  - Once every requirement is satisfied, saving redirects straight
 *    to the doctor's real dashboard instead of back to this page.
 *  - This page is intentionally exempt from require_doctor_setup.php's
 *    redirect (it's the destination of that redirect) — see that
 *    file's DOCTOR_SETUP_EXEMPT_SCRIPTS for wiring it into your other
 *    protected pages.
 */

session_start();
require '../../config/db.php';
require_once '../../includes/doctor_setup_helpers.php';

if (!isset($_SESSION['user'])) {
    header("Location: ../../public/login.php");
    exit;
}

/* ---------- ROLE → DASHBOARD MAP ---------- */
function getDashboardUrl(string $role): string {
    return match (normalizeRole($role)) {
        'doctor'          => '../doctor/index.php',
        // FIX (Dashboard 404): this was '../health-facility/index.php'
        // (hyphen) — every other page in this app (list/patient.php,
        // add/patient.php, list/prescription.php, and many others)
        // consistently uses the underscore folder name
        // '../health_facility/'. A health_facility (or nurse, which logs
        // in under this same role) user clicking "Dashboard" from this
        // page landed on a URL that doesn't exist on disk -> 404.
        'health_facility' => '../health_facility/index.php',
        'super_admin'     => '../super_admin/index.php',
        default           => '../../login.php',
    };
}

$userId   = $_SESSION['user']['id'];
$userRole = normalizeRole($_SESSION['user']['role']);
$dashboardUrl = getDashboardUrl($userRole);

/* ---------- CSRF TOKEN ---------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ================= HANDLE FORM SUBMIT ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        if (
            empty($_SESSION['csrf_token']) ||
            empty($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
        ) {
            throw new Exception("Your session has expired. Please refresh the page and try again.");
        }

        /* ================= UPDATE PROFILE ================= */
        if (isset($_POST['update_profile'])) {

            $first_name = trim($_POST['first_name'] ?? '');
            $last_name  = trim($_POST['last_name'] ?? '');
            $email      = trim($_POST['email'] ?? '');
            $username   = trim($_POST['username'] ?? '');

            if (!$first_name || !$last_name || !$email || !$username) {
                throw new Exception("All profile fields are required.");
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email address.");
            }

            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $userId]);
            if ($stmt->fetch()) {
                throw new Exception("Email already exists.");
            }

            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$username, $userId]);
            if ($stmt->fetch()) {
                throw new Exception("Username already exists.");
            }

            if ($userRole === 'doctor') {
                $license_number = trim($_POST['license_number'] ?? '');
                $ptr_number     = trim($_POST['ptr_number'] ?? ''); // optional

                if (!$license_number) {
                    throw new Exception("License number is required for doctor accounts.");
                }

                $stmt = $conn->prepare("SELECT id FROM users WHERE license_number = ? AND id != ?");
                $stmt->execute([$license_number, $userId]);
                if ($stmt->fetch()) {
                    throw new Exception("License number already exists.");
                }

                $stmt = $conn->prepare("
                    UPDATE users
                    SET first_name = ?, last_name = ?, email = ?, username = ?,
                        license_number = ?, ptr_number = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $first_name, $last_name, $email, $username,
                    $license_number, $ptr_number ?: null,
                    $userId,
                ]);
            } else {
                $stmt = $conn->prepare("
                    UPDATE users
                    SET first_name = ?, last_name = ?, email = ?, username = ?
                    WHERE id = ?
                ");
                $stmt->execute([$first_name, $last_name, $email, $username, $userId]);
            }

            $_SESSION['user']['name'] = $first_name . ' ' . $last_name;
            $_SESSION['success'] = "Profile updated successfully.";
        }

        /* ================= CHANGE PASSWORD ================= */
        if (isset($_POST['change_password'])) {

            $current = (string) ($_POST['current_password'] ?? '');
            $new     = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');

            if (!$current || !$new || !$confirm) {
                throw new Exception("All password fields are required.");
            }
            if ($new !== $confirm) {
                throw new Exception("Passwords do not match.");
            }
            if (strlen($new) < 8) {
                throw new Exception("Password must be at least 8 characters.");
            }

            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$dbUser || !password_verify($current, $dbUser['password'])) {
                throw new Exception("Current password is incorrect.");
            }

            $hash = password_hash($new, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hash, $userId]);

            // Clear the first-login "must change password" flag, if the
            // migration for it has been applied. Failing this silently
            // (logged only) means an un-migrated install just won't
            // enforce the first-login flow yet, rather than blocking a
            // normal password change.
            try {
                $conn->prepare("UPDATE users SET must_change_password = 0 WHERE id = ?")
                     ->execute([$userId]);
            } catch (PDOException $e) {
                error_log("account settings: clearing must_change_password failed — " . $e->getMessage());
            }

            $_SESSION['success'] = "Password updated successfully.";
        }

        /* ================= DOCTOR SIGNATURE UPLOAD ================= */
        if (isset($_POST['upload_signature'])) {

            if ($userRole !== 'doctor') {
                throw new Exception("Only doctors can upload signatures.");
            }

            if (!isset($_FILES['signature_path']) || $_FILES['signature_path']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Signature file is required.");
            }

            $file = $_FILES['signature_path'];

            if ($file['size'] > 2 * 1024 * 1024) {
                throw new Exception("File must be less than 2MB.");
            }

            /*
             * Detect the REAL mime type from file contents rather than
             * trusting $file['type'] (client-supplied, spoofable), and
             * confirm it's actually a decodable image while we're at
             * it. getimagesize() returns false for anything that isn't
             * a real image, catching a broader class of disguised
             * uploads than a mime-string check alone.
             */
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detectedType = $finfo->file($file['tmp_name']);

            $allowedTypes = [
                'image/png'  => 'png',
                'image/jpeg' => 'jpg',
            ];

            if (!isset($allowedTypes[$detectedType]) || @getimagesize($file['tmp_name']) === false) {
                throw new Exception("Only PNG or JPG files are allowed.");
            }

            $ext = $allowedTypes[$detectedType];

            /*
             * PATH HANDLING
             * -------------
             * Filename uses uniqid('sig_') — same convention as
             * prescription_approve_process.php. users.signature_path
             * stores the FULL relative path, because that exact
             * string is used directly as a filesystem path by
             * generate_prescription_pdf.php and
             * prescription_approve_process.php, both two folders deep
             * under the project root — same depth as this page.
             */
            $uploadDir = "../../assets/uploads/signatures/";
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                throw new Exception("Could not prepare upload directory.");
            }

            $filename = uniqid('sig_', true) . '.' . $ext;
            $path = $uploadDir . $filename;

            if (!move_uploaded_file($file['tmp_name'], $path)) {
                throw new Exception("Failed to upload signature.");
            }

            $dbSignaturePath = $uploadDir . $filename;

            $stmt = $conn->prepare("SELECT signature_path FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $previousPath = $stmt->fetchColumn() ?: null;

            $stmt = $conn->prepare("UPDATE users SET signature_path = ? WHERE id = ?");
            $stmt->execute([$dbSignaturePath, $userId]);

            // Clean up the old file now that the new one is confirmed saved.
            if ($previousPath && $previousPath !== $dbSignaturePath && is_file($previousPath)) {
                @unlink($previousPath);
            }

            $_SESSION['success'] = "Signature saved successfully.";
        }

    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    // If this doctor has now satisfied every first-login requirement,
    // send them straight to their real dashboard instead of looping
    // back to this page. Only fires on a successful save (an exception
    // above still falls through to the normal index.php redirect).
    if (!isset($_SESSION['error']) && $userRole === 'doctor') {
        try {
            $freshRow = fetchDoctorSetupRow($conn, $userId);
            if ($freshRow && isDoctorSetupComplete($freshRow, (bool) $freshRow['must_change_password'])) {
                header("Location: " . getDashboardUrl($userRole));
                exit;
            }
        } catch (PDOException $e) {
            error_log("account settings: post-save setup recheck failed — " . $e->getMessage());
        }
    }

    header("Location: index.php");
    exit;
}

/* ================= GET USER ================= */
try {
    $stmt = $conn->prepare("
        SELECT id, first_name, last_name, email, username, role,
               license_number, ptr_number, facility_id, signature_path,
               must_change_password, created_at
        FROM users
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("account settings: base user query failed — " . $e->getMessage());

    $stmt = $conn->prepare("
        SELECT id, first_name, last_name, email, username, role,
               facility_id, signature_path, created_at
        FROM users
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $user['license_number']       = null;
        $user['ptr_number']           = null;
        // Fail open: without this column we can't know, so don't force
        // the doctor into the setup flow over a missing migration.
        $user['must_change_password'] = 0;
    }
}

if (!$user) {
    session_destroy();
    header("Location: ../../public/login.php");
    exit;
}

$user['facility_name'] = null;
$user['pharmacy_name'] = null;

if (!empty($user['facility_id'])) {
    /*
     * FIX (Doctor/Nurse Profile — Health Facility + Pharmacy):
     * previously queried `facilities.name`, a table/column that
     * doesn't exist in this project's real schema — the try/catch
     * below caught that failure silently every single time, so
     * facility_name was ALWAYS null regardless of the user's actual
     * assignment. Corrected to health_facilities.facility_name,
     * matching every other file in this app that reads facility data.
     *
     * Also resolves the facility's assigned Pharmacy in the same
     * query (health_facilities.pharmacy_id -> pharmacy.pharmacy_name),
     * since Pharmacy wasn't being looked up anywhere on this page at
     * all before.
     */
    try {
        $facStmt = $conn->prepare("
            SELECT hf.facility_name, p.pharmacy_name
            FROM health_facilities hf
            LEFT JOIN pharmacy p ON hf.pharmacy_id = p.id
            WHERE hf.id = ?
            LIMIT 1
        ");
        $facStmt->execute([$user['facility_id']]);
        $facRow = $facStmt->fetch(PDO::FETCH_ASSOC);

        if ($facRow) {
            $user['facility_name'] = $facRow['facility_name'] ?: null;
            $user['pharmacy_name'] = $facRow['pharmacy_name'] ?: null;
        }
    } catch (PDOException $e) {
        error_log("account settings: facility/pharmacy lookup failed — " . $e->getMessage());
    }
}

$mustChangePassword = (bool) ($user['must_change_password'] ?? false);
$setupRequired = $userRole === 'doctor' && !isDoctorSetupComplete($user, $mustChangePassword);

$setupChecklist = [
    'Email address filled in'  => trim((string) $user['email']) !== '',
    'Username filled in'       => trim((string) $user['username']) !== '',
    'License number filled in' => trim((string) ($user['license_number'] ?? '')) !== '',
    'Password changed'         => !$mustChangePassword,
    'Signature uploaded'       => !empty($user['signature_path']),
];

$initials = strtoupper(mb_substr($user['first_name'], 0, 1) . mb_substr($user['last_name'], 0, 1));
$roleLabel = match (normalizeRole($user['role'])) {
    'doctor'          => 'Doctor',
    'health_facility' => 'Health Facility Staff',
    'pharmacy'        => 'Pharmacy Staff',
    'super_admin'     => 'System Administrator',
    default           => ucfirst($user['role']),
};

// Health Facility + Pharmacy read-only fields only make sense for
// accounts that actually have a facility assignment — doctor and
// health_facility (nurse accounts also log in under this same role).
$showFacilityPharmacyFields = in_array($userRole, ['doctor', 'health_facility'], true);

include '../../includes/header.php';
?>
<style>
    :root {
        --clinic-primary: #0e7c86;
        --clinic-primary-dark: #0b6169;
        --clinic-primary-light: #e6f6f7;
        --clinic-accent: #2c93a1;
        --clinic-border: #e2eceb;
        --clinic-muted: #5b7a7c;
        --clinic-amber: #b8860b;
        --clinic-amber-light: #fdf6e8;
    }

    body { background: #f4f9f9; }

    .clinic-hero {
        background: linear-gradient(135deg, var(--clinic-primary) 0%, var(--clinic-accent) 100%);
        border-radius: 1rem;
        padding: 1.75rem 2rem;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.75rem;
        box-shadow: 0 6px 20px rgba(14, 124, 134, 0.18);
        position: relative;
        overflow: hidden;
        flex-wrap: wrap;
    }
    .clinic-hero::after {
        content: "";
        position: absolute;
        right: -30px;
        top: -30px;
        width: 160px;
        height: 160px;
        background: rgba(255,255,255,0.08);
        border-radius: 50%;
    }
    .clinic-hero .hero-icon {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: rgba(255,255,255,0.18);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.6rem;
        flex-shrink: 0;
        z-index: 1;
    }
    .clinic-hero h4 { margin: 0; font-weight: 700; z-index: 1; }
    .clinic-hero p { margin: 0; opacity: .9; font-size: .9rem; z-index: 1; }
    .hero-back-btn {
        background: rgba(255,255,255,0.16);
        border: 1px solid rgba(255,255,255,0.35);
        color: #fff;
        font-weight: 600;
        z-index: 1;
        white-space: nowrap;
    }
    .hero-back-btn:hover {
        background: rgba(255,255,255,0.28);
        color: #fff;
    }
    .hero-facility {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        margin-top: .4rem;
        font-size: .82rem;
        font-weight: 600;
        color: #fff;
        background: rgba(255,255,255,0.16);
        border: 1px solid rgba(255,255,255,0.3);
        padding: .2rem .6rem;
        border-radius: .5rem;
        z-index: 1;
    }

    .clinic-card {
        background: #fff;
        border: 1px solid var(--clinic-border);
        border-radius: 1rem;
        box-shadow: 0 2px 10px rgba(14, 124, 134, 0.05);
        overflow: hidden;
    }
    .clinic-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        padding: 1.1rem 1.4rem;
        border-bottom: 1px solid var(--clinic-border);
        background: #fbfdfd;
    }
    .clinic-card-header .title-group { display: flex; align-items: center; gap: .75rem; }
    .clinic-icon-badge {
        width: 40px;
        height: 40px;
        border-radius: 0.65rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }
    .clinic-icon-badge.teal   { background: var(--clinic-primary-light); color: var(--clinic-primary-dark); }
    .clinic-icon-badge.amber  { background: var(--clinic-amber-light); color: var(--clinic-amber); }
    .clinic-icon-badge.blue   { background: #eaf2fb; color: #2563a6; }

    .clinic-card-header h5 { margin: 0; font-weight: 700; font-size: 1rem; color: #1d2939; }
    .clinic-card-header .subtitle { font-size: .78rem; color: var(--clinic-muted); margin-top: 1px; }
    .clinic-card-body { padding: 1.5rem 1.4rem; }

    .clinic-avatar {
        width: 68px;
        height: 68px;
        border-radius: 50%;
        background: var(--clinic-primary);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        font-weight: 700;
        flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(14,124,134,0.25);
    }
    .role-pill {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        background: var(--clinic-primary-light);
        color: var(--clinic-primary-dark);
        font-weight: 600;
        font-size: .78rem;
        padding: .25rem .65rem;
        border-radius: .5rem;
    }

    .form-label {
        font-size: .78rem;
        font-weight: 600;
        color: var(--clinic-muted);
        text-transform: uppercase;
        letter-spacing: .02em;
    }
    .form-control:focus {
        border-color: var(--clinic-primary);
        box-shadow: 0 0 0 .2rem rgba(14, 124, 134, 0.15);
    }
    .btn-clinic-primary {
        background: var(--clinic-primary);
        border-color: var(--clinic-primary);
        color: #fff;
    }
    .btn-clinic-primary:hover { background: var(--clinic-primary-dark); border-color: var(--clinic-primary-dark); color: #fff; }
    .btn-outline-clinic {
        border-color: var(--clinic-primary);
        color: var(--clinic-primary-dark);
        background: transparent;
    }
    .btn-outline-clinic:hover { background: var(--clinic-primary-light); color: var(--clinic-primary-dark); }

    /* Signature editor */
    #signatureCanvasWrap {
        position: relative;
        border: 1px dashed var(--clinic-border);
        border-radius: .75rem;
        background:
            linear-gradient(45deg, #fafcfc 25%, transparent 25%),
            linear-gradient(-45deg, #fafcfc 25%, transparent 25%),
            linear-gradient(45deg, transparent 75%, #fafcfc 75%),
            linear-gradient(-45deg, transparent 75%, #fafcfc 75%);
        background-size: 16px 16px;
        background-position: 0 0, 0 8px, 8px -8px, -8px 0px;
        background-color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        height: 170px;
        overflow: hidden;
    }
    #signatureCanvas { max-width: 100%; max-height: 100%; }
    #noSignatureText { color: var(--clinic-muted); font-size: .85rem; position: absolute; }
    .sig-tool-btn {
        width: 38px; height: 38px;
        border-radius: .5rem;
        display: inline-flex; align-items: center; justify-content: center;
        border: 1px solid var(--clinic-border);
        background: #fff;
        color: var(--clinic-primary-dark);
    }
    .sig-tool-btn:hover:not(:disabled) { background: var(--clinic-primary-light); }
    .sig-tool-btn:disabled { opacity: .4; cursor: not-allowed; }
    .rotation-badge {
        font-size: .75rem; font-weight: 700; color: var(--clinic-muted);
        background: #f1f6f6; border-radius: .4rem; padding: .2rem .5rem;
    }

    @media (max-width: 767.98px) {
        .clinic-hero { padding: 1.25rem 1.4rem; }
        .clinic-card-body { padding: 1.1rem; }
    }
</style>

<!-- Page Title -->
<div class="page-title">
  <nav class="breadcrumbs">
    <div class="container">
      <ol>
        <li><a href="<?= $dashboardUrl ?>">Dashboard</a></li>
        <li class="current">My Account</li>
      </ol>
    </div>
  </nav>
</div><!-- End Page Title -->

<div class="container pt-4 pb-5">

    <!-- HERO -->
    <div class="clinic-hero">
        <div class="d-flex align-items-center gap-3">
            <div class="hero-icon"><i class="bi bi-hospital"></i></div>
            <div>
                <h4 class="text-white">Account Settings</h4>
                <p>Manage your profile, security, and clinical credentials</p>
                <?php if (!empty($user['facility_name'])): ?>
                    <div class="hero-facility">
                        <i class="bi bi-building"></i> <?= htmlspecialchars($user['facility_name']) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($setupRequired): ?>
            <button type="button" class="btn btn-sm hero-back-btn" disabled
                    title="Finish the required setup below before leaving this page">
                <i class="bi bi-lock"></i> Back
            </button>
        <?php else: ?>
            <a href="javascript:history.back()" class="btn btn-sm hero-back-btn">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        <?php endif; ?>
    </div>

    <?php if ($setupRequired): ?>
        <div class="clinic-card mb-4" style="border-color: var(--clinic-amber);">
            <div class="clinic-card-body">
                <div class="d-flex align-items-start gap-3">
                    <span class="clinic-icon-badge amber"><i class="bi bi-exclamation-triangle"></i></span>
                    <div class="flex-grow-1">
                        <h5 class="mb-1">Complete your account setup to continue</h5>
                        <p class="subtitle mb-3">
                            Before you can access the rest of the system, please finish the items below.
                            These are used on every prescription you sign.
                        </p>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($setupChecklist as $label => $done): ?>
                                <li class="mb-1">
                                    <?php if ($done): ?>
                                        <i class="bi bi-check-circle-fill text-success"></i>
                                        <span class="text-decoration-line-through" style="color: var(--clinic-muted);"><?= htmlspecialchars($label) ?></span>
                                    <?php else: ?>
                                        <i class="bi bi-circle" style="color: var(--clinic-amber);"></i>
                                        <strong><?= htmlspecialchars($label) ?></strong>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['success'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                Swal.fire({ icon: 'success', title: 'Success', text: <?= json_encode($_SESSION['success']) ?> });
            });
        </script>
    <?php unset($_SESSION['success']); endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                Swal.fire({ icon: 'error', title: 'Error', text: <?= json_encode($_SESSION['error']) ?> });
            });
        </script>
    <?php unset($_SESSION['error']); endif; ?>

    <div class="row g-4">

        <!-- PROFILE CARD -->
        <div class="col-lg-7">
            <div class="clinic-card mb-4">

                <div class="clinic-card-header">
                    <div class="title-group">
                        <span class="clinic-icon-badge blue"><i class="bi bi-person-badge"></i></span>
                        <div>
                            <h5>Profile Information</h5>
                            <div class="subtitle">Your personal and account details</div>
                        </div>
                    </div>
                    <?php if (!$setupRequired): ?>
                        <button type="button" id="editProfileBtn" class="btn btn-outline-clinic btn-sm">
                            <i class="bi bi-pencil"></i> Edit
                        </button>
                    <?php else: ?>
                        <span class="badge" style="background: var(--clinic-amber-light); color: var(--clinic-amber);">
                            <i class="bi bi-pencil-fill"></i> Editing required
                        </span>
                    <?php endif; ?>
                </div>

                <div class="clinic-card-body">

                    <div class="d-flex align-items-center gap-3 mb-4">
                        <div class="clinic-avatar"><?= htmlspecialchars($initials) ?></div>
                        <div>
                            <div class="fw-bold" style="font-size:1.05rem; color:#1d2939;">
                                <?= htmlspecialchars($user['first_name'].' '.$user['last_name']) ?>
                            </div>
                            <span class="role-pill"><i class="bi bi-shield-check"></i> <?= htmlspecialchars($roleLabel) ?></span>
                        </div>
                    </div>

                    <form method="POST" id="profileForm">
                        <input type="hidden" name="update_profile" value="1">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="row">

                            <?php $ro = $setupRequired ? '' : 'readonly'; ?>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">First Name</label>
                                <input type="text" name="first_name" class="form-control profile-input"
                                       value="<?= htmlspecialchars($user['first_name']) ?>" placeholder="e.g. Juan" <?= $ro ?>>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="last_name" class="form-control profile-input"
                                       value="<?= htmlspecialchars($user['last_name']) ?>" placeholder="e.g. Dela Cruz" <?= $ro ?>>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email <?= $setupRequired ? '<span class="text-danger">*</span>' : '' ?></label>
                                <input type="email" name="email" class="form-control profile-input"
                                       value="<?= htmlspecialchars($user['email']) ?>" placeholder="e.g. juan.delacruz@email.com" <?= $ro ?> required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username <?= $setupRequired ? '<span class="text-danger">*</span>' : '' ?></label>
                                <input type="text" name="username" class="form-control profile-input"
                                       value="<?= htmlspecialchars($user['username']) ?>" placeholder="e.g. jdelacruz" <?= $ro ?> required>
                            </div>

                            <?php if ($userRole === 'doctor'): ?>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">License Number <?= $setupRequired ? '<span class="text-danger">*</span>' : '' ?></label>
                                <input type="text" name="license_number" class="form-control profile-input"
                                       value="<?= htmlspecialchars($user['license_number'] ?? '') ?>" placeholder="e.g. 1234567" <?= $ro ?> required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">PTR Number </label>
                                <input type="text" name="ptr_number" class="form-control profile-input"
                                       value="<?= htmlspecialchars($user['ptr_number'] ?? '') ?>" placeholder="e.g. 9876543" <?= $ro ?>>
                            </div>
                            <?php endif; ?>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Role</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($roleLabel) ?>" readonly>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Member Since</label>
                                <input type="text" class="form-control"
                                       value="<?= date('F d, Y', strtotime($user['created_at'])) ?>" readonly>
                            </div>

                            <?php if ($showFacilityPharmacyFields): ?>
                            <!--
                                NEW (Doctor/Nurse Profile — Health Facility +
                                Pharmacy): both shown as read-only — these are
                                assigned by an administrator via User
                                Management, not something the account holder
                                edits from their own profile. "Not assigned"
                                is shown rather than leaving a blank input if
                                either lookup came back empty (e.g. facility
                                has no pharmacy linked yet).
                            -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Health Facility</label>
                                <input type="text" class="form-control"
                                       value="<?= htmlspecialchars($user['facility_name'] ?: 'Not assigned') ?>" readonly>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Pharmacy</label>
                                <input type="text" class="form-control"
                                       value="<?= htmlspecialchars($user['pharmacy_name'] ?: 'Not assigned') ?>" readonly>
                            </div>
                            <?php endif; ?>

                        </div>

                        <div id="profileActions" class="<?= $setupRequired ? 'd-flex' : 'd-none d-flex' ?> gap-2">
                            <button type="button" id="saveProfileBtn" class="btn btn-clinic-primary">
                                <i class="bi bi-check-circle"></i> Save Changes
                            </button>
                            <?php if (!$setupRequired): ?>
                                <button type="button" id="cancelEditBtn" class="btn btn-outline-secondary">
                                    Cancel
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>

                </div>
            </div>

            <?php if ($userRole === 'doctor'): ?>
            <!-- SIGNATURE CARD -->
            <div class="clinic-card">

                <div class="clinic-card-header">
                    <div class="title-group">
                        <span class="clinic-icon-badge teal"><i class="bi bi-pen"></i></span>
                        <div>
                            <h5>Doctor E-Signature</h5>
                            <div class="subtitle">Used automatically whenever you sign a prescription</div>
                        </div>
                    </div>
                    <?php if ($setupRequired && empty($user['signature_path'])): ?>
                        <span class="badge" style="background: var(--clinic-amber-light); color: var(--clinic-amber);">Required</span>
                    <?php endif; ?>
                </div>

                <div class="clinic-card-body">
                    <div class="row g-4 align-items-start">

                        <!-- CANVAS PREVIEW / EDITOR -->
                        <div class="col-md-5 text-center">
                            <div id="signatureCanvasWrap">
                                <canvas id="signatureCanvas" width="300" height="150"></canvas>
                                <span id="noSignatureText" class="d-none">No signature uploaded yet.</span>
                            </div>

                            <div class="d-flex align-items-center justify-content-center gap-2 mt-3">
                                <button type="button" id="rotateLeftBtn" class="sig-tool-btn" title="Rotate left" disabled>
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                                <span class="rotation-badge" id="rotationBadge">0°</span>
                                <button type="button" id="rotateRightBtn" class="sig-tool-btn" title="Rotate right" disabled>
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>

                            <div id="previewPendingBadge" class="badge mt-2 d-none" style="background: var(--clinic-amber-light); color: var(--clinic-amber);">
                                <i class="bi bi-eye"></i> Unsaved changes
                            </div>
                        </div>

                        <!-- UPLOAD -->
                        <div class="col-md-7">
                            <form method="POST" enctype="multipart/form-data" id="signatureForm">
                                <input type="hidden" name="upload_signature" value="1">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                                <label class="form-label d-block mb-1">
                                    <?= empty($user['signature_path']) ? 'Upload Signature (PNG/JPG)' : 'Replace Signature (PNG/JPG)' ?>
                                </label>
                                <input type="file" name="signature_path" id="signatureFileInput"
                                       class="form-control" accept="image/png,image/jpeg">
                                <div class="form-text">
                                    Max 2MB. Rotate it into place using the controls above before saving —
                                    this is what gets stamped on every prescription you sign.
                                </div>
                                <small class="text-danger d-none" id="signatureFileError"></small>

                                <div class="d-flex gap-2 mt-3">
                                    <button type="button" id="saveSignatureBtn" class="btn btn-clinic-primary" disabled>
                                        <i class="bi bi-check-circle"></i> Save Signature
                                    </button>
                                    <button type="button" id="resetSignatureBtn" class="btn btn-outline-secondary d-none">
                                        Reset
                                    </button>
                                </div>
                            </form>
                        </div>

                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <!-- PASSWORD CARD -->
        <div class="col-lg-5">
            <div class="clinic-card">

                <div class="clinic-card-header">
                    <div class="title-group">
                        <span class="clinic-icon-badge amber"><i class="bi bi-shield-lock"></i></span>
                        <div>
                            <h5>Change Password</h5>
                            <div class="subtitle">Keep your account secure</div>
                        </div>
                    </div>
                    <?php if ($setupRequired && $mustChangePassword): ?>
                        <span class="badge" style="background: var(--clinic-amber-light); color: var(--clinic-amber);">Required</span>
                    <?php endif; ?>
                </div>

                <div class="clinic-card-body">
                    <form method="POST" id="passwordForm">
                        <input type="hidden" name="change_password" value="1">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control" placeholder="Enter current password" autocomplete="current-password" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control" placeholder="At least 8 characters" autocomplete="new-password" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter new password" autocomplete="new-password" required>
                        </div>

                        <button type="button" id="changePasswordBtn" class="btn btn-clinic-primary w-100">
                            <i class="bi bi-shield-lock me-1"></i> Update Password
                        </button>
                    </form>
                </div>
            </div>
        </div>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ================= PROFILE EDIT TOGGLE ================= */
    const editBtn = document.getElementById('editProfileBtn');
    const cancelBtn = document.getElementById('cancelEditBtn');
    const actions = document.getElementById('profileActions');
    const inputs = document.querySelectorAll('.profile-input');

    editBtn?.addEventListener('click', function () {
        inputs.forEach(input => input.readOnly = false);
        actions.classList.remove('d-none');
        actions.classList.add('d-flex');
        editBtn.classList.add('d-none');
    });

    cancelBtn?.addEventListener('click', function () {
        Swal.fire({
            title: 'Discard Changes?',
            text: 'Unsaved changes will be lost.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes'
        }).then(result => {
            if (result.isConfirmed) location.reload();
        });
    });

    document.getElementById('saveProfileBtn')?.addEventListener('click', function () {
        Swal.fire({
            title: 'Save Profile?',
            text: 'Update your profile information?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Save'
        }).then(result => {
            if (result.isConfirmed) {
                document.querySelectorAll('.profile-input').forEach(input => input.readOnly = false);
                document.getElementById('profileForm').submit();
            }
        });
    });

    /* ================= CHANGE PASSWORD ================= */
    document.getElementById('changePasswordBtn')?.addEventListener('click', function () {
        const newPass = document.querySelector('[name="new_password"]').value;
        const confirmPass = document.querySelector('[name="confirm_password"]').value;

        if (newPass !== confirmPass) {
            Swal.fire({ icon: 'error', title: 'Password Mismatch', text: 'Passwords do not match.' });
            return;
        }

        Swal.fire({
            title: 'Change Password?',
            text: 'Continue updating your password?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Change Password'
        }).then(result => {
            if (result.isConfirmed) document.getElementById('passwordForm').submit();
        });
    });

    /* =========================================================
       DOCTOR SIGNATURE EDITOR
       Canvas-based so rotation actually changes the exported
       pixels (a CSS transform on an <img> wouldn't survive into
       the uploaded file). Works on the existing saved signature
       (loaded automatically on page load) AND on a freshly chosen
       file — either way, Save exports the current canvas as a PNG
       blob and swaps it into the file input right before submit,
       so the PHP upload handler needs no changes at all.
    ========================================================= */
    const canvas = document.getElementById('signatureCanvas');
    const ctx = canvas?.getContext('2d');
    const noSigText = document.getElementById('noSignatureText');
    const rotationBadge = document.getElementById('rotationBadge');
    const pendingBadge = document.getElementById('previewPendingBadge');
    const rotateLeftBtn = document.getElementById('rotateLeftBtn');
    const rotateRightBtn = document.getElementById('rotateRightBtn');
    const saveSigBtn = document.getElementById('saveSignatureBtn');
    const resetSigBtn = document.getElementById('resetSignatureBtn');
    const sigFileInput = document.getElementById('signatureFileInput');
    const sigFileError = document.getElementById('signatureFileError');

    if (canvas) {

        const ORIGINAL_SRC = <?= json_encode($user['signature_path'] ?: null) ?>;
        const MAX_BYTES = 2 * 1024 * 1024;
        const ALLOWED_TYPES = ['image/png', 'image/jpeg'];

        let currentImage = null;
        let rotation = 0;
        let isDirty = false;

        function setControlsEnabled(hasImage) {
            rotateLeftBtn.disabled = !hasImage;
            rotateRightBtn.disabled = !hasImage;
        }

        function draw() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            if (!currentImage) {
                noSigText.classList.remove('d-none');
                return;
            }
            noSigText.classList.add('d-none');

            const swap = (rotation === 90 || rotation === 270);
            const iw = currentImage.naturalWidth;
            const ih = currentImage.naturalHeight;
            const boxW = swap ? ih : iw;
            const boxH = swap ? iw : ih;

            const scale = Math.min(canvas.width / boxW, canvas.height / boxH, 1) * 0.92;
            const drawW = iw * scale;
            const drawH = ih * scale;

            ctx.save();
            ctx.translate(canvas.width / 2, canvas.height / 2);
            ctx.rotate(rotation * Math.PI / 180);
            ctx.drawImage(currentImage, -drawW / 2, -drawH / 2, drawW, drawH);
            ctx.restore();

            rotationBadge.textContent = rotation + '°';
        }

        function loadImage(src) {
            const img = new Image();
            img.onload = function () {
                currentImage = img;
                rotation = 0;
                draw();
                setControlsEnabled(true);
            };
            img.onerror = function () {
                currentImage = null;
                draw();
                setControlsEnabled(false);
            };
            img.src = src;
        }

        function markDirty() {
            isDirty = true;
            saveSigBtn.disabled = false;
            resetSigBtn.classList.remove('d-none');
            pendingBadge.classList.remove('d-none');
        }

        function markClean() {
            isDirty = false;
            saveSigBtn.disabled = true;
            resetSigBtn.classList.add('d-none');
            pendingBadge.classList.add('d-none');
        }

        function resetToOriginal() {
            sigFileInput.value = '';
            sigFileError.classList.add('d-none');
            markClean();

            if (ORIGINAL_SRC) {
                loadImage(ORIGINAL_SRC);
            } else {
                currentImage = null;
                rotation = 0;
                draw();
                setControlsEnabled(false);
            }
        }

        if (ORIGINAL_SRC) {
            loadImage(ORIGINAL_SRC);
        } else {
            setControlsEnabled(false);
            noSigText.classList.remove('d-none');
        }

        rotateLeftBtn.addEventListener('click', function () {
            rotation = (rotation - 90 + 360) % 360;
            draw();
            markDirty();
        });

        rotateRightBtn.addEventListener('click', function () {
            rotation = (rotation + 90) % 360;
            draw();
            markDirty();
        });

        sigFileInput.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;

            if (!ALLOWED_TYPES.includes(file.type)) {
                sigFileError.textContent = 'Only PNG or JPG files are allowed.';
                sigFileError.classList.remove('d-none');
                this.value = '';
                return;
            }
            if (file.size > MAX_BYTES) {
                sigFileError.textContent = 'File must be less than 2MB.';
                sigFileError.classList.remove('d-none');
                this.value = '';
                return;
            }
            sigFileError.classList.add('d-none');

            const reader = new FileReader();
            reader.onload = function (e) {
                loadImage(e.target.result);
                markDirty();
            };
            reader.readAsDataURL(file);
        });

        resetSigBtn.addEventListener('click', resetToOriginal);

        saveSigBtn.addEventListener('click', function () {
            if (!currentImage) {
                Swal.fire('Error', 'Please choose a signature image first.', 'error');
                return;
            }

            Swal.fire({
                title: 'Save this signature?',
                text: 'It will be used automatically every time you sign a prescription.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Save Signature'
            }).then(result => {
                if (!result.isConfirmed) return;

                canvas.toBlob(function (blob) {
                    if (!blob) {
                        Swal.fire('Error', 'Could not process the image. Please try again.', 'error');
                        return;
                    }
                    const file = new File([blob], 'signature.png', { type: 'image/png' });
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    sigFileInput.files = dt.files;

                    document.getElementById('signatureForm').submit();
                }, 'image/png');
            });
        });
    }

});
</script>

<?php include '../../includes/footer.php'; ?>