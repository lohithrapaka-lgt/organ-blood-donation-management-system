<?php
$host = 'localhost';
$dbname = 'organ_blood_donation';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Updating patients table status ENUM...<br>";
    $pdo->exec("ALTER TABLE patients MODIFY COLUMN status ENUM('pending', 'approved', 'fulfilled', 'waiting_for_donor', 'donor_matched') DEFAULT 'pending'");
    echo "Successfully updated patients table status ENUM.<br>";

} catch (PDOException $e) {
    echo "Database Error: " . htmlspecialchars($e->getMessage());
}
?>