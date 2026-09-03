<?php
ini_set('session.cookie_lifetime', 86400);
ini_set('session.gc_maxlifetime', 86400);
session_start();

// Secure: Only patients can access this report
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}
if ($_SESSION['role'] !== 'patient') {
    header("Location: login.php");
    exit();
}

$type = $_GET['type'] ?? '';
$patient_id = $_SESSION['ref_id'];

$host = 'localhost';
$dbname = 'organ_blood_donation';
$username = 'root';
$password = '';

$reportData = null;
$patientInfo = null;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Fetch patient profile
    $stmtP = $pdo->prepare("SELECT name, age, blood_group, organ_needed FROM patients WHERE patient_id = ?");
    $stmtP->execute([$patient_id]);
    $patientInfo = $stmtP->fetch(PDO::FETCH_ASSOC);

    if ($type === 'blood_bank') {
        // Step 8: Blood bank report — use LEFT JOIN so pending (bank_id=NULL) requests still show
        $stmt = $pdo->prepare("
            SELECT b.name AS facility_name, b.location, b.contact,
                   br.patient_name, br.age, br.blood_group, br.units_needed, br.status, br.request_id
            FROM blood_requests br
            LEFT JOIN blood_banks b ON br.bank_id = b.bank_id
            WHERE br.patient_id = ?
            ORDER BY br.request_id DESC
            LIMIT 1
        ");
        $stmt->execute([$patient_id]);
        $reportData = $stmt->fetch(PDO::FETCH_ASSOC);
        $reportTitle = "Blood Request Report";
        $facilityType = "Blood Bank";
        // If bank_id not yet assigned (pending), set placeholder
        if ($reportData && empty($reportData['facility_name'])) {
            $reportData['facility_name'] = 'Pending Assignment';
            $reportData['location']      = 'To be confirmed';
            $reportData['contact']       = 'To be confirmed';
        }
        $requestDetail = $reportData ? "Blood Group: <strong>" . htmlspecialchars($reportData['blood_group']) . "</strong> &mdash; " . htmlspecialchars($reportData['units_needed']) . " unit(s)" : "";

    } elseif ($type === 'hospital') {
        // Step 8: Organ/hospital report
        $stmt = $pdo->prepare("
            SELECT h.name AS facility_name, h.location, h.contact,
                   o.organ_type, o.status, o.request_id
            FROM organ_requests o
            JOIN hospitals h ON o.hospital_id = h.hospital_id
            WHERE o.patient_id = ?
            ORDER BY o.request_id DESC
            LIMIT 1
        ");
        $stmt->execute([$patient_id]);
        $reportData = $stmt->fetch(PDO::FETCH_ASSOC);
        $reportTitle = "Organ Request Report";
        $facilityType = "Hospital";
        $requestDetail = $reportData ? "Organ: <strong>" . htmlspecialchars($reportData['organ_type']) . "</strong>" : "";

    } else {
        $reportTitle = "Medical Report";
        $facilityType = "Unknown";
        $requestDetail = "";
    }

} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}

