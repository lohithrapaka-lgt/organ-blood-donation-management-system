<?php
ini_set('session.cookie_lifetime', 86400); // 1 day
ini_set('session.gc_maxlifetime', 86400);
session_start();

// Database connection details
$host = 'localhost';
$dbname = 'organ_blood_donation';
$db_username = 'root';
$db_password = '';

$message = "";
$messageType = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // 1. Validate inputs
    if (empty($email) || empty($password)) {
        $message = "Please enter both email and password.";
        $messageType = "danger";
    } else {
        try {
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $db_username, $db_password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 2. Fetch user
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // 3. User not found, or 4/5. Verify password incorrect
            if ($user && password_verify($password, $user['password'])) {
                // 3. SESSION STORAGE
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['ref_id'] = $user['reference_id']; // Using your explicit ref point terminology

                // 4. ROLE-BASED REDIRECTION
                if ($user['role'] === 'patient') {
                    header("Location: patient_dashboard.php");
                } elseif ($user['role'] === 'donor') {
                    header("Location: donor_dashboard.php");
                } elseif ($user['role'] === 'bloodbank') {
                    header("Location: bloodbank_dashboard.php");
                } elseif ($user['role'] === 'hospital') {
                    header("Location: hospital_dashboard.php");
                } elseif ($user['role'] === 'admin') {
                    header("Location: admin_dashboard.php");
                }
                exit(); // No message needed, direct redirect
            } else {
                $message = "Invalid email or password";
                $messageType = "danger";
            }
        } catch (PDOException $e) {
            die("Database Connection Error: " . htmlspecialchars($e->getMessage()));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - MediMatch</title>
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
            justify-content: center;
        }

        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            border: none;
            width: 100%;
            max-width: 450px;
        }

        .login-header {
            background: linear-gradient(135deg, #ff0844 0%, #ffb199 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .btn-login {
            background: linear-gradient(135deg, #ff0844 0%, #ffb199 100%);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 0.8rem;
            font-weight: 600;
            transition: all 0.3s;
            width: 100%;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 8, 68, 0.4);
            color: white;
        }

        .form-control {
            border-radius: 10px;
            padding: 0.75rem 1rem;
            border-color: #e9ecef;
        }

        .form-control:focus {
            box-shadow: 0 0 0 0.25rem rgba(255, 8, 68, 0.25);
            border-color: #ffb199;
        }

        .form-label {
            font-weight: 500;
            color: #525f7f;
            font-size: 0.9rem;
        }
    </style>
</head>

<body>

    <div class="container d-flex flex-column align-items-center justify-content-center">
        <div class="login-card">
            <div class="login-header">
                <h2 class="fw-bold mb-1"><i class="bi bi-shield-lock-fill me-2"></i>Welcome Back</h2>
                <p class="mb-0 opacity-75">Sign in to your account</p>
            </div>
            <div class="p-4 p-md-5">

                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show"
                        role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <form action="login.php" method="POST">
                    <div class="mb-4">
                        <label class="form-label">Email Address</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0"><i
                                    class="bi bi-envelope text-muted"></i></span>
                            <input type="email" name="email" class="form-control border-start-0" required
                                placeholder="name@example.com"
                                value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0"><i
                                    class="bi bi-lock text-muted"></i></span>
                            <input type="password" name="password" class="form-control border-start-0" required
                                placeholder="Your password">
                        </div>
                    </div>

                    <button type="submit" name="login" class="btn btn-login mt-2">
                        Login <i class="bi bi-box-arrow-in-right ms-1"></i>
                    </button>

                    <p class="text-center mt-4 text-muted small">
                        Don't have an account? <a href="register.php"
                            class="text-danger fw-bold text-decoration-none">Register here</a>
                    </p>
                </form>

            </div>
        </div>
        <div class="text-center text-muted small mt-4">
            &copy; 2026 MediMatch | Saving Lives Through Smart Matching
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>