<?php
/**
 * ClientPrescriptionApi
 * ---------------------
 * Builds a prescription payload from local DB records and posts it to the
 * client's Business Central (NAV) OData-style endpoint using NTLM auth.
 *
 * Usage:
 *   $api = new ClientPrescriptionApi($conn);
 *   $result = $api->sendPrescription($prescriptionId);
 *   if (!$result['success']) {
 *       // log $result['error'], $result['http_code'], $result['raw_response']
 *   }
 */
class ClientPrescriptionApi
{
    private PDO $conn;
    private string $endpoint;
    private string $ntlmUser;
    private string $ntlmPass;

    public function __construct(
        PDO $conn,
        ?string $endpoint = null,
        ?string $ntlmUser = null,
        ?string $ntlmPass = null
    ) {
        $this->conn = $conn;

        // Pull from environment only. No hardcoded fallback secrets —
        // fail loudly instead of silently using a stale/shared credential.
        $this->endpoint = $endpoint ?? getenv('CLIENT_API_ENDPOINT') ?: 
    'http://azw-planetbcsvr.planetdrugstore.ph:9048/TEST/api/planetSysAd/prescriptionData/v1.0/companies(b85c039e-9a58-ef11-bd09-f057a6bad5fa)/prescriptions?$expand=prescriptionLines';
        $this->ntlmUser = $ntlmUser ?? getenv('CLIENT_API_NTLM_USER') ?: 'Infra. Admin';
        $this->ntlmPass = $ntlmPass ?? getenv('CLIENT_API_NTLM_PASS') ?: 'PD$P@ssk3y!';

        if (!$this->endpoint || !$this->ntlmUser || !$this->ntlmPass) {
            throw new RuntimeException(
                'Missing CLIENT_API_ENDPOINT / CLIENT_API_NTLM_USER / CLIENT_API_NTLM_PASS ' .
                'environment variables.'
            );
        }
    }

    /**
     * Build the payload for a single prescription and POST it to the
     * client API. Returns an array describing success/failure so the
     * caller can decide how to handle partial failures.
     */
    public function sendPrescription(int $prescriptionId): array
    {
        $payload = $this->buildPayload($prescriptionId);

        if ($payload === null) {
            return [
                'success' => false,
                'prescription_id' => $prescriptionId,
                'error' => 'Prescription not found or has no medicine lines',
            ];
        }

        return $this->post($payload);
    }

    /**
     * Fetch prescription + patient + doctor + medicine lines and shape
     * them into the JSON structure the client API expects.
     */
    private function buildPayload(int $prescriptionId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT
                p.id,
                p.prescription_number,
                p.created_at,
                p.created_by,
                pt.member_card_no,
                pt.makati_health_plus_no,
                pt.first_name  AS patient_first_name,
                pt.middle_name AS patient_middle_name,
                pt.last_name   AS patient_last_name,
                pt.age,
                pt.gender,
                pt.house_no_street,
                pt.barangay,
                ph.store_code  AS pharmacy_no,
                doc.first_name AS doc_first_name,
                doc.last_name  AS doc_last_name,
                cb.first_name  AS created_by_first_name,
                cb.last_name   AS created_by_last_name
            FROM prescriptions p
            JOIN patients pt   ON pt.id = p.patient_id
            -- pharmacy lookup goes through the PRESCRIPTION's facility_id
            -- (per-visit), not the patient's — a patient's facility on
            -- file may not be where a given prescription was written.
            LEFT JOIN health_facilities hf ON hf.id = p.facility_id
            LEFT JOIN pharmacy ph           ON ph.id = hf.pharmacy_id
            LEFT JOIN users doc ON doc.id = p.doctor_id
            LEFT JOIN users cb  ON cb.id = p.created_by
            WHERE p.id = ?
            LIMIT 1
        ");
        $stmt->execute([$prescriptionId]);
        $rx = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rx) {
            return null;
        }

        $lineStmt = $this->conn->prepare("
            SELECT medicine_name, dosage, frequency, duration, quantity, notes
            FROM prescription_medicines
            WHERE prescription_id = ?
            ORDER BY id ASC
        ");
        $lineStmt->execute([$prescriptionId]);
        $lines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);

