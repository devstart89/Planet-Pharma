<?php
require '../../config/db.php';

header("Content-Type: application/json");

// 🔴 CHANGE THIS → pharmacy endpoint
$webhookUrl = "https://webhook.site/78838079-9d3f-4ded-bac3-2a64ace27388";

try {

    // 🔐 GET API KEY + SECRET
    $stmt = $conn->prepare("
        SELECT api_key, secret_key 
        FROM api_keys 
        WHERE status='active' 
        LIMIT 1
    ");
    $stmt->execute();
    $api = $stmt->fetch();

    if (!$api) {
        throw new Exception("No active API key found");
    }

    $apiKey = $api['api_key'];
    $secret = $api['secret_key'];

    if (!$secret) {
        throw new Exception("Secret key is missing");
    }

    // ✅ FETCH ONLY NOT TRANSMITTED
    $stmt = $conn->query("
        SELECT 
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
            pm.pharmacy_status,

            t.id AS transmittal_id

        FROM prescriptions p
        JOIN patients pat ON pat.id = p.patient_id
        LEFT JOIN users u ON u.id = p.doctor_id
        LEFT JOIN health_facilities hf ON hf.id = p.facility_id
        JOIN prescription_medicines pm ON pm.prescription_id = p.id
        JOIN transmittals t ON t.id = p.transmittal_id

        WHERE p.status = 'Signed'
        AND t.transmitted = 0

        ORDER BY p.id DESC
        LIMIT 20
    ");

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo json_encode([
            "status" => "no_data",
            "message" => "No new prescriptions to transmit"
        ]);
        exit;
    }

    // 🧠 GROUP DATA (Prescription → Medicines)
    $prescriptions = [];

    foreach ($rows as $row) {

        $pid = $row['prescription_id'];

        if (!isset($prescriptions[$pid])) {
            $prescriptions[$pid] = [
                "prescription_id" => $pid,
                "prescription_number" => $row['prescription_number'],
                "diagnosis" => $row['diagnosis'],
                "signed_at" => $row['signed_at'],
                "facility" => $row['facility_name'],
                "patient" => [
                    "name" => $row['first_name'] . ' ' . $row['last_name']
                ],
                "doctor" => [
                    "name" => $row['doctor_name']
                ],
                "medicines" => []
            ];
        }

        $prescriptions[$pid]['medicines'][] = [
            "medicine_id" => $row['medicine_id'],
            "name" => $row['medicine_name'],
            "quantity" => $row['quantity'],
            "status" => $row['pharmacy_status']
        ];
    }

    // 📦 FINAL PAYLOAD
    $payloadArray = [
        "source" => "epscription_system",
        "timestamp" => date("Y-m-d H:i:s"),
        "prescriptions" => array_values($prescriptions)
    ];

    $payload = json_encode($payloadArray);

    // 🔐 SIGN REQUEST
    $timestamp = time();
    $nonce = bin2hex(random_bytes(8));

    $signature = hash_hmac(
        'sha256',
        $timestamp . '.' . $nonce . '.' . $payload,
        $secret
    );

    // 🚀 SEND REQUEST
    $ch = curl_init($webhookUrl);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "X-API-KEY: $apiKey",
            "X-TIMESTAMP: $timestamp",
            "X-NONCE: $nonce",
            "X-SIGNATURE: $signature"
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        throw new Exception(curl_error($ch));
    }

    curl_close($ch);

    // ✅ ONLY MARK TRANSMITTED IF SUCCESS
    if ($httpCode === 200) {

        $transIds = array_unique(array_column($rows, 'transmittal_id'));

        if (!empty($transIds)) {

            $placeholders = implode(',', array_fill(0, count($transIds), '?'));

            $stmt = $conn->prepare("
                UPDATE transmittals 
                SET transmitted = 1, transmitted_at = NOW()
                WHERE id IN ($placeholders)
            ");

            $stmt->execute($transIds);
        }
    }

    echo json_encode([
        "status" => "sent_securely",
        "http_code" => $httpCode,
        "transmitted_ids" => array_values(array_unique(array_column($rows, 'transmittal_id'))),
        "response" => $response
    ]);

} catch (Exception $e) {

    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}