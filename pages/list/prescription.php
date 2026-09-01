<?php
session_start();
require '../../config/db.php';
// new prescription list
/* ================= AUTH ================= */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['health_facility', 'doctor', 'nurse', 'pharmacy'])) {
    header("Location: ../../index.php");
    exit;
}
 /* ---------- ROLE → DASHBOARD MAP ---------- */
    function getDashboardUrl(string $role): string {
        return match ($role) {
            'doctor'          => '../doctor/index.php',
            'nurse'           => '../nurse/index.php',
            'health_facility' => '../health_facility/index.php',
            'pharmacy'        => '../pharmacy/index.php',
            'admin'           => '../admin/index.php',
            default           => '../../login.php',
        };
    }
    /* ---------- ACCESS CHECK ---------- */
$isHealthFacility = $_SESSION['user']['role'] === 'health_facility';
$isDoctor = $_SESSION['user']['role'] === 'doctor';
$isNurse = $_SESSION['user']['role'] === 'nurse';
$isPharmacy = $_SESSION['user']['role'] === 'pharmacy';
$showForbiddenModal = !$isHealthFacility && !$isDoctor && !$isNurse && !$isPharmacy;
$dashboardUrl = getDashboardUrl($_SESSION['user']['role']);
$facilityId = $_SESSION['user']['facility_id'] ?? null;
$pharmacyId = $_SESSION['user']['pharmacy_id'] ?? null;

/* ---------- CENTRALIZED ACCESS ----------
   Prescriptions are centralized across health_facility, doctor, and
   nurse — all three see prescriptions from every facility, not just
   their own, matching the same centralized access already given for
   patient records. pharmacy keeps its own separate pharmacy-partner
   scoping below, which is intentionally a different kind of access,
   not facility ownership. */
$isCentralized = $isHealthFacility || $isDoctor || $isNurse;

/* ================= FETCH PRESCRIPTIONS ================= */
$prescriptions = [];
$facilityColumnAvailable = true; // tracks whether we could resolve facility names for the centralized/pharmacy views

