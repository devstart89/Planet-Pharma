<?php
session_start();
require '../../config/db.php';
require '../../includes/pharmacy_helpers.php';
require '../kiosk_logic.php';

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

/*
 * printer_settings.php now owns the full printer config shape (see that
 * file for the authoritative field list per connection type). This page
 * only needs isConfigured (to decide whether to try direct printing at
 * all) plus the raw config blob, which it passes straight through to the
 * client untouched -- see printerConfig in the script below.
 */
$printer = kioskPrinterConfig($conn, $pharmacyId);
$printerSettingsUrl = 'printer_settings.php';

/*
 * ================= FIRST-LOAD STATE ONLY =================
 * Everything below determines what to show on THIS load only — a
 * fresh visit, a bookmarked/QR ?mode=... deep link, or a manual
 * refresh. Every screen transition AFTER this load runs through
 * kiosk_api.php + JS instead (see the script at the bottom), so a real
 * page navigation — which silently drops browser Fullscreen with no
 * way for JS to prevent it — never happens again during a session.
 * PHP's job here is reduced to "figure out which screen to render
 * first, and with what data" — the actual markup for every screen
 * lives once, in the JS render functions below, not duplicated here.
 */
$mode = $_GET['mode'] ?? null;
$initialError = null;
$initialSlipPayload = null;
$initialCategoryStep = null; // ['rxNo' => ..., 'patientName' => ...]

if ($mode === 'epres' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['rx_no'])) {
    $result = kioskValidateRx($conn, $pharmacyId, $_POST['rx_no']);
    if (!$result['ok']) {
        $initialError = $result['error'];
    } else {
        $prescription = $result['prescription'];
        $existing = kioskExistingQueueForPrescription($conn, $pharmacyId, (int) $prescription['id']);
        $categoryChoice = $_POST['category'] ?? null;

        if ($existing) {
            $initialSlipPayload = kioskTicketPayload($pharmacy, $existing, $prescription['prescription_number']);
        } elseif ($categoryChoice === 'Regular' || $categoryChoice === 'Priority') {
            $row = kioskCreateQueueEntry($conn, $pharmacyId, $categoryChoice, 'E-Pres', (int) $prescription['id']);
            $initialSlipPayload = kioskTicketPayload($pharmacy, $row, $prescription['prescription_number']);
        } else {
            $initialCategoryStep = [
                'rxNo' => $prescription['prescription_number'],
                'patientName' => trim(($prescription['first_name'] ?? '') . ' ' . ($prescription['last_name'] ?? '')),
            ];
        }
    }
}

