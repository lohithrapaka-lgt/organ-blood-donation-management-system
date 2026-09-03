<?php
$host = 'localhost';
$dbname = 'organ_blood_donation';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);

    echo "Recent Users:\n";
    $stmt = $pdo->query("SELECT user_id, email, role, reference_id FROM users ORDER BY user_id DESC LIMIT 5");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $user) {
        echo "ID: {$user['user_id']} | Email: {$user['email']} | Role: {$user['role']} | RefID: {$user['reference_id']}\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>