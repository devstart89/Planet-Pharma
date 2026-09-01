<?php
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

<style>
/* ===== LAYOUT ===== */
.directory-bar {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    position: relative;
    z-index: 1050;
}

/* Prevent overflow issues */
.directory-bar .row > div {
    min-width: 0;
}

/* ===== LABELS ===== */
.directory-bar label {
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 4px;
    display: block;
}

/* ===== DROPDOWN ===== */
#facilityDropdown {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.dropdown-menu {
    max-height: 300px;
    overflow-y: auto;
    border-radius: 10px;
    z-index: 9999 !important;
}

/* ===== SEARCH INPUT ===== */
#facilitySearch {
    font-size: 13px;
}

/* ===== TABLE ===== */
.table-container {
    border-radius: 12px;
    background: #fff;
    overflow: visible; /* 🔥 allow dropdown overlap */
}

/* Fix DataTables overlap issue */
.dataTables_wrapper {
    overflow: visible !important;
}

/* Sticky header */
table thead {
    background: #f8f9fa;
    position: sticky;
    top: 0;
    z-index: 1;
}

/* Table styling */
th {
    font-size: 13px;
    font-weight: 600;
}

td {
    font-size: 13px;
}

/* ===== BUTTONS ===== */
#searchBtn,
#extractBtn {
    font-size: 13px;
    padding: 8px 14px;
    white-space: nowrap;
}

/* ===== MOBILE ===== */
@media (max-width: 767px) {

    /* Stack layout */
    .directory-bar .d-flex {
        flex-direction: column;
        align-items: stretch !important;
    }

    #searchBtn,
    #extractBtn {
        width: 100%;
    }

    /* Allow wrapping on small screen */
    #facilityDropdown {
        white-space: normal;
        word-break: break-word;
    }
}

/* ===== EXTRA POLISH ===== */
.dropdown-toggle::after {
    float: right;
    margin-top: 6px;
}
</style>

<!-- <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script> -->

<div class="page-title">
  <nav class="breadcrumbs">
    <div class="container">
      <ol>
        <li><a href="<?= $dashboardUrl ?>">Dashboard</a></li>
        <li class="current">Prescription List</li>
      </ol>
    </div>
  </nav>
</div>

<?php if ($isSuper_admin): ?>
<section id="doctors" class="doctors section">
    <div class="container" data-aos="fade-up" data-aos-delay="100">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4>Prescription List</h4>
            <div class="row">
                <div class="col-md-2">
                    <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left"></i> 
                    </a>
                </div>
            </div>
        </div>

        <div class="doctor-directory mb-5">
            <div class="border bg-white p-3 p-md-4 rounded-3">

                <!-- FILTER BAR -->
                <div class="directory-bar p-3 p-md-4 mb-4">
                    <div class="row g-3 align-items-end">

                        <!-- FACILITY -->
                        <div class="col-12 col-md-6 col-lg-4">
                            <label>Select Facility</label>
                            <div class="dropdown w-100">
                                <button class="btn btn-light w-100 text-start dropdown-toggle" data-bs-toggle="dropdown" id="facilityDropdown">
                                    Select Facility
                                </button>

                                <div class="dropdown-menu p-2 w-100 shadow">
                                    <input type="text" id="facilitySearch" class="form-control mb-2" placeholder="Search facility...">

                                    <div class="form-check mb-2">
                                        <input type="checkbox" id="selectAll" class="form-check-input">
                                        <label class="form-check-label">Select All</label>
                                    </div>

                                    <div id="facilityList"></div>
                                </div>
                            </div>
                        </div>

                        <!-- DATE -->
                        <div class="col-6 col-md-3 col-lg-2">
                            <label>From</label>
                            <input type="date" id="dateFrom" class="form-control">
                        </div>

                        <div class="col-6 col-md-3 col-lg-2">
                            <label>To</label>
                            <input type="date" id="dateTo" class="form-control">
                        </div>

                        <!-- BUTTONS -->
                        <div class="col-12 col-lg-4 d-flex flex-column flex-md-row gap-2">
                            <button class="btn btn-primary w-100" id="searchBtn">
                                <span id="searchText">Search</span>
                            </button>
                            <button class="btn btn-success w-100" id="extractBtn">
                                Extract
                            </button>
                        </div>

                    </div>
                </div>

                <!-- TABLE -->
                <div class="table-container shadow rounded p-3 p-md-4 bg-white">
                    <table id="reportTable" class="table table-bordered mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Prescription No</th>
                                <th>Patient Name</th>
                                <th>Health Facility</th>
                                <th>Contact Nos.</th>
                                <th>Planet Pharma</th>
                                <th>Transmitted</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>
</section>
<?php endif; ?>

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

