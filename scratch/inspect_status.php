<?php
$pdo = new PDO("mysql:host=localhost;dbname=organ_blood_donation;charset=utf8", 'root', '');
echo "BLOOD BANKS:\n";
print_r($pdo->query("SELECT bank_id, name, status FROM blood_banks")->fetchAll(PDO::FETCH_ASSOC));
echo "HOSPITALS:\n";
print_r($pdo->query("SELECT hospital_id, name, status FROM hospitals")->fetchAll(PDO::FETCH_ASSOC));
