<?php
include '../../config/db.php';
session_start();

/* ---------- AUTH ---------- */
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['health_facility','doctor','nurse','super_admin'])) {
    header("Location: ../../../index.php");
    exit;
}

/* ---------- ROLE DASHBOARD ---------- */
function getDashboardUrl(string $role): string {
    return match ($role) {
        'doctor'   => '../doctor/index.php',
        'nurse'    => '../nurse/index.php',
        'health_facility' => '../health_facility/index.php',
        'super_admin'    => '../super_admin/index.php',
        default    => '../../login.php',
    };
}

/* ---------- ACCESS CHECK ---------- */
$facility_id = $_SESSION['user']['facility_id'];
$isAdmin = $_SESSION['user']['role'] === 'super_admin';
$isHealthFacility = $_SESSION['user']['role'] === 'health_facility';
$isDoctor = $_SESSION['user']['role'] === 'doctor';
$isNurse = $_SESSION['user']['role'] === 'nurse';
$showForbiddenModal = !$isHealthFacility && !$isDoctor && !$isNurse && !$isAdmin;
$dashboardUrl = getDashboardUrl($_SESSION['user']['role']);

/* ---------- CENTRALIZED ACCESS ----------
   Patient records are centralized across all roles — health_facility,
   doctor, nurse, and super_admin all see patients from every facility,
   not just their own. Passed to the API (patients.php) and used here
   to decide whether the Facility column is worth showing. */
$isCentralized = $isHealthFacility || $isDoctor || $isNurse || $isAdmin;

/* ---------- NOTE ----------
   The full patient list is no longer fetched here.
   patients.php (api) now returns one page at a time via
   DataTables' serverSide processing, so there is no need
   to pre-load $patients on every page load anymore.
   This removes the old up-front query entirely.
*/