<!-- <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<?php include '../../includes/footer.php'; ?>

<script>
let selectedFacilities = [];
let allFacilities = [];
let table;

// ================= LOAD FACILITIES =================
function loadFacilities(){
    $.get('../../controller/super_admin/facilities_list.php', function(res){
        if(!res.data) return;
        allFacilities = res.data;
        renderFacilities(allFacilities);
    },'json');
}

// ================= RENDER FACILITIES =================
function renderFacilities(data){
    let html = '';
    data.forEach(f=>{
        html += `
            <div class="form-check facility-item">
                <input class="form-check-input facilityChk" type="checkbox" value="${f.id}" id="f${f.id}">
                <label class="form-check-label" for="f${f.id}">${f.facility_name}</label>
            </div>
        `;
    });
    $('#facilityList').html(html);
    updateDropdownText();
}

// ================= SEARCH INSIDE DROPDOWN =================
$('#facilitySearch').on('keyup', function(){
    let val = $(this).val().toLowerCase();
    $('.facility-item').each(function(){
        $(this).toggle($(this).text().toLowerCase().includes(val));
    });
});

// ================= SELECT ALL =================
$('#selectAll').on('change', function(){
    let checked = this.checked;
    $('.facilityChk').prop('checked', checked);
    selectedFacilities = checked ? allFacilities.map(f=>f.id.toString()) : [];
    updateDropdownText();
});

// ================= INDIVIDUAL CHECKBOX =================
$(document).on('change', '.facilityChk', function(){
    let id = $(this).val();
    if(this.checked){
        if(!selectedFacilities.includes(id)) selectedFacilities.push(id);
    } else {
        selectedFacilities = selectedFacilities.filter(x=>x!=id);
    }

    // Update "Select All" checkbox state
    $('#selectAll').prop('checked', selectedFacilities.length === allFacilities.length);

    updateDropdownText();
});

// ================= UPDATE DROPDOWN TEXT =================
function updateDropdownText(){
    if(selectedFacilities.length===0){
        $('#facilityDropdown').text('Select Facility');
        return;
    }
    let names = [];
    $('.facilityChk:checked').each(function(){ names.push($(this).next('label').text()); });
    let display = names.slice(0,2).join(', ');
    if(names.length>2) display += ` +${names.length-2}`;
    $('#facilityDropdown').text(display);
}

// ================= INIT TABLE =================
table = $('#reportTable').DataTable({
    data: [],
    searching: false,
    pageLength: 10,
    lengthChange: false
});

// ================= SEARCH BUTTON =================
$('#searchBtn').click(function(){
    let from = $('#dateFrom').val();
    let to   = $('#dateTo').val();

    if(!from || !to){
        Swal.fire('Required','Please select date range','warning');
        return;
    }
    if(selectedFacilities.length===0){
        Swal.fire('Required','Please select at least one facility','warning');
        return;
    }

    $('#searchText').html('<span class="spinner-border spinner-border-sm"></span> Loading...');
    $.ajax({
        url:'../../controller/super_admin/fetch_prescription.php',
        type:'POST',
        data:{ facilities:selectedFacilities, from:from, to:to },
        dataType:'json',
        success:function(res){
            table.clear();
            if(!res.data || res.data.length===0){
                Swal.fire({icon:'info',title:'No Records',text:'No prescriptions found for selected filters'});
                $('#searchText').text('Search'); 
                return;
            }
            res.data.forEach(d=>{
                table.row.add([
                    d.date,
                    d.id,
                    d.patient_name,
                    d.facility_name,
                    d.contact,
                    d.pharmacy,
                    d.transmitted_badge
                ]);
            });
            table.draw();
            $('#searchText').text('Search');
        },
        error:function(){
            Swal.fire('Error','Failed to load data','error'); 
            $('#searchText').text('Search'); 
        }
    });
});

// ================= STATUS FORMAT =================
function formatStatus(status){
    if(status==='signed') return '<span class="badge bg-success">Signed</span>';
    if(status==='draft') return '<span class="badge bg-secondary">Draft</span>';
    if(status==='denied') return '<span class="badge bg-danger">Denied</span>';
    return status;
}

// ================= EXTRACT =================
$('#extractBtn').click(()=>{
    let from = $('#dateFrom').val();
    let to   = $('#dateTo').val();

    if(!from || !to){ Swal.fire('Required','Select date range','warning'); return; }
    if(selectedFacilities.length===0){ Swal.fire('Required','Please select at least one facility','warning'); return; }

    let facilities = selectedFacilities.join(',');
    window.open(`../../controller/super_admin/extract_prescriptions.php?facilities=${facilities}&from=${from}&to=${to}`);
});

// ================= INITIAL LOAD =================
loadFacilities();
</script>