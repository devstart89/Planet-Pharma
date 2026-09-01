<?php
require '../../config/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    exit('Invalid request');
}

$id = (int) $_GET['id'];

/* ================= FETCH PRESCRIPTION ================= */
$stmt = $conn->prepare("
    SELECT pr.*,
           CONCAT(u.first_name,' ',u.last_name) AS doctor_name
    FROM prescriptions pr
    LEFT JOIN users u ON u.id = pr.doctor_id
    WHERE pr.id = ?
");
$stmt->execute([$id]);
$prescription = $stmt->fetch();

if (!$prescription) {
    exit('Prescription not found.');
}

/* ================= FETCH MEDICINES ================= */
$medStmt = $conn->prepare("
    SELECT *
    FROM prescription_medicines
    WHERE prescription_id = ?
");
$medStmt->execute([$id]);
$medicines = $medStmt->fetchAll();
?>

<div class="small">

    <p><strong>Prescription #:</strong>
        <?= htmlspecialchars($prescription['prescription_number'] ?? 'N/A') ?>
    </p>

    <p><strong>Date Created:</strong>
        <?= htmlspecialchars($prescription['created_at']) ?>
    </p>

    <p><strong>Status:</strong>
        <span class="badge bg-<?= $prescription['status'] === 'Signed' ? 'success' : 'warning' ?>">
            <?= htmlspecialchars($prescription['status']) ?>
        </span>
    </p>

    <p><strong>Doctor:</strong>
        <?= htmlspecialchars($prescription['doctor_name'] ?? 'Health Facility') ?>
    </p>

    <?php if ($prescription['signed_at']): ?>
        <p><strong>Signed At:</strong>
            <?= htmlspecialchars($prescription['signed_at']) ?>
        </p>
    <?php endif; ?>

    <hr>

    <p><strong>Diagnosis:</strong></p>
    <div class="border rounded p-2 bg-light">
        <?= nl2br(htmlspecialchars($prescription['diagnosis'])) ?>
    </div>

    <hr>

    <h6 class="mt-3">Prescribed Medicines</h6>

    <?php if (empty($medicines)): ?>
        <div class="alert alert-secondary small">
            No medicines found.
        </div>
    <?php else: ?>

        <table class="table table-bordered table-sm mt-2">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Medicine</th>
                    <th>Dosage</th>
                    <th>Quantity</th>
                    <th>Notes</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($medicines as $index => $med): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= htmlspecialchars($med['medicine_name']) ?></td>
                    <td><?= htmlspecialchars($med['dosage']) ?></td>
                    <td><?= htmlspecialchars($med['quantity']) ?></td>
                    <td><?= htmlspecialchars($med['notes']) ?></td>
                    <td><?= htmlspecialchars($med['status']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</div>