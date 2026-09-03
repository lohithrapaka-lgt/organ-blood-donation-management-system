<?php
$host = 'localhost';
$dbname = 'organ_blood_donation';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS organ_inventory (
        inventory_id INT AUTO_INCREMENT PRIMARY KEY,
        hospital_id INT NOT NULL,
        organ_type VARCHAR(50) NOT NULL,
        units_available INT DEFAULT 0
    )");

    // Prevent duplicate entries on multiple runs
    $pdo->exec("TRUNCATE TABLE organ_inventory;");

    // Insert dummy data
    $pdo->exec("INSERT INTO organ_inventory (hospital_id, organ_type, units_available) VALUES 
        (1, 'Kidney', 5), 
        (1, 'Liver', 2), 
        (2, 'Heart', 1), 
        (3, 'Lungs', 3)");

    echo "Dummy data inserted successfully.\n";

    $stmt = $pdo->query("SELECT * FROM organ_inventory");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>