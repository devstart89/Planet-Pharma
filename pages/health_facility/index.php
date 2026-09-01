<?php
// session_start();
include '../../config/db.php';

/* ---------- AUTH ---------- */
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'health_facility') {
    header("Location: ../../index.php");
    exit;
}

/* ---------- ROLE → DASHBOARD MAP ---------- */
function getDashboardUrl(string $role): string {
    return match ($role) {
        'doctor'   => '../doctor/index.php',
        'health-facility' => '../health-facility/index.php',
        'admin'    => '../admin/index.php',
        default    => '../.php',
    };
}

/* ---------- ACCESS CHECK ---------- */
$isHealth_Facility = $_SESSION['user']['role'] === 'health_facility';
$showForbiddenModal = !$isHealth_Facility;

if (!$isHealth_Facility) {
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

<?php if ($isHealth_Facility): ?>
<!-- ================= HEALTH FACILITY CONTENT ONLY ================= -->

<section id="services" class="services section">
  <div class="container section-title" data-aos="fade-up">
    <h2>Health Facility Dashboard</h2>
    <p>Manage all aspects of your health facility</p>
  </div>

  <div class="container" data-aos="fade-up" data-aos-delay="100">
    <div class="services-grid">
      <div class="row g-4">
          
        <!-- Patient List -->
          <div class="col-lg-4 col-md-6" data-aos="zoom-in" data-aos-delay="200">
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

        <!-- Prescription -->
          <div class="col-lg-4 col-md-6" data-aos="zoom-in" data-aos-delay="200">
            <div class="service-card primary-care">
              <div class="service-header">
                <div class="service-icon">
                  <i class="fas fa-file-prescription"></i>
                </div>
                <span class="service-category">Prescription</span>
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


        <!-- Transmittal -->
          <div class="col-lg-4 col-md-6" data-aos="zoom-in" data-aos-delay="200">
            <div class="service-card primary-care">
              <div class="service-header">
                <div class="service-icon">
                  <i class="fas fa-heartbeat"></i>
                </div>
                <span class="service-category">Transmittal</span>
              </div>
              <div class="service-body">
              </div>
              <div class="service-footer">
                <a href="../list/transmittal.php" class="service-btn">
                    Create Transmittal
                    <i class="fas fa-arrow-right"></i>
                  </a>
              </div>
            </div>
          </div>

      </div>
    </div>
  </div>
</section>

<!-- ================= END HEALTH FACILITY CONTENT ================= -->
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
