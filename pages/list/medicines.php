<?php
session_start();
require '../../config/db.php';

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'super_admin') {
    header("Location: ../../../index.php");
    exit;
}

$dashboardUrl = ($_SESSION['user']['role'] === 'super_admin')
    ? '../super_admin/'
    : '../health_facility/';

include '../../includes/header.php';
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>

.text-uppercase{
    text-transform:uppercase;
}

.table td,
.table th{
    vertical-align:middle;
}

.badge{
    font-size:.80rem;
}

</style>

<div class="page-title">

    <nav class="breadcrumbs">

        <div class="container">

            <ol>

                <li>
                    <a href="<?= $dashboardUrl ?>">Dashboard</a>
                </li>

                <li class="current">
                    Medicine List
                </li>

            </ol>

        </div>

    </nav>

</div>

<div class="container py-3">

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-3">

        <div>

            <h4 class="mb-1">
                Medicine Master List
            </h4>

            <small class="text-muted">
                Manage medicines used in e-Prescriptions.
            </small>

        </div>

        <div class="d-flex gap-2 flex-wrap">

            <a href="javascript:history.back()"
               class="btn btn-outline-secondary btn-sm">

                <i class="bi bi-arrow-left"></i>

            </a>

            <button
                class="btn btn-outline-info btn-sm"
                id="downloadTemplate">

                <i class="bi bi-download"></i>

                Download Template

            </button>

            <button
                class="btn btn-outline-primary btn-sm"
                data-bs-toggle="modal"
                data-bs-target="#uploadModal">

                <i class="bi bi-upload"></i>

                Upload CSV

            </button>

            <button
                class="btn btn-primary btn-sm"
                id="openAdd"
                data-bs-toggle="modal"
                data-bs-target="#medicineModal">

                <i class="bi bi-plus-circle"></i>

                Add Medicine

            </button>

        </div>

    </div>

    <div class="card shadow-sm">

        <div class="card-body">

            <div class="table-responsive">

                <table
                    id="medicineTable"
                    class="table table-hover table-bordered w-100">

                    <thead class="table-light">

                        <tr>

                            <th width="5%">#</th>

                            <th>Generic Name</th>

                            <th width="10%">Dosage</th>

                            <!-- NEW: Unit of Measure column -->
                            <th width="10%">UOM</th>

                            <th width="10%">Duration</th>

                            <th>Signa</th>

                            <th width="10%">Status</th>

                            <th width="12%">Action</th>

                        </tr>

                    </thead>

                    <tbody></tbody>

                </table>

            </div>

        </div>

    </div>

</div>

