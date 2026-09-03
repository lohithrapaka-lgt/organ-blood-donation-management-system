<?php
$host = 'localhost';
$dbname = 'organ_blood_donation';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    
    echo "=== BLOOD BANKS ===\n";
    print_r($pdo->query("SELECT * FROM blood_banks")->fetchAll(PDO::FETCH_ASSOC));
    
    echo "=== HOSPITALS ===\n";
    print_r($pdo->query("SELECT * FROM hospitals")->fetchAll(PDO::FETCH_ASSOC));

    echo "=== ORGAN INVENTORY ===\n";
    print_r($pdo->query("SELECT * FROM organ_inventory")->fetchAll(PDO::FETCH_ASSOC));

    echo "=== BLOOD INVENTORY ===\n";
    print_r($pdo->query("SELECT * FROM blood_inventory")->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
