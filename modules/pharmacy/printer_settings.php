<?php
session_start();
require '../../config/db.php';
require '../../includes/pharmacy_helpers.php';

date_default_timezone_set('Asia/Manila');

/* ================= AUTH =================
 * Matches queue.php's actual session structure: $_SESSION['user']['role']
 * and $_SESSION['user']['pharmacy_id'], NOT top-level $_SESSION['role'].
 */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['pharmacy', 'super_admin'], true)) {
    header("Location: ../../index.php");
    exit;
}
$dashboardUrl = '../pharmacy/index.php';

// Pharmacy staff manage only their own branch (users.pharmacy_id).
// Super admin can override via ?pharmacy_id= to manage any branch.
if ($_SESSION['user']['role'] === 'super_admin') {
    $pharmacyId = isset($_GET['pharmacy_id']) ? (int) $_GET['pharmacy_id'] : (int) ($_SESSION['user']['pharmacy_id'] ?? 0);
} else {
    $pharmacyId = (int) ($_SESSION['user']['pharmacy_id'] ?? 0);
}

$pharmacy = resolvePharmacyById($conn, $pharmacyId);
if (!$pharmacy) {
    http_response_code(403);
    die('Your account is not assigned to an active pharmacy location. Please contact an administrator.');
}

$errors = [];
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $connectionType = ($_POST['connection_type'] ?? 'wired') === 'wireless' ? 'wireless' : 'wired';
    $printerLabel   = trim($_POST['printer_label'] ?? '');
    $paperWidth     = ($_POST['paper_width'] ?? '80mm') === '58mm' ? '58mm' : '80mm';

    $printerIp = null;
    $printerPort = null;
    $printerProtocol = null;

    if ($connectionType === 'wireless') {
        $printerIp = trim($_POST['printer_ip'] ?? '');
        $printerProtocol = ($_POST['printer_protocol'] ?? 'epos_xml') === 'raw' ? 'raw' : 'epos_xml';
        $portInput = trim($_POST['printer_port'] ?? '');

        if ($printerIp === '' || !filter_var($printerIp, FILTER_VALIDATE_IP)) {
            $errors[] = 'Please enter a valid printer IP address (e.g. 192.168.1.50).';
        }

        if ($portInput === '') {
            $printerPort = $printerProtocol === 'raw' ? 9100 : 80;
        } elseif (!ctype_digit($portInput) || (int) $portInput < 1 || (int) $portInput > 65535) {
            $errors[] = 'Port must be a number between 1 and 65535.';
        } else {
            $printerPort = (int) $portInput;
        }
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("
            UPDATE pharmacy SET
                printer_connection_type = ?,
                printer_label = ?,
                printer_ip = ?,
                printer_port = ?,
                printer_protocol = ?,
                printer_paper_width = ?,
                printer_settings_updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $connectionType,
            $printerLabel !== '' ? $printerLabel : null,
            $printerIp,
            $printerPort,
            $printerProtocol,
            $paperWidth,
            $pharmacyId,
        ]);
        $saved = true;
        // Re-fetch so the form reflects what was actually saved.
        $pharmacy = resolvePharmacyById($conn, $pharmacyId);
    }
}

$connectionType  = $pharmacy['printer_connection_type'] ?? 'wired';
$printerLabel    = $pharmacy['printer_label'] ?? '';
$printerIp       = $pharmacy['printer_ip'] ?? '';
$printerPort     = $pharmacy['printer_port'] ?? '';
$printerProtocol = $pharmacy['printer_protocol'] ?? 'epos_xml';
$paperWidth      = $pharmacy['printer_paper_width'] ?? '80mm';
$updatedAt       = $pharmacy['printer_settings_updated_at'] ?? null;

// Drives the status panel: has this branch ever saved printer settings
// at all, vs. still sitting on defaults nobody has touched.
$hasSavedSettings = $updatedAt !== null;

