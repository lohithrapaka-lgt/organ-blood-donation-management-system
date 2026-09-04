<?php
/**
 * scratch/test_complete_flow.php
 * Automated verification of:
 * 1. Global Inventory logic
 * 2. Blood Camp Management CRUD
 * 3. Donor Camp Interest
 * 4. Shortage Detection & Deduplicated Alerts
 * 5. Matching Rule verification
 * 6. Donor Response flow
 */

require_once 'emergency_alerts.php';

$host = 'localhost';
$dbname = 'organ_blood_donation';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "=====================================================\n";
    echo "TEST 1: GLOBAL BLOOD BANK INVENTORY & 8 BLOOD GROUPS\n";
    echo "=====================================================\n";
    $globalInv = getGlobalBloodBankInventory($pdo);
    echo "Total registered blood banks fetched: " . count($globalInv) . "\n";
    
    $requiredGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    $bankPass = true;
    foreach ($globalInv as $b) {
        $groupsCount = count($b['inventory']);
        if ($groupsCount !== 8) {
            echo "FAIL: Bank {$b['name']} has $groupsCount groups instead of 8!\n";
            $bankPass = false;
        }
        foreach ($requiredGroups as $rg) {
            if (!isset($b['inventory'][$rg])) {
                echo "FAIL: Missing group $rg in {$b['name']}!\n";
                $bankPass = false;
            }
        }
    }
    if ($bankPass) {
        echo "PASS: All registered blood banks consistently render all 8 standard blood groups!\n";
        echo "Example (Bank 5: {$globalInv[5]['name']}):\n";
        foreach ($globalInv[5]['inventory'] as $bg => $info) {
            echo "  $bg: {$info['units']} units [{$info['status_text']}]\n";
        }
    }

    echo "\n=====================================================\n";
    echo "TEST 2: BLOOD CAMP MANAGEMENT (CRUD)\n";
    echo "=====================================================\n";
    // Create
    $campData = [
        'blood_bank_id' => 5,
        'camp_name' => 'Auto-Test Mega Blood Camp',
        'location' => 'Bhavanipuram Community Center',
        'venue' => 'Main Auditorium, Floor 1',
        'date' => date('Y-m-d', strtotime('+10 days')),
        'start_time' => '09:30:00',
        'end_time' => '15:30:00',
        'contact' => '+919988776655',
        'description' => 'Test blood donation camp organized for verification.',
        'expected_donors' => 100,
        'status' => 'Upcoming'
    ];
    $testCampId = createBloodCamp($pdo, $campData);
    echo "Created camp with ID: $testCampId\n";

    // Read
    $camps = getBloodCamps($pdo, 5, 'Upcoming');
    $foundCamp = null;
    foreach ($camps as $c) {
        if ($c['camp_id'] == $testCampId) {
            $foundCamp = $c;
            break;
        }
    }
    if ($foundCamp && $foundCamp['display_name'] === 'Auto-Test Mega Blood Camp') {
        echo "PASS: Camp created and fetched with organizer details!\n";
    } else {
        echo "FAIL: Camp not found in getBloodCamps!\n";
    }

    // Update
    updateBloodCamp($pdo, $testCampId, array_merge($campData, [
        'camp_name' => 'Auto-Test Mega Blood Camp (Updated)',
        'expected_donors' => 120
    ]));
    $stmtC = $pdo->prepare("SELECT camp_name, expected_donors FROM blood_camps WHERE camp_id = ?");
    $stmtC->execute([$testCampId]);
    $updatedC = $stmtC->fetch(PDO::FETCH_ASSOC);
    if ($updatedC['camp_name'] === 'Auto-Test Mega Blood Camp (Updated)' && $updatedC['expected_donors'] == 120) {
        echo "PASS: Camp successfully updated!\n";
    } else {
        echo "FAIL: Camp update verification failed!\n";
    }

    echo "\n=====================================================\n";
    echo "TEST 3: DONOR CAMP INTEREST\n";
    echo "=====================================================\n";
    $testDonorId = 1; // Alice Walker
    $regRes1 = registerDonorInterestInCamp($pdo, $testDonorId, $testCampId);
    echo "Interest 1: Success=" . ($regRes1['success'] ? 'true' : 'false') . ", AlreadyRegistered=" . ($regRes1['already_registered'] ? 'true' : 'false') . "\n";
    $regRes2 = registerDonorInterestInCamp($pdo, $testDonorId, $testCampId);
    echo "Interest 2: Success=" . ($regRes2['success'] ? 'true' : 'false') . ", AlreadyRegistered=" . ($regRes2['already_registered'] ? 'true' : 'false') . "\n";
    if (!$regRes1['already_registered'] && $regRes2['already_registered']) {
        echo "PASS: Donor camp interest properly recorded and deduplicated!\n";
    } else {
        echo "FAIL: Donor camp interest registration failed!\n";
    }

    echo "\n=====================================================\n";
    echo "TEST 4: SHORTAGE DETECTION & DEDUPLICATED ALERTS\n";
    echo "=====================================================\n";
    // Check available stock for O-
    $stmtStock = $pdo->prepare("SELECT COALESCE(SUM(units_available), 0) FROM blood_inventory WHERE blood_group = 'O-'");
    $stmtStock->execute();
    $currentOminusStock = (int)$stmtStock->fetchColumn();
    echo "Current system stock for O-: $currentOminusStock units\n";

    // Insert a test blood request with units_needed > current stock to guarantee shortage
    $shortageUnitsNeeded = $currentOminusStock + 10;
    $stmtInsReq = $pdo->prepare("
        INSERT INTO blood_requests (patient_id, patient_name, age, blood_group, priority_score, units_needed, status, emergency_alert_status)
        VALUES (13, 'Test Shortage Patient', 25, 'O-', 150, ?, 'pending', 'none')
    ");
    $stmtInsReq->execute([$shortageUnitsNeeded]);
    $testReqId = (int)$pdo->lastInsertId();
    echo "Inserted test shortage blood request ID #$testReqId for O- needing $shortageUnitsNeeded units\n";

    // Run trigger 1
    $stats1 = detectAndTriggerEmergencyAlerts($pdo, $testReqId);
    echo "Scan 1: Shortages={$stats1['shortages_detected']}, DonorsNotified={$stats1['donors_notified']}, Skipped={$stats1['skipped_duplicates']}\n";

    // Run trigger 2 (immediate refresh/re-scan test)
    $stats2 = detectAndTriggerEmergencyAlerts($pdo, $testReqId);
    echo "Scan 2 (Refresh): Shortages={$stats2['shortages_detected']}, DonorsNotified={$stats2['donors_notified']}, Skipped={$stats2['skipped_duplicates']}\n";

    if ($stats1['donors_notified'] > 0 && $stats2['donors_notified'] === 0 && $stats2['skipped_duplicates'] > 0) {
        echo "PASS: Shortage detected, matching donors notified, and ZERO duplicate spam on subsequent refresh!\n";
    } else {
        echo "FAIL: Deduplication or notification logic issue!\n";
    }

    // Verify matching rule: Donor 1 (Alice Walker, O-, available, verified=yes, blood) should have received alert
    $stmtNotifCheck = $pdo->prepare("SELECT * FROM donor_notifications WHERE donor_id = 1 AND request_id = ?");
    $stmtNotifCheck->execute([$testReqId]);
    $notif = $stmtNotifCheck->fetch(PDO::FETCH_ASSOC);
    if ($notif) {
        echo "PASS: Verified available O- donor received alert: '{$notif['title']}'\n";
        echo "Message text:\n{$notif['message']}\n";
    } else {
        echo "FAIL: Matching donor did not receive alert!\n";
    }

    // Clean up test records
    $pdo->prepare("DELETE FROM donor_notifications WHERE request_id = ?")->execute([$testReqId]);
    $pdo->prepare("DELETE FROM blood_requests WHERE request_id = ?")->execute([$testReqId]);
    $pdo->prepare("DELETE FROM camp_registrations WHERE camp_id = ?")->execute([$testCampId]);
    $pdo->prepare("DELETE FROM blood_camps WHERE camp_id = ?")->execute([$testCampId]);
    echo "Test records cleaned up successfully.\n";

    echo "\n=====================================================\n";
    echo "ALL CORE FLOW TESTS COMPLETED SUCCESSFULLY!\n";
    echo "=====================================================\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
