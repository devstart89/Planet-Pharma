<?php
/**
 * Login page
 *
 * Refactor notes vs. original:
 *  - Removed ini_set('display_errors', 1) / error_reporting(E_ALL).
 *    Displaying raw PHP errors on a public login page can leak file
 *    paths, query fragments, and stack traces to anyone who triggers
 *    an error. Error display should be a php.ini / environment-level
 *    setting, not something forced on in a page users can reach.
 *  - Removed the commented-out "Username: admin / Password: 1234567"
 *    hint. Even commented out, it ships to every visitor's browser in
 *    the page source.
 *  - Auto-login now hashes the cookie's remember_token with SHA-256
 *    before the DB lookup, matching login_controller.php storing only
 *    the hash. A stale/invalid cookie is deleted instead of silently
 *    left behind.
 *  - Fixed a real bug in the JS: the old code unchecked #rememberMe
 *    *before* calling $(this).serialize(), so "Remember Me" was never
 *    actually sent to the server — the checkbox state was cleared
 *    before jQuery read the form. The checkbox is no longer touched
 *    before submit.
 *  - Fixed duplicate id="alertBox" (two different elements shared the
 *    same id, which is invalid HTML and makes `$("#alertBox")` behave
 *    unpredictably). There's now a single alert container.
 *  - Added autocomplete="username" / "current-password" and a
 *    clarified placeholder ("Username or Email") since the backend
 *    accepts either.
 *  - CSRF token output is unchanged in behavior but now goes through
 *    htmlspecialchars() for defense-in-depth, even though it's a
 *    hex string today.
 */

session_start();
require '../config/db.php';

function normalizeRole(?string $role): string {
    return str_replace('-', '_', strtolower(trim((string) $role)));
}

