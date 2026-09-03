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
    
    $bank_id = $_SESSION['ref_id'];

    // Handle Old Stock Update (Action modal) [Kept for backwards compatibility]
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock'])) {
        $inventory_id = $_POST['inventory_id'];
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
        
        $message = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-check-circle-fill me-2'></i>Inventory dynamically adjusted!</div>";
        $_SESSION['success'] = $message;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Step 4: Handle NEW Stock Update Feature
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock_direct'])) {
        $bg = $_POST['blood_group'];
        $units = (int)$_POST['units_available'];
        $expiry = $_POST['expiry_date'];
        
        $checkStmt = $pdo->prepare("SELECT * FROM blood_inventory WHERE blood_group = ? AND bank_id = ?");
        $checkStmt->execute([$bg, $bank_id]);
        
        if ($checkStmt->rowCount() > 0) {
            $updStmt = $pdo->prepare("UPDATE blood_inventory SET units_available = units_available + ?, expiry_date = ? WHERE blood_group = ? AND bank_id = ?");
            $updStmt->execute([$units, $expiry, $bg, $bank_id]);
        } else {
            $insStmt = $pdo->prepare("INSERT INTO blood_inventory (bank_id, blood_group, units_available, expiry_date) VALUES (?, ?, ?, ?)");
            $insStmt->execute([$bank_id, $bg, $units, $expiry]);
        }
        
        $message = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-check-circle-fill me-2'></i>Stock updated successfully</div>";
        $_SESSION['success'] = $message;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Step 4 & 5: Handle Fulfill Action
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fulfill_request'])) {
        $request_id = $_POST['request_id'];
        
        $pdo->beginTransaction();
        try {
            // Get blood request details to know units_needed
            $stmtReq = $pdo->prepare("SELECT units_needed, blood_group FROM blood_requests WHERE request_id=?");
            $stmtReq->execute([$request_id]);
            $bloodReq = $stmtReq->fetch(PDO::FETCH_ASSOC);

            if ($bloodReq) {
                $unitsNeeded = (int) $bloodReq['units_needed'];
                $bg = $bloodReq['blood_group'];

                // VALIDATION: Check units_available >= units_needed
                $stmtInv = $pdo->prepare("SELECT units_available FROM blood_inventory WHERE bank_id=? AND blood_group=?");
                $stmtInv->execute([$bank_id, $bg]);
                $inv = $stmtInv->fetch(PDO::FETCH_ASSOC);

                if (!$inv || $inv['units_available'] < $unitsNeeded) {
                    $pdo->rollBack();
                    $_SESSION['error'] = "<div class='alert alert-danger'>Not enough inventory to fulfill request. Need: $unitsNeeded. Have: " . ($inv ? $inv['units_available'] : 0) . ". Setting to pending.</div>";
                    header("Location: bloodbank_dashboard.php");
                    exit();
                }

                // Apply fixes: Update query: units_available = GREATEST(units_available - units_needed, 0)
                $stmtUpdateInv = $pdo->prepare("UPDATE blood_inventory SET units_available = GREATEST(units_available - ?, 0) WHERE bank_id=? AND blood_group=?");
                $stmtUpdateInv->execute([$unitsNeeded, $bank_id, $bg]);
            }

            // STEP 4: Update blood_requests
            $stmtFulfillReq = $pdo->prepare("UPDATE blood_requests SET status = 'fulfilled', bank_id = ? WHERE request_id = ?");
            $stmtFulfillReq->execute([$bank_id, $request_id]);

            // STEP 5: Update patients status
            $stmtUpdatePatient = $pdo->prepare("
                UPDATE patients 
                SET status = 'fulfilled' 
                WHERE patient_id = (SELECT patient_id FROM blood_requests WHERE request_id = ?)
            ");
            $stmtUpdatePatient->execute([$request_id]);

            $pdo->commit();
            
            // STEP 6: PRG Pattern Redirection
            header("Location: bloodbank_dashboard.php");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "<div class='alert alert-danger'>Error fulfilling request: " . htmlspecialchars($e->getMessage()) . "</div>";
            header("Location: bloodbank_dashboard.php");
            exit();
        }
    }

    // Handle Profile Update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
        $name = $_POST['name'];
        $location = $_POST['location'];
        $contact = $_POST['contact'];
        $license_no = $_POST['license_no'];
        $capacity = (int)$_POST['capacity'];

        // Basic validation for contact: allow only numbers
        if (!empty($contact) && !is_numeric($contact)) {
            $_SESSION['error'] = "<div class='alert alert-danger'>Contact number must contain only digits.</div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

        // License is required as per Step 8
        if (empty($license_no)) {
            $_SESSION['error'] = "<div class='alert alert-danger'>License number is required for blood banks.</div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("UPDATE blood_banks SET name = ?, location = ?, contact = ?, license_no = ?, capacity = ? WHERE bank_id = ?");
            $stmt->execute([$name, $location, $contact, $license_no, $capacity, $bank_id]);
            
            $_SESSION['success'] = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-check-circle-fill me-2'></i>Blood Bank profile updated successfully!</div>";
        } catch (Exception $e) {
            $_SESSION['error'] = "<div class='alert alert-danger'>Error updating profile: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Fetch Blood Bank Data
    $stmtBank = $pdo->prepare("SELECT name, location, contact, license_no, capacity FROM blood_banks WHERE bank_id = ?");
    $stmtBank->execute([$bank_id]);
    $bankData = $stmtBank->fetch(PDO::FETCH_ASSOC);

    // Prepare Base Blood Groups and Fetch Aggregated View for Dashboard-Section
    $groups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    $stmtAgg = $pdo->prepare("SELECT blood_group, SUM(units_available) as total_units FROM blood_inventory WHERE bank_id = ? GROUP BY blood_group");
    $stmtAgg->execute([$bank_id]);
    $aggResult = $stmtAgg->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $totalInventory = [];
    $lowStockAlerts = [];
    
    foreach ($groups as $g) {
        $units = isset($aggResult[$g]) ? (int)$aggResult[$g] : 0;
        $totalInventory[$g] = $units;
        if ($units == 0) {
            $lowStockAlerts[] = "$g (Out of Stock)";
        } elseif ($units < 5) {
            $lowStockAlerts[] = "$g (Low : $units units)";
        }
    }

    // Step 1: Fetch Existing Inventory for Inventory-Section (grouped by bank)
    $stmtInv = $pdo->prepare("
        SELECT b.bank_id, b.name, b.location, b.contact, i.blood_group, i.units_available, i.expiry_date
        FROM blood_inventory i
        JOIN blood_banks b ON i.bank_id = b.bank_id
        WHERE i.bank_id = ?
        ORDER BY b.bank_id, i.blood_group
    ");
    $stmtInv->execute([$bank_id]);
    $inventoryRaw = $stmtInv->fetchAll(PDO::FETCH_ASSOC);

    // Group inventory data by bank
    $groupedInventory = [];
    foreach ($inventoryRaw as $row) {
        $bid = $row['bank_id'];
        if (!isset($groupedInventory[$bid])) {
            $groupedInventory[$bid] = [
                'name'     => $row['name'],
                'location' => $row['location'],
                'contact'  => $row['contact'],
                'bloods'   => []
            ];
        }
        $groupedInventory[$bid]['bloods'][] = [
            'group'   => $row['blood_group'],
            'units'   => (int)$row['units_available'],
            'expiry'  => $row['expiry_date']
        ];
    }

    // Step 3: Fetch Expiry Alerts logic
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

    // Step 4: Fix Blood Bank Query (Priority Sorted)
    $queryBloodRequests = "
        SELECT br.request_id, br.patient_name as name, br.blood_group, br.units_needed, br.status, br.priority_score
        FROM blood_requests br
        WHERE br.status = 'pending'
        ORDER BY br.priority_score DESC, br.request_date ASC
    ";
    $pendingBloodRequests = $pdo->query($queryBloodRequests)->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Connection Error: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blood Bank Dashboard - MediMatch</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
            box-shadow: 2px 0 15px rgba(0,0,0,0.03);
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
        .nav-link-custom:hover, .nav-link-custom.active {
            background-color: #f8f9fe;
            color: #d32f2f;
            transform: translateX(5px);
        }

        /* Main Content Styling */
        .header-bg {
            background: linear-gradient(135deg, #d32f2f 0%, #ff5252 100%);
            color: white;
            padding: 2.5rem;
            border-bottom-left-radius: 30px;
            border-bottom-right-radius: 30px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px rgba(211, 47, 47, 0.2);
        }
        
        .content-section {
            display: block;
            opacity: 1;
            transition: opacity 0.3s ease-in-out;
        }
        .d-none-soft {
            display: none !important;
        }

        /* Cards and Elements */
        .card-custom {
            background: white;
            border: none;
            border-radius: 20px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.05);
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .blood-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(211,47,47,0.1);
        }

        .blood-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(211, 47, 47, 0.15);
        }

        .status-high { color: #2e7d32; }
        .status-low { color: #f57c00; }
        .status-zero { color: #c62828; }

        .border-high { border-bottom: 6px solid #2e7d32; }
        .border-low { border-bottom: 6px solid #f57c00; }
        .border-zero { border-bottom: 6px solid #c62828; }

        /* Inventory grouped card styles */
        .inv-bank-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            overflow: hidden;
            margin-bottom: 1rem;
            border: 1px solid rgba(211,47,47,0.08);
            transition: box-shadow 0.25s ease;
        }
        .inv-bank-card:hover {
            box-shadow: 0 8px 30px rgba(211,47,47,0.13);
        }
        .inv-bank-header {
            cursor: pointer;
            padding: 1rem 1.25rem;
            background: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.2s;
            user-select: none;
        }
        .inv-bank-header:hover { background: #fff5f5; }
        .inv-bank-body {
            display: none;
            background: #fafafa;
            border-top: 1px solid #f5e0e0;
            padding: 1rem 1.25rem;
            animation: invFadeIn 0.25s ease;
        }
        .inv-bank-body.open { display: block; }
        @keyframes invFadeIn {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .inv-toggle-icon {
            transition: transform 0.3s ease;
            font-size: 1rem;
            color: #aaa;
        }
        .inv-toggle-icon.rotated { transform: rotate(180deg); }

        .badge-blood {
            background-color: #d32f2f;
            padding: 0.5rem 1.2rem;
            border-radius: 50px;
            color: white;
            font-weight: 600;
        }
    </style>
</head>
<body>

    <div class="row g-0">
        
        <!-- Left Sidebar Navigation -->
        <div class="col-md-3 col-lg-2 sidebar-wrapper d-none d-md-block">
            <div class="text-center px-3 mb-5">
                <h3 class="fw-bold text-dark"><i class="bi bi-droplet-half text-danger me-2"></i>MediMatch</h3>
                <span class="badge bg-light text-secondary rounded-pill px-3 shadow-sm border">Blood Bank</span>
            </div>
            
            <div class="px-3">
                <div class="nav-link-custom active" onclick="showSection('dashboard-section', this)">
                    <i class="bi bi-grid-fill me-3 fs-5"></i> Dashboard
                </div>
                <div class="nav-link-custom" onclick="showSection('inventory-section', this)">
                    <i class="bi bi-box-seam-fill me-3 fs-5"></i> Inventory
                </div>
                <div class="nav-link-custom" onclick="showSection('update-section', this)">
                    <i class="bi bi-cloud-arrow-up-fill me-3 fs-5"></i> Update Stock
                </div>
                <div class="nav-link-custom" onclick="showSection('profile-section', this)">
                    <i class="bi bi-person-fill me-3 fs-5"></i> My Profile
                </div>
                <div class="nav-link-custom" onclick="showSection('expiry-section', this)">
                    <i class="bi bi-exclamation-triangle-fill me-3 fs-5 text-warning"></i> Expiry Alerts
                </div>
                <div class="nav-link-custom" onclick="showSection('requests-section', this)">
                    <i class="bi bi-person-lines-fill me-3 fs-5 text-success"></i> Requests
                </div>
                
                <hr class="my-4 text-muted">
                
                <a href="logout.php" class="nav-link-custom text-danger text-decoration-none border border-danger border-opacity-25 rounded bg-danger bg-opacity-10 mt-auto">
                    <i class="bi bi-box-arrow-right me-3 fs-5"></i> Logout
                </a>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="col-md-9 col-lg-10 bg-light" style="min-height: 100vh;">
            
            <header class="header-bg mb-4">
                <div>
                    <h2 class="fw-bold mb-1"><span id="headerTitle">Dashboard Overview</span></h2>
                    <p class="lead mb-0 opacity-75 fs-6">Global Inventory Management & Monitoring</p>
                </div>
            </header>

            <div class="container px-4 pb-5">

                <?php if (!empty($message)) echo $message; ?>

                <!-- DASHBOARD SECTION -->
                <div id="dashboard-section" class="content-section">
                    <!-- Profile Summary Card -->
                    <div class="card-custom mb-4 border-start border-4 border-danger">
                        <div class="row align-items-center">
                            <div class="col-md-auto mb-3 mb-md-0">
                                <div class="bg-danger bg-opacity-10 text-danger rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 70px; height: 70px;">
                                    <i class="bi bi-droplet-half fs-2"></i>
                                </div>
                            </div>
                            <div class="col-md">
                                <h4 class="fw-bold mb-1 text-dark"><?php echo htmlspecialchars($bankData['name'] ?? 'Blood Bank'); ?></h4>
                                <div class="d-flex flex-wrap gap-3 mt-2">
                                    <span class="badge bg-danger rounded-pill px-3 shadow-sm"><i class="bi bi-box-fill me-1"></i>Cap: <?php echo htmlspecialchars($bankData['capacity'] ?? '0'); ?> Units</span>
                                    <span class="text-muted small"><i class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo htmlspecialchars($bankData['location'] ?: 'Not Provided'); ?></span>
                                    <span class="text-muted small"><i class="bi bi-telephone-fill text-danger me-1"></i><?php echo htmlspecialchars($bankData['contact'] ?: 'Not Provided'); ?></span>
                                    <span class="text-muted small"><i class="bi bi-patch-check-fill text-danger me-1"></i>Lic: <?php echo htmlspecialchars($bankData['license_no'] ?: 'Pending'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h5 class="fw-bold text-dark mb-4"><i class="bi bi-diagram-3-fill text-primary me-2"></i>Aggregated System Stock</h5>
                    
                    <div class="row g-3 mb-4">
                        <?php foreach($totalInventory as $group => $units): ?>
                            <?php 
                                $statClass = 'status-high';
                                $borderClass = 'border-high';
                                if ($units == 0) {
                                    $statClass = 'status-zero';
                                    $borderClass = 'border-zero';
                                } elseif ($units < 5) {
                                    $statClass = 'status-low';
                                    $borderClass = 'border-low';
                                }
                            ?>
                            <div class="col-xl-3 col-lg-4 col-md-6 col-sm-6">
                                <div class="blood-card <?php echo $borderClass; ?>">
                                    <h4 class="fw-bold text-dark mb-2"><?php echo htmlspecialchars($group); ?></h4>
                                    <div class="d-flex align-items-center justify-content-center">
                                        <h2 class="fw-bold mb-0 me-2 <?php echo $statClass; ?>"><?php echo $units; ?></h2>
                                        <span class="text-muted fw-bold">Units</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- INVENTORY SECTION -->
                <div id="inventory-section" class="content-section d-none-soft">
                    <div class="card-custom">
                        <h5 class="fw-bold text-dark mb-1"><i class="bi bi-box-seam-fill text-danger me-2"></i>Global Blood Inventory</h5>
                        <p class="text-muted small mb-4">Click a bank card to expand and view available blood groups and units.</p>

                        <?php if (empty($groupedInventory)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-droplet fs-1 d-block mb-2 opacity-25"></i>No blood inventory data available in the system.
                        </div>
                        <?php endif; ?>

                        <?php foreach ($groupedInventory as $bid => $bank): ?>
                        <div class="inv-bank-card">

                            <!-- Bank Header (clickable toggle) -->
                            <div class="inv-bank-header" id="inv-header-<?php echo $bid; ?>"
                                 onclick="toggleInvCard('inv-body-<?php echo $bid; ?>', 'inv-icon-<?php echo $bid; ?>')"
                                 onmouseover="this.style.background='#fff5f5'" onmouseout="this.style.background=''">
                                <div class="d-flex align-items-center">
                                    <div class="bg-danger bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-3"
                                         style="width:44px;height:44px;flex-shrink:0">
                                        <i class="bi bi-droplet-fill text-danger fs-5"></i>
                                    </div>
                                    <div>
                                        <h6 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($bank['name']); ?></h6>
                                        <small class="text-muted">
                                            <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($bank['location']); ?>
                                            <?php if (!empty($bank['contact'])): ?>
                                            &nbsp;·&nbsp;<i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($bank['contact']); ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-danger rounded-pill"><?php echo count($bank['bloods']); ?> types</span>
                                    <i class="bi bi-chevron-down inv-toggle-icon" id="inv-icon-<?php echo $bid; ?>"></i>
                                </div>
                            </div>

                            <!-- Expandable blood group detail panel -->
                            <div class="inv-bank-body" id="inv-body-<?php echo $bid; ?>">
                                <div class="row g-2">
                                    <?php foreach ($bank['bloods'] as $b):
                                        $uc = $b['units'] === 0 ? 'text-danger' : ($b['units'] < 5 ? 'text-warning fw-bold' : 'text-success');
                                        $dotColor = $b['units'] === 0 ? '#dc3545' : ($b['units'] < 5 ? '#fd7e14' : '#198754');
                                    ?>
                                    <div class="col-6 col-md-3">
                                        <div class="p-3 bg-white border rounded-3 text-center shadow-sm"
                                             style="border-bottom: 3px solid <?php echo $dotColor; ?> !important;">
                                            <span class="badge bg-danger rounded-pill px-3 mb-2"><?php echo htmlspecialchars($b['group']); ?></span>
                                            <div class="fw-bold <?php echo $uc; ?> fs-5"><?php echo $b['units']; ?></div>
                                            <small class="text-muted fw-normal">units</small>
                                            <?php if (!empty($b['expiry'])): ?>
                                            <div class="mt-1">
                                                <small class="text-muted" style="font-size:0.7rem;">
                                                    <i class="bi bi-calendar2-x me-1"></i><?php echo htmlspecialchars($b['expiry']); ?>
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

                <!-- UPDATE STOCK SECTION -->
                <div id="update-section" class="content-section d-none-soft">
                    <div class="card-custom">
                        <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-cloud-arrow-up-fill text-primary me-2"></i>Overwrite Direct Stock</h5>
                        <p class="text-muted mb-4 border-bottom pb-3">Executing changes here directly overwrites your branch's database metrics. Be absolutely sure of your quantities and expiry dates before applying.</p>
                        
                        <form action="bloodbank_dashboard.php" method="POST">
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold text-muted small">Blood Group</label>
                                    <select name="blood_group" class="form-select form-select-lg" required>
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
                                <div class="col-md-4">
                                    <label class="form-label fw-bold text-muted small">Units Available</label>
                                    <input type="number" name="units_available" class="form-control form-control-lg" min="0" placeholder="e.g. 50" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold text-muted small">Expiry Date</label>
                                    <input type="date" name="expiry_date" class="form-control form-control-lg" required>
                                </div>
                            </div>
                            <button type="submit" name="update_stock_direct" class="btn btn-primary btn-lg rounded-pill px-5 shadow-sm fw-bold">Update Stock Record</button>
                        </form>
                    </div>
                </div>

                <!-- PROFILE SECTION -->
                <div id="profile-section" class="content-section d-none-soft">
                    <div class="card-custom">
                        <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-person-fill text-danger me-2"></i>Blood Bank Profile</h5>
                        <form action="bloodbank_dashboard.php" method="POST">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">Bank Name</label>
                                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($bankData['name'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">Location</label>
                                    <input type="text" name="location" class="form-control" value="<?php echo htmlspecialchars($bankData['location'] ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">Contact Info</label>
                                    <input type="text" name="contact" class="form-control" value="<?php echo htmlspecialchars($bankData['contact'] ?? ''); ?>" required placeholder="Enter numbers only">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted">Storage Capacity (Units)</label>
                                    <input type="number" name="capacity" class="form-control" value="<?php echo htmlspecialchars($bankData['capacity'] ?? '0'); ?>" required min="0">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label fw-bold small text-muted">License Number</label>
                                    <input type="text" name="license_no" class="form-control" value="<?php echo htmlspecialchars($bankData['license_no'] ?? ''); ?>" required placeholder="Enter regulatory license number">
                                </div>
                                <div class="col-12 mt-4">
                                    <button type="submit" name="update_profile" class="btn btn-danger rounded-pill px-5 shadow-sm fw-bold">Update Profile</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- EXPIRY ALERTS SECTION -->
                <div id="expiry-section" class="content-section d-none-soft">
                    <div class="card-custom">
                        <h5 class="fw-bold text-dark mb-4"><i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>Imminent Expirations (5 Days)</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Blood Group</th>
                                        <th>Units At Risk</th>
                                        <th>Expiry Threshold</th>
                                        <th>Location ID</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(count($expiryAlerts) > 0): ?>
                                        <?php 
                                            foreach ($expiryAlerts as $alert): 
                                                $today = new DateTime();
                                                $expDate = new DateTime($alert['expiry_date']);
                                                $interval = $today->diff($expDate);
                                                
                                                // If date is completely past
                                                if ($expDate < $today && $interval->days > 0) {
                                                    $alertColor = 'bg-danger text-white border-danger';
                                                    $alertText = 'EXPIRED';
                                                    $textColor = 'text-danger fw-bold';
                                                } else { // It's soon
                                                    $alertColor = 'bg-warning text-dark border-warning';
                                                    $alertText = 'EXPIRING SOON';
                                                    $textColor = 'text-warning fw-bold';
                                                }
                                        ?>
                                            <tr>
                                                <td><span class="badge-blood px-2 py-1 fs-6"><?php echo htmlspecialchars($alert['blood_group']); ?></span></td>
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

                <!-- REQUESTS SECTION -->
                <div id="requests-section" class="content-section d-none-soft">
                    <div class="card-custom">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold m-0 text-dark"><i class="bi bi-clock-history text-success me-2"></i>Pending Fulfillment Approvals</h5>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="text-muted text-uppercase">Patient Target</th>
                                        <th class="text-muted text-uppercase">Blood Type</th>
                                        <th class="text-muted text-uppercase">Dispensing Bank</th>
                                        <th class="text-muted text-uppercase">Priority</th>
                                        <th class="text-muted text-uppercase text-end">Operations</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($pendingBloodRequests as $req): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <i class="bi bi-person-circle fs-4 text-secondary me-2"></i>
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
                                                <span class="fw-bold text-dark"><i class="bi bi-droplet-fill me-1"></i><?php echo htmlspecialchars($req['units_needed']); ?> Units</span>
                                            </td>
                                            <td>
                                                <h5 class="mb-0 fw-bold <?php echo ($req['priority_score'] > 75) ? 'text-danger' : 'text-primary'; ?>">
                                                    <?php echo htmlspecialchars($req['priority_score']); ?>
                                                </h5>
                                            </td>
                                            <td class="text-end">
                                                <form action="bloodbank_dashboard.php" method="POST" class="m-0" onsubmit="return confirm('Confirm that blood units have been securely dispensed? This will reduce your inventory.');">
                                                    <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                                                    <button type="submit" name="fulfill_request" class="btn" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white; border-radius: 50px; font-weight: 600; padding: 0.4rem 1.2rem; border: none;">
                                                        <i class="bi bi-check2-all me-1"></i>Fulfill Request
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if(count($pendingBloodRequests) === 0): ?>
                                        <tr><td colspan="5" class="text-center py-5 text-muted">No pending requests waiting for fulfillment.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
            <!-- Footer -->
            <footer class="text-center py-4 text-muted mt-5" style="border-top: 1px solid rgba(0,0,0,0.05);">
                &copy; 2026 MediMatch | Saving Lives Through Smart Matching
            </footer>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // UI Section Toggling Logic
        function showSection(sectionId, element) {
            document.querySelectorAll('.content-section').forEach(sec => sec.classList.add('d-none-soft'));
            document.querySelectorAll('.nav-link-custom').forEach(nav => nav.classList.remove('active'));
            
            document.getElementById(sectionId).classList.remove('d-none-soft');
            if(element) element.classList.add('active');

            const titles = {
                'dashboard-section': 'Dashboard Overview',
                'inventory-section': 'Global Inventory Logs',
                'update-section': 'Update Target Stock',
                'profile-section': 'Blood Bank Profile',
                'expiry-section': 'Expiration Alerts',
                'requests-section': 'Match Fulfillment Queue'
            };
            document.getElementById('headerTitle').innerText = titles[sectionId] || 'Dashboard Overview';
        }

        // Toggle expandable inventory bank card
        function toggleInvCard(bodyId, iconId) {
            const body = document.getElementById(bodyId);
            const icon = document.getElementById(iconId);
            body.classList.toggle('open');
            icon.classList.toggle('rotated');
        }
    </script>
</body>
</html>
