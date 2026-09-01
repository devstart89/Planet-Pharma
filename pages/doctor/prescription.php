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
        'health-facility' => '../health-facility/index.php',
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

  $patients = $conn->query("
    SELECT id,his_id, gender, birthday AS dob, CONCAT(first_name, ' ', last_name) AS fullname
    FROM patients
    ORDER BY id 
")->fetchAll(PDO::FETCH_ASSOC);
  include '../../includes/header.php'; 
?>

<!-- DataTables -->
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Page Title -->
    <div class="page-title">
      <nav class="breadcrumbs">
        <div class="container">
          <ol>
            <li><a href="index.php">Dashboard</a></li>
            <li class="current">Prescription List</li>
          </ol>
        </div>
      </nav>
    </div><!-- End Page Title -->
    <?php if ($isdoctor): ?>
        <!-- Doctors Section -->
        <section id="doctors" class="doctors section">
            <!-- Section Title -->
            <div class="container section-title" data-aos="fade-up">
                <h2>Prescription</h2>
                <p>Manage prescription</p>
            </div>
            <!-- End Section Title -->
            <div class="container" data-aos="fade-up" data-aos-delay="100">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4>Prescription List</h4>
                    <div class="row">
                        <div class="col-md-2">
                            <a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-arrow-left"></i> 
                            </a>
                        </div>
                        <div class="col-md-10">
                            <a href="../add/prescription.php" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-plus"></i> Add New Prescription
                            </a>
                        </div>
                    </div>
                </div>
                <!-- Filterable Doctor Directory -->
                <div class="doctor-directory mb-5">
                    <div class="directory-bar p-3 p-md-4 rounded-3">
                        <div class="row g-3 align-items-center">
                            <div class="row col-sm-3">
                                <div class="d-flex justify-content-between align-items-center">                               
                                    <label for="statusFilter">Filter by Status:</label>
                                    <select id="statusFilter" class="form-select w-auto">
                                        <option value="">All</option>
                                        <option value="For Signing">For Signing</option>
                                        <option value="Signed">Signed</option>
                                        <option value="Denied">Denied</option>
                                    </select>
                                </div>
                            </div>
                            <div class=" shadow rounded p-3 p-md-4 bg-white">
                                <table class="table table-bordered" id="prescriptionTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Patient</th>
                                            <th>Diagnosis</th>
                                            <th>Status</th>
                                            <th>Created At</th>
                                            <th width="160">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $stmt = $conn->query("
                                            SELECT p.id, p.diagnosis, p.status, p.created_at,
                                                pat.first_name, pat.last_name, pat.his_id
                                            FROM prescriptions p
                                            JOIN patients pat ON p.patient_id = pat.id
                                            ORDER BY p.created_at DESC
                                        ");

                                        $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

                                        foreach ($prescriptions as $pres) {

                                            $patientName = $pres['first_name'] . ' ' . $pres['last_name'];

                                            // Status Badge
                                            switch ($pres['status']) {
                                                case 'For Signing':
                                                    $statusBadge = "<span class='badge bg-warning'>For Signing</span>";
                                                    break;
                                                case 'Signed':
                                                    $statusBadge = "<span class='badge bg-success'>Signed</span>";
                                                    break;
                                                case 'Denied':
                                                    $statusBadge = "<span class='badge bg-danger'>Denied</span>";
                                                    break;
                                                default:
                                                    $statusBadge = "<span class='badge bg-secondary'>{$pres['status']}</span>";
                                            }

                                            echo "<tr>
                                                <td>{$pres['id']}</td>
                                                <td>{$patientName} ({$pres['his_id']})</td>
                                                <td>{$pres['diagnosis']}</td>
                                                <td>{$statusBadge}</td>
                                                <td>{$pres['created_at']}</td>
                                                <td>";

                                            // View button (opens new page)
                                            echo "<a href='../view/prescription.php?id={$pres['id']}' 
                                                    class='btn btn-outline-primary btn-sm'><i class='bi bi-eye'></i> View</a> ";

                                            echo "</td></tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div><!-- End Filterable Doctor Directory -->
            </div>

        </section><!-- /Doctors Section -->
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

<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script><!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php include '../../includes/footer.php'; ?>
<script>
    $(document).ready(function() {

        var table = $('#prescriptionTable').DataTable();

        // Filter by status
        $('#statusFilter').on('change', function() {
            table.column(3).search($(this).val()).draw();
        });

        // Deny button
        $(document).on('click', '.deny-btn', function() {
            let id = $(this).data('id');

            Swal.fire({
                title: 'Are you sure?',
                text: 'This prescription will be marked as Denied.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Deny'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('prescription_update_status.php', 
                        { id: id, status: 'Denied' }, 
                        function(res) {
                            if (res.status === 'success') {
                                Swal.fire('Success', res.message, 'success')
                                    .then(() => location.reload());
                            } else {
                                Swal.fire('Error', res.message, 'error');
                            }
                        }, 
                        'json'
                    );
                }
            });
        });

    });
</script>