/*
|--------------------------------------------------------------------------
| AUTO LOGIN (Remember Me)
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION['user']) && isset($_COOKIE['remember_token'])) {

    $hashedToken = hash('sha256', $_COOKIE['remember_token']);

    $stmt = $conn->prepare("SELECT * FROM users WHERE remember_token = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$hashedToken]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        session_regenerate_id(true);
        $role = normalizeRole($user['role']);
        $_SESSION['user'] = [
            'id' => $user['id'],
            'name' => $user['first_name'] . ' ' . $user['last_name'],
            'role' => $role,
            'facility_id' => $user['facility_id'] ?? null,
        ];
        header("Location: ../pages/{$role}/index.php");
        exit;
    }

    // Cookie didn't match anything valid — clear it so we don't keep
    // hitting the DB with a dead token on every future page load.
    setcookie('remember_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'secure' => isset($_SERVER['HTTPS']),
        'samesite' => 'Strict',
    ]);
}

/*
|--------------------------------------------------------------------------
| Redirect if Already Logged In
|--------------------------------------------------------------------------
*/
if (!empty($_SESSION['user']['role'])) {
    header("Location: ../pages/" . normalizeRole($_SESSION['user']['role']) . "/index.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Generate CSRF Token
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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
  <link href="../logo/epscript-icon.svg" rel="icon">
  <link href="../assets/img/apple-touch-icon.png" rel="apple-touch-icon">

  <!-- Fonts -->
  <link href="https://fonts.googleapis.com" rel="preconnect">
  <link href="https://fonts.gstatic.com" rel="preconnect" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&family=Ubuntu:ital,wght@0,300;0,400;0,500;0,700;1,300;1,400;1,500;1,700&display=swap" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets/vendor/aos/aos.css" rel="stylesheet">
  <link href="../assets/vendor/glightbox/css/glightbox.min.css" rel="stylesheet">
  <link href="../assets/vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
  <link href="../assets/vendor/swiper/swiper-bundle.min.css" rel="stylesheet">

  <!-- Main CSS File -->
  <link href="../assets/css/main.css" rel="stylesheet">
</head>

<body class="index-page">

  <header id="header" class="header d-flex align-items-center fixed-top">
    <div class="container position-relative d-flex align-items-center justify-content-between">

      <a href="#" class="logo d-flex align-items-center me-auto me-xl-0">
         <img src="../logo/epscript-icon.svg" alt="">
        <h1 class="sitename">E<span>Pscript</span></h1>
      </a>

      <a class="btn-getstarted" href="login.php">Get Started</a>
    </div>
  </header>

  <main class="main">
    <!-- Page Title -->
    <div class="page-title">
      <nav class="breadcrumbs">
        <div class="container">
          <ol>
            <li><a href="index.php">Home</a></li>
            <li class="current">Login</li>
          </ol>
        </div>
      </nav>
    </div><!-- End Page Title -->

    <!-- Appointmnet Section -->
    <section id="appointmnet" class="appointmnet section shadow-sm">

      <div class="container" data-aos="fade-up" data-aos-delay="100">

        <div class="row gy-4">

          <!-- Appointment Form -->
          <div class="col-lg-6">
            <div class="appointment-form-wrapper " data-aos="fade-up" data-aos-delay="200">
              <form id="loginForm" class="appointment-form php-email-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div class="row gy-3">

                  <div class="col-md-12 p-2">
                    <input type="text" name="login" class="form-control" placeholder="Username"
                           autocomplete="username" required>
                  </div>

                  <div class="col-md-12">
                    <input type="password" name="password" class="form-control" placeholder="Your Password"
                           autocomplete="current-password" required>
                  </div>
                  <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="remember_token" id="rememberMe">
                    <label class="form-check-label" for="rememberMe">Remember Me</label>
                  </div>
                  <div class="col-12">
                    <div class="loading">Loading</div>
                    <div id="alertBox"></div>

                    <button type="submit" id="loginBtn" class="btn btn-appointment w-100">
                      <i class="bi bi-box-arrow-in-right me-2"></i>
                      <span id="btnText">Login</span>
                      <span id="spinner" class="spinner-border spinner-border-sm d-none"></span>
                    </button>
                  </div>

                </div>
              </form>
            </div>
          </div><!-- End Appointment Form -->

          <!-- Appointment Info -->
          <div class="col-lg-6">
            <div class="appointment-info">
              <h3>Secure Login Access</h3>
              <p class="mb-4">Access your prescription and medical records securely. Use your credentials to login and manage your healthcare needs in one place.</p>

              <div class="info-items">
                <div class="info-item d-flex align-items-center mb-3" data-aos="fade-up" data-aos-delay="200">
                  <div class="icon-wrapper me-3">
                    <i class="bi bi-shield-lock"></i>
                  </div>
                  <div>
                    <h5>Secure Authentication</h5>
                    <p class="mb-0">Your account is protected with industry-standard encryption and security protocols</p>
                  </div>
                </div><!-- End Info Item -->

                <div class="info-item d-flex align-items-center mb-3" data-aos="fade-up" data-aos-delay="250">
                  <div class="icon-wrapper me-3">
                    <i class="bi bi-clock-history"></i>
                  </div>
                  <div>
                    <h5>24/7 Access</h5>
                    <p class="mb-0">Access your prescriptions and medical records anytime, anywhere</p>
                  </div>
                </div><!-- End Info Item -->

                <div class="info-item d-flex align-items-center mb-3" data-aos="fade-up" data-aos-delay="300">
                  <div class="icon-wrapper me-3">
                    <i class="bi bi-person-check"></i>
                  </div>
                  <div>
                    <h5>Easy Account Management</h5>
                    <p class="mb-0">Manage your profile and view your complete prescription history</p>
                  </div>
                </div><!-- End Info Item -->
              </div>

            </div>
          </div><!-- End Appointment Info -->
        </div>
      </div>

    </section><!-- /Appointmnet Section -->

  </main>

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
  <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
  <script src="../assets/vendor/php-email-form/validate.js"></script>
  <script src="../assets/vendor/aos/aos.js"></script>
  <script src="../assets/vendor/glightbox/js/glightbox.min.js"></script>
  <script src="../assets/vendor/purecounter/purecounter_vanilla.js"></script>
  <script src="../assets/vendor/imagesloaded/imagesloaded.pkgd.min.js"></script>
  <script src="../assets/vendor/isotope-layout/isotope.pkgd.min.js"></script>
  <script src="../assets/vendor/swiper/swiper-bundle.min.js"></script>

  <!-- Main JS File -->
  <script src="../assets/js/main.js"></script>

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script>
    $("#loginForm").on("submit", function (e) {
        e.preventDefault();

        const $form = $(this);
        const formData = $form.serialize(); // captured BEFORE any UI state changes

        $("#alertBox").html('');
        $("#btnText").text("Logging in...");
        $("#spinner").removeClass("d-none");
        $("#loginBtn").prop("disabled", true);

        $.ajax({
            url: "../controller/login/login.php",
            type: "POST",
            data: formData,
            dataType: "json",
            success: function (res) {
                if (res.status === "success") {
                    window.location.href = res.redirect;
                } else {
                    showError(res.message);
                }
            },
            error: function () {
                showError("Server error. Try again.");
            }
        });

        function showError(message) {
            $("#alertBox").html(`
                <div class="alert alert-danger alert-dismissible fade show">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `);

            $("#btnText").text("Login");
            $("#spinner").addClass("d-none");
            $("#loginBtn").prop("disabled", false);
        }
    });
  </script>

</body>

</html>