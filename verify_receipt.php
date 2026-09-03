<?php
// verify_receipt.php - Secure Public Verification Page for MediMatch Blood & Organ Receipts
$host = 'localhost';
$dbname = 'organ_blood_donation';
$username = 'root';
$password = '';

$type = isset($_GET['type']) && $_GET['type'] === 'organ' ? 'organ' : 'blood';
$request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
$user_token = isset($_GET['token']) ? trim($_GET['token']) : '';

$secret_key = 'MediMatch_Secure_Receipt_Key_2026';
$expected_token = hash_hmac('sha256', $type . '_receipt_' . $request_id, $secret_key);

$is_valid = false;
$receipt = null;
$error_message = '';

if ($request_id > 0 && !empty($user_token) && hash_equals($expected_token, $user_token)) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($type === 'organ') {
            $stmt = $pdo->prepare("
                SELECT o.request_id, o.patient_id, p.name AS patient_name, p.age, p.blood_group, 
                       o.organ_type, o.priority_score, o.status,
                       h.name AS bank_name, h.location AS bank_location, h.contact AS bank_contact
                FROM organ_requests o
                JOIN patients p ON o.patient_id = p.patient_id
                LEFT JOIN hospitals h ON o.hospital_id = h.hospital_id
                WHERE o.request_id = ?
            ");
            $stmt->execute([$request_id]);
            $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare("
                SELECT br.request_id, br.patient_id, br.patient_name, br.age, br.blood_group, 
                       br.units_needed, br.priority_score, br.status, br.request_date,
                       b.name AS bank_name, b.location AS bank_location, b.contact AS bank_contact
                FROM blood_requests br
                LEFT JOIN blood_banks b ON br.bank_id = b.bank_id
                WHERE br.request_id = ?
            ");
            $stmt->execute([$request_id]);
            $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($receipt) {
            $is_valid = true;
        } else {
            $error_message = "Receipt record not found in database.";
        }
    } catch (PDOException $e) {
        $error_message = "Database Error: " . htmlspecialchars($e->getMessage());
    }
} else {
    $error_message = "Invalid, missing, or tampered receipt verification token.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify <?php echo ucfirst($type); ?> Digital Receipt - MediMatch</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0fdf4 0%, #f8fafc 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .receipt-card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.08);
            max-width: 650px;
            width: 100%;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }
        .receipt-header {
            background: linear-gradient(135deg, #15803d 0%, #16a34a 100%);
            color: white;
            padding: 2rem 2.5rem;
        }
        .receipt-body {
            padding: 2rem 2.5rem;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.85rem 0;
            border-bottom: 1px dashed #e2e8f0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #64748b;
        }
        .info-value {
            font-weight: 600;
            color: #0f172a;
        }
        @media print {
            body { background: white; padding: 0; }
            .no-print { display: none !important; }
            .receipt-card { box-shadow: none; border: 1px solid #ccc; }
        }
    </style>
</head>
<body>
    <div class="receipt-card">
        <?php if ($is_valid && $receipt): ?>
            <div class="receipt-header">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="small opacity-75 text-uppercase fw-bold"><i class="bi bi-heart-pulse-fill me-1"></i>MediMatch Digital Verification</div>
                    <span class="badge bg-white text-success fw-bold px-3 py-1 rounded-pill">Authentic Receipt ✓</span>
                </div>
                <h3 class="fw-bold mb-1"><?php echo ucfirst($type); ?> Request Digital Receipt</h3>
                <p class="opacity-75 mb-0 small"><i class="bi bi-shield-check me-1"></i>Verified & Encrypted Transaction Record</p>
            </div>

            <div class="receipt-body">
                <div class="p-3 bg-success bg-opacity-10 rounded-4 border border-success border-opacity-25 d-flex align-items-center mb-4">
                    <i class="bi bi-patch-check-fill fs-2 text-success me-3"></i>
                    <div>
                        <h6 class="fw-bold text-success mb-0"><?php echo ucfirst($type); ?> Request Fulfilled & Verified</h6>
                        <small class="text-muted">Request ID: Req #<?php echo htmlspecialchars($receipt['request_id']); ?></small>
                    </div>
                </div>

                <h6 class="fw-bold text-muted text-uppercase small mb-3"><i class="bi bi-person-badge me-2"></i>Patient Details</h6>
                <div class="info-row">
                    <span class="info-label">Patient Name</span>
                    <span class="info-value"><?php echo htmlspecialchars($receipt['patient_name'] ?: 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Patient ID</span>
                    <span class="info-value">PAT-<?php echo htmlspecialchars($receipt['patient_id']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Age</span>
                    <span class="info-value"><?php echo htmlspecialchars($receipt['age']); ?> years</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Blood Group</span>
                    <span class="info-value"><span class="badge bg-danger fs-6 px-3 rounded-pill"><?php echo htmlspecialchars($receipt['blood_group']); ?></span></span>
                </div>

                <hr class="my-3" style="border-top: 2px dashed #cbd5e1;">

                <h6 class="fw-bold text-muted text-uppercase small mb-3"><i class="bi bi-building me-2"></i>Fulfillment Facility</h6>
                <div class="info-row">
                    <span class="info-label"><?php echo $type === 'organ' ? 'Hospital Name' : 'Blood Bank Name'; ?></span>
                    <span class="info-value"><?php echo htmlspecialchars($receipt['bank_name'] ?: ($type === 'organ' ? 'Network Hospital' : 'Network Blood Bank')); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Location</span>
                    <span class="info-value"><i class="bi bi-geo-alt me-1 text-primary"></i><?php echo htmlspecialchars($receipt['bank_location'] ?: 'Network Facility'); ?></span>
                </div>
                <?php if (!empty($receipt['bank_contact'])): ?>
                <div class="info-row">
                    <span class="info-label">Contact</span>
                    <span class="info-value"><i class="bi bi-telephone me-1 text-secondary"></i><?php echo htmlspecialchars($receipt['bank_contact']); ?></span>
                </div>
                <?php endif; ?>

                <hr class="my-3" style="border-top: 2px dashed #cbd5e1;">

                <h6 class="fw-bold text-muted text-uppercase small mb-3"><i class="bi bi-clipboard-check me-2"></i>Request Specifics</h6>
                <?php if ($type === 'organ'): ?>
                <div class="info-row">
                    <span class="info-label">Organ Type</span>
                    <span class="info-value"><span class="badge bg-primary px-3 py-1 fs-6"><i class="bi bi-heart-pulse me-1"></i><?php echo htmlspecialchars($receipt['organ_type']); ?></span></span>
                </div>
                <?php else: ?>
                <div class="info-row">
                    <span class="info-label">Units Requested</span>
                    <span class="info-value"><?php echo htmlspecialchars($receipt['units_needed']); ?> unit(s)</span>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <span class="info-label">Priority Score</span>
                    <span class="info-value"><?php echo htmlspecialchars($receipt['priority_score']); ?></span>
                </div>
                <?php if (!empty($receipt['request_date'])): ?>
                <div class="info-row">
                    <span class="info-label">Request Date</span>
                    <span class="info-value"><?php echo date('d M Y, h:i A', strtotime($receipt['request_date'])); ?></span>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <span class="info-label">Fulfillment Status</span>
                    <span class="info-value"><span class="badge bg-success px-3 py-1 rounded-pill">FULFILLED</span></span>
                </div>

                <div class="mt-4 no-print text-center">
                    <button onclick="window.print()" class="btn btn-dark rounded-pill px-4 fw-bold me-2">
                        <i class="bi bi-printer me-2"></i>Print Receipt
                    </button>
                    <a href="patient_dashboard.php" class="btn btn-outline-secondary rounded-pill px-4">Close</a>
                </div>
            </div>
        <?php else: ?>
            <div class="p-5 text-center">
                <i class="bi bi-shield-x fs-1 text-danger d-block mb-3"></i>
                <h4 class="fw-bold text-danger mb-2">Invalid or Tampered Receipt</h4>
                <p class="text-muted mb-4"><?php echo htmlspecialchars($error_message); ?></p>
                <a href="patient_dashboard.php" class="btn btn-primary rounded-pill px-4">Return to Dashboard</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
