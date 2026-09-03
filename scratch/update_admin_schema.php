<?php
$host = 'localhost';
$dbname = 'organ_blood_donation';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Updating schema for Hospitals and Blood Banks to include 'rejected' status...\n";

    // 1. Update Hospitals
    $pdo->exec("ALTER TABLE hospitals MODIFY COLUMN status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending'");
    echo "- Hospitals table updated.\n";

    // 2. Update Blood Banks
    $pdo->exec("ALTER TABLE blood_banks MODIFY COLUMN status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending'");
    echo "- Blood Banks table updated.\n";

    echo "Schema update completed successfully.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>