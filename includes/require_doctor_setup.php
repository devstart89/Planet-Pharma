<?php
/**
 * Doctor first-login setup guard.
 *
 * Include this AFTER session_start(), the DB connection, and the
 * "must be logged in" check, on every protected page a doctor could
 * reach directly by URL (dashboard, prescriptions, patient records,
 * etc.) — EXCEPT the account settings page itself and logout, or
 * you'll get a redirect loop.
 *
 * Why a guard at all, and not just the login redirect: a doctor could
 * already have an open session from before their account was flagged
 * for setup, or could simply type/bookmark a dashboard URL directly
 * and skip the login redirect entirely. This re-checks on every
 * protected page load, and reads the DB fresh rather than trusting
 * session state, since profile/password/signature changes happen on
 * a different request than the one currently loading.
 *
 * CONFIGURE THIS: DOCTOR_SETUP_ACCOUNT_URL and the entries in
 * DOCTOR_SETUP_EXEMPT_SCRIPTS use web-root-absolute paths (starting
 * with /) rather than relative ones. This guard gets included from
 * pages at many different directory depths (dashboard, prescriptions,
 * etc.), so a relative path would resolve differently depending on
 * where it's included from — absolute paths sidestep that entirely.
 * Adjust below if the app isn't served from the domain root.
 */

require_once __DIR__ . '/doctor_setup_helpers.php';

// Where to send an incomplete doctor.
const DOCTOR_SETUP_ACCOUNT_URL = '/pages/profile/index.php?setup=1';

// Script paths (matched against the END of $_SERVER['SCRIPT_NAME'],
// which is always web-root-absolute) that should never be redirected,
// even for an incomplete doctor.
const DOCTOR_SETUP_EXEMPT_SCRIPTS = [
    '/pages/profile/index.php',    // the account settings page itself
    '/controller/login/login.php', // the login POST endpoint
    '/public/logout.php',          // logout, per header.php's link
];

if (!empty($_SESSION['user']) && normalizeRole($_SESSION['user']['role']) === 'doctor') {

    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $isExempt = false;
    foreach (DOCTOR_SETUP_EXEMPT_SCRIPTS as $exemptSuffix) {
        if (str_ends_with($scriptPath, $exemptSuffix)) {
            $isExempt = true;
            break;
        }
    }

    if (!$isExempt) {
        try {
            $row = fetchDoctorSetupRow($conn, (int) $_SESSION['user']['id']);

            if ($row && !isDoctorSetupComplete($row, (bool) $row['must_change_password'])) {
                header('Location: ' . DOCTOR_SETUP_ACCOUNT_URL);
                exit;
            }
        } catch (PDOException $e) {
            // Most likely cause: must_change_password migration hasn't
            // been run yet. Fail OPEN (let the request through) rather
            // than locking every doctor out of the whole system because
            // of a missing column — but log it loudly so it gets fixed.
            error_log("doctor setup guard: completeness check failed — " . $e->getMessage());
        }
    }
}