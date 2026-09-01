<?php
session_start();
require '../../config/db.php';
require '../../includes/pharmacy_helpers.php';

/*
 * Every other date-displaying file in this app (generate_prescription_pdf.php,
 * view/prescription.php) explicitly sets this. This file never did, so
 * date()/DateTime here fell back to PHP's server-default timezone —
 * commonly UTC on shared hosting — which is 8 hours behind Manila. That's
 * exactly the discrepancy reported (queue slip showing 1:37pm when it was
 * actually 9:37pm local time).
 */
date_default_timezone_set('Asia/Manila');

/*
 * ================= BRAND ASSETS (embedded, no external files) =================
 * Kept as inline base64 so the kiosk page has zero dependency on an /assets
 * folder existing on whatever terminal/branch serves this page. Swap these
 * constants for real file paths later if you'd rather serve them as static
 * files (better for browser caching across kiosk sessions).
 */
define('LOGO_PLANET_RED', 'data:image/png;base64,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');
define('LOGO_MAKATI_SEAL', 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAKAAAACgCAMAAAC8EZcfAAAB/lBMVEWynx7t4Zbz3WZcVDVoZlynpZrioCby8vLj4tkgHyWqn2rX19ft7e0eXSJ7e3t/gIChaQ69vsFANyLFu4rAv8D9/f0AAAADAwP5txr5xhoVFxXo6Oj5qRnX19dHR0cnJye3t7fHx8dXV1dnZ2c3NzcYfSCXl5enp6eHh4d3d3j///8bhyP61hr75Rshhyj5mhkKKAwQNBR8fHypqakyJggPUxjm5ubJycnGxsYQRxjX19e1tbXV1dVxVQ6ZmZmtiBLb29tINw315ya+vr799yclGwWmpqapqamKior72CcdZSLQpxX98x04ODhWRA67u7sjlClYWFiMaxCOdhCxlhRKSkoXZh0heCaqeRLQtRf19fVmTA7GiRUXeh88MQhnZ2fNmRnm5uYekiZ7Zww5PEQhbCaFWQ+FhYXGxsbW1tb1ySb++E8bGxspKSnXyCno6OhWWmOSgxKdnZ3W1tb166z89s0YGSI+QURdUQxiXGJ6fYWzpzC3t7fXxRzGxbzOzs4AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABaQiv9AAAAgHRSTlP/////////d////yCf/yD////////+AP7///78//7+/v7+/v7+//7+/v4C/////////wkE///Tj3H/sVSV/y7/yv//Bf//OUcl/////wz/af8S////Df/////P/////xP/rf//////GVF0//8JCf8h//8bIf//////////M///rKpXiWoAAB6sSURBVHjaxV2Hf9vGkobTLslr9+6OAgkIWwAIhUWWZFmS1WVLshW598SO03t5qa+Xf/1mZgsWIChRdt4dfrZEUiTwYfrszgy9mRc+li70+/3Wv8DrF/oveHbvhT7d71/pW5hXN9bWvvgPPL5Yu7Vx+MMdi3Lp/wegodu1v69tfrASdppHuPLB6ueH76n3PjclvRdDd7i2uWLwRJHMMzryPJdRpCGvbG5svwDG5wNI11ra0OAiUSSMB37tCAKeJkUuCebK6sYDuqv/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');
define('LOGO_MHD_SEAL', 'data:image/png;base64,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');

/*
 * ================= MODULE 2: PUBLIC KIOSK (multi-branch) =================
 * Runs on the drugstore kiosk terminal — intentionally public, no staff
 * login. Two categories per the spec:
 *   - E-Pres Online: patient enters their Prescription Number
 *     (prescriptions.prescription_number, e.g. "RX-20260711-001").
 *   - Walk-in: no prescription lookup, patient picks Regular or Priority.
 * Both paths end in the same place: a row in `queues` and a printable
 * slip with Queue Number, Category, Date/Time, and Rx No. (if applicable).
 *
 * MULTI-PHARMACY: each physical kiosk terminal is bookmarked with its
 * own URL, e.g. kiosk.php?branch=downtown. Every query on this page is
 * scoped to that resolved pharmacy_id — queue numbering, E-Pres lookups,
 * and the inserted queue row all stay inside that one branch.
 */

$branchSlug = $_GET['branch'] ?? $_POST['branch'] ?? '';
$pharmacy = resolvePharmacyBySlug($conn, $branchSlug);
if (!$pharmacy) {
    renderBranchPicker($conn, 'kiosk.php');
}
$pharmacyId = (int) $pharmacy['id'];

// Every form/link on this page must keep the branch attached.
function withBranch(string $url, string $slug): string {
    $sep = str_contains($url, '?') ? '&' : '?';
    return $url . $sep . 'branch=' . urlencode($slug);
}

function nextQueueNumber(PDO $conn, int $pharmacyId, string $category): int {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt FROM queues
        WHERE pharmacy_id = ? AND category = ? AND DATE(created_at) = CURDATE()
    ");
    $stmt->execute([$pharmacyId, $category]);
    return ((int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt']) + 1;
}

function queueLabel(string $category, int $number): string {
    $prefix = $category === 'Priority' ? 'P' : 'R';
    return $prefix . str_pad((string)$number, 2, '0', STR_PAD_LEFT);
}

$mode = $_GET['mode'] ?? null;   // 'epres' | 'walkin'
$error = null;
$slip = null; // populated once a queue row is created

/* ---------- E-PRES ONLINE ---------- */
$showCategoryStep = false;
$categoryStepPrescriptionNumber = null;
$categoryStepPatientName = null;

if ($mode === 'epres' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['rx_no'])) {
    // Patients type the prescription_number printed/shown on their
    // e-prescription (e.g. "RX-20260711-001"), not any internal id.
    $rxNo = strtoupper(trim($_POST['rx_no']));
    // Only present on the 2nd submit, after the patient picks a category
    // on the step below — the first submit (just the Rx number) won't
    // have this yet.
    $categoryChoice = $_POST['category'] ?? null;

    if ($rxNo === '') {
        $error = "Please enter a valid Prescription Number.";
    } else {
        // Prescriptions don't carry a pharmacy_id of their own — their
        // branch comes from the health facility that created them
        // (prescriptions.facility_id -> health_facilities.pharmacy_id),
        // which is how routing already works in this system.
        $stmt = $conn->prepare("
            SELECT pr.id, pr.prescription_number, pr.status, pr.transmitted_at,
                   pr.medicine_status, pr.dispensed_at,
                   hf.pharmacy_id AS routed_pharmacy_id, pat.first_name, pat.last_name
            FROM prescriptions pr
            LEFT JOIN health_facilities hf ON pr.facility_id = hf.id
            LEFT JOIN patients pat ON pr.patient_id = pat.id
            WHERE pr.prescription_number = ?
        ");
        $stmt->execute([$rxNo]);
        $prescription = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prescription) {
            $error = "No prescription found for No. {$rxNo}.";
        } elseif ((int)($prescription['routed_pharmacy_id'] ?? 0) !== $pharmacyId) {
            // Prevents patients from queueing at the wrong branch for
            // a prescription whose health facility routes elsewhere.
            $error = "This prescription was not transmitted to this pharmacy location. Please check the correct branch.";
        } elseif ($prescription['medicine_status'] === 'Dispensed') {
            /*
             * BUGFIX: this used to also check
             * $prescription['status'] === 'Dispensed', which can NEVER
             * be true — prescriptions.status is
             * enum('For Signing','Signed','Denied'); there is no
             * 'Dispensed' value in that column. Only medicine_status
             * tracks dispensing (set by queue.php's markCompleted()
             * when pharmacy marks the queue entry Completed). That
             * dead condition looked like it was doing something but
             * never actually could — removed it so this block reflects
             * what's really being checked.
             *
             * Also now shows WHEN it was dispensed, since a bare
             * "already dispensed" message with no timestamp gives the
             * patient nothing to act on (e.g. to know if this is a
             * mix-up from moments ago vs. days ago).
             */
            $dispensedWhen = $prescription['dispensed_at']
                ? (new DateTime($prescription['dispensed_at']))->format('M j, Y g:i A')
                : null;
            $error = $dispensedWhen
                ? "This prescription was already dispensed on {$dispensedWhen}."
                : "This prescription has already been dispensed.";
        } elseif ($prescription['status'] !== 'Signed' || !$prescription['transmitted_at']) {
            $error = "This prescription is not yet signed and transmitted. Please check with your health facility.";
        } else {
            // Already has an active (not-Completed/Unclaimed) queue entry
            // today at this branch? Reuse it as-is — its category was
            // already decided when it was first created, so there's
            // nothing new to ask.
            //
            // NOTE: 'Unclaimed' is referenced here as a queue status, but
            // the queues table's status column is currently
            // enum('Waiting','Now Serving','Completed') — 'Unclaimed'
            // isn't actually a valid value yet. This exclusion is
            // harmless as written (a queue row can never equal a value
            // outside its own enum, so it just never matches), but it
            // means "unclaimed" queue entries have no way to exist in
            // the data yet. If you want that as a real state — e.g. to
            // distinguish "we called them and marked it Completed" from
            // "we called them and they never showed up" — the queues
            // enum needs 'Unclaimed' added, plus a corresponding action
            // in queue.php. Flagging this rather than changing the
            // schema silently, since it's a bigger decision than this
            // specific fix.
            $stmt = $conn->prepare("
                SELECT * FROM queues
                WHERE pharmacy_id = ? AND prescription_id = ? AND status NOT IN ('Completed', 'Unclaimed') AND DATE(created_at) = CURDATE()
                LIMIT 1
            ");
            $stmt->execute([$pharmacyId, $prescription['id']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $slip = $existing;
                $slip['prescription_number'] = $prescription['prescription_number'];
            } elseif ($categoryChoice === 'Regular' || $categoryChoice === 'Priority') {
                // Step 2 submit — category has been chosen, create the entry.
                $category = $categoryChoice;
                $number = nextQueueNumber($conn, $pharmacyId, $category);
                $stmt = $conn->prepare("
                    INSERT INTO queues (pharmacy_id, prescription_id, source, category, queue_number, status)
                    VALUES (?, ?, 'E-Pres', ?, ?, 'Waiting')
                ");
                $stmt->execute([$pharmacyId, $prescription['id'], $category, $number]);

                $stmt = $conn->prepare("SELECT * FROM queues WHERE id = ?");
                $stmt->execute([$conn->lastInsertId()]);
                $slip = $stmt->fetch(PDO::FETCH_ASSOC);
                $slip['prescription_number'] = $prescription['prescription_number'];
            } else {
                // Step 1 submit — prescription is valid and has no active
                // queue yet, so ask Regular vs Priority before creating
                // anything.
                $showCategoryStep = true;
                $categoryStepPrescriptionNumber = $prescription['prescription_number'];
                $categoryStepPatientName = trim(($prescription['first_name'] ?? '') . ' ' . ($prescription['last_name'] ?? ''));
            }
        }
    }
}

/* ---------- WALK-IN ---------- */
if ($mode === 'walkin' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['category'])) {
    $category = $_POST['category'] === 'Priority' ? 'Priority' : 'Regular';
    $walkInName = trim($_POST['walk_in_name'] ?? '');

    $number = nextQueueNumber($conn, $pharmacyId, $category);
    $stmt = $conn->prepare("
        INSERT INTO queues (pharmacy_id, prescription_id, walk_in_name, source, category, queue_number, status)
        VALUES (?, NULL, ?, 'Walk-in', ?, ?, 'Waiting')
    ");
    $stmt->execute([$pharmacyId, $walkInName ?: null, $category, $number]);

    $stmt = $conn->prepare("SELECT * FROM queues WHERE id = ?");
    $stmt->execute([$conn->lastInsertId()]);
    $slip = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Bare landing state — nothing chosen yet — is the only screen that gets
// the "tap to start" cover screen in front of it.
$isLandingState = !$mode && !$showCategoryStep && !$slip;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($pharmacy['pharmacy_name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
    :root {
        --brand-navy: #1d2939;
        --brand-gray: #667085;
        --brand-red-1: #ff6b63;
        --brand-red-2: #ef4444;
        --brand-blue-bg: #eaf1ff;
        --brand-blue: #175cd3;
    }
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body {
        margin: 0;
        min-height: 100vh;
        background: #fff;
        font-family: 'Segoe UI', Arial, sans-serif;
    }

    /*
     * Full-bleed at every size — no gray letterboxed "device mockup"
     * look. Below 900px width this is a single stacked column (phone /
     * tablet portrait, matches the kiosk mockup). At 900px+ it switches
     * to a two-pane layout: a fixed branding sidebar plus a content pane
     * that actually uses the extra horizontal space instead of floating
     * a narrow card in the middle of the screen.
     */
    .kiosk-shell {
        width: 100%;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }
    @media (min-width: 900px) {
        .kiosk-shell { flex-direction: row; height: 100vh; }
    }

    /* ---------- Visual / branding panel ---------- */
    .visual-panel {
        position: relative;
        overflow: hidden;
    }
    /* Mobile/tablet: overlay behind the content, just top+bottom diagonal
       strips like the original mockup. Flex column + space-between keeps
       the brand row pinned to the top and the seal/footer cluster pinned
       to the bottom, instead of everything stacking near the top in
       plain document flow. */
    .visual-panel {
        position: absolute;
        inset: 0;
        z-index: 0;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .diag {
        position: absolute;
        left: 0; right: 0;
        height: 32%;
        background: linear-gradient(135deg, var(--brand-red-1), var(--brand-red-2));
    }
    .diag-top {
        top: 0;
        clip-path: polygon(0 0, 100% 0, 100% 55%, 0 100%);
    }
    .diag-bottom {
        bottom: 0;
        clip-path: polygon(0 45%, 100% 0, 100% 100%, 0 100%);
        opacity: .55;
    }
    .visual-copy { display: none; }

    @media (min-width: 900px) {
        /* Desktop / large tablet landscape: the branding becomes a real
           sidebar column instead of an absolute overlay, so it takes up
           genuine page width rather than framing a small floating card. */
        .visual-panel {
            position: relative;
            inset: auto;
            flex: 0 0 clamp(300px, 34vw, 440px);
            height: 100%;
            background: linear-gradient(160deg, var(--brand-red-1), var(--brand-red-2));
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .diag { display: none; }
        .visual-copy {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 1rem;
            padding: 0 3rem;
            color: #fff;
        }
        .visual-copy i { font-size: 2.5rem; }
        .visual-copy h2 {
            font-weight: 800;
            font-size: clamp(1.5rem, 2.6vw, 2.1rem);
            line-height: 1.25;
            margin: 0;
        }
        .visual-copy p {
            font-size: 1rem;
            color: rgba(255,255,255,.9);
            margin: 0;
            max-width: 32ch;
        }
    }

    .brand {
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        gap: .6rem;
        padding: clamp(1rem, 4vw, 1.5rem) clamp(1rem, 4vw, 1.5rem) 0;
    }
    @media (min-width: 900px) {
        .brand { padding: 2.5rem 3rem 0; }
    }
    .brand-mark {
        width: clamp(30px, 6vw, 36px); height: clamp(30px, 6vw, 36px);
        /*border-radius: 50%;*/
        /*background: #fff;*/
        display: flex; align-items: center; justify-content: center;
        color: var(--brand-red-2);
        font-size: clamp(.9rem, 3vw, 1.1rem);
        box-shadow: 0 2px 6px rgba(0,0,0,.12);
        flex-shrink: 0;
        overflow: hidden;
    }
    .brand-mark img {
        width: 95%;
        height: 95%;
        object-fit: contain;
    }
    .brand-name {
        font-weight: 800;
        font-size: clamp(.8rem, 2.5vw, .95rem);
        letter-spacing: .04em;
        text-transform: uppercase;
        color: #fff;
        line-height: 1.1;
    }
    .brand-sub {
        font-size: clamp(.6rem, 2vw, .7rem);
        color: rgba(255,255,255,.85);
        font-weight: 500;
    }
    .footer-tag {
        position: relative;
        z-index: 2;
        text-align: right;
        padding: 0 clamp(1rem, 4vw, 1.5rem) clamp(.9rem, 3vw, 1.25rem);
        font-size: clamp(.58rem, 1.8vw, .65rem);
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: rgba(255,255,255,.9);
    }
    @media (min-width: 900px) {
        .footer-tag { text-align: left; padding: 0 3rem 2.5rem; }
    }
    .footer-tag i { margin-right: .3rem; }

    /*
     * ---------- Mobile-only accreditation strip ----------
     * The kiosk mockup (portrait / non-desktop) shows a row of
     * partner/authority seals above the footer tag: City of Makati,
     * Planet Drugstore, and the Makati Health Department. This is a
     * mobile/tablet-portrait affordance only — the desktop sidebar
     * layout already has room for the full wordmark and copy, so this
     * strip is hidden at 900px+ to avoid duplicating the branding.
     */
    .mobile-seal-strip {
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: clamp(.6rem, 3vw, 1rem);
        padding: 0 clamp(1rem, 4vw, 1.5rem) .6rem;
    }
    .mobile-seal-strip img {
        height: clamp(28px, 8vw, 38px);
        width: clamp(28px, 8vw, 38px);
        object-fit: contain;
        border-radius: 50%;
        /*background: #fff;*/
        /*padding: 3px;*/
        box-shadow: 0 2px 6px rgba(0,0,0,.15);
    }
    @media (min-width: 900px) {
        .mobile-seal-strip { display: none; }
    }

    /* ---------- Content panel ---------- */
    .content-panel {
        position: relative;
        z-index: 1;
        flex: 1;
        display: flex;
        min-height: 100vh;
    }
    @media (min-width: 900px) {
        .content-panel { min-height: auto; overflow-y: auto; }
    }
    .main-content {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: clamp(1.25rem, 5vw, 2rem) clamp(1.25rem, 6vw, 2.5rem);
        text-align: center;
        width: 100%;
    }
    .main-content-inner {
        width: 100%;
        max-width: 420px;
    }

    /*
     * ---------- Tap-to-start prompt ----------
     * Intentionally NOT an opaque full-screen overlay: it sits inline in
     * main-content-inner, same as every other screen, so the diagonal
     * graphic, top logo, and bottom seal strip stay visible behind it
     * (matching the reference mockup, where the red diagonals and the
     * "PLANET DRUGSTORE · CERTIFIED PHARMACY" tag show through even on
     * the very first tap screen).
     */
    .tap-screen {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        cursor: pointer;
        min-height: 40vh;
    }
    .tap-greeting {
        color: var(--brand-gray);
        font-size: clamp(.8rem, 2.5vw, .95rem);
        margin-bottom: .5rem;
    }
    .tap-cta {
        font-weight: 800;
        font-size: clamp(1.6rem, 5vw, 2.4rem);
        line-height: 1.2;
        color: var(--brand-navy);
        letter-spacing: .02em;
    }
    .tap-pulse {
        margin-top: 1.5rem;
        width: 14px; height: 14px;
        border-radius: 50%;
        background: var(--brand-red-2);
        animation: pulse 1.6s ease-in-out infinite;
    }
    @keyframes pulse {
        0%   { transform: scale(1);   opacity: 1; }
        70%  { transform: scale(2.2); opacity: 0; }
        100% { transform: scale(2.2); opacity: 0; }
    }

    /* ---------- Option / category tiles ---------- */
    .option-heading {
        font-weight: 700;
        font-size: clamp(1rem, 2.4vw, 1.2rem);
        color: var(--brand-navy);
        margin-bottom: 1.5rem;
    }
    .option-heading .accent { color: var(--brand-blue); }
    .kiosk-option {
        width: 100%;
        border: none;
        background: var(--brand-blue-bg);
        border-radius: 1rem;
        padding: clamp(.9rem, 4vw, 1.1rem) clamp(1rem, 4vw, 1.25rem);
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 1rem;
        color: var(--brand-navy);
        text-align: left;
        transition: transform .12s ease;
    }
    .kiosk-option:hover { transform: translateY(-2px); color: var(--brand-navy); }
    .kiosk-option i {
        font-size: clamp(1.25rem, 5vw, 1.5rem);
        color: var(--brand-blue);
        background: #fff;
        width: clamp(38px, 10vw, 46px); height: clamp(38px, 10vw, 46px);
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .kiosk-option strong { display: block; font-size: clamp(.9rem, 3.5vw, 1rem); }
    .kiosk-option .small { color: var(--brand-gray); font-size: clamp(.75rem, 3vw, .85rem); }

    .category-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 1rem;
        width: 100%;
    }
    .category-tile {
        border: none;
        background: var(--brand-blue-bg);
        border-radius: 1rem;
        padding: clamp(1.1rem, 5vw, 1.5rem) 1rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: .6rem;
        color: var(--brand-navy);
        font-weight: 700;
        text-transform: uppercase;
        font-size: clamp(.78rem, 3vw, .85rem);
        letter-spacing: .04em;
        transition: transform .12s ease;
    }
    .category-tile:hover { transform: translateY(-2px); color: var(--brand-navy); }
    .category-tile i {
        font-size: clamp(1.3rem, 5vw, 1.6rem);
        color: var(--brand-blue);
        background: #fff;
        width: clamp(44px, 12vw, 52px); height: clamp(44px, 12vw, 52px);
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
    }
    .category-tile.priority i { color: var(--brand-red-2); }
    .category-tile .caption {
        font-weight: 500;
        text-transform: none;
        font-size: clamp(.66rem, 2.5vw, .7rem);
        color: var(--brand-gray);
        letter-spacing: 0;
    }

    .rx-context {
        color: var(--brand-gray);
        font-size: clamp(.8rem, 3vw, .85rem);
        margin-bottom: 1.5rem;
    }
    .rx-context strong { color: var(--brand-navy); }

    .form-control-lg { border-radius: .75rem; }
    .btn-kiosk-primary {
        background: var(--brand-navy);
        color: #fff;
        border: none;
        border-radius: .75rem;
        font-weight: 700;
        padding: .8rem;
    }
    .btn-kiosk-link { color: var(--brand-gray); font-size: .85rem; }

    /* ---------- Confirmation screen ---------- */
    .confirm-check {
        width: clamp(56px, 14vw, 72px); height: clamp(56px, 14vw, 72px);
        border-radius: 50%;
        background: var(--brand-blue-bg);
        color: var(--brand-blue);
        font-size: clamp(1.6rem, 6vw, 2.2rem);
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 1rem;
    }
    .category-badge {
        display: inline-block; font-weight: 700; font-size: .78rem;
        text-transform: uppercase; letter-spacing: .04em;
        padding: .3rem .7rem; border-radius: .4rem; margin-bottom: .75rem;
    }
    .category-badge.Regular  { background: var(--brand-blue-bg); color: var(--brand-blue); }
    .category-badge.Priority { background: #fef3f2; color: #b42318; }
    .queue-number {
        font-size: clamp(2.4rem, 8vw, 3.6rem);
        font-weight: 800;
        letter-spacing: .04em;
        color: var(--brand-navy);
        line-height: 1;
        margin-bottom: .5rem;
    }
    .confirm-wait {
        font-weight: 700;
        color: var(--brand-navy);
        margin-top: .5rem;
        font-size: clamp(.85rem, 3vw, 1rem);
    }

    /*
     * ---------- Loading state for the confirmation screen ----------
     * Right after the kiosk successfully creates a queue row, the patient
     * gets a brief "getting your number" beat instead of the slip just
     * snapping into place. #slipLoader shows first; once the timer in
     * the inline script below fires, it's swapped out for the real
     * ticket, which fades/slides in, then the action buttons appear.
     */
    .slip-loader {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 1.1rem;
        padding: 1.5rem 0;
    }
    .slip-spinner {
        width: clamp(52px, 13vw, 64px);
        height: clamp(52px, 13vw, 64px);
        border-radius: 50%;
        border: 4px solid var(--brand-blue-bg);
        border-top-color: var(--brand-blue);
        animation: slip-spin .8s linear infinite;
    }
    @keyframes slip-spin { to { transform: rotate(360deg); } }
    .slip-loader p {
        color: var(--brand-gray);
        font-weight: 600;
        font-size: clamp(.82rem, 3vw, .92rem);
        margin: 0;
    }
    #printTicket {
        opacity: 0;
        transform: translateY(10px);
        transition: opacity .45s ease, transform .45s ease;
    }
    #printTicket.show {
        opacity: 1;
        transform: translateY(0);
    }

    @media print {
        body { background: #fff; }
        body * { visibility: hidden; }
        .visual-panel, .footer-tag, .mobile-seal-strip, .mobile-bottom-brand, .brand, .slip-loader { display: none !important; }
        #printTicket, #printTicket * { visibility: visible; }
        #printTicket { position: absolute; top: 0; left: 0; width: 100%; opacity: 1; transform: none; }
    }
</style>
</head>
<body>
<div class="kiosk-shell">
    <div class="visual-panel">
        <div class="diag diag-top"></div>
        <div class="diag diag-bottom"></div>

        <div class="brand">
            <div class="brand-mark"><img src="../logo/PLANETLIGHT.png" alt="<?= htmlspecialchars($pharmacy['pharmacy_name']) ?> logo"></div>
            <div>
                <div class="brand-name">Planet Drugstore</div>
                <div class="brand-sub"><i>Caring Beyond Dispensing</i></div>
            </div>
        </div>

        <div class="visual-copy">
            <!--<i class="bi bi-prescription2"></i>-->
            <h2>Skip the line.<br>Get your queue number in seconds.</h2>
            <p>Walk-in or use your E-Pres prescription number — either way, you'll be called when it's your turn.</p>
        </div>

        <div class="mobile-bottom-brand">
            <!--<div class="mobile-seal-strip">-->
            <!--    <img src="<?= LOGO_MAKATI_SEAL ?>" alt="City Government of Makati">-->
            <!--    <img src="<?= LOGO_PLANET_RED ?>" alt="Planet Drugstore">-->
            <!--    <img src="<?= LOGO_MHD_SEAL ?>" alt="Makati Health Department">-->
            <!--</div>-->

            <div class="footer-tag"><i class="bi bi-geo-alt-fill" ></i>Planet Drugstore - <b class="text-dark"><?= htmlspecialchars($pharmacy['address']) ?></b></div>
        </div>
    </div>

    <div class="content-panel">

        <div class="main-content" id="mainContent">
        <div class="main-content-inner">

        <?php if ($isLandingState): ?>
            <div class="tap-screen" id="tapScreen" onclick="document.getElementById('tapScreen').style.display='none'; document.getElementById('landingOptions').style.display='block';">
                <p class="tap-greeting">Good day! Welcome to <?= htmlspecialchars($pharmacy['pharmacy_name']) ?></p>
                <h1 class="tap-cta">TAP TO<br>GET NUMBER</h1>
                <div class="tap-pulse"></div>
            </div>
            <div id="landingOptions" style="display:none;">
                <h4 class="option-heading">Please select your <span class="accent">queuing</span> option.</h4>
                <div class="d-grid gap-3 w-100">
                    <a href="<?= htmlspecialchars(withBranch('kiosk.php?mode=walkin', $branchSlug)) ?>" class="kiosk-option">
                        <i class="bi bi-person-walking"></i>
                        <div>
                            <strong>Walk-in</strong>
                            <div class="small">No prescription was processed manually</div>
                        </div>
                    </a>
                    <a href="<?= htmlspecialchars(withBranch('kiosk.php?mode=epres', $branchSlug)) ?>" class="kiosk-option">
                        <i class="bi bi-cloud-check"></i>
                        <div>
                            <strong>E-Pres Online</strong>
                            <div class="small">I have a prescription number from the E-Pres app</div>
                        </div>
                    </a>
                </div>
            </div>

        <?php elseif ($slip): ?>
            <div class="slip-loader" id="slipLoader">
                <div class="slip-spinner"></div>
                <p>Getting your queue number&hellip;</p>
            </div>
            <div id="printTicket">
                <div class="confirm-check"><i class="bi bi-check-lg"></i></div>
                <span class="category-badge <?= htmlspecialchars($slip['category']) ?>"><?= htmlspecialchars($slip['category']) ?></span>
                <p class="text-muted mb-1">Your Queue Number</p>
                <div class="queue-number"><?= htmlspecialchars(queueLabel($slip['category'], (int)$slip['queue_number'])) ?></div>
                <?php if ($slip['prescription_id']): ?>
                    <p class="text-muted mb-0">Prescription No. <?= htmlspecialchars($slip['prescription_number']) ?></p>
                <?php endif; ?>
                <p class="text-muted small mb-0"><?= date('M d, Y h:i A') ?></p>
                <p class="confirm-wait">Please wait for your number to be called.</p>
            </div>
            <div class="d-grid gap-2 mt-3 w-100" id="ticketActions" style="display:none;">
                <button class="btn btn-kiosk-primary" onclick="window.print()">
                <i class="bi bi-printer"></i> Print Queue Slip
                </button>
                <a href="<?= htmlspecialchars(withBranch('kiosk.php', $branchSlug)) ?>" class="btn btn-outline-secondary">Done</a>
                <p class="text-muted small mb-0" id="autoReturnNote"></p>
            </div>
            <script>
                // Brief "generating" beat before the actual queue number is
                // revealed, then fade the ticket + action buttons in. Once
                // revealed, a countdown starts that automatically sends the
                // kiosk back to the tap-to-start screen for the next
                // patient — no need to wait for a manual "Done" tap.
                (function () {
                    var loader = document.getElementById('slipLoader');
                    var ticket = document.getElementById('printTicket');
                    var actions = document.getElementById('ticketActions');
                    var note = document.getElementById('autoReturnNote');
                    var homeUrl = <?= json_encode(withBranch('kiosk.php', $branchSlug), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

                    setTimeout(function () {
                        if (loader) loader.style.display = 'none';
                        if (ticket) ticket.classList.add('show');
                        if (actions) actions.style.display = 'grid';

                        var secondsLeft = 12;
                        function tick() {
                            if (note) note.textContent = 'Returning to home screen in ' + secondsLeft + 's';
                            if (secondsLeft <= 0) {
                                window.location.href = homeUrl;
                                return;
                            }
                            secondsLeft--;
                            setTimeout(tick, 1000);
                        }
                        tick();
                    }, 1100);
                })();
            </script>

        <?php elseif ($showCategoryStep): ?>
            <h4 class="option-heading"><i class="bi bi-prescription2"></i> Prescription Found</h4>
            <p class="rx-context">
                <strong><?= htmlspecialchars($categoryStepPrescriptionNumber) ?></strong>
                <?= $categoryStepPatientName ? ' — ' . htmlspecialchars($categoryStepPatientName) : '' ?>
                <br>Select your queue category to get your number.
            </p>
            <form method="post" action="<?= htmlspecialchars(withBranch('kiosk.php?mode=epres', $branchSlug)) ?>" class="w-100">
                <input type="hidden" name="rx_no" value="<?= htmlspecialchars($categoryStepPrescriptionNumber) ?>">
                <div class="category-grid mb-3">
                    <button type="submit" name="category" value="Regular" class="category-tile">
                        <i class="bi bi-people"></i> Regular
                        <span class="caption">General queue</span>
                    </button>
                    <button type="submit" name="category" value="Priority" class="category-tile priority">
                        <i class="bi bi-star"></i> Priority
                        <span class="caption">Senior / PWD / Pregnant</span>
                    </button>
                </div>
                <a href="<?= htmlspecialchars(withBranch('kiosk.php?mode=epres', $branchSlug)) ?>" class="btn btn-kiosk-link btn-sm">Start Over</a>
            </form>

        <?php elseif ($mode === 'epres'): ?>
            <h4 class="option-heading"><i class="bi bi-prescription2"></i> Please select your <span class="accent">queuing</span> option.</h4>
            <p class="text-muted">Enter your Prescription Number to get your queue number.</p>
            <?php if ($error): ?><div class="alert alert-danger py-2 w-100"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post" action="<?= htmlspecialchars(withBranch('kiosk.php?mode=epres', $branchSlug)) ?>" class="d-grid gap-2 w-100">
                <input type="text" name="rx_no" class="form-control form-control-lg text-center text-uppercase"
                       placeholder="e.g. RX-20260711-001" autofocus required>
                <button type="submit" class="btn btn-kiosk-primary">Get Queue Number</button>
                <a href="<?= htmlspecialchars(withBranch('kiosk.php', $branchSlug)) ?>" class="btn btn-kiosk-link btn-sm">Back</a>
            </form>

        <?php elseif ($mode === 'walkin'): ?>
            <h4 class="option-heading">Please select your <span class="accent">queuing</span> option.</h4>
            <?php if ($error): ?><div class="alert alert-danger py-2 w-100"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post" action="<?= htmlspecialchars(withBranch('kiosk.php?mode=walkin', $branchSlug)) ?>" class="w-100">
                <input type="text" name="walk_in_name" class="form-control text-center mb-3" placeholder="Name (optional)">
                <div class="category-grid">
                    <button type="submit" name="category" value="Regular" class="category-tile">
                        <i class="bi bi-people"></i> Regular
                        <span class="caption">General queue</span>
                    </button>
                    <button type="submit" name="category" value="Priority" class="category-tile priority">
                        <i class="bi bi-star"></i> Priority
                        <span class="caption">Senior / PWD / Pregnant</span>
                    </button>
                </div>
                <a href="<?= htmlspecialchars(withBranch('kiosk.php', $branchSlug)) ?>" class="btn btn-kiosk-link btn-sm mt-3">Back</a>
            </form>

        <?php endif; ?>

        </div>
        </div>

    </div>
</div>
</body>
</html>