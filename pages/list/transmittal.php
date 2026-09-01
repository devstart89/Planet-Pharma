<?php
    session_start();
    require '../../config/db.php';

    if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'health_facility') {
        header("Location: ../../public/");
        exit;
    }
    /* ---------- ROLE → DASHBOARD MAP ---------- */
    function getDashboardUrl(string $role): string {
        return match ($role) {
            'doctor'   => '../doctor/index.php',
            'health_facility' => '../health_facility/index.php',
            'admin'    => '../admin/index.php',
            default    => '../../login.php',
        };
    }

    /* ---------- ACCESS CHECK ---------- */
    $isHealth_Facility = $_SESSION['user']['role'] === 'health_facility';
    $showForbiddenModal = !$isHealth_Facility;

    if (!$isHealth_Facility) {
        http_response_code(403);
    }

    $dashboardUrl = getDashboardUrl($_SESSION['user']['role']);
    $facilityId = $_SESSION['user']['facility_id'];

    /* ================= GET FACILITY ================= */
    /*
     * Item 6.1: determine whether this is a Hospice account.
     *
     * health_facilities currently has NO dedicated type/category column
     * (confirmed via SHOW CREATE TABLE — only id, pharmacy_id,
     * facility_name, address, contact_number, status exist). Rather than
     * block this fix on a schema migration, Hospice is detected here by
     * checking whether the facility's name contains "hospice"
     * (case-insensitive) — e.g. "Makati Hospice Care Center".
     *
     * This is a stopgap based on naming convention, not a real flag, so
     * it WILL misfire if:
     *   - a Hospice facility's name doesn't contain the word "hospice", or
     *   - a non-Hospice facility's name happens to contain "hospice".
     * For a reliable long-term fix, add a proper column, e.g.:
     *   ALTER TABLE health_facilities
     *   ADD COLUMN facility_type ENUM('Regular','Hospice')
     *   NOT NULL DEFAULT 'Regular' AFTER facility_name;
     * and swap the WHERE-based check below for a direct column read.
     */
    $stmt = $conn->prepare("SELECT facility_name FROM health_facilities WHERE id=? LIMIT 1");
    $stmt->execute([$facilityId]);
    $facilityName = $stmt->fetchColumn() ?: 'Unknown Facility';
    $isHospice = (stripos($facilityName, 'hospice') !== false);

    // /* ================= GET PHARMACY (AUTO FILL) ================= */
    $pharmStmt = $conn->prepare("
    SELECT p.pharmacy_name
    FROM health_facilities hf
    LEFT JOIN pharmacy p ON hf.pharmacy_id = p.id
    WHERE hf.id = ?
    LIMIT 1
    ");
    $pharmStmt->execute([$facilityId]);
    $pharmacyName = $pharmStmt->fetchColumn() ?: 'No Pharmacy Assigned';


    include '../../includes/header.php';
?>

<style>
    /* ===== DataTables + Bootstrap5 theme adjustments ===== */
</style>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
<style>
    /* ===== Transmittal page refinements ===== */
    .transmittal-toolbar {
        background: #fff;
        border: 1px solid #e6e8eb;
        border-radius: 0.75rem;
        padding: 1.25rem 1.5rem;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
    }
    .transmittal-toolbar .form-label {
        font-size: 0.8rem;
        font-weight: 600;
        color: #475467;
        text-transform: uppercase;
        letter-spacing: .02em;
        margin-bottom: .35rem;
    }
    #transmittalTable thead th {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: .03em;
        color: #667085;
        font-weight: 700;
        border-bottom-width: 1px;
        white-space: nowrap;
    }
    #transmittalTable tbody td {
        vertical-align: middle;
        font-size: 0.92rem;
    }
    #transmittalTable tbody tr:hover {
        background-color: #f9fafb;
    }
    .status-locked-pill {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        background: #ecfdf3;
        color: #027a48;
        border: 1px solid #abefc6;
        font-weight: 600;
        font-size: 0.85rem;
        padding: .45rem .75rem;
        border-radius: 0.5rem;
        width: 100%;
    }
    .status-locked-pill i { font-size: 0.9rem; }
    .field-hint {
        font-size: 0.75rem;
        color: #98a2b3;
        margin-top: .25rem;
    }
    .pres-select-table thead th {
        font-size: 0.75rem;
        text-transform: uppercase;
        color: #667085;
    }
    .modal-section-label {
        font-size: 0.78rem;
        font-weight: 700;
        color: #667085;
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: .5rem;
        display: block;
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
            <li><a href="<?php echo $dashboardUrl; ?>">Dashboard</a></li>
            <li class="current">Transmittal List</li>
          </ol>
        </div>
      </nav>
    </div><!-- End Page Title -->

    <?php if ($isHealth_Facility): ?>
        <!-- Doctors Section -->
        <section id="doctors" class="doctors section">
            <div class="container" data-aos="fade-up" data-aos-delay="100">

                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="mb-0">Transmittal List</h4>
                    <div class="d-flex gap-2">
                        <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-left"></i> Back
                        </a>
                        <!--
                            NEW: Bulk printing. Disabled until at least one
                            transmittal is checked; count updates live via
                            JS. Selection persists across pages, same as the
                            Prescription List's bulk-print behavior.
                        -->
                        <button class="btn btn-outline-primary btn-sm" id="printSelectedTransBtn" disabled>
                            <i class="bi bi-printer"></i> Print Selected (<span id="selectedTransCount">0</span>)
                        </button>
                        <button class="btn btn-dark btn-sm " id="btnGenerate">
                            <i class="bi bi-plus-lg"></i> Generate Transmittal
                        </button>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="transmittal-toolbar mb-4">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4 col-sm-6">
                            <label class="form-label">Filter by Date</label>
                            <input type="date" id="searchDate" class="form-control">
                        </div>
                        <div class="col-md-2 col-sm-6">
                            <button class="btn btn-outline-primary w-100" id="btnSearch">
                                <i class="bi bi-search"></i> Search
                            </button>
                        </div>
                    </div>
                </div>

                <!--
                    NOTE ON "FILTER BY PHARMACY OR HEALTH FACILITY":
                    this page is intentionally scoped to the logged-in
                    health_facility account's OWN facility only (every
                    transmittal listed here already belongs to this one
                    facility, and this account is only ever assigned to
                    one pharmacy at a time) — there is nothing to filter
                    ACROSS on this specific page. A Pharmacy/Facility
                    filter would only be meaningful on a centralized view
                    spanning multiple facilities (e.g. for pharmacy or
                    super_admin roles), which doesn't exist yet. See the
                    chat reply for what that would take to build.
                -->

                <!-- Transmittal Table (DataTable initialized in JS) -->
                <div class="shadow-sm rounded p-2" style="background:#fff;">
                    <table class="table table-bordered align-middle mb-0 w-100" id="transmittalTable"></table>
                </div>

            </div>

        </section><!-- /Doctors Section -->
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


    <!-- ================= GENERATE TRANSMITTAL MODAL ================= -->
    <div class="modal fade" id="transModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-truck me-2"></i>Generate Transmittal</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <span class="modal-section-label">Transmittal Details</span>

                    <!-- TOP FILTER -->
                    <div class="row mb-3 g-3">

                        <div class="col-md-3 col-sm-6">
                            <label class="form-label">Date *</label>
                            <input type="date" id="transDate" class="form-control form-control-sm"
                                value="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <label class="form-label">Prescription Date *</label>
                            <input type="date" id="presDate" class="form-control form-control-sm"
                                value="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <label class="form-label">Delivery Date</label>
                            <!--
                                Item 6.1: only Hospice accounts may enter/edit
                                the Delivery Date. Everyone else gets it
                                disabled — the field simply isn't relevant
                                to their workflow. $isHospice is computed
                                server-side above from health_facilities.
                                Disabling (not just hiding) keeps the modal
                                layout consistent across account types.
                            -->
                            <input type="date" id="deliveryDate" class="form-control form-control-sm"
                                <?= $isHospice ? '' : 'disabled' ?>>
                            <div class="field-hint">
                                <?= $isHospice
                                    ? 'Leave blank if not yet scheduled.'
                                    : 'Only editable for Hospice accounts.' ?>
                            </div>
                        </div>

                        <div class="col-md-3 col-sm-6">
                            <label class="form-label">Status</label>
                            <!--
                                Per request: Status is locked to "Signed" and is not
                                user-editable. Only signed prescriptions are eligible
                                for transmittal. The hidden input carries the actual
                                value submitted to the backend; the pill below it is
                                purely a read-only visual indicator.
                            -->
                            <div class="form-control form-control-sm">
                                <i class="bi bi-lock-fill"></i> Signed
                            </div>
                            <input type="hidden" id="statusFilter" value="Signed">
                        </div>

                    </div>

                    <!-- AUTO FILLED SECTION -->
                    <span class="modal-section-label">Facility &amp; Pharmacy</span>
                    <div class="row mb-3 g-3">

                        <div class="col-md-6 col-sm-6">
                            <label class="form-label">Health Facility</label>
                            <input type="text"
                                id="healthFacility"
                                class="form-control form-control-sm"
                                value="<?= htmlspecialchars($facilityName) ?>"
                                readonly>
                        </div>

                        <div class="col-md-6 col-sm-6">
                            <label class="form-label">Pharmacy</label>
                            <input type="text"
                                id="pharmacy"
                                class="form-control form-control-sm"
                                value="<?= htmlspecialchars($pharmacyName) ?>"
                                readonly>
                        </div>

                    </div>

                    <div class="d-flex justify-content-end mb-3">
                        <button class="btn btn-primary btn-sm px-4" id="searchBtn">
                            <i class="bi bi-search"></i> Search Prescriptions
                        </button>
                    </div>

                    <!-- PRESCRIPTION LIST -->
                    <span class="modal-section-label">Eligible Prescriptions</span>
                    <div style="height:300px; overflow:auto;" class="border rounded">
                        <table class="table table-sm table-bordered align-middle mb-0 pres-select-table">
                            <thead class="table-light" style="position:sticky; top:0; z-index:1;">
                            <tr>
                                <th width="40">#</th>
                                <th>Prescription #</th>
                                <th>Patient</th>
                                <th width="100" class="text-center">
                                    <input type="checkbox" id="selectAll" class="form-check-input me-1"> Select All
                                </th>
                            </tr>
                            </thead>
                            <tbody id="presList">
                                <tr>
                                    <td colspan="4" class="empty-state">
                                        <i class="bi bi-search"></i>
                                        Search to load prescriptions
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                </div>

                <div class="modal-footer">
                    <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button class="btn btn-primary btn-sm" id="generateBtn">
                        <i class="bi bi-check2-circle"></i> Generate
                    </button>
                </div>

            </div>
        </div>
    </div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