<!-- ================= MEDICINE MODAL ================= -->
<div class="modal fade" id="medicineModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-scrollable">

        <div class="modal-content">

            <div class="modal-header bg-primary text-white">

                <h5 class="modal-title" id="modalTitle">
                    Add Medicine
                </h5>

                <button
                    type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="modal">
                </button>

            </div>

            <form id="medicineForm">

                <input
                    type="hidden"
                    name="id"
                    id="medicine_id">

                <div class="modal-body">

                    <div class="row g-3">

                        <!-- Generic Name — full width row on its own, since
                             names can run long (e.g. "K IODINE + N IODINE")
                             and sharing a row with other fields squeezed it. -->
                        <div class="col-12">

                            <label class="form-label">
                                Generic Name
                                <span class="text-danger">*</span>
                            </label>

                            <input
                                type="text"
                                class="form-control text-uppercase"
                                id="generic_name"
                                name="generic_name"
                                placeholder="Example: PARACETAMOL"
                                autocomplete="off"
                                required>

                        </div>

                        <!--
                            FIX: Dosage restricted to numeric values only
                            (see the input handler in the script below).
                            The unit itself (mg, mL, tab, etc.) now belongs
                            in the UOM field next to it, instead of being
                            typed as part of Dosage (e.g. "500 mg" ->
                            Dosage "500", UOM "mg").

                            FIX (layout): Dosage / UOM / Duration are now
                            three EQUAL columns (col-md-4 each) instead of
                            the previous 3/3/6 split, which left too little
                            room for the UOM label + placeholder and made
                            "Unit of Measure (UOM)" wrap onto three lines.
                            The UOM label is also shortened to just "UOM"
                            (matching the compact style of the other short
                            field labels) with the full name only as a
                            placeholder hint, rather than fighting for
                            space in the label text itself.
                        -->
                        <div class="col-md-4">

                            <label class="form-label">
                                Dosage
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="dosage"
                                name="dosage"
                                inputmode="decimal"
                                placeholder="e.g. 500">

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                UOM
                            </label>

                            <input
                                type="text"
                                class="form-control text-uppercase"
                                id="uom"
                                name="uom"
                                placeholder="e.g. MG">

                            <small class="text-muted">
                                Unit of Measure
                            </small>

                        </div>

                        <div class="col-md-4">

                            <label class="form-label">
                                Duration
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="duration"
                                name="duration"
                                placeholder="Example: 7 days">

                        </div>

                        <!-- Signa -->
                        <div class="col-12">

                            <label class="form-label">
                                Signa
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="signa"
                                name="signa"
                                placeholder="Example: TAKE ONE TABLET EVERY 6 HOURS">

                            <small class="text-muted">
                                Prescription directions for the patient.
                            </small>

                        </div>

                        <!-- Description -->
                        <div class="col-12">

                            <label class="form-label">
                                Description
                            </label>

                            <textarea
                                class="form-control"
                                id="description"
                                name="description"
                                rows="4"
                                placeholder="For doctor consultation..."></textarea>

                        </div>

                        <!-- Status -->
                        <div class="col-md-4">

                            <label class="form-label">
                                Status
                            </label>

                            <select
                                class="form-select"
                                id="status"
                                name="status">

                                <option value="active">
                                    Active
                                </option>

                                <option value="inactive">
                                    Inactive
                                </option>

                            </select>

                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        data-bs-dismiss="modal">

                        Cancel

                    </button>

                    <button
                        type="button"
                        class="btn btn-primary"
                        id="saveMedicine">

                        <i class="bi bi-save"></i>

                        Save Medicine

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- ================= UPLOAD MODAL ================= -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">

    <div class="modal-dialog">

        <div class="modal-content">

            <div class="modal-header bg-info text-white">

                <h5 class="modal-title">
                    Upload Medicine List (CSV)
                </h5>

                <button
                    type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="modal">
                </button>

            </div>

            <div class="modal-body">

                <div class="alert alert-info">

                    <strong>CSV Format</strong>

                    <hr class="my-2">

                    <small>

                        Required Columns:

                        <br>

                        <!--
                            FIX: added uom to the required columns list,
                            matching the new field. Column order here
                            matches the Download Template button below —
                            keep both in sync if this list changes again.
                        -->
                        <code>
                            generic_name,dosage,uom,duration,signa,description,status
                        </code>

                    </small>

                </div>

                <form id="uploadForm" enctype="multipart/form-data">

                    <label class="form-label">
                        Select CSV File
                    </label>

                    <input
                        type="file"
                        name="file"
                        class="form-control"
                        accept=".csv"
                        required>

                </form>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-outline-info"
                    id="downloadTemplate">

                    <i class="bi bi-download"></i>

                    Download Template

                </button>

                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    data-bs-dismiss="modal">

                    Close

                </button>

                <button
                    type="button"
                    class="btn btn-primary"
                    id="uploadMedicine">

                    <i class="bi bi-upload"></i>

                    Upload CSV

                </button>

            </div>

        </div>

    </div>

</div>

<!-- ================= DATATABLE ================= -->

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>

<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>

<script>
    
    let table;

/* ===========================================================
   DATATABLE
=========================================================== */

