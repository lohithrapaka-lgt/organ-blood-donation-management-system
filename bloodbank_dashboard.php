<?php
ini_set('session.cookie_lifetime', 86400); // 1 day
ini_set('session.gc_maxlifetime', 86400);
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['role'] !== 'bloodbank') {
    header("Location: login.php");
    exit();
}

$host = 'localhost';
$dbname = 'organ_blood_donation';
$username = 'root';
$password = '';

require_once 'emergency_alerts.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Auto-ensure schema tables and columns are up to date
    ensureBloodModuleSchema($pdo);

    $message = "";
    if (isset($_SESSION['success'])) {
        $message = $_SESSION['success'];
        unset($_SESSION['success']);
    }
    if (isset($_SESSION['error'])) {
        $message = $_SESSION['error'];
        unset($_SESSION['error']);
    }
    
    $bank_id = (int)$_SESSION['ref_id'];

    // Handle Old Stock Update (Action modal) [Kept for backwards compatibility]
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock'])) {
        $inventory_id = (int)$_POST['inventory_id'];
        $action = $_POST['action']; // 'add' or 'remove'
        $amount = (int)$_POST['amount'];
        
        $stmt = $pdo->prepare("SELECT units_available FROM blood_inventory WHERE inventory_id = ?");
        $stmt->execute([$inventory_id]);
        $currentUnits = (int)$stmt->fetchColumn();
        
        if ($action === 'add') {
            $newUnits = $currentUnits + $amount;
        } else {
            $newUnits = max(0, $currentUnits - $amount);
        }
        
        $updateStmt = $pdo->prepare("UPDATE blood_inventory SET units_available = ? WHERE inventory_id = ?");
        $updateStmt->execute([$newUnits, $inventory_id]);

        // Trigger automated emergency shortage check
        detectAndTriggerEmergencyAlerts($pdo);
        
        $message = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-check-circle-fill me-2'></i>Inventory dynamically adjusted!</div>";
        $_SESSION['success'] = $message;
        header("Location: bloodbank_dashboard.php?section=inventory-section");
        exit();
    }

    // Step 4: Handle Direct Stock Update Feature
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock_direct'])) {
        $bg = $_POST['blood_group'];
        $units = (int)$_POST['units_available'];
        $expiry = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        
        $checkStmt = $pdo->prepare("SELECT * FROM blood_inventory WHERE blood_group = ? AND bank_id = ?");
        $checkStmt->execute([$bg, $bank_id]);
        
        if ($checkStmt->rowCount() > 0) {
            $updStmt = $pdo->prepare("UPDATE blood_inventory SET units_available = units_available + ?, expiry_date = ? WHERE blood_group = ? AND bank_id = ?");
            $updStmt->execute([$units, $expiry, $bg, $bank_id]);
        } else {
            $insStmt = $pdo->prepare("INSERT INTO blood_inventory (bank_id, blood_group, units_available, expiry_date) VALUES (?, ?, ?, ?)");
            $insStmt->execute([$bank_id, $bg, $units, $expiry]);
        }

        // Trigger automated emergency shortage check
        detectAndTriggerEmergencyAlerts($pdo);
        
        $message = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-check-circle-fill me-2'></i>Stock updated successfully for $bg (+{$units} units).</div>";
        $_SESSION['success'] = $message;
        header("Location: bloodbank_dashboard.php?section=inventory-section");
        exit();
    }

    // Step 4 & 5: Handle Fulfill Action
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fulfill_request'])) {
        $request_id = (int)$_POST['request_id'];
        
        $pdo->beginTransaction();
        try {
            $stmtReq = $pdo->prepare("SELECT units_needed, blood_group, patient_id FROM blood_requests WHERE request_id = ?");
            $stmtReq->execute([$request_id]);
            $bloodReq = $stmtReq->fetch(PDO::FETCH_ASSOC);

            if ($bloodReq) {
                $unitsNeeded = (int)$bloodReq['units_needed'];
                $bg = $bloodReq['blood_group'];

                // Check units_available >= units_needed in THIS blood bank
                $stmtInv = $pdo->prepare("SELECT units_available FROM blood_inventory WHERE bank_id = ? AND blood_group = ?");
                $stmtInv->execute([$bank_id, $bg]);
                $inv = $stmtInv->fetch(PDO::FETCH_ASSOC);

                if (!$inv || (int)$inv['units_available'] < $unitsNeeded) {
                    $pdo->rollBack();
                    $_SESSION['error'] = "<div class='alert alert-danger'>Not enough inventory in your blood bank to fulfill request. Need: $unitsNeeded units. Have: " . ($inv ? $inv['units_available'] : 0) . " units.</div>";
                    header("Location: bloodbank_dashboard.php?section=requests-section");
                    exit();
                }

                // Deduct inventory
                $stmtUpdateInv = $pdo->prepare("UPDATE blood_inventory SET units_available = GREATEST(units_available - ?, 0) WHERE bank_id = ? AND blood_group = ?");
                $stmtUpdateInv->execute([$unitsNeeded, $bank_id, $bg]);
            }

            // Update blood_requests
            $stmtFulfillReq = $pdo->prepare("UPDATE blood_requests SET status = 'fulfilled', bank_id = ?, emergency_alert_status = 'resolved' WHERE request_id = ?");
            $stmtFulfillReq->execute([$bank_id, $request_id]);

            // Update patients status
            $stmtUpdatePatient = $pdo->prepare("UPDATE patients SET status = 'fulfilled' WHERE patient_id = (SELECT patient_id FROM blood_requests WHERE request_id = ?)");
            $stmtUpdatePatient->execute([$request_id]);

            $pdo->commit();

            // Re-evaluate shortages
            detectAndTriggerEmergencyAlerts($pdo);
            
            $_SESSION['success'] = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-check2-circle me-2 fs-5'></i> Request #$request_id fulfilled successfully! Blood units safely dispensed.</div>";
            header("Location: bloodbank_dashboard.php?section=requests-section");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "<div class='alert alert-danger'>Error fulfilling request: " . htmlspecialchars($e->getMessage()) . "</div>";
            header("Location: bloodbank_dashboard.php?section=requests-section");
            exit();
        }
    }

    // Handle Profile Update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
        $name = trim($_POST['name']);
        $location = trim($_POST['location']);
        $contact = trim($_POST['contact']);
        $license_no = trim($_POST['license_no']);
        $capacity = (int)$_POST['capacity'];

        if (empty($license_no)) {
            $_SESSION['error'] = "<div class='alert alert-danger'>License number is required for blood banks.</div>";
            header("Location: bloodbank_dashboard.php?section=profile-section");
            exit();
        }

        try {
            $stmt = $pdo->prepare("UPDATE blood_banks SET name = ?, location = ?, contact = ?, license_no = ?, capacity = ? WHERE bank_id = ?");
            $stmt->execute([$name, $location, $contact, $license_no, $capacity, $bank_id]);
            $_SESSION['success'] = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-check-circle-fill me-2'></i>Blood Bank profile updated successfully!</div>";
        } catch (Exception $e) {
            $_SESSION['error'] = "<div class='alert alert-danger'>Error updating profile: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        header("Location: bloodbank_dashboard.php?section=profile-section");
        exit();
    }

    // Handle Blood Camp CRUD: Create Camp
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_camp'])) {
        try {
            $campId = createBloodCamp($pdo, [
                'blood_bank_id'   => $bank_id,
                'camp_name'       => $_POST['camp_name'],
                'location'        => $_POST['location'],
                'venue'           => $_POST['venue'],
                'date'            => $_POST['date'],
                'start_time'      => $_POST['start_time'],
                'end_time'        => $_POST['end_time'],
                'contact'         => $_POST['contact'],
                'description'     => $_POST['description'],
                'expected_donors' => $_POST['expected_donors'],
                'status'          => $_POST['status'] ?? 'Upcoming'
            ]);
            $_SESSION['success'] = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-check-circle-fill me-2 fs-5'></i> Blood donation camp created successfully! Donors can now discover it.</div>";
        } catch (Exception $e) {
            $_SESSION['error'] = "<div class='alert alert-danger'>Error creating camp: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        header("Location: bloodbank_dashboard.php?section=camps-section");
        exit();
    }

    // Handle Blood Camp CRUD: Edit Camp
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_camp'])) {
        $campId = (int)$_POST['camp_id'];
        try {
            updateBloodCamp($pdo, $campId, [
                'camp_name'       => $_POST['camp_name'],
                'location'        => $_POST['location'],
                'venue'           => $_POST['venue'],
                'date'            => $_POST['date'],
                'start_time'      => $_POST['start_time'],
                'end_time'        => $_POST['end_time'],
                'contact'         => $_POST['contact'],
                'description'     => $_POST['description'],
                'expected_donors' => $_POST['expected_donors'],
                'status'          => $_POST['status']
            ]);
            $_SESSION['success'] = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-check-circle-fill me-2'></i> Blood donation camp updated successfully!</div>";
        } catch (Exception $e) {
            $_SESSION['error'] = "<div class='alert alert-danger'>Error updating camp: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        header("Location: bloodbank_dashboard.php?section=camps-section");
        exit();
    }

    // Handle Blood Camp CRUD: Cancel Camp
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_camp'])) {
        $campId = (int)$_POST['camp_id'];
        try {
            cancelBloodCamp($pdo, $campId);
            $_SESSION['success'] = "<div class='alert alert-warning d-flex align-items-center' role='alert'><i class='bi bi-x-circle-fill me-2'></i> Camp marked as Cancelled.</div>";
        } catch (Exception $e) {
            $_SESSION['error'] = "<div class='alert alert-danger'>Error cancelling camp.</div>";
        }
        header("Location: bloodbank_dashboard.php?section=camps-section");
        exit();
    }

    // Handle Blood Camp CRUD: Complete Camp
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_camp'])) {
        $campId = (int)$_POST['camp_id'];
        try {
            completeBloodCamp($pdo, $campId);
            $_SESSION['success'] = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-check2-circle me-2'></i> Camp marked as Completed!</div>";
        } catch (Exception $e) {
            $_SESSION['error'] = "<div class='alert alert-danger'>Error completing camp.</div>";
        }
        header("Location: bloodbank_dashboard.php?section=camps-section");
        exit();
    }

    // Handle Manual Emergency Shortage Scan & Donor Broadcast
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['trigger_emergency_check'])) {
        $scanStats = detectAndTriggerEmergencyAlerts($pdo);
        $_SESSION['success'] = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-broadcast me-2 fs-5 text-danger'></i> <div><strong>Emergency scan completed. Matching donors have been notified.</strong> <small class='d-block text-muted'>Active Shortages: {$scanStats['shortages_detected']} | Donors Alerted: {$scanStats['donors_notified']} | Duplicates Protected: {$scanStats['skipped_duplicates']}</small></div></div>";
        header("Location: bloodbank_dashboard.php?section=emergency-section");
        exit();
    }

    // Handle Blood Bank Confirm Emergency Donor Appointment
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_emergency_donor'])) {
        $resp_id = (int)$_POST['response_id'];
        $confRes = confirmEmergencyDonor($pdo, $resp_id);
        if ($confRes['success']) {
            $_SESSION['success'] = "<div class='alert alert-primary d-flex align-items-center' role='alert'><i class='bi bi-check-circle-fill me-2 fs-5'></i> <strong>Donor Appointment Confirmed!</strong> A scheduling notification has been sent to the donor.</div>";
        } else {
            $_SESSION['error'] = "<div class='alert alert-danger'>Error confirming donor: " . htmlspecialchars($confRes['message'] ?? 'Unknown error') . "</div>";
        }
        header("Location: bloodbank_dashboard.php?section=emergency-section");
        exit();
    }

    // Handle Blood Bank Verify Completed Donation
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_emergency_donation'])) {
        $resp_id = (int)$_POST['response_id'];
        $units = max(1, (int)($_POST['units_donated'] ?? 1));
        $vResult = verifyEmergencyDonation($pdo, $resp_id, $bank_id, $units);
        if ($vResult['success']) {
            detectAndTriggerEmergencyAlerts($pdo);
            $_SESSION['success'] = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-patch-check-fill me-2 fs-5'></i> <strong>Donation Verified!</strong> " . htmlspecialchars($vResult['message']) . "</div>";
        } else {
            $_SESSION['error'] = "<div class='alert alert-danger'>Verification error: " . htmlspecialchars($vResult['message'] ?? 'Unknown error') . "</div>";
        }
        header("Location: bloodbank_dashboard.php?section=emergency-section");
        exit();
    }

    // Run automatic shortage detection on page load
    detectAndTriggerEmergencyAlerts($pdo);

    // Fetch Blood Bank Profile Data
    $stmtBank = $pdo->prepare("SELECT name, location, contact, license_no, capacity FROM blood_banks WHERE bank_id = ?");
    $stmtBank->execute([$bank_id]);
    $bankData = $stmtBank->fetch(PDO::FETCH_ASSOC);

    // Standard 8 Blood Groups
    $groups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    // 1. Fetch ONLY this bank's stock (branch-only — used for main dashboard cards)
    $stmtBranch = $pdo->prepare("SELECT blood_group, SUM(units_available) as branch_units FROM blood_inventory WHERE bank_id = ? GROUP BY blood_group");
    $stmtBranch->execute([$bank_id]);
    $branchResult = $stmtBranch->fetchAll(PDO::FETCH_KEY_PAIR);

    $systemInventory = [];
    $criticalShortageCount = 0;
    foreach ($groups as $g) {
        $branchUnits = isset($branchResult[$g]) ? (int)$branchResult[$g] : 0;

        $indicatorStatus = 'healthy';
        $badgeClass = 'bg-success text-white';
        $borderClass = 'border-healthy';
        $labelText = '🟢 Healthy';

        if ($branchUnits === 0) {
            $indicatorStatus = 'critical';
            $badgeClass = 'bg-danger text-white pulse-glow';
            $borderClass = 'border-critical';
            $labelText = '🔴 CRITICAL — DONOR ALERT ACTIVE';
            $criticalShortageCount++;
        } elseif ($branchUnits < 5) {
            $indicatorStatus = 'low';
            $badgeClass = 'bg-warning text-dark';
            $borderClass = 'border-low';
            $labelText = '🟠 Low Stock';
        }

        $systemInventory[$g] = [
            'group'        => $g,
            'branch_units' => $branchUnits,
            'status'       => $indicatorStatus,
            'badge_class'  => $badgeClass,
            'border_class' => $borderClass,
            'label'        => $labelText
        ];
    }

    // 3. Fetch Global Blood Bank Inventory (All Registered Blood Banks)
    $globalInventory = getGlobalBloodBankInventory($pdo);

    // 4. Fetch Active Shortages
    $activeShortages = getActiveShortages($pdo);

    // 5. Fetch Blood Camps
    $allCamps = getBloodCamps($pdo);
    $campStats = [
        'total' => count($allCamps),
        'upcoming' => 0,
        'completed' => 0,
        'cancelled' => 0,
        'registrations' => 0
    ];
    foreach ($allCamps as $c) {
        if ($c['status'] === 'Upcoming') $campStats['upcoming']++;
        elseif ($c['status'] === 'Completed') $campStats['completed']++;
        elseif ($c['status'] === 'Cancelled') $campStats['cancelled']++;
        $campStats['registrations'] += (int)$c['registered_count'];
    }

    // 6. Fetch Expiry Alerts logic
    $stmtExp = $pdo->prepare("
        SELECT i.inventory_id, i.blood_group, i.units_available, i.expiry_date, b.name, b.location
        FROM blood_inventory i
        JOIN blood_banks b ON i.bank_id = b.bank_id
        WHERE i.expiry_date IS NOT NULL 
          AND i.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 5 DAY)
          AND i.bank_id = ?
    ");
    $stmtExp->execute([$bank_id]);
    $expiryAlerts = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

    // 7. Fetch Blood Requests for fulfillment (Priority Sorted)
    $queryBloodRequests = "
        SELECT br.request_id, br.patient_name as name, br.blood_group, br.units_needed, br.status, br.priority_score, br.emergency_alert_status
        FROM blood_requests br
        WHERE br.status = 'pending'
        ORDER BY br.priority_score DESC, br.request_date ASC
    ";
    $pendingBloodRequests = $pdo->query($queryBloodRequests)->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Connection Error: " . htmlspecialchars($e->getMessage()));
}

