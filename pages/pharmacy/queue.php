<?php
session_start();
require '../../config/db.php';
require '../../includes/pharmacy_helpers.php';

/* ================= AUTH ================= */
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'pharmacy') {
    header("Location: ../../index.php");
    exit;
}
$dashboardUrl = '../pharmacy/index.php';

/*
 * ================= MULTI-PHARMACY SCOPE =================
 * A staff account belongs to exactly one branch (users.pharmacy_id).
 * Every query below is scoped to that pharmacy_id so one branch's
 * staff can never see or move another branch's queue.
 */
$pharmacyId = (int) ($_SESSION['user']['pharmacy_id'] ?? 0);
$pharmacy = resolvePharmacyById($conn, $pharmacyId);
if (!$pharmacy) {
    // Misconfigured account (no branch assigned, or branch deactivated).
    http_response_code(403);
    die('Your account is not assigned to an active pharmacy location. Please contact an administrator.');
}

/*
 * ================= DYNAMIC BASE URL =================
 * Built from the CURRENT request instead of a hardcoded relative path.
 * This script lives at modules/pharmacy/queue.php — two folders below
 * the site root — so we can derive that root directly from where this
 * request actually came from ($_SERVER['SCRIPT_NAME']).
 *
 * This self-adjusts correctly whether the app is installed at a domain
 * root, in a subfolder, on localhost, staging, or production.
 */
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);       // e.g. /modules/pharmacy
$siteRoot  = rtrim(dirname($scriptDir, 2), '/');      // strip "pharmacy" and "modules" -> site root
if ($siteRoot === '') $siteRoot = '';

$kioskUrl         = $siteRoot . '/modules/pharmacy/kiosk.php?branch=' . urlencode($pharmacy['slug']);
$publicMonitorUrl = $siteRoot . '/modules/public/queue_display.php?branch=' . urlencode($pharmacy['slug']);

/*
 * ================= BACK-END QUEUE MANAGEMENT =================
 * pharmacy staff see every queue transaction for THEIR branch and
 * move it freely between Waiting / Now Serving / Completed / Cancelled.
 *
 * ---- Requirement 2: Cancel ----
 * The system provides a Cancel button under the Action column so
 * authorized users (any logged-in pharmacy staff for this branch --
 * see the AUTH block above) can void a queued transaction:
 *   - Clicking Cancel shows a confirmation prompt before proceeding
 *     (client-side, see the .cancel-btn handler in the script below).
 *   - Once confirmed, the row's status becomes 'Cancelled' and is no
 *     longer treated as an active queue transaction (isHistoryRow()
 *     below folds it in with Completed/Unclaimed for that purpose).
 *   - If the row has a prescription_id, that prescription's
 *     medicine_status is automatically flipped to 'Cancelled' too.
 *   - Every cancellation is written to queue_status_log for
 *     monitoring/audit (who, when, old/new status, reason).
 *
 * Cancel differs from Completed in one respect: Completed on an
 * E-Pres row requires a per-medicine dispense decision (routed through
 * prescription_action.php's modal), but Cancel doesn't -- nothing was
 * dispensed, so it's allowed directly from this table for BOTH
 * Walk-in and E-Pres rows, gated only by the confirmation prompt.
 *
 * NOTE: prescriptions.status enum('For Signing','Signed','Denied','Dispensed')
 * and prescriptions.medicine_status enum('Pending','Dispensed','Partial','Cancelled') are
 * two separate fields. This page only ever writes medicine_status (via
 * markCompleted()/markCancelled() for Walk-in and direct cancels, or
 * prescription_action.php for E-Pres dispensing) -- whatever sets
 * prescriptions.status to 'Dispensed' lives elsewhere in the app and is
 * untouched here.
 *
 * ================= REQUIRED SCHEMA CHANGES (see migration notes) =================
 * This page assumes the following, which need a migration before this
 * code runs cleanly against a fresh schema:
 *   - queues.status enum(...) gains 'Cancelled'
 *   - queues gains columns: cancelled_at DATETIME NULL, cancelled_reason VARCHAR(255) NULL
 *   - prescriptions.medicine_status enum(...) gains 'Cancelled'
 *   - new table queue_status_log (id, queue_id, old_status, new_status,
 *     reason, changed_by, changed_at) for the audit trail requirement
 */

function markCompleted(PDO $conn, array $queueRow): void {
    $stmt = $conn->prepare("UPDATE queues SET status = 'Completed', completed_at = NOW() WHERE id = ?");
    $stmt->execute([$queueRow['id']]);

    if (!empty($queueRow['prescription_id'])) {
        $stmt = $conn->prepare("UPDATE prescriptions SET medicine_status = 'Dispensed', dispensed_at = NOW() WHERE id = ?");
        $stmt->execute([$queueRow['prescription_id']]);
    }
}

/*
 * Voids a queued transaction. Requires a reason (enforced client-side
 * and re-checked here). Writes an audit row so cancellations are
 * reviewable later — who cancelled what, when, and why.
 */