function loadTable() {

    table = $('#medicineTable').DataTable({

        serverSide: true, // FIX: was missing entirely — without this, every
                           // page load fetched EVERY medicine row and paged
                           // through them in the browser. At real scale
                           // (hundreds of thousands of rows) that's a
                           // multi-hundred-MB payload and a browser trying
                           // to hold references to all of it — this is what
                           // makes the difference between "fine at 50 test
                           // rows" and "never finishes loading" at scale.
        processing: true,
        responsive: true,
        destroy: true,
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        order: [[1, 'asc']], // default: sort by Generic Name

        ajax: {
            // FIX: points at the new server-side-paginated endpoint.
            // draw/start/length/search/order are sent automatically by
            // DataTables once serverSide:true is set — no extra `data`
            // callback needed here.
            url: '../../api/medicine/medicine_list.php',
            type: 'GET'
        },

        columns: [

            {
                data: null,
                orderable: false,
                // FIX: row number now accounts for which PAGE we're on
                // (meta.settings._iDisplayStart), not just the index
                // within the current page's array — otherwise every
                // page would restart numbering at 1.
                render: (d, t, r, meta) => meta.settings._iDisplayStart + meta.row + 1
            },

            {
                data: 'generic_name',
                render: function(data){

                    if(!data) return '';

                    return data.toUpperCase();

                }
            },

            {
                data: 'dosage',
                defaultContent: '-'
            },

            // NEW: UOM column
            {
                data: 'uom',
                defaultContent: '-',
                render: function(data){

                    if(!data) return '-';

                    return data.toUpperCase();

                }
            },

            {
                data: 'duration',
                defaultContent: '-'
            },

            {
                data: 'signa',
                defaultContent: '-'
            },

            {
                data: 'status',
                render: function(status){

                    if(status === 'active'){

                        return `
                            <span class="badge bg-success">
                                Active
                            </span>
                        `;

                    }

                    return `
                        <span class="badge bg-secondary">
                            Inactive
                        </span>
                    `;

                }
            },

            {
                data: 'id',
                orderable: false,
                searchable: false,

                render: function(id){

                    return `

                        <div class="btn-group btn-group-sm">

                            <button
                                class="btn btn-outline-primary editBtn"
                                data-id="${id}">

                                <i class="bi bi-pencil"></i>

                            </button>


                            <button
                                class="btn btn-outline-danger deleteBtn"
                                data-id="${id}">

                                <i class="bi bi-trash"></i>

                            </button>

                        </div>

                    `;

                }

            }

        ]

    });

}

/* ===========================================================
   RESET FORM
=========================================================== */

function resetForm(){

    $('#medicineForm')[0].reset();

    $('#medicine_id').val('');

}

/* ===========================================================
   ADD BUTTON
=========================================================== */

$('#openAdd').on('click', function(){

    resetForm();

    $('#modalTitle').text('Add Medicine');

});

/* ===========================================================
   GENERIC NAME UPPERCASE
=========================================================== */

$('#generic_name').on('input', function(){

    this.value = this.value.toUpperCase();

});

/* ===========================================================
   DOSAGE — NUMERIC ONLY
   FIX (Item 3): strips anything that isn't a digit or a single
   decimal point as the user types, so Dosage can no longer hold
   embedded unit text (e.g. "500 mg") — units now belong in the
   separate UOM field above.
=========================================================== */

$('#dosage').on('input', function(){

    let v = this.value.replace(/[^0-9.]/g, '');

    // Collapse to at most one decimal point.
    const firstDot = v.indexOf('.');
    if (firstDot !== -1) {
        v = v.slice(0, firstDot + 1) + v.slice(firstDot + 1).replace(/\./g, '');
    }

    this.value = v;

});

/* ===========================================================
   UOM UPPERCASE
=========================================================== */

$('#uom').on('input', function(){

    this.value = this.value.toUpperCase();

});