// Silent network printing (kiosk.php) only actually kicks in for
// wireless + ePOS-Print — raw ESC/POS sockets aren't implemented there
// yet. Everything else still works, just via the kiosk device's default
// printer + --kiosk-printing instead of a direct network call.
$silentPrintActive = $connectionType === 'wireless' && $printerProtocol === 'epos_xml' && $printerIp !== '';

$kioskUrl = 'kiosk.php?branch=' . urlencode($pharmacy['slug'] ?? '');

include '../../includes/header.php';
?>

<style>
    :root {
        --pharm-teal:        #0f766e;
        --pharm-teal-dark:   #0b5a54;
        --pharm-teal-light:  #ecfdfa;
        --pharm-teal-border: #99e6dc;
    }
    .pharm-banner {
        background: linear-gradient(135deg, var(--pharm-teal) 0%, #0e6f66 55%, #0b5a54 100%);
        border-radius: 1rem;
        padding: 1.75rem 2rem;
        color: #fff;
        margin-bottom: 1.75rem;
        position: relative;
        overflow: hidden;
        box-shadow: 0 8px 24px rgba(15, 118, 110, 0.18);
    }
    .pharm-banner::after {
        content: "";
        position: absolute;
        top: -40px; right: -30px;
        width: 180px; height: 180px;
        background: rgba(255,255,255,0.08);
        border-radius: 50%;
    }
    .pharm-banner::before {
        content: "";
        position: absolute;
        bottom: -60px; right: 90px;
        width: 120px; height: 120px;
        background: rgba(255,255,255,0.06);
        border-radius: 50%;
    }
    .pharm-banner h2 { font-weight: 800; margin-bottom: .15rem; position: relative; }
    .pharm-banner p { opacity: .9; margin-bottom: 0; position: relative; font-size: .92rem; }
    .pharm-banner .branch-chip {
        display:inline-flex; align-items:center; gap:.4rem; font-weight:700; font-size:.8rem;
        padding:.4rem .85rem; border-radius:2rem; background: rgba(255,255,255,0.15);
        color: #fff; border: 1px solid rgba(255,255,255,0.35); margin-top: .9rem; position: relative;
    }

    /* ===== Layout: form + status panel side by side ===== */
    .printer-settings-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1.5rem;
        align-items: start;
    }
    @media (min-width: 992px) {
        .printer-settings-grid { grid-template-columns: 1.6fr 1fr; }
    }

    .settings-card, .status-card {
        background: #fff;
        border: 1px solid #e6e8eb;
        border-radius: 1rem;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
    }
    .settings-card { padding: 2rem; }

    .field-section { margin-bottom: 1.75rem; }
    .field-section:last-of-type { margin-bottom: 1.5rem; }
    .field-section-label {
        display: flex; align-items: center; gap: .5rem;
        font-weight: 700; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em;
        color: #475467; margin-bottom: .85rem;
    }
    .field-section-label i { color: var(--pharm-teal); font-size: .95rem; }

    .conn-option {
        border: 2px solid #e4e7ec;
        border-radius: .75rem;
        padding: 1rem 1.25rem;
        cursor: pointer;
        display: flex;
        align-items: flex-start;
        gap: .75rem;
        transition: border-color .15s ease, background .15s ease, transform .12s ease;
    }
    .conn-option:hover { border-color: #98a2b3; transform: translateY(-1px); }
    .conn-option.active { border-color: var(--pharm-teal); background: var(--pharm-teal-light); }
    .conn-option .conn-icon {
        width: 40px; height: 40px; flex-shrink: 0;
        border-radius: .6rem;
        background: #f2f4f7;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.15rem; color: #667085;
        transition: background .15s ease, color .15s ease;
    }
    .conn-option.active .conn-icon { background: var(--pharm-teal); color: #fff; }
    .conn-option .title { font-weight: 700; color: #1d2939; font-size: .92rem; }
    .conn-option .desc { font-size: .8rem; color: #667085; margin-top: .1rem; }

    #wirelessFields {
        display: none;
        border: 1px dashed var(--pharm-teal-border);
        background: var(--pharm-teal-light);
        border-radius: .75rem;
        padding: 1.1rem 1.25rem;
    }

    .status-pill { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; padding: .25rem .6rem; border-radius: .4rem; display: inline-flex; align-items: center; gap: .3rem; }
    .status-pill.wired { background: #eef2f6; color: #344054; }
    .status-pill.wireless { background: var(--pharm-teal-light); color: var(--pharm-teal-dark); }
    .status-pill.dot::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

    .btn-teal { background: var(--pharm-teal); color: #fff; border: 1px solid var(--pharm-teal); }
    .btn-teal:hover { background: var(--pharm-teal-dark); color: #fff; }

    /* ===== Status / preview panel ===== */
    .status-card { padding: 1.75rem; position: sticky; top: 1.25rem; }
    .status-card .status-icon-wrap {
        width: 64px; height: 64px;
        border-radius: 1rem;
        background: linear-gradient(135deg, var(--pharm-teal) 0%, var(--pharm-teal-dark) 100%);
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.7rem;
        margin-bottom: 1rem;
        box-shadow: 0 6px 16px rgba(15,118,110,0.25);
    }
    .status-card .status-icon-wrap.unset {
        background: #f2f4f7;
        color: #98a2b3;
        box-shadow: none;
    }
    .status-card h6 { font-weight: 800; color: #1d2939; margin-bottom: .2rem; }
    .status-card .status-sub { color: #667085; font-size: .82rem; margin-bottom: 1.25rem; }

    .status-row {
        display: flex; justify-content: space-between; align-items: center;
        padding: .6rem 0; border-bottom: 1px solid #f2f4f7; font-size: .85rem;
    }
    .status-row:last-child { border-bottom: none; }
    .status-row .label { color: #667085; }
    .status-row .value { font-weight: 700; color: #1d2939; text-align: right; }
    .status-row .value.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem; }

    .silent-print-note {
        margin-top: 1.25rem;
        border-radius: .6rem;
        padding: .8rem .9rem;
        font-size: .78rem;
        line-height: 1.45;
        display: flex; gap: .55rem; align-items: flex-start;
    }
    .silent-print-note.active { background: #ecfdf3; color: #027a48; border: 1px solid #abefc6; }
    .silent-print-note.inactive { background: #fffaeb; color: #93370d; border: 1px solid #fedf89; }
    .silent-print-note i { margin-top: .1rem; }

    .status-card .btn-open-kiosk { margin-top: 1.1rem; width: 100%; }
</style>

<div class="page-title">
  <nav class="breadcrumbs">
    <div class="container">
      <ol>
        <li><a href="<?= $dashboardUrl ?>">Dashboard</a></li>
        <li><a href="queue.php">Queue</a></li>
        <li class="current">Printer Settings</li>
      </ol>
    </div>
  </nav>
</div>

<section class="section">
    <!--<div class="container" data-aos="fade-up">-->
    <!--    <div class="pharm-banner">-->
    <!--        <h2 class="text-white">Kiosk Printer Settings</h2>-->
    <!--        <p>Configure how the queue kiosk prints slips on this branch's receipt printer.</p>-->
    <!--        <span class="branch-chip"><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($pharmacy['pharmacy_name']) ?></span>-->
    <!--    </div>-->
    <!--</div>-->

    <div class="container" data-aos="fade-up" data-aos-delay="100">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div></div>
            <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back</a>
        </div>

        <?php if ($saved): ?>
            <div class="alert alert-success py-2 mb-3"><i class="bi bi-check-circle-fill"></i> Printer settings saved.</div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger py-2 mb-3">
                <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="printer-settings-grid">

            <!-- ===== FORM ===== -->
            <div class="settings-card">
                <form method="post" id="printerForm">

                    <div class="field-section">
                        <div class="field-section-label"><i class="bi bi-hdd-network"></i> Connection type</div>
                        <div class="row g-3">
                            <div class="col-12 col-sm-6">
                                <label class="conn-option w-100 m-0" data-value="wired">
                                    <input type="radio" name="connection_type" value="wired" class="d-none"
                                           <?= $connectionType === 'wired' ? 'checked' : '' ?>>
                                    <span class="conn-icon"><i class="bi bi-usb-symbol"></i></span>
                                    <div>
                                        <div class="title">Wired</div>
                                        <div class="desc">Plugged directly (USB/cable) into the kiosk device.</div>
                                    </div>
                                </label>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="conn-option w-100 m-0" data-value="wireless">
                                    <input type="radio" name="connection_type" value="wireless" class="d-none"
                                           <?= $connectionType === 'wireless' ? 'checked' : '' ?>>
                                    <span class="conn-icon"><i class="bi bi-wifi"></i></span>
                                    <div>
                                        <div class="title">Wireless / Network</div>
                                        <div class="desc">Has its own IP address on the local WiFi/network.</div>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!--<div class="field-section">-->
                    <!--    <div class="field-section-label"><i class="bi bi-tag"></i> Label <span class="text-muted fw-normal text-lowercase">(optional)</span></div>-->
                    <!--    <input type="text" name="printer_label" class="form-control" placeholder="e.g. Front Counter TM-m30"-->
                    <!--           value="<?= htmlspecialchars($printerLabel) ?>">-->
                    <!--    <div class="form-text">Just for your own reference if a branch has more than one printer/kiosk.</div>-->
                    <!--</div>-->

                    <div class="field-section" id="wirelessFields">
                        <div class="field-section-label mb-2"><i class="bi bi-router"></i> Network details</div>
                        <div class="row g-3">
                            <div class="col-12 col-sm-5">
                                <label class="form-label fw-semibold small">Printer IP address</label>
                                <input type="text" name="printer_ip" class="form-control" placeholder="192.168.1.50"
                                       value="<?= htmlspecialchars($printerIp) ?>">
                            </div>
                            <div class="col-6 col-sm-3">
                                <label class="form-label fw-semibold small">Port</label>
                                <input type="text" name="printer_port" class="form-control" placeholder="80"
                                       value="<?= htmlspecialchars((string) $printerPort) ?>">
                            </div>
                            <div class="col-6 col-sm-4">
                                <label class="form-label fw-semibold small">Protocol</label>
                                <select name="printer_protocol" class="form-select">
                                    <option value="epos_xml" <?= $printerProtocol === 'epos_xml' ? 'selected' : '' ?>>ePOS-Print (80)</option>
                                    <option value="raw" <?= $printerProtocol === 'raw' ? 'selected' : '' ?>>Raw ESC/POS (9100)</option>
                                </select>
                            </div>
                        </div>
                        <p class="text-muted small mb-0 mt-2">
                            <i class="bi bi-info-circle"></i>
                            Find the printer's IP by holding <strong>Feed</strong> while powering it on — it prints a status sheet with the network address.
                        </p>
                    </div>

                    <div class="field-section">
                        <div class="field-section-label"><i class="bi bi-receipt"></i> Receipt paper width</div>
                        <select name="paper_width" class="form-select" style="max-width: 220px;">
                            <option value="80mm" <?= $paperWidth === '80mm' ? 'selected' : '' ?>>80mm (standard)</option>
                            <option value="58mm" <?= $paperWidth === '58mm' ? 'selected' : '' ?>>58mm (narrow)</option>
                        </select>
                        <div class="form-text">Matches the TM-m30's paper roll. The kiosk slip layout adjusts to this width automatically.</div>
                    </div>

                    <!--<div class="alert alert-secondary small mb-4">-->
                    <!--    <div class="mb-2"><strong>Wireless + ePOS-Print:</strong> the kiosk sends slips straight to this IP/port — no print dialog at all. If the printer is unreachable (or the site is https talking to a plain-http printer), it automatically falls back to the browser's print dialog instead.</div>-->
                    <!--    <div><strong>Wired, or raw ESC/POS:</strong> still goes through the kiosk device's browser print dialog. Set this printer as that device's default and launch the browser with <code>--kiosk-printing</code> to make it silent too.</div>-->
                    <!--</div>-->

                    <button type="submit" class="btn btn-dark px-4">Save Printer Settings</button>
                    <?php if ($updatedAt): ?>
                        <span class="text-muted small ms-2">Last updated <?= date('M j, Y g:i A', strtotime($updatedAt)) ?></span>
                    <?php endif; ?>
                </form>
            </div>

            <!-- ===== STATUS / PREVIEW PANEL ===== -->
            <div class="status-card">
                <?php if (!$hasSavedSettings): ?>
                    <div class="status-icon-wrap unset"><i class="bi bi-printer"></i></div>
                    <h6>Not set up yet</h6>
                    <div class="status-sub">Save your printer's connection details to enable this panel.</div>
                <?php else: ?>
                    <div class="status-icon-wrap"><i class="bi bi-<?= $connectionType === 'wireless' ? 'wifi' : 'usb-symbol' ?>"></i></div>
                    <h6><?= htmlspecialchars($printerLabel !== '' ? $printerLabel : 'Kiosk Printer') ?></h6>
                    <div class="status-sub"><?= htmlspecialchars($pharmacy['pharmacy_name']) ?></div>

                    <div class="status-row">
                        <span class="label">Connection</span>
                        <span class="status-pill dot <?= htmlspecialchars($connectionType) ?>"><?= $connectionType === 'wireless' ? 'Wireless' : 'Wired' ?></span>
                    </div>
                    <?php if ($connectionType === 'wireless'): ?>
                        <div class="status-row">
                            <span class="label">Address</span>
                            <span class="value mono"><?= htmlspecialchars($printerIp ?: '—') ?><?= $printerPort ? ':' . htmlspecialchars((string) $printerPort) : '' ?></span>
                        </div>
                        <div class="status-row">
                            <span class="label">Protocol</span>
                            <span class="value"><?= $printerProtocol === 'raw' ? 'Raw ESC/POS' : 'ePOS-Print' ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="status-row">
                        <span class="label">Paper width</span>
                        <span class="value"><?= htmlspecialchars($paperWidth) ?></span>
                    </div>
                    <div class="status-row">
                        <span class="label">Updated</span>
                        <span class="value"><?= date('M j, g:i A', strtotime($updatedAt)) ?></span>
                    </div>

                    <div class="silent-print-note <?= $silentPrintActive ? 'active' : 'inactive' ?>">
                        <i class="bi bi-<?= $silentPrintActive ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
                        <span>
                            <?php if ($silentPrintActive): ?>
                                Silent network printing is <strong>active</strong> for this branch's kiosk.
                            <?php else: ?>
                                Silent printing isn't active yet — the kiosk will use the browser's print dialog (or <code>--kiosk-printing</code> if configured on that device).
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>

                <a href="<?= htmlspecialchars($kioskUrl) ?>" target="_blank" class="btn btn-outline-dark btn-sm btn-open-kiosk">
                    <i class="bi bi-display"></i> Open Kiosk to Test
                </a>
            </div>

        </div>
    </div>
</section>

<script>
document.querySelectorAll('.conn-option').forEach(function (el) {
    el.addEventListener('click', function () {
        el.querySelector('input[type=radio]').checked = true;
        toggleWirelessFields();
    });
});

function toggleWirelessFields() {
    var checked = document.querySelector('input[name=connection_type]:checked');
    var isWireless = checked && checked.value === 'wireless';
    document.getElementById('wirelessFields').style.display = isWireless ? 'block' : 'none';
    document.querySelectorAll('.conn-option').forEach(function (el) {
        el.classList.toggle('active', checked && el.dataset.value === checked.value);
    });
}
toggleWirelessFields();
</script>

<?php include '../../includes/footer.php'; ?>