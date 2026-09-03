<?php
$host = 'localhost';
$dbname = 'organ_blood_donation';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get the last inserted donor ID from the setup script
    $donorIdResult = $pdo->query("SELECT donor_id FROM donors WHERE name = 'Matching Test Donor' ORDER BY donor_id DESC LIMIT 1")->fetchColumn();

    if (!$donorIdResult) {
        die("Test Donor not found.");
    }

    echo "Simulating availability update for Donor ID: $donorIdResult...<br>";

    // Simulate donor_dashboard.php logic
    $stmt = $pdo->prepare("UPDATE donors SET availability = 'available' WHERE donor_id = ?");
    $stmt->execute([$donorIdResult]);

    include_once 'match_logic.php';
    $result = triggerMatching($pdo, $donorIdResult);

    echo "Matching Result: $result<br>";

    // Verify Final Status
    $patientStatus = $pdo->query("SELECT status FROM patients WHERE name = 'Priority Patient'")->fetchColumn();
    echo "Current Patient Status: $patientStatus<br>";

    if ($patientStatus === 'donor_matched') {
        echo "SUCCESS: Matching logic worked correctly!";
    } else {
        echo "FAILURE: Matching logic did not update status correctly.";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>