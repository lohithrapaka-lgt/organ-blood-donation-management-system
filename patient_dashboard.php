<?php
ini_set('session.cookie_lifetime', 86400); // 1 day
ini_set('session.gc_maxlifetime', 86400);
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['role'] !== 'patient') {
    header("Location: login.php");
    exit();
}
$host = 'localhost';
$dbname = 'organ_blood_donation';
$username = 'root';
$password = '';
require_once 'priority_calc.php';
require_once 'emergency_alerts.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure emergency schema tables and columns are up to date
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

    // Handle Unified Form Submission (Blood Requests Only)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
        $name = $_POST['name'];
        $age = (int) $_POST['age'];
        $blood_group = $_POST['blood_group'];
        $condition = $_POST['condition'];
        $request_type = $_POST['request_type'] ?? 'blood';
        $patient_id = $_SESSION['ref_id'];
        
        $request_date = date('Y-m-d H:i:s');

        // Patients are not permitted to independently initiate organ requests
        if ($request_type === 'organ') {
            $_SESSION['error'] = "<div class='alert alert-warning alert-dismissible fade show' role='alert'><i class='bi bi-info-circle-fill me-2'></i>Direct organ requests by patients are disabled. Organ transplant requirements must be initiated by an authorized hospital following clinical evaluation.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

        $pdo->beginTransaction();
        try {
            if ($request_type === 'blood') {
                $units_needed = isset($_POST['units_needed']) ? (int)$_POST['units_needed'] : 1;
                $priority_score = calculatePriority($age, $condition, 'blood', '', $request_date);

                // Update patients table with latest request info and priority
                $stmtPat = $pdo->prepare("UPDATE patients SET request_type='blood', organ_needed=NULL, `condition`=?, request_date=?, status='pending', priority_score=? WHERE patient_id=?");
                $stmtPat->execute([$condition, $request_date, $priority_score, $patient_id]);

                // Insert blood request with all required fields
                $stmt = $pdo->prepare("INSERT INTO blood_requests (patient_id, patient_name, age, blood_group, priority_score, units_needed, status, request_date, emergency_alert_status) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, 'none')");
                $stmt->execute([$patient_id, $name, $age, $blood_group, $priority_score, $units_needed, $request_date]);
                $newBloodReqId = (int)$pdo->lastInsertId();

                $pdo->commit();

                // Trigger automatic shortage detection & emergency donor alerts if stock is insufficient
                $alertStats = detectAndTriggerEmergencyAlerts($pdo, $newBloodReqId);

                if ($alertStats['shortages_detected'] > 0) {
                    $message = "<div class='alert alert-warning alert-dismissible fade show' role='alert'><i class='bi bi-broadcast me-2 fs-5 text-danger pulse-glow'></i><strong>Blood Request Logged — Emergency Alert Activated!</strong> System stock is currently insufficient. {$alertStats['donors_notified']} compatible, verified, available donors have been automatically notified. <button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                } else {
                    $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='bi bi-check-circle-fill me-2'></i>Blood Request successfully logged for matching! Sufficient stock available in network. <button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
                }
                $_SESSION['success'] = $message;
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "<div class='alert alert-danger alert-dismissible fade show' role='alert'>Error: " . htmlspecialchars($e->getMessage()) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Handle Profile Update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
        $name = $_POST['name'];
        $age = $_POST['age'];
        $blood_group = $_POST['blood_group'];
        $contact = $_POST['contact'];
        $location = $_POST['location'];
        $patient_id = $_SESSION['ref_id'];

        // Basic validation for contact: allow only numbers
        if (!empty($contact) && !is_numeric($contact)) {
            $_SESSION['error'] = "<div class='alert alert-danger'>Contact number must contain only digits.</div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

        $stmt = $pdo->prepare("UPDATE patients SET name = ?, age = ?, blood_group = ?, contact = ?, location = ? WHERE patient_id = ?");
        $stmt->execute([$name, $age, $blood_group, $contact, $location, $patient_id]);

        $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'>
                        <i class='bi bi-check-circle-fill me-2'></i>Profile updated successfully!
                        <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
                    </div>";
        $_SESSION['success'] = $message;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Direct organ request from patient is disabled per medical protocol
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_organ'])) {
        $_SESSION['error'] = "<div class='alert alert-warning alert-dismissible fade show' role='alert'><i class='bi bi-info-circle-fill me-2'></i>Direct organ requests by patients are disabled. Organ transplant requirements must be initiated by an authorized hospital following clinical evaluation.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Fetch Profile Data
    $stmtProfile = $pdo->prepare("SELECT name, age, blood_group, contact, location FROM patients WHERE patient_id = ?");
    $stmtProfile->execute([$_SESSION['ref_id']]);
    $userProfile = $stmtProfile->fetch(PDO::FETCH_ASSOC);

    // STEP 5: Ensure name exists (fallback if empty)
    if (empty($userProfile['name']) || $userProfile['name'] === 'Unknown') {
        $newName = 'Your Name';
        $updName = $pdo->prepare("UPDATE patients SET name = ? WHERE patient_id = ?");
        $updName->execute([$newName, $_SESSION['ref_id']]);
        $userProfile['name'] = $newName; // Sync for current request
    }

    // Fetch Blood Availability — grouped by bank (Step 2)
    $bloodQuery = "SELECT b.bank_id, b.name, b.location, b.contact, i.blood_group, i.units_available
                   FROM blood_inventory i
                   JOIN blood_banks b ON i.bank_id = b.bank_id
                   ORDER BY b.bank_id, i.blood_group";
    $bloodAvailability = $pdo->query($bloodQuery)->fetchAll(PDO::FETCH_ASSOC);

    // Group blood data by bank
    $groupedBanks = [];
    foreach ($bloodAvailability as $row) {
        $bid = $row['bank_id'];
        if (!isset($groupedBanks[$bid])) {
            $groupedBanks[$bid] = [
                'name' => $row['name'],
                'location' => $row['location'],
                'contact' => $row['contact'],
                'bloods' => []
            ];
        }
        $groupedBanks[$bid]['bloods'][] = [
            'group' => $row['blood_group'],
            'units' => (int) $row['units_available']
        ];
    }

    // Fetch Organ Availability — grouped by hospital (Step 4)
    $organQuery = "SELECT h.hospital_id, h.name AS hospital_name, h.location, h.contact,
                          oi.organ_type, oi.units_available, oi.hospital_id AS hid
                   FROM organ_inventory oi
                   JOIN hospitals h ON oi.hospital_id = h.hospital_id
                   ORDER BY h.hospital_id, oi.organ_type";
    $organAvailability = $pdo->query($organQuery)->fetchAll(PDO::FETCH_ASSOC);

    // Group organ data by hospital
    $groupedHospitals = [];
    foreach ($organAvailability as $row) {
        $hid = $row['hospital_id'];
        if (!isset($groupedHospitals[$hid])) {
            $groupedHospitals[$hid] = [
                'name' => $row['hospital_name'],
                'location' => $row['location'],
                'contact' => $row['contact'],
                'organs' => []
            ];
        }
        $groupedHospitals[$hid]['organs'][] = [
            'type' => $row['organ_type'],
            'units' => (int) $row['units_available'],
            'hid' => $row['hid']
        ];
    }

    // Fetch All Approved Hospitals for the Unit Dropdown
    $stmtAllHospitals = $pdo->query("SELECT hospital_id, name, location FROM hospitals WHERE status = 'approved' ORDER BY name ASC");
    $hospitalDropdownList = $stmtAllHospitals->fetchAll(PDO::FETCH_ASSOC);



    // Fetch Summary Stats — scoped to the logged-in patient (blood + organ requests combined)
    $pid = $_SESSION['ref_id'];

    $stmtTotal = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM blood_requests WHERE patient_id = ?) +
            (SELECT COUNT(*) FROM organ_requests  WHERE patient_id = ?) AS total
    ");
    $stmtTotal->execute([$pid, $pid]);
    $totalReq = (int) $stmtTotal->fetchColumn();

    $stmtApproved = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM blood_requests WHERE patient_id = ? AND status = 'approved') +
            (SELECT COUNT(*) FROM organ_requests  WHERE patient_id = ? AND status = 'approved') AS total
    ");
    $stmtApproved->execute([$pid, $pid]);
    $approvedReq = (int) $stmtApproved->fetchColumn();

    $stmtPending = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM blood_requests WHERE patient_id = ? AND status = 'pending') +
            (SELECT COUNT(*) FROM organ_requests  WHERE patient_id = ? AND status = 'pending') AS total
    ");
    $stmtPending->execute([$pid, $pid]);
    $pendingReq = (int) $stmtPending->fetchColumn();

    $stmtFulfilled = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM blood_requests WHERE patient_id = ? AND status = 'fulfilled') +
            (SELECT COUNT(*) FROM organ_requests  WHERE patient_id = ? AND status = 'fulfilled') AS total
    ");
    $stmtFulfilled->execute([$pid, $pid]);
    $fulfilledReq = (int) $stmtFulfilled->fetchColumn();


    // Step 1: Fetch Data From Correct Table for Recent Requests
    $stmtPatients = $pdo->prepare("
        SELECT br.patient_name, br.age, br.blood_group, br.units_needed, br.status, br.priority_score
        FROM blood_requests br
        WHERE br.patient_id = ?
        ORDER BY br.request_id DESC
    ");
    $stmtPatients->execute([$_SESSION['ref_id']]);
    $patients = $stmtPatients->fetchAll(PDO::FETCH_ASSOC);

    // Step 7: Fetch latest request status
    $stmtMatch = $pdo->prepare("
        SELECT 'blood_bank' as match_type, br.request_id, br.blood_group, br.status, br.units_needed, br.priority_score, br.request_date,
               b.name AS bank_name, b.location AS bank_location
        FROM blood_requests br
        LEFT JOIN blood_banks b ON br.bank_id = b.bank_id
        WHERE br.patient_id = ? 
        ORDER BY br.request_id DESC LIMIT 1
    ");
    $stmtMatch->execute([$_SESSION['ref_id']]);
    $matchedDetails = $stmtMatch->fetch(PDO::FETCH_ASSOC);

    // Also support donor matches if applicable to this user (from previous workflow)
    if (!$matchedDetails) {
        $stmtDonor = $pdo->prepare("
            SELECT 'donor' as match_type, d.blood_group, dr.response as status 
            FROM donor_responses dr 
            JOIN donors d ON dr.donor_id = d.donor_id 
            WHERE dr.patient_id = ?
            LIMIT 1
        ");
        $stmtDonor->execute([$_SESSION['ref_id']]);
        $matchedDetails = $stmtDonor->fetch(PDO::FETCH_ASSOC);
    }

    // Step 6: Organ requests – fetched SEPARATELY so both cards can show simultaneously
    $stmtOrganDet = $pdo->prepare("
        SELECT 'hospital' as match_type, o.request_id, o.patient_id, o.organ_type, o.status, o.priority_score,
               h.name AS hospital_name, h.location, h.contact
        FROM organ_requests o
        JOIN hospitals h ON o.hospital_id = h.hospital_id
        WHERE o.patient_id = ?
        ORDER BY o.request_id DESC LIMIT 1
    ");
    $stmtOrganDet->execute([$_SESSION['ref_id']]);
    $organDetails = $stmtOrganDet->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Connection Error: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Dashboard - MediMatch</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fe;
            color: #333;
        }

        /* Sidebar Styling */
        .sidebar-wrapper {
            background-color: #ffffff;
            box-shadow: 2px 0 15px rgba(0, 0, 0, 0.03);
            min-height: 100vh;
            padding-top: 2rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-link-custom {
            color: #525f7f;
            font-weight: 500;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            transition: all 0.3s;
            cursor: pointer;
            display: flex;
            align-items: center;
        }

        .nav-link-custom:hover,
        .nav-link-custom.active {
            background-color: #f8f9fe;
            color: #ff0844;
            transform: translateX(5px);
        }

        .content-section {
            display: none;
            animation: fadeIn 0.4s ease-in-out;
        }

        .content-section.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card-custom {
            background: white;
            border: none;
            border-radius: 20px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.05);
            padding: 2rem;
        }
    </style>
</head>

<body>

    <div class="row g-0">

        <!-- Left Sidebar Navigation -->
        <div class="col-md-3 col-lg-2 sidebar-wrapper d-none d-md-block">
            <div class="text-center px-3 mb-5">
                <h3 class="fw-bold text-dark"><i class="bi bi-heart-pulse-fill text-danger me-2"></i>MediMatch</h3>
                <span class="badge bg-light text-secondary rounded-pill px-3 shadow-sm border" data-i18n="patientPortal" data-i18n-english="Patient Portal">Patient Portal</span>
            </div>

            <div class="px-3">
                <div class="nav-link-custom active" onclick="showSection('dashboard-section', this)">
                    <i class="bi bi-grid-fill me-3 fs-5"></i> <span data-i18n="dashboard" data-i18n-english="Dashboard">Dashboard</span>
                </div>
                <div class="nav-link-custom" onclick="showSection('profile-section', this)">
                    <i class="bi bi-person-fill me-3 fs-5"></i> <span data-i18n="myProfile" data-i18n-english="My Profile">My Profile</span>
                </div>
                <div class="nav-link-custom" onclick="showSection('request-section', this)">
                    <i class="bi bi-droplet-half me-3 fs-5"></i> Submit Blood Request
                </div>
                <div class="nav-link-custom" onclick="showSection('blood-section', this)">
                    <i class="bi bi-droplet-fill me-3 fs-5"></i> Blood Details
                </div>
                <div class="nav-link-custom" onclick="showSection('organ-section', this)">
                    <i class="bi bi-heart-pulse me-3 fs-5"></i> Organ Details
                </div>


                <hr class="my-4 text-muted">

                <a href="logout.php"
                    class="nav-link-custom text-danger text-decoration-none border border-danger border-opacity-25 rounded bg-danger bg-opacity-10 mt-auto">
                    <i class="bi bi-box-arrow-right me-3 fs-5"></i> <span data-i18n="logout" data-i18n-english="Logout">Logout</span>
                </a>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="col-md-9 col-lg-10 bg-light" style="min-height: 100vh;">

            <!-- Global Top Header -->
            <header class="gradient-header mb-4" style="padding: 2.5rem; border-radius: 0 0 30px 30px;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="fw-bold mb-1"><span id="headerTitle">Overview Dashboard</span></h2>
                        <p class="lead mb-0 opacity-75 fs-6">Manage tracking data seamlessly.</p>
                    </div>
                </div>
            </header>

            <div class="container px-4 pb-5">


                <?php if (!empty($message))
                    echo $message; ?>

                <!-- DASHBOARD SECTION -->
                <div id="dashboard-section" class="content-section active">
                    <!-- Profile Summary Card -->
                    <div class="card-custom mb-4 border-start border-4 border-primary">
                        <div class="row align-items-center">
                            <div class="col-md-auto mb-3 mb-md-0">
                                <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center shadow-sm"
                                    style="width: 70px; height: 70px;">
                                    <i class="bi bi-person-badge fs-2"></i>
                                </div>
                            </div>
                            <div class="col-md">
                                <h4 class="fw-bold mb-1 text-dark">
                                    <?php echo htmlspecialchars($userProfile['name'] ?? 'Guest'); ?>
                                </h4>
                                <div class="d-flex flex-wrap gap-3 mt-2">
                                    <span class="badge bg-danger rounded-pill px-3 shadow-sm"><i
                                            class="bi bi-droplet-fill me-1"></i><?php echo htmlspecialchars($userProfile['blood_group'] ?? 'N/A'); ?></span>
                                    <span class="text-muted small"><i
                                            class="bi bi-geo-alt-fill text-primary me-1"></i><?php echo htmlspecialchars($userProfile['location'] ?: 'Not Provided'); ?></span>
                                    <span class="text-muted small"><i
                                            class="bi bi-telephone-fill text-primary me-1"></i><?php echo htmlspecialchars($userProfile['contact'] ?: 'Not Provided'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Summary Cards -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-3 col-sm-6">
                            <div class="stat-card stat-total d-flex flex-column justify-content-center">
                                <h6 class="text-uppercase text-muted fw-bold mb-1">Total Requests</h6>
                                <h2 class="fw-bold mb-0 text-dark"><?php echo $totalReq; ?></h2>
                                <i class="bi bi-card-list stat-icon text-primary"></i>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="stat-card stat-approved d-flex flex-column justify-content-center">
                                <h6 class="text-uppercase text-muted fw-bold mb-1">Approved</h6>
                                <h2 class="fw-bold mb-0 text-dark"><?php echo $approvedReq; ?></h2>
                                <i class="bi bi-check-circle stat-icon text-info"></i>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="stat-card stat-pending d-flex flex-column justify-content-center">
                                <h6 class="text-uppercase text-muted fw-bold mb-1">Pending</h6>
                                <h2 class="fw-bold mb-0 text-dark"><?php echo $pendingReq; ?></h2>
                                <i class="bi bi-hourglass-split stat-icon text-warning"></i>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="stat-card stat-fulfilled d-flex flex-column justify-content-center">
                                <h6 class="text-uppercase text-muted fw-bold mb-1">Fulfilled</h6>
                                <h2 class="fw-bold mb-0 text-dark"><?php echo $fulfilledReq; ?></h2>
                                <i class="bi bi-heart-fill stat-icon text-success"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Matched Donor/Request Section -->
                    <?php if ($matchedDetails): ?>
                        <?php if ($matchedDetails['status'] === 'fulfilled' && ($matchedDetails['match_type'] ?? '') === 'blood_bank'): ?>
                            <?php
                            $reqId = (int)($matchedDetails['request_id'] ?? 0);
                            $secToken = hash_hmac('sha256', 'blood_receipt_' . $reqId, 'MediMatch_Secure_Receipt_Key_2026');
                            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
                            $host = $_SERVER['HTTP_HOST'];
                            $scriptDir = dirname($_SERVER['PHP_SELF']);
                            $scriptDir = rtrim(str_replace('\\', '/', $scriptDir), '/');
                            $verifyUrl = "$protocol://$host$scriptDir/verify_receipt.php?request_id=$reqId&token=$secToken";
                            ?>
                            <div class="card-custom mb-4 border-2 border-success shadow-sm" style="background: linear-gradient(135deg, #f0fff4 0%, #ffffff 100%);">
                                <!-- Collapsed Header & Summary Row -->
                                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-success bg-opacity-10 text-success rounded-circle p-3 me-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px; flex-shrink: 0;">
                                            <i class="bi bi-patch-check-fill fs-2"></i>
                                        </div>
                                        <div>
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <h4 class="fw-bold text-success mb-0">Blood Request Fulfilled</h4>
                                                <span class="badge bg-success rounded-pill px-3 py-1 text-uppercase">
                                                    <i class="bi bi-check-circle-fill me-1"></i>Fulfilled
                                                </span>
                                            </div>
                                            <div class="text-muted small">
                                                <span><strong>Patient:</strong> <?php echo htmlspecialchars($userProfile['name'] ?? 'Guest'); ?></span>
                                                <span class="mx-2">·</span>
                                                <span><strong>Group:</strong> <span class="badge bg-danger rounded-pill px-2 py-1"><?php echo htmlspecialchars($matchedDetails['blood_group'] ?? 'N/A'); ?></span></span>
                                                <span class="mx-2">·</span>
                                                <span><strong>Units:</strong> <?php echo htmlspecialchars($matchedDetails['units_needed'] ?? 1); ?> unit(s)</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="ms-md-auto">
                                        <button type="button" id="toggleReceiptBtn" onclick="toggleReceiptPanel()" class="btn btn-success rounded-pill px-4 py-2 fw-bold shadow-sm">
                                            <i class="bi bi-receipt me-2"></i>View Receipt <i class="bi bi-chevron-down ms-1" id="receiptChevron"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Expandable Receipt Panel -->
                                <div id="fulfilledReceiptPanel" class="mt-4 pt-4 border-top border-success border-opacity-25" style="display: none;">
                                    <div class="d-flex align-items-center justify-content-between mb-4">
                                        <h5 class="fw-bold text-success mb-0">
                                            <i class="bi bi-patch-check-fill me-2"></i>Blood Request Successfully Fulfilled
                                        </h5>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 fw-bold">
                                            <i class="bi bi-qr-code me-1"></i>Digital Receipt
                                        </span>
                                    </div>

                                    <div class="row align-items-center g-4">
                                        <!-- QR Code Container -->
                                        <div class="col-md-5 col-lg-4 text-center">
                                            <div class="p-3 bg-white border border-success border-opacity-25 rounded-4 shadow-sm d-inline-block qr-container-wrapper">
                                                <div id="receiptQrCode" class="d-flex justify-content-center align-items-center p-2 mb-2"></div>
                                                <span class="badge bg-light text-dark border fw-bold px-3 py-1">
                                                    Request ID: Req #<?php echo htmlspecialchars($reqId); ?>
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Receipt Details & Buttons -->
                                        <div class="col-md-7 col-lg-8">
                                            <div class="row g-3 mb-3">
                                                <div class="col-sm-6">
                                                    <div class="p-3 bg-white rounded-3 border">
                                                        <small class="text-muted text-uppercase fw-bold d-block mb-1">Patient Name</small>
                                                        <span class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($userProfile['name'] ?? 'Guest'); ?></span>
                                                    </div>
                                                </div>
                                                <div class="col-sm-6">
                                                    <div class="p-3 bg-white rounded-3 border">
                                                        <small class="text-muted text-uppercase fw-bold d-block mb-1">Blood Group & Units</small>
                                                        <span class="badge bg-danger fs-6 px-3 py-1 me-1"><?php echo htmlspecialchars($matchedDetails['blood_group'] ?? 'N/A'); ?></span>
                                                        <span class="fw-bold text-dark small"><?php echo htmlspecialchars($matchedDetails['units_needed'] ?? 1); ?> unit(s)</span>
                                                    </div>
                                                </div>
                                                <div class="col-sm-6">
                                                    <div class="p-3 bg-white rounded-3 border">
                                                        <small class="text-muted text-uppercase fw-bold d-block mb-1">Fulfillment Blood Bank</small>
                                                        <span class="fw-bold text-dark small"><?php echo htmlspecialchars($matchedDetails['bank_name'] ?: 'Network Blood Bank'); ?></span>
                                                    </div>
                                                </div>
                                                <div class="col-sm-6">
                                                    <div class="p-3 bg-white rounded-3 border">
                                                        <small class="text-muted text-uppercase fw-bold d-block mb-1">Verification Security</small>
                                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1 small">
                                                            <i class="bi bi-shield-lock-fill me-1"></i>HMAC Signed URL
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="d-flex flex-wrap gap-2">
                                                <button type="button" onclick="downloadReceiptQR()" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">
                                                    <i class="bi bi-download me-2"></i>Download QR
                                                </button>
                                                <a href="verify_receipt.php?request_id=<?php echo $reqId; ?>&token=<?php echo $secToken; ?>" target="_blank" class="btn btn-outline-success rounded-pill px-4 fw-bold">
                                                    <i class="bi bi-printer me-2"></i>Print Receipt / Verification
                                                </a>
                                            </div>
                                            <form method="POST" action="submit_feedback.php" class="mt-4 pt-3 border-top">
                                                <input type="hidden" name="submit_feedback" value="1">
                                                <input type="hidden" name="request_id" value="<?php echo $reqId; ?>">
                                                <input type="hidden" name="redirect" value="patient_dashboard.php">
                                                <div class="row g-2 align-items-end">
                                                    <div class="col-md-3">
                                                        <label class="form-label small fw-bold">Rate this blood bank</label>
                                                        <select name="rating" class="form-select form-select-sm" required>
                                                            <option value="">Rating</option>
                                                            <option value="5">5 - Excellent</option>
                                                            <option value="4">4 - Good</option>
                                                            <option value="3">3 - Average</option>
                                                            <option value="2">2 - Poor</option>
                                                            <option value="1">1 - Very poor</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label small fw-bold">Feedback (optional)</label>
                                                        <input type="text" name="feedback" class="form-control form-control-sm" maxlength="1000" placeholder="Share your experience">
                                                    </div>
                                                    <div class="col-md-3">
                                                        <button type="submit" class="btn btn-outline-success btn-sm w-100"><i class="bi bi-star-fill me-1"></i>Submit Feedback</button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="card-custom mb-4"
                                style="background: linear-gradient(135deg, <?php echo $matchedDetails['status'] === 'fulfilled' ? '#f0fff4' : '#fff5f5'; ?> 0%, #ffffff 100%); border: 1px solid <?php echo $matchedDetails['status'] === 'fulfilled' ? '#22c55e22' : '#ff084422'; ?>;">
                                <div class="d-flex align-items-center mb-3">
                                    <div
                                        class="<?php echo $matchedDetails['status'] === 'fulfilled' ? 'bg-success' : 'bg-danger'; ?> bg-opacity-10 rounded-circle p-3 me-3">
                                        <i
                                            class="bi <?php echo $matchedDetails['status'] === 'fulfilled' ? 'bi-check-all text-success' : 'bi-heart-pulse-fill text-danger'; ?> fs-3"></i>
                                    </div>
                                    <div>
                                        <h4 class="fw-bold text-dark mb-0">
                                            <?php
                                            if ($matchedDetails['status'] === 'fulfilled')
                                                echo 'Request Fulfilled!';
                                            elseif ($matchedDetails['match_type'] === 'blood_bank')
                                                echo 'Blood Request Pending';
                                            elseif ($matchedDetails['match_type'] === 'hospital')
                                                echo 'Organ Request Submitted';
                                            else
                                                echo 'Matched Donor for You!';
                                            ?>
                                        </h4>
                                        <p class="text-muted mb-0 small text-uppercase fw-bold tracking-wider">
                                            <?php
                                            if ($matchedDetails['match_type'] === 'blood_bank')
                                                echo 'Blood Bank Processing';
                                            elseif ($matchedDetails['match_type'] === 'hospital')
                                                echo 'Hospital Organ Request';
                                            else
                                                echo 'Automated System Match';
                                            ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <div class="p-3 bg-white rounded-4 border shadow-sm text-center">
                                            <?php if (($matchedDetails['match_type'] ?? '') === 'hospital'): ?>
                                                <label class="text-muted small fw-bold text-uppercase d-block mb-1">Organ
                                                    Type</label>
                                                <span class="badge bg-primary fs-5 px-3 rounded-pill"><i
                                                        class="bi bi-heart-pulse me-1"></i><?php echo htmlspecialchars($matchedDetails['organ_type'] ?? 'N/A'); ?></span>
                                            <?php else: ?>
                                                <label class="text-muted small fw-bold text-uppercase d-block mb-1">Blood
                                                    Group</label>
                                                <span
                                                    class="badge bg-danger fs-5 px-3 rounded-pill"><?php echo htmlspecialchars($matchedDetails['blood_group'] ?? 'N/A'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="p-3 bg-white rounded-4 border shadow-sm text-center">
                                            <label class="text-muted small fw-bold text-uppercase d-block mb-1">Status</label>
                                            <?php
                                            $status = $matchedDetails['status'];
                                            $color = ($status === 'fulfilled' || $status === 'accepted') ? 'success' : (($status === 'pending') ? 'warning' : 'danger');
                                            $icon = ($status === 'fulfilled') ? 'check-all' : (($status === 'accepted') ? 'check-circle' : (($status === 'pending') ? 'hourglass-split' : 'x-circle'));
                                            ?>
                                            <span class="badge bg-<?php echo $color; ?> fs-6 px-3 rounded-pill text-uppercase">
                                                <i class="bi bi-<?php echo $icon; ?> me-1"></i>
                                                <?php echo $status === 'fulfilled' ? 'Request Fulfilled' : htmlspecialchars($status); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="p-3 bg-white rounded-4 border shadow-sm text-center">
                                            <label class="text-muted small fw-bold text-uppercase d-block mb-1">Next
                                                Step</label>
                                            <p class="mb-0 small fw-medium">
                                                <?php
                                                if ($status === 'fulfilled')
                                                    echo "Request fulfilled successfully.";
                                                elseif ($status === 'pending')
                                                    echo "Awaiting hospital/blood bank response...";
                                                elseif ($status === 'accepted')
                                                    echo "Contacting hospital for transport.";
                                                elseif ($status === 'rejected')
                                                    echo "Request rejected. Submit a new one.";
                                                else
                                                    echo "Searching for another match...";
                                                ?>
                                            </p>
                                            <a href="report.php?type=<?php echo htmlspecialchars($matchedDetails['match_type'] ?? ''); ?>"
                                                class="btn btn-sm btn-outline-primary rounded-pill mt-2 px-3">
                                                <i class="bi bi-file-earmark-text me-1"></i>View Report
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- ── ORGAN FULFILLMENT CARD (Step 2) ────────────────── -->
                    <?php if (!empty($organDetails)): ?>
                        <?php if (strtolower($organDetails['status']) === 'fulfilled'): ?>
                            <?php
                            $oReqId = (int)($organDetails['request_id'] ?? 0);
                            $oSecToken = hash_hmac('sha256', 'organ_receipt_' . $oReqId, 'MediMatch_Secure_Receipt_Key_2026');
                            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
                            $host = $_SERVER['HTTP_HOST'];
                            $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/');
                            $oVerifyUrl = "$protocol://$host$scriptDir/verify_receipt.php?type=organ&request_id=$oReqId&token=$oSecToken";
                            ?>
                            <div class="card-custom mb-4 border-2 border-success shadow-sm" style="background: linear-gradient(135deg, #f0fff4 0%, #ffffff 100%);">
                                <!-- Collapsed Header & Summary Row -->
                                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-success bg-opacity-10 text-success rounded-circle p-3 me-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px; flex-shrink: 0;">
                                            <i class="bi bi-patch-check-fill fs-2"></i>
                                        </div>
                                        <div>
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <h4 class="fw-bold text-success mb-0">Organ Request Fulfilled</h4>
                                                <span class="badge bg-success rounded-pill px-3 py-1 text-uppercase">
                                                    <i class="bi bi-check-circle-fill me-1"></i>Fulfilled
                                                </span>
                                            </div>
                                            <div class="text-muted small">
                                                <span><strong>Patient:</strong> <?php echo htmlspecialchars($userProfile['name'] ?? 'Guest'); ?></span>
                                                <span class="mx-2">·</span>
                                                <span><strong>Organ:</strong> <span class="badge bg-primary rounded-pill px-3 py-1"><i class="bi bi-heart-pulse me-1"></i><?php echo htmlspecialchars($organDetails['organ_type'] ?? 'N/A'); ?></span></span>
                                                <span class="mx-2">·</span>
                                                <span><strong>Hospital:</strong> <?php echo htmlspecialchars($organDetails['hospital_name'] ?? 'Network Hospital'); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="ms-md-auto">
                                        <button type="button" id="toggleOrganReceiptBtn" onclick="toggleOrganReceiptPanel()" class="btn btn-success rounded-pill px-4 py-2 fw-bold shadow-sm">
                                            <i class="bi bi-receipt me-2"></i>View Receipt <i class="bi bi-chevron-down ms-1" id="organReceiptChevron"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Expandable Organ Receipt Panel -->
                                <div id="fulfilledOrganReceiptPanel" class="mt-4 pt-4 border-top border-success border-opacity-25" style="display: none;">
                                    <div class="d-flex align-items-center justify-content-between mb-4">
                                        <h5 class="fw-bold text-success mb-0">
                                            <i class="bi bi-patch-check-fill me-2"></i>Organ Request Successfully Fulfilled
                                        </h5>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1 fw-bold">
                                            <i class="bi bi-qr-code me-1"></i>Digital Receipt
                                        </span>
                                    </div>

                                    <div class="row align-items-center g-4">
                                        <!-- QR Code Container -->
                                        <div class="col-md-5 col-lg-4 text-center">
                                            <div class="p-3 bg-white border border-success border-opacity-25 rounded-4 shadow-sm d-inline-block qr-container-wrapper">
                                                <div id="organReceiptQrCode" class="d-flex justify-content-center align-items-center p-2 mb-2"></div>
                                                <span class="badge bg-light text-dark border fw-bold px-3 py-1">
                                                    Request ID: Req #<?php echo htmlspecialchars($oReqId); ?>
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Receipt Details & Buttons -->
                                        <div class="col-md-7 col-lg-8">
                                            <div class="row g-3 mb-3">
                                                <div class="col-sm-6">
                                                    <div class="p-3 bg-white rounded-3 border">
                                                        <small class="text-muted text-uppercase fw-bold d-block mb-1">Patient Name</small>
                                                        <span class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($userProfile['name'] ?? 'Guest'); ?></span>
                                                    </div>
                                                </div>
                                                <div class="col-sm-6">
                                                    <div class="p-3 bg-white rounded-3 border">
                                                        <small class="text-muted text-uppercase fw-bold d-block mb-1">Organ Type</small>
                                                        <span class="badge bg-primary fs-6 px-3 py-1"><i class="bi bi-heart-pulse me-1"></i><?php echo htmlspecialchars($organDetails['organ_type'] ?? 'N/A'); ?></span>
                                                    </div>
                                                </div>
                                                <div class="col-sm-6">
                                                    <div class="p-3 bg-white rounded-3 border">
                                                        <small class="text-muted text-uppercase fw-bold d-block mb-1">Fulfillment Hospital</small>
                                                        <span class="fw-bold text-dark small"><?php echo htmlspecialchars($organDetails['hospital_name'] ?? 'Network Hospital'); ?></span>
                                                    </div>
                                                </div>
                                                <div class="col-sm-6">
                                                    <div class="p-3 bg-white rounded-3 border">
                                                        <small class="text-muted text-uppercase fw-bold d-block mb-1">Hospital Location</small>
                                                        <span class="fw-bold text-dark small"><i class="bi bi-geo-alt me-1 text-primary"></i><?php echo htmlspecialchars($organDetails['location'] ?? 'Network Facility'); ?></span>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="d-flex flex-wrap gap-2">
                                                <button type="button" onclick="downloadOrganReceiptQR()" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">
                                                    <i class="bi bi-download me-2"></i>Download QR
                                                </button>
                                                <a href="verify_receipt.php?type=organ&request_id=<?php echo $oReqId; ?>&token=<?php echo $oSecToken; ?>" target="_blank" class="btn btn-outline-success rounded-pill px-4 fw-bold">
                                                    <i class="bi bi-printer me-2"></i>Print Receipt / Verification
                                                </a>
                                            </div>
                                            <form method="POST" action="submit_feedback.php" class="mt-4 pt-3 border-top">
                                                <input type="hidden" name="submit_feedback" value="1">
                                                <input type="hidden" name="request_id" value="<?php echo $oReqId; ?>">
                                                <input type="hidden" name="redirect" value="patient_dashboard.php">
                                                <div class="row g-2 align-items-end">
                                                    <div class="col-md-3">
                                                        <label class="form-label small fw-bold">Rate this hospital</label>
                                                        <select name="rating" class="form-select form-select-sm" required>
                                                            <option value="">Rating</option>
                                                            <option value="5">5 - Excellent</option>
                                                            <option value="4">4 - Good</option>
                                                            <option value="3">3 - Average</option>
                                                            <option value="2">2 - Poor</option>
                                                            <option value="1">1 - Very poor</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label small fw-bold">Feedback (optional)</label>
                                                        <input type="text" name="feedback" class="form-control form-control-sm" maxlength="1000" placeholder="Share your experience">
                                                    </div>
                                                    <div class="col-md-3">
                                                        <button type="submit" class="btn btn-outline-success btn-sm w-100"><i class="bi bi-star-fill me-1"></i>Submit Feedback</button>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php
                            $oStatus = strtolower($organDetails['status']);
                            $oGradFrom = $oStatus === 'rejected' ? '#fff1f2' : '#f0f8ff';
                            $oBorder = $oStatus === 'rejected' ? '#ff0a2222' : '#0083b022';
                            $oIconClass = $oStatus === 'rejected' ? 'bi-x-circle-fill text-danger' : 'bi-hourglass-split text-info';
                            $oBgClass = $oStatus === 'rejected' ? 'bg-danger' : 'bg-info';
                            $oBadgeClass = $oStatus === 'rejected' ? 'bg-danger' : 'bg-warning text-dark';
                            $oTitle = $oStatus === 'rejected' ? 'Organ Request Rejected' : 'Organ Request Pending';
                            $oNextStep = $oStatus === 'rejected' ? 'Request rejected. Submit a new one.' : 'Awaiting hospital approval...';
                            ?>
                            <div class="card-custom mb-4"
                                style="background:linear-gradient(135deg,<?php echo $oGradFrom; ?> 0%,#ffffff 100%);border:1px solid <?php echo $oBorder; ?>;">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="<?php echo $oBgClass; ?> bg-opacity-10 rounded-circle p-3 me-3">
                                        <i class="bi <?php echo $oIconClass; ?> fs-3"></i>
                                    </div>
                                    <div>
                                        <h4 class="fw-bold text-dark mb-0"><?php echo $oTitle; ?></h4>
                                        <p class="text-muted mb-0 small text-uppercase fw-bold">Hospital Organ Processing</p>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <div class="p-3 bg-white rounded-4 border shadow-sm text-center">
                                            <label class="text-muted small fw-bold text-uppercase d-block mb-2">Organ</label>
                                            <span class="badge bg-primary fs-5 px-3 rounded-pill">
                                                <i class="bi bi-heart-pulse me-1"></i><?php echo htmlspecialchars($organDetails['organ_type']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="p-3 bg-white rounded-4 border shadow-sm text-center">
                                            <label class="text-muted small fw-bold text-uppercase d-block mb-2">Status</label>
                                            <span class="badge <?php echo $oBadgeClass; ?> fs-6 px-3 rounded-pill text-uppercase">
                                                <i class="bi <?php echo $oIconClass; ?> me-1"></i>
                                                <?php echo ucfirst($oStatus); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="p-3 bg-white rounded-4 border shadow-sm text-center">
                                            <label class="text-muted small fw-bold text-uppercase d-block mb-2">Next Step</label>
                                            <p class="mb-2 small fw-medium"><?php echo $oNextStep; ?></p>
                                            <a href="report.php?type=hospital" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                                                <i class="bi bi-file-earmark-text me-1"></i>View Report
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>


                    <div class="card-custom">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold m-0 text-dark">Recent Requests Overview</h5>
                            <div class="text-muted"><i class="bi bi-arrow-down-up me-1"></i>Sorted by Priority</div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>Patient Name</th>
                                        <th>Age</th>
                                        <th>Request Type</th>
                                        <th>Blood Group</th>
                                        <th>Priority Score</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($patients) > 0): ?>
                                        <?php foreach ($patients as $p): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="bg-light rounded-circle p-2 me-3 d-flex align-items-center justify-content-center"
                                                            style="width: 40px; height: 40px;">
                                                            <i class="bi bi-person-fill text-secondary"></i>
                                                        </div>
                                                        <?php $display_name = !empty($p['patient_name']) ? $p['patient_name'] : 'Unknown'; ?>
                                                        <span
                                                            class="fw-bold text-dark"><?php echo htmlspecialchars($display_name); ?></span>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($p['age']); ?></td>
                                                <td>
                                                    <span class="req-type req-blood"><i
                                                            class="bi bi-droplet-fill me-1"></i>Blood Request</span>
                                                </td>
                                                <td>
                                                    <span
                                                        class="badge bg-secondary rounded-pill px-2 py-1"><?php echo htmlspecialchars($p['blood_group']); ?></span>
                                                </td>

                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <span
                                                            class="fw-bold me-2"><?php echo htmlspecialchars($p['priority_score']); ?></span>
                                                        <div class="progress flex-grow-1" style="height: 6px;">
                                                            <?php
                                                            $width = min(100, $p['priority_score']);
                                                            $bgClass = $p['priority_score'] > 60 ? 'bg-danger' : 'bg-primary';
                                                            ?>
                                                            <div class="progress-bar <?php echo $bgClass; ?>" role="progressbar"
                                                                style="width: <?php echo $width; ?>%;"></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status = strtolower($p['status']);
                                                    // FIX STATUS DISPLAY
                                                    switch ($status) {
                                                        case 'fulfilled':
                                                            $badgeClass = 'bg-success text-white';
                                                            $icon = 'bi-heart-fill';
                                                            $text = 'Fulfilled';
                                                            break;
                                                        case 'approved':
                                                            $badgeClass = 'bg-info text-dark';
                                                            $icon = 'bi-check-circle-fill';
                                                            $text = 'Approved';
                                                            break;
                                                        case 'donor_matched':
                                                            $badgeClass = 'bg-success text-white';
                                                            $icon = 'bi-person-check-fill';
                                                            $text = 'Donor Matched';
                                                            break;
                                                        case 'waiting_for_donor':
                                                            $badgeClass = 'bg-primary text-white';
                                                            $icon = 'bi-person-lines-fill';
                                                            $text = 'Waiting for Donor';
                                                            break;
                                                        case 'rejected':
                                                            $badgeClass = 'bg-danger text-white';
                                                            $icon = 'bi-x-circle-fill';
                                                            $text = 'Rejected';
                                                            break;
                                                        case 'pending':
                                                        default:
                                                            $badgeClass = 'bg-warning text-dark';
                                                            $icon = 'bi-hourglass';
                                                            $text = 'Pending';
                                                    }
                                                    ?>
                                                    <span class="badge <?php echo $badgeClass; ?> rounded-pill px-3 py-2">
                                                        <i
                                                            class="bi <?php echo $icon; ?> me-1"></i><?php echo htmlspecialchars($text); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-muted">No patient requests found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- PROFILE SECTION -->
                <div id="profile-section" class="content-section">
                    <div class="card-custom">
                        <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-person-fill text-primary me-2"></i>Profile
                            Management</h5>
                        <form action="patient_dashboard.php" method="POST">
                            <div class="row g-4">
                                <div class="col-md-12">
                                    <label class="form-label fw-bold text-muted small">Full Legal Name</label>
                                    <input type="text" name="name" class="form-control form-control-lg" required
                                        value="<?php echo htmlspecialchars($userProfile['name'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-muted small">Age</label>
                                    <input type="number" name="age" class="form-control form-control-lg" required
                                        min="1" max="150"
                                        value="<?php echo htmlspecialchars($userProfile['age'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-muted small">Blood Group</label>
                                    <?php $bg = $userProfile['blood_group'] ?? ''; ?>
                                    <select name="blood_group" class="form-select form-select-lg" required>
                                        <option value="A+" <?php if ($bg == 'A+')
                                            echo 'selected'; ?>>A+</option>
                                        <option value="A-" <?php if ($bg == 'A-')
                                            echo 'selected'; ?>>A-</option>
                                        <option value="B+" <?php if ($bg == 'B+')
                                            echo 'selected'; ?>>B+</option>
                                        <option value="B-" <?php if ($bg == 'B-')
                                            echo 'selected'; ?>>B-</option>
                                        <option value="AB+" <?php if ($bg == 'AB+')
                                            echo 'selected'; ?>>AB+</option>
                                        <option value="AB-" <?php if ($bg == 'AB-')
                                            echo 'selected'; ?>>AB-</option>
                                        <option value="O+" <?php if ($bg == 'O+')
                                            echo 'selected'; ?>>O+</option>
                                        <option value="O-" <?php if ($bg == 'O-')
                                            echo 'selected'; ?>>O-</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-muted small">Contact Number</label>
                                    <input type="text" name="contact" class="form-control form-control-lg"
                                        placeholder="Enter numbers only" pattern="[0-9]*"
                                        value="<?php echo htmlspecialchars($userProfile['contact'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-muted small">Location (City/Area)</label>
                                    <input type="text" name="location" class="form-control form-control-lg" required
                                        placeholder="Enter your current location"
                                        value="<?php echo htmlspecialchars($userProfile['location'] ?? ''); ?>">
                                </div>
                                <div class="col-12 mt-4">
                                    <button type="submit" name="update_profile"
                                        class="btn btn-primary rounded-pill px-5 shadow-sm fw-bold">Save Profile
                                        Update</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- REQUEST SECTION (Blood Requests Only) -->
                <div id="request-section" class="content-section">
                    <div class="card-custom">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold mb-0 text-dark"><i
                                    class="bi bi-droplet-half text-danger me-2"></i>Initiate Blood Request
                            </h5>
                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-3 py-2 fw-bold">Blood Network</span>
                        </div>
                        <p class="text-muted small mb-4 border-bottom pb-3">Submit a blood requirement to match with inventory across regional blood banks and emergency donor networks.</p>

                        <!-- Informational notice for Organ Transplants -->
                        <div class="alert alert-info border-0 rounded-4 d-flex align-items-center mb-4 shadow-sm">
                            <i class="bi bi-hospital-fill text-primary me-3 fs-3"></i>
                            <div>
                                <strong class="d-block text-dark">Looking for an Organ Transplant?</strong>
                                <span class="small text-muted">Per medical protocol, organ transplant requirements must be initiated and verified by an authorized hospital medical team following clinical evaluation. Once your hospital submits an organ request on your behalf, you can track its live progress under <strong>Organ Details</strong>.</span>
                            </div>
                        </div>

                        <form action="patient_dashboard.php" method="POST">
                            <input type="hidden" name="request_type" value="blood">
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-muted small">Patient Name (For Request)</label>
                                    <input type="text" name="name" class="form-control form-control-lg" required 
                                        value="<?php echo htmlspecialchars($userProfile['name'] ?? ''); ?>"
                                        placeholder="e.g. John Doe">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-muted small">Age</label>
                                    <input type="number" name="age" class="form-control form-control-lg" required min="1" max="120"
                                        value="<?php echo htmlspecialchars($userProfile['age'] ?? ''); ?>">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-bold text-muted small">Blood Group</label>
                                    <select name="blood_group" id="bgSelect" class="form-select form-select-lg" required>
                                        <option value="" disabled <?php echo empty($userProfile['blood_group']) ? 'selected' : ''; ?>>Select Group...</option>
                                        <?php
                                        $allGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
                                        $patBg = $userProfile['blood_group'] ?? '';
                                        foreach ($allGroups as $g):
                                        ?>
                                            <option value="<?php echo $g; ?>" <?php echo ($patBg === $g) ? 'selected' : ''; ?>><?php echo $g; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-bold text-muted small">Units Needed</label>
                                    <input type="number" name="units_needed" id="unitsInput" class="form-control form-control-lg" min="1" max="10" value="1" required placeholder="e.g. 1">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-bold text-muted small">Medical Urgency</label>
                                    <select name="condition" class="form-select form-select-lg" required>
                                        <option value="normal">Normal / Routine</option>
                                        <option value="urgent">Urgent</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                </div>

                                <div class="col-12 mt-4">
                                    <button type="submit" name="submit_request"
                                        class="btn btn-request rounded-pill px-5 shadow-sm fw-bold">Submit Blood Request <i class="bi bi-send ms-2"></i></button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- BLOOD SECTION -->
                <div id="blood-section" class="content-section">
                    <div class="card-custom">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 pb-3 border-bottom">
                            <div>
                                <h5 class="fw-bold text-dark mb-1">
                                    <i class="bi bi-droplet-fill text-danger me-2"></i>Blood Bank Network
                                </h5>
                                <p class="text-muted small mb-0">Select a blood group to view real-time facility availability across network blood banks.</p>
                            </div>
                        </div>

                        <!-- Blood Group Filter Container -->
                        <div class="p-3 bg-light rounded-4 border mb-4">
                            <label class="form-label text-uppercase fw-bold text-muted small d-block mb-2">
                                <i class="bi bi-funnel-fill text-danger me-1"></i> Filter By Blood Group
                            </label>
                            <div class="d-flex flex-wrap gap-2 align-items-center" id="bloodGroupFilterContainer">
                                <?php
                                $allGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
                                $defaultGroup = !empty($userProfile['blood_group']) ? $userProfile['blood_group'] : 'A+';
                                foreach ($allGroups as $group):
                                    $isActive = ($group === $defaultGroup);
                                ?>
                                    <button type="button" 
                                            class="btn blood-group-btn <?php echo $isActive ? 'active' : ''; ?>" 
                                            data-group="<?php echo htmlspecialchars($group); ?>"
                                            onclick="selectBloodGroup('<?php echo htmlspecialchars($group); ?>')">
                                        <i class="bi bi-droplet-fill me-1"></i><?php echo htmlspecialchars($group); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Availability Results -->
                        <div id="bloodAvailabilityResults" class="blood-results-container">
                            <!-- Dynamically populated via JS -->
                        </div>
                    </div>
                </div>

                <!-- ORGAN SECTION -->
                <div id="organ-section" class="content-section">
                    <div class="card-custom">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 pb-3 border-bottom">
                            <div>
                                <h5 class="fw-bold text-dark mb-1">
                                    <i class="bi bi-heart-pulse-fill text-primary me-2"></i>Hospital Organ Network
                                </h5>
                                <p class="text-muted small mb-0">Select an organ type to view real-time facility availability and submit requests.</p>
                            </div>
                        </div>

                        <!-- Organ Type Filter Container -->
                        <div class="p-3 bg-light rounded-4 border mb-4">
                            <label class="form-label text-uppercase fw-bold text-muted small d-block mb-2">
                                <i class="bi bi-funnel-fill text-primary me-1"></i> Filter By Organ Type
                            </label>
                            <div class="d-flex flex-wrap gap-2 align-items-center" id="organTypeFilterContainer">
                                <?php
                                $organButtons = [
                                    ['label' => 'All Organs', 'icon' => 'bi-grid-fill', 'type' => 'all'],
                                    ['label' => 'Heart', 'icon' => 'bi-heart-fill text-danger', 'type' => 'Heart'],
                                    ['label' => 'Kidney', 'icon' => 'bi-capsule text-warning', 'type' => 'Kidney'],
                                    ['label' => 'Liver', 'icon' => 'bi-activity text-danger', 'type' => 'Liver'],
                                    ['label' => 'Lungs', 'icon' => 'bi-wind text-info', 'type' => 'Lungs'],
                                    ['label' => 'Pancreas', 'icon' => 'bi-shield-fill-plus text-primary', 'type' => 'Pancreas'],
                                    ['label' => 'Cornea', 'icon' => 'bi-eye-fill text-info', 'type' => 'Cornea'],
                                    ['label' => 'Bone Marrow', 'icon' => 'bi-bandaid-fill text-danger', 'type' => 'Bone Marrow']
                                ];
                                foreach ($organButtons as $btn):
                                    $isActive = ($btn['type'] === 'all');
                                ?>
                                    <button type="button" 
                                            class="btn organ-type-btn <?php echo $isActive ? 'active' : ''; ?>" 
                                            data-organ="<?php echo htmlspecialchars($btn['type']); ?>"
                                            onclick="selectOrganType('<?php echo htmlspecialchars($btn['type']); ?>')">
                                        <i class="bi <?php echo $btn['icon']; ?> me-1"></i><?php echo htmlspecialchars($btn['label']); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Availability Results -->
                        <div id="organAvailabilityResults" class="organ-results-container">
                            <!-- Dynamically populated via JS -->
                        </div>
                    </div>

                    <!-- My Organ Requests Status -->
                    <?php
                    $myOrganReqs = $pdo->prepare("
                        SELECT o.organ_type, o.status, h.name AS hospital_name
                        FROM organ_requests o
                        JOIN hospitals h ON o.hospital_id = h.hospital_id
                        WHERE o.patient_id = ?
                        ORDER BY o.request_id DESC
                    ");
                    $myOrganReqs->execute([$_SESSION['ref_id']]);
                    $myOrganRequests = $myOrganReqs->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    <?php if (!empty($myOrganRequests)): ?>
                        <div class="card-custom mt-4">
                            <h5 class="fw-bold text-dark mb-4"><i class="bi bi-list-check text-info me-2"></i>My Organ
                                Request History</h5>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Organ Type</th>
                                            <th>Hospital</th>
                                            <th>Status</th>
                                            <th class="text-end">Report</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($myOrganRequests as $or): ?>
                                            <tr>
                                                <td><span
                                                        class="badge bg-primary rounded-pill px-3"><?php echo htmlspecialchars($or['organ_type']); ?></span>
                                                </td>
                                                <td class="fw-bold"><?php echo htmlspecialchars($or['hospital_name']); ?></td>
                                                <td>
                                                    <?php
                                                    $st = strtolower($or['status']);
                                                    $bc = $st === 'fulfilled' ? 'bg-success' : ($st === 'pending' ? 'bg-warning text-dark' : 'bg-danger');
                                                    ?>
                                                    <span
                                                        class="badge <?php echo $bc; ?> rounded-pill px-3"><?php echo ucfirst($st); ?></span>
                                                </td>
                                                <td class="text-end">
                                                    <a href="report.php?type=hospital"
                                                        class="btn btn-sm btn-outline-primary rounded-pill px-3">
                                                        <i class="bi bi-file-earmark-text me-1"></i>Report
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>



            </div>
            <!-- Footer -->
            <footer class="text-center py-4 text-muted mt-5" style="border-top: 1px solid rgba(0,0,0,0.05);">
                &copy; 2026 MediMatch | Saving Lives Through Smart Matching
            </footer>
        </div>
    </div>

    <!-- Bootstrap JS, QRCode.js & Data Display Logic -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        .hover-lift {
            transition: box-shadow 0.25s, transform 0.25s;
        }

        .hover-lift:hover {
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.10) !important;
            transform: translateY(-2px);
        }

        .card-expand {
            animation: slideDown 0.25s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes qrScaleFade {
            0% {
                opacity: 0;
                transform: scale(0.7) translateY(10px);
            }
            100% {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .qr-container-wrapper {
            animation: qrScaleFade 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
        }

        #fulfilledReceiptPanel, #fulfilledOrganReceiptPanel {
            display: none;
            opacity: 0;
            transform: translateY(-10px);
            transition: opacity 0.35s ease, transform 0.35s ease;
        }

        #fulfilledReceiptPanel.panel-expanded, #fulfilledOrganReceiptPanel.panel-expanded {
            opacity: 1;
            transform: translateY(0);
        }

        /* Blood Group Filter Button Styling & Animations */
        .blood-group-btn {
            background: #ffffff;
            color: #495057;
            border: 1px solid #dee2e6;
            border-radius: 50px;
            padding: 0.45rem 1.15rem;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.03);
            display: inline-flex;
            align-items: center;
            cursor: pointer;
        }

        .blood-group-btn:hover {
            transform: translateY(-3px) scale(1.05);
            border-color: #ff0844;
            color: #ff0844;
            box-shadow: 0 6px 16px rgba(255, 8, 68, 0.18);
        }

        .blood-group-btn.active {
            background: linear-gradient(135deg, #ff0844 0%, #ff4e50 100%);
            color: #ffffff;
            border-color: transparent;
            box-shadow: 0 8px 20px rgba(255, 8, 68, 0.35);
            transform: translateY(-2px) scale(1.04);
        }

        .blood-group-btn.active:hover {
            transform: translateY(-4px) scale(1.07);
            box-shadow: 0 10px 24px rgba(255, 8, 68, 0.45);
        }

        .blood-group-btn i {
            transition: transform 0.25s ease;
        }

        .blood-group-btn:hover i {
            transform: scale(1.2);
        }

        /* Results Transition Animation */
        .blood-results-container {
            opacity: 1;
            transform: translateY(0);
            transition: opacity 0.25s ease, transform 0.25s ease;
        }

        .blood-results-container.fade-out {
            opacity: 0;
            transform: translateY(10px);
        }

        /* Organ Type Filter Button Styling & Animations */
        .organ-type-btn {
            background: #ffffff;
            color: #495057;
            border: 1px solid #dee2e6;
            border-radius: 50px;
            padding: 0.45rem 1.15rem;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.03);
            display: inline-flex;
            align-items: center;
            cursor: pointer;
        }

        .organ-type-btn:hover {
            transform: translateY(-3px) scale(1.05);
            border-color: #0d6efd;
            color: #0d6efd;
            box-shadow: 0 6px 16px rgba(13, 110, 253, 0.18);
        }

        .organ-type-btn.active {
            background: linear-gradient(135deg, #0d6efd 0%, #00d2ff 100%);
            color: #ffffff;
            border-color: transparent;
            box-shadow: 0 8px 20px rgba(13, 110, 253, 0.35);
            transform: translateY(-2px) scale(1.04);
        }

        .organ-type-btn.active:hover {
            transform: translateY(-4px) scale(1.07);
            box-shadow: 0 10px 24px rgba(13, 110, 253, 0.45);
        }

        .organ-type-btn i {
            transition: transform 0.25s ease;
        }

        .organ-type-btn:hover i {
            transform: scale(1.2);
        }

        .organ-results-container {
            opacity: 1;
            transform: translateY(0);
            transition: opacity 0.25s ease, transform 0.25s ease;
        }

        .organ-results-container.fade-out {
            opacity: 0;
            transform: translateY(10px);
        }
    </style>
    <script>
        // UI Section Toggling Logic
        function showSection(sectionId, element) {
            document.querySelectorAll('.content-section').forEach(sec => sec.classList.remove('active'));
            document.querySelectorAll('.nav-link-custom').forEach(nav => nav.classList.remove('active'));
            document.getElementById(sectionId).classList.add('active');
            if (element) element.classList.add('active');
            const titles = {
                'dashboard-section': 'Overview Dashboard',
                'profile-section': 'Manage Profile',
                'request-section': 'Submit Blood Request',
                'blood-section': 'Blood Bank Network',
                'organ-section': 'Hospital Organ Network'
            };
            document.getElementById('headerTitle').innerText = titles[sectionId] || 'Overview Dashboard';
        }

        // Accordion card toggle with chevron rotation
        function toggleCard(panelId, header) {
            const panel = document.getElementById(panelId);
            const icon = header.querySelector('.toggle-icon');
            const isOpen = panel.style.display === 'block';
            panel.style.display = isOpen ? 'none' : 'block';
            if (icon) icon.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
        }
    </script>
    <script>
        function toggleRequestFields() {
            // Deprecated: Patient requests are now dedicated to blood requests
        }
    </script>
    <script>
        const bloodInventoryData = <?php echo json_encode($bloodAvailability, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        let currentSelectedBloodGroup = <?php echo json_encode(!empty($userProfile['blood_group']) ? $userProfile['blood_group'] : 'A+'); ?>;

        function selectBloodGroup(group) {
            currentSelectedBloodGroup = group;
            
            document.querySelectorAll('.blood-group-btn').forEach(btn => {
                if (btn.getAttribute('data-group') === group) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            const container = document.getElementById('bloodAvailabilityResults');
            if (!container) return;

            container.classList.add('fade-out');

            setTimeout(() => {
                renderBloodAvailability(group);
                container.classList.remove('fade-out');
            }, 200);
        }

        function renderBloodAvailability(group) {
            const container = document.getElementById('bloodAvailabilityResults');
            if (!container) return;

            const matchingRows = bloodInventoryData.filter(item => item.blood_group === group);

            if (matchingRows.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-5 bg-white border rounded-4 shadow-sm p-4">
                        <i class="bi bi-droplet-half fs-1 text-danger opacity-50 d-block mb-3"></i>
                        <h5 class="fw-bold text-dark mb-2">No blood currently available</h5>
                        <p class="text-muted mb-0">There are currently no blood bank facilities with <strong>${escapeHtml(group)}</strong> inventory recorded.</p>
                    </div>
                `;
                return;
            }

            const totalUnits = matchingRows.reduce((sum, row) => sum + parseInt(row.units_available || 0, 10), 0);

            let html = '';

            if (totalUnits === 0) {
                html += `
                    <div class="alert alert-warning border-0 rounded-4 d-flex align-items-center mb-3 shadow-sm">
                        <i class="bi bi-exclamation-triangle-fill text-warning me-3 fs-4"></i>
                        <div>
                            <strong class="d-block text-dark">No blood currently available</strong>
                            <span class="small text-muted">All registered facilities for blood group <strong>${escapeHtml(group)}</strong> are currently out of stock.</span>
                        </div>
                    </div>
                `;
            }

            html += '<div class="row g-3">';
            matchingRows.forEach(row => {
                const units = parseInt(row.units_available || 0, 10);
                let unitsBadgeHtml = '';

                if (units > 1) {
                    unitsBadgeHtml = `<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2 rounded-pill fw-bold fs-6"><i class="bi bi-check-circle-fill me-1"></i>${units} units available</span>`;
                } else if (units === 1) {
                    unitsBadgeHtml = `<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2 rounded-pill fw-bold fs-6"><i class="bi bi-check-circle-fill me-1"></i>1 unit available</span>`;
                } else {
                    unitsBadgeHtml = `<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-3 py-2 rounded-pill fw-bold fs-6"><i class="bi bi-x-circle-fill me-1"></i>Out of Stock (0 units available)</span>`;
                }

                const contactHtml = row.contact ? `&nbsp;·&nbsp;<i class="bi bi-telephone-fill text-secondary me-1"></i>${escapeHtml(row.contact)}` : '';

                html += `
                    <div class="col-12">
                        <div class="card-custom hover-lift p-3 border rounded-4 bg-white shadow-sm d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
                            <div class="d-flex align-items-center">
                                <div class="bg-danger bg-opacity-10 text-danger rounded-circle p-3 me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; flex-shrink: 0;">
                                    <i class="bi bi-building fs-4"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold text-dark mb-1">${escapeHtml(row.name)}</h6>
                                    <div class="text-muted small">
                                        <i class="bi bi-geo-alt-fill text-primary me-1"></i>${escapeHtml(row.location)}
                                        ${contactHtml}
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-3 ms-sm-auto flex-wrap">
                                <span class="badge bg-danger rounded-pill px-3 py-2 fs-6 shadow-sm">
                                    <i class="bi bi-droplet-fill me-1"></i>${escapeHtml(row.blood_group)}
                                </span>
                                <div>
                                    ${unitsBadgeHtml}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';

            container.innerHTML = html;
        }

        const organInventoryData = <?php echo json_encode($organAvailability, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        let currentSelectedOrganType = 'all';

        function selectOrganType(organType) {
            currentSelectedOrganType = organType;
            
            document.querySelectorAll('.organ-type-btn').forEach(btn => {
                if (btn.getAttribute('data-organ') === organType) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            const container = document.getElementById('organAvailabilityResults');
            if (!container) return;

            container.classList.add('fade-out');

            setTimeout(() => {
                renderOrganAvailability(organType);
                container.classList.remove('fade-out');
            }, 200);
        }

        function renderOrganAvailability(organType) {
            const container = document.getElementById('organAvailabilityResults');
            if (!container) return;

            let matchingRows = organInventoryData;
            if (organType !== 'all') {
                matchingRows = organInventoryData.filter(item => 
                    item.organ_type.toLowerCase() === organType.toLowerCase()
                );
            }

            if (matchingRows.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-5 bg-white border rounded-4 shadow-sm p-4">
                        <i class="bi bi-heart-pulse fs-1 text-primary opacity-50 d-block mb-3"></i>
                        <h5 class="fw-bold text-dark mb-2">No organ currently available</h5>
                        <p class="text-muted mb-0">There are currently no hospital facilities with <strong>${escapeHtml(organType === 'all' ? 'Organ' : organType)}</strong> inventory recorded.</p>
                    </div>
                `;
                return;
            }

            const totalUnits = matchingRows.reduce((sum, row) => sum + parseInt(row.units_available || 0, 10), 0);

            let html = '';

            if (totalUnits === 0 && organType !== 'all') {
                html += `
                    <div class="alert alert-warning border-0 rounded-4 d-flex align-items-center mb-3 shadow-sm">
                        <i class="bi bi-exclamation-triangle-fill text-warning me-3 fs-4"></i>
                        <div>
                            <strong class="d-block text-dark">No organ currently available</strong>
                            <span class="small text-muted">All registered hospitals for <strong>${escapeHtml(organType)}</strong> are currently out of stock.</span>
                        </div>
                    </div>
                `;
            }

            html += '<div class="row g-3">';
            matchingRows.forEach(row => {
                const units = parseInt(row.units_available || 0, 10);
                let unitsBadgeHtml = '';
                let requestBtnHtml = '';

                if (units > 1) {
                    unitsBadgeHtml = `<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2 rounded-pill fw-bold fs-6"><i class="bi bi-check-circle-fill me-1"></i>${units} units available</span>`;
                } else if (units === 1) {
                    unitsBadgeHtml = `<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2 rounded-pill fw-bold fs-6"><i class="bi bi-check-circle-fill me-1"></i>1 unit available</span>`;
                } else {
                    unitsBadgeHtml = `<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-3 py-2 rounded-pill fw-bold fs-6"><i class="bi bi-x-circle-fill me-1"></i>Out of Stock (0 units available)</span>`;
                }

                requestBtnHtml = `
                    <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-3 py-2 rounded-pill fw-bold" title="Organ requests must be initiated by an authorized hospital">
                        <i class="bi bi-hospital me-1"></i>Hospital Ordered Only
                    </span>
                `;

                const contactHtml = row.contact ? `&nbsp;·&nbsp;<i class="bi bi-telephone-fill text-secondary me-1"></i>${escapeHtml(row.contact)}` : '';

                html += `
                    <div class="col-12">
                        <div class="card-custom hover-lift p-3 border rounded-4 bg-white shadow-sm d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
                            <div class="d-flex align-items-center">
                                <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3 me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; flex-shrink: 0;">
                                    <i class="bi bi-hospital fs-4"></i>
                                </div>
                                <div>
                                    <h6 class="fw-bold text-dark mb-1">${escapeHtml(row.hospital_name)}</h6>
                                    <div class="text-muted small">
                                        <i class="bi bi-geo-alt-fill text-primary me-1"></i>${escapeHtml(row.location)}
                                        ${contactHtml}
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-3 ms-sm-auto flex-wrap">
                                <span class="badge bg-primary rounded-pill px-3 py-2 fs-6 shadow-sm">
                                    <i class="bi bi-heart-pulse me-1"></i>${escapeHtml(row.organ_type)}
                                </span>
                                <div>
                                    ${unitsBadgeHtml}
                                </div>
                                <div>
                                    ${requestBtnHtml}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += '</div>';

            container.innerHTML = html;
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        const qrVerificationUrl = <?php echo json_encode($verifyUrl ?? ''); ?>;
        const organQrVerificationUrl = <?php echo json_encode($oVerifyUrl ?? ''); ?>;

        function toggleReceiptPanel() {
            const panel = document.getElementById('fulfilledReceiptPanel');
            const btn = document.getElementById('toggleReceiptBtn');
            if (!panel || !btn) return;

            const isHidden = panel.style.display === 'none' || panel.style.display === '';

            if (isHidden) {
                panel.style.display = 'block';
                setTimeout(() => {
                    panel.classList.add('panel-expanded');
                }, 10);
                btn.innerHTML = '<i class="bi bi-receipt me-2"></i>Hide Receipt <i class="bi bi-chevron-up ms-1"></i>';
                btn.classList.replace('btn-success', 'btn-outline-success');
            } else {
                panel.classList.remove('panel-expanded');
                setTimeout(() => {
                    panel.style.display = 'none';
                }, 300);
                btn.innerHTML = '<i class="bi bi-receipt me-2"></i>View Receipt <i class="bi bi-chevron-down ms-1"></i>';
                btn.classList.replace('btn-outline-success', 'btn-success');
            }
        }

        function toggleOrganReceiptPanel() {
            const panel = document.getElementById('fulfilledOrganReceiptPanel');
            const btn = document.getElementById('toggleOrganReceiptBtn');
            if (!panel || !btn) return;

            const isHidden = panel.style.display === 'none' || panel.style.display === '';

            if (isHidden) {
                panel.style.display = 'block';
                setTimeout(() => {
                    panel.classList.add('panel-expanded');
                }, 10);
                btn.innerHTML = '<i class="bi bi-receipt me-2"></i>Hide Receipt <i class="bi bi-chevron-up ms-1"></i>';
                btn.classList.replace('btn-success', 'btn-outline-success');
            } else {
                panel.classList.remove('panel-expanded');
                setTimeout(() => {
                    panel.style.display = 'none';
                }, 300);
                btn.innerHTML = '<i class="bi bi-receipt me-2"></i>View Receipt <i class="bi bi-chevron-down ms-1"></i>';
                btn.classList.replace('btn-outline-success', 'btn-success');
            }
        }

        function downloadReceiptQR() {
            const qrImg = document.querySelector("#receiptQrCode img");
            const qrCanvas = document.querySelector("#receiptQrCode canvas");
            let imageUri = "";
            if (qrImg && qrImg.src) {
                imageUri = qrImg.src;
            } else if (qrCanvas) {
                imageUri = qrCanvas.toDataURL("image/png");
            }
            
            if (imageUri) {
                const link = document.createElement("a");
                link.href = imageUri;
                link.download = "blood_receipt_<?php echo $reqId ?? 'qr'; ?>.png";
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        function downloadOrganReceiptQR() {
            const qrImg = document.querySelector("#organReceiptQrCode img");
            const qrCanvas = document.querySelector("#organReceiptQrCode canvas");
            let imageUri = "";
            if (qrImg && qrImg.src) {
                imageUri = qrImg.src;
            } else if (qrCanvas) {
                imageUri = qrCanvas.toDataURL("image/png");
            }
            
            if (imageUri) {
                const link = document.createElement("a");
                link.href = imageUri;
                link.download = "organ_receipt_<?php echo $oReqId ?? 'qr'; ?>.png";
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            renderBloodAvailability(currentSelectedBloodGroup);
            renderOrganAvailability(currentSelectedOrganType);

            if (qrVerificationUrl && document.getElementById('receiptQrCode')) {
                new QRCode(document.getElementById("receiptQrCode"), {
                    text: qrVerificationUrl,
                    width: 160,
                    height: 160,
                    colorDark: "#15803d",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.H
                });
            }

            if (organQrVerificationUrl && document.getElementById('organReceiptQrCode')) {
                new QRCode(document.getElementById("organReceiptQrCode"), {
                    text: organQrVerificationUrl,
                    width: 160,
                    height: 160,
                    colorDark: "#15803d",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.H
                });
            }
        });
    </script>

    <?php require 'chatbot.php'; ?>
    <?php require 'language_switcher.php'; ?>
</body>

</html>