<?php
ini_set('session.cookie_lifetime', 86400); // 1 day
ini_set('session.gc_maxlifetime', 86400);
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['role'] !== 'admin') {
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

    // Handle Approval/Rejection Actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        $id = $_POST['id'] ?? '';
        $type = $_POST['type'] ?? '';

        if (($action === 'approve' || $action === 'reject') && $id && $type) {
            $status = ($action === 'approve') ? 'approved' : 'rejected';
            $table = ($type === 'hospital') ? 'hospitals' : 'blood_banks';
            $colId = ($type === 'hospital') ? 'hospital_id' : 'bank_id';

            $stmt = $pdo->prepare("UPDATE $table SET status = ? WHERE $colId = ?");
            $stmt->execute([$status, $id]);

            $_SESSION['success'] = "Facility registration successfully " . $status . "!";
            header("Location: admin_dashboard.php");
            exit();
        }
    }

    // Fetch Success Message
    $message = "";
    if (isset($_SESSION['success'])) {
        $message = $_SESSION['success'];
        unset($_SESSION['success']);
    }

    // Fetch Overview Stats
    $stats = [
        'patients' => $pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn(),
        'donors' => $pdo->query("SELECT COUNT(*) FROM donors")->fetchColumn(),
        'banks' => $pdo->query("SELECT COUNT(*) FROM blood_banks")->fetchColumn(),
        'hospitals' => $pdo->query("SELECT COUNT(*) FROM hospitals")->fetchColumn(),
        'pending_reg' => $pdo->query("SELECT ((SELECT COUNT(*) FROM hospitals WHERE status='pending') + (SELECT COUNT(*) FROM blood_banks WHERE status='pending'))")->fetchColumn(),
        'fulfilled' => $pdo->query("SELECT COUNT(*) FROM patients WHERE status = 'fulfilled'")->fetchColumn(),
    ];

    // Chart Data
    $chartData = [
        'blood' => $pdo->query("SELECT COUNT(*) FROM patients WHERE request_type = 'blood'")->fetchColumn(),
        'organ' => $pdo->query("SELECT COUNT(*) FROM patients WHERE request_type = 'organ'")->fetchColumn(),
        'approved' => $pdo->query("SELECT COUNT(*) FROM patients WHERE status = 'approved'")->fetchColumn(),
    ];

    // Data Queries
    // JOIN patients table to ensure name always comes from the canonical patients record
    $allPatients = $pdo->query("
        SELECT p.*
        FROM patients p
        ORDER BY p.priority_score DESC, p.request_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $allDonors = $pdo->query("SELECT * FROM donors ORDER BY verified DESC")->fetchAll(PDO::FETCH_ASSOC);
    $allBanks = $pdo->query("SELECT * FROM blood_banks ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $allHospitals = $pdo->query("SELECT * FROM hospitals ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // All Blood Requests — fetch directly from blood_requests for correct submitted data
    $allBloodRequests = $pdo->query("
        SELECT
            br.request_id, br.patient_id, br.blood_group, br.units_needed,
            br.priority_score, br.status,
            br.patient_name, br.age
        FROM blood_requests br
        ORDER BY br.request_id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // All Organ Requests — JOIN patients and hospitals for correct name
    $allOrganRequests = $pdo->query("
        SELECT
            orq.request_id, orq.patient_id, orq.organ_type,
            orq.status, orq.priority_score,
            p.name AS patient_name, p.age, p.blood_group,
            h.name AS hospital_name
        FROM organ_requests orq
        JOIN patients p ON orq.patient_id = p.patient_id
        LEFT JOIN hospitals h ON orq.hospital_id = h.hospital_id
        ORDER BY orq.request_id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Pending Approvals
    $pendingHospitals = $pdo->query("SELECT * FROM hospitals WHERE status = 'pending'")->fetchAll(PDO::FETCH_ASSOC);
    $pendingBanks = $pdo->query("SELECT * FROM blood_banks WHERE status = 'pending'")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Connection Error: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - MediMatch</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            --sidebar-bg: #0f172a;
            --accent-color: #38bdf8;
            --card-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            overflow-x: hidden;
        }

        /* Sidebar UI */
        .sidebar {
            width: 280px;
            background-color: var(--sidebar-bg);
            min-height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            padding-top: 2rem;
            color: white;
            z-index: 1000;
            box-shadow: 4px 0 15px rgba(0, 0, 0, 0.1);
        }

        .sidebar-brand {
            padding: 0 2rem;
            margin-bottom: 3rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            background: var(--primary-gradient);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .nav-link-custom {
            padding: 1rem 2rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 15px;
            text-decoration: none;
            transition: all 0.3s ease;
            cursor: pointer;
            border-left: 4px solid transparent;
        }

        .nav-link-custom:hover {
            background-color: rgba(255, 255, 255, 0.05);
            color: white;
        }

        .nav-link-custom.active {
            background-color: rgba(37, 99, 235, 0.1);
            color: var(--accent-color);
            border-left-color: var(--accent-color);
            font-weight: 600;
        }

        /* Main Content area */
        .main-content {
            margin-left: 280px;
            padding: 2.5rem;
            min-height: 100vh;
        }

        /* Top Header Area */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.5rem;
            background: white;
            padding: 1.5rem 2.5rem;
            margin: -2.5rem -2.5rem 2.5rem -2.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        /* Summary Cards */
        .card-custom {
            background: white;
            border: none;
            border-radius: 1.25rem;
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
            transition: transform 0.3s ease;
            height: 100%;
        }

        .card-custom:hover {
            transform: translateY(-5px);
        }

        .stat-icon-box {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1.25rem;
        }

        /* Section Display Toggle */
        .content-section {
            display: none;
            animation: fadeIn 0.4s ease forwards;
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

        /* Table Styling */
        .table-premium {
            background: white;
            border-radius: 1rem;
            overflow: hidden;
        }

        .table-premium thead {
            background-color: #f1f5f9;
        }

        .table-premium th {
            padding: 1.25rem;
            font-weight: 600;
            color: #475569;
        }

        .table-premium td {
            padding: 1.25rem;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
        }

        /* Custom Elements */
        .status-badge {
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-pending {
            background-color: #fef3c7;
            color: #d97706;
        }

        .status-approved {
            background-color: #dcfce7;
            color: #16a34a;
        }

        .status-rejected {
            background-color: #fee2e2;
            color: #dc2626;
        }

        .status-donor-matched {
            background-color: #e0e7ff;
            color: #4f46e5;
        }

        .priority-score {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-weight: 700;
        }
    </style>
</head>

<body>

    <!-- Sidebar Menu -->
    <div class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon"><i class="bi bi-shield-check"></i></div>
            <h4 class="mb-0 fw-bold">Admin Panel - MediMatch</h4>
        </div>

        <div class="nav flex-column">
            <div class="nav-link-custom active" onclick="showSection('dashboard-section', this)">
                <i class="bi bi-grid-fill"></i> Dashboard
            </div>
            <div class="nav-link-custom" onclick="showSection('patients-section', this)">
                <i class="bi bi-people-fill"></i> Patients
            </div>
            <div class="nav-link-custom" onclick="showSection('donors-section', this)">
                <i class="bi bi-person-heart"></i> Donors
            </div>
            <div class="nav-link-custom" onclick="showSection('hospitals-section', this)">
                <i class="bi bi-hospital-fill"></i> Hospitals
            </div>
            <div class="nav-link-custom" onclick="showSection('banks-section', this)">
                <i class="bi bi-building-fill-add"></i> Blood Banks
            </div>
            <div class="nav-link-custom position-relative" onclick="showSection('approvals-section', this)">
                <i class="bi bi-clipboard2-check-fill"></i> Approvals
                <?php if ($stats['pending_reg'] > 0): ?>
                    <span class="position-absolute top-50 end-0 translate-middle-y me-3 badge rounded-pill bg-danger"
                        style="font-size: 0.6rem;">
                        <?php echo $stats['pending_reg']; ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="nav-link-custom" onclick="showSection('requests-section', this)">
                <i class="bi bi-file-earmark-medical-fill"></i> All Requests
            </div>
            <div class="nav-link-custom" onclick="showSection('reports-section', this)">
                <i class="bi bi-graph-up-arrow"></i> Reports
            </div>
            <hr class="mx-3 opacity-25">
            <a href="logout.php" class="nav-link-custom text-danger">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="main-content">

        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div>
                <h3 class="fw-bold mb-0" id="current-section-title">Dashboard Overview</h3>
                <p class="text-muted mb-0 small">Real-time system monitoring & health stats</p>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="text-end">
                    <div class="fw-bold">System Admin</div>
                    <div class="small text-muted">ID: #001-A</div>
                </div>
                <div class="brand-icon" style="background: #e2e8f0; color: #475569;"><i class="bi bi-person"></i></div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show mb-4 border-0 shadow-sm rounded-4 px-4 py-3"
                role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- DASHBOARD SECTION -->
        <div id="dashboard-section" class="content-section active">
            <!-- Summary Stats -->
            <div class="row g-4 mb-5">
                <div class="col-md-4 col-lg-2">
                    <div class="card-custom">
                        <div class="stat-icon-box bg-primary-subtle text-primary"><i class="bi bi-people-fill"></i>
                        </div>
                        <h6 class="text-muted fw-bold small">PATIENTS</h6>
                        <h2 class="fw-bold mb-0"><?php echo $stats['patients']; ?></h2>
                    </div>
                </div>
                <div class="col-md-4 col-lg-2">
                    <div class="card-custom">
                        <div class="stat-icon-box bg-success-subtle text-success"><i class="bi bi-person-heart"></i>
                        </div>
                        <h6 class="text-muted fw-bold small">DONORS</h6>
                        <h2 class="fw-bold mb-0"><?php echo $stats['donors']; ?></h2>
                    </div>
                </div>
                <div class="col-md-4 col-lg-2">
                    <div class="card-custom">
                        <div class="stat-icon-box bg-info-subtle text-info"><i class="bi bi-hospital"></i></div>
                        <h6 class="text-muted fw-bold small">HOSPITALS</h6>
                        <h2 class="fw-bold mb-0"><?php echo $stats['hospitals']; ?></h2>
                    </div>
                </div>
                <div class="col-md-4 col-lg-2">
                    <div class="card-custom">
                        <div class="stat-icon-box bg-danger-subtle text-danger"><i class="bi bi-droplet-fill"></i></div>
                        <h6 class="text-muted fw-bold small">BANKS</h6>
                        <h2 class="fw-bold mb-0"><?php echo $stats['banks']; ?></h2>
                    </div>
                </div>
                <div class="col-md-4 col-lg-2">
                    <div class="card-custom">
                        <div class="stat-icon-box bg-warning-subtle text-warning"><i class="bi bi-activity"></i></div>
                        <h6 class="text-muted fw-bold small">PENDING</h6>
                        <h2 class="fw-bold mb-0"><?php echo $stats['pending_reg']; ?></h2>
                    </div>
                </div>
                <div class="col-md-4 col-lg-2">
                    <div class="card-custom">
                        <div class="stat-icon-box bg-dark-subtle text-dark"><i class="bi bi-calendar-check"></i></div>
                        <h6 class="text-muted fw-bold small">HISTORY</h6>
                        <h2 class="fw-bold mb-0"><?php echo $stats['fulfilled']; ?></h2>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-5">
                <div class="col-lg-8">
                    <div class="card-custom p-4">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold mb-0">Patient Requests Overview</h5>
                            <span class="badge bg-light text-dark">By Category</span>
                        </div>
                        <canvas id="reqBarChart" height="150"></canvas>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card-custom p-4">
                        <h5 class="fw-bold mb-4">Verification Health</h5>
                        <canvas id="statusPieChart" height="250"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- PATIENTS SECTION -->
        <div id="patients-section" class="content-section">
            <div class="card-custom p-0 overflow-hidden">
                <div class="p-4 border-bottom d-flex justify-content-between align-items-center bg-light">
                    <h5 class="fw-bold mb-0">Total Patient Pool</h5>
                    <div class="input-group w-auto">
                        <span class="input-group-text bg-white border-0"><i class="bi bi-search"></i></span>
                        <input type="text" class="form-control border-0" placeholder="Search patients..."
                            id="searchPatient" onkeyup="searchTable('searchPatient', 'patientTable')">
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-premium mb-0" id="patientTable">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Age</th>
                                <th>Blood Group</th>
                                <th>Type</th>
                                <th>Priority</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allPatients as $p): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($p['name']); ?></div>
                                        <small class="text-muted">ID: #P-<?php echo $p['patient_id']; ?></small>
                                    </td>
                                    <td><?php echo $p['age']; ?></td>
                                    <td><span
                                            class="badge bg-secondary-subtle text-secondary px-3"><?php echo $p['blood_group']; ?></span>
                                    </td>
                                    <td>
                                        <?php if ($p['request_type'] == 'blood'): ?>
                                            <span class="text-danger small fw-bold"><i class="bi bi-droplet-fill"></i>
                                                Blood</span>
                                        <?php else: ?>
                                            <span class="text-primary small fw-bold"><i class="bi bi-lungs-fill"></i>
                                                <?php echo $p['organ_needed']; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $score = $p['priority_score'];
                                        $sBg = $score >= 80 ? 'bg-danger text-white' : ($score >= 50 ? 'bg-warning text-dark' : 'bg-success text-white');
                                        ?>
                                        <div class="priority-score <?php echo $sBg; ?>"><?php echo $score; ?></div>
                                    </td>
                                    <td>
                                        <?php
                                        $st = strtolower($p['status']);
                                        $labelMap = [
                                            'pending' => 'Pending',
                                            'waiting_for_donor' => 'Waiting for Donor',
                                            'donor_matched' => 'Donor Assigned',
                                            'approved' => 'Approved',
                                            'fulfilled' => 'Fulfilled',
                                            'rejected' => 'Rejected'
                                        ];
                                        $displayLabel = $labelMap[$st] ?? ucfirst(str_replace('_', ' ', $st));
                                        
                                        $statBox = 'status-pending';
                                        if ($st === 'approved') $statBox = 'status-approved';
                                        elseif ($st === 'fulfilled') $statBox = 'status-fulfilled';
                                        elseif ($st === 'rejected') $statBox = 'status-rejected';
                                        elseif ($st === 'donor_matched') $statBox = 'status-donor-matched';
                                        ?>
                                        <span class="status-badge <?php echo $statBox; ?>"><?php echo $displayLabel; ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- DONORS SECTION -->
        <div id="donors-section" class="content-section">
            <div class="card-custom p-0 overflow-hidden">
                <div class="p-4 border-bottom d-flex justify-content-between align-items-center bg-light">
                    <h5 class="fw-bold mb-0">System Donors Registry</h5>
                    <input type="text" class="form-control w-auto border-0 shadow-sm" placeholder="Search Donors..."
                        id="searchDonor" onkeyup="searchTable('searchDonor', 'donorTable')">
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-premium mb-0" id="donorTable">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Blood Group</th>
                                <th>Donor Type</th>
                                <th>Verified</th>
                                <th>Availability</th>
                                <th>Contact</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allDonors as $d): ?>
                                <tr>
                                    <td class="fw-bold"><?php echo htmlspecialchars($d['name']); ?></td>
                                    <td><span
                                            class="badge bg-dark rounded-pill px-3"><?php echo $d['blood_group']; ?></span>
                                    </td>
                                    <td><span class="text-capitalize small fw-bold"><?php echo $d['donor_type']; ?></span>
                                    </td>
                                    <td>
                                        <?php if ($d['verified'] == 'yes'): ?>
                                            <i class="bi bi-patch-check-fill text-primary"></i>
                                        <?php else: ?>
                                            <i class="bi bi-x-circle text-muted"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span
                                            class="badge <?php echo $d['availability'] == 'available' ? 'bg-success-subtle text-success' : 'bg-light text-muted'; ?> rounded-pill">
                                            <?php echo ucfirst(str_replace('_', ' ', $d['availability'])); ?>
                                        </span>
                                    </td>
                                    <td class="small"><?php echo $d['contact']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- HOSPITALS SECTION -->
        <div id="hospitals-section" class="content-section">
            <div class="card-custom p-0 overflow-hidden">
                <div class="p-4 border-bottom d-flex justify-content-between align-items-center bg-light">
                    <h5 class="fw-bold mb-0">Registered Hospitals</h5>
                    <input type="text" class="form-control w-auto border-0 shadow-sm" placeholder="Search..."
                        id="searchHosp" onkeyup="searchTable('searchHosp', 'hospTable')">
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-premium mb-0" id="hospTable">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Location</th>
                                <th>Contact</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allHospitals as $h): ?>
                                <tr>
                                    <td class="fw-bold"><?php echo htmlspecialchars($h['name']); ?></td>
                                    <td><i class="bi bi-geo-alt-fill text-muted me-1"></i><?php echo $h['location']; ?></td>
                                    <td><?php echo $h['contact']; ?></td>
                                    <td>
                                        <?php
                                        $st = strtolower($h['status']);
                                        $cls = $st === 'approved' ? 'status-approved' : ($st === 'pending' ? 'status-pending' : 'status-rejected');
                                        ?>
                                        <span class="status-badge <?php echo $cls; ?>"><?php echo $st; ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- BANKS SECTION -->
        <div id="banks-section" class="content-section">
            <div class="card-custom p-0 overflow-hidden">
                <div class="p-4 border-bottom d-flex justify-content-between align-items-center bg-light">
                    <h5 class="fw-bold mb-0">Network Blood Banks</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-premium mb-0">
                        <thead>
                            <tr>
                                <th>Bank Name</th>
                                <th>Location</th>
                                <th>Contact No</th>
                                <th>License</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allBanks as $b): ?>
                                <tr>
                                    <td class="fw-bold"><?php echo htmlspecialchars($b['name']); ?></td>
                                    <td><?php echo $b['location']; ?></td>
                                    <td><?php echo $b['contact']; ?></td>
                                    <td>
                                        <?php
                                        $st = strtolower($b['status']);
                                        $cls = $st === 'approved' ? 'status-approved' : ($st === 'pending' ? 'status-pending' : 'status-rejected');
                                        ?>
                                        <span class="status-badge <?php echo $cls; ?>"><?php echo $st; ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- APPROVALS SECTION -->
        <div id="approvals-section" class="content-section">

            <?php if (count($pendingHospitals) > 0): ?>
                <div class="card-custom p-4 mb-4">
                    <h5 class="fw-bold mb-4">Pending Hospital Registrations</h5>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Hospital Name</th>
                                    <th>Location</th>
                                    <th>Contact</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingHospitals as $ph): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo htmlspecialchars($ph['name']); ?></td>
                                        <td><?php echo $ph['location']; ?></td>
                                        <td><?php echo $ph['contact']; ?></td>
                                        <td class="text-end">
                                            <form action="admin_dashboard.php" method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="type" value="hospital">
                                                <input type="hidden" name="id" value="<?php echo $ph['hospital_id']; ?>">
                                                <button type="submit" class="btn btn-success btn-sm rounded-pill px-3"><i
                                                        class="bi bi-check-circle"></i> Approve</button>
                                            </form>
                                            <form action="admin_dashboard.php" method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="type" value="hospital">
                                                <input type="hidden" name="id" value="<?php echo $ph['hospital_id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm rounded-pill px-3"><i
                                                        class="bi bi-x-circle"></i> Reject</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (count($pendingBanks) > 0): ?>
                <div class="card-custom p-4">
                    <h5 class="fw-bold mb-4">Pending Blood Bank Registrations</h5>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Bank Name</th>
                                    <th>Location</th>
                                    <th>Contact</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingBanks as $pb): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo htmlspecialchars($pb['name']); ?></td>
                                        <td><?php echo $pb['location']; ?></td>
                                        <td><?php echo $pb['contact']; ?></td>
                                        <td class="text-end">
                                            <form action="admin_dashboard.php" method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="type" value="bloodbank">
                                                <input type="hidden" name="id" value="<?php echo $pb['bank_id']; ?>">
                                                <button type="submit"
                                                    class="btn btn-success btn-sm rounded-pill px-3">Approve</button>
                                            </form>
                                            <form action="admin_dashboard.php" method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="type" value="bloodbank">
                                                <input type="hidden" name="id" value="<?php echo $pb['bank_id']; ?>">
                                                <button type="submit"
                                                    class="btn btn-danger btn-sm rounded-pill px-3">Reject</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (count($pendingHospitals) === 0 && count($pendingBanks) === 0): ?>
                <div class="card-custom d-flex flex-column align-items-center justify-content-center py-5 text-center">
                    <div class="stat-icon-box bg-light text-muted" style="width: 80px; height: 80px; font-size: 2.5rem;">
                        <i class="bi bi-patch-check"></i>
                    </div>
                    <h5 class="fw-bold text-muted">No Pending Approvals</h5>
                    <p class="text-muted small">All facility registrations have been processed.</p>
                </div>
            <?php endif; ?>

        </div>

        <!-- ALL REQUESTS SECTION -->
        <div id="requests-section" class="content-section">

            <!-- Blood Requests Table -->
            <div class="card-custom p-0 overflow-hidden mb-4">
                <div class="p-4 border-bottom d-flex justify-content-between align-items-center bg-light">
                    <h5 class="fw-bold mb-0"><i class="bi bi-droplet-fill text-danger me-2"></i>Blood Requests</h5>
                    <span class="badge bg-danger-subtle text-danger rounded-pill px-3"><?php echo count($allBloodRequests); ?> records</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-premium mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Patient Name</th>
                                <th>Blood Group</th>
                                <th>Units Needed</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($allBloodRequests)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-inbox me-2"></i>No blood requests found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($allBloodRequests as $br): ?>
                                    <tr>
                                        <td class="text-muted small">#BR-<?php echo $br['request_id']; ?></td>
                                        <td>
                                            <?php $display_name = !empty($br['patient_name']) ? $br['patient_name'] : 'Unknown'; ?>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($display_name); ?></div>
                                            <small class="text-muted">ID: #P-<?php echo $br['patient_id']; ?></small>
                                        </td>
                                        <td><span class="badge bg-danger rounded-pill px-3"><?php echo htmlspecialchars($br['blood_group']); ?></span></td>
                                        <td><?php echo $br['units_needed']; ?> unit(s)</td>
                                        <td>
                                            <?php
                                            $score = $br['priority_score'];
                                            $sBg = $score >= 80 ? 'bg-danger text-white' : ($score >= 50 ? 'bg-warning text-dark' : 'bg-success text-white');
                                            ?>
                                            <div class="priority-score <?php echo $sBg; ?>"><?php echo $score; ?></div>
                                        </td>
                                        <td>
                                            <?php
                                            $st = strtolower($br['status']);
                                            $cls = $st === 'approved' ? 'status-approved' : ($st === 'fulfilled' ? 'status-approved' : ($st === 'rejected' ? 'status-rejected' : 'status-pending'));
                                            ?>
                                            <span class="status-badge <?php echo $cls; ?>"><?php echo ucfirst($st); ?></span>
                                        </td>
                                        <td class="small text-muted">N/A</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Organ Requests Table -->
            <div class="card-custom p-0 overflow-hidden">
                <div class="p-4 border-bottom d-flex justify-content-between align-items-center bg-light">
                    <h5 class="fw-bold mb-0"><i class="bi bi-heart-pulse-fill text-primary me-2"></i>Organ Requests</h5>
                    <span class="badge bg-primary-subtle text-primary rounded-pill px-3"><?php echo count($allOrganRequests); ?> records</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-premium mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Patient Name</th>
                                <th>Blood Group</th>
                                <th>Organ Needed</th>
                                <th>Hospital</th>
                                <th>Priority</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($allOrganRequests)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-inbox me-2"></i>No organ requests found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($allOrganRequests as $orq): ?>
                                    <tr>
                                        <td class="text-muted small">#OR-<?php echo $orq['request_id']; ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($orq['patient_name']); ?></div>
                                            <small class="text-muted">ID: #P-<?php echo $orq['patient_id']; ?></small>
                                        </td>
                                        <td><span class="badge bg-secondary rounded-pill px-3"><?php echo htmlspecialchars($orq['blood_group']); ?></span></td>
                                        <td><span class="fw-bold text-primary"><i class="bi bi-lungs-fill me-1"></i><?php echo htmlspecialchars(ucfirst($orq['organ_type'])); ?></span></td>
                                        <td class="small"><?php echo htmlspecialchars($orq['hospital_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php
                                            $score = $orq['priority_score'];
                                            $sBg = $score >= 80 ? 'bg-danger text-white' : ($score >= 50 ? 'bg-warning text-dark' : 'bg-success text-white');
                                            ?>
                                            <div class="priority-score <?php echo $sBg; ?>"><?php echo $score; ?></div>
                                        </td>
                                        <td>
                                            <?php
                                            $st = strtolower($orq['status']);
                                            $cls = $st === 'approved' ? 'status-approved' : ($st === 'fulfilled' ? 'status-approved' : ($st === 'rejected' ? 'status-rejected' : 'status-pending'));
                                            ?>
                                            <span class="status-badge <?php echo $cls; ?>"><?php echo ucfirst($st); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

        <!-- REPORTS SECTION -->
        <div id="reports-section" class="content-section">
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card-custom p-4 h-100">
                        <h5 class="fw-bold mb-4 text-primary">System Composition</h5>
                        <div class="small mb-2">Total Patients Enrolled: <span
                                class="fw-bold float-end"><?php echo $stats['patients']; ?></span></div>
                        <div class="small mb-2">Total Donors Verified: <span
                                class="fw-bold float-end"><?php echo $stats['donors']; ?></span></div>
                        <div class="small mb-2">Active Hospitals: <span
                                class="fw-bold float-end"><?php echo $stats['hospitals']; ?></span></div>
                        <div class="small mb-4">Blood Bank Units: <span
                                class="fw-bold float-end"><?php echo $stats['banks']; ?></span></div>
                        <canvas id="compDoughnut"></canvas>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card-custom p-4 h-100 bg-primary text-white">
                        <h5 class="fw-bold mb-4">System Sustainability</h5>
                        <p class="small opacity-75">Our current match-to-request ratio and system health indicators.</p>
                        <div class="mt-4">
                            <h2 class="fw-bold mb-0">
                                <?php echo round(($stats['fulfilled'] / max(1, $stats['patients'])) * 100, 1); ?>%
                            </h2>
                            <div class="small opacity-75">Fulfilled Request Rate</div>

                        </div>
                        <div class="mt-5">
                            <div class="d-flex justify-content-between small mb-1">
                                <span>Verification Integrity</span>
                                <span>High</span>
                            </div>
                            <div class="progress" style="height: 6px; background: rgba(255,255,255,0.2);">
                                <div class="progress-bar bg-info" style="width: 88%;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="text-center py-4 text-muted mt-5" style="border-top: 1px solid rgba(0,0,0,0.05);">
            &copy; 2026 MediMatch | Saving Lives Through Smart Matching
        </footer>

    </div>

    <!-- Bootstrap Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        function showSection(id, element) {
            // Hide all sections
            document.querySelectorAll('.content-section').forEach(sec => sec.classList.remove('active'));
            // Remove active from all links
            document.querySelectorAll('.nav-link-custom').forEach(nav => nav.classList.remove('active'));

            // Show new section
            document.getElementById(id).classList.add('active');
            element.classList.add('active');

            // Update title
            const titles = {
                'dashboard-section': 'Dashboard Overview',
                'patients-section': 'Patient Management',
                'donors-section': 'Donor Registry',
                'hospitals-section': 'Hospital Network',
                'banks-section': 'Blood Bank Control',
                'approvals-section': 'Pending Approvals',
                'requests-section': 'All Patient Requests',
                'reports-section': 'System Analytics'
            };
            document.getElementById('current-section-title').innerText = titles[id];
        }

        function searchTable(inputId, tableId) {
            let input = document.getElementById(inputId);
            let filter = input.value.toUpperCase();
            let table = document.getElementById(tableId);
            let tr = table.getElementsByTagName("tr");

            for (let i = 1; i < tr.length; i++) {
                let td = tr[i].getElementsByTagName("td")[0];
                if (td) {
                    let txtValue = td.textContent || td.innerText;
                    if (txtValue.toUpperCase().indexOf(filter) > -1) {
                        tr[i].style.display = "";
                    } else {
                        tr[i].style.display = "none";
                    }
                }
            }
        }

        // Charts
        const chartData = {
            blood: <?php echo $chartData['blood']; ?>,
            organ: <?php echo $chartData['organ']; ?>,
            pending: <?php echo $stats['pending_reg']; ?>,
            approved: <?php echo $chartData['approved']; ?>,
            fulfilled: <?php echo $stats['fulfilled']; ?>
        };

        const ctxBar = document.getElementById('reqBarChart').getContext('2d');
        new Chart(ctxBar, {
            type: 'bar',
            data: {
                labels: ['Blood Requests', 'Organ Requests'],
                datasets: [{
                    label: 'Requests',
                    data: [chartData.blood, chartData.organ],
                    backgroundColor: ['#ef4444', '#3b82f6'],
                    borderRadius: 8
                }]
            },
            options: { plugins: { legend: { display: false } } }
        });

        const ctxPie = document.getElementById('statusPieChart').getContext('2d');
        new Chart(ctxPie, {
            type: 'doughnut',
            data: {
                labels: ['Active Requests', 'Completed Cases'],
                datasets: [{
                    data: [chartData.blood + chartData.organ, chartData.fulfilled],
                    backgroundColor: ['#6366f1', '#10b981'],
                    borderWidth: 0
                }]
            },
            options: { cutout: '75%', plugins: { legend: { position: 'bottom' } } }
        });

        const ctxComp = document.getElementById('compDoughnut')?.getContext('2d');
        if (ctxComp) {
            new Chart(ctxComp, {
                type: 'doughnut',
                data: {
                    labels: ['Patients', 'Donors', 'Banks', 'Hospitals'],
                    datasets: [{
                        data: [<?php echo $stats['patients']; ?>, <?php echo $stats['donors']; ?>, <?php echo $stats['banks']; ?>, <?php echo $stats['hospitals']; ?>],
                        backgroundColor: ['#6366f1', '#10b981', '#f59e0b', '#ef4444'],
                        borderWidth: 0
                    }]
                },
                options: { cutout: '60%', plugins: { legend: { display: false } } }
            });
        }
    </script>
</body>

</html>