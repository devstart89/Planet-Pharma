<?php
require '../../config/db.php';
header("Content-Type: application/json");

function response($status,$message,$extra=[]){
    echo json_encode(array_merge([
        "status"=>$status,
        "message"=>$message
    ],$extra));
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
$rows = $input['data'] ?? [];

if(empty($rows)){
    response("error","No data");
}

try {

    $conn->beginTransaction();

    $insert = $conn->prepare("
        INSERT INTO medicines
        (generic_name, brand_name, dosage, frequency, duration, description, status)
        VALUES
        (:generic_name,:brand_name,:dosage,:frequency,:duration,:description,'active')
    ");

    $check = $conn->prepare("
        SELECT id FROM medicines
        WHERE LOWER(TRIM(generic_name)) = LOWER(TRIM(:generic_name))
        AND (LOWER(TRIM(IFNULL(brand_name,''))) = LOWER(TRIM(IFNULL(:brand_name,''))))
        LIMIT 1
    ");

    $inserted = 0;
    $skipped = 0;
    $duplicates = [];

    foreach($rows as $r){

        if(empty($r['generic_name'])) continue;

        $check->execute([
            ":generic_name"=>$r['generic_name'],
            ":brand_name"=>$r['brand_name'] ?? ''
        ]);

        if($check->fetch()){
            $skipped++;
            $duplicates[] = $r['generic_name'];
            continue;
        }

        $insert->execute([
            ":generic_name"=>$r['generic_name'],
            ":brand_name"=>$r['brand_name'] ?? null,
            ":dosage"=>$r['dosage'] ?? null,
            ":frequency"=>$r['frequency'] ?? null,
            ":duration"=>$r['duration'] ?? null,
            ":description"=>$r['description'] ?? null
        ]);

        $inserted++;
    }

    $conn->commit();

    response("success","done",[
        "inserted"=>$inserted,
        "skipped"=>$skipped,
        "duplicates"=>$duplicates
    ]);

} catch(Exception $e){
    $conn->rollBack();
    response("error",$e->getMessage());
}