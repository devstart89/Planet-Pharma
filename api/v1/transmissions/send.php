<?php
require '../../../config/db.php';

header("Content-Type: application/json");

try {

    // ================= API KEY AUTH =================
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $apiKey = $headers['X-API-KEY'] ?? null;

    if (!$apiKey) {
        throw new Exception("API Key is required");
    }

    // ================= VALIDATE API KEY =================
    $stmt = $conn->prepare("
        SELECT facility_id, status 
        FROM api_keys 
        WHERE api_key = ? 
        LIMIT 1
    ");
    $stmt->execute([$apiKey]);

    $api = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$api || $api['status'] !== 'active') {
        throw new Exception("Invalid or inactive API key");
    }

    $facilityId = $api['facility_id'];

    if (empty($facilityId)) {
        throw new Exception("API key not linked to a facility");
    }

    // ================= FETCH TRANSMITTALS (FIXED FLOW) =================
    $stmt = $conn->prepare("
        SELECT 
            t.id AS transmittal_id,
            t.date_generated,
            t.prescription_date,

            p.id AS prescription_id,
            p.prescription_number,
            p.diagnosis,
            p.signed_at,

            pat.first_name,
            pat.last_name,

            hf.facility_name,

            CONCAT(u.first_name, ' ', u.last_name) AS doctor_name,

            pm.id AS medicine_id,
            pm.medicine_name,
            pm.quantity,
            pm.pharmacy_status

        FROM transmittals t

        INNER JOIN transmittal_prescriptions tp 
            ON tp.transmittal_id = t.id

        INNER JOIN prescriptions p 
            ON p.id = tp.prescription_id

        INNER JOIN patients pat 
            ON pat.id = p.patient_id

        LEFT JOIN users u 
            ON u.id = p.signed_by

        LEFT JOIN health_facilities hf 
            ON hf.id = p.facility_id

        INNER JOIN prescription_medicines pm 
            ON pm.prescription_id = p.id

        WHERE t.transmitted = 0
          AND t.facility_id = ?

        ORDER BY t.id DESC
        LIMIT 50
    ");

    $stmt->execute([$facilityId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo json_encode([
            "status" => "no_data",
            "message" => "No new transmittals available",
            "data" => []
        ]);
        exit;
    }

    // ================= GROUP DATA =================
    $transmittals = [];

    foreach ($rows as $row) {

        $tid = $row['transmittal_id'];
        $pid = $row['prescription_id'];

        if (!isset($transmittals[$tid])) {
            $transmittals[$tid] = [
                "transmittal_id" => $tid,
                "date_generated" => $row['date_generated'],
                "prescription_date" => $row['prescription_date'],
                "facility" => $row['facility_name'] ?? '-',
                "prescriptions" => []
            ];
        }

        if (!isset($transmittals[$tid]['prescriptions'][$pid])) {

            $transmittals[$tid]['prescriptions'][$pid] = [
                "prescription_id" => $pid,
                "prescription_number" => $row['prescription_number'] ?? '-',
                "diagnosis" => $row['diagnosis'] ?? '-',
                "signed_at" => $row['signed_at'] ?? null,

                "patient" => [
                    "name" => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))
                ],

                "doctor" => [
                    "name" => $row['doctor_name'] ?? '-'
                ],

                "medicines" => []
            ];
        }

        $transmittals[$tid]['prescriptions'][$pid]['medicines'][] = [
            "medicine_id" => $row['medicine_id'],
            "name" => $row['medicine_name'],
            "quantity" => $row['quantity'],
            "status" => $row['pharmacy_status']
        ];
    }

    // ================= FORMAT RESPONSE =================
    $result = array_values(array_map(function ($t) {
        $t['prescriptions'] = array_values($t['prescriptions']);
        return $t;
    }, $transmittals));

    // ================= RESPONSE =================
    echo json_encode([
        "status" => "success",
        "count" => count($result),
        "data" => [
            "source" => "eprescription_system_v1",
            "facility_id" => $facilityId,
            "timestamp" => date("Y-m-d H:i:s"),
            "transmittals" => $result
        ]
    ]);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}