// Initial active section from query param if present
$initialSection = isset($_GET['section']) ? htmlspecialchars($_GET['section']) : 'dashboard-section';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blood Resource & Emergency Management Center - MediMatch</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    
    <style>
        :root {
            --primary-red: #d32f2f;
            --dark-red: #b71c1c;
            --light-red: #ffebee;
            --accent-glow: rgba(211, 47, 47, 0.25);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fe;
            color: #2d3748;
        }

        /* Sidebar Styling */
        .sidebar-wrapper {
            background-color: #ffffff;
            box-shadow: 2px 0 20px rgba(0,0,0,0.04);
            min-height: 100vh;
            padding-top: 2rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .nav-link-custom {
            color: #525f7f;
            font-weight: 500;
            padding: 0.85rem 1.4rem;
            border-radius: 12px;
            margin-bottom: 0.45rem;
            transition: all 0.25s ease;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .nav-link-custom:hover, .nav-link-custom.active {
            background: linear-gradient(135deg, #fff5f5 0%, #ffe3e3 100%);
            color: var(--primary-red);
            transform: translateX(4px);
            font-weight: 600;
        }

        /* Header Styling */
        .header-bg {
            background: linear-gradient(135deg, #c62828 0%, #e53935 60%, #ff5252 100%);
            color: white;
            padding: 2.2rem 2.5rem;
            border-bottom-left-radius: 28px;
            border-bottom-right-radius: 28px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(211, 47, 47, 0.22);
        }
        
        .content-section {
            display: block;
            animation: fadeInSection 0.35s cubic-bezier(0.165, 0.84, 0.44, 1);
        }
        .d-none-soft {
            display: none !important;
        }

        @keyframes fadeInSection {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Cards and Elements */
        .card-custom {
            background: white;
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 20px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.04);
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .blood-card {
            background: white;
            border-radius: 18px;
            padding: 1.4rem 1.2rem;
            text-align: center;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.04);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(226, 232, 240, 0.9);
        }
        .blood-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 28px rgba(211, 47, 47, 0.12);
        }

        .border-healthy { border-bottom: 5px solid #2e7d32; }
        .border-low     { border-bottom: 5px solid #f57c00; }
        .border-critical{ border-bottom: 5px solid #c62828; }

        /* Pulsating Critical Badge Animation */
        @keyframes pulseCritical {
            0% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(220, 53, 69, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
            }
        }

        .pulse-glow {
            animation: pulseCritical 2s infinite;
        }

        /* Expandable Bank Inventory Card */
        .inv-bank-card {
            background: #ffffff;
            border-radius: 18px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
            border: 1px solid rgba(226, 232, 240, 0.9);
            transition: all 0.25s ease;
            overflow: hidden;
        }
        .inv-bank-card:hover {
            box-shadow: 0 8px 30px rgba(211, 47, 47, 0.12);
        }
        .inv-bank-header {
            cursor: pointer;
            padding: 1.4rem 1.8rem;
            background: #ffffff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.2s;
            user-select: none;
        }
        .inv-bank-header:hover {
            background: #fff8f8;
        }
        .inv-bank-body {
            display: none;
            background: #fdfdfd;
            border-top: 1px solid #f1f3f9;
            padding: 1.6rem 1.8rem;
            animation: invExpand 0.3s ease;
        }
        .inv-bank-body.open {
            display: block;
        }
        @keyframes invExpand {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .inv-toggle-btn {
            transition: transform 0.3s ease;
        }
        .inv-toggle-btn.rotated {
            transform: rotate(180deg);
        }

        /* Blood Camp Cards */
        .camp-card {
            background: white;
            border-radius: 18px;
            border: 1px solid rgba(226, 232, 240, 0.9);
            box-shadow: 0 6px 22px rgba(0,0,0,0.04);
            transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        .camp-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 14px 34px rgba(211, 47, 47, 0.12);
            border-color: rgba(211, 47, 47, 0.2);
        }

        .badge-blood {
            background-color: var(--primary-red);
            padding: 0.45rem 1.1rem;
            border-radius: 50px;
            color: white;
            font-weight: 600;
        }

        .btn-gradient-red {
            background: linear-gradient(135deg, #d32f2f 0%, #f44336 100%);
            color: white;
            border: none;
            transition: all 0.25s;
        }
        .btn-gradient-red:hover {
            background: linear-gradient(135deg, #b71c1c 0%, #d32f2f 100%);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(211, 47, 47, 0.3);
        }

        /* Filter Pills */
        .filter-btn {
            border-radius: 50px;
            padding: 0.4rem 1.2rem;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.2s;
            border: 1px solid #e2e8f0;
            background: white;
            color: #4a5568;
        }
        .filter-btn.active, .filter-btn:hover {
            background: var(--primary-red);
            color: white;
            border-color: var(--primary-red);
        }
    </style>
</head>
<body>

    <div class="row g-0">
        
        <!-- Left Sidebar Navigation -->
        <div class="col-md-3 col-lg-2 sidebar-wrapper d-none d-md-block">
            <div class="text-center px-3 mb-4">
                <h3 class="fw-bold text-dark mb-1"><i class="bi bi-droplet-half text-danger me-2"></i>MediMatch</h3>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1 fw-bold">Blood Bank Hub</span>
            </div>
            
            <div class="px-3">
                <div class="nav-link-custom <?php echo $initialSection === 'dashboard-section' ? 'active' : ''; ?>" onclick="showSection('dashboard-section', this)">
                    <span><i class="bi bi-grid-fill me-3 fs-5"></i> Dashboard</span>
                </div>
                <div class="nav-link-custom <?php echo $initialSection === 'inventory-section' ? 'active' : ''; ?>" onclick="showSection('inventory-section', this)">
                    <span><i class="bi bi-box-seam-fill me-3 fs-5"></i> Global Inventory</span>
                    <span class="badge bg-danger rounded-pill"><?php echo count($globalInventory); ?></span>
                </div>
                <div class="nav-link-custom <?php echo $initialSection === 'emergency-section' ? 'active' : ''; ?>" onclick="showSection('emergency-section', this)">
                    <span><i class="bi bi-exclamation-octagon-fill me-3 fs-5 text-danger"></i> Emergency Shortages</span>
                    <?php if (count($activeShortages) > 0): ?>
                        <span class="badge bg-danger rounded-pill pulse-glow"><?php echo count($activeShortages); ?></span>
                    <?php endif; ?>
                </div>
                <div class="nav-link-custom <?php echo $initialSection === 'camps-section' ? 'active' : ''; ?>" onclick="showSection('camps-section', this)">
                    <span><i class="bi bi-calendar2-heart-fill me-3 fs-5 text-danger"></i> Blood Camps</span>
                    <span class="badge bg-light text-dark border rounded-pill"><?php echo $campStats['upcoming']; ?></span>
                </div>
                <div class="nav-link-custom <?php echo $initialSection === 'update-section' ? 'active' : ''; ?>" onclick="showSection('update-section', this)">
                    <span><i class="bi bi-cloud-arrow-up-fill me-3 fs-5 text-primary"></i> Update Stock</span>
                </div>
                <div class="nav-link-custom <?php echo $initialSection === 'expiry-section' ? 'active' : ''; ?>" onclick="showSection('expiry-section', this)">
                    <span><i class="bi bi-exclamation-triangle-fill me-3 fs-5 text-warning"></i> Expiry Alerts</span>
                    <?php if (count($expiryAlerts) > 0): ?>
                        <span class="badge bg-warning text-dark rounded-pill"><?php echo count($expiryAlerts); ?></span>
                    <?php endif; ?>
                </div>
                <div class="nav-link-custom <?php echo $initialSection === 'requests-section' ? 'active' : ''; ?>" onclick="showSection('requests-section', this)">
                    <span><i class="bi bi-person-lines-fill me-3 fs-5 text-success"></i> Requests</span>
                    <span class="badge bg-light text-dark border rounded-pill"><?php echo count($pendingBloodRequests); ?></span>
                </div>
                <div class="nav-link-custom <?php echo $initialSection === 'profile-section' ? 'active' : ''; ?>" onclick="showSection('profile-section', this)">
                    <span><i class="bi bi-person-fill me-3 fs-5"></i> My Profile</span>
                </div>
                
                <hr class="my-4 text-muted">
                
                <a href="logout.php" class="nav-link-custom text-danger text-decoration-none border border-danger border-opacity-25 rounded-3 bg-danger bg-opacity-10 mt-auto">
                    <span><i class="bi bi-box-arrow-right me-3 fs-5"></i> Logout</span>
                </a>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="col-md-9 col-lg-10 bg-light" style="min-height: 100vh;">
            
            <header class="header-bg mb-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <h2 class="fw-bold mb-1"><span id="headerTitle">Dashboard Overview</span></h2>
                        <p class="lead mb-0 opacity-90 fs-6">Blood Resource & Automated Emergency Shortage Management Center</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-white text-danger px-3 py-2 rounded-pill shadow-sm fw-bold">
                            <i class="bi bi-hospital me-1"></i> <?php echo htmlspecialchars($bankData['name'] ?? 'Blood Bank'); ?>
                        </span>
                    </div>
                </div>
            </header>

            <div class="container px-4 pb-5">

                <?php if (!empty($message)) echo $message; ?>

                <!-- ========================================== -->
                <!-- 1. DASHBOARD OVERVIEW SECTION              -->
                <!-- ========================================== -->
                <div id="dashboard-section" class="content-section <?php echo $initialSection === 'dashboard-section' ? '' : 'd-none-soft'; ?>">
                    
                    <!-- Profile Summary Card -->
                    <div class="card-custom mb-4 border-start border-4 border-danger">
                        <div class="row align-items-center">
                            <div class="col-md-auto mb-3 mb-md-0">
                                <div class="bg-danger bg-opacity-10 text-danger rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 70px; height: 70px;">
                                    <i class="bi bi-droplet-half fs-2"></i>
                                </div>
                            </div>
                            <div class="col-md">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                    <h4 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($bankData['name'] ?? 'Blood Bank'); ?></h4>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1">Authorized Facility</span>
                                </div>
                                <div class="d-flex flex-wrap gap-3 mt-2">
                                    <span class="badge bg-danger rounded-pill px-3 shadow-sm"><i class="bi bi-box-fill me-1"></i>Cap: <?php echo htmlspecialchars($bankData['capacity'] ?? '0'); ?> Units</span>
                                    <span class="text-muted small"><i class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo htmlspecialchars($bankData['location'] ?: 'Not Provided'); ?></span>
                                    <span class="text-muted small"><i class="bi bi-telephone-fill text-danger me-1"></i><?php echo htmlspecialchars($bankData['contact'] ?: 'Not Provided'); ?></span>
                                    <span class="text-muted small"><i class="bi bi-patch-check-fill text-danger me-1"></i>Lic: <?php echo htmlspecialchars($bankData['license_no'] ?: 'Pending'); ?></span>
                                </div>
                            </div>
                            <div class="col-md-auto mt-3 mt-md-0">
                                <button class="btn btn-outline-danger rounded-pill px-3 fw-bold" onclick="showSection('update-section')">
                                    <i class="bi bi-plus-circle me-1"></i> Quick Stock Update
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Critical Shortage Alert Banner (If Any Shortages Active) -->
                    <?php if (count($activeShortages) > 0): ?>
                        <div class="alert alert-danger rounded-4 p-4 shadow-sm border-danger border-2 mb-4">
                            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="badge bg-danger rounded-pill px-3 py-1 pulse-glow fw-bold fs-6">
                                            <i class="bi bi-broadcast me-1"></i> AUTOMATIC EMERGENCY ALERT ACTIVE
                                        </span>
                                        <span class="fw-bold text-dark"><?php echo count($activeShortages); ?> Critical Shortage Condition(s) Detected</span>
                                    </div>
                                    <p class="small text-muted mb-0">System inventory is below required units for pending patient requests. Compatible, verified, available donors have been automatically notified!</p>
                                </div>
                                <div>
                                    <button class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm" onclick="showSection('emergency-section')">
                                        <i class="bi bi-eye-fill me-1"></i> View Shortages & Donors
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- My Blood Bank Stock -->
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-droplet-half text-danger me-2"></i>My Blood Bank Stock</h5>
                            <small class="text-muted">Current blood inventory available at your blood bank.</small>
                        </div>
                        <span class="badge bg-light text-secondary border px-3 py-2 rounded-pill">All 8 Blood Groups</span>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <?php foreach($systemInventory as $group => $data): ?>
                            <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6">
                                <div class="blood-card <?php echo $data['border_class']; ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h4 class="fw-bold text-dark mb-0"><?php echo htmlspecialchars($group); ?></h4>
                                        <span class="badge <?php echo $data['badge_class']; ?> rounded-pill px-2 py-1 small" style="font-size: 0.75rem;">
                                            <?php echo $data['label']; ?>
                                        </span>
                                    </div>
                                    <div class="py-2">
                                        <h2 class="fw-extrabold mb-0 <?php echo $data['status'] === 'critical' ? 'text-danger' : ($data['status'] === 'low' ? 'text-warning' : 'text-success'); ?>">
                                            <?php echo $data['branch_units']; ?>
                                        </h2>
                                        <small class="text-muted fw-bold">Units Available</small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Quick Overview Modules Row -->
                    <div class="row g-4">
                        <!-- Upcoming Blood Camps Quick Box -->
                        <div class="col-lg-6">
                            <div class="card-custom h-100 mb-0">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-calendar2-heart-fill text-danger me-2"></i>Upcoming Blood Camps</h5>
                                    <button class="btn btn-sm btn-link text-danger text-decoration-none fw-bold" onclick="showSection('camps-section')">Manage All &rarr;</button>
                                </div>
                                <?php 
                                    $upcomingCamps = array_filter($allCamps, fn($c) => $c['status'] === 'Upcoming');
                                    $upcomingCampsSlice = array_slice($upcomingCamps, 0, 3);
                                ?>
                                <?php if (count($upcomingCampsSlice) > 0): ?>
                                    <div class="d-flex flex-column gap-3">
                                        <?php foreach ($upcomingCampsSlice as $camp): ?>
                                            <div class="p-3 bg-light rounded-4 border d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($camp['display_name']); ?></h6>
                                                    <small class="text-muted d-block"><i class="bi bi-geo-alt me-1 text-danger"></i><?php echo htmlspecialchars($camp['location'] . ($camp['venue'] ? ' (' . $camp['venue'] . ')' : '')); ?></small>
                                                    <small class="text-muted"><i class="bi bi-calendar-event me-1"></i><?php echo htmlspecialchars($camp['date']); ?> &bull; <?php echo htmlspecialchars($camp['start_time'] ?? '10:00'); ?> - <?php echo htmlspecialchars($camp['end_time'] ?? '15:00'); ?></small>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 mb-1 d-block">🟢 Upcoming</span>
                                                    <small class="text-muted"><i class="bi bi-people me-1"></i><?php echo (int)$camp['registered_count']; ?> Donors</small>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="bi bi-calendar-x fs-2 d-block mb-1 text-secondary opacity-50"></i>
                                        <p class="small mb-0">No upcoming blood camps scheduled. Create one to mobilize donors!</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Active Shortage Quick Box -->
                        <div class="col-lg-6">
                            <div class="card-custom h-100 mb-0">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <h5 class="fw-bold text-dark mb-0"><i class="bi bi-exclamation-octagon-fill text-danger me-2"></i>Emergency Shortages</h5>
                                        <span class="badge bg-danger rounded-pill px-2 py-1 small"><?php echo count($activeShortages); ?> Active</span>
                                    </div>
                                    <button class="btn btn-sm btn-link text-danger text-decoration-none fw-bold" onclick="showSection('emergency-section')">View Engine &rarr;</button>
                                </div>
                                <?php if (count($activeShortages) > 0): ?>
                                    <div class="d-flex flex-column gap-3">
                                        <?php foreach (array_slice($activeShortages, 0, 4) as $sh): ?>
                                            <div class="p-3 bg-danger bg-opacity-10 border border-danger border-opacity-25 rounded-4 d-flex justify-content-between align-items-center">
                                                <div>
                                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                        <span class="badge bg-danger rounded-pill px-3 py-1 fw-bold fs-6"><?php echo htmlspecialchars($sh['blood_group']); ?></span>
                                                        <span class="fw-bold text-dark">Need: <?php echo (int)$sh['units_needed']; ?> units (Stock: <?php echo (int)$sh['units_available']; ?>)</span>
                                                        <span class="badge bg-danger text-white rounded-pill px-2 py-1 small">Shortage: -<?php echo $sh['deficit']; ?></span>
                                                    </div>
                                                    <small class="text-muted d-block">
                                                        Req #<?php echo $sh['request_id']; ?> &bull; Patient: <strong><?php echo htmlspecialchars($sh['patient_name'] ?? 'Confidential'); ?></strong> (Age: <?php echo $sh['age'] ?? '—'; ?>) &bull; Priority: <span class="badge bg-dark rounded-pill px-2"><?php echo $sh['priority_score']; ?></span>
                                                    </small>
                                                </div>
                                                <div class="text-end ps-2">
                                                    <span class="badge <?php echo $sh['status_badge_class']; ?> rounded-pill px-3 py-1 mb-1 d-block" style="font-size: 0.75rem;"><?php echo $sh['display_status']; ?></span>
                                                    <small class="text-muted d-block"><i class="bi bi-bell-fill text-danger me-1"></i><?php echo (int)$sh['alerted_count']; ?> Alerted &bull; <?php echo (int)$sh['willing_count']; ?> Responded</small>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4 text-muted">
                                        <i class="bi bi-shield-check fs-2 d-block mb-1 text-success opacity-75"></i>
                                        <p class="small mb-0">All pending patient requests currently satisfy inventory thresholds.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- ========================================== -->
                <!-- 2. GLOBAL BLOOD INVENTORY SECTION          -->
                <!-- ========================================== -->
                <div id="inventory-section" class="content-section <?php echo $initialSection === 'inventory-section' ? '' : 'd-none-soft'; ?>">
                    <div class="card-custom">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
                            <div>
                                <h4 class="fw-bold text-dark mb-1"><i class="bi bi-box-seam-fill text-danger me-2"></i>Global Blood Bank Inventory</h4>
                                <p class="text-muted small mb-0">Complete list of <strong>ALL REGISTERED BLOOD BANKS</strong> in the MediMatch network. Click any card to smoothly expand and view the full 8 blood groups.</p>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-danger rounded-pill px-3 py-2 fs-6">
                                    <i class="bi bi-buildings me-1"></i> <?php echo count($globalInventory); ?> Registered Banks
                                </span>
                            </div>
                        </div>

                        <!-- Search Filter for Banks -->
                        <div class="mb-4">
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                                <input type="text" id="bankSearchInput" class="form-control border-start-0 py-2" placeholder="Search blood banks by name, location, or license..." onkeyup="filterBloodBanks()">
                            </div>
                        </div>

                        <?php if (empty($globalInventory)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-droplet fs-1 d-block mb-2 opacity-25"></i>No registered blood banks found in the system.
                            </div>
                        <?php endif; ?>

                        <!-- Registered Blood Bank Expandable Cards -->
                        <div id="bankCardsContainer">
                            <?php foreach ($globalInventory as $bid => $bank): 
                                $isMyBranch = ($bid === $bank_id);
                            ?>
                            <div class="inv-bank-card bank-item" data-name="<?php echo strtolower(htmlspecialchars($bank['name'])); ?>" data-loc="<?php echo strtolower(htmlspecialchars($bank['location'])); ?>">

                                <!-- Bank Header (Clickable Toggle) -->
                                <div class="inv-bank-header" onclick="toggleInvCard('inv-body-<?php echo $bid; ?>', 'inv-btn-<?php echo $bid; ?>')">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-danger bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 52px; height: 52px; flex-shrink: 0;">
                                            <i class="bi bi-droplet-half text-danger fs-4"></i>
                                        </div>
                                        <div>
                                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                <h5 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($bank['name']); ?></h5>
                                                <?php if ($isMyBranch): ?>
                                                    <span class="badge bg-danger rounded-pill px-2 py-1" style="font-size: 0.72rem;"><i class="bi bi-star-fill me-1"></i>Your Branch</span>
                                                <?php endif; ?>
                                                <?php if (!empty($bank['license_no'])): ?>
                                                    <span class="badge bg-light text-muted border rounded-pill px-2 py-1" style="font-size: 0.72rem;">🪪 Lic: <?php echo htmlspecialchars($bank['license_no']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-muted small d-flex flex-wrap gap-3">
                                                <span><i class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo htmlspecialchars($bank['location'] ?: 'Location Not Specified'); ?></span>
                                                <?php if (!empty($bank['contact'])): ?>
                                                    <span><i class="bi bi-telephone-fill text-danger me-1"></i><?php echo htmlspecialchars($bank['contact']); ?></span>
                                                <?php endif; ?>
                                                <span><i class="bi bi-archive-fill text-secondary me-1"></i>Cap: <?php echo $bank['capacity']; ?> Units</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex align-items-center gap-3">
                                        <div class="text-end d-none d-sm-block">
                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 fw-bold">
                                                <?php echo $bank['total_units']; ?> Units Available
                                            </span>
                                            <small class="text-muted d-block mt-1" style="font-size: 0.75rem;"><?php echo $bank['in_stock_types_count']; ?> / 8 Groups in Stock</small>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-bold d-flex align-items-center gap-1">
                                            <span>View Inventory</span>
                                            <i class="bi bi-chevron-down inv-toggle-btn" id="inv-btn-<?php echo $bid; ?>"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Collapsed Quick Preview of 8 Blood Groups -->
                                <div class="px-4 py-2 bg-light border-top d-flex flex-wrap gap-2 align-items-center text-muted small" style="font-size: 0.8rem;">
                                    <span class="fw-bold text-dark me-2">Quick Preview:</span>
                                    <?php foreach ($bank['inventory'] as $bg => $item): ?>
                                        <span class="badge <?php echo $item['units'] > 0 ? 'bg-white text-dark border' : 'bg-danger-subtle text-danger border border-danger-subtle'; ?> rounded-pill px-2 py-1">
                                            <?php echo $bg; ?>: <strong><?php echo $item['units']; ?></strong>
                                        </span>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Expandable Detailed Inventory Panel -->
                                <div class="inv-bank-body <?php echo $isMyBranch ? 'open' : ''; ?>" id="inv-body-<?php echo $bid; ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h6 class="fw-bold text-dark mb-0"><i class="bi bi-grid-3x3-gap-fill text-danger me-2"></i>Detailed Stock Breakdown for <?php echo htmlspecialchars($bank['name']); ?></h6>
                                        <?php if ($isMyBranch): ?>
                                            <button class="btn btn-sm btn-primary rounded-pill px-3 fw-bold" onclick="showSection('update-section')">
                                                <i class="bi bi-plus-lg me-1"></i>Update Your Branch Stock
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                    <div class="row g-3">
                                        <?php foreach ($bank['inventory'] as $bg => $item): 
                                            $cardBorderColor = ($item['units'] === 0) ? '#dc3545' : (($item['units'] < 5) ? '#f59e0b' : '#10b981');
                                            $tagBadgeClass = ($item['units'] === 0) ? 'bg-danger text-white' : (($item['units'] < 5) ? 'bg-warning text-dark' : 'bg-success text-white');
                                        ?>
                                            <div class="col-6 col-md-4 col-lg-3">
                                                <div class="p-3 bg-white border rounded-4 text-center shadow-sm position-relative" style="border-bottom: 4px solid <?php echo $cardBorderColor; ?> !important;">
                                                    <span class="badge bg-danger rounded-pill px-3 py-1 mb-2 fs-6"><?php echo $bg; ?></span>
                                                    <div class="my-1">
                                                        <h3 class="fw-extrabold mb-0 <?php echo ($item['units'] === 0) ? 'text-danger' : (($item['units'] < 5) ? 'text-warning' : 'text-success'); ?>">
                                                            <?php echo $item['units']; ?>
                                                        </h3>
                                                        <small class="text-muted fw-semibold">Units Available</small>
                                                    </div>
                                                    
                                                    <div class="mt-2">
                                                        <span class="badge <?php echo $tagBadgeClass; ?> rounded-pill px-2 py-1" style="font-size: 0.72rem;">
                                                            <?php echo $item['status_text']; ?>
                                                        </span>
                                                    </div>

                                                    <?php if (!empty($item['expiry'])): ?>
                                                        <div class="mt-2 pt-2 border-top">
                                                            <small class="text-muted" style="font-size: 0.72rem;">
                                                                <i class="bi bi-calendar2-x me-1"></i>Exp: <?php echo htmlspecialchars($item['expiry']); ?>
                                                            </small>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                            </div>
                            <?php endforeach; ?>
                        </div>

                    </div>
                </div>

                <!-- ========================================== -->
                <!-- 3. EMERGENCY BLOOD NEEDS SECTION           -->
                <!-- ========================================== -->
                <div id="emergency-section" class="content-section <?php echo $initialSection === 'emergency-section' ? '' : 'd-none-soft'; ?>">
                    <div class="card-custom">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4 pb-3 border-bottom">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <h4 class="fw-bold text-danger mb-0"><i class="bi bi-exclamation-octagon-fill me-2"></i>Emergency Shortages</h4>
                                    <span class="badge bg-danger rounded-pill px-3 py-1 fs-6 pulse-glow">
                                        <i class="bi bi-broadcast me-1"></i><?php echo count($activeShortages); ?> Active
                                    </span>
                                </div>
                                <p class="text-muted small mb-0">Whenever patient blood requests exceed total available network inventory, shortages are automatically detected and matching verified available donors are instantly alerted.</p>
                            </div>
                            <form method="POST" action="bloodbank_dashboard.php" class="m-0">
                                <button type="submit" name="trigger_emergency_check" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm">
                                    <i class="bi bi-broadcast me-2"></i> Scan & Broadcast Shortage Alerts
                                </button>
                            </form>
                        </div>

                        <?php if (count($activeShortages) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Req ID & Patient</th>
                                            <th>Blood Group</th>
                                            <th>Priority</th>
                                            <th>Required</th>
                                            <th>In Network</th>
                                            <th>Shortage Deficit</th>
                                            <th>Emergency Status</th>
                                            <th>👥 Donors Alerted</th>
                                            <th>❤️ Willing Donors</th>
                                            <th class="text-end">Staff Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($activeShortages as $sh): 
                                            $reqId = (int)$sh['request_id'];
                                            $respCount = count($sh['responding_donors']);
                                        ?>
                                            <tr>
                                                <td class="fw-bold text-dark">
                                                    #<?php echo $reqId; ?><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($sh['patient_name'] ?? 'Confidential'); ?> (Age: <?php echo $sh['age'] ?? '—'; ?>)</small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-danger rounded-pill px-3 py-2 fs-6 fw-bold"><?php echo htmlspecialchars($sh['blood_group']); ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-dark rounded-pill px-2 py-1">Score: <?php echo $sh['priority_score']; ?></span>
                                                </td>
                                                <td>
                                                    <strong class="text-dark fs-6"><?php echo (int)$sh['units_needed']; ?> units</strong>
                                                </td>
                                                <td>
                                                    <span class="text-muted"><?php echo (int)$sh['units_available']; ?> units</span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-danger rounded-pill px-3 py-1 fs-6">
                                                        -<?php echo $sh['deficit']; ?> units
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $sh['status_badge_class']; ?> rounded-pill px-3 py-1 fw-bold">
                                                        <?php echo $sh['display_status']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="fw-bold text-dark fs-6"><i class="bi bi-broadcast me-1 text-danger"></i><?php echo $sh['alerted_count']; ?> Donors</span>
                                                    <small class="text-muted d-block" style="font-size: 0.75rem;"><?php echo $sh['matching_donors_count']; ?> Eligible in Network</small>
                                                </td>
                                                <td>
                                                    <span class="fw-bold text-danger fs-6"><i class="bi bi-heart-fill me-1"></i><?php echo $sh['willing_count']; ?> Donors</span>
                                                    <small class="text-muted d-block" style="font-size: 0.75rem;">Responded Yes</small>
                                                </td>
                                                <td class="text-end">
                                                    <button type="button" class="btn btn-sm btn-danger rounded-pill px-3 fw-bold"
                                                            onclick="toggleEmergencyDonors(<?php echo $reqId; ?>)">
                                                        <i class="bi bi-people-fill me-1"></i>👥 View Donors (<?php echo $respCount; ?>)
                                                    </button>
                                                </td>
                                            </tr>

                                            <!-- Expandable Responding Donors Row -->
                                            <tr id="emergency_donors_row_<?php echo $reqId; ?>" class="d-none bg-light">
                                                <td colspan="10" class="p-3">
                                                    <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
                                                        <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
                                                            <h6 class="fw-bold text-dark mb-0">
                                                                <i class="bi bi-people-fill text-danger me-2"></i>INTERESTED / RESPONDING DONORS for Request #<?php echo $reqId; ?> (<?php echo htmlspecialchars($sh['blood_group']); ?>)
                                                            </h6>
                                                            <span class="badge bg-light text-muted border rounded-pill px-3 py-1">
                                                                <?php echo $respCount; ?> Total Response(s)
                                                            </span>
                                                        </div>

                                                        <?php if ($respCount > 0): ?>
                                                            <div class="table-responsive">
                                                                <table class="table table-sm table-hover align-middle mb-0">
                                                                    <thead class="table-light">
                                                                        <tr>
                                                                            <th>#</th>
                                                                            <th>Donor Name</th>
                                                                            <th>Blood Group</th>
                                                                            <th>Contact</th>
                                                                            <th>Verified</th>
                                                                            <th>Response Time</th>
                                                                            <th>Status</th>
                                                                            <th class="text-end">Staff Actions</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <?php foreach ($sh['responding_donors'] as $i => $rd): 
                                                                            $respStatus = $rd['status'];
                                                                            $isVerifiedDonor = strtolower($rd['verified'] ?? '') === 'yes';
                                                                            $statusBadge = ($respStatus === 'Completed') 
                                                                                ? 'bg-success text-white' 
                                                                                : (($respStatus === 'Confirmed') ? 'bg-primary text-white' : 'bg-warning text-dark');
                                                                        ?>
                                                                            <tr>
                                                                                <td class="fw-bold text-muted"><?php echo $i + 1; ?></td>
                                                                                <td class="fw-bold text-dark"><?php echo htmlspecialchars($rd['donor_name']); ?></td>
                                                                                <td>
                                                                                    <span class="badge bg-danger rounded-pill px-2 py-1"><?php echo htmlspecialchars($rd['blood_group']); ?></span>
                                                                                </td>
                                                                                <td>
                                                                                    <?php if (!empty($rd['contact'])): ?>
                                                                                        <a href="tel:<?php echo htmlspecialchars($rd['contact']); ?>" class="text-decoration-none fw-bold text-primary">
                                                                                            <i class="bi bi-telephone-fill me-1"></i><?php echo htmlspecialchars($rd['contact']); ?>
                                                                                        </a>
                                                                                    <?php else: ?>
                                                                                        <span class="text-muted">Not Provided</span>
                                                                                    <?php endif; ?>
                                                                                </td>
                                                                                <td>
                                                                                    <?php if ($isVerifiedDonor): ?>
                                                                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1"><i class="bi bi-patch-check-fill me-1"></i>Verified</span>
                                                                                    <?php else: ?>
                                                                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-2 py-1">Pending</span>
                                                                                    <?php endif; ?>
                                                                                </td>
                                                                                <td class="small text-muted"><?php echo date('d M Y, h:i A', strtotime($rd['created_at'])); ?></td>
                                                                                <td>
                                                                                    <span class="badge <?php echo $statusBadge; ?> rounded-pill px-3 py-1">
                                                                                        <?php echo htmlspecialchars($respStatus); ?>
                                                                                    </span>
                                                                                </td>
                                                                                <td class="text-end">
                                                                                    <div class="d-flex gap-1 justify-content-end">
                                                                                        <?php if ($respStatus === 'Willing to Donate'): ?>
                                                                                            <form method="POST" action="bloodbank_dashboard.php" class="m-0">
                                                                                                <input type="hidden" name="response_id" value="<?php echo $rd['response_id']; ?>">
                                                                                                <button type="submit" name="confirm_emergency_donor" class="btn btn-sm btn-outline-primary rounded-pill px-3" title="Confirm appointment with donor">
                                                                                                    <i class="bi bi-calendar-check me-1"></i>Confirm Appointment
                                                                                                </button>
                                                                                            </form>
                                                                                        <?php endif; ?>

                                                                                        <?php if (in_array($respStatus, ['Willing to Donate', 'Confirmed'])): ?>
                                                                                            <button type="button" class="btn btn-sm btn-success rounded-pill px-3 fw-bold shadow-sm"
                                                                                                    onclick="openVerifyDonationModal(<?php echo $rd['response_id']; ?>, '<?php echo addslashes($rd['donor_name']); ?>', '<?php echo $rd['blood_group']; ?>', <?php echo $reqId; ?>)">
                                                                                                <i class="bi bi-patch-check-fill me-1"></i>Verify Donation
                                                                                            </button>
                                                                                        <?php elseif ($respStatus === 'Completed'): ?>
                                                                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-2">
                                                                                                <i class="bi bi-check-all me-1"></i>Donation Verified (+250 Pts)
                                                                                            </span>
                                                                                        <?php endif; ?>
                                                                                    </div>
                                                                                </td>
                                                                            </tr>
                                                                        <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="text-center py-4 text-muted small">
                                                                <i class="bi bi-clock-history fs-3 d-block mb-2 opacity-50"></i>
                                                                No donors have responded "I CAN HELP" yet for this shortage. Notifications were dispatched to compatible donors.
                                                            </div>
                                                        <?php endif; ?>

                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <div class="bg-success bg-opacity-10 text-success rounded-circle d-inline-flex align-items-center justify-content-center p-4 mb-3" style="width: 80px; height: 80px;">
                                    <i class="bi bi-shield-check fs-1"></i>
                                </div>
                                <h5 class="fw-bold text-dark">No Critical Shortages Detected</h5>
                                <p class="text-muted small mb-0">System stock currently satisfies pending patient requirements. Automated alerts will fire immediately if a shortage is detected.</p>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>

                <!-- ========================================== -->
                <!-- 4. BLOOD CAMP MANAGEMENT SECTION           -->
                <!-- ========================================== -->
                <div id="camps-section" class="content-section <?php echo $initialSection === 'camps-section' ? '' : 'd-none-soft'; ?>">
                    <div class="card-custom">

                        <!-- Section Header -->
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4 pb-3 border-bottom">
                            <div>
                                <h4 class="fw-bold text-dark mb-1"><i class="bi bi-calendar2-heart-fill text-danger me-2"></i>🩸 Blood Camp Management</h4>
                                <p class="text-muted mb-0">Create, organize and monitor blood donation camps. Donors can discover and express interest in upcoming camps.</p>
                            </div>
                            <button type="button" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm flex-shrink-0"
                                    data-bs-toggle="modal" data-bs-target="#createCampModal">
                                <i class="bi bi-plus-circle-fill me-2"></i>+ Create Blood Camp
                            </button>
                        </div>

                        <!-- Summary Stats Row -->
                        <div class="row g-3 mb-4 text-center">
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-light rounded-4 border h-100">
                                    <div class="text-muted text-uppercase fw-bold small mb-1">Total Camps</div>
                                    <div class="fs-3 fw-extrabold text-dark"><?php echo $campStats['total']; ?></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-success bg-opacity-10 rounded-4 border border-success-subtle h-100">
                                    <div class="text-success text-uppercase fw-bold small mb-1">Upcoming</div>
                                    <div class="fs-3 fw-extrabold text-success"><?php echo $campStats['upcoming']; ?></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-primary bg-opacity-10 rounded-4 border border-primary-subtle h-100">
                                    <div class="text-primary text-uppercase fw-bold small mb-1">Completed</div>
                                    <div class="fs-3 fw-extrabold text-primary"><?php echo $campStats['completed']; ?></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-danger bg-opacity-10 rounded-4 border border-danger-subtle h-100">
                                    <div class="text-danger text-uppercase fw-bold small mb-1">Interested Donors</div>
                                    <div class="fs-3 fw-extrabold text-danger"><?php echo $campStats['registrations']; ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Filter Pills -->
                        <div class="d-flex flex-wrap gap-2 mb-4">
                            <button class="filter-btn active" onclick="filterCamps('All', this)">All Camps (<?php echo $campStats['total']; ?>)</button>
                            <button class="filter-btn" onclick="filterCamps('Upcoming', this)">🟢 Upcoming (<?php echo $campStats['upcoming']; ?>)</button>
                            <button class="filter-btn" onclick="filterCamps('Completed', this)">🔵 Completed (<?php echo $campStats['completed']; ?>)</button>
                            <button class="filter-btn" onclick="filterCamps('Cancelled', this)">🔴 Cancelled (<?php echo $campStats['cancelled']; ?>)</button>
                        </div>

                        <!-- Camp Cards Grid -->
                        <?php if (count($allCamps) > 0): ?>
                            <div class="row g-4" id="campsGrid">
                                <?php foreach ($allCamps as $camp):
                                    $statusColor  = $camp['status'] === 'Upcoming' ? 'success' : ($camp['status'] === 'Completed' ? 'primary' : 'danger');
                                    $statusEmoji  = $camp['status'] === 'Upcoming' ? '🟢' : ($camp['status'] === 'Completed' ? '🔵' : '🔴');
                                    $campName     = htmlspecialchars($camp['display_name'] ?? $camp['name'] ?? 'Blood Donation Camp');
                                    $registered   = (int)$camp['registered_count'];
                                    $expected     = (int)($camp['expected_donors'] ?? 0);
                                    $isFull       = $expected > 0 && $registered >= $expected;
                                    $timeStr      = htmlspecialchars(($camp['start_time'] ?? '10:00') . ' – ' . ($camp['end_time'] ?? '15:00'));
                                    $dateStr      = $camp['date'] ? date('d M Y', strtotime($camp['date'])) : '—';
                                    $campJson     = json_encode($camp, JSON_HEX_APOS | JSON_HEX_QUOT);
                                ?>
                                    <div class="col-md-6 col-lg-4 camp-card-col" data-status="<?php echo htmlspecialchars($camp['status']); ?>">
                                        <div class="camp-card p-4 d-flex flex-column h-100">

                                            <!-- Top row: status badge + donor count -->
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                <span class="badge bg-<?php echo $statusColor; ?>-subtle text-<?php echo $statusColor; ?> border border-<?php echo $statusColor; ?>-subtle rounded-pill px-3 py-2 fw-bold fs-7">
                                                    <?php echo $statusEmoji . ' ' . htmlspecialchars($camp['status']); ?>
                                                </span>
                                                <!-- Clickable donor count badge -->
                                                <button type="button"
                                                    class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-bold donor-count-btn"
                                                    onclick="openCampDonors(<?php echo (int)$camp['camp_id']; ?>, '<?php echo addslashes($campName); ?>')"
                                                    title="View Interested Donors">
                                                    <i class="bi bi-people-fill me-1"></i>
                                                    <?php echo $registered; ?> / <?php echo $expected; ?> Interested
                                                    <?php if ($isFull): ?>
                                                        <span class="badge bg-danger ms-1">FULL</span>
                                                    <?php endif; ?>
                                                </button>
                                            </div>

                                            <!-- Camp Name -->
                                            <h5 class="fw-bold text-dark mb-3"><?php echo $campName; ?></h5>

                                            <!-- Details -->
                                            <div class="text-muted mb-3 flex-grow-1" style="font-size: 0.93rem; line-height: 1.8;">
                                                <div><i class="bi bi-geo-alt-fill text-danger me-2"></i><?php echo htmlspecialchars($camp['location'] . ($camp['venue'] ? ', ' . $camp['venue'] : '')); ?></div>
                                                <div><i class="bi bi-calendar-event text-danger me-2"></i><?php echo $dateStr; ?></div>
                                                <div><i class="bi bi-clock-fill text-danger me-2"></i><?php echo $timeStr; ?></div>
                                                <?php if (!empty($camp['contact'])): ?>
                                                    <div><i class="bi bi-telephone-fill text-danger me-2"></i><?php echo htmlspecialchars($camp['contact']); ?></div>
                                                <?php endif; ?>
                                            </div>

                                            <?php if (!empty($camp['description'])): ?>
                                                <p class="text-muted mb-3" style="font-size: 0.88rem; font-style: italic; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">
                                                    <?php echo htmlspecialchars($camp['description']); ?>
                                                </p>
                                            <?php endif; ?>

                                            <!-- Action Buttons -->
                                            <div class="d-flex flex-wrap gap-2 pt-3 border-top mt-auto">
                                                <!-- View Interested Donors -->
                                                <button type="button"
                                                    class="btn btn-danger rounded-pill fw-bold flex-fill"
                                                    style="font-size: 0.88rem;"
                                                    onclick="openCampDonors(<?php echo (int)$camp['camp_id']; ?>, '<?php echo addslashes($campName); ?>')">
                                                    <i class="bi bi-people-fill me-1"></i>👥 View Interested Donors
                                                </button>
                                            </div>
                                            <div class="d-flex flex-wrap gap-2 mt-2">
                                                <!-- View Details -->
                                                <button type="button"
                                                    class="btn btn-sm btn-outline-secondary rounded-pill flex-fill"
                                                    onclick='openViewCampModal(<?php echo $campJson; ?>)'>
                                                    <i class="bi bi-eye me-1"></i>View Details
                                                </button>
                                                <!-- Edit -->
                                                <button type="button"
                                                    class="btn btn-sm btn-outline-primary rounded-pill flex-fill"
                                                    onclick='openEditCampModal(<?php echo $campJson; ?>)'>
                                                    <i class="bi bi-pencil me-1"></i>Edit
                                                </button>
                                                <!-- Cancel (Upcoming only) -->
                                                <?php if ($camp['status'] === 'Upcoming'): ?>
                                                    <form method="POST" action="bloodbank_dashboard.php" class="m-0"
                                                          onsubmit="return confirm('Cancel this camp? This cannot be undone.');">
                                                        <input type="hidden" name="camp_id" value="<?php echo (int)$camp['camp_id']; ?>">
                                                        <button type="submit" name="cancel_camp"
                                                            class="btn btn-sm btn-outline-danger rounded-pill px-3"
                                                            title="Cancel Camp">
                                                            <i class="bi bi-x-circle me-1"></i>Cancel
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>

                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-calendar2-x fs-1 d-block mb-3 opacity-25"></i>
                                <h6 class="fw-bold">No Blood Camps Yet</h6>
                                <p class="mb-0">Click <strong>+ Create Blood Camp</strong> above to schedule an upcoming donation drive.</p>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>


                <!-- ========================================== -->
                <!-- 5. UPDATE TARGET STOCK SECTION             -->
                <!-- ========================================== -->
                <div id="update-section" class="content-section <?php echo $initialSection === 'update-section' ? '' : 'd-none-soft'; ?>">
                    <div class="card-custom">
                        <h4 class="fw-bold mb-1 text-dark"><i class="bi bi-cloud-arrow-up-fill text-primary me-2"></i>Update Target Stock</h4>
                        <p class="text-muted mb-4 border-bottom pb-3">Updating stock here directly refreshes your blood bank's inventory in the network. Quantities will automatically update in real-time.</p>
                        
                        <form action="bloodbank_dashboard.php" method="POST">
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold text-muted small">Blood Group</label>
                                    <select name="blood_group" class="form-select form-select-lg" required>
                                        <option value="" disabled selected>Select Group...</option>
                                        <?php foreach ($groups as $g): ?>
                                            <option value="<?php echo $g; ?>"><?php echo $g; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold text-muted small">Units to Add/Set</label>
                                    <input type="number" name="units_available" class="form-control form-control-lg" min="1" placeholder="e.g. 10" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold text-muted small">Expiry Date</label>
                                    <input type="date" name="expiry_date" class="form-control form-control-lg" required value="<?php echo date('Y-m-d', strtotime('+35 days')); ?>">
                                </div>
                            </div>
                            <button type="submit" name="update_stock_direct" class="btn btn-primary btn-lg rounded-pill px-5 shadow-sm fw-bold">
                                <i class="bi bi-check2-circle me-2"></i>Update Stock Record
                            </button>
                        </form>
                    </div>
                </div>

                <!-- ========================================== -->
                <!-- 6. EXPIRY ALERTS SECTION                   -->
                <!-- ========================================== -->
                <div id="expiry-section" class="content-section <?php echo $initialSection === 'expiry-section' ? '' : 'd-none-soft'; ?>">
                    <div class="card-custom">
                        <h4 class="fw-bold text-dark mb-1"><i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>Imminent Expirations (5 Days)</h4>
                        <p class="text-muted small mb-4">Blood units nearing safety expiration thresholds that require immediate priority dispensing.</p>
                        
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Blood Group</th>
                                        <th>Units At Risk</th>
                                        <th>Expiry Threshold</th>
                                        <th>Facility Location</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(count($expiryAlerts) > 0): ?>
                                        <?php 
                                            foreach ($expiryAlerts as $alert): 
                                                $today = new DateTime();
                                                $expDate = new DateTime($alert['expiry_date']);
                                                $interval = $today->diff($expDate);
                                                
                                                if ($expDate < $today && $interval->days > 0) {
                                                    $alertColor = 'bg-danger text-white border-danger';
                                                    $alertText = 'EXPIRED';
                                                    $textColor = 'text-danger fw-bold';
                                                } else {
                                                    $alertColor = 'bg-warning text-dark border-warning';
                                                    $alertText = 'EXPIRING SOON';
                                                    $textColor = 'text-warning fw-bold';
                                                }
                                        ?>
                                            <tr>
                                                <td><span class="badge-blood px-3 py-1 fs-6"><?php echo htmlspecialchars($alert['blood_group']); ?></span></td>
                                                <td><h5 class="mb-0 fw-bold"><?php echo htmlspecialchars($alert['units_available']); ?></h5></td>
                                                <td>
                                                    <span class="badge <?php echo $alertColor; ?> border d-flex justify-content-between align-items-center" style="width: 140px;">
                                                        <span><?php echo htmlspecialchars($alert['expiry_date']); ?></span>
                                                        <i class="bi bi-clock-history ms-1"></i>
                                                    </span>
                                                    <small class="<?php echo $textColor; ?> d-block mt-1" style="font-size: 0.70rem;"><?php echo $alertText; ?></small>
                                                </td>
                                                <td class="text-muted">
                                                    <span class="fw-bold text-dark"><i class="bi bi-hospital me-1"></i><?php echo htmlspecialchars($alert['name']); ?></span><br>
                                                    <small><i class="bi bi-pin-map-fill me-1"></i><?php echo htmlspecialchars($alert['location']); ?></small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center text-muted py-4">No critical expiries detected in the system within the next 5 days.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ========================================== -->
                <!-- 7. REQUESTS SECTION (MATCH QUEUE)          -->
                <!-- ========================================== -->
                <div id="requests-section" class="content-section <?php echo $initialSection === 'requests-section' ? '' : 'd-none-soft'; ?>">
                    <div class="card-custom">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div>
                                <h4 class="fw-bold m-0 text-dark"><i class="bi bi-clock-history text-success me-2"></i>Pending Fulfillment Queue</h4>
                                <small class="text-muted">Patient blood requests awaiting authorized dispensing from blood bank storage.</small>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Patient Target</th>
                                        <th>Blood Group</th>
                                        <th>Units Needed</th>
                                        <th>Priority Score</th>
                                        <th>Shortage Alert</th>
                                        <th class="text-end">Fulfill</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($pendingBloodRequests as $req): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <i class="bi bi-person-circle fs-3 text-secondary me-2"></i>
                                                    <div>
                                                        <span class="fw-bold text-dark d-block"><?php echo htmlspecialchars($req['name']); ?></span>
                                                        <small class="text-muted">Req ID: #<?php echo $req['request_id']; ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-danger rounded-pill px-3 py-2 fs-6"><?php echo htmlspecialchars($req['blood_group']); ?></span>
                                            </td>
                                            <td>
                                                <span class="fw-bold text-dark"><i class="bi bi-droplet-fill text-danger me-1"></i><?php echo htmlspecialchars($req['units_needed']); ?> Units</span>
                                            </td>
                                            <td>
                                                <h5 class="mb-0 fw-bold <?php echo ($req['priority_score'] > 75) ? 'text-danger' : 'text-primary'; ?>">
                                                    <?php echo htmlspecialchars($req['priority_score']); ?>
                                                </h5>
                                            </td>
                                            <td>
                                                <?php if ($req['emergency_alert_status'] === 'active'): ?>
                                                    <span class="badge bg-danger rounded-pill px-3 py-1 pulse-glow">
                                                        <i class="bi bi-broadcast me-1"></i> Alert Active
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-muted border rounded-pill px-2 py-1">Normal</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <form action="bloodbank_dashboard.php" method="POST" class="m-0" onsubmit="return confirm('Confirm blood dispensing? This will deduct inventory and complete request.');">
                                                    <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                                                    <button type="submit" name="fulfill_request" class="btn" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white; border-radius: 50px; font-weight: 600; padding: 0.4rem 1.2rem; border: none;">
                                                        <i class="bi bi-check2-all me-1"></i>Fulfill
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if(count($pendingBloodRequests) === 0): ?>
                                        <tr><td colspan="6" class="text-center py-5 text-muted">No pending requests waiting for fulfillment.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ========================================== -->
                <!-- 8. BLOOD BANK PROFILE SECTION              -->
                <!-- ========================================== -->
                <div id="profile-section" class="content-section <?php echo $initialSection === 'profile-section' ? '' : 'd-none-soft'; ?>">
                    <div class="card-custom">
                        <h4 class="fw-bold mb-4 text-dark"><i class="bi bi-person-fill text-danger me-2"></i>Blood Bank Facility Profile</h4>
                        <form action="bloodbank_dashboard.php" method="POST">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">Bank Name</label>
                                    <input type="text" name="name" class="form-control form-control-lg" value="<?php echo htmlspecialchars($bankData['name'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">Location / City Area</label>
                                    <input type="text" name="location" class="form-control form-control-lg" value="<?php echo htmlspecialchars($bankData['location'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">Contact Phone Number</label>
                                    <input type="text" name="contact" class="form-control form-control-lg" value="<?php echo htmlspecialchars($bankData['contact'] ?? ''); ?>" required placeholder="e.g. +91XXXXXXXXXX">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">Storage Capacity (Units)</label>
                                    <input type="number" name="capacity" class="form-control form-control-lg" value="<?php echo htmlspecialchars($bankData['capacity'] ?? '0'); ?>" required min="0">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label fw-bold small text-muted">Regulatory License Number</label>
                                    <input type="text" name="license_no" class="form-control form-control-lg" value="<?php echo htmlspecialchars($bankData['license_no'] ?? ''); ?>" required placeholder="Enter valid blood bank regulatory license number">
                                </div>
                                <div class="col-12 mt-4">
                                    <button type="submit" name="update_profile" class="btn btn-danger rounded-pill px-5 shadow-sm fw-bold">Save Profile Changes</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

            </div>

            <!-- Footer -->
            <footer class="text-center py-4 text-muted mt-5" style="border-top: 1px solid rgba(0,0,0,0.05);">
                &copy; 2026 MediMatch Blood Resource & Emergency Management Center | Saving Lives Through Smart Matching
            </footer>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODALS: CREATE & EDIT BLOOD CAMPS          -->
    <!-- ========================================== -->

    <!-- Create Blood Camp Modal -->
    <div class="modal fade" id="createCampModal" tabindex="-1" aria-labelledby="createCampModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark" id="createCampModalLabel">
                        <i class="bi bi-plus-circle-fill text-danger me-2"></i>Create New Blood Donation Camp
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="bloodbank_dashboard.php" method="POST">
                    <div class="modal-body pt-3">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label fw-bold small text-muted">Camp Name *</label>
                                <input type="text" name="camp_name" class="form-control" required placeholder="e.g. City Central Community Blood Drive">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-muted">Location (City/District) *</label>
                                <input type="text" name="location" class="form-control" required placeholder="e.g. Bhavanipuram Colony">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-muted">Venue / Full Address *</label>
                                <input type="text" name="venue" class="form-control" required placeholder="e.g. Community Hall, Opp. Central Park">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-muted">Date *</label>
                                <input type="date" name="date" class="form-control" required min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-muted">Start Time *</label>
                                <input type="time" name="start_time" class="form-control" required value="09:00">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-muted">End Time *</label>
                                <input type="time" name="end_time" class="form-control" required value="16:00">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-muted">Organizer / Contact Phone *</label>
                                <input type="text" name="contact" class="form-control" required placeholder="e.g. +91XXXXXXXXXX" value="<?php echo htmlspecialchars($bankData['contact'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-muted">Expected Donors</label>
                                <input type="number" name="expected_donors" class="form-control" min="1" value="50" placeholder="e.g. 50">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-bold small text-muted">Camp Description & Guidelines</label>
                                <textarea name="description" class="form-control" rows="3" placeholder="Explain the camp purpose, refreshments provided, doctor consultations available..."></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-muted">Camp Status</label>
                                <select name="status" class="form-select">
                                    <option value="Upcoming" selected>Upcoming</option>
                                    <option value="Completed">Completed</option>
                                    <option value="Cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-top-0 pt-0">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_camp" class="btn btn-danger rounded-pill px-5 fw-bold shadow-sm">
                            <i class="bi bi-calendar2-check me-2"></i>Create Camp
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Blood Camp Modal -->
    <div class="modal fade" id="editCampModal" tabindex="-1" aria-labelledby="editCampModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark" id="editCampModalLabel">
                        <i class="bi bi-pencil-square text-primary me-2"></i>Edit Blood Donation Camp
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="bloodbank_dashboard.php" method="POST">
                    <input type="hidden" name="camp_id" id="edit_camp_id">
                    <div class="modal-body pt-3">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label fw-bold small text-muted">Camp Name *</label>
                                <input type="text" name="camp_name" id="edit_camp_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-muted">Location (City/District) *</label>
                                <input type="text" name="location" id="edit_location" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-muted">Venue / Full Address *</label>
                                <input type="text" name="venue" id="edit_venue" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-muted">Date *</label>
                                <input type="date" name="date" id="edit_date" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-muted">Start Time *</label>
                                <input type="time" name="start_time" id="edit_start_time" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-muted">End Time *</label>
                                <input type="time" name="end_time" id="edit_end_time" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-muted">Organizer / Contact Phone *</label>
                                <input type="text" name="contact" id="edit_contact" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-muted">Expected Donors</label>
                                <input type="number" name="expected_donors" id="edit_expected_donors" class="form-control" min="1">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-bold small text-muted">Camp Description & Guidelines</label>
                                <textarea name="description" id="edit_description" class="form-control" rows="3"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-muted">Camp Status *</label>
                                <select name="status" id="edit_status" class="form-select" required>
                                    <option value="Upcoming">Upcoming</option>
                                    <option value="Completed">Completed</option>
                                    <option value="Cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-top-0 pt-0">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="edit_camp" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm">
                            <i class="bi bi-check2-circle me-2"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- View Camp Details Modal -->
    <div class="modal fade" id="viewCampModal" tabindex="-1" aria-labelledby="viewCampModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-bottom-0">
                    <h5 class="modal-title fw-bold text-dark" id="viewCampModalLabel">
                        <i class="bi bi-calendar2-heart-fill text-danger me-2"></i>Camp Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-0">
                    <h4 class="fw-bold text-dark mb-2" id="view_camp_name"></h4>
                    <div class="mb-3" id="view_camp_status_badge"></div>
                    <div class="p-3 bg-light rounded-4 border mb-3" style="line-height: 1.9;">
                        <div class="mb-2"><strong class="text-dark"><i class="bi bi-geo-alt-fill text-danger me-2"></i>Location:</strong> <span id="view_location"></span></div>
                        <div class="mb-2"><strong class="text-dark"><i class="bi bi-building me-2"></i>Venue:</strong> <span id="view_venue"></span></div>
                        <div class="mb-2"><strong class="text-dark"><i class="bi bi-calendar-event me-2"></i>Date:</strong> <span id="view_date"></span></div>
                        <div class="mb-2"><strong class="text-dark"><i class="bi bi-clock me-2"></i>Timing:</strong> <span id="view_time"></span></div>
                        <div class="mb-2"><strong class="text-dark"><i class="bi bi-telephone me-2"></i>Contact:</strong> <span id="view_contact"></span></div>
                        <div class="mb-0"><strong class="text-dark"><i class="bi bi-people me-2"></i>Interested Donors:</strong> <span id="view_donors"></span></div>
                    </div>
                    <div class="mb-3">
                        <strong class="text-dark small text-uppercase">Description:</strong>
                        <p class="text-muted mt-1 mb-0" id="view_description"></p>
                    </div>
                    <!-- View Donors button inside details modal -->
                    <button type="button" class="btn btn-danger rounded-pill px-4 fw-bold w-100" id="view_camp_donors_btn">
                        <i class="bi bi-people-fill me-2"></i>👥 View Interested Donors List
                    </button>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================================================== -->
    <!-- INTERESTED DONORS MODAL                               -->
    <!-- ===================================================== -->
    <div class="modal fade" id="campDonorsModal" tabindex="-1" aria-labelledby="campDonorsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header bg-danger text-white rounded-top-4 border-0">
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="campDonorsModalLabel">
                            <i class="bi bi-people-fill me-2"></i>👥 Interested Donors
                        </h5>
                        <div class="small mt-1 opacity-75" id="donors_modal_camp_info"></div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">

                    <!-- Loading spinner -->
                    <div id="donors_loading" class="text-center py-5">
                        <div class="spinner-border text-danger" role="status" style="width:3rem;height:3rem;"></div>
                        <p class="text-muted mt-3 fw-bold">Loading donor list...</p>
                    </div>

                    <!-- Error state -->
                    <div id="donors_error" class="d-none alert alert-danger rounded-4">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><span id="donors_error_msg"></span>
                    </div>

                    <!-- Donor content (hidden until loaded) -->
                    <div id="donors_content" class="d-none">

                        <!-- Camp summary bar -->
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 p-3 bg-light rounded-4 border mb-4">
                            <div>
                                <div class="fw-bold text-dark" id="dm_camp_name" style="font-size:1.05rem;"></div>
                                <div class="text-muted small mt-1" id="dm_camp_meta"></div>
                            </div>
                            <div class="text-center">
                                <div class="fs-4 fw-extrabold text-danger" id="dm_count_display">0 / 0</div>
                                <div class="text-muted small">Interested / Expected</div>
                            </div>
                        </div>

                        <!-- Blood Group Summary -->
                        <div class="mb-4">
                            <div class="fw-bold text-dark mb-2 small text-uppercase">🩸 Blood Group Summary</div>
                            <div class="d-flex flex-wrap gap-2" id="dm_bg_summary"></div>
                        </div>

                        <!-- Search + Filter Row -->
                        <div class="d-flex flex-column flex-md-row gap-2 mb-4">
                            <div class="input-group flex-fill">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                                <input type="text" class="form-control border-start-0 ps-0" id="donor_search_input"
                                    placeholder="🔍 Search by name, blood group..." oninput="filterDonorRows()">
                            </div>
                            <select class="form-select" style="max-width:200px;" id="donor_bg_filter" onchange="filterDonorRows()">
                                <option value="">All Blood Groups</option>
                                <option>A+</option><option>A-</option>
                                <option>B+</option><option>B-</option>
                                <option>AB+</option><option>AB-</option>
                                <option>O+</option><option>O-</option>
                            </select>
                        </div>

                        <!-- Donor Table -->
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="donors_table">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:40px;">#</th>
                                        <th>Donor</th>
                                        <th>Blood Group</th>
                                        <th>Contact</th>
                                        <th>Type</th>
                                        <th>Verified</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody id="donors_table_body">
                                </tbody>
                            </table>
                        </div>

                        <!-- No results within filter -->
                        <div id="donors_no_filter_results" class="d-none text-center py-4 text-muted">
                            <i class="bi bi-search fs-3 d-block mb-2 opacity-25"></i>
                            No donors match the current filter.
                        </div>

                        <!-- Empty state (no donors at all) -->
                        <div id="donors_empty" class="d-none text-center py-5 text-muted">
                            <i class="bi bi-people fs-1 d-block mb-3 opacity-25"></i>
                            <h6 class="fw-bold">No Donors Interested Yet</h6>
                            <p class="mb-0 small">Donors who click "I'm Interested" on this camp will appear here.</p>
                        </div>

                    </div><!-- /donors_content -->
                </div>
                <div class="modal-footer border-top-0 bg-light rounded-bottom-4">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-5" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- VERIFY EMERGENCY DONATION MODAL -->
    <div class="modal fade" id="verifyDonationModal" tabindex="-1" aria-labelledby="verifyDonationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header bg-success text-white rounded-top-4 border-0">
                    <h5 class="modal-title fw-bold" id="verifyDonationModalLabel">
                        <i class="bi bi-patch-check-fill me-2"></i>Verify Completed Blood Donation
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="bloodbank_dashboard.php" method="POST">
                    <input type="hidden" name="response_id" id="vd_response_id">
                    <div class="modal-body p-4">
                        <div class="p-3 bg-light rounded-4 border mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted small fw-bold">Donor Name:</span>
                                <strong class="text-dark" id="vd_donor_name"></strong>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted small fw-bold">Blood Group:</span>
                                <span class="badge bg-danger rounded-pill px-3 py-1 fs-6" id="vd_blood_group"></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small fw-bold">Shortage Request:</span>
                                <strong class="text-dark" id="vd_request_id"></strong>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted">Blood Units Collected & Verified *</label>
                            <input type="number" name="units_donated" class="form-control form-control-lg fw-bold text-center" value="1" min="1" max="10" required>
                            <small class="text-muted">These units will be automatically added to your blood bank's inventory.</small>
                        </div>

                        <div class="alert alert-success border-0 rounded-4 p-3 small mb-0">
                            <i class="bi bi-check-circle-fill me-2 text-success"></i>
                            <strong>Official Verification:</strong> This will record an authorized donation in the donor's medical history, award <strong>+250 points</strong>, evaluate milestone gifts, and resolve/reduce the emergency deficit.
                        </div>
                    </div>
                    <div class="modal-footer border-top-0 bg-light rounded-bottom-4">
                        <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="verify_emergency_donation" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">
                            <i class="bi bi-patch-check-fill me-1"></i>Confirm & Add to Inventory
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle emergency responding donors table
        function toggleEmergencyDonors(reqId) {
            const row = document.getElementById('emergency_donors_row_' + reqId);
            if (row) {
                row.classList.toggle('d-none');
            }
        }

        // Open verify donation modal
        function openVerifyDonationModal(respId, donorName, bloodGroup, reqId) {
            document.getElementById('vd_response_id').value = respId || '';
            document.getElementById('vd_donor_name').innerText = donorName || 'Donor';
            document.getElementById('vd_blood_group').innerText = bloodGroup || '';
            document.getElementById('vd_request_id').innerText = '#' + (reqId || '');
            new bootstrap.Modal(document.getElementById('verifyDonationModal')).show();
        }
        // ── UI Section Toggling ────────────────────────────────────────────────
        function showSection(sectionId, element) {
            document.querySelectorAll('.content-section').forEach(sec => sec.classList.add('d-none-soft'));
            document.querySelectorAll('.nav-link-custom').forEach(nav => nav.classList.remove('active'));

            const targetSec = document.getElementById(sectionId);
            if (targetSec) targetSec.classList.remove('d-none-soft');

            if (element) {
                element.classList.add('active');
            } else {
                document.querySelectorAll('.nav-link-custom').forEach(nav => {
                    if (nav.getAttribute('onclick') && nav.getAttribute('onclick').includes(sectionId)) {
                        nav.classList.add('active');
                    }
                });
            }

            const titles = {
                'dashboard-section': 'Dashboard Overview',
                'inventory-section': 'Global Blood Bank Inventory',
                'emergency-section': 'Emergency Shortage Detection',
                'camps-section': 'Blood Camp Management',
                'update-section': 'Update Target Stock',
                'profile-section': 'Blood Bank Facility Profile',
                'expiry-section': 'Expiration Alerts',
                'requests-section': 'Pending Match Fulfillment Queue'
            };
            document.getElementById('headerTitle').innerText = titles[sectionId] || 'Dashboard Overview';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // ── Toggle expandable inventory bank card ──────────────────────────────
        function toggleInvCard(bodyId, btnId) {
            const body = document.getElementById(bodyId);
            const btn  = document.getElementById(btnId);
            if (body) body.classList.toggle('open');
            if (btn)  btn.classList.toggle('rotated');
        }

        // ── Filter global inventory blood banks ────────────────────────────────
        function filterBloodBanks() {
            const query = document.getElementById('bankSearchInput').value.toLowerCase();
            document.querySelectorAll('.bank-item').forEach(item => {
                const name = (item.getAttribute('data-name') || '').toLowerCase();
                const loc  = (item.getAttribute('data-loc')  || '').toLowerCase();
                item.style.display = (name.includes(query) || loc.includes(query)) ? '' : 'none';
            });
        }

        // ── Filter Blood Camps by status ───────────────────────────────────────
        function filterCamps(status, btn) {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.camp-card-col').forEach(col => {
                const colStatus = col.getAttribute('data-status');
                col.style.display = (status === 'All' || colStatus === status) ? '' : 'none';
            });
        }

        // ── Open Edit Camp Modal ───────────────────────────────────────────────
        function openEditCampModal(camp) {
            document.getElementById('edit_camp_id').value          = camp.camp_id || '';
            document.getElementById('edit_camp_name').value        = camp.display_name || camp.name || '';
            document.getElementById('edit_location').value         = camp.location || '';
            document.getElementById('edit_venue').value            = camp.venue || '';
            document.getElementById('edit_date').value             = camp.date || '';
            document.getElementById('edit_start_time').value       = camp.start_time || '09:00';
            document.getElementById('edit_end_time').value         = camp.end_time   || '16:00';
            document.getElementById('edit_contact').value          = camp.contact || '';
            document.getElementById('edit_expected_donors').value  = camp.expected_donors || 50;
            document.getElementById('edit_description').value      = camp.description || '';
            document.getElementById('edit_status').value           = camp.status || 'Upcoming';
            new bootstrap.Modal(document.getElementById('editCampModal')).show();
        }

        // ── Open View Camp Details Modal ───────────────────────────────────────
        let _currentViewCampId = null;
        let _currentViewCampName = '';

        function openViewCampModal(camp) {
            _currentViewCampId   = camp.camp_id;
            _currentViewCampName = camp.display_name || camp.name || 'Blood Donation Camp';

            document.getElementById('view_camp_name').innerText    = _currentViewCampName;
            document.getElementById('view_location').innerText     = camp.location  || 'Not Specified';
            document.getElementById('view_venue').innerText        = camp.venue     || 'Not Specified';
            document.getElementById('view_date').innerText         = camp.date      || '—';
            document.getElementById('view_time').innerText         = (camp.start_time || '10:00') + ' – ' + (camp.end_time || '15:00');
            document.getElementById('view_contact').innerText      = camp.contact   || 'Not Provided';
            document.getElementById('view_donors').innerText       = (camp.registered_count || 0) + ' interested (Expected: ' + (camp.expected_donors || 0) + ')';
            document.getElementById('view_description').innerText  = camp.description || 'No additional description provided.';

            const sc = camp.status === 'Upcoming' ? 'success' : (camp.status === 'Completed' ? 'primary' : 'danger');
            document.getElementById('view_camp_status_badge').innerHTML =
                `<span class="badge bg-${sc}-subtle text-${sc} border border-${sc}-subtle rounded-pill px-3 py-1 fw-bold">${camp.status}</span>`;

            // Wire "View Donors" button inside details modal
            document.getElementById('view_camp_donors_btn').onclick = function () {
                bootstrap.Modal.getInstance(document.getElementById('viewCampModal')).hide();
                setTimeout(() => openCampDonors(_currentViewCampId, _currentViewCampName), 350);
            };

            new bootstrap.Modal(document.getElementById('viewCampModal')).show();
        }

        // ── Interested Donors Modal ────────────────────────────────────────────
        let _allDonorRows = [];   // full data for client-side filtering

        function openCampDonors(campId, campName) {
            // Reset UI
            document.getElementById('donors_loading').classList.remove('d-none');
            document.getElementById('donors_error').classList.add('d-none');
            document.getElementById('donors_content').classList.add('d-none');
            document.getElementById('donor_search_input').value = '';
            document.getElementById('donor_bg_filter').value    = '';
            document.getElementById('donors_modal_camp_info').innerText = campName || '';

            new bootstrap.Modal(document.getElementById('campDonorsModal')).show();

            fetch(`get_camp_donors.php?camp_id=${encodeURIComponent(campId)}`)
                .then(r => r.json())
                .then(data => {
                    document.getElementById('donors_loading').classList.add('d-none');

                    if (data.error) {
                        document.getElementById('donors_error_msg').innerText = data.error;
                        document.getElementById('donors_error').classList.remove('d-none');
                        return;
                    }

                    renderDonorsModal(data);
                })
                .catch(() => {
                    document.getElementById('donors_loading').classList.add('d-none');
                    document.getElementById('donors_error_msg').innerText = 'Network error. Please try again.';
                    document.getElementById('donors_error').classList.remove('d-none');
                });
        }

        function renderDonorsModal(data) {
            const camp    = data.camp;
            const donors  = data.donors;
            const total   = data.total;
            const expected = data.expected;

            // Camp summary
            document.getElementById('dm_camp_name').innerText = camp.camp_name || 'Blood Donation Camp';
            const loc = [camp.location, camp.venue].filter(Boolean).join(', ');
            const campDate = camp.date ? new Date(camp.date).toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'}) : '';
            const campTime = [(camp.start_time||'').slice(0,5), (camp.end_time||'').slice(0,5)].filter(Boolean).join(' – ');
            document.getElementById('dm_camp_meta').innerText = [loc ? '📍 '+loc : '', campDate ? '📅 '+campDate : '', campTime ? '⏰ '+campTime : ''].filter(Boolean).join('   ');

            // Animated count
            document.getElementById('dm_count_display').innerText = total + ' / ' + expected;

            // Blood Group Summary
            const bgSumEl = document.getElementById('dm_bg_summary');
            bgSumEl.innerHTML = '';
            const bgColors = {
                'A+':'danger','A-':'danger','B+':'primary','B-':'primary',
                'AB+':'success','AB-':'success','O+':'warning','O-':'warning'
            };
            (data.groups_order || Object.keys(data.bg_summary)).forEach(bg => {
                const count = data.bg_summary[bg] || 0;
                const col   = bgColors[bg] || 'secondary';
                const opacity = count > 0 ? '' : ' opacity-40';
                bgSumEl.innerHTML += `
                    <span class="badge bg-${col}${count > 0 ? '' : '-subtle text-' + col} rounded-pill px-3 py-2${opacity}" style="font-size:0.85rem;">
                        <strong>${bg}</strong> <span class="ms-1 badge bg-white text-dark">${count}</span>
                    </span>`;
            });

            // Store for filtering
            _allDonorRows = donors;

            // Render table
            renderDonorTable(donors);

            // Show content
            document.getElementById('donors_content').classList.remove('d-none');
        }

        function renderDonorTable(donors) {
            const tbody     = document.getElementById('donors_table_body');
            const emptyEl   = document.getElementById('donors_empty');
            const tableEl   = document.getElementById('donors_table');
            const noFilter  = document.getElementById('donors_no_filter_results');

            if (!donors || donors.length === 0) {
                tbody.innerHTML = '';
                tableEl.classList.add('d-none');
                emptyEl.classList.remove('d-none');
                noFilter.classList.add('d-none');
                return;
            }

            emptyEl.classList.add('d-none');
            tableEl.classList.remove('d-none');
            noFilter.classList.add('d-none');

            const bgColors = {
                'A+':'danger','A-':'danger','B+':'primary','B-':'primary',
                'AB+':'success','AB-':'success','O+':'warning','O-':'warning'
            };

            tbody.innerHTML = donors.map((d, i) => {
                const bg    = d.blood_group || '—';
                const bgCol = bgColors[bg] || 'secondary';
                const isVerified = (d.verified || '').toLowerCase() === 'yes';
                const donorTypeFmt = d.donor_type ? (d.donor_type.charAt(0).toUpperCase() + d.donor_type.slice(1)) : '—';
                const statusFmt = d.interest_status || 'Interested';

                return `<tr class="donor-row align-middle" data-name="${(d.name||'').toLowerCase()}" data-bg="${bg}">
                    <td class="fw-bold text-muted">${i + 1}</td>
                    <td>
                        <div class="fw-bold text-dark">${escHtml(d.name || '—')}</div>
                        <small class="text-muted">${escHtml(d.availability || '')}</small>
                    </td>
                    <td>
                        <span class="badge bg-${bgCol} rounded-pill px-3 py-1 fw-bold fs-7">${escHtml(bg)}</span>
                    </td>
                    <td class="text-muted">${escHtml(d.contact_masked || '****')}</td>
                    <td><span class="badge bg-light text-dark border rounded-pill px-2">${escHtml(donorTypeFmt)}</span></td>
                    <td>
                        ${isVerified
                            ? '<span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1"><i class="bi bi-patch-check-fill me-1"></i>Verified</span>'
                            : '<span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-2 py-1">Pending</span>'}
                    </td>
                    <td><span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2 py-1">❤️ ${escHtml(statusFmt)}</span></td>
                    <td class="text-muted small">${escHtml(d.interested_at_fmt || '—')}</td>
                </tr>`;
            }).join('');
        }

        function filterDonorRows() {
            const q  = (document.getElementById('donor_search_input').value || '').toLowerCase().trim();
            const bg = (document.getElementById('donor_bg_filter').value   || '').toLowerCase().trim();

            const filtered = _allDonorRows.filter(d => {
                const matchQ  = !q  || (d.name||'').toLowerCase().includes(q) || (d.blood_group||'').toLowerCase().includes(q);
                const matchBg = !bg || (d.blood_group||'').toLowerCase() === bg;
                return matchQ && matchBg;
            });

            renderDonorTable(filtered);

            // Show "no filter results" only if there ARE donors but filter yields zero
            const noFilter = document.getElementById('donors_no_filter_results');
            if (filtered.length === 0 && _allDonorRows.length > 0) {
                noFilter.classList.remove('d-none');
                document.getElementById('donors_table').classList.add('d-none');
                document.getElementById('donors_empty').classList.add('d-none');
            } else {
                noFilter.classList.add('d-none');
            }
        }

        function escHtml(str) {
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    </script>

    <style>
        /* Donor count badge hover */
        .donor-count-btn { transition: all 0.18s; }
        .donor-count-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(220,53,69,0.25); }

        /* Donor table row hover */
        #donors_table_body tr { transition: background 0.15s; }

        /* Blood group badge opacity for zero-count */
        .opacity-40 { opacity: 0.35; }

        /* Modal header rounded */
        .rounded-top-4 { border-radius: 1rem 1rem 0 0 !important; }
        .rounded-bottom-4 { border-radius: 0 0 1rem 1rem !important; }
    </style>
    <?php require 'language_switcher.php'; ?>
</body>
</html>

