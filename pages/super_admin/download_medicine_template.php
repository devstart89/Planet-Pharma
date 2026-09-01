<?php
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="medicines_template.csv"');

$output = fopen("php://output", "w");

/* =========================
   HEADERS (MATCH DB FIELDS)
========================= */
fputcsv($output, [
    "generic_name",
    "brand_name",
    "dosage",
    "frequency",
    "duration",
    "description"
]);

/* =========================
   SAMPLE DATA (GUIDE)
========================= */
fputcsv($output, [
    "Paracetamol",
    "Biogesic",
    "500 mg",
    "1-1-1",
    "3 days",
    "For fever and pain"
]);

fputcsv($output, [
    "Amoxicillin",
    "Amoxil",
    "500 mg",
    "1-1-1",
    "7 days",
    "Antibiotic"
]);

fputcsv($output, [
    "Ibuprofen",
    "Advil",
    "400 mg",
    "1-1-1",
    "5 days",
    "Pain reliever"
]);

fclose($output);
exit;