$statusColor = 'secondary';
$statusIcon = 'hourglass-split';
if ($reportData) {
    $s = strtolower($reportData['status'] ?? '');
    if ($s === 'fulfilled') { $statusColor = 'success'; $statusIcon = 'check-circle-fill'; }
    elseif ($s === 'approved') { $statusColor = 'primary'; $statusIcon = 'check-lg'; }
    elseif ($s === 'pending') { $statusColor = 'warning'; $statusIcon = 'hourglass-split'; }
    elseif ($s === 'rejected') { $statusColor = 'danger'; $statusIcon = 'x-circle-fill'; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($reportTitle); ?> - MediMatch</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f4ff 0%, #fafafa 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .report-wrapper {
            width: 100%;
            max-width: 700px;
        }
        .report-card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .report-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: white;
            padding: 2.5rem;
        }
        .report-body {
            padding: 2.5rem;
        }
        .report-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .report-row:last-child {
            border-bottom: none;
        }
        .report-label {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
        }
        .report-value {
            font-weight: 600;
            color: #1e293b;
            text-align: right;
        }
        .divider-line {
            border: none;
            border-top: 2px dashed #e2e8f0;
            margin: 1.5rem 0;
        }
        .report-id-badge {
            background: rgba(255,255,255,0.2);
            border-radius: 50px;
            padding: 0.3rem 1rem;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .action-btn {
            border-radius: 50px;
            padding: 0.75rem 2rem;
            font-weight: 600;
        }
        @media print {
            body { background: white; padding: 0; }
            .no-print { display: none !important; }
            .report-card { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>
    <div class="report-wrapper">

        <!-- Back Button -->
        <div class="mb-3 no-print">
            <a href="patient_dashboard.php" class="btn btn-light rounded-pill px-4 shadow-sm">
                <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>

        <div class="report-card">
            <!-- Header -->
            <div class="report-header">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="small opacity-75 mb-1 text-uppercase fw-bold">
                            <i class="bi bi-heart-pulse-fill me-1"></i>MediMatch
                        </div>
                        <h2 class="fw-bold mb-0"><?php echo htmlspecialchars($reportTitle); ?></h2>
                        <p class="opacity-75 mb-0 mt-1 small">Generated on <?php echo date('d M Y, h:i A'); ?></p>
                    </div>
                    <?php if ($reportData): ?>
                    <div class="text-end">
                        <span class="report-id-badge">
                            Req #<?php echo htmlspecialchars($reportData['request_id']); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Body -->
            <div class="report-body">

                <?php if (!$reportData): ?>
                <!-- No Data State -->
                <div class="text-center py-5">
                    <i class="bi bi-file-earmark-x fs-1 text-muted mb-3 d-block"></i>
                    <h5 class="text-muted fw-semibold">No Report Available</h5>
                    <p class="text-muted small">No <?php echo $type === 'blood_bank' ? 'blood' : 'organ'; ?> request found for your account yet.</p>
                    <a href="patient_dashboard.php" class="btn btn-primary rounded-pill px-4 mt-2 no-print">Submit a Request</a>
                </div>

                <?php else: ?>

                <!-- PATIENT INFO BLOCK -->
                <h6 class="fw-bold text-muted text-uppercase small mb-3">
                    <i class="bi bi-person-badge me-2"></i>Patient Information
                </h6>
                <div class="row g-3 mb-4">
                    <div class="col-6">
                        <div class="p-3 bg-light rounded-3">
                            <div class="text-muted small fw-bold mb-1">Full Name</div>
                            <?php $display_name = !empty($reportData['patient_name']) ? $reportData['patient_name'] : 'Unknown'; ?>
                            <div class="fw-bold"><?php echo htmlspecialchars($display_name); ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-light rounded-3">
                            <div class="text-muted small fw-bold mb-1">Age</div>
                            <div class="fw-bold"><?php echo htmlspecialchars($reportData['age'] ?? $patientInfo['age'] ?? 'N/A'); ?> years</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-light rounded-3">
                            <div class="text-muted small fw-bold mb-1">Blood Group</div>
                            <div class="fw-bold text-danger"><?php echo htmlspecialchars($patientInfo['blood_group'] ?? 'N/A'); ?></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-light rounded-3">
                            <div class="text-muted small fw-bold mb-1">Request Type</div>
                            <div class="fw-bold"><?php echo $type === 'hospital' ? '<i class="bi bi-heart-pulse me-1 text-primary"></i>Organ' : '<i class="bi bi-droplet-fill me-1 text-danger"></i>Blood'; ?></div>
                        </div>
                    </div>
                </div>

                <hr class="divider-line">

                <!-- FACILITY INFO BLOCK -->
                <h6 class="fw-bold text-muted text-uppercase small mb-3">
                    <i class="bi bi-building me-2"></i><?php echo htmlspecialchars($facilityType); ?> Details
                </h6>

                <div class="report-row">
                    <span class="report-label">Facility Name</span>
                    <span class="report-value"><?php echo htmlspecialchars($reportData['facility_name']); ?></span>
                </div>
                <div class="report-row">
                    <span class="report-label">Location</span>
                    <span class="report-value"><i class="bi bi-geo-alt me-1 text-muted"></i><?php echo htmlspecialchars($reportData['location']); ?></span>
                </div>
                <div class="report-row">
                    <span class="report-label">Contact</span>
                    <span class="report-value"><i class="bi bi-telephone me-1 text-muted"></i><?php echo htmlspecialchars($reportData['contact']); ?></span>
                </div>

                <hr class="divider-line">

                <!-- REQUEST SPECIFICS -->
                <h6 class="fw-bold text-muted text-uppercase small mb-3">
                    <i class="bi bi-clipboard-pulse me-2"></i>Request Details
                </h6>

                <?php if ($type === 'blood_bank'): ?>
                <div class="report-row">
                    <span class="report-label">Blood Group Requested</span>
                    <span class="report-value"><span class="badge bg-danger px-3 rounded-pill"><?php echo htmlspecialchars($reportData['blood_group']); ?></span></span>
                </div>
                <div class="report-row">
                    <span class="report-label">Units Required</span>
                    <span class="report-value fw-bold"><?php echo htmlspecialchars($reportData['units_needed']); ?> unit(s)</span>
                </div>
                <?php elseif ($type === 'hospital'): ?>
                <div class="report-row">
                    <span class="report-label">Organ Type</span>
                    <span class="report-value"><span class="badge bg-primary px-3 rounded-pill"><i class="bi bi-heart-pulse me-1"></i><?php echo htmlspecialchars($reportData['organ_type']); ?></span></span>
                </div>
                <?php endif; ?>

                <div class="report-row">
                    <span class="report-label">Current Status</span>
                    <span class="report-value">
                        <span class="badge bg-<?php echo $statusColor; ?> px-3 py-2 rounded-pill">
                            <i class="bi bi-<?php echo $statusIcon; ?> me-1"></i>
                            <?php echo ucfirst(htmlspecialchars($reportData['status'])); ?>
                        </span>
                    </span>
                </div>

                <hr class="divider-line">

                <!-- STATUS SUMMARY BOX -->
                <div class="p-4 rounded-3 text-center"
                     style="background: <?php echo $s === 'fulfilled' ? 'linear-gradient(135deg,#f0fff4,#dcfce7)' : ($s === 'pending' ? 'linear-gradient(135deg,#fffbeb,#fef9c3)' : 'linear-gradient(135deg,#fff1f2,#ffe4e6)'); ?>">
                    <i class="bi bi-<?php echo $statusIcon; ?> fs-1 text-<?php echo $statusColor; ?> mb-2 d-block"></i>
                    <h5 class="fw-bold text-<?php echo $statusColor; ?> mb-1">
                        <?php
                        if ($s === 'fulfilled') echo "Request Successfully Fulfilled";
                        elseif ($s === 'approved') echo "Request Approved";
                        elseif ($s === 'pending') echo "Awaiting Facility Response";
                        elseif ($s === 'rejected') echo "Request Rejected";
                        else echo "Status: " . ucfirst($s);
                        ?>
                    </h5>
                    <p class="text-muted small mb-0">
                        <?php
                        if ($s === 'fulfilled') echo "Your request has been processed and fulfilled by " . htmlspecialchars($reportData['facility_name']) . ".";
                        elseif ($s === 'pending') echo "Your request is under review by " . htmlspecialchars($reportData['facility_name']) . ". Please wait for their response.";
                        elseif ($s === 'rejected') echo "Please contact the facility or submit a new request.";
                        else echo "Please check back later for updates.";
                        ?>
                    </p>
                </div>

                <?php endif; ?>

            </div>

            <!-- Footer Actions -->
            <div class="px-4 pb-4 d-flex gap-3 flex-wrap no-print">
                <button onclick="window.print()" class="btn btn-dark action-btn">
                    <i class="bi bi-printer me-2"></i>Print Report
                </button>
                <a href="patient_dashboard.php" class="btn btn-outline-secondary action-btn">
                    <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Disclaimer -->
        <p class="text-center text-muted small mt-3 mb-1">
            <i class="bi bi-shield-check me-1"></i>
            This report is confidential and intended only for the patient registered on this system.
        </p>
        <div class="text-center text-muted small mt-2 pb-3">
            &copy; 2026 MediMatch | Saving Lives Through Smart Matching
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