/* ===========================================================
   SIGNA UPPERCASE
=========================================================== */

$('#signa').on('input', function(){

    this.value = this.value.toUpperCase();

});

/* ===========================================================
   DESCRIPTION UPPERCASE (OPTIONAL)
=========================================================== */

$('#description').on('input', function(){

    this.value = this.value.toUpperCase();

});

/* ===========================================================
   MODAL BACKDROP SAFETY NET
   Bootstrap and SweetAlert2 both manipulate the page's backdrop /
   body classes independently. Showing a Swal confirmation WHILE a
   Bootstrap modal is still technically open behind it (exactly
   what the save/upload flows below do) can leave a stray
   `.modal-backdrop` element and the `modal-open` body class stuck
   in the DOM even after the Bootstrap modal itself reports as
   hidden — that's what causes the page to look permanently dimmed
   and unresponsive to clicks after saving, with no visible dialog
   open. Calling this right after every modal .hide() guarantees
   the backdrop is actually gone regardless of that timing.
=========================================================== */

function cleanupModalBackdrop(){

    $('.modal-backdrop').remove();

    $('body')
        .removeClass('modal-open')
        .css({ 'padding-right': '', 'overflow': '' });

}

/* ===========================================================
   SAVE MEDICINE
=========================================================== */

$('#saveMedicine').on('click', function () {

    const btn = $(this);

    if (!$('#medicineForm')[0].checkValidity()) {
        $('#medicineForm')[0].reportValidity();
        return;
    }

    $.ajax({

        url: '../../api/medicine/save.php',
        type: 'POST',
        data: $('#medicineForm').serialize(),
        dataType: 'json',

        beforeSend: function () {

            btn.prop('disabled', true)
               .html('<i class="bi bi-hourglass-split"></i> Saving...');

        },

        success: function (res) {

            if (res.success) {

                Swal.fire({

                    icon: 'success',
                    title: 'Success',
                    text: 'Medicine saved successfully.',
                    timer: 1500,
                    showConfirmButton: false

                }).then(() => {

                    bootstrap.Modal
                        .getInstance(document.getElementById('medicineModal'))
                        .hide();

                    // Guards against the exact scenario that causes the
                    // stuck-dimmed-page bug: this Swal was shown while
                    // medicineModal was still open behind it.
                    cleanupModalBackdrop();

                    resetForm();

                    table.ajax.reload(null, false);

                });

            } else {

                Swal.fire({

                    icon: 'error',
                    title: 'Error',
                    text: res.error || 'Unable to save medicine.'

                });

            }

        },

        error: function () {

            Swal.fire({

                icon: 'error',
                title: 'Server Error',
                text: 'Unable to communicate with the server.'

            });

        },

        complete: function () {

            btn.prop('disabled', false)
               .html('<i class="bi bi-save"></i> Save Medicine');

        }

    });

});


/* ===========================================================
   EDIT MEDICINE
=========================================================== */

$(document).on('click', '.editBtn', function () {

    const id = $(this).data('id');

    $.getJSON('../../api/medicine/get.php', { id: id }, function (res) {

        $('#medicine_id').val(res.id);

        $('#generic_name').val(
            (res.generic_name || '').toUpperCase()
        );

        $('#dosage').val(res.dosage);

        // NEW: populate UOM on edit
        $('#uom').val(
            (res.uom || '').toUpperCase()
        );

        $('#duration').val(res.duration);

        $('#signa').val(
            (res.signa || '').toUpperCase()
        );

        $('#description').val(
            (res.description || '').toUpperCase()
        );

        $('#status').val(res.status);

        $('#modalTitle').text('Edit Medicine');

        new bootstrap.Modal(
            document.getElementById('medicineModal')
        ).show();

    });

});


/* ===========================================================
   DELETE MEDICINE
=========================================================== */

