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

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $message = "";
    if (isset($_SESSION['success'])) {
        $message = $_SESSION['success'];
        unset($_SESSION['success']);
    }
    if (isset($_SESSION['error'])) {
        $message = $_SESSION['error'];
        unset($_SESSION['error']);
    }

    // Handle Unified Form Submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
        $name = $_POST['name'];
        $age = (int) $_POST['age'];
        $blood_group = $_POST['blood_group'];
        $condition = $_POST['condition'];
        $request_type = $_POST['request_type']; // 'blood' or 'organ'
        $patient_id = $_SESSION['ref_id'];
        
        $request_date = date('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            if ($request_type === 'blood') {
                $units_needed = isset($_POST['units_needed']) ? (int)$_POST['units_needed'] : 1;
                $priority_score = calculatePriority($age, $condition, 'blood', '', $request_date);

                // Update patients table with latest request info and priority
                $stmtPat = $pdo->prepare("UPDATE patients SET request_type='blood', organ_needed=NULL, `condition`=?, request_date=?, status='pending', priority_score=? WHERE patient_id=?");
                $stmtPat->execute([$condition, $request_date, $priority_score, $patient_id]);

                // Insert blood request using submitted patient_name directly
                $stmt = $pdo->prepare("INSERT INTO blood_requests (patient_id, patient_name, age, blood_group, priority_score, units_needed, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                $stmt->execute([$patient_id, $name, $age, $blood_group, $priority_score, $units_needed]);

                $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='bi bi-check-circle-fill me-2'></i>Blood Request successfully logged for matching! <button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            } elseif ($request_type === 'organ') {
                $organ_type = trim($_POST['organ_type']);
                $req_hospital_id = (int) $_POST['hospital_id'];
                $priority_score = calculatePriority($age, $condition, 'organ', $organ_type, $request_date);

                $stmtOrg = $pdo->prepare("INSERT INTO organ_requests (patient_id, hospital_id, organ_type, status, priority_score) VALUES (?, ?, ?, 'pending', ?)");
                $stmtOrg->execute([$patient_id, $req_hospital_id, $organ_type, $priority_score]);

                // Update patients table with latest request info and priority
                $stmtPat = $pdo->prepare("UPDATE patients SET request_type='organ', organ_needed=?, `condition`=?, request_date=?, status='pending', priority_score=? WHERE patient_id=?");
                $stmtPat->execute([$organ_type, $condition, $request_date, $priority_score, $patient_id]);

                $message = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='bi bi-check-circle-fill me-2'></i>Organ Request successfully submitted to hospital queue!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
            }
            $pdo->commit();
            $_SESSION['success'] = $message;
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

    // Note: The previous Handle Organ Request block is deprecated since we unified the form.
    // However, we preserve functionality if the legacy hospital network buttons are still active.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_organ'])) {
        $patient_id = $_SESSION['ref_id'];
        $req_hospital_id = (int) $_POST['hospital_id'];
        $organ_type = trim($_POST['organ_type']);

        $stmtAge = $pdo->prepare("SELECT age FROM patients WHERE patient_id = ?");
        $stmtAge->execute([$patient_id]);
        $age = (int) $stmtAge->fetchColumn();

        $condition = 'critical';
        $request_date = date('Y-m-d H:i:s');
        $priority_score = calculatePriority($age, $condition, 'organ', $organ_type, $request_date);

        $pdo->beginTransaction();
        try {
            $stmtOrg = $pdo->prepare("INSERT INTO organ_requests (patient_id, hospital_id, organ_type, status, priority_score) VALUES (?, ?, ?, 'pending', ?)");
            $stmtOrg->execute([$patient_id, $req_hospital_id, $organ_type, $priority_score]);
            $stmtPat = $pdo->prepare("UPDATE patients SET request_type='organ', organ_needed=? WHERE patient_id=?");
            $stmtPat->execute([$organ_type, $patient_id]);
            $pdo->commit();
            $_SESSION['success'] = "<div class='alert alert-success alert-dismissible fade show' role='alert'><i class='bi bi-check-circle-fill me-2'></i>Organ request submitted via direct hospital catalog!<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "<div class='alert alert-danger alert-dismissible fade show' role='alert'><i class='bi bi-x-circle-fill me-2'></i>Error: " . htmlspecialchars($e->getMessage()) . "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
        }
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
                   WHERE oi.units_available > 0
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
        SELECT 'blood_bank' as match_type, blood_group, status 
        FROM blood_requests 
        WHERE patient_id = ? 
        ORDER BY request_id DESC LIMIT 1
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
        SELECT 'hospital' as match_type, o.organ_type, o.status,
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
                <span class="badge bg-light text-secondary rounded-pill px-3 shadow-sm border">Patient Portal</span>
            </div>

            <div class="px-3">
                <div class="nav-link-custom active" onclick="showSection('dashboard-section', this)">
                    <i class="bi bi-grid-fill me-3 fs-5"></i> Dashboard
                </div>
                <div class="nav-link-custom" onclick="showSection('profile-section', this)">
                    <i class="bi bi-person-fill me-3 fs-5"></i> My Profile
                </div>
                <div class="nav-link-custom" onclick="showSection('request-section', this)">
                    <i class="bi bi-bandaid-fill me-3 fs-5"></i> Submit Request
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
                    <i class="bi bi-box-arrow-right me-3 fs-5"></i> Logout
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

                    <!-- ── ORGAN FULFILLMENT CARD (Step 2) ────────────────── -->
                    <?php if (!empty($organDetails)): ?>
                        <?php
                        $oStatus = strtolower($organDetails['status']);
                        $oGradFrom = $oStatus === 'fulfilled' ? '#f0fff4' : ($oStatus === 'rejected' ? '#fff1f2' : '#f0f8ff');
                        $oBorder = $oStatus === 'fulfilled' ? '#22c55e22' : ($oStatus === 'rejected' ? '#ff0a2222' : '#0083b022');
                        $oIconClass = $oStatus === 'fulfilled' ? 'bi-check-all text-success' : ($oStatus === 'rejected' ? 'bi-x-circle-fill text-danger' : 'bi-hourglass-split text-info');
                        $oBgClass = $oStatus === 'fulfilled' ? 'bg-success' : ($oStatus === 'rejected' ? 'bg-danger' : 'bg-info');
                        $oBadgeClass = $oStatus === 'fulfilled' ? 'bg-success' : ($oStatus === 'rejected' ? 'bg-danger' : 'bg-warning text-dark');
                        $oTitle = $oStatus === 'fulfilled' ? 'Organ Request Fulfilled!' : ($oStatus === 'rejected' ? 'Organ Request Rejected' : 'Organ Request Pending');
                        $oNextStep = $oStatus === 'fulfilled' ? 'Units allocated. Contact hospital.' : ($oStatus === 'rejected' ? 'Request rejected. Submit a new one.' : 'Awaiting hospital approval...');
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
                                <!-- Organ Type -->
                                <div class="col-md-4">
                                    <div class="p-3 bg-white rounded-4 border shadow-sm text-center">
                                        <label class="text-muted small fw-bold text-uppercase d-block mb-2">Organ</label>
                                        <span class="badge bg-primary fs-5 px-3 rounded-pill">
                                            <i
                                                class="bi bi-heart-pulse me-1"></i><?php echo htmlspecialchars($organDetails['organ_type']); ?>
                                        </span>
                                    </div>
                                </div>
                                <!-- Status -->
                                <div class="col-md-4">
                                    <div class="p-3 bg-white rounded-4 border shadow-sm text-center">
                                        <label class="text-muted small fw-bold text-uppercase d-block mb-2">Status</label>
                                        <span
                                            class="badge <?php echo $oBadgeClass; ?> fs-6 px-3 rounded-pill text-uppercase">
                                            <i class="bi <?php echo $oIconClass; ?> me-1"></i>
                                            <?php echo $oStatus === 'fulfilled' ? 'Fulfilled' : ucfirst($oStatus); ?>
                                        </span>
                                    </div>
                                </div>
                                <!-- Next Step + Report -->
                                <div class="col-md-4">
                                    <div class="p-3 bg-white rounded-4 border shadow-sm text-center">
                                        <label class="text-muted small fw-bold text-uppercase d-block mb-2">Next
                                            Step</label>
                                        <p class="mb-2 small fw-medium"><?php echo $oNextStep; ?></p>
                                        <a href="report.php?type=hospital"
                                            class="btn btn-sm btn-outline-primary rounded-pill px-3">
                                            <i class="bi bi-file-earmark-text me-1"></i>View Report
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <!-- Hospital details strip (only when fulfilled) -->
                            <?php if ($oStatus === 'fulfilled'): ?>
                                <div class="mt-3 pt-3 border-top d-flex flex-wrap gap-3">
                                    <span class="text-muted small"><i
                                            class="bi bi-hospital me-1 text-primary"></i><strong><?php echo htmlspecialchars($organDetails['hospital_name']); ?></strong></span>
                                    <span class="text-muted small"><i
                                            class="bi bi-geo-alt me-1 text-info"></i><?php echo htmlspecialchars($organDetails['location']); ?></span>
                                    <span class="text-muted small"><i
                                            class="bi bi-telephone me-1 text-success"></i><?php echo htmlspecialchars($organDetails['contact']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
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

                <!-- REQUEST SECTION -->
                <div id="request-section" class="content-section">
                    <div class="card-custom">
                        <h5 class="fw-bold mb-4 text-dark"><i
                                class="bi bi-file-earmark-medical text-danger me-2"></i>Initiate System Match Request
                        </h5>
                        <form action="patient_dashboard.php" method="POST">
                            <div class="row g-4">
                                <div class="col-md-12">
                                    <label class="form-label fw-bold text-muted small">Patient Name (For Request)</label>
                                    <input type="text" name="name" class="form-control form-control-lg" required placeholder="e.g. John Doe">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-bold text-muted small">Request Type</label>
                                    <select name="request_type" id="requestTypeSelect" class="form-select form-select-lg" onchange="toggleRequestFields()" required>
                                        <option value="" disabled selected>Select Type...</option>
                                        <option value="blood">Blood Request</option>
                                        <option value="organ">Organ Request</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold text-muted small">Age</label>
                                    <input type="number" name="age" class="form-control form-control-lg" required min="1" max="120">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold text-muted small">Request Severity</label>
                                    <select name="condition" class="form-select form-select-lg" required>
                                        <option value="normal">Normal / Routine</option>
                                        <option value="urgent">Urgent</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                </div>

                                <!-- BLOOD FIELDS (Hidden by default) -->
                                <div id="bloodFields" class="row g-4 m-0 p-0" style="display:none;">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small">Blood Group</label>
                                        <select name="blood_group" id="bgSelect" class="form-select form-select-lg">
                                            <option value="" disabled selected>Select Group...</option>
                                            <option value="A+">A+</option>
                                            <option value="A-">A-</option>
                                            <option value="B+">B+</option>
                                            <option value="B-">B-</option>
                                            <option value="AB+">AB+</option>
                                            <option value="AB-">AB-</option>
                                            <option value="O+">O+</option>
                                            <option value="O-">O-</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small">Units Needed</label>
                                        <input type="number" name="units_needed" id="unitsInput" class="form-control form-control-lg" min="1" max="10" placeholder="e.g. 2">
                                    </div>
                                </div>

                                <!-- ORGAN FIELDS (Hidden by default) -->
                                <div id="organFields" class="row g-4 m-0 p-0" style="display:none;">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small">Organ Type Needed</label>
                                        <select name="organ_type" id="organSelect" class="form-select form-select-lg">
                                            <option value="" disabled selected>Select Organ...</option>
                                            <option value="Heart">Heart</option>
                                            <option value="Liver">Liver</option>
                                            <option value="Lungs">Lungs</option>
                                            <option value="Kidney">Kidney</option>
                                            <option value="Pancreas">Pancreas</option>
                                            <option value="Cornea">Cornea</option>
                                            <option value="Bone Marrow">Bone Marrow</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small">Target Hospital</label>
                                        <select name="hospital_id" id="hospitalSelect" class="form-select form-select-lg">
                                            <option value="" disabled selected>Select Nearest Hospital...</option>
                                            <?php foreach ($hospitalDropdownList as $h): ?>
                                                <option value="<?php echo $h['hospital_id']; ?>"><?php echo htmlspecialchars($h['name']); ?> (<?php echo htmlspecialchars($h['location']); ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="col-12 mt-4">
                                    <button type="submit" name="submit_request"
                                        class="btn btn-request rounded-pill px-5 shadow-sm fw-bold">Run Matching
                                        Sequence <i class="bi bi-send ms-2"></i></button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- BLOOD SECTION -->
                <div id="blood-section" class="content-section">
                    <div class="card-custom">
                        <h5 class="fw-bold text-dark mb-1"><i class="bi bi-droplet-fill text-danger me-2"></i>Blood Bank
                            Network</h5>
                        <p class="text-muted small mb-4">Click a bank card to view available blood groups and units.</p>

                        <?php if (empty($groupedBanks)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-droplet fs-1 d-block mb-2 opacity-25"></i>No blood inventory active across
                                network facilities.
                            </div>
                        <?php endif; ?>

                        <?php $bidx = 0;
                        foreach ($groupedBanks as $bid => $bank):
                            $bidx++; ?>
                            <div class="mb-3 border rounded-4 overflow-hidden shadow-sm hover-lift">
                                <!-- Bank Header (clickable toggle) -->
                                <div class="p-3 d-flex justify-content-between align-items-center"
                                    style="cursor:pointer; background:#fff; transition:background 0.2s;"
                                    onclick="toggleCard('bcard-<?php echo $bid; ?>', this)"
                                    onmouseover="this.style.background='#fff8f8'" onmouseout="this.style.background='#fff'">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-danger bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-3"
                                            style="width:44px;height:44px;flex-shrink:0">
                                            <i class="bi bi-droplet-fill text-danger fs-5"></i>
                                        </div>
                                        <div>
                                            <h6 class="fw-bold mb-0 text-dark">
                                                <?php echo htmlspecialchars($bank['name']); ?>
                                            </h6>
                                            <small class="text-muted"><i
                                                    class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($bank['location']); ?>
                                                <?php if (!empty($bank['contact'])): ?>&nbsp;·&nbsp;<i
                                                        class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($bank['contact']); ?><?php endif; ?></small>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-danger rounded-pill"><?php echo count($bank['bloods']); ?>
                                            types</span>
                                        <i class="bi bi-chevron-down text-muted toggle-icon"
                                            style="transition:transform 0.3s;"></i>
                                    </div>
                                </div>
                                <!-- Blood group detail panel -->
                                <div id="bcard-<?php echo $bid; ?>" class="card-expand"
                                    style="display:none; background:#fafafa; border-top:1px solid #f0e6e6; padding:1rem 1.25rem;">
                                    <div class="row g-2">
                                        <?php foreach ($bank['bloods'] as $b):
                                            $uc = $b['units'] === 0 ? 'text-danger' : ($b['units'] < 5 ? 'text-warning' : 'text-success');
                                            ?>
                                            <div class="col-6 col-md-3">
                                                <div class="p-2 bg-white border rounded-3 text-center shadow-sm">
                                                    <span
                                                        class="badge bg-danger rounded-pill px-3 mb-1"><?php echo htmlspecialchars($b['group']); ?></span>
                                                    <div class="fw-bold <?php echo $uc; ?>"><?php echo $b['units']; ?> <small
                                                            class="text-muted fw-normal">units</small></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ORGAN SECTION -->
                <div id="organ-section" class="content-section">
                    <div class="card-custom">
                        <h5 class="fw-bold text-dark mb-1"><i
                                class="bi bi-heart-pulse-fill text-danger me-2"></i>Hospital Organ Network</h5>
                        <p class="text-muted small mb-4">Click a hospital card to view available organs and submit a
                            request.</p>

                        <?php if (empty($groupedHospitals)): ?>
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-heart-pulse fs-1 d-block mb-2 opacity-25"></i>No organ inventory available
                                across network hospitals.
                            </div>
                        <?php endif; ?>

                        <?php foreach ($groupedHospitals as $hid => $hosp): ?>
                            <div class="mb-3 border rounded-4 overflow-hidden shadow-sm hover-lift">
                                <!-- Hospital Header (clickable toggle) -->
                                <div class="p-3 d-flex justify-content-between align-items-center"
                                    style="cursor:pointer; background:#fff; transition:background 0.2s;"
                                    onclick="toggleCard('hcard-<?php echo $hid; ?>', this)"
                                    onmouseover="this.style.background='#f0f8ff'" onmouseout="this.style.background='#fff'">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-3"
                                            style="width:44px;height:44px;flex-shrink:0">
                                            <i class="bi bi-hospital text-primary fs-5"></i>
                                        </div>
                                        <div>
                                            <h6 class="fw-bold mb-0 text-dark">
                                                <?php echo htmlspecialchars($hosp['name']); ?>
                                            </h6>
                                            <small class="text-muted"><i
                                                    class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($hosp['location']); ?>
                                                <?php if (!empty($hosp['contact'])): ?>&nbsp;·&nbsp;<i
                                                        class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($hosp['contact']); ?><?php endif; ?></small>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-primary rounded-pill"><?php echo count($hosp['organs']); ?>
                                            organ<?php echo count($hosp['organs']) > 1 ? 's' : ''; ?></span>
                                        <i class="bi bi-chevron-down text-muted toggle-icon"
                                            style="transition:transform 0.3s;"></i>
                                    </div>
                                </div>
                                <!-- Organ detail panel -->
                                <div id="hcard-<?php echo $hid; ?>" class="card-expand"
                                    style="display:none; background:#f8fbff; border-top:1px solid #e0eeff; padding:1rem 1.25rem;">
                                    <div class="row g-2">
                                        <?php foreach ($hosp['organs'] as $o):
                                            $uc = $o['units'] === 0 ? 'text-danger' : ($o['units'] < 3 ? 'text-warning' : 'text-success');
                                            ?>
                                            <div class="col-12 col-md-6">
                                                <div
                                                    class="p-2 bg-white border rounded-3 d-flex align-items-center justify-content-between shadow-sm">
                                                    <span class="badge bg-primary rounded-pill px-3 py-2"><i
                                                            class="bi bi-heart-pulse me-1"></i><?php echo htmlspecialchars($o['type']); ?></span>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="fw-bold <?php echo $uc; ?>"><?php echo $o['units']; ?>
                                                            <small class="text-muted fw-normal">units</small></span>
                                                        <form method="POST" class="m-0"
                                                            onsubmit="return confirm('Request <?php echo addslashes($o['type']); ?> from <?php echo addslashes($hosp['name']); ?>?')">
                                                            <input type="hidden" name="hospital_id"
                                                                value="<?php echo (int) $o['hid']; ?>">
                                                            <input type="hidden" name="organ_type"
                                                                value="<?php echo htmlspecialchars($o['type']); ?>">
                                                            <button type="submit" name="request_organ"
                                                                class="btn btn-primary btn-sm rounded-pill px-3 fw-bold">
                                                                <i class="bi bi-send me-1"></i>Request
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
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

    <!-- Bootstrap JS & Data Display Logic -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
                'request-section': 'Secure Request Submission',
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
            const type = document.getElementById('requestTypeSelect').value;
            const bloodFields = document.getElementById('bloodFields');
            const organFields = document.getElementById('organFields');
            
            if (type === 'blood') {
                bloodFields.style.display = 'flex';
                organFields.style.display = 'none';
                
                document.getElementById('bgSelect').required = true;
                document.getElementById('unitsInput').required = true;
                document.getElementById('organSelect').required = false;
                document.getElementById('hospitalSelect').required = false;
                
            } else if (type === 'organ') {
                bloodFields.style.display = 'none';
                organFields.style.display = 'flex';
                
                document.getElementById('bgSelect').required = false;
                document.getElementById('unitsInput').required = false;
                document.getElementById('organSelect').required = true;
                document.getElementById('hospitalSelect').required = true;
            }
        }
    </script>
</body>

</html>