<?php
session_start();

require_once 'priority_calc.php';

$host = 'localhost';
$dbname = 'organ_blood_donation';
$db_username = 'root';
$db_password = '';

$message = "";
$messageType = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
        $email = htmlspecialchars(trim($_POST['email']));
        $password = $_POST['password'];
        $role = htmlspecialchars(trim($_POST['role']));

        // Dynamic fields based on role
        $name = isset($_POST['name']) ? htmlspecialchars(trim($_POST['name'])) : null;
        $age = isset($_POST['age']) && $_POST['age'] !== '' ? htmlspecialchars(trim($_POST['age'])) : null;
        $blood_group = isset($_POST['blood_group']) ? htmlspecialchars(trim($_POST['blood_group'])) : null;

        $hospital_name = isset($_POST['hospital_name']) ? htmlspecialchars(trim($_POST['hospital_name'])) : null;
        $bank_name = isset($_POST['bank_name']) ? htmlspecialchars(trim($_POST['bank_name'])) : null;
        $location = isset($_POST['location']) ? htmlspecialchars(trim($_POST['location'])) : null;
        $contact = isset($_POST['contact']) ? htmlspecialchars(trim($_POST['contact'])) : null;
        $license_no = isset($_POST['license_no']) ? htmlspecialchars(trim($_POST['license_no'])) : null;
        $specialization = isset($_POST['specialization']) ? htmlspecialchars(trim($_POST['specialization'])) : null;
        $capacity = isset($_POST['capacity']) && $_POST['capacity'] !== '' ? htmlspecialchars(trim($_POST['capacity'])) : null;

        // 1. Validate common inputs
        if (empty($email) || empty($password) || empty($role)) {
            $message = "Email, Password, and Role are required.";
            $messageType = "danger";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Invalid email format.";
            $messageType = "danger";
        } elseif (strlen($password) < 6) {
            $message = "Password must be at least 6 characters long.";
            $messageType = "danger";
        } else {
            // 2. Check if email already exists
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->rowCount() > 0) {
                $message = "Email is already registered.";
                $messageType = "danger";
            } else {
                // 3. Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $reference_id = null;
                $user_phone = null;

                // Begin transaction
                $pdo->beginTransaction();

                try {
                    // 4. Role-based insertion with dynamic handling
                    if ($role === 'patient') {
                        if (empty($name) || empty($age) || empty($blood_group)) {
                            throw new Exception("Name, Age, and Blood Group are required for Patient.");
                        }
                        // Insert into patients table, but do not set request parameters
                        $stmt = $pdo->prepare("INSERT INTO patients (name, age, blood_group, status) VALUES (?, ?, ?, 'idle')");
                        $stmt->execute([$name, $age, $blood_group]);
                        $reference_id = $pdo->lastInsertId();
                        
                        $user_phone = null;
                    } elseif ($role === 'donor') {
                        if (empty($name) || empty($age) || empty($blood_group)) {
                            throw new Exception("Name, Age, and Blood Group are required for Donor.");
                        }
                        $stmt = $pdo->prepare("INSERT INTO donors (name, age, blood_group, donor_type, availability, verified, contact) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$name, $age, $blood_group, 'blood', 'available', 'no', $contact]);
                        $reference_id = $pdo->lastInsertId();
                        $user_phone = null;
                    } elseif ($role === 'hospital') {
                        if (empty($hospital_name) || empty($location) || empty($contact) || empty($license_no) || empty($specialization)) {
                            throw new Exception("Hospital Name, Location, Contact, License Number, and Specialization are required.");
                        }
                        $stmt = $pdo->prepare("INSERT INTO hospitals (name, location, contact, license_no, specialization, status) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$hospital_name, $location, $contact, $license_no, $specialization, 'pending']);
                        $reference_id = $pdo->lastInsertId();
                        $user_phone = $contact;
                        $age = null; // Enforce no age for hospital
                    } elseif ($role === 'bloodbank') {
                        if (empty($bank_name) || empty($location) || empty($contact) || empty($license_no) || empty($capacity)) {
                            throw new Exception("Blood Bank Name, Location, Contact, License Number, and Capacity are required.");
                        }
                        $stmt = $pdo->prepare("INSERT INTO blood_banks (name, location, contact, license_no, capacity, status) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$bank_name, $location, $contact, $license_no, $capacity, 'pending']);
                        $reference_id = $pdo->lastInsertId();
                        $user_phone = $contact;
                        $age = null; // Enforce no age for bloodbank
                    } elseif ($role === 'admin') {
                        $user_phone = null;
                        $age = null;
                    }

                    // 5. Insert into users table
                    // Use $user_phone which captures contact from hospital/bloodbank, else null. Age is null for institutions.
                    $stmt = $pdo->prepare("INSERT INTO users (email, password, phone, age, role, reference_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$email, $hashed_password, $user_phone, $age, $role, $reference_id]);

                    $pdo->commit();

                    $message = "Registration Successful! Redirecting to login...";
                    $messageType = "success";

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = "Registration failed: " . htmlspecialchars($e->getMessage());
                    $messageType = "danger";
                }
            }
        }
    }
} catch (PDOException $e) {
    die("Database Error: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration - MediMatch</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fe;
            min-height: 100vh;
            display: flex;
            align-items: center;
        }

        .register-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            border: none;
            margin-top: 2rem;
            margin-bottom: 2rem;
        }

        .register-header {
            background: linear-gradient(135deg, #ff0844 0%, #ffb199 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .btn-register {
            background: linear-gradient(135deg, #ff0844 0%, #ffb199 100%);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 0.8rem;
            font-weight: 600;
            transition: all 0.3s;
            width: 100%;
        }

        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 8, 68, 0.4);
            color: white;
        }

        .form-control,
        .form-select {
            border-radius: 10px;
            padding: 0.75rem 1rem;
            border-color: #e9ecef;
        }

        .form-control:focus,
        .form-select:focus {
            box-shadow: 0 0 0 0.25rem rgba(255, 8, 68, 0.25);
            border-color: #ffb199;
        }

        .form-label {
            font-weight: 500;
            color: #525f7f;
            font-size: 0.9rem;
        }

        .dynamic-section {
            display: none;
            /* hidden by default */
            animation: fadeIn 0.4s ease-in-out;
            background-color: #fdfdfd;
            border: 1px solid #f0f0f0;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-5px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>

<body>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="register-card">
                    <div class="register-header">
                        <h2 class="fw-bold mb-1"><i class="bi bi-heart-pulse-fill me-2"></i><span data-i18n="register" data-i18n-english="Join MediMatch">Join MediMatch</span></h2>
                        <p class="mb-0 opacity-75">Register as Patient, Donor, or Institution</p>
                    </div>
                    <div class="p-4 p-md-5">

                        <?php if (!empty($message)): ?>
                            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                                <?php if ($messageType === 'success'): ?>
                                    <i class="bi bi-check-circle-fill me-2"></i>
                                <?php else: ?>
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <?php endif; ?>
                                <?php echo $message; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($messageType === 'success'): ?>
                            <script>
                                setTimeout(function () {
                                    window.location.href = 'login.php';
                                }, 2000);
                            </script>
                        <?php else: ?>
                            <form action="register.php" method="POST" id="registerForm">

                                <!-- COMMON FIELDS -->
                                <div class="mb-3">
                                    <label class="form-label" data-i18n="role" data-i18n-english="Role">Role</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0"><i
                                                class="bi bi-person-badge text-muted"></i></span>
                                        <select name="role" id="roleSelect" class="form-select border-start-0" required
                                            onchange="toggleSections()">
                                            <option value="" disabled selected data-i18n="selectRole" data-i18n-english="Select your role...">Select your role...</option>
                                            <option value="patient" <?php echo (isset($_POST['role']) && $_POST['role'] == 'patient') ? 'selected' : ''; ?> data-i18n="patient" data-i18n-english="Patient">Patient</option>
                                            <option value="donor" <?php echo (isset($_POST['role']) && $_POST['role'] == 'donor') ? 'selected' : ''; ?> data-i18n="donor" data-i18n-english="Donor">Donor</option>
                                            <option value="hospital" <?php echo (isset($_POST['role']) && $_POST['role'] == 'hospital') ? 'selected' : ''; ?>>Hospital</option>
                                            <option value="bloodbank" <?php echo (isset($_POST['role']) && $_POST['role'] == 'bloodbank') ? 'selected' : ''; ?>>Blood Bank</option>
                                            <option value="admin" <?php echo (isset($_POST['role']) && $_POST['role'] == 'admin') ? 'selected' : ''; ?>>Admin</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label" data-i18n="email" data-i18n-english="Email Address">Email Address</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0"><i
                                                class="bi bi-envelope text-muted"></i></span>
                                        <input type="email" name="email" class="form-control border-start-0" required
                                            placeholder="name@example.com"
                                            value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label" data-i18n="password" data-i18n-english="Password">Password</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-end-0"><i
                                                class="bi bi-lock text-muted"></i></span>
                                        <input type="password" name="password" class="form-control border-start-0" required
                                            placeholder="Minimum 6 characters" minlength="6">
                                    </div>
                                </div>

                                <!-- DYNAMIC SECTION: PATIENT & DONOR -->
                                <div id="section-person" class="dynamic-section">
                                    <h6 class="fw-bold mb-3 text-primary"><i
                                            class="bi bi-person-lines-fill me-2"></i><span data-i18n="personalDetails" data-i18n-english="Personal Details">Personal Details</span></h6>

                                    <div class="mb-3">
                                        <label class="form-label" data-i18n="fullName" data-i18n-english="Full Name">Full Name</label>
                                        <input type="text" name="name" id="person_name" class="form-control"
                                            placeholder="John Doe">
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label" data-i18n="age" data-i18n-english="Age">Age</label>
                                            <input type="number" name="age" id="person_age" class="form-control"
                                                placeholder="Years" min="1" max="150">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label" data-i18n="bloodGroup" data-i18n-english="Blood Group">Blood Group</label>
                                            <select name="blood_group" id="person_blood" class="form-select">
                                                <option value="" disabled selected>Select...</option>
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
                                    </div>
                                </div>

                                <!-- DYNAMIC SECTION: HOSPITAL -->
                                <div id="section-hospital" class="dynamic-section">
                                    <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-building-add me-2"></i>Hospital
                                        Details</h6>

                                    <div class="mb-3">
                                        <label class="form-label">Hospital Name</label>
                                        <input type="text" name="hospital_name" id="hosp_name" class="form-control"
                                            placeholder="Central Hospital">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Location / Address</label>
                                        <input type="text" name="location" id="hosp_location" class="form-control"
                                            placeholder="123 Health Ave, City">
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Contact Number</label>
                                            <input type="tel" name="contact" id="hosp_contact" class="form-control"
                                                placeholder="e.g. +1 234 567">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">License Number</label>
                                            <input type="text" name="license_no" id="hosp_license" class="form-control"
                                                placeholder="HOSP-12345">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Specialization</label>
                                        <input type="text" name="specialization" id="hosp_specialization"
                                            class="form-control" placeholder="e.g. Cardiology, Pediatrics, General">
                                    </div>
                                </div>

                                <!-- DYNAMIC SECTION: BLOOD BANK -->
                                <div id="section-bloodbank" class="dynamic-section">
                                    <h6 class="fw-bold mb-3 text-primary"><i class="bi bi-droplet-half me-2"></i>Blood Bank
                                        Details</h6>

                                    <div class="mb-3">
                                        <label class="form-label">Blood Bank Name</label>
                                        <input type="text" name="bank_name" id="bb_name" class="form-control"
                                            placeholder="Community Blood Center">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Location / Address</label>
                                        <!-- Use same ID as hospital for generic script if needed, but separated for clarity -->
                                        <input type="text" name="location" id="bb_location" class="form-control"
                                            placeholder="456 Donor St, City">
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Contact Number</label>
                                            <input type="tel" name="contact" id="bb_contact" class="form-control"
                                                placeholder="e.g. +1 555 789">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">License Number</label>
                                            <input type="text" name="license_no" id="bb_license" class="form-control"
                                                placeholder="BB-98765">
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Storage Capacity (Units)</label>
                                        <input type="number" name="capacity" id="bb_capacity" class="form-control"
                                            placeholder="e.g. 5000" min="1">
                                    </div>
                                </div>

                                <!-- DYNAMIC SECTION: ADMIN -->
                                <div id="section-admin" class="dynamic-section">
                                    <h6 class="fw-bold mb-3 text-danger"><i
                                            class="bi bi-shield-lock me-2"></i>Administration</h6>
                                    <p class="text-muted small mb-0">You are requesting admin privileges. Only email and
                                        password are required.</p>
                                </div>

                                <button type="submit" name="register" class="btn btn-register mt-2">
                                    <span data-i18n="completeRegistration" data-i18n-english="Complete Registration">Complete Registration</span> <i class="bi bi-arrow-right-circle ms-1"></i>
                                </button>

                                <p class="text-center mt-4 text-muted small">
                                    Already have an account? <a href="login.php"
                                        class="text-danger fw-bold text-decoration-none">Log in here</a>
                                </p>
                            </form>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
            <div class="text-center text-muted small mt-4">
                &copy; 2026 MediMatch | Saving Lives Through Smart Matching
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSections() {
            const role = document.getElementById('roleSelect').value;
            const form = document.getElementById('registerForm');

            // References to sections
            const secPerson = document.getElementById('section-person');
            const secHospital = document.getElementById('section-hospital');
            const secBloodBank = document.getElementById('section-bloodbank');
            const secAdmin = document.getElementById('section-admin');

            // Hide all fields initially
            secPerson.style.display = 'none';
            secHospital.style.display = 'none';
            secBloodBank.style.display = 'none';
            secAdmin.style.display = 'none';

            // Disable all dynamic inputs to prevent submitting hidden fields
            const allDynamicInputs = document.querySelectorAll('.dynamic-section input, .dynamic-section select');
            allDynamicInputs.forEach(input => {
                input.disabled = true;
                input.required = false;
            });

            // Show specific fields based on selection and re-enable them
            if (role === 'patient' || role === 'donor') {
                secPerson.style.display = 'block';
                enableRequiredFields(['person_name', 'person_age', 'person_blood']);
            } else if (role === 'hospital') {
                secHospital.style.display = 'block';
                enableRequiredFields(['hosp_name', 'hosp_location', 'hosp_contact', 'hosp_license', 'hosp_specialization']);
            } else if (role === 'bloodbank') {
                secBloodBank.style.display = 'block';
                enableRequiredFields(['bb_name', 'bb_location', 'bb_contact', 'bb_license', 'bb_capacity']);
            } else if (role === 'admin') {
                secAdmin.style.display = 'block';
            }
        }

        function enableRequiredFields(ids) {
            ids.forEach(id => {
                const element = document.getElementById(id);
                if (element) {
                    element.disabled = false;
                    element.required = true;
                }
            });
        }

        // Run on init in case form was resubmitted
        window.addEventListener('DOMContentLoaded', (event) => {
            if (document.getElementById('roleSelect').value) {
                toggleSections();
            }
        });
    </script>
    <?php require 'language_switcher.php'; ?>
</body>

</html>