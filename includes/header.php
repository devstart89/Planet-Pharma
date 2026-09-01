<?php
/**
 * Shared page header.
 *
 * Included by every logged-in page (dashboards, list/add screens,
 * etc.) AFTER session_start() and the DB connection are already set
 * up by the including page — so both $_SESSION and $conn are
 * available here.
 *
 * ADDED: require_doctor_setup.php runs first, before any HTML output.
 * It's a no-op for every role except doctor, and a no-op for a doctor
 * who has already finished their first-login setup (mandatory profile
 * fields + password change + signature upload). An incomplete doctor
 * gets redirected to Account Settings instead of seeing whatever page
 * included this header — which is what makes "don't show the
 * dashboard until setup is done" apply everywhere at once, since
 * essentially every protected page includes this file.
 *
 * This MUST stay the very first thing in the file (before the
 * DOCTYPE) — header('Location: ...') fails once any output has
 * already been sent to the browser.
 */
require_once __DIR__ . '/require_doctor_setup.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <title>E Prescription</title>
  <meta name="description" content="">
  <meta name="keywords" content="">
  <!-- Favicons -->
  <link href="../../logo/epscript-icon.svg" rel="icon">
  <link href="../logo/epscript-icon.svg" rel="apple-touch-icon">
  
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap4.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap4.min.css">
  <!-- Fonts -->
  <link href="https://fonts.googleapis.com" rel="preconnect">
  <link href="https://fonts.gstatic.com" rel="preconnect" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&family=Ubuntu:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400;1,500;1,700&display=swap" rel="stylesheet">
  <!-- Vendor CSS Files -->
  <link href="../../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../../assets/vendor/aos/aos.css" rel="stylesheet">
  <link href="../../assets/vendor/glightbox/css/glightbox.min.css" rel="stylesheet">
  <link href="../../assets/vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
  <link href="../../assets/vendor/swiper/swiper-bundle.min.css" rel="stylesheet">
  <!-- Main CSS File -->
  <link href="../../assets/css/main.css" rel="stylesheet">
  <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
  
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body class="index-page">
  <header id="header" class="header d-flex align-items-center fixed-top">
    <div class="container position-relative d-flex align-items-center justify-content-between">
      <a href="#" class="logo d-flex align-items-center me-auto me-xl-0">
         <img src="../../logo/epscript-icon.svg" alt=""> 
        <h1 class="sitename">E<span>Pscript</span></h1>
      </a><?php if (isset($_SESSION['user'])): ?>
      <div class="dropdown btn-getstarted ">
          <a
              class=" btn-getstarted dropdown-toggle d-flex flex-column align-items-start lh-sm"
              href="#"
              role="button"
              data-bs-toggle="dropdown"
              aria-expanded="false">
              <span>
                  <i class="bi bi-person-circle"></i>
                  <?= htmlspecialchars($_SESSION['user']['name'] ?? 'User') ?>
              </span>
              <?php if (!empty($_SESSION['user']['facility_name'])): ?>
                  <!-- Item 1: Health Facility assigned to the user -->
                  <small class="text-white-50" style="font-size: 0.7rem; margin-left: 1.3rem;">
                      <i class="bi bi-hospital"></i>
                      <?= htmlspecialchars($_SESSION['user']['facility_name']) ?>
                  </small>
              <?php endif; ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end shadow">
              <li>
                  <a class="dropdown-item" href="../../pages/profile/index.php">
                      <i class="bi bi-person me-2"></i>Profile
                  </a>
              </li>
              <li><hr class="dropdown-divider"></li>
              <li>
                  <a class="dropdown-item text-danger" href="../../public/logout.php">
                      <i class="bi bi-box-arrow-right me-2"></i>Logout
                  </a>
              </li>
          </ul>
      </div>
      <?php else: ?>
      <a class="btn-getstarted" href="../../login.php">
          Get Started
      </a>
      <?php endif; ?>
    </div>
  </header>
  <main class="main">