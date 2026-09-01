<?php
session_start();
require '../../config/db.php';

/* ================= AUTH ================= */
// This module is super_admin only, per request.
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'super_admin') {
    header("Location: ../../index.php");
    exit;
}

$dashboardUrl = '../super_admin/';

include '../../includes/header.php';
?>

<style>
    :root {
        --rx-border: #e6e8eb;
        --rx-muted: #667085;
        --rx-muted-light: #98a2b3;
    }

    .rx-header-bar h4 { font-weight: 700; color: #1d2939; margin-bottom: 0; }

    .rx-card {
        background: #fff;
        border: 1px solid var(--rx-border);
        border-radius: 0.85rem;
        padding: 1.5rem;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
    }
    .rx-section-label {
        font-size: 0.75rem;
        font-weight: 700;
        color: var(--rx-muted);
        text-transform: uppercase;
        letter-spacing: .04em;
        margin: 0 0 .6rem;
        display: block;
    }
    .rx-field-label {
        font-size: 0.78rem;
        font-weight: 700;
        color: var(--rx-muted);
        text-transform: uppercase;
        letter-spacing: .02em;
        margin-bottom: .3rem;
        display: block;
    }
    .nav-tabs .nav-link {
        font-weight: 600;
        font-size: 0.9rem;
        color: var(--rx-muted);
    }
    .nav-tabs .nav-link.active { color: #0d6efd; }

    .rx-dropzone {
        border: 2px dashed var(--rx-border);
        border-radius: 0.75rem;
        padding: 2rem 1.5rem;
        text-align: center;
        background: #f9fafb;
        transition: border-color .15s ease, background .15s ease;
    }
    .rx-dropzone.dragover {
        border-color: #0d6efd;
        background: #eff8ff;
    }
    .rx-dropzone i { font-size: 2rem; color: var(--rx-muted-light); }
    .rx-file-chip {
        display: inline-flex;
        align-items: center;
        gap: .5rem;
        background: #eff8ff;
        color: #175cd3;
        border: 1px solid #b2ddff;
        font-weight: 600;
        font-size: 0.85rem;
        padding: .4rem .75rem;
        border-radius: 0.5rem;
    }

    .rx-summary-card {
        border-radius: 0.6rem;
        padding: .9rem 1rem;
        border: 1px solid var(--rx-border);
    }
    .rx-summary-card .num { font-size: 1.4rem; font-weight: 700; }
    .rx-summary-success { background: #ecfdf3; border-color: #abefc6; color: #027a48; }
    .rx-summary-skipped { background: #fffaeb; border-color: #fedf89; color: #b54708; }
    .rx-summary-error   { background: #fef3f2; border-color: #fecdca; color: #b42318; }

    #bulkErrorList { max-height: 220px; overflow-y: auto; font-size: 0.85rem; }
    #bulkErrorList li { padding: .35rem 0; border-bottom: 1px dashed var(--rx-border); }
    #bulkErrorList li:last-child { border-bottom: none; }
</style>

<!-- Page Title -->
<div class="page-title">
  <nav class="breadcrumbs">
    <div class="container">
      <ol>
        <li><a href="<?= $dashboardUrl ?>">Dashboard</a></li>
        <li class="current">Upload Health Facility</li>
      </ol>
    </div>
  </nav>
</div><!-- End Page Title -->

<section class="section" id="facilityUpload">
    <div class="container" data-aos="fade-up" data-aos-delay="100">

        <div class="d-flex justify-content-between align-items-center mb-4 rx-header-bar">
            <h4>Upload Health Facility</h4>
            <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#singleTab">
                    <i class="bi bi-building-add me-1"></i>Add Facility
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#bulkTab">
                    <i class="bi bi-file-earmark-spreadsheet me-1"></i>Bulk Upload (CSV)
                </button>
            </li>
        </ul>

        <div class="tab-content">

            <!-- ============ SINGLE ADD TAB ============ -->
            <div class="tab-pane fade show active" id="singleTab">
                <div class="rx-card" style="max-width: 640px;">
                    <span class="rx-section-label"><i class="bi bi-building me-1"></i>Facility Details</span>

                    <form id="singleFacilityForm">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="rx-field-label">Facility Name <span class="text-danger">*</span></label>
                                <input type="text" name="facility_name" id="facility_name" class="form-control" required>
                            </div>
                            <div class="col-12">
                                <label class="rx-field-label">Address</label>
                                <input type="text" name="address" id="address" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="rx-field-label">Contact Number</label>
                                <input type="text" name="contact_number" id="contact_number" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="rx-field-label">Email</label>
                                <input type="email" name="email" id="email" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="rx-field-label">Status</label>
                                <select name="status" id="status" class="form-select">
                                    <option value="Active" selected>Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-4">
                            <button type="button" id="submitSingleBtn" class="btn btn-primary btn-sm px-4">
                                <i class="bi bi-check-circle me-1"></i> Add Facility
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ============ BULK UPLOAD TAB ============ -->
            <div class="tab-pane fade" id="bulkTab">
                <div class="rx-card" style="max-width: 720px;">

                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <span class="rx-section-label mb-1"><i class="bi bi-file-earmark-spreadsheet me-1"></i>CSV Bulk Upload</span>
                            <p class="text-muted small mb-0">
                                Upload a CSV with columns: <code>facility_name</code> (required),
                                <code>address</code>, <code>contact_number</code>, <code>email</code>, <code>status</code>.
                                Rows missing a facility name, or matching an existing facility name, are skipped.
                            </p>
                        </div>
                        <a href="../../controller/facility/download_template.php" class="btn btn-outline-secondary btn-sm text-nowrap">
                            <i class="bi bi-download"></i> Download Template
                        </a>
                    </div>

                    <form id="bulkFacilityForm">
                        <div class="rx-dropzone" id="dropzone">
                            <i class="bi bi-cloud-arrow-up d-block mb-2"></i>
                            <p class="mb-2">Drag & drop your CSV file here, or</p>
                            <input type="file" id="csvFileInput" name="csv_file" accept=".csv" class="d-none">
                            <button type="button" id="browseFileBtn" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-folder2-open"></i> Browse File
                            </button>
                            <div id="selectedFileWrap" class="mt-3" style="display:none;">
                                <span class="rx-file-chip">
                                    <i class="bi bi-file-earmark-spreadsheet"></i>
                                    <span id="selectedFileName"></span>
                                    <i class="bi bi-x-lg ms-1" id="clearFileBtn" style="cursor:pointer;"></i>
                                </span>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-3">
                            <button type="button" id="submitBulkBtn" class="btn btn-primary btn-sm px-4" disabled>
                                <i class="bi bi-upload me-1"></i> Upload & Process
                            </button>
                        </div>
                    </form>

                    <!-- RESULTS -->
                    <div id="bulkResults" class="mt-4" style="display:none;">
                        <hr>
                        <span class="rx-section-label">Upload Summary</span>
                        <div class="row g-2 mb-3">
                            <div class="col-4">
                                <div class="rx-summary-card rx-summary-success text-center">
                                    <div class="num" id="summaryInserted">0</div>
                                    <div class="small">Added</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="rx-summary-card rx-summary-skipped text-center">
                                    <div class="num" id="summarySkipped">0</div>
                                    <div class="small">Skipped</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="rx-summary-card rx-summary-error text-center">
                                    <div class="num" id="summaryErrors">0</div>
                                    <div class="small">Errors</div>
                                </div>
                            </div>
                        </div>
                        <div id="bulkErrorWrap" style="display:none;">
                            <span class="rx-field-label">Row-level details</span>
                            <ul id="bulkErrorList" class="list-unstyled mb-0"></ul>
                        </div>
                    </div>

                </div>
            </div>

        </div>

    </div>
</section>

<?php include '../../includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    function escapeHtml(str) {
        return $('<div>').text(str ?? '').html();
    }

    /* =========================================================
       SINGLE ADD
    ========================================================= */
    $('#submitSingleBtn').click(function () {
        const name = $('#facility_name').val().trim();

        if (!name) {
            Swal.fire('Missing Field', 'Facility name is required.', 'warning');
            return;
        }

        const $btn = $(this);
        $btn.prop('disabled', true);

        $.post(
            '../../controller/facility/facility_process.php',
            $('#singleFacilityForm').serialize() + '&action=add_single',
            function (res) {
                if (res.status === 'success') {
                    Swal.fire('Success', res.message, 'success');
                    $('#singleFacilityForm')[0].reset();
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            },
            'json'
        ).fail(function () {
            Swal.fire('Error', 'Something went wrong. Please try again.', 'error');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    /* =========================================================
       BULK UPLOAD
    ========================================================= */
    let selectedFile = null;

    $('#browseFileBtn').click(function () {
        $('#csvFileInput').trigger('click');
    });

    $('#csvFileInput').on('change', function () {
        if (this.files.length) {
            setSelectedFile(this.files[0]);
        }
    });

    const dropzone = document.getElementById('dropzone');
    ['dragenter', 'dragover'].forEach(evt => {
        dropzone.addEventListener(evt, function (e) {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });
    });
    ['dragleave', 'drop'].forEach(evt => {
        dropzone.addEventListener(evt, function (e) {
            e.preventDefault();
            dropzone.classList.remove('dragover');
        });
    });
    dropzone.addEventListener('drop', function (e) {
        const files = e.dataTransfer.files;
        if (files.length) {
            if (!files[0].name.toLowerCase().endsWith('.csv')) {
                Swal.fire('Invalid File', 'Please upload a .csv file.', 'warning');
                return;
            }
            setSelectedFile(files[0]);
        }
    });

    function setSelectedFile(file) {
        selectedFile = file;
        $('#selectedFileName').text(file.name);
        $('#selectedFileWrap').show();
        $('#submitBulkBtn').prop('disabled', false);
    }

    $('#clearFileBtn').click(function () {
        selectedFile = null;
        $('#csvFileInput').val('');
        $('#selectedFileWrap').hide();
        $('#submitBulkBtn').prop('disabled', true);
    });

    $('#submitBulkBtn').click(function () {
        if (!selectedFile) return;

        const $btn = $(this);
        $btn.prop('disabled', true);

        const formData = new FormData();
        formData.append('csv_file', selectedFile);
        formData.append('action', 'bulk_upload');

        $.ajax({
            url: '../../controller/facility/facility_process.php',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function (res) {
                if (res.status === 'success' || res.status === 'partial') {
                    renderBulkSummary(res);
                    Swal.fire(
                        res.status === 'success' ? 'Upload Complete' : 'Upload Finished With Issues',
                        res.message,
                        res.status === 'success' ? 'success' : 'warning'
                    );
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            },
            error: function () {
                Swal.fire('Error', 'Something went wrong while uploading. Please try again.', 'error');
            },
            complete: function () {
                $btn.prop('disabled', false);
            }
        });
    });

    function renderBulkSummary(res) {
        $('#summaryInserted').text(res.inserted ?? 0);
        $('#summarySkipped').text((res.skipped ?? []).length);
        $('#summaryErrors').text((res.errors ?? []).length);

        const issues = [...(res.skipped ?? []), ...(res.errors ?? [])];

        if (issues.length) {
            let html = '';
            issues.forEach(item => {
                html += `<li><i class="bi bi-exclamation-triangle text-warning me-1"></i>${escapeHtml(item)}</li>`;
            });
            $('#bulkErrorList').html(html);
            $('#bulkErrorWrap').show();
        } else {
            $('#bulkErrorWrap').hide();
        }

        $('#bulkResults').show();
    }
</script>