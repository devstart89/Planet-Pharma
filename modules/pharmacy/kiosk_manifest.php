<?php
/*
 * Dynamic PWA manifest, one per branch. Static manifest.json files can't
 * vary their start_url per request — but each physical kiosk tablet
 * needs its installed icon to open straight into ITS branch, not a
 * generic branch picker. Generating this from PHP with ?branch= solves
 * that: install from https://.../kiosk.php?branch=bangkal and the
 * resulting home-screen icon is permanently wired to the Bangkal kiosk.
 *
 * Linked from kiosk.php as:
 *   <link rel="manifest" href="kiosk_manifest.php?branch=<?= urlencode($branchSlug) ?>">
 */
require '../../config/db.php';
require '../../includes/pharmacy_helpers.php';

$branchSlug = $_GET['branch'] ?? '';
$pharmacy = resolvePharmacyBySlug($conn, $branchSlug);

$appName = $pharmacy ? $pharmacy['pharmacy_name'] . ' Kiosk' : 'EPscript Pharmacy Kiosk';
$startUrl = 'kiosk.php' . ($branchSlug !== '' ? '?branch=' . urlencode($branchSlug) : '');

// Reuses the existing branch logo already referenced in kiosk.php
// (../logo/PLANETLIGHT.png) rather than requiring new pre-sized icon
// assets right away. Browsers will scale it to fit, which works but
// won't look as crisp as a purpose-made 192x192 / 512x512 PNG — worth
// swapping in dedicated icon files later if the low-res scaling looks
// rough on a given device's home screen.
$iconUrl = '../logo/PLANETLIGHT.png';

header('Content-Type: application/manifest+json');

echo json_encode([
    'name' => $appName,
    'short_name' => $pharmacy ? $pharmacy['pharmacy_name'] : 'Pharmacy Kiosk',
    'start_url' => $startUrl,
    'scope' => 'kiosk.php',
    'display' => 'standalone',       // hides the browser address bar / chrome once installed
    'orientation' => 'any',          // works whether the tablet is mounted portrait or landscape
    'background_color' => '#ffffff',
    'theme_color' => '#1d2939',      // matches --brand-navy in kiosk.php
    'icons' => [
        ['src' => $iconUrl, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => $iconUrl, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
], JSON_UNESCAPED_SLASHES);