function markCancelled(PDO $conn, array $queueRow, string $reason): void {
    $stmt = $conn->prepare("UPDATE queues SET status = 'Cancelled', cancelled_at = NOW(), cancelled_reason = ? WHERE id = ?");
    $stmt->execute([$reason, $queueRow['id']]);

    if (!empty($queueRow['prescription_id'])) {
        $stmt = $conn->prepare("UPDATE prescriptions SET medicine_status = 'Cancelled' WHERE id = ?");
        $stmt->execute([$queueRow['prescription_id']]);
    }

    $stmt = $conn->prepare("
        INSERT INTO queue_status_log (queue_id, old_status, new_status, reason, changed_by, changed_at)
        VALUES (?, ?, 'Cancelled', ?, ?, NOW())
    ");
    $stmt->execute([
        $queueRow['id'],
        $queueRow['status'],
        $reason,
        $_SESSION['user']['id'] ?? null,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Direct status change on a single row (Waiting / Now Serving / Completed / Cancelled).
    // Completed is only allowed here for Walk-in rows — E-Pres rows must
    // go through prescription_action.php so every medicine gets a
    // dispense decision instead of being silently skipped. Cancelled is
    // allowed here for BOTH sources.
    if (!empty($_POST['queue_id']) && !empty($_POST['new_status'])) {
        $queueId = (int) $_POST['queue_id'];
        $newStatus = $_POST['new_status'];

        // Scope the lookup by pharmacy_id too — this is the key
        // multi-tenant guard: even if someone guesses/tampers with a
        // queue_id belonging to another branch, this WHERE clause
        // means the row simply won't be found here.
        $stmt = $conn->prepare("SELECT * FROM queues WHERE id = ? AND pharmacy_id = ?");
        $stmt->execute([$queueId, $pharmacyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && in_array($newStatus, ['Waiting', 'Now Serving', 'Completed', 'Cancelled'], true)) {
            if ($newStatus === 'Completed') {
                if ($row['source'] === 'E-Pres') {
                    // Blocked here on purpose — see comment above.
                } else {
                    markCompleted($conn, $row);
                }
            } elseif ($newStatus === 'Cancelled') {
                // Spec only calls for a confirmation prompt here, not a
                // mandatory reason (same as the existing "Mark Unclaimed"
                // flow, which takes an optional note) — so the reason is
                // stored when given but never blocks the cancellation.
                $reason = trim((string) ($_POST['reason'] ?? ''));
                markCancelled($conn, $row, $reason);
            } elseif ($newStatus === 'Now Serving') {
                $stmt = $conn->prepare("UPDATE queues SET status = 'Now Serving', called_at = NOW() WHERE id = ? AND pharmacy_id = ?");
                $stmt->execute([$queueId, $pharmacyId]);
            } else { // back to Waiting
                $stmt = $conn->prepare("UPDATE queues SET status = 'Waiting' WHERE id = ? AND pharmacy_id = ?");
                $stmt->execute([$queueId, $pharmacyId]);
            }
        }
    }

    // "Call Next" convenience action: promote the earliest Waiting row
    // in a category (within THIS branch) straight to Now Serving.
    if (!empty($_POST['call_next_category'])) {
        $category = $_POST['call_next_category'] === 'Priority' ? 'Priority' : 'Regular';
        $stmt = $conn->prepare("
            SELECT id FROM queues
            WHERE pharmacy_id = ? AND category = ? AND status = 'Waiting' AND DATE(created_at) = CURDATE()
            ORDER BY queue_number ASC LIMIT 1
        ");
        $stmt->execute([$pharmacyId, $category]);
        $next = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($next) {
            $stmt = $conn->prepare("UPDATE queues SET status = 'Now Serving', called_at = NOW() WHERE id = ? AND pharmacy_id = ?");
            $stmt->execute([$next['id'], $pharmacyId]);
        }
    }

    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
        exit;
    }

    header("Location: queue.php");
    exit;
}

include '../../includes/header.php';
?>

<!-- DataTables CSS -->
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<style>
    :root {
        --pharm-teal:        #0f766e;
        --pharm-teal-dark:   #0b5a54;
        --pharm-teal-light:  #ecfdfa;
        --pharm-teal-border: #99e6dc;
        --pharm-blue:        #175cd3;
    }

    /* ===== Hospital / pharmacy header banner ===== */
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
    .pharm-banner .pharm-icon {
        width: 48px; height: 48px;
        background: rgba(255,255,255,0.16);
        border: 1px solid rgba(255,255,255,0.3);
        border-radius: .75rem;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 1.4rem;
        margin-bottom: .75rem;
    }
    .pharm-banner h2 { font-weight: 800; margin-bottom: .15rem; position: relative; }
    .pharm-banner p { opacity: .9; margin-bottom: 0; position: relative; font-size: .92rem; }
    .pharm-banner .branch-chip {
        display:inline-flex; align-items:center; gap:.4rem; font-weight:700; font-size:.8rem;
        padding:.4rem .85rem; border-radius:2rem; background: rgba(255,255,255,0.15);
        color: #fff; border: 1px solid rgba(255,255,255,0.35); margin-top: .9rem; position: relative;
    }

    /* ===== List page refinements (shared visual language with Prescription List) ===== */
    .list-toolbar {
        background: #fff;
        border: 1px solid #e6e8eb;
        border-radius: 0.75rem;
        padding: 1.25rem 1.5rem;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
    }
    .list-toolbar .form-label {
        font-size: 0.8rem;
        font-weight: 600;
        color: #475467;
        text-transform: uppercase;
        letter-spacing: .02em;
        margin-bottom: .35rem;
    }
    #queueTable thead th {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: .03em;
        color: #667085;
        font-weight: 700;
        border-bottom-width: 1px;
        white-space: nowrap;
    }
    #queueTable tbody td { vertical-align: middle; font-size: 0.92rem; }
    #queueTable tbody tr:hover { background-color: #f9fafb; }

    .status-pill { display:inline-flex; align-items:center; gap:.35rem; font-weight:600; font-size:0.8rem; padding:.35rem .7rem; border-radius:0.5rem; white-space:nowrap; }
    .status-pill-waiting     { background:#fffaeb; color:#b54708; border:1px solid #fedf89; }
    .status-pill-serving     { background:#eff8ff; color:#175cd3; border:1px solid #b2ddff; }
    .status-pill-completed   { background:#ecfdf3; color:#027a48; border:1px solid #abefc6; }
    .status-pill-unclaimed   { background:#f2f4f7; color:#475467; border:1px solid #d0d5dd; }
    .status-pill-cancelled   { background:#fef3f2; color:#b42318; border:1px solid #fecdca; }

    .category-pill { font-weight:700; font-size:.75rem; padding:.25rem .55rem; border-radius:.4rem; }
    .category-pill.Regular   { background:#eff8ff; color:#175cd3; }
    .category-pill.Priority  { background:#fef3f2; color:#b42318; }

    .source-pill { display:inline-flex; align-items:center; gap:.35rem; font-weight:600; font-size:0.78rem; padding:.3rem .6rem; border-radius:0.5rem; white-space:nowrap; }
    .source-pill.online { background:#f4f3ff; color:#5925dc; border:1px solid #d9d6fe; }
    .source-pill.walkin { background:#f2f4f7; color:#344054; border:1px solid #eaecf0; }

    .rx-no-pill {
        display:inline-flex; align-items:center; gap:.3rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size:.8rem; font-weight:700; color: var(--pharm-teal-dark);
        background: var(--pharm-teal-light); border:1px solid var(--pharm-teal-border);
        padding:.3rem .6rem; border-radius:.45rem; white-space:nowrap;
    }
    .rx-no-none { color:#98a2b3; font-size:.85rem; }

    .queue-number-badge { font-size: 1.05rem; font-weight: 800; color: var(--pharm-teal-dark); }

    .empty-state { text-align:center; color:#98a2b3; padding:2rem 1rem; }
    .empty-state i { font-size: 1.75rem; display: block; margin-bottom: .5rem; }

    .call-next-bar {
        background:#fff; border:1px solid #e6e8eb; border-radius:.75rem; padding:1rem 1.25rem;
        border-left: 4px solid var(--pharm-teal);
    }

    /* ===== Source / status tabs =====
       Requirement 3 asks for SEPARATE filters for Completed, Unclaimed,
       and Cancelled -- rather than bundling all three into one combined
       "history" tab, each gets its own dedicated, independently
       clickable tab with its own live count, alongside All/Walk-in/
       Online. All/Walk-in/Online show only ACTIVE (Waiting/Now Serving)
       rows, matching their original meaning as "live queue" views;
       Completed/Unclaimed/Cancelled are dedicated status-only views. */
    .queue-tabs {
        display:flex; flex-wrap:wrap; gap:.35rem; background:#f2f4f7; border-radius:2rem; padding:.3rem;
        margin-bottom: 1.25rem;
    }
    .queue-tabs .tab-btn {
        border:none; background:transparent; color:#475467; font-weight:700; font-size:.85rem;
        padding:.5rem 1.1rem; border-radius:1.75rem; display:inline-flex; align-items:center; gap:.4rem;
        transition: all .15s ease; white-space: nowrap;
    }
    .queue-tabs .tab-btn .badge-count {
        background:rgba(0,0,0,0.08); color:inherit; font-size:.72rem; font-weight:800;
        padding:.1rem .45rem; border-radius:1rem;
    }
    .queue-tabs .tab-btn.active { background: var(--pharm-teal); color:#fff; box-shadow: 0 2px 6px rgba(15,118,110,0.3); }
    .queue-tabs .tab-btn.active .badge-count { background: rgba(255,255,255,0.25); }
    .queue-tabs .tab-btn:not(.active):hover { background:#e6e8eb; }

    /* ===== Action column — pharmacy-professional look ===== */
    .action-group { display:flex; align-items:center; gap:.4rem; flex-wrap:wrap; }
    .status-toggle { border:1px solid #d0d5dd; border-radius:.5rem; overflow:hidden; display:inline-flex; }
    .status-toggle .btn { border-radius:0; border:none; font-size:.76rem; padding:.35rem .6rem; }
    .status-toggle .btn + .btn { border-left:1px solid #d0d5dd; }
    .btn-dispense {
        display:inline-flex; align-items:center; gap:.35rem; font-size:.78rem; font-weight:600;
        padding:.35rem .75rem; border-radius:.5rem; background: var(--pharm-teal); color:#fff; border:1px solid var(--pharm-teal);
    }
    .btn-dispense:hover { background: var(--pharm-teal-dark); color:#fff; }
    .btn-complete-walkin {
        display:inline-flex; align-items:center; gap:.35rem; font-size:.78rem; font-weight:600;
        padding:.35rem .75rem; border-radius:.5rem; background:#fff; color:#12b76a; border:1px solid #abefc6;
    }
    .btn-complete-walkin:hover { background:#ecfdf3; color:#12b76a; }
    .btn-cancel-queue {
        display:inline-flex; align-items:center; gap:.35rem; font-size:.78rem; font-weight:600;
        padding:.35rem .75rem; border-radius:.5rem; background:#fff; color:#b42318; border:1px solid #fecdca;
    }
    .btn-cancel-queue:hover { background:#fef3f2; color:#b42318; }
    .btn-view-record { color:#98a2b3; padding:.3rem .4rem; }
    .btn-view-record:hover { color: var(--pharm-teal); }

    /* ===== Dispense modal ===== */
    #dispenseModal .modal-dialog { max-width: 720px; }
    #dispenseModal .modal-header {
        background: linear-gradient(135deg, var(--pharm-teal) 0%, var(--pharm-teal-dark) 100%);
        color: #fff; border: none;
    }
    #dispenseModal .modal-header .text-muted { color: rgba(255,255,255,0.85) !important; }
    #dispenseModal .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
    .rx-info-grid {
        display:grid; grid-template-columns:repeat(2, 1fr); gap:.6rem 1.5rem;
        background: var(--pharm-teal-light); border:1px solid var(--pharm-teal-border); border-radius:.6rem; padding:1rem 1.25rem; margin-bottom:1.25rem;
    }
    .rx-info-grid .label { font-size:.72rem; text-transform:uppercase; letter-spacing:.03em; color:#0b8577; font-weight:700; }
    .rx-info-grid .value { font-size:.9rem; color:#1d2939; font-weight:600; }
    .rx-medicine-card { border:1px solid #eaecf0; border-radius:.6rem; padding:.9rem 1rem; margin-bottom:.75rem; }
    .rx-medicine-card .med-name { font-weight:700; color:#1d2939; }
    .rx-medicine-card .med-meta { font-size:.8rem; color:#667085; }
    .rx-status-btns { display:inline-flex; border:1px solid #d0d5dd; border-radius:.5rem; overflow:hidden; }
    .rx-status-btns .btn { border:none; border-radius:0; font-size:.78rem; padding:.3rem .65rem; }
    .rx-status-btns .btn + .btn { border-left:1px solid #d0d5dd; }
    .rx-status-btns .btn.active.dispensed { background:#ecfdf3; color:#027a48; }
    .rx-status-btns .btn.active.partial { background:#fffaeb; color:#b54708; }
    .rx-status-btns .btn.active.not-dispensed { background:#fef3f2; color:#b42318; }
    .rx-qty-input { width:80px; }
    .rx-not-serving-notice {
        background:#fffaeb; border:1px solid #fedf89; color:#93370d; border-radius:.5rem;
        padding:.6rem .9rem; font-size:.85rem; margin-bottom:1rem;
    }
</style>

<div class="page-title">
  <nav class="breadcrumbs">
    <div class="container">
      <ol>
        <li><a href="<?= $dashboardUrl ?>">Dashboard</a></li>
        <li><a href="../list/prescription.php">Prescription List</a></li>
        <li class="current">Queue</li>
      </ol>
    </div>
  </nav>
</div>

<section class="section">
    <div class="container" data-aos="fade-up">
        <div class="pharm-banner">
            <h2 class="text-white">Pharmacy Queue Management</h2>
            <p>Track walk-in and online (E-Pres) patients, and dispense prescriptions from one place.</p>
            <span class="branch-chip"><i class="bi bi-geo-alt-fill"></i> <?= htmlspecialchars($pharmacy['pharmacy_name']) ?></span>
        </div>
    </div>

    <div class="container" data-aos="fade-up" data-aos-delay="100">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0">Today's Queue</h4>
            <div class="d-flex gap-2">
                <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back</a>
                <a href="<?= htmlspecialchars($kioskUrl) ?>" target="_blank" class="btn btn-outline-dark btn-sm"><i class="bi bi-display"></i> Open Kiosk</a>
                <a href="<?= htmlspecialchars($publicMonitorUrl) ?>" target="_blank" class="btn btn-dark btn-sm"><i class="bi bi-tv"></i> Public Monitor</a>
            </div>
        </div>

        <div class="call-next-bar mb-4 d-flex flex-wrap gap-3 align-items-center">
            <strong class="me-2"><i class="bi bi-megaphone-fill me-1" style="color:var(--pharm-teal);"></i> Call Next:</strong>
            <button type="button" class="btn btn-sm btn-primary call-next-btn" data-category="Regular">Regular</button>
            <button type="button" class="btn btn-sm btn-danger call-next-btn" data-category="Priority">Priority</button>
            <span class="text-muted small ms-auto" id="liveStatusText">
                <span class="spinner-grow spinner-grow-sm text-success" style="width:.5rem;height:.5rem;"></span>
                Live
            </span>
        </div>

        <!-- Source / Status Tabs -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="queue-tabs" id="sourceTabs">
                <button type="button" class="tab-btn active" data-tab="all">
                    <i class="bi bi-grid-fill"></i> All <span class="badge-count" id="countAll">0</span>
                </button>
                <button type="button" class="tab-btn" data-tab="walkin">
                    <i class="bi bi-person-walking"></i> Walk-in <span class="badge-count" id="countWalkin">0</span>
                </button>
                <button type="button" class="tab-btn" data-tab="online">
                    <i class="bi bi-cloud-check"></i> Online (E-Pres) <span class="badge-count" id="countOnline">0</span>
                </button>
                <button type="button" class="tab-btn" data-tab="completed">
                    <i class="bi bi-check2-circle"></i> Completed <span class="badge-count" id="countCompleted">0</span>
                </button>
                <button type="button" class="tab-btn" data-tab="unclaimed">
                    <i class="bi bi-person-dash"></i> Unclaimed <span class="badge-count" id="countUnclaimed">0</span>
                </button>
                <button type="button" class="tab-btn" data-tab="cancelled">
                    <i class="bi bi-x-circle"></i> Cancelled <span class="badge-count" id="countCancelled">0</span>
                </button>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="list-toolbar mb-4 mt-3">
            <div class="row g-3 align-items-end">
                <div class="col-md-4 col-sm-6">
                    <label class="form-label">Status Filter</label>
                    <!--
                        Requirement 4: Regular/Priority categories are
                        added to filtering alongside status (kept as its
                        own adjacent dropdown rather than merged into
                        this one, per a later explicit request in this
                        project to split them into two separate
                        controls -- see the Category Filter dropdown
                        next to this one).
                    -->
                    <select id="statusFilter" class="form-select">
                        <option value="">All</option>
                        <option value="Waiting">Waiting</option>
                        <option value="Now Serving">Now Serving</option>
                        <option value="Completed">Completed</option>
                        <option value="Unclaimed">Unclaimed</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-md-4 col-sm-6">
                    <label class="form-label">Category Filter</label>
                    <select id="categoryFilter" class="form-select">
                        <option value="">All</option>
                        <option value="Regular">Regular</option>
                        <option value="Priority">Priority</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Queue Table -->
        <div class="shadow-sm rounded p-2" style="background:#fff;">
            <table id="queueTable" class="table table-bordered align-middle mb-0 w-100">
                <thead class="table-light">
                    <tr>
                        <th>Queue No.</th>
                        <th>Category</th>
                        <th>Source</th>
                        <th>Patient</th>
                        <th>Prescription No.</th>
                        <th>Diagnosis</th>
                        <th>Status</th>
                        <th width="320">Action</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</section>

<!-- ================= DISPENSE MODAL ================= -->
<!-- Opened for E-Pres rows via the "View & Dispense Rx" button. Lets
     pharmacy review the prescription + every medicine on it, decide
     Dispensed / Partial / Not Dispensed per item (with a reason for
     anything short of Dispensed), then either Complete the visit or
     mark it Unclaimed if the patient never came to the counter. -->
<div class="modal fade" id="dispenseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0" id="rxModalTitle"><i class="bi bi-capsule-pill me-1"></i> Prescription</h5>
          <div class="text-muted small" id="rxModalSubtitle"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="rxNotServingNotice" class="rx-not-serving-notice d-none">
          <i class="bi bi-exclamation-triangle-fill"></i>
          This patient isn't "Now Serving" yet. Set them to Now Serving from the queue table before dispensing or marking unclaimed.
        </div>
        <div id="rxLoading" class="text-center text-muted py-4">
          <span class="spinner-border spinner-border-sm"></span> Loading prescription…
        </div>
        <div id="rxContent" class="d-none">
          <div class="rx-info-grid">
            <div><div class="label">Patient</div><div class="value" id="rxPatientName">—</div></div>
            <div><div class="label">Prescription No.</div><div class="value" id="rxNumber">—</div></div>
            <div><div class="label">Diagnosis</div><div class="value" id="rxDiagnosis">—</div></div>
            <div><div class="label">Health Facility</div><div class="value" id="rxFacility">—</div></div>
            <div><div class="label">Physician</div><div class="value" id="rxDoctor">—</div></div>
            <!--<div><div class="label hidd">Refill</div><div class="value" id="rxRefill">—</div></div>-->
          </div>

          <h6 class="mb-2"><i class="bi bi-capsule me-1" style="color:var(--pharm-teal);"></i> Medicines</h6>
          <div id="rxMedicineList"></div>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="rxUnclaimedBtn" disabled>
          <i class="bi bi-person-dash"></i> Mark Unclaimed
        </button>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
          <button type="button" class="btn btn-success btn-sm" id="rxCompleteBtn" disabled>
            <i class="bi bi-check2-circle"></i> Complete Prescription
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script>
/*
 * ================= LIVE QUEUE TABLE (DataTables ajax mode) =================
 * Table data comes from modules/pharmacy/queue_data.php, which now scopes
 * every row to the logged-in staff member's own pharmacy_id server-side —
 * nothing branch-specific needs to be passed from here.
 *
 * IMPORTANT: this expects each row to include a `prescription_number`
 * field (the actual Rx No., e.g. "RX-20260711-001") for E-Pres rows.
 * If your queue_data.php doesn't select that yet, see the note at the
 * bottom of this file / ask for the endpoint to add it.
 */
const POLL_MS = 4000;
const liveStatusText = document.getElementById('liveStatusText');

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

function statusPillHtml(status) {
    const cls = status === 'Waiting' ? 'status-pill-waiting'
              : status === 'Now Serving' ? 'status-pill-serving'
              : status === 'Completed' ? 'status-pill-completed'
              : status === 'Unclaimed' ? 'status-pill-unclaimed'
              : status === 'Cancelled' ? 'status-pill-cancelled'
              : 'status-pill-waiting';
    return `<span class="status-pill ${cls}">${escapeHtml(status)}</span>`;
}

function rxNoHtml(row) {
    if (row.source !== 'E-Pres') return '<span class="rx-no-none">—</span>';
    const rxNo = row.prescription_number || (row.prescription_id ? ('#' + row.prescription_id) : null);
    if (!rxNo) return '<span class="rx-no-none">—</span>';
    return `<span class="rx-no-pill"><i class="bi bi-upc-scan"></i> ${escapeHtml(rxNo)}</span>`;
}

function actionsHtml(row) {
    const isTerminal = row.status === 'Completed' || row.status === 'Unclaimed' || row.status === 'Cancelled';
    const waitingBtnClass = row.status === 'Waiting' ? 'btn-warning' : 'btn-outline-warning';
    const servingBtnClass = row.status === 'Now Serving' ? 'btn-primary' : 'btn-outline-primary';
    const who = escapeHtml(row.who || 'this entry');

    const toggleGroup = `
        <div class="status-toggle">
            <button type="button" class="btn btn-sm ${waitingBtnClass} status-action-btn"
                data-target-status="Waiting" data-queue-id="${row.id}" data-who="${who}"
                ${isTerminal || row.status === 'Waiting' ? 'disabled' : ''}>Waiting</button>
            <button type="button" class="btn btn-sm ${servingBtnClass} status-action-btn"
                data-target-status="Now Serving" data-queue-id="${row.id}" data-who="${who}"
                ${isTerminal || row.status === 'Now Serving' ? 'disabled' : ''}>Now Serving</button>
        </div>
    `;

    // Voids the transaction without a dispense decision — see the
    // Cancel comment block near the top of this file for why it's
    // allowed here for both Walk-in and E-Pres, unlike Completed.
    const cancelBtn = `
        <button type="button" class="btn-cancel-queue cancel-btn" data-queue-id="${row.id}" data-who="${who}" ${isTerminal ? 'disabled' : ''}>
            <i class="bi bi-x-circle"></i> Cancel
        </button>
    `;

    if (row.source === 'E-Pres') {
        // E-Pres rows always dispense through the modal — no one-click
        // Complete here, so every medicine gets an explicit decision.
        const recordLink = row.prescription_id
            ? `<a href="../view/prescription.php?id=${row.prescription_id}" target="_blank"
                class="btn btn-sm btn-view-record" data-bs-toggle="tooltip" title="Open full prescription record">
                <i class="bi bi-file-earmark-text"></i></a>`
            : '';
        return `
            <div class="action-group">
                ${toggleGroup}
                <button type="button" class="btn-dispense dispense-btn" data-queue-id="${row.id}" ${isTerminal ? 'disabled' : ''}>
                    <i class="bi bi-capsule"></i> ${isTerminal ? 'Rx Reviewed' : 'View & Dispense Rx'}
                </button>
                ${cancelBtn}
                ${recordLink}
            </div>
        `;
    }

    return `
        <div class="action-group">
            ${toggleGroup}
            <button type="button" class="btn-complete-walkin complete-btn"
                data-queue-id="${row.id}" data-who="${who}" data-category="${escapeHtml(row.category)}" ${isTerminal ? 'disabled' : ''}>
                <i class="bi bi-check2"></i> Complete
            </button>
            ${cancelBtn}
        </div>
    `;
}

/*
 * ================= FILTERING =================
 * Two independent layers:
 *  1. `activeTab` -- one of the six tab buttons above. All/Walk-in/
 *     Online show only ACTIVE (Waiting/Now Serving) rows -- these are
 *     "live queue" views. Completed/Unclaimed/Cancelled are dedicated,
 *     separate filters per requirement 3, each showing only rows in
 *     that exact status regardless of source.
 *  2. The toolbar's Status Filter + Category Filter dropdowns, which
 *     further narrow whatever the active tab already shows. All three
 *     (tab, status dropdown, category dropdown) combine with AND.
 */
let activeTab = 'all';
let statusFilterValue = '';
let categoryFilterValue = '';

function isHistoryRow(row) {
    return row.status === 'Completed' || row.status === 'Unclaimed' || row.status === 'Cancelled';
}

$.fn.dataTable.ext.search.push(function (settings, searchData, index, rowData) {
    if (settings.nTable.id !== 'queueTable') return true;

    switch (activeTab) {
        case 'all':
            if (isHistoryRow(rowData)) return false;
            break;
        case 'walkin':
            if (isHistoryRow(rowData) || rowData.source === 'E-Pres') return false;
            break;
        case 'online':
            if (isHistoryRow(rowData) || rowData.source !== 'E-Pres') return false;
            break;
        case 'completed':
            if (rowData.status !== 'Completed') return false;
            break;
        case 'unclaimed':
            if (rowData.status !== 'Unclaimed') return false;
            break;
        case 'cancelled':
            if (rowData.status !== 'Cancelled') return false;
            break;
    }

    if (statusFilterValue && rowData.status !== statusFilterValue) return false;
    if (categoryFilterValue && rowData.category !== categoryFilterValue) return false;

    return true;
});

let table;
table = $('#queueTable').DataTable({
    ajax: {
        url: 'queue_data.php',
        dataSrc: 'rows',
        cache: false,
        error: function () {
            liveStatusText.innerHTML = '<span class="spinner-grow spinner-grow-sm text-danger" style="width:.5rem;height:.5rem;"></span> Reconnecting…';
        },
        complete: function () {
            liveStatusText.innerHTML = '<span class="spinner-grow spinner-grow-sm text-success" style="width:.5rem;height:.5rem;"></span> Live';
        }
    },
    responsive: true,
    pageLength: 10,
    lengthMenu: [5, 10, 25, 50, 100],
    ordering: false,
    columns: [
        { data: 'label', render: (data, type) => type === 'display' ? `<span class="queue-number-badge">${escapeHtml(data)}</span>` : data },
        { data: 'category', render: (data, type) => type === 'display' ? `<span class="category-pill ${escapeHtml(data)}">${escapeHtml(data)}</span>` : data },
        {
            data: 'source',
            render: (data, type) => {
                const isOnline = data === 'E-Pres';
                if (type !== 'display') return isOnline ? 'online' : 'walkin';
                return `<span class="source-pill ${isOnline ? 'online' : 'walkin'}">${isOnline ? 'Online (E-Pres)' : 'Walk-in'}</span>`;
            }
        },
        { data: 'who' },
        { data: null, render: (data, type, row) => type === 'display' ? rxNoHtml(row) : (row.prescription_number || '') },
        { data: 'diagnosis', defaultContent: '—' },
        { data: 'status', render: (data, type) => type === 'display' ? statusPillHtml(data) : data },
        { data: null, orderable: false, render: (data, type, row) => type === 'display' ? actionsHtml(row) : '' }
    ],
    language: {
        emptyTable: '<div class="empty-state"><i class="bi bi-inbox"></i>No queue entries for today yet.</div>',
        zeroRecords: '<div class="empty-state"><i class="bi bi-inbox"></i>No matching queue entries.</div>'
    },
    drawCallback: function () {
        $('[data-bs-toggle="tooltip"]').each(function () {
            const existing = bootstrap.Tooltip.getInstance(this);
            if (existing) existing.dispose();
            new bootstrap.Tooltip(this);
        });
        updateTabCounts();
    }
});

// Status Filter and Category Filter — two separate dropdowns, each
// narrowing on its own column independently of the tabs and of each
// other. See statusFilterValue / categoryFilterValue above.
$('#statusFilter').on('change', function () {
    statusFilterValue = this.value;
    table.draw();
});
$('#categoryFilter').on('change', function () {
    categoryFilterValue = this.value;
    table.draw();
});

// Tabs
$('#sourceTabs .tab-btn').on('click', function () {
    $('#sourceTabs .tab-btn').removeClass('active');
    $(this).addClass('active');
    activeTab = $(this).data('tab') || 'all';
    table.draw();
});

function updateTabCounts() {
    if (!table) return; // guards the very first synchronous drawCallback fired during init
    const all = table.data().toArray();
    const activeRows = all.filter(r => !isHistoryRow(r));
    document.getElementById('countAll').textContent = activeRows.length;
    document.getElementById('countWalkin').textContent = activeRows.filter(r => r.source !== 'E-Pres').length;
    document.getElementById('countOnline').textContent = activeRows.filter(r => r.source === 'E-Pres').length;
    document.getElementById('countCompleted').textContent = all.filter(r => r.status === 'Completed').length;
    document.getElementById('countUnclaimed').textContent = all.filter(r => r.status === 'Unclaimed').length;
    document.getElementById('countCancelled').textContent = all.filter(r => r.status === 'Cancelled').length;
}

async function sendStatusChange(queueId, newStatus, reason) {
    const params = { ajax: '1', queue_id: queueId, new_status: newStatus };
    if (reason !== undefined) params.reason = reason;
    await fetch('queue.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(params).toString()
    });
    table.ajax.reload(null, false); // reflect the change immediately instead of waiting for the next tick
}

/*
 * Promotes the earliest Waiting row in `category` (this branch, today)
 * straight to Now Serving — the same action the "Call Next" buttons
 * trigger. Called automatically right after a patient is marked
 * Completed or Unclaimed, so the counter never sits idle waiting for
 * staff to click Call Next themselves. If there's nobody waiting in
 * that category, the backend just no-ops.
 */
async function callNextInCategory(category) {
    if (!category) return;
    await fetch('queue.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `ajax=1&call_next_category=${encodeURIComponent(category)}`
    });
}

// Event delegation on the table body — rows get redrawn on every ajax reload
$('#queueTable tbody').on('click', '.status-action-btn', function () {
    if (this.disabled) return;
    const queueId = this.dataset.queueId;
    const who = this.dataset.who || 'this entry';
    const targetStatus = this.dataset.targetStatus;
    const btn = this;

    Swal.fire({
        title: 'Confirm status change',
        html: `Set <strong>${who}</strong> to <strong>${escapeHtml(targetStatus)}</strong>?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, update',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#175cd3',
    }).then(async (result) => {
        if (!result.isConfirmed) return;
        btn.disabled = true;
        try {
            await sendStatusChange(queueId, targetStatus);
        } finally {
            btn.disabled = false;
        }
    });
});

$('#queueTable tbody').on('click', '.complete-btn', function () {
    if (this.disabled) return;
    const queueId = this.dataset.queueId;
    const who = this.dataset.who || 'this entry';
    const category = this.dataset.category;
    const btn = this;

    Swal.fire({
        title: 'Mark as Completed?',
        html: `Mark <strong>${who}</strong> as Completed? The next ${escapeHtml(category || '')} patient will be called automatically.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, complete',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#12b76a',
    }).then(async (result) => {
        if (!result.isConfirmed) return;
        btn.disabled = true;
        try {
            await sendStatusChange(queueId, 'Completed');
            await callNextInCategory(category);
            table.ajax.reload(null, false);
        } finally {
            btn.disabled = false;
        }
    });
});

/*
 * Cancel — voids a queued transaction (Waiting or Now Serving, any
 * source). Requirement 2: confirmation prompt before proceeding, then
 * marks the row Cancelled (no longer active), flips medicine_status to
 * Cancelled when a prescription is attached, and logs the change for
 * audit. The reason field is optional (same pattern as "Mark
 * Unclaimed" below) since the requirement only calls for a
 * confirmation prompt, not a mandatory reason.
 */
$('#queueTable tbody').on('click', '.cancel-btn', async function () {
    if (this.disabled) return;
    const queueId = this.dataset.queueId;
    const who = this.dataset.who || 'this entry';
    const btn = this;

    const { value: reason, isConfirmed } = await Swal.fire({
        title: 'Cancel this transaction?',
        html: `This voids <strong>${who}</strong>'s queue entry. It will no longer be an active transaction.`,
        input: 'textarea',
        inputPlaceholder: 'Optional note (e.g. "patient changed their mind")',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, cancel it',
        cancelButtonText: 'Back',
        confirmButtonColor: '#b42318',
    });
    if (!isConfirmed) return;

    btn.disabled = true;
    try {
        await sendStatusChange(queueId, 'Cancelled', (reason || '').trim());
    } finally {
        btn.disabled = false;
    }
});

/*
 * ================= DISPENSE MODAL (E-Pres rows) =================
 * Fetches the full prescription + medicines from prescription_detail.php,
 * lets pharmacy set a Dispensed / Partial / Not Dispensed decision (with
 * a reason for anything short of Dispensed) per medicine, then submits
 * the whole batch to prescription_action.php as either action=complete
 * or action=unclaimed.
 */
const dispenseModalEl = document.getElementById('dispenseModal');
const dispenseModal = new bootstrap.Modal(dispenseModalEl);
let currentQueueId = null;
let currentQueueStatus = null;
let currentQueueCategory = null;

const STATUS_OPTIONS = [
    { value: 'Dispensed',     label: 'Dispensed',     cls: 'dispensed' },
    { value: 'Partial',       label: 'Partial',       cls: 'partial' },
    { value: 'Not Dispensed', label: 'Not Dispensed', cls: 'not-dispensed' },
];

function medicineCardHtml(med) {
    const savedStatus = med.pharmacy_status && med.pharmacy_status !== 'Pending' ? med.pharmacy_status : 'Dispensed';
    const savedQty = med.dispensed_quantity !== null ? med.dispensed_quantity : med.quantity;
    const btns = STATUS_OPTIONS.map(opt => `
        <button type="button" class="btn med-status-btn ${opt.cls} ${savedStatus === opt.value ? 'active ' + opt.cls : ''}"
            data-med-id="${med.id}" data-value="${opt.value}">${opt.label}</button>
    `).join('');

    return `
        <div class="rx-medicine-card" data-med-id="${med.id}" data-max-qty="${med.quantity}">
            <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
                <div>
                    <div class="med-name">${escapeHtml(med.medicine_name)}</div>
                    <div class="med-meta">${escapeHtml(med.dosage || '—')} · ${escapeHtml(med.frequency || '—')} · ${escapeHtml(med.duration || '—')} · Prescribed qty: ${med.quantity}</div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <label class="small text-muted mb-0">Qty to dispense</label>
                    <input type="number" class="form-control form-control-sm rx-qty-input med-qty-input"
                        min="0" max="${med.quantity}" value="${savedQty}">
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <div class="rx-status-btns">${btns}</div>
            </div>
            <textarea class="form-control form-control-sm mt-2 med-reason-input ${savedStatus === 'Dispensed' ? 'd-none' : ''}"
                rows="2" placeholder="Reason (required for Partial / Not Dispensed)">${escapeHtml(med.pharmacy_reason || '')}</textarea>
        </div>
    `;
}

function refreshMedicineButtonState(card, selectedValue) {
    card.querySelectorAll('.med-status-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.value === selectedValue);
    });
    const reasonInput = card.querySelector('.med-reason-input');
    reasonInput.classList.toggle('d-none', selectedValue === 'Dispensed');
}

async function openDispenseModal(queueId) {
    currentQueueId = queueId;
    document.getElementById('rxLoading').classList.remove('d-none');
    document.getElementById('rxContent').classList.add('d-none');
    document.getElementById('rxNotServingNotice').classList.add('d-none');
    document.getElementById('rxCompleteBtn').disabled = true;
    document.getElementById('rxUnclaimedBtn').disabled = true;
    dispenseModal.show();

    try {
        const res = await fetch(`prescription_detail.php?queue_id=${encodeURIComponent(queueId)}`);
        const data = await res.json();
        if (data.status !== 'success') {
            dispenseModal.hide();
            Swal.fire({ icon: 'error', title: 'Could not load prescription', text: data.message || 'Unknown error.' });
            return;
        }

        currentQueueStatus = data.queue.status;
        currentQueueCategory = data.queue.category;
        const pr = data.prescription;

        document.getElementById('rxModalTitle').innerHTML = `<i class="bi bi-capsule-pill me-1"></i> ${escapeHtml(pr.prescription_number || 'Prescription')}`;
        document.getElementById('rxModalSubtitle').textContent = `Queue ${data.queue.category} · ${data.queue.status}`;
        document.getElementById('rxPatientName').textContent = pr.patient_name || '—';
        document.getElementById('rxNumber').textContent = pr.prescription_number || '—';
        document.getElementById('rxDiagnosis').textContent = pr.diagnosis || '—';
        document.getElementById('rxFacility').textContent = pr.facility_name || '—';
        document.getElementById('rxDoctor').textContent = pr.doctor_name || '—';
        // document.getElementById('rxRefill').textContent = pr.is_refill === 'YES' ? 'Yes' : 'No';

        document.getElementById('rxMedicineList').innerHTML = data.medicines.map(medicineCardHtml).join('');

        const isServing = currentQueueStatus === 'Now Serving';
        document.getElementById('rxNotServingNotice').classList.toggle('d-none', isServing);
        document.getElementById('rxCompleteBtn').disabled = !isServing;
        document.getElementById('rxUnclaimedBtn').disabled = !isServing;

        document.getElementById('rxLoading').classList.add('d-none');
        document.getElementById('rxContent').classList.remove('d-none');
    } catch (e) {
        dispenseModal.hide();
        Swal.fire({ icon: 'error', title: 'Connection error', text: 'Could not load the prescription. Please try again.' });
    }
}

$('#queueTable tbody').on('click', '.dispense-btn', function () {
    if (this.disabled) return;
    openDispenseModal(this.dataset.queueId);
});

// Per-medicine status button clicks (event delegation, since cards are
// re-rendered on every modal open)
document.getElementById('rxMedicineList').addEventListener('click', function (e) {
    const btn = e.target.closest('.med-status-btn');
    if (!btn) return;
    const card = btn.closest('.rx-medicine-card');
    refreshMedicineButtonState(card, btn.dataset.value);

    // Dispensed defaults the quantity back to the full prescribed
    // amount; switching away from Dispensed doesn't touch whatever
    // quantity the pharmacist already typed.
    if (btn.dataset.value === 'Dispensed') {
        const qtyInput = card.querySelector('.med-qty-input');
        qtyInput.value = card.dataset.maxQty;
    }
});

// Typing a quantity below the prescribed amount nudges the status to
// Partial automatically (pharmacist can still override it back).
document.getElementById('rxMedicineList').addEventListener('input', function (e) {
    if (!e.target.classList.contains('med-qty-input')) return;
    const card = e.target.closest('.rx-medicine-card');
    const maxQty = parseInt(card.dataset.maxQty, 10);
    let qty = parseInt(e.target.value, 10);
    if (isNaN(qty)) qty = 0;
    qty = Math.max(0, Math.min(qty, maxQty));
    e.target.value = qty;

    const activeBtn = card.querySelector('.med-status-btn.active');
    const currentValue = activeBtn ? activeBtn.dataset.value : null;
    if (qty === maxQty && currentValue !== 'Dispensed') {
        refreshMedicineButtonState(card, 'Dispensed');
    } else if (qty < maxQty && currentValue === 'Dispensed') {
        refreshMedicineButtonState(card, qty === 0 ? 'Not Dispensed' : 'Partial');
    }
});

function collectMedicinePayload() {
    const cards = document.querySelectorAll('#rxMedicineList .rx-medicine-card');
    const payload = [];
    for (const card of cards) {
        const activeBtn = card.querySelector('.med-status-btn.active');
        const status = activeBtn ? activeBtn.dataset.value : null;
        const reason = card.querySelector('.med-reason-input').value.trim();
        const qty = parseInt(card.querySelector('.med-qty-input').value, 10) || 0;

        if (!status) {
            Swal.fire({ icon: 'warning', title: 'Missing status', text: 'Every medicine needs a Dispensed / Partial / Not Dispensed status.' });
            return null;
        }
        if (status !== 'Dispensed' && reason === '') {
            Swal.fire({ icon: 'warning', title: 'Reason required', text: `Please add a reason for medicines marked ${status}.` });
            return null;
        }
        payload.push({ id: card.dataset.medId, pharmacy_status: status, pharmacy_reason: reason, dispensed_quantity: qty });
    }
    return payload;
}

document.getElementById('rxCompleteBtn').addEventListener('click', async function () {
    const payload = collectMedicinePayload();
    if (!payload) return;

    const btn = this;
    btn.disabled = true;
    try {
        const res = await fetch('prescription_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=complete&queue_id=${encodeURIComponent(currentQueueId)}&medicines=${encodeURIComponent(JSON.stringify(payload))}`
        });
        const data = await res.json();
        if (data.status !== 'success') {
            Swal.fire({ icon: 'error', title: 'Could not complete', text: data.message || 'Unknown error.' });
            btn.disabled = false;
            return;
        }
        dispenseModal.hide();
        await callNextInCategory(currentQueueCategory);
        table.ajax.reload(null, false);
        Swal.fire({ icon: 'success', title: `Marked as ${data.outcome}`, timer: 1500, showConfirmButton: false });
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Connection error', text: 'Please try again.' });
        btn.disabled = false;
    }
});

document.getElementById('rxUnclaimedBtn').addEventListener('click', async function () {
    const { value: reason, isConfirmed } = await Swal.fire({
        title: 'Mark as Unclaimed?',
        html: 'The patient was called but never came to the counter. This closes the queue entry; the prescription stays valid for them to queue again.',
        input: 'textarea',
        inputPlaceholder: 'Optional note (e.g. "called 3x, no response")',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, mark Unclaimed',
        confirmButtonColor: '#475467',
    });
    if (!isConfirmed) return;

    const btn = this;
    btn.disabled = true;
    try {
        const res = await fetch('prescription_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=unclaimed&queue_id=${encodeURIComponent(currentQueueId)}&reason=${encodeURIComponent(reason || '')}`
        });
        const data = await res.json();
        if (data.status !== 'success') {
            Swal.fire({ icon: 'error', title: 'Could not update', text: data.message || 'Unknown error.' });
            btn.disabled = false;
            return;
        }
        dispenseModal.hide();
        await callNextInCategory(currentQueueCategory);
        table.ajax.reload(null, false);
        Swal.fire({ icon: 'success', title: 'Marked as Unclaimed', timer: 1500, showConfirmButton: false });
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Connection error', text: 'Please try again.' });
        btn.disabled = false;
    }
});

// Call Next buttons
document.querySelectorAll('.call-next-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        const category = this.dataset.category;
        Swal.fire({
            title: 'Call next patient?',
            html: `Promote the next <strong>${escapeHtml(category)}</strong> patient in line to Now Serving?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, call',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#175cd3',
        }).then(async (result) => {
            if (!result.isConfirmed) return;
            this.disabled = true;
            try {
                await fetch('queue.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `ajax=1&call_next_category=${encodeURIComponent(category)}`
                });
                table.ajax.reload(null, false);
            } finally {
                this.disabled = false;
            }
        });
    });
});

// Pause polling while the tab is backgrounded
let pollInterval = setInterval(() => table.ajax.reload(null, false), POLL_MS);
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        if (pollInterval) clearInterval(pollInterval);
        liveStatusText.textContent = 'Paused (tab inactive)';
    } else {
        table.ajax.reload(null, false);
        pollInterval = setInterval(() => table.ajax.reload(null, false), POLL_MS);
    }
});
</script>

<?php include '../../includes/footer.php'; ?>