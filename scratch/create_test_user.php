<?php
$host = 'localhost';
$dbname = 'organ_blood_donation';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);

    $pId = $pdo->query("SELECT patient_id FROM patients WHERE name = 'Priority Patient'")->fetchColumn();
    echo "Patient ID: $pId\n";

    // 2. Prevent duplicate user insertion
    $email = 'patient_test@example.com';
    $stmtCheck = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmtCheck->execute([$email]);
    if ($stmtCheck->fetchColumn()) {
        echo "User already exists: $email. Skipping insertion.\n";
    } else {
        // Create a user for this patient
        $stmt = $pdo->prepare("INSERT INTO users (email, password, role, reference_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$email, password_hash('test123', PASSWORD_BCRYPT), 'patient', $pId]);
        echo "User created: $email / test123\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>