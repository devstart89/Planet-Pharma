<?php
// session_start();
include '../../config/db.php';

/* ---------- AUTH ---------- */
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'doctor') {
    header("Location: ../../index.php");
    exit;
}

/* ---------- ROLE → DASHBOARD MAP ---------- */
function getDashboardUrl(string $role): string {
    return match ($role) {
        'doctor'   => '../doctor/index.php',
        'health_facility' => '../health-facility/index.php',
        'admin'    => '../admin/index.php',
        default    => '../../login.php',
    };
}

/* ---------- ACCESS CHECK ---------- */
$isdoctor = $_SESSION['user']['role'] === 'doctor';
$showForbiddenModal = !$isdoctor;

if (!$isdoctor) {
    http_response_code(403);
}

$dashboardUrl = getDashboardUrl($_SESSION['user']['role']);

include '../../includes/header.php';
?>

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

<?php if ($isdoctor): ?>
<!-- ================= DOCTOR CONTENT ONLY ================= -->

<section id="services" class="services section">
  <div class="container section-title" data-aos="fade-up">
    <h2>Doctor Dashboard</h2>
    <p>Manage all aspects of your doctor profile</p>
  </div>

  <div class="container" data-aos="fade-up" data-aos-delay="100">
    <div class="services-grid">
      <div class="row g-4">

        <!-- Prescription -->
          <div class="col-lg-6 col-md-6" data-aos="zoom-in" data-aos-delay="200">
            <div class="service-card primary-care">
              <div class="service-header">
                <div class="service-icon">
                  <i class="fas fa-file-prescription"></i>
                </div>
                <span class="service-category">Prescription List</span>
              </div>
              <div class="service-body">
              </div>
              <div class="service-footer">
                <a href="../list/prescription.php" class="service-btn">
                    Prescription List
                    <i class="fas fa-arrow-right"></i>
                  </a>
              </div>
            </div>
          </div>

        <!-- Create Prescription -->
          <!-- <div class="col-lg-6 col-md-6" data-aos="zoom-in" data-aos-delay="200">
            <div class="service-card primary-care">
              <div class="service-header">
                <div class="service-icon">
                  <i class="fas fa-plus"></i>
                </div>
                <span class="service-category">Create Prescription</span>
              </div>
              <div class="service-body">
              </div>
              <div class="service-footer">
                <a href="../add/prescription.php" class="service-btn">
                    Create Prescription
                    <i class="fas fa-arrow-right"></i>
                  </a>
              </div>
            </div>
          </div> -->

        <!-- Patient List -->
          <div class="col-lg-6 col-md-6" data-aos="zoom-in" data-aos-delay="200">
            <div class="service-card primary-care">
              <div class="service-header">
                <div class="service-icon">
                  <i class="fas fa-user-injured"></i>
                </div>
                <span class="service-category">Patient</span>
              </div>
              <div class="service-body">
              </div>
              <div class="service-footer">
                <a href="../list/patient.php" class="service-btn">
                    Patient List
                    <i class="fas fa-arrow-right"></i>
                  </a>
              </div>
            </div>
          </div>

      </div>
    </div>
  </div>
</section>

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
    document.addEventListener('DOMContentLoaded', () => {
        new bootstrap.Modal(
            document.getElementById('forbiddenModal'),
            { backdrop: 'static', keyboard: false }
        ).show();
    });
  </script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