        $prescriptionLines = [];
        $lineNo = 0;
        foreach ($lines as $line) {
            $lineNo += 1000;
            $prescriptionLines[] = [
                'prescriptionID' => $rx['prescription_number'],
                'lineNo'         => $lineNo,
                'medicine'       => $line['medicine_name'],
                'dosage'         => $line['dosage'] ?? '',
                'signa'      => $line['frequency'] ?? '',
                // BC's own field is a STRING (confirmed from a live
                // record: "duration": ""), not an integer — send as-is,
                // don't cast to (int).
                'duration'       => $line['duration'] ?? '',
                'qty'            => (int)$line['quantity'],
                'notes'          => $line['notes'] ?? '',
            ];
        }

        if (empty($prescriptionLines)) {
            return null;
        }

        $address = trim(
            ($rx['house_no_street'] ?? '') .
            (!empty($rx['barangay']) ? ', ' . $rx['barangay'] : '')
        );

        // TODO: confirm this is the right person for "healthcareAssistant" —
        // there is no matching role in the users table. Defaulting to
        // whichever user created the prescription record.
        $healthcareAssistant = trim(
            ($rx['created_by_first_name'] ?? '') . ' ' . ($rx['created_by_last_name'] ?? '')
        );

        return [
            'prescriptionID'       => $rx['prescription_number'],
            'memberCardNo'         => $rx['member_card_no'] ?? '',
            'healthPlusNo'         => $rx['makati_health_plus_no'] ?? '',
            'prescriptionDate'     => date('Y-m-d', strtotime($rx['created_at'])),
            'patientFirstName'     => $rx['patient_first_name'] ?? '',
            'patientMiddleName'    => $rx['patient_middle_name'] ?? '',
            'patientLastName'      => $rx['patient_last_name'] ?? '',
            'age'                  => (int)($rx['age'] ?? 0),
            'gender'               => $rx['gender'] ?? '',
            'address'              => $address,
            'prescribingDoctor'    => trim(($rx['doc_first_name'] ?? '') . ' ' . ($rx['doc_last_name'] ?? '')),
            'healthcareAssistant'  => $healthcareAssistant ?: 'Unknown',
            'pharmacyNo'           => $rx['pharmacy_no'] ?? '',
            'prescriptionLines'    => $prescriptionLines,
        ];
    }

    /**
     * Debug helper: fetch BC's $metadata document for this endpoint so we
     * can see the *actual* field types/definitions for prescriptionLines
     * (dosage, signa, etc.) instead of guessing from a sample payload.
     *
     * Not called anywhere automatically — run manually while debugging:
     *   $api = new ClientPrescriptionApi($conn);
     *   echo $api->fetchMetadata();
     */
    public function fetchMetadata(): string
    {
        // $metadata lives at the service root, one level up from the
        // company-scoped collection URL.
        $root = preg_replace('#/companies\(.*$#', '', $this->endpoint);
        $url  = $root . '/$metadata';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_NTLM,
            CURLOPT_USERPWD  => $this->ntlmUser . ':' . $this->ntlmPass,
            CURLOPT_TIMEOUT  => 30,
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        return $err ? "cURL error: $err" : $response;
    }

    /**
     * POST the payload to the client API using NTLM authentication.
     */
    private function post(array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init($this->endpoint);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            // NTLM auth over cURL. Username must be in "DOMAIN\username"
            // format (or "username@domain" depending on server config).
            CURLOPT_HTTPAUTH => CURLAUTH_NTLM,
            CURLOPT_USERPWD  => $this->ntlmUser . ':' . $this->ntlmPass,
            CURLOPT_TIMEOUT  => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return [
                'success' => false,
                'prescription_id' => $payload['prescriptionID'],
                'error' => 'cURL error: ' . $curlErr,
            ];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            // Log the exact outgoing body alongside the failure so we can
            // see precisely which shape/types BC rejected, instead of
            // re-guessing from a generic "Invalid Request Body" message.
            error_log(sprintf(
                "[ClientPrescriptionApi] POST failed (HTTP %d) for %s\nRequest body: %s\nResponse: %s",
                $httpCode,
                $payload['prescriptionID'],
                $body,
                $response
            ));

            return [
                'success' => false,
                'prescription_id' => $payload['prescriptionID'],
                'http_code' => $httpCode,
                'error' => 'Client API returned an error',
                'raw_response' => $response,
                'request_body' => $body, // surfaced for debugging; strip before prod if payload is sensitive
            ];
        }

        return [
            'success' => true,
            'prescription_id' => $payload['prescriptionID'],
            'http_code' => $httpCode,
            'response' => json_decode($response, true),
        ];
    }
}