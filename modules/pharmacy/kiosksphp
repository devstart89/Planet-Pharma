<?php
session_start();
require '../../config/db.php';
require '../../includes/pharmacy_helpers.php';

date_default_timezone_set('Asia/Manila');

define('LOGO_PLANET_RED', 'data:image/png;base64,PLACEHOLDER');
define('LOGO_MAKATI_SEAL', 'data:image/png;base64,PLACEHOLDER');
define('LOGO_MHD_SEAL', 'data:image/png;base64,PLACEHOLDER');

$branchSlug = $_GET['branch'] ?? $_POST['branch'] ?? '';
$pharmacy = resolvePharmacyBySlug($conn, $branchSlug);
if (!$pharmacy) {
    renderBranchPicker($conn, 'kiosk.php');
}
$pharmacyId = (int) $pharmacy['id'];

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

$mode = $_GET['mode'] ?? null;
$error = null;
$slip = null;

$showCategoryStep = false;
$categoryStepPrescriptionNumber = null;
$categoryStepPatientName = null;

if ($mode === 'epres' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['rx_no'])) {
    $rxNo = strtoupper(trim($_POST['rx_no']));
    $categoryChoice = $_POST['category'] ?? null;

    if ($rxNo === '') {
        $error = "Please enter a valid Prescription Number.";
    } else {
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
            $error = "This prescription was not transmitted to this pharmacy location. Please check the correct branch.";
        } elseif ($prescription['medicine_status'] === 'Dispensed') {
            $dispensedWhen = $prescription['dispensed_at']
                ? (new DateTime($prescription['dispensed_at']))->format('M j, Y g:i A')
                : null;
            $error = $dispensedWhen
                ? "This prescription was already dispensed on {$dispensedWhen}."
                : "This prescription has already been dispensed.";
        } elseif ($prescription['status'] !== 'Signed' || !$prescription['transmitted_at']) {
            $error = "This prescription is not yet signed and transmitted. Please check with your health facility.";
        } else {
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
                $showCategoryStep = true;
                $categoryStepPrescriptionNumber = $prescription['prescription_number'];
                $categoryStepPatientName = trim(($prescription['first_name'] ?? '') . ' ' . ($prescription['last_name'] ?? ''));
            }
        }
    }
}

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

    .kiosk-shell {
        width: 100%;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }
    @media (min-width: 900px) {
        .kiosk-shell { flex-direction: row; height: 100vh; }
    }

    .visual-panel {
        position: absolute;
        inset: 0;
        z-index: 0;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        overflow: hidden;
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
        display: flex; align-items: center; justify-content: center;
        color: var(--brand-red-2);
        font-size: clamp(.9rem, 3vw, 1.1rem);
        box-shadow: 0 2px 6px rgba(0,0,0,.12);
        flex-shrink: 0;
        overflow: hidden;
    }
    .brand-mark img { width: 95%; height: 95%; object-fit: contain; }
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
        box-shadow: 0 2px 6px rgba(0,0,0,.15);
    }
    @media (min-width: 900px) {
        .mobile-seal-strip { display: none; }
    }

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
    .main-content-inner { width: 100%; max-width: 420px; }

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

    /* ---------- Confirmation screen (on-screen) ---------- */
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
    #printTicket.show { opacity: 1; transform: translateY(0); }

    /*
     * ================= PRINT — kiosk-printer-agnostic =================
     * IMPORTANT: #printTicket is nested inside .main-content-inner >
     * .main-content > .content-panel > .kiosk-shell. Any ancestor with
     * display:none removes its descendants from rendering entirely —
     * visibility:visible on a child can't undo that. So those ancestors
     * must stay display:block (just reset to plain, unstyled block
     * boxes with no leftover height/padding); only the actual sibling
     * elements we don't want (branding panel, footer, loader, etc.) get
     * display:none.
     */
    @media print {
        /*
         * Continuous-roll thermal printers (e.g. Epson TM-m30) don't have
         * a fixed "page" the way a sheet-fed printer does — without a
         * size hint, some driver/browser combos fall back to a default
         * sheet size (Letter/A4) and feed out a long stretch of mostly
         * blank paper before cutting, since the page never naturally
         * ends. `size: 80mm auto` tells the browser the page is 80mm
         * wide with a height that hugs the actual content instead.
         * If your terminals use the 58mm variant of this printer,
         * change 80mm to 58mm below.
         */
        @page { size: 80mm auto; margin: 0; }
        html, body { width: 100%; height: auto; }
        body { margin: 0; background: #fff; }
        body * { visibility: hidden; }

        /* Hide everything that isn't the ticket. */
        .visual-panel, .footer-tag, .mobile-seal-strip, .mobile-bottom-brand,
        .brand, .slip-loader, #ticketActions { display: none !important; }

        /* Ancestors of #printTicket: keep rendered, strip all the
           on-screen flex/height/padding so they don't leave blank
           space or fight the print layout. */
        .kiosk-shell, .content-panel, .main-content, .main-content-inner {
            display: block !important;
            position: static !important;
            width: 100% !important;
            height: auto !important;
            min-height: 0 !important;
            max-width: none !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        #printTicket, #printTicket * {
            visibility: visible;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        #printTicket {
            display: block;
            position: static;
            width: 100%;
            margin: 0 auto;
            padding: 6px 4px 10px;
            text-align: center;
            opacity: 1 !important;
            transform: none !important;
            color: #000;
            font-family: Arial, Helvetica, sans-serif;
        }
        /* Fixed pt sizes replace every clamp()'d on-screen size, so the
           slip reads the same whether it comes out of a narrow thermal
           roll or a full sheet of paper — vw/clamp() resolve against
           the print page viewport, which varies wildly by printer. */
        #printTicket .category-badge {
            display: inline-block;
            background: none !important;
            border: 1.5pt solid #000;
            color: #000 !important;
            font-size: 9pt;
            font-weight: 700;
            padding: 2pt 8pt;
            border-radius: 3pt;
            margin-bottom: 6pt;
        }
        #printTicket p {
            color: #000 !important;
            font-size: 9pt;
            margin: 2pt 0;
        }
        #printTicket .queue-number {
            color: #000 !important;
            font-size: 30pt;
            font-weight: 800;
            line-height: 1.1;
            margin: 4pt 0 6pt;
        }
        #printTicket .confirm-wait {
            color: #000 !important;
            font-size: 10pt;
            font-weight: 700;
            margin-top: 6pt;
        }
        /* Belt-and-suspenders: if some browser/driver still tries to
           render an icon font glyph here, don't let a missing/odd
           character corrupt the layout. */
        #printTicket i, #printTicket .bi { display: none !important; }
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
            <h2>Skip the line.<br>Get your queue number in seconds.</h2>
            <p>Walk-in or use your E-Pres prescription number — either way, you'll be called when it's your turn.</p>
        </div>

        <div class="mobile-bottom-brand">
            <div class="footer-tag"><i class="bi bi-geo-alt-fill"></i>Planet Drugstore - <b class="text-dark"><?= htmlspecialchars($pharmacy['address']) ?></b></div>
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