if ($mode === 'walkin' && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['category'])) {
    $category = $_POST['category'] === 'Priority' ? 'Priority' : 'Regular';
    $walkInName = trim($_POST['walk_in_name'] ?? '');
    $row = kioskCreateQueueEntry($conn, $pharmacyId, $category, 'Walk-in', null, $walkInName !== '' ? $walkInName : null);
    $initialSlipPayload = kioskTicketPayload($pharmacy, $row);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($pharmacy['pharmacy_name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="../kiosk_common.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="manifest" href="kiosk_manifest.php?branch=<?= urlencode($branchSlug) ?>">
<meta name="theme-color" content="#1d2939">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($pharmacy['pharmacy_name']) ?>">
<link rel="apple-touch-icon" href="../logo/PLANETLIGHT.png">
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
        cursor: pointer;
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
        cursor: pointer;
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
    .ticket-actions-grid {
        display: grid;
        gap: .5rem;
        margin-top: 1rem;
        width: 100%;
    }
    .btn-kiosk-link { color: var(--brand-gray); font-size: .85rem; background: none; border: none; cursor: pointer; }

    .ticket-branch {
        font-weight: 800;
        font-size: clamp(.85rem, 3vw, .95rem);
        letter-spacing: .01em;
        color: var(--brand-navy);
        margin-bottom: .6rem;
        padding-bottom: .6rem;
        border-bottom: 1px dashed #d0d5dd;
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

    .printer-setup-overlay {
        position: fixed;
        inset: 0;
        z-index: 50;
        background: rgba(29, 41, 57, 0.55);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1.25rem;
    }
    .printer-setup-card {
        background: #fff;
        border-radius: 1rem;
        max-width: 380px;
        width: 100%;
        padding: 1.75rem 1.5rem;
        text-align: center;
        box-shadow: 0 20px 40px rgba(0,0,0,.25);
        position: relative;
    }
    .printer-setup-close {
        position: absolute;
        top: .6rem; right: .75rem;
        border: none;
        background: transparent;
        color: var(--brand-gray);
        font-size: 1.4rem;
        line-height: 1;
        padding: .25rem .5rem;
        cursor: pointer;
    }
    .printer-setup-close:hover { color: var(--brand-navy); }
    .printer-setup-icon {
        width: 56px; height: 56px;
        margin: 0 auto .9rem;
        border-radius: 50%;
        background: var(--brand-blue-bg);
        color: var(--brand-blue);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.5rem;
    }
    .printer-setup-card h5 {
        font-weight: 800;
        color: var(--brand-navy);
        margin-bottom: .5rem;
    }
    .printer-setup-card p {
        color: var(--brand-gray);
        font-size: .88rem;
        margin-bottom: 1.5rem;
    }
    .printer-setup-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: .6rem;
    }
    .printer-setup-actions .btn { border-radius: .65rem; font-weight: 700; font-size: .85rem; }

    #printFallbackNotice { display: none; }

    @media print {
        @page { size: <?= $printer['paperWidth'] ?> auto; margin: 0; }
        html, body { width: 100%; height: auto; }
        body { margin: 0; background: #fff; }
        body * { visibility: hidden; }

        .visual-panel, .footer-tag, .mobile-seal-strip, .mobile-bottom-brand,
        .brand, .slip-loader, #ticketActions, #printSuccessScreen, .printer-setup-overlay { display: none !important; }

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

        /*
         * The fallback notice used to be forced visible/block
         * unconditionally, which meant it printed EVERY time -- even
         * when a real ticket was on screen -- because #printTicket's
         * `display: block` below had no !important and so lost to the
         * inline `display:none` that startPrintingUI() sets on it right
         * before window.print() runs. Two fixes, both required:
         *   1. #printTicket's print display is now !important, so it
         *      always wins over that inline style.
         *   2. The fallback notice is now scoped to "no ticket in the
         *      DOM at all" via :has(), instead of being unconditional,
         *      so it only appears on the landing/walk-in/e-pres screens
         *      where there genuinely is nothing to print.
         */
        #printFallbackNotice, #printFallbackNotice * {
            visibility: hidden;
        }
        #mainContent:not(:has(#printTicket)) #printFallbackNotice,
        #mainContent:not(:has(#printTicket)) #printFallbackNotice * {
            visibility: visible !important;
        }
        #mainContent:not(:has(#printTicket)) #printFallbackNotice {
            display: block !important;
            position: static !important;
            width: 100%;
            padding: 20px 14px;
            text-align: center;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11pt;
            color: #000 !important;
        }

        #printTicket, #printTicket * {
            visibility: visible;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        #printTicket {
            display: block !important;
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
        #printTicket .ticket-branch {
            color: #000 !important;
            font-size: 11pt;
            font-weight: 800;
            margin-bottom: 6pt;
            padding-bottom: 6pt;
            border-bottom: 1pt dashed #000;
        }
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
        #printTicket i, #printTicket .bi { display: none !important; }
    }
</style>
</head>
<body>
<script src="../kiosk_common.js"></script>
<script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('kiosk_sw.js').catch(function () {});
    }
</script>
<div class="kiosk-shell">
    <div class="visual-panel">
        <div class="diag diag-top"></div>
        <div class="diag diag-bottom"></div>

        <div class="brand kiosk-safe-area-top">
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

        <div class="mobile-bottom-brand kiosk-safe-area-bottom">
            <div class="footer-tag"><i class="bi bi-geo-alt-fill"></i>Planet Drugstore - <b class="text-dark"><?= htmlspecialchars($pharmacy['address']) ?></b></div>
        </div>
    </div>

    <div class="content-panel">
        <div class="main-content" id="mainContent">
            <div id="printFallbackNotice">
                Nothing to print from this screen yet.<br>
                Please get a queue number first, then use the
                "Print Queue Slip" button on the confirmation screen.
            </div>
            <div class="main-content-inner" id="mainContentInner"></div>
        </div>
    </div>
</div>

