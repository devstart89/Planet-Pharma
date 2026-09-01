<?php
// session_start();
include '../../config/db.php';

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

    <!-- Bootstrap -->
    <!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"> -->

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

    <!-- DataTables -->
    <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    
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
        <!-- Doctors Section -->
        <section id="doctors" class="doctors section">
            <div class="container" data-aos="fade-up" data-aos-delay="100">
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
                    <h4>User Management</h4>

                    <!-- Desktop Buttons -->
                    <div class="d-none d-sm-flex flex-wrap gap-2">
                        <a href="javascript:history.back()" 
                        class="btn btn-outline-secondary btn-sm" 
                        data-bs-toggle="tooltip" 
                        data-bs-placement="top" 
                        title="Go Back">
                            <i class="bi bi-arrow-left"></i> 
                        </a>

                        <button class="btn btn-outline-secondary btn-sm" 
                                id="btnAdd" 
                                data-bs-toggle="tooltip" 
                                data-bs-placement="top" 
                                title="Add User">
                            <i class="bi bi-plus"></i>
                        </button>

                        <button class="btn btn-outline-secondary btn-sm" 
                                id="btnUpload" 
                                data-bs-toggle="tooltip" 
                                data-bs-placement="top" 
                                title="Upload User">
                            <i class="bi bi-upload"></i>
                        </button>

                        <!-- <a class="btn btn-outline-secondary btn-sm" 
                            href="../../assets/templates/users_template.csv"
                                id="btnDownload" 
                                data-bs-toggle="tooltip" 
                                data-bs-placement="top" 
                                title="Download Template">
                            <i class="bi bi-download"></i>
                        </a> -->
                    </div>

                    <!-- Mobile Dropdown -->
                    <div class="d-flex d-sm-none">
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" 
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
                                    <button class="dropdown-item" id="btnUploadMobile"><i class="bi bi-upload"></i> Upload User</button>
                                </li>
                                <!-- <li>
                                    <button class="dropdown-item" id="btnDownloadMobile"><i class="bi bi-download"></i> Download Template</button>
                                </li> -->
                            </ul>
                        </div>
                    </div>
                </div>

                <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        // Initialize tooltips on desktop buttons
                        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
                        tooltipTriggerList.forEach(function (tooltipTriggerEl) {
                            new bootstrap.Tooltip(tooltipTriggerEl);
                        });
                    });
                </script>
                <!-- Filterable Doctor Directory -->
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
                        <button class="nav-link" type="button" data-role="super_admin">Super Admin</button>
                    </li>
                </ul>

                <!-- User Table -->
                <div class="doctor-directory mb-5">
                    <div class="bg-light p-3 p-md-4 rounded-3">
                        <div class="row g-3 align-items-center">
                            <div class="shadow rounded p-3 p-md-4 bg-light">
                                <table id="userTable" class="table responsive table-hover align-middle w-100">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Full Name</th>
                                            <th>Email</th>
                                            <th>Role</th>
                                            <th>Facility</th>
                                            <th>Status</th>
                                            <th width="150">Action</th>
                                        </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- End Filterable Doctor Directory -->
            </div>

        </section><!-- /Doctors Section -->

        <!-- USER MODAL -->
        <div class="modal fade" id="userModal">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">User Form</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <form id="userForm">

                            <input type="hidden" id="user_id">

                            <div class="row">
                                <div class="col-md-4 mb-2">
                                    <input type="text" id="first_name" class="form-control" placeholder="First Name" required>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <input type="text" id="middle_name" class="form-control" placeholder="Middle Name">
                                </div>
                                <div class="col-md-4 mb-2">
                                    <input type="text" id="last_name" class="form-control" placeholder="Last Name" required>
                                </div>
                            </div>

                            <input type="email" id="email" class="form-control mb-2" placeholder="Email" required>
                            <input type="password" id="password" class="form-control mb-2" placeholder="Password">

                            <select id="role" class="form-control mb-2">
                                <option value="super_admin">Super Admin</option>
                                <option value="doctor">Doctor</option>
                                <option value="health_facility">Health Facility</option>
                            </select>

                            <select id="facility_id" class="form-control mb-2">
                                <option value="">Select Facility</option>
                            </select>

                            <select id="status" class="form-control mb-2">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>

                            <button type="submit" class="btn btn-success w-100 mt-2">
                                Save
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- UPLOAD USER MODAL -->
        <div class="modal fade" id="uploadModal">
            <div class="modal-dialog">
                <div class="modal-content">

                    <div class="modal-header">
                        <h5 class="modal-title">Upload Users</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">

                        <form id="userUpload" enctype="multipart/form-data">

                            <input type="file" name="file" class="form-control mb-3" accept=".csv" required>

                            <button class="btn btn-dark rounded w-100">
                                Upload Users <i class="fas fa-upload"></i>
                            </button>

                        </form>

                        <small class="text-muted">
                            Default password: <b>epscript.1</b>
                        </small>

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
<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<?php include '../../includes/footer.php'; ?>
<script>

    const apiUrl = "../../api/users/users.php";
    const facilityApi = "../../api/users/health_facilities.php";
    const uploadModal = new bootstrap.Modal(document.getElementById('uploadModal'));
    const userModal = new bootstrap.Modal(document.getElementById('userModal'));
    let table;
    let isSubmitting = false;

    /* =========================
    LOAD FACILITIES
    ========================= */
    function loadFacilities(selectedId = null) {
        $.getJSON(facilityApi, function(data) {

            let options = '<option value="">Select Facility</option>';

            data.forEach(f => {
                const selected = selectedId == f.id ? "selected" : "";
                options += `<option value="${f.id}" ${selected}>${f.facility_name}</option>`;
            });

            $('#facility_id').html(options);
        });
    }

    /* =========================
    INIT DATATABLE
    ========================= */
    $(document).ready(function() {

        table = $('#userTable').DataTable({
            ajax: {
                url: apiUrl,
                dataSrc: ''
            },
            columns: [
                { data: 'id' },
                {
                    data: null,
                    render: function(data) {
                        return `${data.first_name} ${data.middle_name ?? ''} ${data.last_name}`;
                    }
                },
                { data: 'email' },
                { data: 'role' },
                {
                    data: 'facility_name',
                    defaultContent: '-'
                },
                {
                    data: 'status',
                    render: function(data) {
                        const badge = data === 'active'
                            ? 'success'
                            : 'secondary';
                        return `<span class="badge bg-${badge}">${data}</span>`;
                    }
                },
                {
                    data: null,
                    render: function(data) {
                        return `
                            <button class="btn btn-sm btn-warning btnEdit" data-id="${data.id}">Edit</button>
                            <button class="btn btn-sm btn-danger btnDelete" data-id="${data.id}">Delete</button>
                        `;
                    },
                    orderable: false
                }
            ]
        });

    });
    $('#userTab button').on('click', function() {
        $('#userTab button').removeClass('active'); // Remove active class
        $(this).addClass('active'); // Add active to clicked tab

        var role = $(this).data('role'); // Get role from data-role
        if(role === '') {
            table.column(3).search('').draw(); // Show all users
        } else {
            table.column(3).search('^' + role + '$', true, false).draw(); // Exact role match
        }
    });

    /* =========================
    UPLOAD USER
    ========================= */
    $('#btnUpload').click(function () {

        Swal.fire({
            title: 'Upload Users?',
            text: 'Make sure your CSV file is ready. Default password will be: epscript.1',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Continue',
            cancelButtonText: 'Cancel'
        }).then((result) => {

            if (result.isConfirmed) {

                $('#userUpload')[0].reset();
                uploadModal.show();

            }

        });

    });

    /* =========================
    SUBMIT CSV UPLOAD
    ========================= */
    $('#userUpload').submit(function(e){

        e.preventDefault();

        let formData = new FormData(this);

        $.ajax({

            url: '../../api/bulk_upload/users_upload.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,

            beforeSend:function(){

                Swal.fire({
                    title:'Uploading Users...',
                    allowOutsideClick:false,
                    didOpen:()=>{
                        Swal.showLoading();
                    }
                });

            },

            success:function(res){

                let r = typeof res === "string" ? JSON.parse(res) : res;

                if(r.status === "success"){

                    Swal.fire({
                        icon:'success',
                        title:'Upload Successful',
                        text:r.message
                    });

                    uploadModal.hide();
                    $('#userUpload')[0].reset();

                    table.ajax.reload(null,false);

                }else{

                    Swal.fire({
                        icon:'error',
                        title:'Upload Failed',
                        text:r.message
                    });

                }

            },

            error:function(){

                Swal.fire({
                    icon:'error',
                    title:'Server Error',
                    text:'Something went wrong.'
                });

            }

        });

    });

    /* =========================
    ADD USER
    ========================= */
    $('#btnAdd').click(function() {
        $('#userForm')[0].reset();
        $('#user_id').val('');
        $('#password').prop('required', true);
        loadFacilities();
        userModal.show();
    });

    /* =========================
    EDIT USER
    ========================= */
    $(document).on('click', '.btnEdit', function() {

        const id = $(this).data('id');

        $.getJSON(apiUrl + "?id=" + id, function(user) {

            $('#user_id').val(user.id);
            $('#first_name').val(user.first_name);
            $('#middle_name').val(user.middle_name);
            $('#last_name').val(user.last_name);
            $('#email').val(user.email);
            $('#role').val(user.role);
            $('#status').val(user.status);
            $('#password').val('').prop('required', false);

            loadFacilities(user.facility_id);
            userModal.show();
        });
    });

    /* =========================
    SAVE USER
    ========================= */
    $('#userForm').submit(function(e) {
        e.preventDefault();

        if (isSubmitting) return;
        isSubmitting = true;

        const id = $('#user_id').val();
        const btn = $('#userForm button[type="submit"]');
        btn.prop('disabled', true);

        const payload = {
            first_name: $('#first_name').val(),
            middle_name: $('#middle_name').val(),
            last_name: $('#last_name').val(),
            email: $('#email').val(),
            password: $('#password').val(),
            role: $('#role').val(),
            facility_id: $('#facility_id').val(),
            status: $('#status').val()
        };

        $.ajax({
            url: id ? apiUrl + "?id=" + id : apiUrl,
            type: id ? "PUT" : "POST",
            contentType: "application/json",
            data: JSON.stringify(payload),
            success: function() {

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
                Swal.fire('Error', xhr.responseText || 'Failed.', 'error');
            },
            complete: function() {
                isSubmitting = false;
                btn.prop('disabled', false);
            }
        });
    });

    /* =========================
    DELETE USER
    ========================= */
    $(document).on('click', '.btnDelete', function() {

        const id = $(this).data('id');

        Swal.fire({
            title: 'Delete this user?',
            icon: 'warning',
            text: 'This user will be deleted permanently.',
            showCancelButton: true,
            confirmButtonText: "Yes, delete it!",
            cancelButtonText: "No, cancel!",
            confirmButtonColor: '#d33'
        }).then((result) => {

            if (result.isConfirmed) {

                $.ajax({
                    url: apiUrl + "?id=" + id,
                    type: "DELETE",
                    success: function() {
                        table.ajax.reload(null, false);
                        Swal.fire('Deleted!', '', 'success');
                    }
                });
            }else if (result.dismiss === Swal.DismissReason.cancel) {
                Swal.fire('Cancelled', 'User is safe.', 'error');
            }
        });
    });

    /* =========================
    RESET MODAL
    ========================= */
    $('#userModal').on('hidden.bs.modal', function () {
        $('#userForm')[0].reset();
        $('#user_id').val('');
    });

</script>
