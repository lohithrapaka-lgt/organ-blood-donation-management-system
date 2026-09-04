<?php
/**
 * emergency_alerts.php
 * Core engine for MediMatch Blood Bank:
 * - Dynamic database schema compatibility
 * - Inventory shortage detection
 * - Emergency donor alert generation with deduplication
 * - Multi-bank aggregated stock metrics
 */

// Helper to safely ensure database columns exist across MySQL versions
function ensureBloodModuleSchema(PDO $pdo) {
    static $schemaChecked = false;
    if ($schemaChecked) return;

    try {
        // 1. Upgrade `blood_camps` table
        $campCols = $pdo->query("DESCRIBE blood_camps")->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('blood_bank_id', $campCols)) {
            $pdo->exec("ALTER TABLE blood_camps ADD COLUMN blood_bank_id INT NULL AFTER camp_id");
        }
        if (!in_array('camp_name', $campCols)) {
            $pdo->exec("ALTER TABLE blood_camps ADD COLUMN camp_name VARCHAR(255) NULL AFTER blood_bank_id");
            // Sync existing `name` column to `camp_name`
            $pdo->exec("UPDATE blood_camps SET camp_name = name WHERE camp_name IS NULL OR camp_name = ''");
        }
        if (!in_array('venue', $campCols)) {
            $pdo->exec("ALTER TABLE blood_camps ADD COLUMN venue VARCHAR(255) NULL AFTER location");
        }
        if (!in_array('start_time', $campCols)) {
            $pdo->exec("ALTER TABLE blood_camps ADD COLUMN start_time TIME NULL AFTER date");
        }
        if (!in_array('end_time', $campCols)) {
            $pdo->exec("ALTER TABLE blood_camps ADD COLUMN end_time TIME NULL AFTER start_time");
        }
        if (!in_array('contact', $campCols)) {
            $pdo->exec("ALTER TABLE blood_camps ADD COLUMN contact VARCHAR(255) NULL AFTER end_time");
        }
        if (!in_array('description', $campCols)) {
            $pdo->exec("ALTER TABLE blood_camps ADD COLUMN description TEXT NULL AFTER contact");
        }
        if (!in_array('expected_donors', $campCols)) {
            $pdo->exec("ALTER TABLE blood_camps ADD COLUMN expected_donors INT DEFAULT 0 AFTER description");
        }
        if (!in_array('status', $campCols)) {
            $pdo->exec("ALTER TABLE blood_camps ADD COLUMN status ENUM('Upcoming', 'Completed', 'Cancelled') DEFAULT 'Upcoming' AFTER expected_donors");
        }
        if (!in_array('created_at', $campCols)) {
            $pdo->exec("ALTER TABLE blood_camps ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER status");
        }
        if (!in_array('updated_at', $campCols)) {
            $pdo->exec("ALTER TABLE blood_camps ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
        }

        // 2. Upgrade `donor_notifications` table with request_id for deduplication
        $notifCols = $pdo->query("DESCRIBE donor_notifications")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('request_id', $notifCols)) {
            $pdo->exec("ALTER TABLE donor_notifications ADD COLUMN request_id INT NULL AFTER donor_id");
            $pdo->exec("CREATE INDEX idx_donor_notif_req ON donor_notifications (donor_id, request_id, type)");
        }

        // 3. Upgrade `blood_requests` table with emergency_alert_status & support for donors_responding status
        $reqCols = $pdo->query("DESCRIBE blood_requests")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('emergency_alert_status', $reqCols)) {
            $pdo->exec("ALTER TABLE blood_requests ADD COLUMN emergency_alert_status ENUM('none', 'active', 'resolved') DEFAULT 'none' AFTER status");
        }
        try {
            $pdo->exec("ALTER TABLE blood_requests MODIFY COLUMN status ENUM('pending', 'approved', 'fulfilled', 'donors_responding') DEFAULT 'pending'");
        } catch (Exception $e) {}

        // 4. Ensure camp_registrations has created_at and status
        $regCols = $pdo->query("DESCRIBE camp_registrations")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('created_at', $regCols)) {
            $pdo->exec("ALTER TABLE camp_registrations ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        }
        if (!in_array('status', $regCols)) {
            $pdo->exec("ALTER TABLE camp_registrations ADD COLUMN status ENUM('Interested', 'Confirmed', 'Attended', 'Cancelled') DEFAULT 'Interested' AFTER camp_id");
        }
        try {
            $pdo->exec("ALTER TABLE camp_registrations ADD UNIQUE KEY unique_camp_donor (camp_id, donor_id)");
        } catch (Exception $e) {}

        // 5. Upgrade `donor_responses` table for emergency requests
        $respCols = $pdo->query("DESCRIBE donor_responses")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('request_id', $respCols)) {
            $pdo->exec("ALTER TABLE donor_responses ADD COLUMN request_id INT NULL AFTER donor_id");
        }
        if (!in_array('status', $respCols)) {
            $pdo->exec("ALTER TABLE donor_responses ADD COLUMN status VARCHAR(50) DEFAULT 'Willing to Donate' AFTER request_id");
        }
        if (!in_array('created_at', $respCols)) {
            $pdo->exec("ALTER TABLE donor_responses ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        }
        try {
            $pdo->exec("ALTER TABLE donor_responses MODIFY COLUMN patient_id INT NULL");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE donor_responses DROP INDEX unique_donor_patient");
        } catch (Exception $e) {}

        $schemaChecked = true;
    } catch (Exception $e) {
        error_log("MediMatch schema upgrade notice: " . $e->getMessage());
    }
}

/**
 * Detects inventory shortage for pending blood requests and generates
 * targeted, deduplicated emergency alerts to verified available donors.
 *
 * @param PDO $pdo
 * @param int|null $specificRequestId Optional request_id to target
 * @return array Array of notification statistics
 */
function detectAndTriggerEmergencyAlerts(PDO $pdo, $specificRequestId = null) {
    ensureBloodModuleSchema($pdo);

    $stats = [
        'shortages_detected' => 0,
        'donors_notified' => 0,
        'skipped_duplicates' => 0,
        'details' => []
    ];

    try {
        // Fetch pending blood requests (target specific or all pending)
        if ($specificRequestId !== null) {
            $stmtReq = $pdo->prepare("
                SELECT br.request_id, br.patient_id, br.patient_name, br.age, br.blood_group, 
                       br.units_needed, br.priority_score, br.status, br.bank_id, br.emergency_alert_status,
                       bb.name AS bank_name, bb.location AS bank_location
                FROM blood_requests br
                LEFT JOIN blood_banks bb ON br.bank_id = bb.bank_id
                WHERE br.status IN ('pending', 'donors_responding') AND br.request_id = ?
            ");
            $stmtReq->execute([(int)$specificRequestId]);
        } else {
            $stmtReq = $pdo->prepare("
                SELECT br.request_id, br.patient_id, br.patient_name, br.age, br.blood_group, 
                       br.units_needed, br.priority_score, br.status, br.bank_id, br.emergency_alert_status,
                       bb.name AS bank_name, bb.location AS bank_location
                FROM blood_requests br
                LEFT JOIN blood_banks bb ON br.bank_id = bb.bank_id
                WHERE br.status IN ('pending', 'donors_responding')
                ORDER BY br.priority_score DESC, br.request_date ASC
            ");
            $stmtReq->execute();
        }

        $requests = $stmtReq->fetchAll(PDO::FETCH_ASSOC);

        // Prepare donor search query:
        // Rule: donor.blood_group = required AND availability = 'available' AND verified = 'yes' AND donor_type IN ('blood', 'both')
        $stmtDonors = $pdo->prepare("
            SELECT donor_id, name, blood_group, contact, location
            FROM donors
            WHERE blood_group = ?
              AND availability = 'available'
              AND verified = 'yes'
              AND donor_type IN ('blood', 'both')
        ");

        // Prepare duplicate check query
        $stmtCheckNotif = $pdo->prepare("
            SELECT COUNT(*) 
            FROM donor_notifications 
            WHERE donor_id = ? 
              AND request_id = ? 
              AND type = 'urgent'
        ");

        // Prepare insert notification query
        $stmtInsertNotif = $pdo->prepare("
            INSERT INTO donor_notifications (donor_id, request_id, title, message, type, is_read) 
            VALUES (?, ?, ?, ?, 'urgent', 0)
        ");

        // Prepare update blood_requests emergency status
        $stmtUpdateAlertStatus = $pdo->prepare("
            UPDATE blood_requests 
            SET emergency_alert_status = ? 
            WHERE request_id = ?
        ");

        // Sum inventory per blood group query
        $stmtSumInv = $pdo->prepare("
            SELECT COALESCE(SUM(units_available), 0) AS total_units 
            FROM blood_inventory 
            WHERE blood_group = ?
        ");

        foreach ($requests as $req) {
            $bg = $req['blood_group'];
            $reqId = (int)$req['request_id'];
            $needed = (int)$req['units_needed'];

            // Query current system-wide available stock for this blood group
            $stmtSumInv->execute([$bg]);
            $availableUnits = (int)$stmtSumInv->fetchColumn();

            // Check shortage condition: Available stock < Required units
            if ($availableUnits < $needed) {
                $stats['shortages_detected']++;

                // Mark request as actively requiring emergency donor support
                $stmtUpdateAlertStatus->execute(['active', $reqId]);

                // Determine facility location description
                $facility = !empty($req['bank_name']) 
                    ? $req['bank_name'] . (!empty($req['bank_location']) ? ' (' . $req['bank_location'] . ')' : '')
                    : 'City Central Blood Bank & Partner Centers';

                // Find matching verified available donors with compatible blood group
                $compatGroups = getCompatibleDonorBloodGroups($bg);
                $inSql = implode(',', array_fill(0, count($compatGroups), '?'));
                $stmtDonors = $pdo->prepare("
                    SELECT donor_id, name, blood_group, contact, location
                    FROM donors
                    WHERE blood_group IN ($inSql)
                      AND availability = 'available'
                      AND verified = 'yes'
                      AND donor_type IN ('blood', 'both')
                ");
                $stmtDonors->execute($compatGroups);
                $matchingDonors = $stmtDonors->fetchAll(PDO::FETCH_ASSOC);

                $reqNotifiedCount = 0;
                $reqSkippedCount = 0;

                foreach ($matchingDonors as $donor) {
                    $donorId = (int)$donor['donor_id'];

                    // Check if already notified for this exact shortage request (NO SPAM RULE)
                    $stmtCheckNotif->execute([$donorId, $reqId]);
                    $alreadyNotified = (int)$stmtCheckNotif->fetchColumn();

                    if ($alreadyNotified === 0) {
                        $title = "🚨 EMERGENCY BLOOD ALERT: {$bg} Needed Urgently";
                        $compatNote = ($donor['blood_group'] === $bg)
                            ? "Your registered blood group matches this requirement."
                            : "Your registered blood group ({$donor['blood_group']}) is medically compatible to donate for {$bg} requirements.";
                        $message = "🚨 EMERGENCY BLOOD ALERT: {$bg} blood is urgently needed. Current available stock: {$availableUnits} unit(s). Required: {$needed} unit(s). Location: {$facility}. {$compatNote} ❤️ Your help could make a difference.";

                        $stmtInsertNotif->execute([$donorId, $reqId, $title, $message]);
                        $reqNotifiedCount++;
                        $stats['donors_notified']++;
                    } else {
                        $reqSkippedCount++;
                        $stats['skipped_duplicates']++;
                    }
                }

                $stats['details'][] = [
                    'request_id' => $reqId,
                    'blood_group' => $bg,
                    'units_needed' => $needed,
                    'units_available' => $availableUnits,
                    'donors_matched' => count($matchingDonors),
                    'donors_notified' => $reqNotifiedCount,
                    'duplicates_skipped' => $reqSkippedCount,
                    'status' => 'shortage_alert_active'
                ];
            } else {
                // Stock is sufficient; if it had an active emergency alert earlier, mark resolved
                if ($req['emergency_alert_status'] === 'active') {
                    $stmtUpdateAlertStatus->execute(['resolved', $reqId]);

                    // Send resolution notification to responding donors
                    try {
                        $stmtRespDonors = $pdo->prepare("SELECT DISTINCT donor_id FROM donor_responses WHERE request_id = ?");
                        $stmtRespDonors->execute([$reqId]);
                        $notifiedResp = $stmtRespDonors->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($notifiedResp as $dId) {
                            $checkRes = $pdo->prepare("SELECT COUNT(*) FROM donor_notifications WHERE donor_id = ? AND request_id = ? AND title LIKE '%RESOLVED%'");
                            $checkRes->execute([$dId, $reqId]);
                            if ((int)$checkRes->fetchColumn() === 0) {
                                $stmtNotifRes = $pdo->prepare("INSERT INTO donor_notifications (donor_id, request_id, title, message, type, is_read) VALUES (?, ?, ?, ?, 'urgent', 0)");
                                $stmtNotifRes->execute([$dId, $reqId, "🎉 BLOOD SHORTAGE RESOLVED", "Thank you! The required blood stock for {$bg} has been restored."]);
                            }
                        }
                    } catch (Exception $e) {}
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error in detectAndTriggerEmergencyAlerts: " . $e->getMessage());
    }

    return $stats;
}

/**
 * Returns active shortages across the system for dashboard display
 */
function getActiveShortages(PDO $pdo) {
    ensureBloodModuleSchema($pdo);

    $sql = "
        SELECT br.request_id, br.patient_id, br.patient_name, br.age, br.blood_group, 
               br.units_needed, br.priority_score, br.request_date, br.status, br.emergency_alert_status,
               (SELECT COALESCE(SUM(units_available), 0) FROM blood_inventory WHERE blood_group = br.blood_group) AS units_available,
               bb.name AS bank_name, bb.location AS bank_location
        FROM blood_requests br
        LEFT JOIN blood_banks bb ON br.bank_id = bb.bank_id
        WHERE br.status IN ('pending', 'donors_responding')
          AND (br.emergency_alert_status IS NULL OR br.emergency_alert_status != 'resolved')
        HAVING units_available < br.units_needed
        ORDER BY br.priority_score DESC, br.request_date ASC
    ";

    try {
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtCountAlerted = $pdo->prepare("
            SELECT COUNT(DISTINCT donor_id) 
            FROM donor_notifications 
            WHERE request_id = ? AND type = 'urgent'
        ");

        foreach ($rows as &$r) {
            $compatGroups = getCompatibleDonorBloodGroups($r['blood_group']);
            $inSql = implode(',', array_fill(0, count($compatGroups), '?'));
            $stmtCountDonors = $pdo->prepare("
                SELECT COUNT(*) 
                FROM donors 
                WHERE blood_group IN ($inSql) 
                  AND availability = 'available' 
                  AND verified = 'yes' 
                  AND donor_type IN ('blood', 'both')
            ");
            $stmtCountDonors->execute($compatGroups);
            $r['matching_donors_count'] = (int)$stmtCountDonors->fetchColumn();
            $r['deficit'] = max(0, (int)$r['units_needed'] - (int)$r['units_available']);

            // Alerted donors count
            $stmtCountAlerted->execute([$r['request_id']]);
            $r['alerted_count'] = (int)$stmtCountAlerted->fetchColumn();
            if ($r['alerted_count'] === 0) {
                $r['alerted_count'] = $r['matching_donors_count'];
            }

            // Responding donors list
            $r['responding_donors'] = getEmergencyResponsesForRequest($pdo, $r['request_id']);
            $willingDonors = array_filter($r['responding_donors'], function($d) {
                return in_array($d['status'], ['Willing to Donate', 'Confirmed', 'Completed']);
            });
            $r['willing_count'] = count($willingDonors);

            // Dynamic status based on responses & availability
            $hasConfirmed = false;
            foreach ($r['responding_donors'] as $rd) {
                if ($rd['status'] === 'Confirmed') { $hasConfirmed = true; break; }
            }

            if ($r['units_available'] >= $r['units_needed']) {
                $r['display_status'] = '🟢 RESOLVED';
                $r['status_badge_class'] = 'bg-success text-white';
            } elseif ($hasConfirmed) {
                $r['display_status'] = '🔵 DONOR CONFIRMED';
                $r['status_badge_class'] = 'bg-primary text-white';
            } elseif ($r['willing_count'] > 0) {
                $r['display_status'] = '🟡 DONORS RESPONDING';
                $r['status_badge_class'] = 'bg-warning text-dark';
            } else {
                $r['display_status'] = '🔴 ACTIVE ALERT';
                $r['status_badge_class'] = 'bg-danger text-white pulse-glow';
            }
        }
        unset($r);

        return $rows;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Returns grouped inventory for all registered blood banks with all 8 groups guaranteed
 */
function getGlobalBloodBankInventory(PDO $pdo) {
    $standardGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    // 1. Fetch all registered blood banks
    $stmtBanks = $pdo->query("
        SELECT bank_id, name, location, contact, license_no, capacity, status
        FROM blood_banks
        ORDER BY name ASC
    ");
    $banks = $stmtBanks->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch inventory for all banks
    $stmtInv = $pdo->query("
        SELECT bank_id, blood_group, units_available, expiry_date
        FROM blood_inventory
    ");
    $invRows = $stmtInv->fetchAll(PDO::FETCH_ASSOC);

    // Group inventory rows by bank_id -> blood_group
    $invMap = [];
    foreach ($invRows as $row) {
        $bid = $row['bank_id'];
        $bg = $row['blood_group'];
        if (!isset($invMap[$bid])) {
            $invMap[$bid] = [];
        }
        $invMap[$bid][$bg] = [
            'units' => (int)$row['units_available'],
            'expiry' => $row['expiry_date']
        ];
    }

    $result = [];
    foreach ($banks as $b) {
        $bid = (int)$b['bank_id'];
        $bankInventory = [];
        $totalUnits = 0;
        $inStockTypesCount = 0;

        foreach ($standardGroups as $bg) {
            $units = isset($invMap[$bid][$bg]) ? $invMap[$bid][$bg]['units'] : 0;
            $expiry = isset($invMap[$bid][$bg]) ? $invMap[$bid][$bg]['expiry'] : null;

            if ($units > 0) {
                $inStockTypesCount++;
            }
            $totalUnits += $units;

            $statusText = 'Out of Stock';
            $statusClass = 'danger';
            if ($units >= 5) {
                $statusText = 'Healthy';
                $statusClass = 'success';
            } elseif ($units > 0) {
                $statusText = 'Low';
                $statusClass = 'warning';
            }

            $bankInventory[$bg] = [
                'group' => $bg,
                'units' => $units,
                'expiry' => $expiry,
                'status_text' => $statusText,
                'status_class' => $statusClass
            ];
        }

        $result[$bid] = [
            'bank_id' => $bid,
            'name' => $b['name'],
            'location' => $b['location'],
            'contact' => $b['contact'],
            'license_no' => $b['license_no'],
            'capacity' => (int)$b['capacity'],
            'status' => $b['status'] ?? 'approved',
            'total_units' => $totalUnits,
            'in_stock_types_count' => $inStockTypesCount,
            'inventory' => $bankInventory
        ];
    }

    return $result;
}

/**
 * Fetch blood camps with organizer bank details and registered donor counts
 */
function getBloodCamps(PDO $pdo, $bloodBankId = null, $status = null) {
    ensureBloodModuleSchema($pdo);

    $sql = "
        SELECT c.*, 
               COALESCE(c.camp_name, c.name) AS display_name,
               bb.name AS organizer_bank_name,
               bb.location AS organizer_bank_location,
               bb.contact AS organizer_bank_contact,
               (SELECT COUNT(*) FROM camp_registrations cr WHERE cr.camp_id = c.camp_id) AS registered_count
        FROM blood_camps c
        LEFT JOIN blood_banks bb ON c.blood_bank_id = bb.bank_id
        WHERE 1=1
    ";

    $params = [];
    if ($bloodBankId !== null) {
        $sql .= " AND c.blood_bank_id = ?";
        $params[] = (int)$bloodBankId;
    }
    if ($status !== null && $status !== 'All') {
        $sql .= " AND c.status = ?";
        $params[] = $status;
    }

    $sql .= " ORDER BY c.date ASC, c.start_time ASC, c.camp_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Create a new blood donation camp
 */
function createBloodCamp(PDO $pdo, array $data) {
    ensureBloodModuleSchema($pdo);

    $name = trim($data['camp_name'] ?? ($data['name'] ?? 'Blood Donation Camp'));
    $location = trim($data['location'] ?? '');
    $venue = trim($data['venue'] ?? '');
    $date = $data['date'] ?? date('Y-m-d');
    $startTime = !empty($data['start_time']) ? $data['start_time'] : null;
    $endTime = !empty($data['end_time']) ? $data['end_time'] : null;
    $contact = trim($data['contact'] ?? '');
    $description = trim($data['description'] ?? '');
    $expectedDonors = (int)($data['expected_donors'] ?? 0);
    $status = in_array($data['status'] ?? '', ['Upcoming', 'Completed', 'Cancelled']) ? $data['status'] : 'Upcoming';
    $bloodBankId = !empty($data['blood_bank_id']) ? (int)$data['blood_bank_id'] : null;

    $stmt = $pdo->prepare("
        INSERT INTO blood_camps 
        (blood_bank_id, camp_name, name, location, venue, date, start_time, end_time, contact, description, expected_donors, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $bloodBankId,
        $name,
        $name, // Sync both `camp_name` and legacy `name`
        $location,
        $venue,
        $date,
        $startTime,
        $endTime,
        $contact,
        $description,
        $expectedDonors,
        $status
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Update an existing blood donation camp
 */
function updateBloodCamp(PDO $pdo, $campId, array $data) {
    ensureBloodModuleSchema($pdo);

    $name = trim($data['camp_name'] ?? ($data['name'] ?? 'Blood Donation Camp'));
    $location = trim($data['location'] ?? '');
    $venue = trim($data['venue'] ?? '');
    $date = $data['date'] ?? date('Y-m-d');
    $startTime = !empty($data['start_time']) ? $data['start_time'] : null;
    $endTime = !empty($data['end_time']) ? $data['end_time'] : null;
    $contact = trim($data['contact'] ?? '');
    $description = trim($data['description'] ?? '');
    $expectedDonors = (int)($data['expected_donors'] ?? 0);
    $status = in_array($data['status'] ?? '', ['Upcoming', 'Completed', 'Cancelled']) ? $data['status'] : 'Upcoming';

    $stmt = $pdo->prepare("
        UPDATE blood_camps 
        SET camp_name = ?, name = ?, location = ?, venue = ?, date = ?, 
            start_time = ?, end_time = ?, contact = ?, description = ?, 
            expected_donors = ?, status = ?
        WHERE camp_id = ?
    ");
    return $stmt->execute([
        $name,
        $name,
        $location,
        $venue,
        $date,
        $startTime,
        $endTime,
        $contact,
        $description,
        $expectedDonors,
        $status,
        (int)$campId
    ]);
}

/**
 * Cancel a blood donation camp
 */
function cancelBloodCamp(PDO $pdo, $campId) {
    ensureBloodModuleSchema($pdo);
    $stmt = $pdo->prepare("UPDATE blood_camps SET status = 'Cancelled' WHERE camp_id = ?");
    return $stmt->execute([(int)$campId]);
}

/**
 * Complete a blood donation camp
 */
function completeBloodCamp(PDO $pdo, $campId) {
    ensureBloodModuleSchema($pdo);
    $stmt = $pdo->prepare("UPDATE blood_camps SET status = 'Completed' WHERE camp_id = ?");
    return $stmt->execute([(int)$campId]);
}

/**
 * Register a donor's interest in a blood camp
 * Returns ['success' => bool, 'already_registered' => bool]
 */
function registerDonorInterestInCamp(PDO $pdo, $donorId, $campId) {
    ensureBloodModuleSchema($pdo);

    $checkStmt = $pdo->prepare("SELECT id FROM camp_registrations WHERE donor_id = ? AND camp_id = ?");
    $checkStmt->execute([(int)$donorId, (int)$campId]);
    if ($checkStmt->fetchColumn()) {
        return ['success' => true, 'already_registered' => true];
    }

    $stmt = $pdo->prepare("INSERT INTO camp_registrations (donor_id, camp_id) VALUES (?, ?)");
    $stmt->execute([(int)$donorId, (int)$campId]);
    return ['success' => true, 'already_registered' => false];
}

/**
 * Safe helper to add donor notification without collisions
 */
function addDonorNotificationSafe(PDO $pdo, $donorId, $title, $message, $type = 'system', $requestId = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO donor_notifications (donor_id, request_id, title, message, type, is_read) VALUES (?, ?, ?, ?, ?, 0)");
        $stmt->execute([(int)$donorId, $requestId ? (int)$requestId : null, $title, $message, $type]);
    } catch (Exception $e) {}
}

/**
 * Centralized blood group compatibility / matching rules for red blood cells.
 * Returns array of donor blood groups compatible with the patient's blood group.
 */
function getCompatibleDonorBloodGroups($patientBloodGroup) {
    $patientBg = strtoupper(trim($patientBloodGroup));
    $matrix = [
        'A+'  => ['A+', 'A-', 'O+', 'O-'],
        'A-'  => ['A-', 'O-'],
        'B+'  => ['B+', 'B-', 'O+', 'O-'],
        'B-'  => ['B-', 'O-'],
        'AB+' => ['AB+', 'AB-', 'A+', 'A-', 'B+', 'B-', 'O+', 'O-'],
        'AB-' => ['AB-', 'A-', 'B-', 'O-'],
        'O+'  => ['O+', 'O-'],
        'O-'  => ['O-']
    ];
    return $matrix[$patientBg] ?? [$patientBg];
}

/**
 * Centralized blood group compatibility / matching check
 */
function isDonorCompatibleWithRequest($donorBloodGroup, $requiredBloodGroup) {
    $donorBg = strtoupper(trim($donorBloodGroup));
    $reqBg = strtoupper(trim($requiredBloodGroup));
    if ($donorBg === $reqBg) {
        return true;
    }
    $compatibleGroups = getCompatibleDonorBloodGroups($reqBg);
    return in_array($donorBg, $compatibleGroups, true);
}

/**
 * Returns active emergency shortages matching a specific blood group (for donor dashboard)
 */
function getShortagesForDonorBloodGroup(PDO $pdo, $donorBloodGroup) {
    ensureBloodModuleSchema($pdo);
    try {
        $sql = "
            SELECT br.request_id, br.patient_id, br.blood_group, br.units_needed, br.priority_score, 
                   br.request_date, br.status, br.emergency_alert_status,
                   (SELECT COALESCE(SUM(bi.units_available), 0) FROM blood_inventory bi WHERE bi.blood_group = br.blood_group) AS units_available,
                   COALESCE(bb.name, 'City Central Blood Bank') AS bank_name,
                   COALESCE(bb.location, 'Bhavanipuram Colony') AS bank_location
            FROM blood_requests br
            LEFT JOIN blood_banks bb ON br.bank_id = bb.bank_id
            WHERE br.status IN ('pending', 'donors_responding')
              AND (br.emergency_alert_status IS NULL OR br.emergency_alert_status != 'resolved')
            HAVING units_available < br.units_needed
            ORDER BY br.priority_score DESC, br.request_date ASC
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $matching = [];
        foreach ($rows as $r) {
            if (isDonorCompatibleWithRequest($donorBloodGroup, $r['blood_group'])) {
                $r['deficit'] = max(0, (int)$r['units_needed'] - (int)$r['units_available']);
                $matching[] = $r;
            }
        }

        return $matching;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Returns responding donors for an emergency request
 */
function getEmergencyResponsesForRequest(PDO $pdo, $requestId) {
    ensureBloodModuleSchema($pdo);
    try {
        $stmt = $pdo->prepare("
            SELECT dr.response_id, dr.donor_id, dr.request_id, dr.patient_id, 
                   COALESCE(dr.status, 'Willing to Donate') AS status,
                   COALESCE(dr.created_at, NOW()) AS created_at,
                   d.name AS donor_name, d.blood_group, d.contact, d.donor_type, 
                   d.verified, d.availability, d.points
            FROM donor_responses dr
            JOIN donors d ON dr.donor_id = d.donor_id
            WHERE dr.request_id = ?
            ORDER BY dr.response_id DESC
        ");
        $stmt->execute([(int)$requestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Record a donor's willingness to donate for an emergency request
 * NOTE: DOES NOT award points or increment donation count!
 */
function recordDonorEmergencyWillingness(PDO $pdo, $donorId, $requestId) {
    ensureBloodModuleSchema($pdo);
    try {
        // Fetch request info
        $stmtReq = $pdo->prepare("
            SELECT br.patient_id, br.blood_group, br.units_needed, COALESCE(bb.name, 'City Central Blood Bank') as bank_name
            FROM blood_requests br
            LEFT JOIN blood_banks bb ON br.bank_id = bb.bank_id
            WHERE br.request_id = ?
        ");
        $stmtReq->execute([(int)$requestId]);
        $req = $stmtReq->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            return ['success' => false, 'message' => 'Blood request not found.'];
        }

        // Check if already responded
        $stmtCheck = $pdo->prepare("SELECT response_id, status FROM donor_responses WHERE donor_id = ? AND request_id = ?");
        $stmtCheck->execute([(int)$donorId, (int)$requestId]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            return ['success' => true, 'already_responded' => true, 'status' => $existing['status']];
        }

        // Insert response safely
        try {
            $stmtIns = $pdo->prepare("
                INSERT INTO donor_responses (donor_id, request_id, patient_id, response, status, created_at)
                VALUES (?, ?, ?, 'accepted', 'Willing to Donate', NOW())
            ");
            $stmtIns->execute([(int)$donorId, (int)$requestId, $req['patient_id']]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                // If unique_donor_patient constraint exists and collides, insert with patient_id = NULL
                $stmtInsNull = $pdo->prepare("
                    INSERT INTO donor_responses (donor_id, request_id, patient_id, response, status, created_at)
                    VALUES (?, ?, NULL, 'accepted', 'Willing to Donate', NOW())
                ");
                $stmtInsNull->execute([(int)$donorId, (int)$requestId]);
            } else {
                throw $e;
            }
        }

        // Transition blood_requests to 'donors_responding'
        $pdo->prepare("UPDATE blood_requests SET status = 'donors_responding' WHERE request_id = ? AND status = 'pending'")
            ->execute([(int)$requestId]);

        // Send donor confirmation notification
        addDonorNotificationSafe(
            $pdo, 
            $donorId, 
            "❤️ Willingness to Donate Registered", 
            "Your willingness to donate {$req['blood_group']} blood has been sent to {$req['bank_name']}. The blood bank will coordinate with you shortly.", 
            "urgent", 
            (int)$requestId
        );

        return ['success' => true, 'already_responded' => false];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Blood bank confirms a responding donor appointment
 */
function confirmEmergencyDonor(PDO $pdo, $responseId) {
    ensureBloodModuleSchema($pdo);
    try {
        $stmt = $pdo->prepare("UPDATE donor_responses SET status = 'Confirmed' WHERE response_id = ?");
        $stmt->execute([(int)$responseId]);

        $stmtInfo = $pdo->prepare("
            SELECT dr.donor_id, dr.request_id, br.blood_group, COALESCE(bb.name, 'City Central Blood Bank') as bank_name
            FROM donor_responses dr
            LEFT JOIN blood_requests br ON dr.request_id = br.request_id
            LEFT JOIN blood_banks bb ON br.bank_id = bb.bank_id
            WHERE dr.response_id = ?
        ");
        $stmtInfo->execute([(int)$responseId]);
        $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);

        if ($info && !empty($info['donor_id'])) {
            addDonorNotificationSafe(
                $pdo, 
                $info['donor_id'], 
                "✅ Donation Scheduled & Confirmed", 
                "{$info['bank_name']} has confirmed your appointment to donate {$info['blood_group']} blood. Please visit the center at your scheduled time.", 
                "urgent", 
                $info['request_id']
            );
        }

        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Authorized staff verifies a completed physical donation:
 * 1. Replenishes blood_inventory (+units) for this bank
 * 2. Records verified donation in donor_donations
 * 3. Awards donor points (+250)
 * 4. Checks and unlocks Mystery Gift & badges
 * 5. Updates response status to 'Completed'
 * 6. Checks if shortage is resolved -> marks request fulfilled & resolved
 */
function verifyEmergencyDonation(PDO $pdo, $responseId, $bankId, $units = 1) {
    ensureBloodModuleSchema($pdo);
    $units = max(1, (int)$units);

    try {
        $pdo->beginTransaction();

        $stmtResp = $pdo->prepare("
            SELECT dr.response_id, dr.donor_id, dr.request_id, dr.patient_id, dr.status,
                   d.name AS donor_name, d.blood_group, d.points,
                   br.units_needed, br.blood_group AS req_blood_group, br.status AS req_status
            FROM donor_responses dr
            JOIN donors d ON dr.donor_id = d.donor_id
            LEFT JOIN blood_requests br ON dr.request_id = br.request_id
            WHERE dr.response_id = ?
            FOR UPDATE
        ");
        $stmtResp->execute([(int)$responseId]);
        $resp = $stmtResp->fetch(PDO::FETCH_ASSOC);

        if (!$resp) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Response record not found.'];
        }

        $donorId = (int)$resp['donor_id'];
        $requestId = !empty($resp['request_id']) ? (int)$resp['request_id'] : null;
        $patientId = !empty($resp['patient_id']) ? (int)$resp['patient_id'] : null;
        if (!$patientId && $requestId) {
            $stmtPatLookup = $pdo->prepare("SELECT patient_id FROM blood_requests WHERE request_id = ?");
            $stmtPatLookup->execute([$requestId]);
            $patientId = (int)$stmtPatLookup->fetchColumn() ?: null;
        }
        $bloodGroup = !empty($resp['blood_group']) ? $resp['blood_group'] : $resp['req_blood_group'];

        $stmtBank = $pdo->prepare("SELECT name FROM blood_banks WHERE bank_id = ?");
        $stmtBank->execute([(int)$bankId]);
        $bankName = $stmtBank->fetchColumn() ?: 'City Central Blood Bank';

        // 1. Update response status to 'Completed'
        $pdo->prepare("UPDATE donor_responses SET status = 'Completed', response = 'accepted' WHERE response_id = ?")
            ->execute([(int)$responseId]);

        // 2. Replenish blood_inventory
        $stmtInvCheck = $pdo->prepare("
            SELECT inventory_id, units_available 
            FROM blood_inventory 
            WHERE bank_id = ? AND blood_group = ? 
            ORDER BY expiry_date DESC LIMIT 1
        ");
        $stmtInvCheck->execute([(int)$bankId, $bloodGroup]);
        $invRow = $stmtInvCheck->fetch(PDO::FETCH_ASSOC);

        if ($invRow) {
            $pdo->prepare("UPDATE blood_inventory SET units_available = units_available + ? WHERE inventory_id = ?")
                ->execute([$units, (int)$invRow['inventory_id']]);
        } else {
            $pdo->prepare("
                INSERT INTO blood_inventory (bank_id, blood_group, units_available, expiry_date)
                VALUES (?, ?, ?, DATE_ADD(CURDATE(), INTERVAL 35 DAY))
            ")->execute([(int)$bankId, $bloodGroup, $units]);
        }

        // 3. Record genuine verified donation in donor_donations
        $pdo->prepare("
            INSERT INTO donor_donations 
            (donor_id, patient_id, donation_type, blood_group_or_organ, facility_name, donation_date, verification_status, verification_date)
            VALUES (?, ?, 'blood', ?, ?, NOW(), 'verified', NOW())
        ")->execute([$donorId, $patientId, $bloodGroup, $bankName]);

        // 4. Award donation points (+250 points)
        $pdo->prepare("UPDATE donors SET points = points + 250 WHERE donor_id = ?")->execute([$donorId]);

        // 5. Evaluate Mystery Gift & Badges
        $stmtVc = $pdo->prepare("SELECT COUNT(*) FROM donor_donations WHERE donor_id = ? AND verification_status = 'verified'");
        $stmtVc->execute([$donorId]);
        $verifiedCount = (int)$stmtVc->fetchColumn();

        if ($verifiedCount >= 2) {
            $stmtGiftCheck = $pdo->prepare("SELECT COUNT(*) FROM mystery_gifts WHERE donor_id = ? AND gift_number = 1");
            $stmtGiftCheck->execute([$donorId]);
            if ((int)$stmtGiftCheck->fetchColumn() === 0) {
                $trackingCode = 'MM' . rand(1000, 9999);
                $pdo->prepare("INSERT INTO mystery_gifts (donor_id, gift_number, tracking_code, status) VALUES (?, 1, ?, 'unlocked')")
                    ->execute([$donorId, $trackingCode]);
                addDonorNotificationSafe($pdo, $donorId, "🎁 Mystery Gift Unlocked!", "Your 2nd verified donation has unlocked a surprise healthcare gift box! Claim it in your dashboard.", "reward");
            }
        }

        // Badges evaluation
        $badgesToGrant = ['first_step'];
        if ($verifiedCount >= 1) $badgesToGrant[] = 'life_saver';
        if ($verifiedCount >= 2) $badgesToGrant[] = 'blood_hero';
        if ($verifiedCount >= 5) $badgesToGrant[] = 'gold_donor';
        if ($verifiedCount >= 10) $badgesToGrant[] = 'platinum_donor';

        $badgeLabels = [
            'first_step' => '🏅 First Step',
            'life_saver' => '❤️ Life Saver',
            'blood_hero' => '🩸 Blood Hero',
            'gold_donor' => '🏆 Gold Donor',
            'platinum_donor' => '💎 Platinum Donor'
        ];

        foreach ($badgesToGrant as $bKey) {
            $stmtBadgeCheck = $pdo->prepare("SELECT COUNT(*) FROM donor_badges WHERE donor_id = ? AND badge_key = ?");
            $stmtBadgeCheck->execute([$donorId, $bKey]);
            if ((int)$stmtBadgeCheck->fetchColumn() === 0) {
                $pdo->prepare("INSERT INTO donor_badges (donor_id, badge_key) VALUES (?, ?)")->execute([$donorId, $bKey]);
                addDonorNotificationSafe($pdo, $donorId, "🎖 New Achievement Unlocked!", "Congratulations! You unlocked the '" . ($badgeLabels[$bKey] ?? $bKey) . "' badge!", "badge");
            }
        }

        // 6. Notification: Verified donation recorded
        addDonorNotificationSafe(
            $pdo, 
            $donorId, 
            "🎉 Verified Donation Recorded!", 
            "Your emergency blood donation has been officially verified at {$bankName}! +{$units} unit(s) added to inventory. +250 Points awarded to your profile.", 
            "verification"
        );

        // 7. Check if shortage is now resolved
        if ($requestId) {
            $stmtReqCheck = $pdo->prepare("SELECT units_needed, blood_group, patient_id FROM blood_requests WHERE request_id = ?");
            $stmtReqCheck->execute([$requestId]);
            $reqCheck = $stmtReqCheck->fetch(PDO::FETCH_ASSOC);

            if ($reqCheck) {
                $needed = (int)$reqCheck['units_needed'];
                $bg = $reqCheck['blood_group'];

                $stmtTotalStock = $pdo->prepare("SELECT COALESCE(SUM(units_available), 0) FROM blood_inventory WHERE blood_group = ?");
                $stmtTotalStock->execute([$bg]);
                $totalStock = (int)$stmtTotalStock->fetchColumn();

                if ($totalStock >= $needed) {
                    $pdo->prepare("UPDATE blood_requests SET status = 'fulfilled', bank_id = ?, emergency_alert_status = 'resolved' WHERE request_id = ?")
                        ->execute([(int)$bankId, $requestId]);

                    if (!empty($reqCheck['patient_id'])) {
                        $pdo->prepare("UPDATE patients SET status = 'fulfilled' WHERE patient_id = ?")
                            ->execute([(int)$reqCheck['patient_id']]);
                    }

                    // Send resolution notification to all donors who offered to help
                    $stmtAllResp = $pdo->prepare("SELECT DISTINCT donor_id FROM donor_responses WHERE request_id = ?");
                    $stmtAllResp->execute([$requestId]);
                    $allRespDonors = $stmtAllResp->fetchAll(PDO::FETCH_COLUMN);

                    foreach ($allRespDonors as $dId) {
                        addDonorNotificationSafe(
                            $pdo, 
                            $dId, 
                            "🎉 BLOOD SHORTAGE RESOLVED", 
                            "Thank you! The required blood stock for {$bg} has been restored.", 
                            "urgent", 
                            $requestId
                        );
                    }
                }
            }
        }

        $pdo->commit();
        return [
            'success' => true, 
            'message' => "Donation verified successfully! +{$units} unit(s) added to inventory, +250 points awarded to {$resp['donor_name']}."
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}


