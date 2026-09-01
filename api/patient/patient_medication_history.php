<?php
require '../../config/db.php';
header('Content-Type: application/json');
$patient_id = $_GET['patient_id'] ?? 0;
if(!$patient_id){
    echo json_encode([]);
    exit;
}
/*
 * FIX (Item 4 — Transmittal Status not updating in Medication History):
 * There is no `transmittal_status` column — confirmed by the fatal
 * error this caused. list/prescription.php already derives Transmitted
 * vs Pending from `transmitted_at` (a nullable timestamp: set once the
 * prescription is actually transmitted, NULL until then), so this uses
 * the same real column instead. The previous version of this query
 * selected neither column at all, which is why the frontend's fallback
 * to 'Pending' fired permanently regardless of the real state.
 */
$sql = "
SELECT 
    p.id,
    p.prescription_number,
    p.patient_id,
    p.diagnosis,
    p.remarks,
    p.status,
    p.is_refill,
    p.for_refill,
    p.transmitted_at,
    p.created_at,
    CONCAT(u.first_name,' ',u.last_name) AS doctor_name,
    GROUP_CONCAT(
        JSON_OBJECT(
            'medicine_name', pm.medicine_name,
            'dosage', pm.dosage,
            'frequency', pm.frequency,
            'duration', pm.duration,
            'quantity', pm.quantity,
            'status', pm.status,
            'notes', pm.notes
        )
    ) AS medicines_json
FROM prescriptions p
LEFT JOIN prescription_medicines pm 
    ON pm.prescription_id = p.id
LEFT JOIN users u 
    ON u.id = p.created_by
WHERE p.patient_id = ?
GROUP BY p.id
ORDER BY p.created_at DESC
LIMIT 5
";
$stmt = $conn->prepare($sql);
$stmt->execute([$patient_id]);
$data = [];
while($row = $stmt->fetch(PDO::FETCH_ASSOC)){
    if($row['medicines_json']){
        $row['medicines'] = json_decode("[".$row['medicines_json']."]", true);
    }else{
        $row['medicines'] = [];
    }
    unset($row['medicines_json']);
    $data[] = $row;
}
echo json_encode($data);