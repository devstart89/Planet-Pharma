<?php

require '../../config/db.php';

header('Content-Type: application/json');


$cluster = $_GET['cluster'] ?? null;


if(!$cluster){

echo json_encode([]);

exit;

}


$stmt=$conn->prepare("
SELECT id,name
FROM hf_description
WHERE cluster_id=?
ORDER BY name ASC
");


$stmt->execute([$cluster]);


echo json_encode(
$stmt->fetchAll(PDO::FETCH_ASSOC)
);