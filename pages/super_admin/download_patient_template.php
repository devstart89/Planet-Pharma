<?php
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="patients_template.csv"');

$output = fopen("php://output", "w");

/**
 * HEADER (NO HIS_ID, NO AGE)
 */
fputcsv($output, [
    "last_name",
    "first_name",
    "middle_name",
    "suffix",
    "gender",
    "birthday",
    "civil_status",
    "house_no_street",
    "barangay",
    "contact_number",
    "makati_health_plus_no",
    "expiration_date",
    "membership_type",
    "house_address",
    "makati_employee",
    "status"
]);

/**
 * SAMPLE ROW (CLEAN + REALISTIC)
 */
fputcsv($output, [
    "Doe",
    "John",
    "",
    "",
    "MALE",
    "1987-07-15",
    "MARRIED",
    "Street 1",
    "Brgy 1",
    "09123456789",
    "1234-567890",
    "2026-12-31",
    "MCG-SOLO",
    "Unit 101, Sample Address",
    "NO",
    "ACTIVE"
]);

fclose($output);
exit;