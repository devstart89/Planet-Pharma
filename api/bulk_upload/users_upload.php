<?php
require '../../config/db.php';

header('Content-Type: application/json');

if(!isset($_FILES['file'])){
    echo json_encode([
        "status" => "error",
        "message" => "No file uploaded."
    ]);
    exit;
}

$file = fopen($_FILES['file']['tmp_name'], "r");

if(!$file){
    echo json_encode([
        "status" => "error",
        "message" => "Unable to read file."
    ]);
    exit;
}

/* Default Password */
$defaultPassword = password_hash("epscript.1", PASSWORD_DEFAULT);

$count = 0;
$rowNum = 1; // header is row 1
$errors = [];

/* Skip CSV Header */
fgetcsv($file);

$insertStmt = $conn->prepare("
    INSERT INTO users
    (first_name,last_name,middle_name,username,email,password,role,facility_id)
    VALUES (?,?,?,?,?,?,?,?)
");

/* Reusable lookups to catch duplicates before hitting the DB constraint */
$checkEmailStmt    = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$checkUsernameStmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");

while(($row = fgetcsv($file, 1000, ",")) !== FALSE){

    $rowNum++;

    // Skip fully blank lines
    if(count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue;

    $first_name  = trim($row[0] ?? '');
    $last_name   = trim($row[1] ?? '');
    $middle_name = trim($row[2] ?? '');
    $username    = trim($row[3] ?? '');
    $email       = trim($row[4] ?? '');
    $role        = trim($row[5] ?? '');
    $facility_id = trim($row[6] ?? '');

    /* ---------- REQUIRED FIELDS ---------- */
    if($first_name === '' || $last_name === '' || $username === '' || $email === ''){
        $errors[] = "Row $rowNum: Missing required field(s) (first name, last name, username, or email).";
        continue;
    }

    /* ---------- EMAIL FORMAT VALIDATION ---------- */
    if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
        $errors[] = "Row $rowNum: Invalid email format \"$email\".";
        continue;
    }

    /* ---------- USERNAME FORMAT VALIDATION ---------- */
    // Letters, numbers, dots, underscores, hyphens only, 3-50 chars
    if(!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)){
        $errors[] = "Row $rowNum: Invalid username \"$username\" (use 3-50 letters, numbers, ., _, or -).";
        continue;
    }

    /* ---------- DUPLICATE EMAIL CHECK ---------- */
    $checkEmailStmt->execute([$email]);
    if($checkEmailStmt->fetch()){
        $errors[] = "Row $rowNum: Email already exists \"$email\".";
        continue;
    }

    /* ---------- DUPLICATE USERNAME CHECK ---------- */
    $checkUsernameStmt->execute([$username]);
    if($checkUsernameStmt->fetch()){
        $errors[] = "Row $rowNum: Username already exists \"$username\".";
        continue;
    }

    /* ---------- INSERT ---------- */
    try{

        $insertStmt->execute([
            $first_name,
            $last_name,
            $middle_name,
            $username,
            $email,
            $defaultPassword,
            $role,
            $facility_id !== '' ? $facility_id : null
        ]);

        $count++;

    }catch(Exception $e){

        $error = $e->getMessage();

        if(str_contains($error, 'Duplicate entry')){
            $errors[] = "Row $rowNum: Duplicate entry for \"$username\" / \"$email\".";
        }else{
            $errors[] = "Row $rowNum: Failed to insert (invalid role or facility_id?).";
        }
    }
}

fclose($file);

if($count > 0){
    echo json_encode([
        "status"  => "success",
        "message" => "$count user(s) uploaded successfully." .
                      (count($errors) ? " " . count($errors) . " row(s) skipped." : ""),
        "errors"  => $errors
    ]);
}else{
    echo json_encode([
        "status"  => "error",
        "message" => count($errors)
            ? "No users were uploaded. " . count($errors) . " row(s) had errors."
            : "No valid rows found in CSV.",
        "errors"  => $errors
    ]);
}
?>
