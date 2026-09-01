<?php
/**
 * Generates a CSV template for bulk user upload.
 * Column order MUST match what ../bulk_upload/users_upload.php expects:
 * first_name, last_name, middle_name, email, role, facility_id
 * (password is not included - a default password is applied on upload)
 */

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="users_template.csv"');

$output = fopen('php://output', 'w');

// Header row
fputcsv($output, ['first_name', 'last_name', 'middle_name', 'usernmae', 'email', 'role', 'facility_id']);

// Example row to show the expected format
fputcsv($output, ['Juan', 'Dela Cruz', 'Santos', 'juan', 'juan.delacruz@example.com', 'health_facility', '1']);

fclose($output);
exit;