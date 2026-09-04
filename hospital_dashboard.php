<?php
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}
if ($_SESSION['role'] !== 'hospital') {
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

    $hospital_id = $_SESSION['ref_id'];

    // ── Handle Profile Update ──────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
        $name = trim($_POST['name']);
        $location = trim($_POST['location']);
        $contact = trim($_POST['contact']);
        $specialization = trim($_POST['specialization']);
        $license_no = trim($_POST['license_no']);

        if (!empty($contact) && !is_numeric($contact)) {
            $_SESSION['error'] = "<div class='alert alert-danger'>Contact must contain digits only.</div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
        if (empty($license_no)) {
            $_SESSION['error'] = "<div class='alert alert-danger'>License number is required.</div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

        $stmt = $pdo->prepare("UPDATE hospitals SET name=?, location=?, contact=?, specialization=?, license_no=? WHERE hospital_id=?");
        $stmt->execute([$name, $location, $contact, $specialization, $license_no, $hospital_id]);
        $_SESSION['success'] = "<div class='alert alert-success d-flex align-items-center'><i class='bi bi-check-circle-fill me-2'></i>Hospital profile updated successfully!</div>";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // ── Handle Update Stock (Step 3) ───────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_organ_stock'])) {
        $organ_type = trim($_POST['organ_type']);
        $units = (int) $_POST['units_to_add'];

        if ($units <= 0) {
            $_SESSION['error'] = "<div class='alert alert-danger'>Units to add must be greater than zero.</div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

        $checkStmt = $pdo->prepare("SELECT organ_id FROM organ_inventory WHERE hospital_id=? AND organ_type=?");
        $checkStmt->execute([$hospital_id, $organ_type]);

        if ($checkStmt->rowCount() > 0) {
            $pdo->prepare("UPDATE organ_inventory SET units_available = units_available + ? WHERE hospital_id=? AND organ_type=?")
                ->execute([$units, $hospital_id, $organ_type]);
        } else {
            $pdo->prepare("INSERT INTO organ_inventory (hospital_id, organ_type, units_available) VALUES (?, ?, ?)")
                ->execute([$hospital_id, $organ_type, $units]);
        }

        $_SESSION['success'] = "<div class='alert alert-success d-flex align-items-center'><i class='bi bi-check-circle-fill me-2'></i><strong>" . htmlspecialchars($organ_type) . "</strong> stock updated by <strong>+{$units}</strong> units!</div>";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // ── Handle Create Organ Request for Patient ─────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_patient_organ_request'])) {
        $patient_id = isset($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0;
        $organ_type = isset($_POST['organ_type']) ? trim($_POST['organ_type']) : '';
        $condition = isset($_POST['condition']) ? trim($_POST['condition']) : 'normal';

        if ($patient_id <= 0) {
            $_SESSION['error'] = "<div class='alert alert-danger d-flex align-items-center'><i class='bi bi-x-circle-fill me-2'></i>Please select a valid registered patient.</div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

        $validOrgans = ['Kidney', 'Liver', 'Heart', 'Lungs', 'Pancreas', 'Cornea', 'Bone Marrow'];
        if (!in_array($organ_type, $validOrgans, true)) {
            $_SESSION['error'] = "<div class='alert alert-danger d-flex align-items-center'><i class='bi bi-x-circle-fill me-2'></i>Please select a valid organ type from the list.</div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

        $validConditions = ['normal', 'urgent', 'critical'];
        if (!in_array($condition, $validConditions, true)) {
            $condition = 'normal';
        }

        // 1. Verify patient exists in database
        $stmtCheckPat = $pdo->prepare("SELECT patient_id, name, age, blood_group FROM patients WHERE patient_id = ?");
        $stmtCheckPat->execute([$patient_id]);
        $patData = $stmtCheckPat->fetch(PDO::FETCH_ASSOC);

        if (!$patData) {
            $_SESSION['error'] = "<div class='alert alert-danger d-flex align-items-center'><i class='bi bi-x-circle-fill me-2'></i>Patient record not found. Cannot create organ request for non-existent patient.</div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

        // 2. Prevent accidental duplicate active organ request for same patient & organ
        $stmtCheckDup = $pdo->prepare("SELECT request_id, status FROM organ_requests WHERE patient_id = ? AND organ_type = ? AND status IN ('pending', 'approved')");
        $stmtCheckDup->execute([$patient_id, $organ_type]);
        $existingReq = $stmtCheckDup->fetch(PDO::FETCH_ASSOC);

        if ($existingReq) {
            $_SESSION['error'] = "<div class='alert alert-warning d-flex align-items-center'><i class='bi bi-exclamation-triangle-fill me-2'></i>An active organ request (#" . $existingReq['request_id'] . ") for <strong>" . htmlspecialchars($organ_type) . "</strong> already exists for patient <strong>" . htmlspecialchars($patData['name']) . "</strong> with status: <strong>" . strtoupper($existingReq['status']) . "</strong>. Duplicate active requests are prevented.</div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

        // 3. Calculate priority score using existing system formula
        $request_date = date('Y-m-d H:i:s');
        $priority_score = calculatePriority((int)$patData['age'], $condition, 'organ', $organ_type, $request_date);

        // 4. Insert organ request & update patient record
        $pdo->beginTransaction();
        try {
            $stmtInsert = $pdo->prepare("
                INSERT INTO organ_requests (patient_id, hospital_id, organ_type, status, priority_score, request_date)
                VALUES (?, ?, ?, 'pending', ?, ?)
            ");
            $stmtInsert->execute([$patient_id, $hospital_id, $organ_type, $priority_score, $request_date]);
            $newReqId = (int)$pdo->lastInsertId();

            $stmtUpdatePat = $pdo->prepare("
                UPDATE patients 
                SET request_type = 'organ', organ_needed = ?, `condition` = ?, priority_score = ?, request_date = ?, status = 'pending' 
                WHERE patient_id = ?
            ");
            $stmtUpdatePat->execute([$organ_type, $condition, $priority_score, $request_date, $patient_id]);

            $pdo->commit();

            $_SESSION['success'] = "<div class='alert alert-success d-flex align-items-center'><i class='bi bi-check-circle-fill me-2'></i>Organ request <strong>#{$newReqId}</strong> for patient <strong>" . htmlspecialchars($patData['name']) . "</strong> (" . htmlspecialchars($organ_type) . ", Priority Score: <strong>{$priority_score}</strong>) registered successfully under your hospital queue!</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "<div class='alert alert-danger'>Error creating organ request: " . htmlspecialchars($e->getMessage()) . "</div>";
        }

        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // ── Handle Accept / Reject Organ Request (Step 5) ────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['handle_organ_request'])) {
        $request_id = (int) $_POST['request_id'];
        $organ_action = $_POST['organ_action'];

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE organ_requests SET status=? WHERE request_id=?")
                ->execute([$organ_action, $request_id]);

            if ($organ_action === 'fulfilled') {
                $stmtFetch = $pdo->prepare("SELECT patient_id, organ_type FROM organ_requests WHERE request_id=?");
                $stmtFetch->execute([$request_id]);
                $orgReq = $stmtFetch->fetch(PDO::FETCH_ASSOC);

                if ($orgReq) {
                    $organType = $orgReq['organ_type'];

                    // VALIDATION: Check units_available >= 1
                    $stmtCheck = $pdo->prepare("SELECT units_available FROM organ_inventory WHERE hospital_id=? AND organ_type=?");
                    $stmtCheck->execute([$hospital_id, $organType]);
                    $inv = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                    if (!$inv || $inv['units_available'] < 1) {
                        // IF NOT ENOUGH STOCK: Do NOT approve request. Keep status pending
                        $pdo->rollBack();
                        $_SESSION['error'] = "<div class='alert alert-danger'>Not enough stock for $organType. Request kept as pending.</div>";
                        header("Location: " . $_SERVER['PHP_SELF']);
                        exit();
                    }

                    $pdo->prepare("UPDATE patients SET status='fulfilled' WHERE patient_id=?")
                        ->execute([$orgReq['patient_id']]);

                    // PREVENT NEGATIVE STOCK / Reduce by 1 ONLY when fulfilled
                    $pdo->prepare("UPDATE organ_inventory SET units_available = GREATEST(units_available - 1, 0) WHERE hospital_id=? AND organ_type=?")
                        ->execute([$hospital_id, $organType]);
                }
                $_SESSION['success'] = "<div class='alert alert-success d-flex align-items-center'><i class='bi bi-check-circle-fill me-2'></i>Organ request <strong>accepted & fulfilled</strong>. Inventory deducted.</div>";
            } else {
                $_SESSION['success'] = "<div class='alert alert-warning d-flex align-items-center'><i class='bi bi-x-circle-fill me-2'></i>Organ request <strong>rejected</strong>.</div>";
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "<div class='alert alert-danger'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // ── Fetch Data ────────────────────────────────────────────────────────────
    $stmtHosp = $pdo->prepare("SELECT name, location, contact, specialization, license_no FROM hospitals WHERE hospital_id=?");
    $stmtHosp->execute([$hospital_id]);
    $hospitalData = $stmtHosp->fetch(PDO::FETCH_ASSOC);

    // Organ inventory for this hospital
    $stmtInv = $pdo->prepare("SELECT organ_type, units_available FROM organ_inventory WHERE hospital_id=? ORDER BY organ_type ASC");
    $stmtInv->execute([$hospital_id]);
    $myInventory = $stmtInv->fetchAll(PDO::FETCH_ASSOC);

    // Auto-seed inventory removed to ensure new users see ZERO data.

    // Build inventory lookup map
    $inventoryMap = [];
    foreach ($myInventory as $inv) {
        $inventoryMap[$inv['organ_type']] = (int) $inv['units_available'];
    }

    // Summary stats (Step 6)
    $stmtStats = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM organ_requests WHERE hospital_id=? GROUP BY status");
    $stmtStats->execute([$hospital_id]);
    $statsRaw = $stmtStats->fetchAll(PDO::FETCH_ASSOC);
    $stats = ['pending' => 0, 'fulfilled' => 0, 'rejected' => 0];
    $totalRequests = 0;
    foreach ($statsRaw as $row) {
        $stats[$row['status']] = (int) $row['cnt'];
        $totalRequests += (int) $row['cnt'];
    }

    // Pending organ requests for this hospital (Step 4)
    $stmtPending = $pdo->prepare("
        SELECT o.request_id, p.name AS patient_name, p.age, p.condition, p.blood_group, o.priority_score, o.organ_type, o.request_date
        FROM organ_requests o
        JOIN patients p ON o.patient_id = p.patient_id
        WHERE o.hospital_id=? AND o.status='pending'
        ORDER BY o.priority_score DESC, o.request_date ASC
    ");
    $stmtPending->execute([$hospital_id]);
    $pendingOrganRequests = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all registered patients for hospital search/select
    $stmtAllPatients = $pdo->query("
        SELECT patient_id, name, age, blood_group, contact, location, `condition`, status
        FROM patients
        ORDER BY name ASC
    ");
    $registeredPatients = $stmtAllPatients->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Dashboard - MediMatch</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fe;
            color: #333;
        }

        /* ── Sidebar ──────────────────────────────── */
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
            color: #0083b0;
            transform: translateX(5px);
        }

        /* ── Header ───────────────────────────────── */
        .header-bg {
            background: linear-gradient(135deg, #00b4db 0%, #0083b0 100%);
            color: white;
            padding: 2.5rem;
            border-bottom-left-radius: 30px;
            border-bottom-right-radius: 30px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(0, 131, 176, 0.2);
        }

        /* ── Sections ─────────────────────────────── */
        .content-section {
            display: block;
            opacity: 1;
            transition: opacity 0.3s ease-in-out;
        }

        .d-none-soft {
            display: none !important;
        }

        /* ── Cards ────────────────────────────────── */
        .card-custom {
            background: white;
            border: none;
            border-radius: 20px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.05);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .organ-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(0, 131, 176, 0.1);
        }

        .organ-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0, 131, 176, 0.15);
        }

        .status-high {
            color: #2e7d32;
        }

        .status-low {
            color: #f57c00;
        }

        .status-zero {
            color: #c62828;
        }

        .border-high {
            border-bottom: 6px solid #2e7d32;
        }

        .border-low {
            border-bottom: 6px solid #f57c00;
        }

        .border-zero {
            border-bottom: 6px solid #c62828;
        }

        /* Summary stat cards */
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(0, 131, 176, 0.08);
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(0, 131, 176, 0.12);
        }

        .stat-icon {
            font-size: 2.8rem;
            position: absolute;
            right: 15px;
            bottom: 8px;
            opacity: 0.08;
        }

        /* Organ tag badges */
        .organ-tag {
            background: #0083b0;
            color: white;
            padding: 0.3rem 0.9rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.8rem;
        }
    </style>
</head>

<body>

    <div class="row g-0">

        <!-- ── Left Sidebar ────────────────────────────────────────────────── -->
        <div class="col-md-3 col-lg-2 sidebar-wrapper d-none d-md-block">
            <div class="text-center px-3 mb-5">
                <h3 class="fw-bold text-dark"><i class="bi bi-heart-pulse-fill text-info me-2"></i>MediMatch</h3>
                <span class="badge bg-light text-secondary rounded-pill px-3 shadow-sm border">Hospital Portal</span>
            </div>

            <div class="px-3">
                <div class="nav-link-custom active" onclick="showSection('dashboard-section', this)">
                    <i class="bi bi-grid-fill me-3 fs-5"></i> Dashboard
                </div>
                <div class="nav-link-custom" onclick="showSection('inventory-section', this)">
                    <i class="bi bi-heart-pulse me-3 fs-5"></i> Organ Inventory
                </div>
                <div class="nav-link-custom" onclick="showSection('update-stock-section', this)">
                    <i class="bi bi-plus-circle-fill me-3 fs-5"></i> Update Stock
                </div>
                <div class="nav-link-custom" onclick="showSection('create-request-section', this)">
                    <i class="bi bi-person-plus-fill me-3 fs-5 text-primary"></i> Create Organ Request
                </div>
                <div class="nav-link-custom" onclick="showSection('requests-section', this)">
                    <i class="bi bi-clipboard-check-fill me-3 fs-5 text-info"></i> Requests
                    <?php if (count($pendingOrganRequests) > 0): ?>
                        <span
                            class="badge bg-danger rounded-pill ms-auto"><?php echo count($pendingOrganRequests); ?></span>
                    <?php endif; ?>
                </div>
                <div class="nav-link-custom" onclick="showSection('profile-section', this)">
                    <i class="bi bi-person-fill me-3 fs-5"></i> Profile
                </div>

                <hr class="my-4 text-muted">

                <a href="logout.php"
                    class="nav-link-custom text-danger text-decoration-none border border-danger border-opacity-25 rounded bg-danger bg-opacity-10">
                    <i class="bi bi-box-arrow-right me-3 fs-5"></i> Logout
                </a>
            </div>
        </div>

        <!-- ── Main Content ─────────────────────────────────────────────────── -->
        <div class="col-md-9 col-lg-10 bg-light" style="min-height:100vh;">

            <header class="header-bg mb-4">
                <div>
                    <h2 class="fw-bold mb-1"><span id="headerTitle">Dashboard Overview</span></h2>
                    <p class="lead mb-0 opacity-75 fs-6">Organ Inventory Management &amp; Request Handling</p>
                </div>
            </header>

            <div class="container px-4 pb-5">

                <?php if (!empty($message))
                    echo $message; ?>

                <!-- ══ DASHBOARD SECTION ══════════════════════════════════════════ -->
                <div id="dashboard-section" class="content-section">

                    <!-- Profile Summary Card -->
                    <div class="card-custom mb-4 border-start border-4 border-info">
                        <div class="row align-items-center">
                            <div class="col-md-auto mb-3 mb-md-0">
                                <div class="bg-info bg-opacity-10 text-info rounded-circle d-flex align-items-center justify-content-center shadow-sm"
                                    style="width:70px;height:70px;">
                                    <i class="bi bi-hospital fs-2"></i>
                                </div>
                            </div>
                            <div class="col-md">
                                <h4 class="fw-bold mb-1 text-dark">
                                    <?php echo htmlspecialchars($hospitalData['name'] ?? 'Hospital'); ?>
                                </h4>
                                <div class="d-flex flex-wrap gap-3 mt-2">
                                    <span class="badge bg-info rounded-pill px-3 shadow-sm"><i
                                            class="bi bi-star-fill me-1"></i><?php echo htmlspecialchars($hospitalData['specialization'] ?: 'General'); ?></span>
                                    <span class="text-muted small"><i
                                            class="bi bi-geo-alt-fill text-info me-1"></i><?php echo htmlspecialchars($hospitalData['location'] ?: 'Not Provided'); ?></span>
                                    <span class="text-muted small"><i
                                            class="bi bi-telephone-fill text-info me-1"></i><?php echo htmlspecialchars($hospitalData['contact'] ?: 'Not Provided'); ?></span>
                                    <span class="text-muted small"><i
                                            class="bi bi-patch-check-fill text-info me-1"></i>Lic:
                                        <?php echo htmlspecialchars($hospitalData['license_no'] ?: 'Pending'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Summary Stat Cards (Step 6) -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-3 col-sm-6">
                            <div class="stat-card">
                                <h6 class="text-uppercase text-muted fw-bold mb-2 small">Total Requests</h6>
                                <h2 class="fw-bold text-dark mb-0"><?php echo $totalRequests; ?></h2>
                                <i class="bi bi-clipboard-data stat-icon text-info"></i>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="stat-card">
                                <h6 class="text-uppercase text-muted fw-bold mb-2 small">Pending</h6>
                                <h2 class="fw-bold text-warning mb-0"><?php echo $stats['pending']; ?></h2>
                                <i class="bi bi-hourglass-split stat-icon text-warning"></i>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="stat-card">
                                <h6 class="text-uppercase text-muted fw-bold mb-2 small">Fulfilled</h6>
                                <h2 class="fw-bold text-success mb-0"><?php echo $stats['fulfilled']; ?></h2>
                                <i class="bi bi-check-circle-fill stat-icon text-success"></i>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="stat-card">
                                <h6 class="text-uppercase text-muted fw-bold mb-2 small">Rejected</h6>
                                <h2 class="fw-bold text-danger mb-0"><?php echo $stats['rejected']; ?></h2>
                                <i class="bi bi-x-circle-fill stat-icon text-danger"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Action Banner for Hospital-Initiated Organ Request -->
                    <div class="card-custom p-4 mb-4 bg-white border border-primary border-opacity-25 rounded-4 shadow-sm">
                        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                            <div class="d-flex align-items-center">
                                <div class="bg-primary bg-opacity-10 text-primary rounded-circle p-3 me-3 d-flex align-items-center justify-content-center" style="width:55px;height:55px;flex-shrink:0;">
                                    <i class="bi bi-person-plus-fill fs-3"></i>
                                </div>
                                <div>
                                    <h5 class="fw-bold text-dark mb-1">Evaluate &amp; Create Organ Request for Patient</h5>
                                    <p class="text-muted small mb-0">Hospitals initiate verified organ transplant requirements following patient clinical consultation. Automatically assigns priority score.</p>
                                </div>
                            </div>
                            <div>
                                <button type="button" class="btn btn-primary rounded-pill px-4 py-2 fw-bold text-nowrap shadow-sm" onclick="showSection('create-request-section', document.querySelectorAll('.nav-link-custom')[3])">
                                    <i class="bi bi-plus-circle-fill me-2"></i>Create Request for Patient
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Organ stock overview cards -->
                    <h5 class="fw-bold text-dark mb-4"><i class="bi bi-diagram-3-fill text-primary me-2"></i>Current
                        Organ Stock</h5>
                    <div class="row g-3">
                        <?php foreach ($myInventory as $inv):
                            $u = (int) $inv['units_available'];
                            $sc = $u === 0 ? 'status-zero' : ($u < 3 ? 'status-low' : 'status-high');
                            $bc = $u === 0 ? 'border-zero' : ($u < 3 ? 'border-low' : 'border-high');
                            ?>
                            <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6">
                                <div class="organ-card <?php echo $bc; ?>">
                                    <h5 class="fw-bold text-dark mb-2"><i
                                            class="bi bi-heart-pulse me-2 text-info"></i><?php echo htmlspecialchars($inv['organ_type']); ?>
                                    </h5>
                                    <div class="d-flex align-items-center justify-content-center">
                                        <h2 class="fw-bold mb-0 me-2 <?php echo $sc; ?>"><?php echo $u; ?></h2>
                                        <span class="text-muted fw-bold">Units</span>
                                    </div>
                                    <?php if ($u === 0): ?>
                                        <small class="text-danger fw-bold d-block mt-1">OUT OF STOCK</small>
                                    <?php elseif ($u < 3): ?>
                                        <small class="text-warning fw-bold d-block mt-1">LOW STOCK</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ══ ORGAN INVENTORY SECTION (Step 2) ══════════════════════════ -->
                <div id="inventory-section" class="content-section d-none-soft">
                    <div class="card-custom">
                        <h5 class="fw-bold text-dark mb-4"><i class="bi bi-heart-pulse text-info me-2"></i>Organ
                            Inventory — <?php echo htmlspecialchars($hospitalData['name'] ?? 'Your Hospital'); ?></h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead style="background-color:#eaf6fb;">
                                    <tr>
                                        <th>Organ Type</th>
                                        <th>Units Available</th>
                                        <th>Stock Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($myInventory as $inv):
                                        $u = (int) $inv['units_available'];
                                        $label = $u === 0 ? ['bg-danger', 'OUT OF STOCK'] : ($u < 3 ? ['bg-warning text-dark', 'LOW STOCK'] : ['bg-success', 'AVAILABLE']);
                                        ?>
                                        <tr>
                                            <td><span
                                                    class="organ-tag"><?php echo htmlspecialchars($inv['organ_type']); ?></span>
                                            </td>
                                            <td>
                                                <h5 class="mb-0 fw-bold"><?php echo $u; ?></h5>
                                            </td>
                                            <td><span
                                                    class="badge <?php echo $label[0]; ?> rounded-pill px-3"><?php echo $label[1]; ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($myInventory)): ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted py-4">No inventory data found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ══ UPDATE STOCK SECTION (Step 3) ═════════════════════════════ -->
                <div id="update-stock-section" class="content-section d-none-soft">
                    <div class="card-custom">
                        <h5 class="fw-bold mb-2 text-dark"><i class="bi bi-plus-circle-fill text-info me-2"></i>Update
                            Organ Stock</h5>
                        <p class="text-muted mb-4 border-bottom pb-3">Choose an organ type and enter the number of units
                            to add to your inventory. Existing records are incremented, new records are created
                            automatically.</p>

                        <form action="hospital_dashboard.php" method="POST">
                            <div class="row g-4">
                                <div class="col-md-5">
                                    <label class="form-label fw-bold text-muted small">Organ Type</label>
                                    <select name="organ_type" class="form-select form-select-lg" required>
                                        <option value="" disabled selected>Select Organ...</option>
                                        <option value="Kidney">Kidney</option>
                                        <option value="Liver">Liver</option>
                                        <option value="Heart">Heart</option>
                                        <option value="Lungs">Lungs</option>
                                        <option value="Pancreas">Pancreas</option>
                                        <option value="Cornea">Cornea</option>
                                        <option value="Bone Marrow">Bone Marrow</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold text-muted small">Units to Add</label>
                                    <input type="number" name="units_to_add" class="form-control form-control-lg"
                                        min="1" placeholder="e.g. 5" required>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" name="update_organ_stock"
                                        class="btn btn-primary btn-lg rounded-pill px-5 shadow-sm fw-bold w-100">
                                        <i class="bi bi-cloud-arrow-up me-2"></i>Update Stock
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Current stock summary below form -->
                    <div class="card-custom">
                        <h6 class="fw-bold text-muted mb-3"><i class="bi bi-list-ul me-2"></i>Current Stock Levels</h6>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Organ</th>
                                        <th>Units</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($myInventory as $inv):
                                        $u = (int) $inv['units_available'];
                                        $cls = $u === 0 ? 'text-danger' : ($u < 3 ? 'text-warning' : 'text-success');
                                        ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo htmlspecialchars($inv['organ_type']); ?></td>
                                            <td><span class="fw-bold fs-5 <?php echo $cls; ?>"><?php echo $u; ?></span></td>
                                            <td><?php echo $u === 0 ? "<span class='badge bg-danger'>Out</span>" : ($u < 3 ? "<span class='badge bg-warning text-dark'>Low</span>" : "<span class='badge bg-success'>OK</span>"); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ══ CREATE ORGAN REQUEST FOR PATIENT SECTION ══════════════════ -->
                <div id="create-request-section" class="content-section d-none-soft">
                    <div class="card-custom">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <h5 class="fw-bold m-0 text-dark"><i class="bi bi-person-plus-fill text-primary me-2"></i>Create Organ Request for Patient</h5>
                                <p class="text-muted small mb-0 mt-1">Create a verified organ transplant request after clinical evaluation of the patient.</p>
                            </div>
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-3 py-2 fw-bold">Hospital Verified</span>
                        </div>

                        <div class="alert alert-primary border-0 rounded-4 d-flex align-items-center my-4 shadow-sm">
                            <i class="bi bi-shield-fill-check me-3 fs-4 text-primary"></i>
                            <div>
                                <strong class="d-block">Medical Protocol Notice</strong>
                                <span class="small text-muted">Only authorized hospital staff should initiate organ requests. This action creates a verified, high-priority entry in the MediMatch system. The priority score is automatically calculated.</span>
                            </div>
                        </div>

                        <form action="hospital_dashboard.php" method="POST" id="createOrganRequestForm">
                            <div class="row g-4">

                                <!-- Patient Selection -->
                                <div class="col-md-12">
                                    <label class="form-label fw-bold text-muted small">Patient <span class="text-danger">*</span></label>
                                    <select name="patient_id" id="patientSelect" class="form-select form-select-lg" required onchange="showPatientDetails(this.value)">
                                        <option value="" disabled selected>— Select a registered patient —</option>
                                        <?php foreach ($registeredPatients as $rp): ?>
                                            <option value="<?php echo (int)$rp['patient_id']; ?>"
                                                data-name="<?php echo htmlspecialchars($rp['name'], ENT_QUOTES); ?>"
                                                data-age="<?php echo (int)$rp['age']; ?>"
                                                data-blood="<?php echo htmlspecialchars($rp['blood_group'], ENT_QUOTES); ?>"
                                                data-condition="<?php echo htmlspecialchars($rp['condition'] ?? 'N/A', ENT_QUOTES); ?>"
                                                data-status="<?php echo htmlspecialchars($rp['status'] ?? 'N/A', ENT_QUOTES); ?>"
                                                data-contact="<?php echo htmlspecialchars($rp['contact'] ?? '', ENT_QUOTES); ?>"
                                                data-location="<?php echo htmlspecialchars($rp['location'] ?? '', ENT_QUOTES); ?>">
                                                <?php echo htmlspecialchars($rp['name']); ?> — Patient #<?php echo (int)$rp['patient_id']; ?> — <?php echo htmlspecialchars($rp['blood_group']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Search and select the patient for whom this organ requirement is being created.</div>
                                </div>

                                <!-- Patient Info Card (shown dynamically) -->
                                <div class="col-md-12" id="patientInfoCard" style="display:none;">
                                    <div class="p-4 bg-light border border-primary border-opacity-25 rounded-4 shadow-sm">
                                        <h6 class="fw-bold text-primary mb-3"><i class="bi bi-person-badge-fill me-2"></i>Selected Patient Details</h6>
                                        <div class="row g-3">
                                            <div class="col-6 col-md-3">
                                                <div class="text-muted small fw-bold text-uppercase">Name</div>
                                                <div class="fw-bold text-dark" id="piName">—</div>
                                            </div>
                                            <div class="col-6 col-md-3">
                                                <div class="text-muted small fw-bold text-uppercase">Age</div>
                                                <div class="fw-bold text-dark" id="piAge">—</div>
                                            </div>
                                            <div class="col-6 col-md-2">
                                                <div class="text-muted small fw-bold text-uppercase">Blood Group</div>
                                                <div id="piBlood">—</div>
                                            </div>
                                            <div class="col-6 col-md-2">
                                                <div class="text-muted small fw-bold text-uppercase">Condition</div>
                                                <div id="piCondition">—</div>
                                            </div>
                                            <div class="col-6 col-md-2">
                                                <div class="text-muted small fw-bold text-uppercase">Status</div>
                                                <div id="piStatus">—</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Organ Required -->
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-muted small">Organ Required <span class="text-danger">*</span></label>
                                    <select name="organ_type" class="form-select form-select-lg" required>
                                        <option value="" disabled selected>— Select organ type —</option>
                                        <option value="Kidney">Kidney</option>
                                        <option value="Liver">Liver</option>
                                        <option value="Heart">Heart</option>
                                        <option value="Lungs">Lungs</option>
                                        <option value="Pancreas">Pancreas</option>
                                        <option value="Cornea">Cornea</option>
                                        <option value="Bone Marrow">Bone Marrow</option>
                                    </select>
                                </div>

                                <!-- Clinical Condition / Priority -->
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-muted small">Clinical Condition / Priority <span class="text-danger">*</span></label>
                                    <select name="condition" class="form-select form-select-lg" required>
                                        <option value="" disabled selected>— Select condition —</option>
                                        <option value="normal">Normal</option>
                                        <option value="urgent">Urgent</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                    <div class="form-text"><i class="bi bi-info-circle me-1 text-primary"></i>Priority score is automatically calculated based on clinical condition, patient age, waiting time and organ type.</div>
                                </div>

                                <!-- Submit -->
                                <div class="col-12 mt-2">
                                    <button type="submit" name="create_patient_organ_request"
                                        class="btn btn-primary rounded-pill px-5 py-2 shadow-sm fw-bold fs-6">
                                        <i class="bi bi-plus-circle-fill me-2"></i>Create Organ Request
                                    </button>
                                    <span class="text-muted small ms-3"><i class="bi bi-lock-fill me-1"></i>Request will be logged with your hospital ID and submitted to MediMatch priority queue.</span>
                                </div>

                            </div>
                        </form>
                    </div>
                </div>

                <!-- ══ REQUESTS SECTION (Step 4 & 5) ═════════════════════════════ -->
                <div id="requests-section" class="content-section d-none-soft">
                    <div class="card-custom">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div>
                                <h5 class="fw-bold m-0 text-dark"><i
                                        class="bi bi-clipboard-check-fill text-info me-2"></i>Pending Organ Requests
                                </h5>
                                <p class="text-muted small mb-0 mt-1">Accept to fulfil and deduct 1 unit from your
                                    inventory. Patients are sorted by priority score.</p>
                            </div>
                            <?php if (count($pendingOrganRequests) > 0): ?>
                                <span
                                    class="badge bg-danger rounded-pill px-3 py-2 fs-6"><?php echo count($pendingOrganRequests); ?>
                                    Pending</span>
                            <?php endif; ?>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-muted text-uppercase small">Patient</th>
                                        <th class="text-muted text-uppercase small">Blood Group</th>
                                        <th class="text-muted text-uppercase small">Organ</th>
                                        <th class="text-muted text-uppercase small">Priority</th>
                                        <th class="text-muted text-uppercase small text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingOrganRequests as $por): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <i class="bi bi-person-circle fs-4 text-secondary me-2"></i>
                                                    <div>
                                                        <span
                                                            class="fw-bold text-dark d-block"><?php echo htmlspecialchars($por['patient_name']); ?></span>
                                                        <small class="text-muted">Req
                                                            #<?php echo $por['request_id']; ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><span
                                                    class="badge bg-dark rounded-pill px-3"><?php echo htmlspecialchars($por['blood_group']); ?></span>
                                            </td>
                                            <td><span
                                                    class="organ-tag"><?php echo htmlspecialchars($por['organ_type']); ?></span>
                                            </td>
                                            <td>
                                                <?php
                                                // Standardized calculation leveraging priority_calc.php logic
                                                $ps = calculatePriority(
                                                    $por['age'], 
                                                    $por['condition'], 
                                                    'organ', 
                                                    $por['organ_type'], 
                                                    $por['request_date']
                                                );
                                                $pc = $ps >= 150 ? 'text-danger' : ($ps >= 100 ? 'text-warning' : 'text-success'); ?>
                                                <h5 class="mb-0 fw-bold <?php echo $pc; ?>"><?php echo $ps; ?></h5>
                                            </td>
                                            <td class="text-end">
                                                <?php
                                                $stockOk = isset($inventoryMap[$por['organ_type']]) && $inventoryMap[$por['organ_type']] > 0;
                                                ?>
                                                <?php if ($stockOk): ?>
                                                    <form method="POST" class="d-inline"
                                                        onsubmit="return confirm('Accept this request? 1 unit of <?php echo addslashes($por['organ_type']); ?> will be deducted from inventory.')">
                                                        <input type="hidden" name="request_id"
                                                            value="<?php echo $por['request_id']; ?>">
                                                        <input type="hidden" name="organ_action" value="fulfilled">
                                                        <button type="submit" name="handle_organ_request"
                                                            class="btn btn-sm me-1"
                                                            style="background:linear-gradient(135deg,#11998e,#38ef7d);color:white;border-radius:50px;font-weight:600;padding:0.4rem 1.2rem;border:none;">
                                                            <i class="bi bi-check2-all me-1"></i>Accept
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary rounded-pill px-3 me-1">No Stock</span>
                                                <?php endif; ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="request_id"
                                                        value="<?php echo $por['request_id']; ?>">
                                                    <input type="hidden" name="organ_action" value="rejected">
                                                    <button type="submit" name="handle_organ_request"
                                                        class="btn btn-sm btn-outline-danger rounded-pill px-3">
                                                        <i class="bi bi-x-circle me-1"></i>Reject
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($pendingOrganRequests)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-5 text-muted">
                                                <i class="bi bi-inbox fs-1 d-block mb-3 opacity-25"></i>
                                                No pending organ requests from patients.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ══ PROFILE SECTION (Step 7) ══════════════════════════════════ -->
                <div id="profile-section" class="content-section d-none-soft">
                    <div class="card-custom">
                        <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-person-fill text-info me-2"></i>Hospital
                            Profile</h5>
                        <form action="hospital_dashboard.php" method="POST">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">Hospital Name</label>
                                    <input type="text" name="name" class="form-control"
                                        value="<?php echo htmlspecialchars($hospitalData['name'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">Location</label>
                                    <input type="text" name="location" class="form-control"
                                        value="<?php echo htmlspecialchars($hospitalData['location'] ?? ''); ?>"
                                        required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">Contact (digits only)</label>
                                    <input type="text" name="contact" class="form-control"
                                        value="<?php echo htmlspecialchars($hospitalData['contact'] ?? ''); ?>" required
                                        placeholder="e.g. 9876543210">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">Specialization</label>
                                    <input type="text" name="specialization" class="form-control"
                                        value="<?php echo htmlspecialchars($hospitalData['specialization'] ?? ''); ?>"
                                        placeholder="e.g. Cardiology, Multi-organ Transplant">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label fw-bold small text-muted">License Number</label>
                                    <input type="text" name="license_no" class="form-control"
                                        value="<?php echo htmlspecialchars($hospitalData['license_no'] ?? ''); ?>"
                                        required placeholder="Enter regulatory license number">
                                </div>
                                <div class="col-12 mt-4">
                                    <button type="submit" name="update_profile"
                                        class="btn btn-info text-white rounded-pill px-5 shadow-sm fw-bold">
                                        Update Profile
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

            </div><!-- /container -->
            <!-- Footer -->
            <footer class="text-center py-4 text-muted mt-5" style="border-top: 1px solid rgba(0,0,0,0.05);">
                &copy; 2026 MediMatch | Saving Lives Through Smart Matching
            </footer>
        </div><!-- /col-md-9 -->
    </div><!-- /row -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showSection(sectionId, element) {
            document.querySelectorAll('.content-section').forEach(sec => sec.classList.add('d-none-soft'));
            document.querySelectorAll('.nav-link-custom').forEach(nav => nav.classList.remove('active'));

            document.getElementById(sectionId).classList.remove('d-none-soft');
            if (element) element.classList.add('active');

            const titles = {
                'dashboard-section': 'Dashboard Overview',
                'inventory-section': 'Organ Inventory',
                'update-stock-section': 'Update Stock',
                'create-request-section': 'Create Organ Request',
                'requests-section': 'Patient Organ Requests',
                'profile-section': 'Hospital Profile'
            };
            document.getElementById('headerTitle').innerText = titles[sectionId] || 'Dashboard Overview';
        }

        function showPatientDetails(patientId) {
            const card = document.getElementById('patientInfoCard');
            if (!patientId) {
                card.style.display = 'none';
                return;
            }

            // Find the selected option
            const select = document.getElementById('patientSelect');
            const selectedOption = select.options[select.selectedIndex];

            if (!selectedOption || !selectedOption.value) {
                card.style.display = 'none';
                return;
            }

            const name      = selectedOption.getAttribute('data-name') || '—';
            const age       = selectedOption.getAttribute('data-age') || '—';
            const blood     = selectedOption.getAttribute('data-blood') || '—';
            const condition = selectedOption.getAttribute('data-condition') || '—';
            const status    = selectedOption.getAttribute('data-status') || '—';

            document.getElementById('piName').textContent = name;
            document.getElementById('piAge').textContent = age + ' years';

            const condMap = { critical: 'danger', urgent: 'warning', normal: 'success' };
            const condColor = condMap[condition.toLowerCase()] || 'secondary';
            document.getElementById('piBlood').innerHTML = `<span class="badge bg-danger rounded-pill px-3">${escapeHtmlH(blood)}</span>`;
            document.getElementById('piCondition').innerHTML = `<span class="badge bg-${condColor} rounded-pill px-3">${escapeHtmlH(condition)}</span>`;

            const stMap = { pending: 'warning', approved: 'primary', fulfilled: 'success', waiting_for_donor: 'info', donor_matched: 'info', rejected: 'danger' };
            const stColor = stMap[status.toLowerCase()] || 'secondary';
            document.getElementById('piStatus').innerHTML = `<span class="badge bg-${stColor} rounded-pill px-3">${escapeHtmlH(status)}</span>`;

            card.style.display = 'block';
        }

        function escapeHtmlH(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    </script>
</body>

</html>