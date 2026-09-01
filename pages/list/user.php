<?php
// session_start();
include '../../config/db.php';
// new
/* ---------- AUTH ---------- */
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'super_admin') {
    header("Location: ../../index.php");
    exit;
}

/* ---------- ROLE → DASHBOARD MAP ---------- */
function getDashboardUrl(string $role): string {
    return match ($role) {
        'doctor'   => '../doctor/index.php',
        'health-facility' => '../health-facility/index.php',
        'super_admin'    => '../super_admin/index.php',
        default    => '../../login.php',
    };
}

/* ---------- ACCESS CHECK ---------- */
$isSuper_admin = $_SESSION['user']['role'] === 'super_admin';
$showForbiddenModal = !$isSuper_admin;

if (!$isSuper_admin) {
    http_response_code(403);
}

$dashboardUrl = getDashboardUrl($_SESSION['user']['role']);

include '../../includes/header.php';
?>

<!-- DataTables CSS -->
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">

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
    #userTable thead th {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: .03em;
        color: #667085;
        font-weight: 700;
        border-bottom-width: 1px;
        white-space: nowrap;
    }
    #userTable tbody td {
        vertical-align: middle;
        font-size: 0.92rem;
    }
    #userTable tbody tr:hover {
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
    .status-pill-secondary { background:#f2f4f7; color:#344054; border:1px solid #eaecf0; }
    .role-pill {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        background: #eff8ff;
        color: #175cd3;
        border: 1px solid #b2ddff;
        font-weight: 600;
        font-size: 0.78rem;
        padding: .3rem .6rem;
        border-radius: 0.5rem;
        white-space: nowrap;
        text-transform: capitalize;
    }
    .empty-state {
        text-align: center;
        color: #98a2b3;
        padding: 2rem 1rem;
    }
    .empty-state i { font-size: 1.75rem; display: block; margin-bottom: .5rem; }

    /* Tabs styled to sit flush with the toolbar card */
    #userTab.nav-tabs { border-bottom: 1px solid #e6e8eb; }
    #userTab .nav-link {
        font-size: 0.85rem;
        font-weight: 600;
        color: #667085;
    }
    #userTab .nav-link.active {
        color: #0d6efd;
        border-color: #e6e8eb #e6e8eb #fff;
    }

    /* ===== Modal redesign to match list-toolbar language ===== */
    .modal-content {
        border: 1px solid #e6e8eb;
        border-radius: 0.9rem;
        box-shadow: 0 8px 24px rgba(16, 24, 40, 0.10);
        overflow: hidden;
    }
    .modal-header {
        background: #fff;
        border-bottom: 1px solid #eaecf0;
        padding: 1.1rem 1.5rem;
    }
    .modal-header .modal-title {
        font-size: 1rem;
        font-weight: 700;
        color: #101828;
        display: flex;
        align-items: center;
        gap: .5rem;
    }
    .modal-header .modal-title i {
        color: #0d6efd;
        font-size: 1.1rem;
    }
    .modal-body {
        padding: 1.5rem;
    }
    .modal-footer {
        border-top: 1px solid #eaecf0;
        padding: 1rem 1.5rem;
    }
    .form-label-sm {
        font-size: 0.75rem;
        font-weight: 600;
        color: #475467;
        text-transform: uppercase;
        letter-spacing: .02em;
        margin-bottom: .3rem;
        display: block;
    }
    .modal-body .form-control,
    .modal-body .form-select {
        border: 1px solid #e6e8eb;
        border-radius: 0.5rem;
        font-size: 0.9rem;
        padding: .55rem .75rem;
    }
    .modal-body .form-control:focus,
    .modal-body .form-select:focus {
        border-color: #0d6efd;
        box-shadow: 0 0 0 3px rgba(13,110,253,.1);
    }
    .upload-dropzone {
        border: 1.5px dashed #d0d5dd;
        border-radius: 0.65rem;
        padding: 1.25rem;
        text-align: center;
        background: #f9fafb;
        transition: border-color .15s;
    }
    .upload-dropzone:hover { border-color: #0d6efd; }
</style>

<!-- Page Title -->
<div class="page-title">
  <nav class="breadcrumbs">
    <div class="container">
      <ol>
        <li><a href="<?= $dashboardUrl ?>">Dashboard</a></li>
        <li class="current">Users Management</li>
      </ol>
    </div>
  </nav>
</div><!-- End Page Title -->

<?php if ($isSuper_admin): ?>
<!-- Users Section -->
<section id="users" class="users section">
    <!-- Section Title -->
    <div class="container section-title" data-aos="fade-up">
        <h2>Users</h2>
        <p>Manage system users and roles</p>
    </div>
    <!-- End Section Title -->

    <div class="container" data-aos="fade-up" data-aos-delay="100">

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
            <h4 class="mb-0">User Management</h4>

            <!-- Desktop Buttons -->
            <div class="d-none d-sm-flex flex-wrap gap-2">
                <a href="javascript:history.back()"
                   class="btn btn-outline-secondary btn-sm"
                   data-bs-toggle="tooltip"
                   data-bs-placement="top"
                   title="Go Back">
                    <i class="bi bi-arrow-left"></i>
                </a>

                <button class="btn btn-primary btn-sm"
                        id="btnAdd"
                        data-bs-toggle="tooltip"
                        data-bs-placement="top"
                        title="Add User">
                    <i class="bi bi-plus-lg"></i> Add User
                </button>

                <button class="btn btn-outline-primary btn-sm"
                        id="btnUpload"
                        data-bs-toggle="tooltip"
                        data-bs-placement="top"
                        title="Upload Users">
                    <i class="bi bi-upload"></i> Upload
                </button>

                <a class="btn btn-outline-success btn-sm"
                   href="../../api/users/download.php"
                   id="btnDownload"
                   data-bs-toggle="tooltip"
                   data-bs-placement="top"
                   title="Download CSV Template">
                    <i class="bi bi-download"></i> Template
                </a>
            </div>

            <!-- Mobile Dropdown -->
            <div class="d-flex d-sm-none">
                <div class="dropdown">
                    <button class="btn btn-outline-secondary dropdown-toggle"
                            type="button"
                            id="mobileDropdown"
                            data-bs-toggle="dropdown"
                            aria-expanded="false">
                        Actions
                    </button>

                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="mobileDropdown">
                        <li>
                            <a class="dropdown-item" href="javascript:history.back()"><i class="bi bi-arrow-left"></i> Go Back</a>
                        </li>
                        <li>
                            <button class="dropdown-item" id="btnAddMobile"><i class="bi bi-plus"></i> Add User</button>
                        </li>
                        <li>
                            <button class="dropdown-item" id="btnUploadMobile"><i class="bi bi-upload"></i> Upload Users</button>
                        </li>
                        <li>
                            <a class="dropdown-item" href="../../api/users/download.php"><i class="bi bi-download"></i> Download Template</a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Toolbar -->
        <!--<div class="list-toolbar mb-3">-->
        <!--    <div class="row g-3 align-items-end">-->
        <!--        <div class="col-md-4 col-sm-6">-->
        <!--            <label class="form-label">Search</label>-->
        <!--            <input type="text" id="userSearch" class="form-control" placeholder="Name or email">-->
        <!--        </div>-->
        <!--        <div class="col-md-2 col-sm-6">-->
        <!--            <button class="btn btn-outline-primary w-100" id="btnUserSearch">-->
        <!--                <i class="bi bi-search"></i> Search-->
        <!--            </button>-->
        <!--        </div>-->
        <!--    </div>-->
        <!--</div>-->

        <!-- Role Tabs -->
        <ul class="nav nav-tabs mb-3" id="userTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" type="button" data-role="">All Users</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" type="button" data-role="doctor">Doctor</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" type="button" data-role="health_facility">Health Facility</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" type="button" data-role="pharmacy">Pharmacy</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" type="button" data-role="super_admin">Super Admin</button>
            </li>
        </ul>

        <!-- User Table -->
        <div class="shadow-sm rounded p-2" style="background:#fff;">
            <div class="table-responsive">
                <table id="userTable" class="table table-bordered align-middle mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Pharmacy</th>
                            <th>Facility</th>
                            <th>Status</th>
                            <th width="120">Action</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>

    </div>
</section><!-- /Users Section -->

<!-- USER MODAL -->
<div class="modal fade" id="userModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-circle"></i> User Form</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <form id="userForm">
                <div class="modal-body">

                    <input type="hidden" id="user_id">

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label-sm">First Name</label>
                            <input type="text" id="first_name" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label-sm">Middle Name</label>
                            <input type="text" id="middle_name" class="form-control">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label-sm">Last Name</label>
                            <input type="text" id="last_name" class="form-control" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label-sm">Username</label>
                            <input type="text" id="username" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label-sm">Email</label>
                            <input type="email" id="email" class="form-control" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-sm">Password</label>
                        <input type="password" id="password" class="form-control" required>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label-sm">Role</label>
                            <select id="role" class="form-select">
                                <option value="super_admin">Super Admin</option>
                                <option value="doctor">Doctor</option>
                                <option value="health_facility">Health Facility</option>
                                <option value="pharmacy">Pharmacy</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3" id="pharmacyWrapper">
                            <label class="form-label-sm">Pharmacy</label>
                            <select id="pharmacy_id" class="form-select">
                                <option value="">Select Pharmacy</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12 mb-3" id="facilityWrapper" style="display:none;">
                            <label class="form-label-sm">Facility</label>
                            <select id="facility_id" class="form-select">
                                <option value="">Select Facility</option>
                            </select>
                            <small class="text-muted d-none" id="facilityHint">
                                No facilities found for this pharmacy.
                            </small>
                        </div>
                    </div>

                    <div class="mb-1">
                        <label class="form-label-sm">Status</label>
                        <select id="status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- UPLOAD USER MODAL -->
<div class="modal fade" id="uploadModal">
    <div class="modal-dialog">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload"></i> Upload Users</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="form-label-sm mb-0">CSV File</span>
                    <a href="../../api/users/download.php" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-download"></i> Template
                    </a>
                </div>

                <form id="userUpload" enctype="multipart/form-data">

                    <div class="upload-dropzone mb-3">
                        <i class="bi bi-file-earmark-arrow-up" style="font-size:1.5rem;color:#98a2b3;"></i>
                        <input type="file" name="file" class="form-control mt-2" accept=".csv" required>
                    </div>

                    <div class="d-flex align-items-start gap-2 p-2 mb-3" style="background:#f9fafb;border-radius:.5rem;">
                        <i class="bi bi-info-circle text-primary mt-1"></i>
                        <small class="text-muted">
                            Columns: first_name, last_name, middle_name, username, email, role, facility_id.<br>
                            Default password: <b>epscript.1</b>
                        </small>
                    </div>

                    <button class="btn btn-primary w-100">
                        <i class="bi bi-upload"></i> Upload Users
                    </button>

                </form>

            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- ================= ACCESS DENIED MODAL ================= -->
<?php if ($showForbiddenModal): ?>
    <div class="modal fade" id="forbiddenModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-color:#fda29b;">
                <div class="modal-body text-center py-4">
                    <div class="mb-3" style="width:56px;height:56px;border-radius:50%;background:#fef3f2;display:flex;align-items:center;justify-content:center;margin:0 auto;">
                        <i class="bi bi-shield-lock" style="font-size:1.6rem;color:#d92d20;"></i>
                    </div>
                    <h5 class="fw-bold mb-1" style="color:#101828;">Access Denied</h5>
                    <p class="text-muted mb-0">You are not authorized to view this page.</p>
                </div>
                <div class="modal-footer justify-content-center border-0 pt-0 pb-4">
                    <a href="<?= $dashboardUrl ?>" class="btn btn-danger px-4">
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

<?php include '../../includes/footer.php'; ?>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script>
const apiUrl = "../../api/users/user.php";
const facilityApi = "../../api/users/health_facility.php";
const pharmacyApi = "../../api/users/pharmacies.php";

const uploadModal = new bootstrap.Modal(document.getElementById('uploadModal'));
const userModal   = new bootstrap.Modal(document.getElementById('userModal'));

let table;
let isSubmitting = false;

/* =========================
GLOBAL RESPONSE HANDLER
========================= */
function handleResponse(res) {
    if (typeof res === "string") res = JSON.parse(res);
    if (res.status === "success") return res.data;
    throw new Error(res.message || "Something went wrong");
}

/* =========================
TOOLTIP INIT (called on every draw so dynamically
rendered rows/buttons actually show their tooltips)
========================= */
function initTooltips() {
    $('[data-bs-toggle="tooltip"]').each(function () {
        let existing = bootstrap.Tooltip.getInstance(this);
        if (existing) existing.dispose();
        new bootstrap.Tooltip(this);
    });
}

/* =========================
LOAD PHARMACIES
========================= */
function loadPharmacies(selectedId = null) {
    return $.get(pharmacyApi, function(res) {

        let data = handleResponse(res);

        let options = `<option value="">Select Pharmacy</option>`;

        data.forEach(p => {
            const selected = selectedId == p.id ? "selected" : "";
            options += `<option value="${p.id}" ${selected}>
                ${p.pharmacy_name}
            </option>`;
        });

        $('#pharmacy_id').html(options);

    }).fail(() => {
        Swal.fire("Error", "Failed to load pharmacies", "error");
    });
}

/* =========================
LOAD FACILITIES (scoped to a pharmacy)
========================= */
function loadFacilities(pharmacyId, selectedId = null) {

    // No pharmacy chosen yet -> keep facility hidden/empty
    if (!pharmacyId) {
        $('#facility_id').html('<option value="">Select Facility</option>');
        $('#facilityWrapper').hide();
        return $.Deferred().resolve().promise();
    }

    return $.get(facilityApi, { pharmacy_id: pharmacyId }, function(res) {

        let data = handleResponse(res) || [];

        let options = `<option value="">Select Facility</option>`;

        data.forEach(f => {
            const selected = selectedId == f.id ? "selected" : "";
            options += `<option value="${f.id}" ${selected}>
                ${f.facility_name}
            </option>`;
        });

        $('#facility_id').html(options);
        $('#facilityWrapper').show();
        $('#facilityHint').toggleClass('d-none', data.length !== 0);

    }).fail(() => {
        Swal.fire("Error", "Failed to load facilities", "error");
    });
}

/* =========================
INIT DATATABLE
========================= */
$(document).ready(function() {

    initTooltips();

    table = $('#userTable').DataTable({
        responsive: true,
        pageLength: 10,
        lengthMenu: [5, 10, 25, 50, 100],
        ajax: {
            url: apiUrl,
            dataSrc: function(res) {
                return handleResponse(res) || [];
            }
        },
        columns: [
            { data: 'id' },
            {
                data: null,
                render: d => `${d.first_name} ${d.middle_name ?? ''} ${d.last_name}`
            },
            { data: 'email' },
            {
                data: 'role',
                render: function (d, type) {
                    // Only prettify for display; search/sort/filter must see
                    // the raw role value ("health_facility", "super_admin")
                    // so the role tabs' regex search still matches.
                    if (type === 'display') {
                        return `<span class="role-pill">${d.replace('_', ' ')}</span>`;
                    }
                    return d;
                }
            },
            {
                data: 'pharmacy_name',
                render: d => d ?? '-'
            },
            {
                data: 'facility_name',
                render: d => d ?? '-'
            },
            {
                data: 'status',
                render: function (d, type) {
                    if (type === 'display') {
                        return `
                            <span class="status-pill ${d === 'active' ? 'status-pill-success' : 'status-pill-secondary'}">
                                ${d}
                            </span>
                        `;
                    }
                    return d;
                }
            },
            {
                data: null,
                render: d => `
                    <button class="btn btn-sm btn-outline-secondary btnEdit"
                        data-id="${d.id}"
                        data-bs-toggle="tooltip"
                        data-bs-placement="top"
                        title="Edit User">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger btnDelete"
                        data-id="${d.id}"
                        data-bs-toggle="tooltip"
                        data-bs-placement="top"
                        title="Delete User">
                        <i class="bi bi-trash"></i>
                    </button>
                `,
                orderable: false,
                responsivePriority: 1
            }
        ],
        language: {
            emptyTable: '<div class="empty-state"><i class="bi bi-inbox"></i>No users found.</div>',
            zeroRecords: '<div class="empty-state"><i class="bi bi-inbox"></i>No matching users.</div>'
        },
        drawCallback: function () {
            initTooltips();
        }
    });

});

/* =========================
ROLE FILTER
========================= */
$('#userTab button').on('click', function() {

    $('#userTab button').removeClass('active');
    $(this).addClass('active');

    let role = $(this).data('role');

    if (!role) {
        table.column(3).search('').draw();
    } else {
        table.column(3).search('^' + role + '$', true, false).draw();
    }
});

/* =========================
SEARCH BOX (name / email)
========================= */
$('#btnUserSearch').click(function () {
    table.search($('#userSearch').val()).draw();
});

$('#userSearch').on('keypress', function (e) {
    if (e.which === 13) {
        table.search($(this).val()).draw();
    }
});

/* =========================
ROLE -> SHOW/HIDE PHARMACY & FACILITY
- super_admin      : hide both
- pharmacy         : show Pharmacy only (Facility always hidden)
- doctor / health_facility : show Pharmacy, then Facility once a
  pharmacy is picked
========================= */
function updateRoleFields(role) {

    if (role === 'super_admin') {
        $('#pharmacyWrapper').hide();
        $('#facilityWrapper').hide();
        $('#pharmacy_id').val('');
        $('#facility_id').val('').html('<option value="">Select Facility</option>');
        return;
    }

    if (role === 'pharmacy') {
        $('#pharmacyWrapper').show();
        $('#facilityWrapper').hide();
        $('#facility_id').val('').html('<option value="">Select Facility</option>');
        return;
    }

    // doctor / health_facility
    $('#pharmacyWrapper').show();
    if ($('#pharmacy_id').val()) {
        $('#facilityWrapper').show();
    } else {
        $('#facilityWrapper').hide();
    }
}

$('#role').on('change', function () {
    updateRoleFields($(this).val());
});

/* =========================
PHARMACY CHANGE -> RELOAD FACILITIES
(only doctor / health_facility ever need a facility)
========================= */
$('#pharmacy_id').on('change', function () {
    const pharmacyId = $(this).val();
    const role = $('#role').val();

    $('#facility_id').val('');

    if (role === 'doctor' || role === 'health_facility') {
        loadFacilities(pharmacyId);
    }
});

/* =========================
OPEN ADD MODAL
========================= */
$('#btnAdd, #btnAddMobile').click(function() {

    $('#userForm')[0].reset();
    $('#user_id').val('');
    $('#password').prop('required', true);

    $('#facility_id').html('<option value="">Select Facility</option>');

    loadPharmacies();
    updateRoleFields($('#role').val());
    userModal.show();
});

/* =========================
EDIT USER
========================= */
$(document).on('click', '.btnEdit', function() {

    const id = $(this).data('id');

    $.get(apiUrl + "?id=" + id, function(res) {

        let user = handleResponse(res);

        $('#user_id').val(user.id);
        $('#first_name').val(user.first_name);
        $('#middle_name').val(user.middle_name);
        $('#last_name').val(user.last_name);
        $('#username').val(user.username);
        $('#email').val(user.email);
        $('#role').val(user.role);
        $('#status').val(user.status);

        $('#password').val('').prop('required', false);

        // users.php resolves pharmacy_id whether the user is a pharmacy
        // account directly, or a doctor/health_facility account whose
        // facility belongs to a pharmacy.
        if (user.role === 'super_admin') {
            updateRoleFields('super_admin');
        } else {
            loadPharmacies(user.pharmacy_id).then(() => {
                updateRoleFields(user.role);
                if (user.role !== 'pharmacy' && user.pharmacy_id) {
                    loadFacilities(user.pharmacy_id, user.facility_id);
                }
            });
        }

        userModal.show();

    }).fail(() => {
        Swal.fire("Error", "Failed to fetch user", "error");
    });
});

/* =========================
SAVE USER
========================= */
$('#userForm').submit(function(e) {

    e.preventDefault();
    if (isSubmitting) return;

    isSubmitting = true;

    const id   = $('#user_id').val();
    const role = $('#role').val();

    const payload = {
        username: $('#username').val().trim(),
        first_name: $('#first_name').val().trim(),
        middle_name: $('#middle_name').val(),
        last_name: $('#last_name').val().trim(),
        email: $('#email').val().trim(),
        password: $('#password').val(),
        role: role,
        // Pharmacy is sent for any role that shows the Pharmacy field
        // (pharmacy, doctor, health_facility) — kept even for
        // doctor/health_facility so it's stored directly on the user
        // rather than only derivable via the facility join.
        // Facility is only relevant for doctor/health_facility.
        pharmacy_id: role !== 'super_admin' ? $('#pharmacy_id').val() : '',
        facility_id: (role === 'doctor' || role === 'health_facility') ? $('#facility_id').val() : '',
        status: $('#status').val()
    };

    $.ajax({
        url: id ? apiUrl + "?id=" + id : apiUrl,
        type: id ? "PUT" : "POST",
        contentType: "application/json",
        data: JSON.stringify(payload),

        success: function(res) {

            handleResponse(res);

            Swal.fire({
                icon: 'success',
                title: 'Saved!',
                timer: 1200,
                showConfirmButton: false
            });

            userModal.hide();
            table.ajax.reload(null, false);
        },

        error: function(xhr) {
            let msg = "Something went wrong";

            try {
                const res = JSON.parse(xhr.responseText);
                msg = res.message || msg;
            } catch {}

            Swal.fire("Error", msg, "error");
        },

        complete: function() {
            isSubmitting = false;
        }
    });

});

/* =========================
DELETE USER (SOFT)
========================= */
$(document).on('click', '.btnDelete', function() {

    const id = $(this).data('id');

    Swal.fire({
        title: 'Delete this user?',
        icon: 'warning',
        text: 'User will be permanently deleted.',
        showCancelButton: true,
        confirmButtonColor: '#d33'
    }).then((result) => {

        if (!result.isConfirmed) return;

        $.ajax({
            url: apiUrl + "?id=" + id,
            type: "DELETE",
            success: function(res) {

                handleResponse(res);

                Swal.fire("Success", "User deleted", "success");
                table.ajax.reload(null, false);
            }
        });
    });
});

/* =========================
UPLOAD MODAL
========================= */
$('#btnUpload, #btnUploadMobile').click(function () {
    $('#userUpload')[0].reset();
    uploadModal.show();
});

/* =========================
UPLOAD SUBMIT
========================= */
$('#userUpload').submit(function(e){

    e.preventDefault();

    let formData = new FormData(this);

    Swal.fire({
        title: 'Upload Users?',
        text: 'Default password: epscript.1',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Upload'
    }).then((r) => {

        if (!r.isConfirmed) return;

        $.ajax({
            url: '../../api/bulk_upload/users_upload.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,

            beforeSend: () => {
                Swal.fire({
                    title: 'Uploading...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });
            },

            success: function(res){

                try { res = JSON.parse(res); } catch {}

                if (res.status === "success") {
                    Swal.fire("Success", res.message, "success");
                    uploadModal.hide();
                    table.ajax.reload(null, false);
                } else {
                    Swal.fire("Error", res.message, "error");
                }
            },

            error: () => Swal.fire("Error", "Upload failed", "error")
        });

    });
});

/* =========================
RESET FORM
========================= */
$('#userModal').on('hidden.bs.modal', function () {
    $('#userForm')[0].reset();
    $('#user_id').val('');
    $('#facility_id').html('<option value="">Select Facility</option>');
    updateRoleFields($('#role').val());
});
</script>