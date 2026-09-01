<?php
/*
|--------------------------------------------------------------------------
| prescription_table.php
|--------------------------------------------------------------------------
| Included partial, used twice by view/patient.php (once for "Current
| Medication" with $tableData = [most recent prescription], once for
| "History" with $tableData = [all prescriptions]).
|
| NOTE: this file was not provided/shared, so it's rebuilt from context
| (the columns implied by view/patient.php's DataTable init and the
| fields selected in its prescriptions query: prescription_number,
| diagnosis, status, created_at, doctor_name). If a real version of this
| file exists with different columns or logic, send it and I'll merge
| instead of guessing further.
|
| CHANGE FROM ORIGINAL: the old page opened prescription details in a
| modal via AJAX to view_prescription_details.php (a file that also
| wasn't provided). This now links directly to the full, already-built
| Prescription Details page (view/prescription.php) instead, per request
| ("user can view the prescription").
|
| Visual language (status-pill / date-pill) matches the redesigned
| Patient List page for consistency across the app.
|
| $forceCollapseAllExceptAction (bool, set by the including page):
|   - false (Current tab): normal progressive responsive collapsing —
|     columns hide one by one via data-priority only as screen width
|     runs out, exactly like the Patient List table.
|   - true (History tab): every column except Action gets DataTables
|     Responsive's "never" class, meaning they are ALWAYS hidden in the
|     main row (tucked under the "+" expand control) no matter how wide
|     the screen is. Action gets the "all" class so it's always visible
|     in the row itself.
|
| BUG FIX (function redeclare): this file is include()'d TWICE in the
| same request by view/patient.php (once per tab). An earlier version
| defined a rxColClass() function here, which caused a fatal "Cannot
| redeclare function" error on the second include — that's what was
| producing a completely blank page. Replaced with inline conditionals
| below so nothing gets declared twice.
|
| BUG FIX (DataTables _DT_CellIndex crash): this file used to render its
| own "No prescriptions found." row as a single <td colspan="7">. With
| the Responsive extension active (view/patient.php initializes with
| responsive: true), DataTables expects exactly one physical <td> per
| header column on every row it processes. A colspan row breaks that
| assumption — Responsive tries to look up a cell at a column index that
| doesn't physically exist and crashes trying to tag it with
| _DT_CellIndex ("Cannot set properties of undefined"). view/patient.php
| already configures language.emptyTable with this same message, so the
| fix is to stop hand-rendering the empty row entirely and let
| DataTables generate its own empty-table state (which is built to
| handle zero rows / column-count correctly). If $tableData is empty,
| <tbody> now just ends up with zero <tr> — that's expected and safe.
|--------------------------------------------------------------------------
*/
$forceCollapseAllExceptAction = $forceCollapseAllExceptAction ?? false;
?>
<table class="table table-bordered table-hover align-middle datatable w-100">
    <thead class="table-light">
        <tr>
            <th class="<?= $forceCollapseAllExceptAction ? 'never' : '' ?>" data-priority="6">#</th>
            <th class="<?= $forceCollapseAllExceptAction ? 'never' : '' ?>" data-priority="4">Prescription #</th>
            <th class="<?= $forceCollapseAllExceptAction ? 'never' : '' ?>" data-priority="2">Diagnosis</th>
            <th class="<?= $forceCollapseAllExceptAction ? 'never' : '' ?>" data-priority="5">Doctor</th>
            <th class="<?= $forceCollapseAllExceptAction ? 'never' : '' ?>" data-priority="3">Date</th>
            <th class="<?= $forceCollapseAllExceptAction ? 'never' : '' ?>" data-priority="1">Status</th>
            <th class="text-center <?= $forceCollapseAllExceptAction ? 'all' : '' ?>" data-priority="1">Action</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($tableData as $i => $rx): ?>
            <?php
                $pillClass = 'status-pill-secondary';
                $pillIcon = 'bi-question-circle';
                if ($rx['status'] === 'For Signing') { $pillClass = 'status-pill-warning'; $pillIcon = 'bi-hourglass-split'; }
                elseif ($rx['status'] === 'Signed') { $pillClass = 'status-pill-success'; $pillIcon = 'bi-check-circle-fill'; }
                elseif ($rx['status'] === 'Denied') { $pillClass = 'status-pill-danger'; $pillIcon = 'bi-x-circle-fill'; }
            ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($rx['prescription_number'] ?? $rx['id']) ?></td>
                <td><?= htmlspecialchars($rx['diagnosis']) ?></td>
                <td><?= htmlspecialchars(trim($rx['doctor_name'] ?? '') ?: 'Health Facility') ?></td>
                <td>
                    <span class="date-pill">
                        <?= htmlspecialchars(date('M j, Y g:i A', strtotime($rx['created_at']))) ?>
                    </span>
                </td>
                <td>
                    <span class="status-pill <?= $pillClass ?>">
                        <i class="bi <?= $pillIcon ?>"></i><?= htmlspecialchars($rx['status']) ?>
                    </span>
                </td>
                <td class="text-center">
                    <a href="../view/prescription.php?id=<?= (int)$rx['id'] ?>"
                       class="btn btn-sm btn-outline-secondary"
                       data-bs-toggle="tooltip"
                       data-bs-placement="top"
                       title="View Prescription">
                        <i class="bi bi-eye"></i>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>