$(document).on('click', '.deleteBtn', function () {

    const id = $(this).data('id');

    Swal.fire({

        icon: 'warning',

        title: 'Delete Medicine?',

        html: `
            This action will permanently remove this medicine.<br><br>
            <strong class="text-danger">
                This action cannot be undone.
            </strong>
        `,

        showCancelButton: true,

        confirmButtonColor: '#dc3545',

        confirmButtonText: 'Delete',

        cancelButtonText: 'Cancel'

    }).then((result) => {

        if (!result.isConfirmed) return;

        $.ajax({

            url: '../../api/medicine/delete.php',

            type: 'POST',

            data: { id: id },

            dataType: 'json',

            success: function (res) {

                if (res.success) {

                    Swal.fire({

                        icon: 'success',

                        title: 'Deleted',

                        text: 'Medicine deleted successfully.',

                        timer: 1500,

                        showConfirmButton: false

                    });

                    table.ajax.reload(null, false);

                } else {

                    Swal.fire({

                        icon: 'error',

                        title: 'Error',

                        text: res.error || 'Delete failed.'

                    });

                }

            },

            error: function () {

                Swal.fire({

                    icon: 'error',

                    title: 'Server Error',

                    text: 'Unable to delete medicine.'

                });

            }

        });

    });

});

/* ===========================================================
   UPLOAD CSV
=========================================================== */

$('#uploadMedicine').on('click', function () {

    const btn = $(this);

    let formData = new FormData($('#uploadForm')[0]);

    $.ajax({

        url: '../../api/medicine/upload.php',

        type: 'POST',

        data: formData,

        processData: false,

        contentType: false,

        dataType: 'json',

        beforeSend: function () {

            btn.prop('disabled', true)
               .html('<i class="bi bi-hourglass-split"></i> Uploading...');

        },

        success: function (res) {

            if (res.success) {

                Swal.fire({

                    icon: 'success',

                    title: 'Upload Successful',

                    text: res.message,

                    timer: 1800,

                    showConfirmButton: false

                }).then(function () {

                    bootstrap.Modal
                        .getInstance(document.getElementById('uploadModal'))
                        .hide();

                    $('#uploadForm')[0].reset();

                    // table.ajax.reload(null, false);
                        window.location.reload();

                });

            } else {

                Swal.fire({

                    icon: 'error',

                    title: 'Upload Failed',

                    text: res.error || 'Unable to upload CSV.'

                });

            }

        },

        error: function () {

            Swal.fire({

                icon: 'error',

                title: 'Server Error',

                text: 'Unable to upload the file.'

            });

        },

        complete: function () {

            btn.prop('disabled', false)
               .html('<i class="bi bi-upload"></i> Upload CSV');

        }

    });

});


/* ===========================================================
   DOWNLOAD CSV TEMPLATE
=========================================================== */

$('#downloadTemplate').on('click', function () {

    const csv = [

        [
            "generic_name",
            "dosage",
            "uom",
            "duration",
            "signa",
            "description",
            "status"
        ],

        [
            "PARACETAMOL",
            "500",
            "MG",
            "5 days",
            "TAKE 1 TABLET EVERY 6 HOURS",
            "FOR FEVER",
            "active"
        ],

        [
            "AMOXICILLIN",
            "500",
            "MG",
            "7 days",
            "TAKE 1 CAPSULE THREE TIMES A DAY",
            "FOR BACTERIAL INFECTION",
            "active"
        ]

    ].map(row => row.join(",")).join("\n");

    const blob = new Blob(
        [csv],
        { type: "text/csv;charset=utf-8;" }
    );

    const link = document.createElement("a");

    link.href = URL.createObjectURL(blob);

    link.download = "medicine_template.csv";

    document.body.appendChild(link);

    link.click();

    document.body.removeChild(link);

});


/* ===========================================================
   INITIALIZE
=========================================================== */

$(document).ready(function () {

    loadTable();

});
</script>

<?php include '../../includes/footer.php'; ?>