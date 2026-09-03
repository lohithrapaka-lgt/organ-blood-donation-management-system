<?php
$host = 'localhost';
$dbname = 'organ_blood_donation';
$username = 'root';
$password = '';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $stmt = $pdo->query('SHOW TABLES');
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "TABLES:\n";
    print_r($tables);
    foreach($tables as $table) {
        $stmt2 = $pdo->query("DESCRIBE $table");
        echo "\nTable: $table\n";
        print_r($stmt2->fetchAll(PDO::FETCH_COLUMN));
    }
} catch (Exception $e) {
    echo $e->getMessage();
}
