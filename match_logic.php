<?php
/**
 * Standalone logic to match a newly available donor with the highest priority patient.
 */

function triggerMatching($pdo, $donorId)
{
    try {
        // 1. Fetch Donor Details
        $stmtDonor = $pdo->prepare("SELECT blood_group, donor_type, organ_type FROM donors WHERE donor_id = ?");
        $stmtDonor->execute([$donorId]);
        $donor = $stmtDonor->fetch(PDO::FETCH_ASSOC);

        if (!$donor)
            return "Donor not found.";

        $donorBloodGroup = $donor['blood_group'];
        $donorType = $donor['donor_type'];
        $donorOrganType = $donor['organ_type'];

        // 2. Find Matching Patients
        // We match by blood group and status 'waiting_for_donor'.
        // If it's an organ donor, we also match the organ type.
        // If it's a blood donor, we match patients with request_type = 'blood'.

        $sql = "SELECT * FROM patients WHERE blood_group = :bg AND status = 'waiting_for_donor' ";

        if ($donorType === 'organ') {
            $sql .= " AND request_type = 'organ' AND organ_needed = :organ ";
        } elseif ($donorType === 'blood') {
            $sql .= " AND request_type = 'blood' ";
        } else {
            // Both - we'll prioritize organ matching first if they provide an organ, 
            // or just match whichever is highest priority.
            // For simplicity and following user steps, we'll keep it broad or specific based on donor's available organ.
            if ($donorOrganType) {
                $sql .= " AND ( (request_type = 'organ' AND organ_needed = :organ) OR request_type = 'blood' ) ";
            } else {
                $sql .= " AND request_type = 'blood' ";
            }
        }

        $sql .= " ORDER BY priority_score DESC, request_date ASC LIMIT 1";

        $stmtPatient = $pdo->prepare($sql);
        $params = [':bg' => $donorBloodGroup];
        if ($donorType === 'organ' || ($donorType === 'both' && $donorOrganType)) {
            $params[':organ'] = $donorOrganType;
        }

        $stmtPatient->execute($params);
        $patient = $stmtPatient->fetch(PDO::FETCH_ASSOC);

        if ($patient) {
            $patientId = $patient['patient_id'];

            // 3. Check for Duplicate Mapping
            $stmtCheck = $pdo->prepare("SELECT response_id FROM donor_responses WHERE donor_id = ? AND patient_id = ? AND response IN ('pending','accepted')");
            $stmtCheck->execute([$donorId, $patientId]);
            if ($stmtCheck->fetchColumn()) {
                return "Match skipped. A valid response already exists for this donor-patient pair.";
            }

            try {
                // 4. Create Match Entry
                $stmtInsert = $pdo->prepare("INSERT INTO donor_responses (donor_id, patient_id, response) VALUES (?, ?, 'pending')");
                $stmtInsert->execute([$donorId, $patientId]);

                // 5. Update Patient Status
                $stmtUpdatePatient = $pdo->prepare("UPDATE patients SET status = 'donor_matched' WHERE patient_id = ?");
                $stmtUpdatePatient->execute([$patientId]);

                return "Match found! Patient ID: $patientId has been matched with Donor ID: $donorId.";
            } catch (PDOException $e) {
                // Handle uniqueness constraint gracefully
                if ($e->getCode() == 23000) {
                    return "Duplicate record constraint prevented insertion. Skipped gracefully.";
                }
                throw $e;
            }
        }

        return "No matching patients found at this time.";

    } catch (Exception $e) {
        return "Matching Error: " . $e->getMessage();
    }
}
?>