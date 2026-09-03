<?php
/**
 * Script to automatically match pending patients with blood inventory or organ donors
 * based on priority score.
 */

$host = 'localhost';
$dbname = 'organ_blood_donation';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Minor DB Fix: The 'status' column in the patients schema did not originally support 'waiting_for_donor'.
    // minor DB Fix: The 'status' column in the patients schema dynamically altered
    // to ensure all statuses required by the state machine are available!
    $pdo->exec("ALTER TABLE patients MODIFY COLUMN status ENUM('pending', 'waiting_for_donor', 'donor_matched', 'approved', 'fulfilled', 'rejected') DEFAULT 'pending'");

    // Ensure UNIQUE constraint exists for donor_id and patient_id combo
    try {
        $pdo->exec("ALTER TABLE donor_responses ADD CONSTRAINT unique_donor_patient UNIQUE (donor_id, patient_id)");
    } catch (PDOException $e) {
        // Constraint already exists or is ignorable
    }

    echo "<p>Matching system executed</p>";

    // 1. Fetch pending patients (target a specific patient if $target_patient_id exists, else all)
    if (isset($target_patient_id)) {
        $queryPatients = "SELECT * FROM patients WHERE status = 'pending' AND patient_id = " . (int) $target_patient_id . " ORDER BY priority_score DESC, request_date ASC";
    } else {
        $queryPatients = "SELECT * FROM patients WHERE status = 'pending' ORDER BY priority_score DESC, request_date ASC";
    }
    $patients = $pdo->query($queryPatients)->fetchAll(PDO::FETCH_ASSOC);

    // Prepare Blood Request SQL Statements
    $stmtCheckBloodReq = $pdo->prepare("SELECT request_id, units_needed FROM blood_requests WHERE patient_id = :patient_id");

    // We will dynamically create the reduce blood statement inside the loop where we know units_needed
    $stmtInsertBloodReq = $pdo->prepare("INSERT INTO blood_requests (patient_id, patient_name, age, bank_id, blood_group, priority_score, units_needed, status) VALUES (:patient_id, :patient_name, :age, :bank_id, :bg, :priority_score, :units_needed, 'approved')");
    $stmtUpdateBloodReq = $pdo->prepare("UPDATE blood_requests SET status = 'approved', bank_id = :bank_id WHERE patient_id = :patient_id");

    $stmtApprovePatient = $pdo->prepare("UPDATE patients SET status = 'approved' WHERE patient_id = :patient_id");

    // Prepare Organ Request SQL Statements
    $stmtFindDonor = $pdo->prepare("
        SELECT donor_id 
        FROM donors 
        WHERE blood_group = :bg 
          AND organ_type = :organ 
          AND availability = 'available' 
          AND verified = 'yes' 
        LIMIT 1
    ");
    $stmtInsertResponse = $pdo->prepare("INSERT INTO donor_responses (donor_id, patient_id, response) VALUES (:donor_id, :patient_id, 'pending')");
    $stmtUpdateWaitingForDonor = $pdo->prepare("UPDATE patients SET status = 'waiting_for_donor' WHERE patient_id = :patient_id");

    $bloodMatchedCount = 0;
    $organMatchedCount = 0;

    // 2. Iterate each patient
    foreach ($patients as $patient) {
        $pId = $patient['patient_id'];
        $bGroup = $patient['blood_group'];
        $reqType = $patient['request_type'];

        if ($reqType === 'blood') {
            // Determine units_needed for validation and deduction
            $stmtCheckBloodReq->execute([':patient_id' => $pId]);
            $bloodReq = $stmtCheckBloodReq->fetch(PDO::FETCH_ASSOC);
            $unitsNeeded = $bloodReq ? (int) $bloodReq['units_needed'] : 1;

            // Find matched inventory - Add validation: check units_available >= units_needed
            $stmtFindBlood = $pdo->prepare("SELECT bank_id, units_available FROM blood_inventory WHERE blood_group = :bg AND units_available >= :units_needed LIMIT 1");
            $stmtFindBlood->execute([':bg' => $bGroup, ':units_needed' => $unitsNeeded]);
            $inventory = $stmtFindBlood->fetch(PDO::FETCH_ASSOC);

            if ($inventory) {
                // We use transactions to ensure all updates happen safely together!
                $pdo->beginTransaction();
                try {
                    $bankId = $inventory['bank_id'];

                    // Reduce units_available preventing negative stock using GREATEST
                    $stmtReduceBlood = $pdo->prepare("UPDATE blood_inventory SET units_available = GREATEST(units_available - :units_needed, 0) WHERE bank_id = :bank_id AND blood_group = :bg");
                    $stmtReduceBlood->execute([':units_needed' => $unitsNeeded, ':bank_id' => $bankId, ':bg' => $bGroup]);

                    // Assign to blood_requests
                    if ($bloodReq) {
                        $stmtUpdateBloodReq->execute([':bank_id' => $bankId, ':patient_id' => $pId]);
                    } else {
                        $stmtInsertBloodReq->execute([
                            ':patient_id' => $pId,
                            ':patient_name' => $patient['name'],
                            ':age' => $patient['age'],
                            ':bank_id' => $bankId,
                            ':bg' => $bGroup,
                            ':priority_score' => $patient['priority_score'],
                            ':units_needed' => $unitsNeeded
                        ]);
                    }

                    // Update patients table
                    $stmtApprovePatient->execute([':patient_id' => $pId]);

                    $pdo->commit();
                    $bloodMatchedCount++;
                } catch (Exception $e) {
                    $pdo->rollBack();
                }
            }
        } elseif ($reqType === 'organ') {
            // Unconditionally update patient status to waiting_for_donor 
            $stmtUpdateWaitingForDonor->execute([':patient_id' => $pId]);

            // Find matched organ donor
            $stmtFindDonor->execute([':bg' => $bGroup, ':organ' => $patient['organ_needed']]);
            $donor = $stmtFindDonor->fetch(PDO::FETCH_ASSOC);

            echo "<p>Patient processed and correctly set to waiting_for_donor</p>";

            if ($donor) {
                echo "<p>Donor found</p>";
                $pdo->beginTransaction();
                try {
                    $dId = $donor['donor_id'];

                    // Explicitly prevent duplicate inserts: Check if response already exists for this donor & patient combo
                    $stmtCheckDup = $pdo->prepare("SELECT response_id FROM donor_responses WHERE donor_id = :donor_id AND patient_id = :patient_id AND response IN ('pending', 'accepted')");
                    $stmtCheckDup->execute([':donor_id' => $dId, ':patient_id' => $pId]);

                    if (!$stmtCheckDup->fetchColumn()) {
                        // Insert into donor_responses
                        $stmtInsertResponse->execute([':donor_id' => $dId, ':patient_id' => $pId]);
                        echo "<p>Donor inserted</p>";

                        // Update patient status to donor_matched
                        $stmtUpdateDonorMatched = $pdo->prepare("UPDATE patients SET status = 'donor_matched' WHERE patient_id = :patient_id");
                        $stmtUpdateDonorMatched->execute([':patient_id' => $pId]);
                    } else {
                        echo "<p>Pair already exists. Skipped insertion.</p>";
                    }

                    $pdo->commit();
                    $organMatchedCount++;
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    if ($e->getCode() == 23000) {
                        echo "<p>Gracefully handled duplicate insert via UNIQUE DB constraint.</p>";
                    } else {
                        echo "<p>Database Error during match insertion.</p>";
                    }
                } catch (Exception $e) {
                    $pdo->rollBack();
                }
            } else {
                echo "<p>No donor found</p>";
            }
        }
    }

    // Print Success Message
    echo "<h3>Matching System Execution Complete!</h3>";
    echo "<p>Total Blood Patients Matched and Approved: <strong>$bloodMatchedCount</strong></p>";
    echo "<p>Total Organ Patients Matched (Pending Evaluation): <strong>$organMatchedCount</strong></p>";

} catch (PDOException $e) {
    echo "Database Error: " . htmlspecialchars($e->getMessage());
}
?>