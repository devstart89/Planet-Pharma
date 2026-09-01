<?php
session_start();
if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['health_facility', 'doctor','super_admin','pharmacy'])) {
    header("Location: ../../index.php");
    exit;
}
include '../../includes/header.php';
?>
    <!-- Main Content -->
    <main id="main" class="main">
      <!-- Under Development Section -->
      <section id="error-404" class="error-404 section">
        <div class="container" data-aos="fade-up" data-aos-delay="100">
          <div class="error-wrapper">
            <div class="row align-items-center">
              <div class="col-lg-6" data-aos="fade-right" data-aos-delay="200">
                <div class="error-illustration">
                  <i class="bi bi-tools"></i>
                  <div class="circle circle-1"></div>
                  <div class="circle circle-2"></div>
                  <div class="circle circle-3"></div>
                </div>
              </div>
              <div class="col-lg-6" data-aos="fade-left" data-aos-delay="300">
                <div class="error-content">
                  <span class="error-badge" data-aos="zoom-in" data-aos-delay="400">Notice</span>
                  <h1 class="error-code" data-aos="fade-up" data-aos-delay="500">
                    <i class="bi bi-cone-striped"></i>
                  </h1>
                  <h2 class="error-title" data-aos="fade-up" data-aos-delay="600">Page Under Development</h2>
                  <p class="error-description" data-aos="fade-up" data-aos-delay="700">
                    This page is currently being built and is not yet available. Please check back soon.
                  </p>
                  <div class="error-actions" data-aos="fade-up" data-aos-delay="800">
                    <a href="javascript:history.back()" class="btn-home">
                      <i class="bi bi-arrow-right-circle"></i> Return to previous page
                    </a>
                    <a href="#" class="btn-help">
                      <i class="bi bi-question-circle"></i> Help Center
                    </a>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section><!-- /Under Development Section -->
    </main>
<?php include '../../includes/footer.php'; ?>