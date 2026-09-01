<?php
/* If already logged in, redirect by role */
if (isset($_SESSION['user'])) {
    header("Location: public/{$_SESSION['user']['role']}/dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <title>EPscript</title>
  <meta name="description" content="">
  <meta name="keywords" content="">


  <!-- Favicons -->
  <link href="logo/epscript-icon.svg" rel="icon">
  <link href="logo/logo.png" rel="apple-touch-icon">

  <!-- Fonts -->
  <link href="https://fonts.googleapis.com" rel="preconnect">
  <link href="https://fonts.gstatic.com" rel="preconnect" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&family=Ubuntu:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400;1,500;1,700&display=swap" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/vendor/aos/aos.css" rel="stylesheet">
  <link href="assets/vendor/glightbox/css/glightbox.min.css" rel="stylesheet">
  <link href="assets/vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
  <link href="assets/vendor/swiper/swiper-bundle.min.css" rel="stylesheet">

  <!-- Main CSS File -->
  <link href="assets/css/main.css" rel="stylesheet">

  <!-- =======================================================
  * Template Name: MediNest
  * Template URL: https://bootstrapmade.com/medinest-bootstrap-hospital-template/
  * Updated: Aug 11 2025 with Bootstrap v5.3.7
  * Author: BootstrapMade.com
  * License: https://bootstrapmade.com/license/
  ======================================================== -->
</head>

<body class="index-page">

  <header id="header" class="header d-flex align-items-center fixed-top">
    <div class="container position-relative d-flex align-items-center justify-content-between">

      <a href="#" class="logo d-flex align-items-center me-auto me-xl-0">
        <!-- Uncomment the line below if you also wish to use an image logo -->
         <img src="logo/epscript-icon.svg" alt=""> 
        <h1 class="sitename">E<span>Pscript</span></h1>
      </a>

      </nav>

          <?php if (isset($_SESSION['user'])): ?>
          <a class="btn-getstarted" href="public/logout.php">Logout</a>
          <?php else: ?>
              <a class="btn-getstarted" href="public/login.php">Get Started</a>
          <?php endif; ?>

    </div>
  </header>

  <main class="main">

    <!-- Page Title -->
    <div class="page-title">
      <nav class="breadcrumbs">
        <div class="container">
          <ol>
            <?php if (isset($_SESSION['user'])): ?>
                <li><a class="btn-getstarted" href="public/logout.php">Back to Dashboard</a></li>
            <?php else: ?>
                <li><a class="btn-getstarted" href="public/login.php">Home</a></li>
            <?php endif; ?>
            <!-- <li class="current">Starter Page</li> -->
          </ol>
        </div>
      </nav>
    </div><!-- End Page Title -->

    <!-- Call To Action Section -->
    <section id="call-to-action" class="call-to-action section light-background">

      <div class="container" data-aos="fade-up" data-aos-delay="100">

        <div class="hero-content" data-aos="fade-up" data-aos-delay="200">
          <div class="row align-items-center">
            <div class="col-lg-6">
              <div class="content-wrapper">
                <h2>Seamless E-Prescription Services</h2>
                <p>Experience the future of healthcare with secure, digital e-prescriptions. Manage your medications, consult with doctors, and access your prescription history—all online.</p>

                <div class="action-buttons">
                  <a href="public/login.php" class=" secondary-link">Request E-Prescription</a>
                    <i class="fas fa-arrow-right"></i>
                </div>
              </div>
            </div>
            <div class="col-lg-6">
              <div class="hero-image" data-aos="zoom-in" data-aos-delay="300">
                <img src="assets/img/health/showcase-2.webp" alt="E-Prescription Excellence" class="img-fluid">
              </div>
            </div>
          </div>
        </div>

        <div class="services-grid" data-aos="fade-up" data-aos-delay="500">
          <div class="row">

            <div class="col-lg-4 col-md-6">
              <div class="service-card" data-aos="fade-up" data-aos-delay="100">
                <div class="service-icon">
                  <i class="fas fa-prescription-bottle-alt"></i>
                </div>
                <h4>E-Prescription Management</h4>
                <p>Receive, store, and manage your prescriptions digitally. Enjoy easy access and safe storage for all your medication needs.</p>
              </div>
            </div>

            <div class="col-lg-4 col-md-6">
              <div class="service-card" data-aos="fade-up" data-aos-delay="200">
                <div class="service-icon">
                  <i class="fas fa-history"></i>
                </div>
                <h4>E-Prescription History</h4>
                <p>Track your e-prescription history, view past medications, and easily request refills from your healthcare provider.</p>
              </div>
            </div>

            <div class="col-lg-4 col-md-6">
              <div class="service-card" data-aos="fade-up" data-aos-delay="300">
                <div class="service-icon">
                  <i class="fas fa-shield-alt"></i>
                </div>
                <h4>Secure & Private</h4>
                <p>Your e-prescriptions are protected with advanced encryption and privacy standards, ensuring your medical data stays confidential.</p>
              </div>
            </div>

          </div>
        </div>

        <div class="contact-banner" data-aos="zoom-in" data-aos-delay="600">
          <div class="banner-content">
            <div class="contact-info">
              <div class="contact-icon">
                <i class="fas fa-envelope"></i>
              </div>
              <div class="contact-text">
                <h5>Get Your E-Prescription Online</h5>
                <p>Request e-prescriptions from your physician and receive them digitally for fast and convenient pharmacy fulfillment.</p>
              </div>
            </div>
            <div class="contact-actions">
              <a href="public/login.php" class="call-btn">
                <i class="fas fa-sign-in-alt"></i>
                Login to Access E-Prescriptions
              </a>
            </div>
          </div>
        </div>

      </div>

    </section><!-- /Call To Action Section -->



  <footer id="footer" class="footer position-relative">

    <div class="container copyright text-center mt-4">
      <p>© <span>Copyright</span> <strong>EPscript</strong>&nbsp;<span>All Rights Reserved</span></p>
      <div class="credits">
      </div>
    </div>

  </footer>

  <!-- Scroll Top -->
  <a href="#" id="scroll-top" class="scroll-top d-flex align-items-center justify-content-center"><i class="bi bi-arrow-up-short"></i></a>

  <!-- Preloader -->
  <div id="preloader"></div>

  <!-- Vendor JS Files -->
  <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="assets/vendor/php-email-form/validate.js"></script>
  <script src="assets/vendor/aos/aos.js"></script>
  <script src="assets/vendor/glightbox/js/glightbox.min.js"></script>
  <script src="assets/vendor/purecounter/purecounter_vanilla.js"></script>
  <script src="assets/vendor/imagesloaded/imagesloaded.pkgd.min.js"></script>
  <script src="assets/vendor/isotope-layout/isotope.pkgd.min.js"></script>
  <script src="assets/vendor/swiper/swiper-bundle.min.js"></script>

  <!-- Main JS File -->
  <script src="assets/js/main.js"></script>

</body>

</html>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>