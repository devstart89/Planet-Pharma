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
        'admin'    => '../admin/index.php',
        default    => '../../public/login.php',
    };
}

/* ---------- ACCESS CHECK ---------- */
$isSuperAdmin = $_SESSION['user']['role'] === 'super_admin';
$showForbiddenModal = !$isSuperAdmin;

if (!$isSuperAdmin) {
    http_response_code(403);
}

$dashboardUrl = getDashboardUrl($_SESSION['user']['role']);

$facilities = $conn->query("
    SELECT id, facility_name 
    FROM health_facilities 
    ORDER BY facility_name ASC
");

include '../../includes/header.php';
?>
<style>
.upload-btn {
    position: relative;
}

.spinner-border-sm {
    width: 1rem;
    height: 1rem;
    margin-left: 8px;
    display: none;
}
</style>
<!-- Page Title -->
<div class="page-title">
  <nav class="breadcrumbs">
    <div class="container">
      <ol>
        <li><a href="<?= $dashboardUrl ?>">Dashboard</a></li>
      </ol>
    </div>
  </nav>
</div>
<!-- End Page Title -->

<?php if ($isSuperAdmin): ?>
<!-- ================= SUPER ADMIN CONTENT ONLY ================= -->

<section id="services" class="services section">
  <div class="container section-title" data-aos="fade-up">
    <h2>Manage Upload</h2>
    <p>Manage some aspects of the system</p>
  </div>

  <div class="container" data-aos="fade-up" data-aos-delay="100">
    <div class="services-grid">
      <div class="row g-4">

        <!-- Patients -->
        <div class="col-lg-6 col-md-6" data-aos="zoom-in">
          <div class="service-card primary-care">
            <div class="service-header">
              <div class="service-icon">
                <i class="fas fa-user-injured"></i>
              </div>
              <span class="service-category">Upload Patients</span>
            </div>
            <div class="service-footer">
                <button class="service-btn" data-bs-toggle="modal" data-bs-target="#patientModal">Upload Patients <i class="fas fa-upload"></i></button>
            </div>
          </div>
        </div>

        <!-- Medicines -->
        <div class="col-lg-6 col-md-6" data-aos="zoom-in">
          <div class="service-card specialty-care">
            <div class="service-header">
              <div class="service-icon">
                <i class="fas fa-pills"></i>
              </div>
              <span class="service-category">Upload Medicines</span>
            </div>
            <div class="service-footer">
                <button class="service-btn" data-bs-toggle="modal" data-bs-target="#medicineModal">Upload Medicines <i class="fas fa-upload"></i></button>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</section>

<!-- ================= PATIENT MODAL ================= -->
<div class="modal fade" id="patientModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">

            <div class="modal-header ">
                <h5>Upload Patients</h5>
            </div>

            <div class="modal-body">
                <select id="facility_id" class="form-control mb-2">
                    <option value="">Select Facility</option>
                    <?php while($f = $facilities->fetch(PDO::FETCH_ASSOC)) { ?>
                        <option value="<?= $f['id'] ?>">
                            <?= htmlspecialchars($f['facility_name']) ?>
                        </option>
                    <?php } ?>
                </select>

                <input type="file" id="patientFile" class="form-control">

            </div>

            <div class="modal-footer">
                <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="download_patient_template.php" class="btn btn-sm btn-success"><i class="fas fa-download"></i> Template</a>
                <button class="btn btn-sm btn-primary" id="uploadPatientBtn">
                    <i class="fas fa-upload"></i> Upload
                    <span class="spinner-border spinner-border-sm" id="patientLoader"></span>
                </button>
            </div>

        </div>
    </div>
</div>
<!-- ================= MEDICINE MODAL ================= -->
<div class="modal fade" id="medicineModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">

            <div class="modal-header">
                <h5>Upload Medicines</h5>
            </div>

            <div class="modal-body">
                <input type="file" id="medicineFile" class="form-control">

            </div>

            <div class="modal-footer">
                <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="download_medicine_template.php" class="btn btn-sm btn-success"><i class="fas fa-download"></i> Template</a>
                <button class="btn btn-sm btn-primary" id="uploadMedicineBtn">
                    <i class="fas fa-upload"></i> Upload
                    <span class="spinner-border spinner-border-sm" id="medicineLoader"></span>
                </button>
            </div>

        </div>
    </div>
</div>
<!-- ================= END DOCTOR CONTENT ================= -->
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
    document.addEvner('DOMContentLoaded', () => {
        new bootstrap.Modal(
            document.getElementById('forbiddenModal'),
            { backdrop: 'static', keyboard: false }
        ).show();
    });
  </script>
<?php endif; ?>

<script>
    let patientData = [];
    let medicineData = [];

    /* ================= CSV PARSER ================= */
    function parseCSV(text) {
        const lines = text.split(/\r?\n/).filter(l => l.trim());
        const headers = lines[0].split(",");

        return lines.slice(1).map(line => {
            const values = line.split(",");
            let obj = {};
            headers.forEach((h,i) => obj[h.trim()] = values[i]?.trim() || "");
            return obj;
        });
    }

    /* ================= FILE LOAD ================= */
    document.getElementById("patientFile").onchange = e => {
        const reader = new FileReader();
        reader.onload = ev => {
            patientData = parseCSV(ev.target.result);

            Swal.fire({
                icon: "success",
                title: "Patients Loaded",
                text: `${patientData.length} rows ready`
            });
        };
        reader.readAsText(e.target.files[0]);
    };

    document.getElementById("medicineFile").onchange = e => {
        const reader = new FileReader();
        reader.onload = ev => {
            medicineData = parseCSV(ev.target.result);

            Swal.fire({
                icon: "success",
                title: "Medicines Loaded",
                text: `${medicineData.length} rows ready`
            });
        };
        reader.readAsText(e.target.files[0]);
    };

    /* ================= CLOSE MODAL SAFELY ================= */
    function closeModal(id){
        const modal = bootstrap.Modal.getInstance(document.getElementById(id));
        if(modal){
            modal.hide();
            document.activeElement?.blur();
        }
    }

    /* ================= PATIENT UPLOAD ================= */
    document.getElementById("uploadPatientBtn").onclick = async () => {

        const facility_id = document.getElementById("facility_id").value;
        const loader = document.getElementById("patientLoader");

        if(!facility_id){
            return Swal.fire("Missing", "Select facility first", "warning");
        }

        if(!patientData.length){
            return Swal.fire("No Data", "Upload CSV first", "info");
        }

        loader.style.display = "inline-block";

        const batch_id = "BATCH_" + Date.now();

        try {

            const res = await fetch("../../api/bulk_upload/patients_upload.php", {
                method: "POST",
                headers: {"Content-Type":"application/json"},
                body: JSON.stringify({
                    facility_id,
                    batch_id,
                    data: patientData
                })
            });

            const result = await res.json();

            loader.style.display = "none";
            closeModal("patientModal");

            if(result.status === "success"){

                let dupText = "";

                if(result.duplicates?.length){
                    dupText = "<br><br><b>Duplicates:</b><br>" +
                        result.duplicates.map(d => "• " + d.name).join("<br>");
                }

                await Swal.fire({
                    icon: "success",
                    title: "Upload Completed",
                    html: `
                        <b>Inserted:</b> ${result.inserted}<br>
                        <b>Skipped:</b> ${result.skipped}
                        ${dupText}
                    `
                });

                location.reload();
            }
            else {
                Swal.fire("Error", result.message, "error");
            }

        } catch (err) {
            loader.style.display = "none";
            Swal.fire("Error", "Upload failed", "error");
        }
    };


    /* ================= MEDICINE UPLOAD ================= */
    document.getElementById("uploadMedicineBtn").onclick = async () => {

        const loader = document.getElementById("medicineLoader");

        if(!medicineData.length){
            return Swal.fire("No Data", "Upload CSV first", "info");
        }

        loader.style.display = "inline-block";

        try {

            const res = await fetch("../../api/bulk_upload/medicines_upload.php", {
                method: "POST",
                headers: {"Content-Type":"application/json"},
                body: JSON.stringify({ data: medicineData })
            });

            const result = await res.json();

            loader.style.display = "none";
            closeModal("medicineModal");

            if(result.status === "success"){

                let dupText = "";

                if(result.duplicates?.length){
                    dupText = "<br><br><b>Duplicates:</b><br>" +
                        result.duplicates.map(d => "• " + d).join("<br>");
                }

                await Swal.fire({
                    icon: "success",
                    title: "Upload Completed",
                    html: `
                        <b>Inserted:</b> ${result.inserted}<br>
                        <b>Skipped:</b> ${result.skipped}
                        ${dupText}
                    `
                });

                location.reload();
            }
            else {
                Swal.fire("Error", result.message, "error");
            }

        } catch (err) {
            loader.style.display = "none";
            Swal.fire("Error", "Upload failed", "error");
        }
    };

</script>
<?php include '../../includes/footer.php'; ?>
