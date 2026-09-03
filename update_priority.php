<?php
/**
 * Script to calculate and update priority_score for all patients
 * in the Organ and Blood Donation System.
 */

require_once 'priority_calc.php';

// Database Configuration
$host = 'localhost';
$dbname = 'organ_blood_donation';
$username = 'root'; // Adjust to your MySQL username
$password = '';     // Adjust to your MySQL password

try {
    // Determine connection via PDO
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);

    // Set error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Fetch patient records (target a specific patient if $target_patient_id exists, else all)
    if (isset($target_patient_id)) {
        $query = "SELECT patient_id, age, `condition`, request_date, request_type, organ_needed FROM patients WHERE patient_id = " . (int) $target_patient_id;
    } else {
        $query = "SELECT patient_id, age, `condition`, request_date, request_type, organ_needed FROM patients";
    }
    $stmt = $pdo->query($query);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare update statement for efficiency
    $updateQuery = "UPDATE patients SET priority_score = :priority_score WHERE patient_id = :patient_id";
    $updateStmt = $pdo->prepare($updateQuery);

    $updatedCount = 0;
    $currentDate = new DateTime(); // Get the current date and time

    // 2. Iterate through each patient to calculate their priority score
    foreach ($patients as $patient) {
        $age = $patient['age'] ?? 0;
        $condition = $patient['condition'] ?? 'normal';
        $request_type = $patient['request_type'] ?? '';
        $organ_needed = $patient['organ_needed'] ?? '';
        $request_date = $patient['request_date'] ?? null;

        $priorityScore = calculatePriority($age, $condition, $request_type, $organ_needed, $request_date);

        // 3. Update the priority_score column in the patient table
        $updateStmt->execute([
            ':priority_score' => $priorityScore,
            ':patient_id' => $patient['patient_id']
        ]);

        $updatedCount++;
    }

    echo "<h3>Success!</h3>";
    echo "<p>Priority scores have been successfully calculated and updated for <strong>$updatedCount</strong> patients.</p>";

} catch (PDOException $e) {
    // Handle database-related errors
    echo "<h3>Database Error</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
} catch (Exception $e) {
    // Handle other general errors (e.g., DateTime parsing)
    echo "<h3>General Error</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>