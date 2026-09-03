<?php
ini_set('session.cookie_lifetime', 86400); // 1 day
ini_set('session.gc_maxlifetime', 86400);
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['role'] !== 'donor') {
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
} catch (PDOException $e) {
    die("Database Connection Error: " . htmlspecialchars($e->getMessage()));
}

$message = "";
if (isset($_SESSION['success'])) {
    $message = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $message = $_SESSION['error'];
    unset($_SESSION['error']);
}

$donor_id = $_SESSION['ref_id'];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Accept/Reject Match Logic
    $response_id = $_POST['response_id'] ?? null;
    $patient_id = $_POST['patient_id'] ?? null;
    $action = $_POST['action'] ?? null;

    if ($response_id && $patient_id && $action) {
        $pdo->beginTransaction();
        try {
            if ($action === 'accept') {
                $stmtUpdateResp = $pdo->prepare("UPDATE donor_responses SET response = 'accepted' WHERE response_id = :response_id");
                $stmtUpdateResp->execute([':response_id' => $response_id]);

                $stmtUpdatePatient = $pdo->prepare("UPDATE patients SET status = 'approved' WHERE patient_id = :patient_id");
                $stmtUpdatePatient->execute([':patient_id' => $patient_id]);

                $message = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-check-circle-fill me-2'></i> Match successfully <strong>Accepted</strong>! Patient has been approved.</div>";
            } elseif ($action === 'reject') {
                $stmtUpdateResp = $pdo->prepare("UPDATE donor_responses SET response = 'rejected' WHERE response_id = :response_id");
                $stmtUpdateResp->execute([':response_id' => $response_id]);

                $stmtUpdatePatient = $pdo->prepare("UPDATE patients SET status = 'waiting_for_donor' WHERE patient_id = :patient_id");
                $stmtUpdatePatient->execute([':patient_id' => $patient_id]);

                $message = "<div class='alert alert-warning d-flex align-items-center' role='alert'><i class='bi bi-x-circle-fill me-2'></i> Match <strong>Rejected</strong>. returning patient to queue.</div>";

                // Immediately trigger matching again
                ob_start();
                include 'matching_system.php';
                ob_end_clean();
            }
            $pdo->commit();
            $_SESSION['success'] = $message;
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "<div class='alert alert-danger'>Error processing request: " . htmlspecialchars($e->getMessage()) . "</div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }

    // Profile Update Logic
    if (isset($_POST['update_profile'])) {
        $name = $_POST['name'];
        $age = $_POST['age'];
        $blood_group = $_POST['blood_group'];
        $contact = $_POST['contact'];
        $location = $_POST['location'];

        // Basic validation for contact: allow only numbers
        if (!empty($contact) && !is_numeric($contact)) {
            $_SESSION['error'] = "<div class='alert alert-danger'>Contact number must contain only digits.</div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }

        try {
            $stmt = $pdo->prepare("UPDATE donors SET name = ?, age = ?, blood_group = ?, contact = ?, location = ? WHERE donor_id = ?");
            $stmt->execute([$name, $age, $blood_group, $contact, $location, $donor_id]);
            $message = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-check-circle-fill me-2'></i> Profile updated successfully!</div>";
            $_SESSION['success'] = $message;
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } catch (PDOException $e) {
            $_SESSION['error'] = "<div class='alert alert-danger d-flex align-items-center' role='alert'>Error updating profile.</div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }

    // Set Availability Logic
    if (isset($_POST['set_available'])) {
        try {
            $stmt = $pdo->prepare("UPDATE donors SET availability = 'available' WHERE donor_id = ?");
            $stmt->execute([$donor_id]);

            // Trigger Automatic Matching Logic
            include_once 'match_logic.php';
            $matchResult = triggerMatching($pdo, $donor_id);

            $message = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-check-circle-fill me-2'></i> You are now successfully marked as available to donate! <br><small class='ms-4'>System: $matchResult</small></div>";
            $_SESSION['success'] = $message;
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } catch (PDOException $e) {
            $_SESSION['error'] = "<div class='alert alert-danger d-flex align-items-center' role='alert'>Error updating availability.</div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }

    // Camp Registration Logic
    if (isset($_POST['register_camp'])) {
        $camp_id = $_POST['camp_id'];

        $checkStmt = $pdo->prepare("SELECT * FROM camp_registrations WHERE donor_id = ? AND camp_id = ?");
        $checkStmt->execute([$donor_id, $camp_id]);

        if ($checkStmt->rowCount() > 0) {
            $_SESSION['error'] = "<div class='alert alert-warning d-flex align-items-center mb-0' id='campAlert'><i class='bi bi-exclamation-triangle-fill me-2'></i> You have already registered for this camp.</div>";
        } else {
            $stmt = $pdo->prepare("INSERT INTO camp_registrations (donor_id, camp_id) VALUES (?, ?)");
            $stmt->execute([$donor_id, $camp_id]);
            $_SESSION['success'] = "<div class='alert alert-success d-flex align-items-center mb-0' id='campAlert'><i class='bi bi-check-circle-fill me-2'></i> Successfully registered for the blood camp!</div>";
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Fetch all match requests (Including hospital info)
$query = "
    SELECT dr.response_id, dr.donor_id, dr.patient_id, p.name AS patient_name, p.organ_needed, p.blood_group, dr.response, h.name AS hospital_name 
    FROM donor_responses dr
    JOIN patients p ON dr.patient_id = p.patient_id
    LEFT JOIN organ_requests orq ON p.patient_id = orq.patient_id
    LEFT JOIN hospitals h ON orq.hospital_id = h.hospital_id
    WHERE dr.donor_id = ?
";
$stmtAll = $pdo->prepare($query);
$stmtAll->execute([$donor_id]);
$allRequests = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

// Fetch current donor data
$stmtDonor = $pdo->prepare("SELECT name, age, blood_group, contact, location, availability FROM donors WHERE donor_id = ?");
$stmtDonor->execute([$donor_id]);
$donorData = $stmtDonor->fetch(PDO::FETCH_ASSOC);
$my_blood_group = $donorData['blood_group'] ?? '';
$my_availability = $donorData['availability'] ?? '';

// Fetch Blood Camps
$camps = $pdo->query("SELECT * FROM blood_camps")->fetchAll(PDO::FETCH_ASSOC);

// Fetch My Registered Camps
$stmtMyCamps = $pdo->prepare("
    SELECT c.camp_id, c.name, c.location, c.date
    FROM camp_registrations cr
    JOIN blood_camps c ON cr.camp_id = c.camp_id
    WHERE cr.donor_id = ?
");
$stmtMyCamps->execute([$donor_id]);
$myCamps = $stmtMyCamps->fetchAll(PDO::FETCH_ASSOC);
$myRegisteredCampIds = array_column($myCamps, 'camp_id');

// Fetch Shortage Alerts (only for matching blood group and less than 5 units)
$stmtShortage = $pdo->prepare("
    SELECT b.name, b.location, i.blood_group, i.units_available
    FROM blood_inventory i
    JOIN blood_banks b ON i.bank_id = b.bank_id
    WHERE i.units_available < 5 AND i.blood_group = ?
");
$stmtShortage->execute([$my_blood_group]);
$shortages = $stmtShortage->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donor Dashboard - MediMatch</title>
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
            color: #ff416c;
            transform: translateX(5px);
        }

        /* Main Content Styling */
        .header-bg {
            background: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
            color: white;
            padding: 2.5rem;
            border-bottom-left-radius: 30px;
            border-bottom-right-radius: 30px;
            margin-bottom: 2rem;
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

        /* Cards and Elements */
        .card-custom {
            background: white;
            border: none;
            border-radius: 20px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.05);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .match-card {
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(0, 0, 0, 0.05);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.05);
            transition: all 0.3s;
        }

        .match-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.1);
        }

        .badge-blood {
            background-color: #ff4b2b;
            padding: 0.5rem 1.2rem;
            border-radius: 50px;
            color: white;
            font-weight: 600;
        }

        .badge-organ {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 0.5rem 1.2rem;
            border-radius: 50px;
            color: white;
            font-weight: 600;
        }

        .btn-accept {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
            border: none;
        }

        .btn-accept:hover {
            background: #0f857b;
            color: white;
        }

        .btn-reject {
            background: linear-gradient(135deg, #cb2d3e 0%, #ef473a 100%);
            color: white;
            border: none;
        }

        .btn-reject:hover {
            background: #ab2634;
            color: white;
        }
    </style>
</head>

<body>

    <div class="row g-0">

        <!-- Left Sidebar Navigation -->
        <div class="col-md-3 col-lg-2 sidebar-wrapper d-none d-md-block">
            <div class="text-center px-3 mb-5">
                <h3 class="fw-bold text-dark"><i class="bi bi-heart-pulse-fill text-danger me-2"></i>MediMatch</h3>
                <span class="badge bg-light text-secondary rounded-pill px-3 shadow-sm border">Donor Portal</span>
            </div>

            <div class="px-3">
                <div class="nav-link-custom active" onclick="showSection('dashboard-section', this)">
                    <i class="bi bi-grid-fill me-3 fs-5"></i> Dashboard
                </div>
                <div class="nav-link-custom" onclick="showSection('profile-section', this)">
                    <i class="bi bi-person-fill me-3 fs-5"></i> Profile
                </div>
                <div class="nav-link-custom" onclick="showSection('availability-section', this)">
                    <i class="bi bi-calendar-check-fill me-3 fs-5"></i> Availability
                </div>
                <div class="nav-link-custom" onclick="showSection('camps-section', this)">
                    <i class="bi bi-geo-alt-fill me-3 fs-5"></i> Blood Camps
                </div>
                <div class="nav-link-custom" onclick="showSection('shortage-section', this)">
                    <i class="bi bi-exclamation-triangle-fill me-3 fs-5 text-warning"></i> Shortage Alerts
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

            <header class="header-bg mb-4">
                <div>
                    <h2 class="fw-bold mb-1"><span id="headerTitle">Dashboard Overview</span></h2>
                    <p class="lead mb-0 opacity-75 fs-6">Manage your donor profile and life-saving matches.</p>
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
                                    <?php echo htmlspecialchars($donorData['name'] ?? 'Donor'); ?>
                                </h4>
                                <div class="d-flex flex-wrap gap-3 mt-2">
                                    <span class="badge bg-danger rounded-pill px-3 shadow-sm"><i
                                            class="bi bi-droplet-fill me-1"></i><?php echo htmlspecialchars($donorData['blood_group'] ?? 'N/A'); ?></span>
                                    <span class="text-muted small"><i
                                            class="bi bi-geo-alt-fill text-primary me-1"></i><?php echo htmlspecialchars($donorData['location'] ?: 'Not Provided'); ?></span>
                                    <span class="text-muted small"><i
                                            class="bi bi-telephone-fill text-primary me-1"></i><?php echo htmlspecialchars($donorData['contact'] ?: 'Not Provided'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h5 class="fw-bold text-dark mb-4"><i class="bi bi-bell-fill text-primary me-2"></i>Match Requests
                    </h5>
                    <?php if (count($allRequests) > 0): ?>
                        <div class="card-custom">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Patient Name</th>
                                            <th>Organ Needed</th>
                                            <th>Blood Group</th>
                                            <th>Status</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($allRequests as $req): ?>
                                            <tr>
                                                <td class="fw-bold"><i
                                                        class="bi bi-person-badge text-muted me-2"></i><?php echo htmlspecialchars($req['patient_name']); ?>
                                                </td>
                                                <td><span class="badge-organ px-2 py-1 fs-6"><i
                                                            class="bi bi-lungs me-1"></i><?php echo htmlspecialchars($req['organ_needed']); ?></span>
                                                </td>
                                                <td><span class="badge-blood px-2 py-1 fs-6"><i
                                                            class="bi bi-droplet-fill me-1"></i><?php echo htmlspecialchars($req['blood_group']); ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($req['response'] === 'pending'): ?>
                                                        <span class="badge bg-warning text-dark px-3 py-1 rounded-pill">
                                                            <i class="bi bi-hourglass-split me-1"></i> Waiting for hospital
                                                        </span>
                                                    <?php elseif ($req['response'] === 'accepted'): ?>
                                                        <span class="badge bg-success px-3 py-1 rounded-pill">
                                                            <i class="bi bi-check-circle-fill me-1"></i> Accepted by
                                                            <?php echo htmlspecialchars($req['hospital_name'] ?? 'Hospital'); ?>
                                                        </span>
                                                    <?php elseif ($req['response'] === 'rejected'): ?>
                                                        <span class="badge bg-danger px-3 py-1 rounded-pill">
                                                            <i class="bi bi-x-circle-fill me-1"></i> Rejected
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($req['response'] === 'pending'): ?>
                                                        <div class="d-flex justify-content-end gap-2">
                                                            <form method="POST" action="donor_dashboard.php" class="m-0">
                                                                <input type="hidden" name="response_id"
                                                                    value="<?php echo htmlspecialchars($req['response_id']); ?>">
                                                                <input type="hidden" name="patient_id"
                                                                    value="<?php echo htmlspecialchars($req['patient_id']); ?>">
                                                                <button type="submit" name="action" value="reject"
                                                                    class="btn btn-sm btn-danger rounded-pill px-3"><i
                                                                        class="bi bi-x-lg me-1"></i>Reject</button>
                                                            </form>
                                                            <form method="POST" action="donor_dashboard.php" class="m-0">
                                                                <input type="hidden" name="response_id"
                                                                    value="<?php echo htmlspecialchars($req['response_id']); ?>">
                                                                <input type="hidden" name="patient_id"
                                                                    value="<?php echo htmlspecialchars($req['patient_id']); ?>">
                                                                <button type="submit" name="action" value="accept"
                                                                    class="btn btn-sm btn-success rounded-pill px-3"><i
                                                                        class="bi bi-check-lg me-1"></i>Accept</button>
                                                            </form>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted fst-italic small">No further action</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                    <?php endif; ?>

                    <!-- REGISTERED BLOOD CAMPS (Moved from Camps Section) -->
                    <?php if (count($myCamps) > 0): ?>
                        <div class="card-custom mt-4 border-start border-4 border-success">
                            <h5 class="fw-bold text-success mb-3">
                                <i class="bi bi-calendar-check-fill me-2"></i>
                                Your Blood Camp Registrations
                            </h5>
                            <div class="row g-3">
                                <?php foreach ($myCamps as $camp): ?>
                                    <div class="col-md-6">
                                        <div class="p-3 border rounded bg-light shadow-sm">
                                            <h6 class="fw-bold mb-2 text-dark"><?php echo htmlspecialchars($camp['name']); ?>
                                            </h6>
                                            <div class="small">
                                                <p class="mb-1 text-muted"><i
                                                        class="bi bi-calendar-event me-2"></i><strong>Date:</strong>
                                                    <?php echo htmlspecialchars($camp['date']); ?></p>
                                                <p class="mb-1 text-muted"><i
                                                        class="bi bi-geo-alt-fill me-2"></i><strong>Location:</strong>
                                                    <?php echo htmlspecialchars($camp['location']); ?></p>
                                                <p class="mb-0"><span class="badge bg-success opacity-75">Registered</span></p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div id="profile-section" class="content-section">
                    <div class="card-custom">
                        <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-person-fill text-primary me-2"></i>Manage
                            Donor Profile</h5>
                        <form action="donor_dashboard.php" method="POST">
                            <div class="row g-4">
                                <div class="col-md-12">
                                    <label class="form-label fw-bold text-muted small">Full Legal Name</label>
                                    <input type="text" name="name" class="form-control form-control-lg" required
                                        value="<?php echo htmlspecialchars($donorData['name'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-muted small">Age</label>
                                    <input type="number" name="age" class="form-control form-control-lg" required
                                        min="18" max="100"
                                        value="<?php echo htmlspecialchars($donorData['age'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-muted small">Blood Group</label>
                                    <select name="blood_group" class="form-select form-select-lg" required>
                                        <option value="A+" <?php if ($my_blood_group == 'A+')
                                            echo 'selected'; ?>>A+
                                        </option>
                                        <option value="A-" <?php if ($my_blood_group == 'A-')
                                            echo 'selected'; ?>>A-
                                        </option>
                                        <option value="B+" <?php if ($my_blood_group == 'B+')
                                            echo 'selected'; ?>>B+
                                        </option>
                                        <option value="B-" <?php if ($my_blood_group == 'B-')
                                            echo 'selected'; ?>>B-
                                        </option>
                                        <option value="AB+" <?php if ($my_blood_group == 'AB+')
                                            echo 'selected'; ?>>AB+
                                        </option>
                                        <option value="AB-" <?php if ($my_blood_group == 'AB-')
                                            echo 'selected'; ?>>AB-
                                        </option>
                                        <option value="O+" <?php if ($my_blood_group == 'O+')
                                            echo 'selected'; ?>>O+
                                        </option>
                                        <option value="O-" <?php if ($my_blood_group == 'O-')
                                            echo 'selected'; ?>>O-
                                        </option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-muted small">Contact Number</label>
                                    <input type="text" name="contact" class="form-control form-control-lg" required
                                        placeholder="Numbers only" pattern="[0-9]+"
                                        value="<?php echo htmlspecialchars($donorData['contact'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold text-muted small">Location (City/Area)</label>
                                    <input type="text" name="location" class="form-control form-control-lg" required
                                        placeholder="Enter location"
                                        value="<?php echo htmlspecialchars($donorData['location'] ?? ''); ?>">
                                </div>
                                <div class="col-12 mt-4">
                                    <button type="submit" name="update_profile"
                                        class="btn btn-primary rounded-pill px-5 shadow-sm fw-bold">Save Profile
                                        Changes</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- AVAILABILITY SECTION -->
                <div id="availability-section" class="content-section">
                    <div class="card-custom text-center py-5">
                        <div class="mb-4">
                            <i class="bi <?php echo ($my_availability === 'available') ? 'bi-check-circle-fill text-success' : 'bi-dash-circle-fill text-warning'; ?>"
                                style="font-size: 4rem;"></i>
                        </div>
                        <h4 class="fw-bold text-dark">Current Status: <span
                                class="text-uppercase <?php echo ($my_availability === 'available') ? 'text-success' : 'text-warning'; ?>"><?php echo htmlspecialchars($my_availability ? $my_availability : 'Not Set'); ?></span>
                        </h4>
                        <p class="text-muted w-75 mx-auto mb-4">Triggering your availability allows hospitals and
                            patients to query your profile safely within the matching system actively!</p>
                        <form action="donor_dashboard.php" method="POST">
                            <button type="submit" name="set_available"
                                class="btn btn-success btn-lg rounded-pill px-5 shadow-sm fw-bold">
                                <i class="bi bi-heart-pulse-fill me-2"></i> I am available to donate
                            </button>
                        </form>
                    </div>
                </div>

                <!-- BLOOD CAMPS SECTION -->
                <div id="camps-section" class="content-section">
                    <?php if (!empty($message) && strpos($message, 'campAlert') !== false): ?>
                        <script>
                            // Force show camps section if a camp registration occurred
                            window.onload = function () {
                                showSection('camps-section');
                                setTimeout(function () {
                                    var alert = document.getElementById('campAlert');
                                    if (alert) {
                                        alert.style.transition = 'opacity 0.6s';
                                        alert.style.opacity = '0';
                                        setTimeout(() => alert.remove(), 600);
                                    }
                                }, 4000);
                            };
                        </script>
                    <?php endif; ?>
                    <div class="card-custom">
                        <h5 class="fw-bold text-dark mb-4"><i class="bi bi-geo-alt-fill text-danger me-2"></i>Upcoming
                            Blood Camps</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Camp Name</th>
                                        <th>Location</th>
                                        <th>Date</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($camps) > 0): ?>
                                        <?php foreach ($camps as $camp): ?>
                                            <tr>
                                                <td class="fw-bold"><?php echo htmlspecialchars($camp['name']); ?></td>
                                                <td class="text-muted"><i
                                                        class="bi bi-pin-map-fill me-1"></i><?php echo htmlspecialchars($camp['location']); ?>
                                                </td>
                                                <td><span class="badge bg-light text-dark border"><i
                                                            class="bi bi-calendar-event me-1"></i><?php echo htmlspecialchars($camp['date']); ?></span>
                                                </td>
                                                <td class="text-end">
                                                    <?php if (in_array($camp['camp_id'], $myRegisteredCampIds)): ?>
                                                        <button class="btn btn-sm btn-secondary rounded-pill px-3 fw-bold"
                                                            disabled><i class="bi bi-check-circle me-1"></i>Registered</button>
                                                    <?php else: ?>
                                                        <form method="POST" action="donor_dashboard.php" class="m-0">
                                                            <input type="hidden" name="camp_id"
                                                                value="<?php echo htmlspecialchars($camp['camp_id']); ?>">
                                                            <button type="submit" name="register_camp"
                                                                class="btn btn-sm btn-success rounded-pill px-3 fw-bold">Register</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">No active camps available at
                                                this time.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Registered camps moved to Dashboard Section -->
                </div>

                <!-- SHORTAGE ALERTS SECTION -->
                <div id="shortage-section" class="content-section">
                    <div class="card-custom">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold text-dark m-0"><i
                                    class="bi bi-exclamation-triangle-fill text-warning me-2"></i>Critical Shortage
                                Alerts</h5>
                            <span class="badge bg-danger rounded-pill px-3 py-2">Filtered:
                                <?php echo htmlspecialchars($my_blood_group); ?> Group Only</span>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Bank Name</th>
                                        <th>Location</th>
                                        <th>Blood Group</th>
                                        <th>Units Available</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($shortages) > 0): ?>
                                        <?php foreach ($shortages as $alert): ?>
                                            <tr>
                                                <td class="fw-bold"><?php echo htmlspecialchars($alert['name']); ?></td>
                                                <td class="text-muted"><i
                                                        class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($alert['location']); ?>
                                                </td>
                                                <td><span
                                                        class="badge bg-danger px-2 py-1"><?php echo htmlspecialchars($alert['blood_group']); ?></span>
                                                </td>
                                                <td><span
                                                        class="text-danger fw-bold fs-5"><?php echo htmlspecialchars($alert['units_available']); ?>
                                                        Units</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">No critical shortages
                                                detected for your blood group.</td>
                                        </tr>
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
            document.querySelectorAll('.content-section').forEach(sec => sec.classList.remove('active'));
            document.querySelectorAll('.nav-link-custom').forEach(nav => nav.classList.remove('active'));

            document.getElementById(sectionId).classList.add('active');
            if (element) element.classList.add('active');

            const titles = {
                'dashboard-section': 'Dashboard Overview',
                'profile-section': 'Manage Profile Settings',
                'availability-section': 'Donation Availability',
                'camps-section': 'Active Blood Camps',
                'shortage-section': 'System Shortage Alerts'
            };
            document.getElementById('headerTitle').innerText = titles[sectionId] || 'Dashboard Overview';
        }
    </script>
</body>

</html>