<?php
$host = 'localhost';
$dbname = 'organ_blood_donation';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // 5. Cleanup existing test data to prevent duplicates
    $pdo->exec("DELETE FROM donor_responses WHERE patient_id IN (SELECT patient_id FROM patients WHERE name = 'Priority Patient')");
    $pdo->exec("DELETE FROM patients WHERE name = 'Priority Patient'");
    $pdo->exec("DELETE FROM donors WHERE name = 'Matching Test Donor'");

    // 1. Create a donor who is NOT available yet
    $pdo->exec("INSERT INTO donors (name, age, blood_group, donor_type, organ_type, availability, verified, contact) 
                VALUES ('Matching Test Donor', 40, 'A+', 'organ', 'Liver', 'not_available', 'yes', 'test@example.com')");
    $testDonorId = $pdo->lastInsertId();

    // 2. Create a patient who is 'waiting_for_donor'
    $pdo->exec("INSERT INTO patients (name, age, blood_group, request_type, organ_needed, `condition`, status, priority_score) 
                VALUES ('Priority Patient', 35, 'A+', 'organ', 'Liver', 'critical', 'waiting_for_donor', 500)");
    $testPatientId = $pdo->lastInsertId();

    echo "Setup Complete!<br>";
    echo "Donor ID: $testDonorId (A+, Liver, Not Available)<br>";
    echo "Patient ID: $testPatientId (A+, Liver, Waiting for Donor, Priority 500)<br>";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>