<script>
    // Item 6.1: mirror the server-side Hospice check in JS as a defensive
    // second layer — even if the `disabled` attribute were ever stripped
    // client-side, generate() below also blanks the value for non-Hospice
    // accounts before it's ever sent to the backend.
    const isHospiceAccount = <?= $isHospice ? 'true' : 'false' ?>;

    // NEW: bulk-print selection state, keyed by transmittal id — persists
    // across pagination the same way the Prescription List's does.
    const selectedTransIds = new Set();

    function updateTransPrintButtonState() {
        $('#selectedTransCount').text(selectedTransIds.size);
        $('#printSelectedTransBtn').prop('disabled', selectedTransIds.size === 0);
    }

    function syncTransSelectAllState() {
        const boxes = $('#transmittalTable tbody .transSelectChk');
        if (boxes.length === 0) {
            $('.transSelectAll').prop('checked', false).prop('indeterminate', false);
            return;
        }
        const checkedCount = boxes.filter(function () {
            return selectedTransIds.has(String($(this).val()));
        }).length;
        $('.transSelectAll').prop('checked', checkedCount === boxes.length);
        $('.transSelectAll').prop('indeterminate', checkedCount > 0 && checkedCount < boxes.length);
    }

    $(function(){

        loadTransmittals();

        // Open modal
        $('#btnGenerate').click(function(){
            $('#transModal').modal('show');
        });

        // ================= SEARCH (list filter) =================
        $('#btnSearch').click(function(){
            loadTransmittals($('#searchDate').val());
        });

        // ================= SEARCH (modal - eligible prescriptions) =================
        // Status is intentionally NOT read from a user-editable control —
        // only "Signed" prescriptions are ever eligible for transmittal.
        $('#searchBtn').click(function(){

            $('#presList').html('<tr><td colspan="4" class="text-center py-3">Loading...</td></tr>');

            $.getJSON('../../api/transmittal/transmittal.php',{
                action:'get_prescriptions',
                pres_date: $('#presDate').val(),
                status: 'Signed'
            }, function(res){

                let rows='';

                if(!res.data || res.data.length===0){
                    rows = `
                        <tr>
                            <td colspan="4" class="empty-state">
                                <i class="bi bi-inbox"></i>
                                No signed prescriptions found for this date.
                            </td>
                        </tr>`;
                }else{
                    res.data.forEach((p,i)=>{
                        // NOTE: `patient_name` must come from the API as a
                        // concatenation of the patients table's first_name /
                        // middle_name / last_name / suffix columns — there is
                        // no single `patient_name` column in the database.
                        rows+=`
                        <tr>
                            <td>${i+1}</td>
                            <td>${p.prescription_number}</td>
                            <td>${p.patient_name}</td>
                            <td class="text-center">
                                <input type="checkbox" value="${p.id}" class="chk form-check-input">
                            </td>
                        </tr>`;
                    });
                }

                $('#presList').html(rows);
            });

        });

        // ================= SELECT ALL =================
        $(document).on('change','#selectAll',function(){
            $('.chk').prop('checked', $(this).is(':checked'));
        });

        $(document).on('change','.chk',function(){
            if(!$(this).is(':checked')){
                $('#selectAll').prop('checked', false);
            }
        });

        // ================= GENERATE =================
        $('#generateBtn').click(function(){

            let selected=[];
            $('.chk:checked').each(function(){
                selected.push($(this).val());
            });

            if(selected.length===0){
                Swal.fire({
                    icon:'warning',
                    title:'No Selection',
                    text:'Please select at least one prescription.'
                });
                return;
            }

            // CONFIRMATION ALERT
            Swal.fire({
                title: 'Are you sure?',
                text: "Generate transmittal for selected prescriptions?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0d6efd',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Generate'
            }).then((result) => {

                if (result.isConfirmed) {

                    // Item 6.1: defensive second layer — non-Hospice accounts
                    // never send a delivery_date, even if the disabled
                    // attribute were somehow bypassed client-side.
                    let deliveryDateVal = isHospiceAccount
                        ? ($('#deliveryDate').val() || null)
                        : null;

                    $.post('../../api/transmittal/transmittal.php',{
                        action:'generate',
                        trans_date: $('#transDate').val(),
                        pres_date: $('#presDate').val(),
                        delivery_date: deliveryDateVal, // optional, Hospice-only
                        status: 'Signed', // locked, always Signed — see statusFilter note above
                        health_facility: $('#healthFacility').val(),
                        pharmacy: $('#pharmacy').val(),
                        prescriptions: selected
                    }, function(res){

                        if(res.status==='success'){

                            Swal.fire({
                                icon:'success',
                                title:'Success',
                                text:res.message,
                                timer:2000,
                                showConfirmButton:false
                            });

                            $('#transModal').modal('hide');
                            loadTransmittals();

                        }else{

                            Swal.fire({
                                icon:'error',
                                title:'Error',
                                text:res.message
                            });

                        }

                    },'json');

                }
            });

        });

    });

    // Item: display Date and Time Generated in 12-hour format (e.g.
    // "8/12/2026, 10:06:01 PM") instead of raw 24-hour MySQL datetime
    // (e.g. "2026-08-12 22:06:01"). Mirrors the formatDateTime() helper
    // already used on create/prescription.php.
    function formatDateTime12Hour(dateStr) {

        if (!dateStr) return '';

        let normalized = dateStr.replace(' ', 'T');
        let d = new Date(normalized);

        if (isNaN(d.getTime())) {
            return dateStr;
        }

        return d.toLocaleString('en-PH', {
            timeZone: 'Asia/Manila',
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        });
    }

    // ================= LOAD TRANSMITTALS (DataTable) =================
    let transmittalDataTable = null;

    function loadTransmittals(filterDate){

        let params = {action:'list'};
        if (filterDate) {
            params.date = filterDate;
        }

        $.getJSON('../../api/transmittal/transmittal.php',
        params,function(res){

            let data = res.data || [];

            // NEW: checkbox column prepended to every row — only rows
            // still present in $prescriptions have a real transmittal id
            // to select, so this is always safe to render.
            let rows = data.map(t => {
                let pdfBtn = `
                    <a href="../../print/transmittal/pdf.php?id=${t.id}" target="_blank" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-file-earmark-pdf"></i> PDF
                    </a>`;

                let checkbox = `<input type="checkbox" class="transSelectChk" value="${t.id}">`;

                return [
                    checkbox,
                    formatDateTime12Hour(t.date_generated),
                    t.num_patients,
                    t.prescription_date,
                    t.health_facility,   // was incorrectly bound to t.pharmacist before
                    t.generated_by,
                    pdfBtn
                ];
            });

            // Destroy any existing instance before rebuilding, so repeated
            // searches/filters don't stack multiple DataTable bindings on
            // the same <table>.
            if ($.fn.DataTable.isDataTable('#transmittalTable')) {
                transmittalDataTable.clear();
                transmittalDataTable.rows.add(rows);
                transmittalDataTable.draw();
                return;
            }

            transmittalDataTable = $('#transmittalTable').DataTable({
                data: rows,
                columns: [
                    {
                        title: '<input type="checkbox" class="transSelectAll" title="Select all on this page">',
                        orderable: false,
                        searchable: false
                    },
                    { title: 'Date and Time Generated' },
                    { title: 'No. of Patients' },
                    { title: 'Date of Prescription' },
                    { title: 'Health Facility' },
                    { title: 'Generated By' },
                    { title: 'Actions', orderable: false, responsivePriority: 1 }
                ],
                responsive: true,
                pageLength: 10,
                lengthMenu: [5, 10, 25, 50, 100],
                order: [[1, 'desc']],
                language: {
                    emptyTable: '<div class="empty-state"><i class="bi bi-inbox"></i>No transmittals found.</div>',
                    zeroRecords: '<div class="empty-state"><i class="bi bi-inbox"></i>No transmittals found.</div>'
                },
                drawCallback: function () {
                    syncTransSelectAllState();
                }
            });
        });
    }

    /* =========================================================
       BULK PRINT — event delegation, since rows are redrawn on
       every page/sort/filter.
    ========================================================= */
    $('#transmittalTable').on('change', 'tbody .transSelectChk', function () {
        const id = String($(this).val());
        if (this.checked) {
            selectedTransIds.add(id);
        } else {
            selectedTransIds.delete(id);
        }
        updateTransPrintButtonState();
        syncTransSelectAllState();
    });

    $('#transmittalTable').on('change', 'thead .transSelectAll', function () {
        const checked = this.checked;
        $('#transmittalTable tbody .transSelectChk').each(function () {
            $(this).prop('checked', checked);
            const id = String($(this).val());
            if (checked) {
                selectedTransIds.add(id);
            } else {
                selectedTransIds.delete(id);
            }
        });
        updateTransPrintButtonState();
    });

    $('#printSelectedTransBtn').on('click', function () {
        if (selectedTransIds.size === 0) return;

        const params = new URLSearchParams();
        selectedTransIds.forEach(function (id) {
            params.append('ids[]', id);
        });

        const btn = $(this);
        btn.prop('disabled', true);

        const bulkUrl = '../../print/transmittal/bulk.php?' + params.toString();

        // Same pattern as the Prescription List's bulk print: open the
        // tab synchronously (popup-blocker safe), precheck via fetch(),
        // then redirect it to the real PDF or close it and show a
        // SweetAlert instead of a raw die() message in a blank tab.
        const newTab = window.open('', '_blank');

        fetch(bulkUrl + '&precheck=1')
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    if (newTab) {
                        newTab.location.href = bulkUrl;
                    } else {
                        window.location.href = bulkUrl;
                    }
                } else {
                    if (newTab) newTab.close();
                    Swal.fire({
                        icon: 'warning',
                        title: 'Nothing to Print',
                        text: data.message || 'None of the requested transmittals are available to you.'
                    });
                }
            })
            .catch(() => {
                if (newTab) newTab.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Could not verify the selected transmittals. Please try again.'
                });
            })
            .finally(() => {
                btn.prop('disabled', false);
            });
    });
</script>


<?php include '../../includes/footer.php'; ?>