include '../../includes/header.php';
?>
<?php if (!empty($_SESSION['success'])): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        icon: 'success',
        title: 'Success',
        text: <?= json_encode($_SESSION['success']) ?>,
        timer: 2500,
        showConfirmButton: false
    });
});
</script>
<?php unset($_SESSION['success']); endif; ?>
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
    #patientTable thead th {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: .03em;
        color: #667085;
        font-weight: 700;
        border-bottom-width: 1px;
        white-space: nowrap;
    }
    #patientTable tbody td {
        vertical-align: middle;
        font-size: 0.92rem;
    }
    #patientTable tbody tr:hover {
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
    .status-pill-success { background:#ecfdf3; color:#027a48; border:1px solid #abefc6; }
    .status-pill-warning { background:#fffaeb; color:#b54708; border:1px solid #fedf89; }
    .status-pill-danger  { background:#fef3f2; color:#b42318; border:1px solid #fecdca; }
    .status-pill-secondary { background:#f2f4f7; color:#344054; border:1px solid #eaecf0; }
    .status-pill-dark { background:#f2f4f7; color:#1d2939; border:1px solid #d0d5dd; }
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
    .empty-state {
        text-align: center;
        color: #98a2b3;
        padding: 2rem 1rem;
    }
    .empty-state i { font-size: 1.75rem; display: block; margin-bottom: .5rem; }

    /* Tabs styled to sit flush with the toolbar card */
    #patientTabs.nav-tabs { border-bottom: 1px solid #e6e8eb; }
    #patientTabs .nav-link {
        font-size: 0.85rem;
        font-weight: 600;
        color: #667085;
    }
    #patientTabs .nav-link.active {
        color: #0d6efd;
        border-color: #e6e8eb #e6e8eb #fff;
    }
</style>

<div class="page-title">
    <nav class="breadcrumbs">
        <div class="container">
            <ol>
                <li><a href="<?= $dashboardUrl ?>">Dashboard</a></li>
                <li class="current">Patient List</li>
            </ol>
        </div>
    </nav>
</div>

<?php if ($isHealthFacility || $isDoctor || $isNurse || $isAdmin): ?>
<section class="section">
    <div class="container" data-aos="fade-up" data-aos-delay="100">

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="mb-0">Patient List</h4>
            <div class="d-flex gap-2 flex-wrap">
                <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                <a href="../add/patient.php" class="btn btn-dark btn-sm">
                    <i class="bi bi-plus-lg"></i> Add New
                </a>
                <?php if ($isAdmin): ?>
                <a href="../../api/patient/download.php" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-download"></i> Template
                </a>
                <button class="btn btn-outline-primary btn-sm" id="uploadTrigger">
                    <i class="bi bi-upload"></i> Upload
                </button>
                <?php endif; ?>
                <!-- <a href="barangay_issues.php" class="btn btn-outline-warning btn-sm">
                    <i class="bi bi-exclamation-triangle"></i> Check Health Center Data
                </a> -->
            </div>
        </div>

        <!-- Search box: wired to DataTables server-side search -->
        <!-- <div class="list-toolbar mb-3">
            <div class="row g-3 align-items-end">
                <div class="col-md-4 col-sm-6">
                    <label class="form-label">Search Patient</label>
                    <input type="text" id="patientSearch" class="form-control" placeholder="Name or Health Plus No.">
                </div>
                <div class="col-md-2 col-sm-6">
                    <button class="btn btn-outline-primary w-100" id="btnPatientSearch">
                        <i class="bi bi-search"></i> Search
                    </button>
                </div>
            </div>
        </div> -->

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-3" id="patientTabs">
            <li class="nav-item">
                <button class="nav-link active" data-type="non">Patients</button>
            </li>
        </ul>

        <!-- Patient Table -->
        <div class="shadow-sm rounded p-2" style="background:#fff;">
            <div class="table-responsive">
                <table id="patientTable" class="table table-bordered align-middle mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Facility</th>
                            <th>Age</th>
                            <th>Birthdate</th>
                            <th>Status</th>
                            <th>Last Prescription</th>
                            <th>Last Medical Consult</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>

    </div>
</section>
<?php endif; ?>

<!-- Upload Modal -->
<?php if ($isAdmin): ?>
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title">Upload Patients</h5>
                    <button class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <form id="uploadForm" method="POST" enctype="multipart/form-data">

                        <!-- FACILITY SELECT -->
                        <select name="facility_id" class="form-control mb-3" required>
                            <option value="">Select Facility</option>

                            <?php
                            $facilities = $conn->query("SELECT id, facility_name FROM health_facilities ORDER BY facility_name ASC");
                            foreach ($facilities as $f) {
                                echo "<option value='" . htmlspecialchars($f['id']) . "'>" . htmlspecialchars($f['facility_name']) . "</option>";
                            }
                            ?>
                        </select>

                        <!-- FILE -->
                        <input type="file"
                            name="file"
                            class="form-control"
                            accept=".csv"
                            required>

                        <div class="d-flex align-items-start gap-2 p-2 mt-3" style="background:#f9fafb;border-radius:.5rem;">
                            <i class="bi bi-info-circle text-primary mt-1"></i>
                            <small class="text-muted">
                                <strong>barangay</strong> must exactly match a registered Health Center name, or be "Others".<br>
                                <strong>barangay_other</strong> is required only when barangay = "Others".<br>
                                <strong>cluster</strong> is auto-filled from the Health Center — leave it blank.
                            </small>
                        </div>

                    </form>
                </div>

                <div class="modal-footer">
                    <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>

                    <a href="../../api/patient/download.php"
                       class="btn btn-success btn-sm">
                        Template
                    </a>

                    <button class="btn btn-primary btn-sm" id="uploadBtn">
                        Upload
                    </button>
                </div>

            </div>
        </div>
    </div>
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
                <a href="<?= $dashboardUrl ?>" class="btn btn-danger">Return to Dashboard</a>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    new bootstrap.Modal(document.getElementById('forbiddenModal'), { backdrop: 'static', keyboard: false }).show();
});
</script>
<?php endif; ?>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script>
    let table;
    const userRole = "<?= $_SESSION['user']['role'] ?>";
    const isAdminUser = <?= $isAdmin ? 'true' : 'false' ?>;
    const isCentralized = <?= $isCentralized ? 'true' : 'false' ?>; // all roles (health_facility/doctor/nurse/super_admin) see patients across all facilities

    /* ==========================================================
       SERVER-SIDE DATATABLE
       DataTables now requests one page at a time from
       api/patient/patients.php (draw/start/length/search/order
       are sent automatically once serverSide:true is set),
       instead of loading every patient row into the browser.
       ========================================================== */
    function loadPatients(type = 'non') {

        if (table) {
            table.destroy();
            $('#patientTable').empty();
            $('#patientTable').html(`
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Facility</th>
                        <th>Age</th>
                        <th>Birthdate</th>
                        <th>Status</th>
                        <th>Last Prescription</th>
                        <th>Last Medical Consult</th>
                        <th>Action</th>
                    </tr>
                </thead>
            `);
        }

        table = $('#patientTable').DataTable({
            serverSide: true,
            processing: true,
            responsive: true,
            pageLength: 25,
            order: [[6, 'desc']], // default: sort by Last Prescription desc

            ajax: {
                url: '../../api/patient/patients.php',
                type: 'GET',
                data: function (d) {
                    d.type = type; // kept for future re-enabling of the tab filter
                },
                dataSrc: 'data'
            },

            language: {
                emptyTable: '<div class="empty-state"><i class="bi bi-inbox"></i>No patients found.</div>',
                zeroRecords: '<div class="empty-state"><i class="bi bi-inbox"></i>No matching patients.</div>',
                processing: '<div class="spinner-border spinner-border-sm text-secondary" role="status"></div>'
            },

            columns: [
                { data: null, orderable: false },
                { data: 'fullname' },
                {
                    data: 'facility_name',
                    orderable: false,
                    render: function (data) {
                        return data ? data : '<span class="text-muted">—</span>';
                    }
                },
                {
                    data: 'dob',
                    render: function (data) {
                        let birth = new Date(data);
                        let today = new Date();
                        let age = today.getFullYear() - birth.getFullYear();
                        return age + " yrs";
                    }
                },
                { data: 'dob' },
                {
                    data: 'computed_status',
                    render: function (status) {
                        let cls = 'status-pill-secondary';
                        if (status === 'ACTIVE') cls = 'status-pill-success';
                        if (status === 'INACTIVE') cls = 'status-pill-warning';
                        if (status === 'ARCHIVED') cls = 'status-pill-dark';
                        return `<span class="status-pill ${cls}">${status}</span>`;
                    }
                },
                {
                    data: 'last_prescription_date',
                    render: function (data) {
                        if (!data || data === '0000-00-00 00:00:00') {
                            return `<span class="status-pill status-pill-danger">No Prescription</span>`;
                        }
                        let date = new Date(data);
                        if (isNaN(date.getTime())) {
                            return `<span class="status-pill status-pill-danger">No Prescription</span>`;
                        }
                        let formatted = date.toLocaleString('en-US', {
                            year: 'numeric', month: 'short', day: '2-digit',
                            hour: '2-digit', minute: '2-digit', hour12: true
                        });
                        return `<span class="date-pill">${formatted}</span>`;
                    }
                },
                {
                    data: 'last_medical_consult',
                    render: function (data) {
                        if (!data || data === '0000-00-00 00:00:00') {
                            return `<span class="status-pill status-pill-danger">No Consultation</span>`;
                        }
                        let date = new Date(data);
                        if (isNaN(date.getTime())) {
                            return `<span class="status-pill status-pill-danger">No Consultation</span>`;
                        }
                        let formatted = date.toLocaleString('en-US', {
                            year: 'numeric', month: 'short', day: '2-digit',
                            hour: '2-digit', minute: '2-digit', hour12: true
                        });
                        return `<span class="date-pill">${formatted}</span>`;
                    }
                },
                {
                    data: 'id',
                    orderable: false,
                    render: function (id) {

                        let buttons = `
                            <a href="../view/patient.php?id=${id}" class="btn btn-sm btn-outline-secondary"
                                data-bs-toggle="tooltip"
                                data-bs-placement="top"
                                title="View Patient Details">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a href="../edit/patient.php?id=${id}" class="btn btn-sm btn-outline-secondary"
                                data-bs-toggle="tooltip"
                                data-bs-placement="top"
                                title="Edit Patient Details">
                                <i class="bi bi-pen"></i>
                            </a>
                            ${userRole !== 'super_admin' ? `
                                <a href="../add/prescription.php?patient_id=${id}" class="btn btn-sm btn-outline-secondary"
                                    data-bs-toggle="tooltip"
                                    data-bs-placement="top"
                                    title="Add Prescription">
                                    <i class="bi bi-plus"></i>
                                </a>
                            ` : ''}
                        `;

                        if (isAdminUser) {
                            buttons += `
                                <button class="btn btn-sm btn-danger deleteBtn" data-id="${id}"
                                    data-bs-toggle="tooltip"
                                    data-bs-placement="top"
                                    title="Delete Patient">
                                    <i class="bi bi-trash"></i>
                                </button>
                            `;
                        }

                        return buttons;
                    }
                }
            ],

            columnDefs: [
                {
                    targets: 0,
                    orderable: false,
                    render: function (data, type, row, meta) {
                        // row number relative to the current page, not just the page's array index
                        return meta.settings._iDisplayStart + meta.row + 1;
                    }
                },
                {
                    // Facility column — only meaningful for centralized (doctor/
                    // nurse/super_admin) views; a health_facility user's own
                    // patients are all the same facility, so hide it for them.
                    targets: 2,
                    visible: isCentralized
                },
                {
                    targets: 8,
                    orderable: false,
                    responsivePriority: 1
                }
            ],

            drawCallback: function () {
                $('[data-bs-toggle="tooltip"]').each(function () {
                    let existing = bootstrap.Tooltip.getInstance(this);
                    if (existing) existing.dispose();
                    new bootstrap.Tooltip(this);
                });
            }
        });
    }

    /* INIT */
    $(document).ready(function () {
        loadPatients('non'); // default tab

        $('#patientTabs button').click(function () {
            $('#patientTabs button').removeClass('active');
            $(this).addClass('active');

            let type = $(this).data('type');
            loadPatients(type);
        });

        $('#btnPatientSearch').click(function () {
            table.search($('#patientSearch').val()).draw();
        });

        $('#patientSearch').on('keypress', function (e) {
            if (e.which === 13) {
                table.search($(this).val()).draw();
            }
        });
    });

    $(document).on('click', '.archiveBtn', function () {

        let id = $(this).data('id');

        Swal.fire({
            title: 'Delete patient?',
            text: "Patient will be deleted temporarily.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Delete'
        }).then((result) => {

            if (result.isConfirmed) {

                Swal.fire({
                    title: 'Deleting...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                $.ajax({
                    url: '../../api/patient/archive_patient.php',
                    type: 'POST',
                    data: { id: id },
                    success: function (res) {
                        if (res.success) {
                            Swal.fire('Deleted', 'Patient deleted', 'success');
                            table.ajax.reload(null, false); // keep current page
                        } else {
                            Swal.fire('Error', res.error, 'error');
                        }
                    },
                    error: function () {
                        Swal.fire('Error', 'Server error', 'error');
                    }
                });

            }
        });

    });

    $(document).on('click', '.deleteBtn', function () {

        let id = $(this).data('id');

        Swal.fire({
            title: 'PERMANENT DELETE?',
            html: `
                <b style="color:red">This cannot be undone!</b><br>
                Patient data will be permanently removed.
            `,
            icon: 'error',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, Delete Permanently'
        }).then((result) => {

            if (result.isConfirmed) {

                Swal.fire({
                    title: 'Deleting permanently...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                $.ajax({
                    url: '../../api/patient/delete_patient.php',
                    type: 'POST',
                    data: { id: id },
                    success: function (res) {
                        if (res.success) {
                            Swal.fire('Deleted', 'Patient permanently deleted', 'success');
                            table.ajax.reload(null, false); // keep current page
                        } else {
                            Swal.fire('Error', res.error, 'error');
                        }
                    },
                    error: function () {
                        Swal.fire('Error', 'Server error', 'error');
                    }
                });

            }
        });

    });

    /* ================= UPLOAD TEMPLATE ================= */
    $('#uploadBtn').click(function () {

        let file = $('input[name="file"]').val();
        let facility = $('select[name="facility_id"]').val();

        if (!file) {
            Swal.fire('Error', 'Please select a file', 'error');
            return;
        }

        if (!facility) {
            Swal.fire('Error', 'Please select a facility', 'error');
            return;
        }

        let formData = new FormData($('#uploadForm')[0]);

        Swal.fire({
            title: 'Upload Patients?',
            text: 'Bulk upload will insert multiple patients.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes Upload'
        }).then(res => {

            if (res.isConfirmed) {

                $.ajax({
                    url: '../../api/patient/upload.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',

                    beforeSend: () => {
                        Swal.fire({
                            title: 'Uploading...',
                            allowOutsideClick: false,
                            didOpen: () => Swal.showLoading()
                        });
                    },

                    success: function (res) {

                        const hasErrors = Array.isArray(res.errors) && res.errors.length > 0;

                        const errorListHtml = hasErrors
                            ? `<div class="text-start mt-2" style="max-height:250px;overflow-y:auto;">
                                 <ul class="mb-0 ps-3">
                                   ${res.errors.map(e => `<li>${$('<div>').text(e).html()}</li>`).join('')}
                                 </ul>
                               </div>`
                            : '';

                        if (res.status === "success") {
                            Swal.fire({
                                icon: hasErrors ? 'warning' : 'success',
                                title: hasErrors ? 'Uploaded with some issues' : 'Success',
                                html: `<p class="mb-0">${res.message}</p>${errorListHtml}`
                            }).then(() => location.reload());
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Upload Failed',
                                html: `<p class="mb-0">${res.message}</p>${errorListHtml}`
                            });
                        }
                    },

                    error: function (xhr) {
                        console.log(xhr.responseText);
                        Swal.fire('Error', 'Upload failed (check console)', 'error');
                    }
                });

            }

        });

    });

    $('#uploadTrigger').click(function () {
        let modal = new bootstrap.Modal(document.getElementById('uploadModal'));
        modal.show();
    });

</script>
<?php include '../../includes/footer.php'; ?>