<div class="printer-setup-overlay" id="printerSetupModal">
    <div class="printer-setup-card">
        <button type="button" class="printer-setup-close" id="printerSetupCloseBtn" aria-label="Close">&times;</button>
        <div class="printer-setup-icon"><i class="bi bi-camera"></i></div>
        <h5>No Printer Available</h5>
        <p>This branch doesn't have a kiosk printer set up yet, so we can't print your slip. Tap Close, then take a photo of your queue number so you don't forget it.</p>
        <div class="printer-setup-actions">
            <button type="button" class="btn btn-outline-secondary" id="printerSetupCloseActionBtn">Close</button>
            <a href="<?= htmlspecialchars($printerSettingsUrl) ?>" target="_blank" class="btn btn-kiosk-primary" id="printerSetupGoBtn">Set Up</a>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var BRANCH_SLUG = <?= json_encode($branchSlug) ?>;
    var PHARMACY_NAME = <?= json_encode($pharmacy['pharmacy_name']) ?>;
    // Every field printer_settings.php saves for the branch's chosen
    // connection type flows straight through here untouched. New driver
    // types (see PRINT_DRIVERS below) only need code added in TWO places:
    // printer_settings.php (a config form) and PRINT_DRIVERS (a print
    // function) -- never here.
    var printerConfig = <?= json_encode([
        'type'           => $printer['connectionType'] ?? null,
        // wireless (Epson ePOS-XML over the network)
        'ip'             => $printer['ip'] ?? null,
        'port'           => $printer['port'] ?? null,
        'protocol'       => $printer['protocol'] ?? null,
        // serial (USB-to-serial / RS232 thermal printers via Web Serial)
        'serialBaudRate' => $printer['serialBaudRate'] ?? 9600,
        'usbVendorId'    => $printer['usbVendorId'] ?? null,
        'usbProductId'   => $printer['usbProductId'] ?? null,
        // agent (local print-bridge app -- see printer_settings.php notes;
        // this is the "any printer" path: network, shared, Bluetooth, etc.
        // all go through whatever the bridge app supports)
        'agentUrl'       => $printer['agentUrl'] ?? null,
        'agentApiKey'    => $printer['agentApiKey'] ?? null,
    ]) ?>;
    var printerConfigured = <?= !empty($printer['isConfigured']) ? 'true' : 'false' ?>;

    var mainInner = document.getElementById('mainContentInner');
    var setupModal = document.getElementById('printerSetupModal');
    var idleTimeoutId = null;
    var idleSecondsLeft = 0;

    function escapeHtml(str) {
        // NOTE: a DOM round-trip (textContent -> innerHTML) only escapes
        // &, <, > — it leaves " and ' untouched. That's fine for plain
        // text nodes, but this function's output also gets concatenated
        // into double-quoted HTML attributes below (e.g. the Rx number
        // input's value="..."), so a bare " in user input (typed on the
        // kiosk touch keyboard) could break out of the attribute and
        // inject markup/JS. Escape all five reserved characters explicitly
        // instead so the same helper is safe in both contexts.
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function clearIdleTimer() {
        if (idleTimeoutId) { clearTimeout(idleTimeoutId); idleTimeoutId = null; }
    }

    function renderLanding() {
        clearIdleTimer();
        mainInner.innerHTML =
            '<div class="tap-screen" id="tapScreen">' +
                '<p class="tap-greeting">Good day! Welcome to ' + escapeHtml(PHARMACY_NAME) + '</p>' +
                '<h1 class="tap-cta">TAP TO<br>GET NUMBER</h1>' +
                '<div class="tap-pulse"></div>' +
            '</div>' +
            '<div id="landingOptions" style="display:none;">' +
                '<h4 class="option-heading">Please select your <span class="accent">queuing</span> option.</h4>' +
                '<div class="d-grid gap-3 w-100">' +
                    '<button type="button" class="kiosk-option" id="optWalkin">' +
                        '<i class="bi bi-person-walking"></i>' +
                        '<div><strong>Walk-in</strong><div class="small">No prescription was processed manually</div></div>' +
                    '</button>' +
                    '<button type="button" class="kiosk-option" id="optEpres">' +
                        '<i class="bi bi-cloud-check"></i>' +
                        '<div><strong>E-Pres Online</strong><div class="small">I have a prescription number from the E-Pres app</div></div>' +
                    '</button>' +
                '</div>' +
            '</div>';

        document.getElementById('tapScreen').addEventListener('click', function () {
            document.getElementById('tapScreen').style.display = 'none';
            document.getElementById('landingOptions').style.display = 'block';
        });
        document.getElementById('optWalkin').addEventListener('click', function () { renderWalkinForm(); });
        document.getElementById('optEpres').addEventListener('click', function () { renderEpresForm(); });
    }

    function renderWalkinForm(errorMsg) {
        clearIdleTimer();
        mainInner.innerHTML =
            '<h4 class="option-heading">Please select your <span class="accent">queuing</span> option.</h4>' +
            (errorMsg ? '<div class="alert alert-danger py-2 w-100">' + escapeHtml(errorMsg) + '</div>' : '') +
            '<div class="w-100">' +
                '<input type="text" id="walkinName" class="form-control text-center mb-3" placeholder="Name (optional)">' +
                '<div class="category-grid">' +
                    '<button type="button" class="category-tile" id="walkinRegular"><i class="bi bi-people"></i> Regular<span class="caption">General queue</span></button>' +
                    '<button type="button" class="category-tile priority" id="walkinPriority"><i class="bi bi-star"></i> Priority<span class="caption">Senior / PWD / Pregnant</span></button>' +
                '</div>' +
                '<button type="button" class="btn btn-kiosk-link btn-sm mt-3" id="walkinBack">Back</button>' +
            '</div>';

        function submitCategory(category) {
            var name = document.getElementById('walkinName').value;
            apiCall('walkin_create', { category: category, walk_in_name: name }).then(function (data) {
                if (data.status === 'ticket') renderTicket(data.ticket);
                else renderWalkinForm(data.message || 'Something went wrong. Please try again.');
            });
        }
        document.getElementById('walkinRegular').addEventListener('click', function () { submitCategory('Regular'); });
        document.getElementById('walkinPriority').addEventListener('click', function () { submitCategory('Priority'); });
        document.getElementById('walkinBack').addEventListener('click', function () { renderLanding(); });
    }

    function renderEpresForm(errorMsg, prefillRxNo) {
        clearIdleTimer();
        mainInner.innerHTML =
            '<h4 class="option-heading"><i class="bi bi-prescription2"></i> Please select your <span class="accent">queuing</span> option.</h4>' +
            '<p class="text-muted">Enter your Prescription Number to get your queue number.</p>' +
            (errorMsg ? '<div class="alert alert-danger py-2 w-100">' + escapeHtml(errorMsg) + '</div>' : '') +
            '<div class="d-grid gap-2 w-100">' +
                '<input type="text" id="epresRxNo" class="form-control form-control-lg text-center text-uppercase" placeholder="e.g. RX-20260711-001" value="' + escapeHtml(prefillRxNo || '') + '">' +
                '<button type="button" class="btn btn-kiosk-primary" id="epresSubmit">Get Queue Number</button>' +
                '<button type="button" class="btn btn-kiosk-link btn-sm" id="epresBack">Back</button>' +
            '</div>';

        var input = document.getElementById('epresRxNo');
        input.focus();

        function submit() {
            var rxNo = input.value;
            apiCall('epres_lookup', { rx_no: rxNo }).then(function (data) { handleEpresResponse(data, rxNo); });
        }
        document.getElementById('epresSubmit').addEventListener('click', submit);
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') submit(); });
        document.getElementById('epresBack').addEventListener('click', function () { renderLanding(); });
    }

    function handleEpresResponse(data, rxNo) {
        if (data.status === 'error') renderEpresForm(data.message, rxNo);
        else if (data.status === 'category_needed') renderEpresCategoryStep(data.rx_no, data.patient_name);
        else if (data.status === 'ticket') renderTicket(data.ticket);
    }

    function renderEpresCategoryStep(rxNo, patientName) {
        clearIdleTimer();
        mainInner.innerHTML =
            '<h4 class="option-heading"><i class="bi bi-prescription2"></i> Prescription Found</h4>' +
            '<p class="rx-context"><strong>' + escapeHtml(rxNo) + '</strong>' +
                (patientName ? ' — ' + escapeHtml(patientName) : '') +
                '<br>Select your queue category to get your number.</p>' +
            '<div class="category-grid mb-3">' +
                '<button type="button" class="category-tile" id="epresRegular"><i class="bi bi-people"></i> Regular<span class="caption">General queue</span></button>' +
                '<button type="button" class="category-tile priority" id="epresPriority"><i class="bi bi-star"></i> Priority<span class="caption">Senior / PWD / Pregnant</span></button>' +
            '</div>' +
            '<button type="button" class="btn btn-kiosk-link btn-sm" id="epresStartOver">Start Over</button>';

        function submitCategory(category) {
            apiCall('epres_lookup', { rx_no: rxNo, category: category }).then(function (data) { handleEpresResponse(data, rxNo); });
        }
        document.getElementById('epresRegular').addEventListener('click', function () { submitCategory('Regular'); });
        document.getElementById('epresPriority').addEventListener('click', function () { submitCategory('Priority'); });
        document.getElementById('epresStartOver').addEventListener('click', function () { renderEpresForm(); });
    }

    function renderTicket(ticket) {
        clearIdleTimer();
        mainInner.innerHTML =
            '<div class="slip-loader" id="slipLoader"><div class="slip-spinner"></div><p>Getting your queue number&hellip;</p></div>' +
            '<div id="printTicket">' +
                '<p class="ticket-branch">' + escapeHtml(ticket.branch_name) + '</p>' +
                '<span class="category-badge ' + escapeHtml(ticket.category) + '">' + escapeHtml(ticket.category) + '</span>' +
                '<p class="text-muted mb-1">Your Queue Number</p>' +
                '<div class="queue-number">' + escapeHtml(ticket.label) + '</div>' +
                (ticket.prescription_id ? '<p class="text-muted mb-0">Prescription No. ' + escapeHtml(ticket.prescription_number) + '</p>' : '') +
                '<p class="text-muted small mb-0">' + escapeHtml(ticket.datetime) + '</p>' +
                '<p class="confirm-wait">Please wait for your number to be called.</p>' +
            '</div>' +
            '<div class="ticket-actions-grid" id="ticketActions" style="display:none;">' +
                '<button class="btn btn-kiosk-primary" id="printSlipBtn"><i class="bi bi-printer"></i> Print Queue Slip</button>' +
                '<button class="btn btn-outline-secondary" id="ticketDoneBtn">Done</button>' +
                '<p class="text-muted small mb-0" id="autoReturnNote"></p>' +
            '</div>' +
            '<div class="slip-loader" id="printSuccessScreen" style="display:none;">' +
                '<div class="slip-spinner" id="printSuccessSpinner"></div>' +
                '<p id="printSuccessMessage">Printing your slip&hellip;</p>' +
            '</div>';

        var loader = document.getElementById('slipLoader');
        var ticketEl = document.getElementById('printTicket');
        var actions = document.getElementById('ticketActions');
        var note = document.getElementById('autoReturnNote');

        document.getElementById('ticketDoneBtn').addEventListener('click', function () { renderLanding(); });
        document.getElementById('printSlipBtn').addEventListener('click', handlePrintClick);

        function tick() {
            if (note) note.textContent = 'Returning to home screen in ' + idleSecondsLeft + 's';
            if (idleSecondsLeft <= 0) { renderLanding(); return; }
            idleSecondsLeft--;
            idleTimeoutId = setTimeout(tick, 1000);
        }
        function resumeCountdown() {
            clearIdleTimer();
            tick();
        }

        function showPrinterSetupModal() { clearIdleTimer(); setupModal.style.display = 'flex'; }
        function hidePrinterSetupModal() { setupModal.style.display = 'none'; }

        var goBtn = document.getElementById('printerSetupGoBtn');
        var closeBtn = document.getElementById('printerSetupCloseBtn');
        var closeActionBtn = document.getElementById('printerSetupCloseActionBtn');
        var newGoBtn = goBtn.cloneNode(true);
        goBtn.parentNode.replaceChild(newGoBtn, goBtn);
        var newCloseBtn = closeBtn.cloneNode(true);
        closeBtn.parentNode.replaceChild(newCloseBtn, closeBtn);
        var newCloseActionBtn = closeActionBtn.cloneNode(true);
        closeActionBtn.parentNode.replaceChild(newCloseActionBtn, closeActionBtn);

        newGoBtn.addEventListener('click', function () { hidePrinterSetupModal(); resumeCountdown(); });
        function closeSetupModalAndResume() { hidePrinterSetupModal(); resumeCountdown(); }
        newCloseBtn.addEventListener('click', closeSetupModalAndResume);
        newCloseActionBtn.addEventListener('click', closeSetupModalAndResume);
        setupModal.onclick = function (e) { if (e.target === setupModal) closeSetupModalAndResume(); };

        function eposXmlFor(t) {
            function esc(s) { return (s || '').toString().replace(/&/g, '&amp;').replace(/</g, '&lt;'); }
            var lines = [
                { text: t.branch, w: '1' },
                { text: '--------------------------------', w: '1' },
                { text: t.category.toUpperCase(), w: '2' },
                { text: 'YOUR QUEUE NUMBER', w: '1' },
                { text: t.label, w: '3' },
            ];
            if (t.rxNo) lines.push({ text: 'Rx No. ' + t.rxNo, w: '1' });
            lines.push({ text: t.datetime, w: '1' });
            lines.push({ text: 'Please wait for your number', w: '1' });
            lines.push({ text: 'to be called.', w: '1' });

            var body = lines.map(function (l) {
                return '<text align="center" width="' + l.w + '" height="' + l.w + '">' + esc(l.text) + '&#10;</text>';
            }).join('');

            return '<?xml version="1.0" encoding="utf-8"?>' +
                '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>' +
                '<epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">' +
                body + '<cut type="feed"/></epos-print></s:Body></s:Envelope>';
        }

        function ticketData() {
            return {
                branch: ticket.branch_name,
                category: ticket.category,
                label: ticket.label,
                rxNo: ticket.prescription_id ? ticket.prescription_number : null,
                datetime: ticket.datetime,
            };
        }

        async function trySilentPrint() {
            if (!printerConfig.ip || printerConfig.protocol !== 'epos_xml') {
                return { ok: false, reason: 'not_configured' };
            }
            if (window.location.protocol === 'https:') {
                console.warn('Silent print skipped: page is HTTPS, printer is HTTP (mixed content).');
                return { ok: false, reason: 'mixed_content' };
            }

            var port = printerConfig.port || 80;
            var controller = new AbortController();
            var timeoutId = setTimeout(function () { controller.abort(); }, 8000);

            try {
                var res = await fetch('http://' + printerConfig.ip + ':' + port +
                    '/cgi-bin/epos/service.cgi?devid=local_printer&timeout=10000', {
                    method: 'POST',
                    headers: { 'Content-Type': 'text/xml; charset=utf-8', 'SOAPAction': '""' },
                    body: eposXmlFor(ticketData()),
                    signal: controller.signal,
                });
                clearTimeout(timeoutId);
                return { ok: res.ok, reason: res.ok ? 'printed' : 'http_' + res.status };
            } catch (e) {
                clearTimeout(timeoutId);
                return { ok: false, reason: e.name === 'AbortError' ? 'timeout' : 'unreachable' };
            }
        }

        function escposBytesFor(t) {
            var enc = new TextEncoder();
            var chunks = [];
            function push(part) { chunks.push(typeof part === 'string' ? enc.encode(part) : new Uint8Array(part)); }

            push([0x1B, 0x40]);
            push([0x1B, 0x61, 0x01]);
            push(t.branch + '\n');
            push('--------------------------------\n');
            push([0x1B, 0x21, 0x10]);
            push(t.category.toUpperCase() + '\n');
            push([0x1B, 0x21, 0x00]);
            push('YOUR QUEUE NUMBER\n');
            push([0x1D, 0x21, 0x33]);
            push(t.label + '\n');
            push([0x1D, 0x21, 0x00]);
            if (t.rxNo) push('Rx No. ' + t.rxNo + '\n');
            push(t.datetime + '\n');
            push('Please wait for your number\nto be called.\n');
            push('\n\n\n');
            push([0x1D, 0x56, 0x42, 0x00]);

            var total = chunks.reduce(function (n, c) { return n + c.length; }, 0);
            var out = new Uint8Array(total);
            var offset = 0;
            chunks.forEach(function (c) { out.set(c, offset); offset += c.length; });
            return out;
        }

        async function tryUsbPrint() {
            if (!('usb' in navigator)) return { ok: false, reason: 'no_webusb' };

            var device = null;
            var claimedInterfaceNumber = null;

            try {
                var devices = await navigator.usb.getDevices();
                device = devices[0];
                if (!device) {
                    device = await navigator.usb.requestDevice({ filters: [{ classCode: 7 }] });
                }
                await device.open();
                if (!device.configuration) await device.selectConfiguration(1);

                // Find BOTH the interface and the specific alternate that
                // actually has interfaceClass 7 — a previous version used
                // alternates[0] unconditionally, which reads the wrong
                // alternate's endpoints (and so silently fails to print)
                // whenever the printer's class-7 alternate isn't index 0.
                var iface = null;
                var matchedAlt = null;
                device.configuration.interfaces.forEach(function (candidate) {
                    if (matchedAlt) return;
                    var alt = candidate.alternates.find(function (a) { return a.interfaceClass === 7; });
                    if (alt) { iface = candidate; matchedAlt = alt; }
                });
                if (!iface) {
                    iface = device.configuration.interfaces[0];
                    matchedAlt = iface.alternates[0];
                }

                await device.claimInterface(iface.interfaceNumber);
                claimedInterfaceNumber = iface.interfaceNumber;

                if (matchedAlt.alternateSetting !== 0) {
                    await device.selectAlternateInterface(iface.interfaceNumber, matchedAlt.alternateSetting);
                }

                var outEndpoint = matchedAlt.endpoints.find(function (e) { return e.direction === 'out'; });
                if (!outEndpoint) throw { name: 'NoOutEndpoint' };

                await device.transferOut(outEndpoint.endpointNumber, escposBytesFor(ticketData()));
                return { ok: true, reason: 'usb_printed' };
            } catch (e) {
                return { ok: false, reason: 'usb_' + (e && e.name ? e.name : 'error') };
            } finally {
                // Always release/close, including on failure — otherwise a
                // single failed print (jam, timing glitch, etc.) leaves the
                // interface claimed and every print after it fails too,
                // until someone physically unplugs and replugs the printer.
                if (device) {
                    try {
                        if (claimedInterfaceNumber !== null) await device.releaseInterface(claimedInterfaceNumber);
                    } catch (releaseErr) { /* already released or device gone — ignore */ }
                    try {
                        await device.close();
                    } catch (closeErr) { /* already closed or device gone — ignore */ }
                }
            }
        }

        // ---- serial: USB-to-serial / RS232 thermal printers via Web Serial.
        // This also rescues printers that WebUSB can't touch because the
        // OS already grabbed them as a COM port (a common cause of the
        // usb_SecurityError case below).
        async function trySerialPrint() {
            if (!('serial' in navigator)) return { ok: false, reason: 'no_webserial' };

            var port = null;
            try {
                var ports = await navigator.serial.getPorts();
                port = ports[0];
                if (!port) {
                    var filters = [];
                    if (printerConfig.usbVendorId) {
                        var f = { usbVendorId: Number(printerConfig.usbVendorId) };
                        if (printerConfig.usbProductId) f.usbProductId = Number(printerConfig.usbProductId);
                        filters.push(f);
                    }
                    port = await navigator.serial.requestPort(filters.length ? { filters: filters } : {});
                }

                await port.open({ baudRate: Number(printerConfig.serialBaudRate) || 9600 });

                var writer = port.writable.getWriter();
                try {
                    await writer.write(escposBytesFor(ticketData()));
                } finally {
                    writer.releaseLock();
                }
                return { ok: true, reason: 'serial_printed' };
            } catch (e) {
                return { ok: false, reason: 'serial_' + (e && e.name ? e.name : 'error') };
            } finally {
                if (port) {
                    try { await port.close(); } catch (closeErr) { /* already closed/gone — ignore */ }
                }
            }
        }

        // ---- agent: a small local "print bridge" app the client runs on
        // the kiosk PC (e.g. a lightweight Node/Python service, or an
        // existing tool like QZ Tray). The browser just POSTs the raw
        // ESC/POS bytes to it over localhost/LAN; the bridge itself has
        // full OS-level printing access, so it can forward to literally
        // any printer -- shared Windows printers, Bluetooth, a raw
        // ESC/POS network socket on port 9100, whatever the bridge
        // supports -- without the browser needing to understand that
        // printer's transport at all. This is the general-purpose answer
        // to "print to any printer" from a browser-based kiosk.
        function bytesToBase64(bytes) {
            var binary = '';
            for (var i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
            return window.btoa(binary);
        }

        async function tryAgentPrint() {
            if (!printerConfig.agentUrl) return { ok: false, reason: 'not_configured' };

            var controller = new AbortController();
            var timeoutId = setTimeout(function () { controller.abort(); }, 8000);

            try {
                var headers = { 'Content-Type': 'application/json' };
                if (printerConfig.agentApiKey) headers['Authorization'] = 'Bearer ' + printerConfig.agentApiKey;

                var res = await fetch(printerConfig.agentUrl, {
                    method: 'POST',
                    headers: headers,
                    body: JSON.stringify({
                        format: 'escpos',
                        dataBase64: bytesToBase64(escposBytesFor(ticketData())),
                        ticket: ticketData(),
                    }),
                    signal: controller.signal,
                });
                clearTimeout(timeoutId);
                return { ok: res.ok, reason: res.ok ? 'agent_printed' : 'agent_http_' + res.status };
            } catch (e) {
                clearTimeout(timeoutId);
                return { ok: false, reason: e.name === 'AbortError' ? 'agent_timeout' : 'agent_unreachable' };
            }
        }

        // ---- Driver dispatch table. Adding a new connection type later
        // means: (1) a print function like the ones above, (2) one line
        // here, (3) a config section in printer_settings.php. Nothing
        // else in this file needs to change.
        var PRINT_DRIVERS = {
            wireless: trySilentPrint,
            wired: tryUsbPrint,
            serial: trySerialPrint,
            agent: tryAgentPrint,
            // 'system' is intentionally absent: it means "skip every
            // direct-print attempt and go straight to window.print()",
            // which lets the OS's own print dialog target ANY installed
            // printer (network, USB, virtual/PDF, shared) -- the one
            // universal fallback every browser already supports.
        };

        async function attemptDirectPrint() {
            var driver = PRINT_DRIVERS[printerConfig.type];
            if (!driver) return { ok: false, reason: 'not_configured' };
            return await driver();
        }

        var reasonText = {
            not_configured: 'Printer not set up for this branch — using standard print dialog.',
            mixed_content: 'This site is secure (https) but the printer only speaks plain http — browsers block that combination.',
            timeout: 'Printer did not respond in time — using standard print dialog.',
            unreachable: 'Could not reach the network printer — using standard print dialog.',
            no_webusb: 'This browser doesn\u2019t support direct USB printing — using standard print dialog.',
            usb_NotFoundError: 'No USB printer was selected/found — using standard print dialog.',
            usb_SecurityError: 'Windows/the OS already has this USB printer claimed for normal printing — using standard print dialog.',
            usb_NetworkError: 'Could not open the USB printer — using standard print dialog.',
            usb_NoOutEndpoint: 'USB printer connected but no data channel was found — using standard print dialog.',
            no_webserial: 'This browser doesn\u2019t support direct serial printing — using standard print dialog.',
            serial_NotFoundError: 'No serial printer was selected/found — using standard print dialog.',
            serial_SecurityError: 'The OS already has this serial port claimed — using standard print dialog.',
            serial_NetworkError: 'Could not open the serial port — using standard print dialog.',
            agent_timeout: 'Print bridge app did not respond in time — using standard print dialog.',
            agent_unreachable: 'Could not reach the print bridge app on this PC — using standard print dialog.',
        };

        function startPrintingUI() {
            clearIdleTimer();
            if (note) note.textContent = '';
            ticketEl.style.display = 'none';
            actions.style.display = 'none';

            var successScreen = document.getElementById('printSuccessScreen');
            var spinner = document.getElementById('printSuccessSpinner');
            var message = document.getElementById('printSuccessMessage');
            if (spinner) spinner.style.display = '';
            message.textContent = 'Printing your slip\u2026';
            successScreen.style.display = 'flex';
        }

        function finishPrintingUI(diagText) {
            var message = document.getElementById('printSuccessMessage');
            function showDone() {
                var spinner = document.getElementById('printSuccessSpinner');
                if (spinner) spinner.outerHTML = '<i class="bi bi-check-circle-fill" style="font-size:2.75rem;color:#12b76a;"></i>';
                message.textContent = 'Please wait for your number to be called.';
                setTimeout(renderLanding, 2200);
            }
            if (diagText) { message.textContent = diagText; setTimeout(showDone, 2500); }
            else { setTimeout(showDone, 1200); }
        }

        async function proceedWithPrint() {
            startPrintingUI();
            var result = await attemptDirectPrint();
            console.log('Kiosk print:', result.ok ? 'silent print sent' : 'falling back to window.print() (' + result.reason + ')');
            var diagText = null;
            if (!result.ok) {
                window.print();
                var reason = result.reason || '';
                if (reasonText[reason] || reason.startsWith('http_') || reason.startsWith('agent_http_')) {
                    diagText = reasonText[reason] || ('Printer rejected the job (' + reason + ') — using standard print dialog.');
                }
            }
            finishPrintingUI(diagText);
        }

        var printInProgress = false;

        function handlePrintClick() {
            if (printInProgress) return; // guards against a double-tap firing two concurrent print jobs
            if (!printerConfigured) { showPrinterSetupModal(); return; }

            printInProgress = true;
            proceedWithPrint().finally(function () { printInProgress = false; });
        }

        setTimeout(function () {
            if (loader) loader.style.display = 'none';
            if (ticketEl) ticketEl.classList.add('show');
            if (actions) actions.style.display = 'grid';
            idleSecondsLeft = 12;
            tick();
        }, 1100);
    }

    async function apiCall(action, params) {
        var body = new URLSearchParams(Object.assign({ action: action, branch: BRANCH_SLUG }, params));
        try {
            var res = await fetch('kiosk_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });
            return await res.json();
        } catch (e) {
            return { status: 'error', message: 'Connection problem — please try again.' };
        }
    }

    <?php if ($initialSlipPayload): ?>
        renderTicket(<?= json_encode($initialSlipPayload) ?>);
    <?php elseif ($initialCategoryStep): ?>
        renderEpresCategoryStep(<?= json_encode($initialCategoryStep['rxNo']) ?>, <?= json_encode($initialCategoryStep['patientName']) ?>);
    <?php elseif ($mode === 'walkin'): ?>
        renderWalkinForm(<?= json_encode($initialError) ?>);
    <?php elseif ($mode === 'epres'): ?>
        renderEpresForm(<?= json_encode($initialError) ?>, <?= json_encode($_POST['rx_no'] ?? '') ?>);
    <?php else: ?>
        renderLanding();
    <?php endif; ?>
})();
</script>
</body>
</html>