if ($isPharmacy) {
    /*
     * Pharmacy sees SIGNED prescriptions, scoped to the health facilities
     * that belong to THIS pharmacy (health_facilities.pharmacy_id ==
     * the logged-in pharmacy user's pharmacy_id) — not every facility
     * in the system.
     *
     * If the logged-in pharmacy account doesn't have a pharmacy_id yet
     * (e.g. it was created before the pharmacy_id column existed and
     * hasn't been re-saved), we fall back to showing all signed
     * prescriptions rather than showing an empty list.
     *
     * The join itself is wrapped in try/catch on purpose: if a table or
     * column name doesn't match your actual schema, this used to throw
     * a fatal PDOException and 500 the whole page. Now it falls back to
     * the join-free query below so pharmacy still gets a working list —
     * just without the Facility column and without pharmacy scoping —
     * instead of a hard crash.
     */
    try {
        $sql = "
            SELECT p.id, p.diagnosis, p.status, p.created_at, p.transmitted_at,
                   pat.first_name, pat.last_name, pat.his_id,
                   hf.facility_name AS facility_name
            FROM prescriptions p
            JOIN patients pat ON p.patient_id = pat.id
            LEFT JOIN health_facilities hf ON p.facility_id = hf.id
            WHERE p.status = 'Signed'
        ";

        $params = [];

        if (!empty($pharmacyId)) {
            $sql .= " AND hf.pharmacy_id = ?";
            $params[] = $pharmacyId;
        }

        $sql .= " ORDER BY p.transmitted_at DESC, p.created_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Log this somewhere you'll actually see it — table/column name
        // mismatch is the most likely cause. Falling back below so the
        // page still renders instead of a 500.
        error_log('Pharmacy prescription list facilities join failed: ' . $e->getMessage());
        $facilityColumnAvailable = false;

        $stmt = $conn->prepare("
            SELECT p.id, p.diagnosis, p.status, p.created_at, p.transmitted_at,
                   pat.first_name, pat.last_name, pat.his_id
            FROM prescriptions p
            JOIN patients pat ON p.patient_id = pat.id
            WHERE p.status = 'Signed'
            ORDER BY p.transmitted_at DESC, p.created_at DESC
        ");
        $stmt->execute();
        $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($isCentralized) {
    /*
     * health_facility / doctor / nurse: centralized — every
     * prescription across every facility, all statuses, with a
     * Facility column so it's clear which facility each one belongs
     * to. Same try/catch-with-fallback pattern as the pharmacy branch
     * above, in case the facilities join doesn't match your actual
     * schema — falls back to a working (if unscoped-looking) list
     * instead of a hard 500.
     */
    try {
        $stmt = $conn->prepare("
            SELECT p.id, p.diagnosis, p.status, p.created_at, p.transmitted_at,
                   pat.first_name, pat.last_name, pat.his_id,
                   hf.facility_name AS facility_name
            FROM prescriptions p
            JOIN patients pat ON p.patient_id = pat.id
            LEFT JOIN health_facilities hf ON p.facility_id = hf.id
            ORDER BY p.created_at DESC
        ");
        $stmt->execute();
        $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Centralized prescription list facilities join failed: ' . $e->getMessage());
        $facilityColumnAvailable = false;

        $stmt = $conn->prepare("
            SELECT p.id, p.diagnosis, p.status, p.created_at, p.transmitted_at,
                   pat.first_name, pat.last_name, pat.his_id
            FROM prescriptions p
            JOIN patients pat ON p.patient_id = pat.id
            ORDER BY p.created_at DESC
        ");
        $stmt->execute();
        $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
/*
 * No remaining else branch: every allowed role (pharmacy, and now
 * health_facility/doctor/nurse via $isCentralized) is covered by one
 * of the branches above. Kept as a plain if/elseif rather than
 * re-adding a catch-all else, so a future new role without an
 * explicit branch fails loudly (empty $prescriptions) instead of
 * silently inheriting old facility-scoped behavior by accident.
 */

// Show the Facility column/filter for pharmacy OR centralized (health_facility/doctor/nurse) views —
// only when we actually have facility_name data to show.
$showFacilityColumn = ($isPharmacy || $isCentralized) && $facilityColumnAvailable;

include '../../includes/header.php';

?>

<!-- DataTables CSS -->
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<style>
    /* ===== List page refinements (shared visual language with Transmittal) ===== */
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
    #prescriptionTable thead th {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: .03em;
        color: #667085;
        font-weight: 700;
        border-bottom-width: 1px;
        white-space: nowrap;
    }
    #prescriptionTable tbody td {
        vertical-align: middle;
        font-size: 0.92rem;
    }
    #prescriptionTable tbody tr:hover {
        background-color: #f9fafb;
    }
    .status-pill {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        font-weight: 600;
        font-size: 0.8rem;
        padding: .35rem .7rem;
        border-radius: 0.5rem;
        white-space: nowrap;
    }
    .status-pill i { font-size: 0.8rem; }
    .status-pill-success { background:#ecfdf3; color:#027a48; border:1px solid #abefc6; }
    .status-pill-warning { background:#fffaeb; color:#b54708; border:1px solid #fedf89; }
    .status-pill-danger  { background:#fef3f2; color:#b42318; border:1px solid #fecdca; }
    .status-pill-secondary { background:#f2f4f7; color:#344054; border:1px solid #eaecf0; }
    .date-pill {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        background: #f9fafb;
        color: #475467;
        border: 1px solid #eaecf0;
        font-weight: 500;
        font-size: 0.8rem;
        padding: .35rem .65rem;
        border-radius: 0.5rem;
        white-space: nowrap;
    }
    .facility-pill {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        background: #eff8ff;
        color: #175cd3;
        border: 1px solid #b2ddff;
        font-weight: 600;
        font-size: 0.8rem;
        padding: .35rem .65rem;
        border-radius: 0.5rem;
        white-space: nowrap;
    }
    .empty-state {
        text-align: center;
        color: #98a2b3;
        padding: 2rem 1rem;
    }
    .empty-state i { font-size: 1.75rem; display: block; margin-bottom: .5rem; }
</style>

<!-- Page Title -->
<div class="page-title">
  <nav class="breadcrumbs">
    <div class="container">
      <ol>
        <li><a href="<?= $dashboardUrl ?>">Dashboard</a></li>
        <li class="current">Prescription List</li>
      </ol>
    </div>
  </nav>
</div><!-- End Page Title -->

<?php if ($isHealthFacility || $isDoctor || $isNurse || $isPharmacy): ?>
<!-- Prescriptions Section -->
<section id="prescriptions" class="prescriptions section">

    <div class="container" data-aos="fade-up" data-aos-delay="100">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0">Prescription List</h4>
            <div class="d-flex gap-2">
                <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                <?php if ($isHealthFacility): ?>
                <a href="../add/prescription.php" class="btn btn-dark btn-sm">
                    <i class="bi bi-plus-lg"></i> Add New Prescription
                </a>
                <?php endif; ?>
                <!--
                    NEW: Bulk printing. Only Signed prescriptions can ever
                    be selected (checkbox is disabled otherwise — see the
                    row markup below), matching the same rule already
                    enforced for single-prescription printing (the Print
                    button on Prescription Details only appears once
                    status === 'Signed'). Disabled until at least one row
                    is checked; the count updates live via JS.
                -->
                <button type="button" id="printSelectedBtn" class="btn btn-outline-primary btn-sm" disabled>
                    <i class="bi bi-printer"></i> Print Selected (<span id="selectedCount">0</span>)
                </button>
            </div>
        </div>

        <!-- Toolbar -->
        <?php if (!$isPharmacy && !$isCentralized): ?>
        <div class="list-toolbar mb-4">
            <div class="row g-3 align-items-end">
                <div class="col-md-4 col-sm-6">
                    <label class="form-label">Status Filter</label>
                    <select id="statusFilter" class="form-select">
                        <option value="">All</option>
                        <option value="For Signing">For Signing</option>
                        <option value="Signed">Signed</option>
                        <option value="Denied">Denied</option>
                    </select>
                </div>
            </div>
        </div>
        <?php elseif ($isCentralized && $showFacilityColumn): ?>
        <!-- Centralized view (health_facility/doctor/nurse): both filters,
             since they now see every facility's prescriptions across all
             statuses. -->
        <div class="list-toolbar mb-4">
            <div class="row g-3 align-items-end">
                <div class="col-md-4 col-sm-6">
                    <label class="form-label">Status Filter</label>
                    <select id="statusFilter" class="form-select">
                        <option value="">All</option>
                        <option value="For Signing">For Signing</option>
                        <option value="Signed">Signed</option>
                        <option value="Denied">Denied</option>
                    </select>
                </div>
                <div class="col-md-4 col-sm-6">
                    <label class="form-label">Facility Filter</label>
                    <select id="facilityFilter" class="form-select">
                        <option value="">All Facilities</option>
                        <?php
                        $facilityNames = array_unique(array_filter(array_column($prescriptions, 'facility_name')));
                        sort($facilityNames);
                        foreach ($facilityNames as $fname):
                        ?>
                            <option value="<?= htmlspecialchars($fname) ?>"><?= htmlspecialchars($fname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <?php elseif ($isPharmacy && $showFacilityColumn): ?>
        <div class="list-toolbar mb-4">
            <div class="row g-3 align-items-end">
                <div class="col-md-4 col-sm-6">
                    <label class="form-label">Facility Filter</label>
                    <select id="facilityFilter" class="form-select">
                        <option value="">All Facilities</option>
                        <?php
                        $facilityNames = array_unique(array_filter(array_column($prescriptions, 'facility_name')));
                        sort($facilityNames);
                        foreach ($facilityNames as $fname):
                        ?>
                            <option value="<?= htmlspecialchars($fname) ?>"><?= htmlspecialchars($fname) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Prescription Table -->
        <div class="shadow-sm rounded p-2" style="background:#fff;">
            <table id="prescriptionTable" class="table table-bordered align-middle mb-0 w-100">
                <thead class="table-light">
                    <tr>
                        <th width="30">
                            <input type="checkbox" id="selectAllPres" title="Select all Signed prescriptions on this page">
                        </th>
                        <th>ID</th>
                        <th>Patient</th>
                        <?php if ($showFacilityColumn): ?>
                        <th>Facility</th>
                        <?php endif; ?>
                        <th>Diagnosis</th>
                        <th>Status</th>
                        <th>Created At</th>
                        <th>Transmitted At</th>
                        <th width="100">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    /*
                     * IMPORTANT: do NOT render a static "no data" <tr> here.
                     * DataTables counts actual <td> elements per row against
                     * the <thead> column count when it initializes on
                     * existing markup — it does not expand colspan for that
                     * check. A single <td colspan="7|8"> row (1 real <td>)
                     * against a 7-8 column <thead> triggers:
                     *   "DataTables warning ... Incorrect column count"
                     *
                     * Instead, just emit zero <tr> rows when $prescriptions
                     * is empty and let DataTables' own `language.emptyTable`
                     * message (configured in the script below) handle the
                     * empty state. That message is rendered by DataTables
                     * itself with the correct colspan, so there's no mismatch.
                     */
                    foreach ($prescriptions as $pres):
                        $patientName = $pres['first_name'].' '.$pres['last_name'];

                        $pillClass = 'status-pill-secondary';
                        if ($pres['status'] === 'For Signing') $pillClass = 'status-pill-warning';
                        elseif ($pres['status'] === 'Signed') $pillClass = 'status-pill-success';
                        elseif ($pres['status'] === 'Denied') $pillClass = 'status-pill-danger';

                        $createdTs = strtotime($pres['created_at']);
                        $transmittedTs = $pres['transmitted_at'] ? strtotime($pres['transmitted_at']) : 0;
                    ?>
                        <tr>
                            <td>
                                <?php if ($pres['status'] === 'Signed'): ?>
                                    <input type="checkbox" class="presSelectChk" value="<?= $pres['id'] ?>">
                                <?php else: ?>
                                    <input type="checkbox" disabled
                                           data-bs-toggle="tooltip" data-bs-placement="top"
                                           title="Only Signed prescriptions can be printed">
                                <?php endif; ?>
                            </td>
                            <td><?= $pres['id'] ?></td>
                            <td><?= htmlspecialchars($patientName) ?></td>
                            <?php if ($showFacilityColumn): ?>
                            <td>
                                <span class="facility-pill"><?= htmlspecialchars($pres['facility_name'] ?? 'Unknown Facility') ?></span>
                            </td>
                            <?php endif; ?>
                            <td><?= htmlspecialchars($pres['diagnosis']) ?></td>
                            <td>
                                <span class="status-pill <?= $pillClass ?>">
                                    <?= htmlspecialchars($pres['status']) ?>
                                </span>
                            </td>
                            <td data-order="<?= $createdTs ?>">
                                <span class="date-pill"><?= date('M d, Y h:i A', $createdTs) ?></span>
                            </td>
                            <td data-order="<?= $transmittedTs ?>">
                                <?php if ($pres['transmitted_at']): ?>
                                    <span class="date-pill"><?= date('M d, Y h:i A', $transmittedTs) ?></span>
                                <?php else: ?>
                                    <span class="status-pill status-pill-danger">Not Transmitted</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="../view/prescription.php?id=<?= $pres['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary"
                                   data-bs-toggle="tooltip"
                                   data-bs-placement="top"
                                   title="View Prescription Details">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
</section><!-- /Prescriptions Section -->
<?php endif; ?>

<!-- ================= ACCESS DENIED MODAL ================= -->
<?php if ($showForbiddenModal): ?>
    <div class="modal fade" id="forbiddenModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-danger">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Access Denied</h5>
                </div>
                <div class="modal-body text-center">
                    <p class="fw-semibold mb-1">You are not authorized to view this page.</p>
                </div>
                <div class="modal-footer justify-content-center">
                    <a href="<?= $dashboardUrl ?>" class="btn btn-danger">
                        Return to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        new bootstrap.Modal(
            document.getElementById('forbiddenModal'),
            { backdrop: 'static', keyboard: false }
        ).show();
    });
    </script>
<?php endif; ?>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script>
$(function () {

    const showFacilityColumn = <?= $showFacilityColumn ? 'true' : 'false' ?>;
    const isPharmacy = <?= $isPharmacy ? 'true' : 'false' ?>;
    const isCentralized = <?= $isCentralized ? 'true' : 'false' ?>;
    // Columns shift by 1 for the new checkbox column, and again by 1 more
    // when the Facility column is present.
    const checkboxColIndex = 0;
    const statusColIndex = showFacilityColumn ? 5 : 4;
    const dateColIndex = showFacilityColumn ? 6 : 5;
    const actionColIndex = showFacilityColumn ? 8 : 7;
    const facilityColIndex = showFacilityColumn ? 3 : null;

    // Empty-state message now lives entirely here instead of as static
    // PHP-rendered <tr> markup, so DataTables renders it itself with the
    // correct colspan — this is what avoids the "Incorrect column count"
    // warning when there are zero prescriptions.
    const emptyMessage = isPharmacy
        ? '<div class="empty-state"><i class="bi bi-inbox"></i>No signed prescriptions found.</div>'
        : '<div class="empty-state"><i class="bi bi-inbox"></i>No prescriptions found.</div>';

    /* =========================================================
       BULK PRINTING — declared BEFORE the DataTable initialization
       below on purpose. drawCallback fires synchronously as part of
       the very first .DataTable() call (the initial draw), and it
       calls syncSelectAllState() which reads selectedIds — if that
       const were declared further down the script (after the table
       init, where it's logically grouped with the rest of the bulk-
       print code), that first synchronous drawCallback would run
       before the `const selectedIds = ...` line had executed,
       throwing "Cannot access 'selectedIds' before initialization"
       (a temporal dead zone error, not a hoisting issue — function
       declarations ARE hoisted, but const/let bindings are not
       initialized until their own line runs).
       Selection state is tracked as a plain Set of prescription IDs,
       independent of pagination — checking a row on page 1, then
       moving to page 2 and checking more rows there, keeps ALL of
       them selected rather than only whatever's on the current page.
    ========================================================= */
    const selectedIds = new Set();

    function updatePrintButtonState() {
        $('#selectedCount').text(selectedIds.size);
        $('#printSelectedBtn').prop('disabled', selectedIds.size === 0);
    }

    function syncSelectAllState() {
        const enabledBoxes = $('#prescriptionTable tbody .presSelectChk');
        if (enabledBoxes.length === 0) {
            $('#selectAllPres').prop('checked', false).prop('indeterminate', false);
            return;
        }
        const checkedCount = enabledBoxes.filter(function () {
            return selectedIds.has(String($(this).val()));
        }).length;

        $('#selectAllPres').prop('checked', checkedCount === enabledBoxes.length);
        $('#selectAllPres').prop('indeterminate', checkedCount > 0 && checkedCount < enabledBoxes.length);
    }

    let table = $('#prescriptionTable').DataTable({
        responsive: true,
        pageLength: 10,
        lengthMenu: [5, 10, 25, 50, 100],
        // Created At column carries a data-order="<unix timestamp>" attribute,
        // so this sorts by the real datetime instead of the formatted display
        // string (which sorted alphabetically, not chronologically).
        order: [[dateColIndex, 'desc']],
        columnDefs: [
            { orderable: false, searchable: false, targets: checkboxColIndex, responsivePriority: 1 },
            { orderable: false, targets: actionColIndex, responsivePriority: 2 }
        ],
        language: {
            emptyTable: emptyMessage,
            zeroRecords: '<div class="empty-state"><i class="bi bi-inbox"></i>No matching prescriptions.</div>'
        },
        drawCallback: function () {
            $('[data-bs-toggle="tooltip"]').each(function () {
                let existing = bootstrap.Tooltip.getInstance(this);
                if (existing) existing.dispose();
                new bootstrap.Tooltip(this);
            });
            // "Select All" only ever reflects the CURRENT page/filtered
            // view, so it needs to be re-synced after every redraw
            // (paging, sorting, filtering all fire this).
            syncSelectAllState();
        }
    });

    // Status filter now applies for health_facility (unscoped view) AND
    // the centralized doctor/nurse view — both render this dropdown.
    $('#statusFilter').on('change', function () {
        table.column(statusColIndex).search($(this).val()).draw();
    });

    if (showFacilityColumn) {
        $('#facilityFilter').on('change', function () {
            table.column(facilityColIndex).search($(this).val() ? '^' + $.fn.dataTable.util.escapeRegex($(this).val()) + '$' : '', true, false).draw();
        });
    }

    /* =========================================================
       BULK PRINTING — event wiring. (selectedIds, updatePrintButtonState(),
       and syncSelectAllState() are declared earlier, above the
       DataTable initialization — see the comment there for why.)
    ========================================================= */

    // Event delegation — rows are redrawn on every page/sort/filter, so
    // this must be bound on the table body, not the individual checkboxes.
    $('#prescriptionTable tbody').on('change', '.presSelectChk', function () {
        const id = String($(this).val());
        if (this.checked) {
            selectedIds.add(id);
        } else {
            selectedIds.delete(id);
        }
        updatePrintButtonState();
        syncSelectAllState();
    });

    // "Select All" only affects rows currently visible on THIS page —
    // standard DataTables convention (matches how most admin UIs treat
    // per-page select-all), not every row across every page.
    $('#selectAllPres').on('change', function () {
        const checked = this.checked;
        $('#prescriptionTable tbody .presSelectChk').each(function () {
            $(this).prop('checked', checked);
            const id = String($(this).val());
            if (checked) {
                selectedIds.add(id);
            } else {
                selectedIds.delete(id);
            }
        });
        updatePrintButtonState();
    });

    $('#printSelectedBtn').on('click', function () {
        if (selectedIds.size === 0) return;

        const params = new URLSearchParams();
        selectedIds.forEach(function (id) {
            params.append('ids[]', id);
        });

        const btn = $(this);
        btn.prop('disabled', true);

        const bulkUrl = '../../print/prescription/bulk.php?' + params.toString();

        // FIX: the new tab is opened HERE, synchronously, during the
        // actual click — most browsers' popup blockers only reliably
        // allow window.open() when it happens directly inside a user
        // gesture's call stack. Opening it later, inside the fetch()
        // .then() below, risks being silently blocked in some browsers
        // since that callback runs asynchronously. The tab starts blank
        // and gets redirected to the real PDF (or closed) once the
        // precheck resolves.
        const newTab = window.open('', '_blank');

        // FIX: precheck first via fetch() instead of directly loading the
        // PDF URL. Without this, a selection that turns out to be empty/
        // unauthorized (e.g. a prescription got transmitted or its status
        // changed in the moment between listing and clicking Print)
        // showed a raw, unstyled die() message in a separate tab —
        // jarring and easy to miss. Now that case closes the blank tab
        // and shows a normal SweetAlert here on the list page instead.
        fetch(bulkUrl + '&precheck=1')
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    if (newTab) {
                        newTab.location.href = bulkUrl;
                    } else {
                        // Popup was blocked despite the synchronous
                        // open() attempt (can still happen depending on
                        // browser settings) — fall back to a normal
                        // same-tab navigation instead of silently doing
                        // nothing.
                        window.location.href = bulkUrl;
                    }
                } else {
                    if (newTab) newTab.close();
                    Swal.fire({
                        icon: 'warning',
                        title: 'Nothing to Print',
                        text: data.message || 'None of the requested prescriptions are available to you.'
                    });
                }
            })
            .catch(() => {
                if (newTab) newTab.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Could not verify the selected prescriptions. Please try again.'
                });
            })
            .finally(() => {
                btn.prop('disabled', false);
            });
    });

});
</script>

<?php include '../../includes/footer.php'; ?>