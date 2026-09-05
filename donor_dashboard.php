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

require_once 'emergency_alerts.php';
ensureBloodModuleSchema($pdo);

// Ensure database tables for Donor Engagement system
function ensureDonorEngagementTables($pdo) {
    try {
        $pdo->exec("
            ALTER TABLE donors 
            ADD COLUMN IF NOT EXISTS referral_code VARCHAR(50) NULL UNIQUE,
            ADD COLUMN IF NOT EXISTS referred_by VARCHAR(50) NULL,
            ADD COLUMN IF NOT EXISTS points INT DEFAULT 50;
        ");
        
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS donor_donations (
                donation_id INT AUTO_INCREMENT PRIMARY KEY,
                donor_id INT NOT NULL,
                patient_id INT NULL,
                donation_type ENUM('blood', 'organ') DEFAULT 'blood',
                blood_group_or_organ VARCHAR(50) NOT NULL,
                facility_name VARCHAR(255) NOT NULL,
                donation_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                verification_status ENUM('verified', 'pending') DEFAULT 'verified',
                verification_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (donor_id) REFERENCES donors(donor_id) ON DELETE CASCADE
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS mystery_gifts (
                claim_id INT AUTO_INCREMENT PRIMARY KEY,
                donor_id INT NOT NULL,
                gift_number INT NOT NULL DEFAULT 1,
                tracking_code VARCHAR(50) NOT NULL,
                recipient_name VARCHAR(255) NULL,
                phone VARCHAR(20) NULL,
                address TEXT NULL,
                city VARCHAR(100) NULL,
                pincode VARCHAR(20) NULL,
                status ENUM('unlocked', 'claimed', 'preparing', 'shipped', 'delivered') DEFAULT 'unlocked',
                claim_date DATETIME DEFAULT CURRENT_TIMESTAMP,
                delivery_date DATETIME NULL,
                FOREIGN KEY (donor_id) REFERENCES donors(donor_id) ON DELETE CASCADE
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS donor_badges (
                badge_id INT AUTO_INCREMENT PRIMARY KEY,
                donor_id INT NOT NULL,
                badge_key VARCHAR(50) NOT NULL,
                unlocked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (donor_id) REFERENCES donors(donor_id) ON DELETE CASCADE,
                UNIQUE KEY unique_donor_badge (donor_id, badge_key)
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS donor_notifications (
                notification_id INT AUTO_INCREMENT PRIMARY KEY,
                donor_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                type ENUM('verification', 'reward', 'level', 'badge', 'urgent', 'shipping', 'referral', 'system') DEFAULT 'system',
                is_read TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (donor_id) REFERENCES donors(donor_id) ON DELETE CASCADE
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS donor_referrals (
                referral_id INT AUTO_INCREMENT PRIMARY KEY,
                referrer_donor_id INT NOT NULL,
                referred_donor_id INT NOT NULL,
                status ENUM('pending', 'verified') DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (referrer_donor_id) REFERENCES donors(donor_id) ON DELETE CASCADE,
                FOREIGN KEY (referred_donor_id) REFERENCES donors(donor_id) ON DELETE CASCADE
            );
        ");
    } catch (Exception $e) {
        // Silently handle safely
    }
}

ensureDonorEngagementTables($pdo);

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

// Helper: Add Donor Notification
function addDonorNotification($pdo, $donor_id, $title, $message, $type = 'system') {
    try {
        $stmt = $pdo->prepare("INSERT INTO donor_notifications (donor_id, title, message, type) VALUES (?, ?, ?, ?)");
        $stmt->execute([$donor_id, $title, $message, $type]);
    } catch (Exception $e) {
        // Silently handle
    }
}

// Helper: Check and Unlock Mystery Gift after 2nd verified donation
function checkAndUnlockMysteryGift($pdo, $donor_id, $verified_count) {
    if ($verified_count >= 2) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM mystery_gifts WHERE donor_id = ? AND gift_number = 1");
        $stmt->execute([$donor_id]);
        if ($stmt->fetchColumn() == 0) {
            $tracking_code = 'MM' . rand(1000, 9999);
            $stmtIns = $pdo->prepare("INSERT INTO mystery_gifts (donor_id, gift_number, tracking_code, status) VALUES (?, 1, ?, 'unlocked')");
            $stmtIns->execute([$donor_id, $tracking_code]);

            addDonorNotification($pdo, $donor_id, "🎁 Mystery Gift Unlocked!", "Your 2nd donation has been verified! Your surprise healthcare gift is now ready to claim.", "reward");
        }
    }
}

// Helper: Evaluate & Grant Badges
function evaluateAndGrantBadges($pdo, $donor_id, $verified_count, $claimed_gifts_count, $referrals_count) {
    $badgesToGrant = ['first_step'];
    if ($verified_count >= 1) $badgesToGrant[] = 'life_saver';
    if ($verified_count >= 2) $badgesToGrant[] = 'blood_hero';
    if ($claimed_gifts_count >= 1) $badgesToGrant[] = 'reward_unlocker';
    if ($referrals_count >= 1) $badgesToGrant[] = 'community_builder';
    if ($verified_count >= 5) $badgesToGrant[] = 'gold_donor';
    if ($verified_count >= 10) $badgesToGrant[] = 'platinum_donor';

    $badgeLabels = [
        'first_step' => '🏅 First Step',
        'life_saver' => '❤️ Life Saver',
        'blood_hero' => '🩸 Blood Hero',
        'reward_unlocker' => '🎁 Reward Unlocker',
        'community_builder' => '👥 Community Builder',
        'gold_donor' => '🏆 Gold Donor',
        'platinum_donor' => '💎 Platinum Donor'
    ];

    foreach ($badgesToGrant as $bKey) {
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM donor_badges WHERE donor_id = ? AND badge_key = ?");
        $stmtCheck->execute([$donor_id, $bKey]);
        if ($stmtCheck->fetchColumn() == 0) {
            $stmtIns = $pdo->prepare("INSERT INTO donor_badges (donor_id, badge_key) VALUES (?, ?)");
            $stmtIns->execute([$donor_id, $bKey]);
            addDonorNotification($pdo, $donor_id, "🎖 New Achievement Unlocked!", "Congratulations! You unlocked the '" . ($badgeLabels[$bKey] ?? $bKey) . "' badge!", "badge");
        }
    }
}

// Helper: Ensure Referral Code
function ensureReferralCode($pdo, $donor_id) {
    $stmt = $pdo->prepare("SELECT referral_code FROM donors WHERE donor_id = ?");
    $stmt->execute([$donor_id]);
    $refCode = $stmt->fetchColumn();
    if (empty($refCode)) {
        $newCode = 'MEDI-DON-' . str_pad($donor_id, 4, '0', STR_PAD_LEFT) . rand(10, 99);
        $stmtUp = $pdo->prepare("UPDATE donors SET referral_code = ? WHERE donor_id = ?");
        $stmtUp->execute([$newCode, $donor_id]);
        return $newCode;
    }
    return $refCode;
}

$my_referral_code = ensureReferralCode($pdo, $donor_id);

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Mark Notification as Read
    if (isset($_POST['mark_notif_read'])) {
        $notif_id = (int)$_POST['notification_id'];
        $stmtNot = $pdo->prepare("UPDATE donor_notifications SET is_read = 1 WHERE notification_id = ? AND donor_id = ?");
        $stmtNot->execute([$notif_id, $donor_id]);
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Emergency Request Response ([ CONFIRM — I CAN HELP ❤️ ])
    if (isset($_POST['confirm_emergency_help']) || isset($_POST['respond_emergency'])) {
        $req_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
        
        // If request_id not passed but patient_id is, look up active request
        if ($req_id === 0 && isset($_POST['patient_id'])) {
            $stmtFindReq = $pdo->prepare("SELECT request_id FROM blood_requests WHERE patient_id = ? AND status IN ('pending', 'donors_responding') ORDER BY request_id DESC LIMIT 1");
            $stmtFindReq->execute([(int)$_POST['patient_id']]);
            $req_id = (int)$stmtFindReq->fetchColumn();
        }

        if ($req_id > 0) {
            $respResult = recordDonorEmergencyWillingness($pdo, $donor_id, $req_id);
            if ($respResult['success']) {
                if (!empty($respResult['already_responded'])) {
                    $_SESSION['error'] = "<div class='alert alert-warning d-flex align-items-center' role='alert'><i class='bi bi-exclamation-triangle-fill me-2 fs-5'></i> You have already registered your willingness to donate for this emergency.</div>";
                } else {
                    $_SESSION['success'] = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-check-circle-fill me-2 fs-5'></i> <div><strong>✓ RESPONSE REGISTERED</strong><br>Your willingness to donate has been sent to the blood bank. They will coordinate with you shortly.</div></div>";
                }
            } else {
                $_SESSION['error'] = "<div class='alert alert-danger'>Error submitting response: " . htmlspecialchars($respResult['message'] ?? 'Unknown error') . "</div>";
            }
        } else {
            $_SESSION['error'] = "<div class='alert alert-danger'>Invalid blood request specified.</div>";
        }
        header("Location: donor_dashboard.php?section=shortage-section");
        exit();
    }

    // Claim Mystery Gift Submission
    if (isset($_POST['claim_mystery_gift'])) {
        $claim_id = (int)($_POST['claim_id'] ?? 0);
        $recipient_name = trim($_POST['recipient_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $pincode = trim($_POST['pincode'] ?? '');

        if ($claim_id > 0 && !empty($recipient_name) && !empty($phone) && !empty($address)) {
            try {
                $stmtUpGift = $pdo->prepare("
                    UPDATE mystery_gifts 
                    SET recipient_name = ?, phone = ?, address = ?, city = ?, pincode = ?, status = 'shipped', claim_date = NOW()
                    WHERE claim_id = ? AND donor_id = ?
                ");
                $stmtUpGift->execute([$recipient_name, $phone, $address, $city, $pincode, $claim_id, $donor_id]);

                addDonorNotification($pdo, $donor_id, "🚚 Mystery Gift Shipped!", "Your mystery healthcare gift has been claimed and shipped to $city! Tracking active.", "shipping");

                $_SESSION['success'] = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-gift-fill me-2 fs-4'></i> <strong>Congratulations!</strong> Your Mystery Gift delivery is confirmed and shipped! 🚚</div>";
            } catch (Exception $e) {
                $_SESSION['error'] = "<div class='alert alert-danger'>Error processing gift claim.</div>";
            }
        } else {
            $_SESSION['error'] = "<div class='alert alert-warning'>Please fill in all delivery details.</div>";
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

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

                // Fetch patient details for donation record
                $stmtPatInfo = $pdo->prepare("SELECT name, blood_group, organ_needed, request_type FROM patients WHERE patient_id = ?");
                $stmtPatInfo->execute([$patient_id]);
                $patInfo = $stmtPatInfo->fetch(PDO::FETCH_ASSOC);

                $dType = ($patInfo['request_type'] === 'organ') ? 'organ' : 'blood';
                $dGroup = !empty($patInfo['organ_needed']) ? $patInfo['organ_needed'] : ($patInfo['blood_group'] ?? 'O+');
                $fName = "Network Medical Center";

                // Insert Genuine Verified Donation Record
                $stmtInsDonation = $pdo->prepare("
                    INSERT INTO donor_donations (donor_id, patient_id, donation_type, blood_group_or_organ, facility_name, verification_status)
                    VALUES (?, ?, ?, ?, ?, 'verified')
                ");
                $stmtInsDonation->execute([$donor_id, $patient_id, $dType, $dGroup, $fName]);

                // Award points (+250 points for verified donation)
                $pdo->prepare("UPDATE donors SET points = points + 250 WHERE donor_id = ?")->execute([$donor_id]);

                // Add Notification
                addDonorNotification($pdo, $donor_id, "🎉 Verified Donation Recorded!", "Your donation match was accepted and verified! +250 MediMatch Points awarded.", "verification");

                $message = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-check-circle-fill me-2 fs-5'></i> Match successfully <strong>Accepted</strong>! Verified donation recorded & +250 Points awarded!</div>";
            } elseif ($action === 'reject') {
                $stmtUpdateResp = $pdo->prepare("UPDATE donor_responses SET response = 'rejected' WHERE response_id = :response_id");
                $stmtUpdateResp->execute([':response_id' => $response_id]);

                $stmtUpdatePatient = $pdo->prepare("UPDATE patients SET status = 'waiting_for_donor' WHERE patient_id = :patient_id");
                $stmtUpdatePatient->execute([':patient_id' => $patient_id]);

                $message = "<div class='alert alert-warning d-flex align-items-center' role='alert'><i class='bi bi-x-circle-fill me-2'></i> Match <strong>Rejected</strong>. returning patient to queue.</div>";

                ob_start();
                include 'matching_system.php';
                ob_end_clean();
            }
            $pdo->commit();

            // Re-evaluate mystery gift and badges
            $stmtVc = $pdo->prepare("SELECT COUNT(*) FROM donor_donations WHERE donor_id = ? AND verification_status = 'verified'");
            $stmtVc->execute([$donor_id]);
            $vCount = (int)$stmtVc->fetchColumn();

            $stmtGc = $pdo->prepare("SELECT COUNT(*) FROM mystery_gifts WHERE donor_id = ? AND status IN ('claimed','shipped','delivered')");
            $stmtGc->execute([$donor_id]);
            $gCount = (int)$stmtGc->fetchColumn();

            $stmtRc = $pdo->prepare("SELECT COUNT(*) FROM donor_referrals WHERE referrer_donor_id = ? AND status = 'verified'");
            $stmtRc->execute([$donor_id]);
            $rCount = (int)$stmtRc->fetchColumn();

            checkAndUnlockMysteryGift($pdo, $donor_id, $vCount);
            evaluateAndGrantBadges($pdo, $donor_id, $vCount, $gCount, $rCount);

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

    // Set Availability Logic (Does NOT falsely award donation points)
    if (isset($_POST['set_available'])) {
        try {
            $stmt = $pdo->prepare("UPDATE donors SET availability = 'available' WHERE donor_id = ?");
            $stmt->execute([$donor_id]);

            include_once 'match_logic.php';
            $matchResult = triggerMatching($pdo, $donor_id);

            $message = "<div class='alert alert-success d-flex align-items-center' role='alert'><i class='bi bi-check-circle-fill me-2'></i> You are now marked as <strong>AVAILABLE</strong> to donate! <br><small class='ms-4'>System: $matchResult</small></div>";
            $_SESSION['success'] = $message;
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } catch (PDOException $e) {
            $_SESSION['error'] = "<div class='alert alert-danger d-flex align-items-center' role='alert'>Error updating availability.</div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }

    // Set Not Available Logic
    if (isset($_POST['set_not_available'])) {
        try {
            $stmt = $pdo->prepare("UPDATE donors SET availability = 'not_available' WHERE donor_id = ?");
            $stmt->execute([$donor_id]);

            $message = "<div class='alert alert-secondary d-flex align-items-center' role='alert'><i class='bi bi-dash-circle-fill me-2'></i> Status updated to <strong>NOT AVAILABLE</strong>.</div>";
            $_SESSION['success'] = $message;
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        } catch (PDOException $e) {
            $_SESSION['error'] = "<div class='alert alert-danger'>Error updating status.</div>";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }

    // Camp Registration / Interest Logic
    if (isset($_POST['register_camp'])) {
        $camp_id = (int)$_POST['camp_id'];
        $res = registerDonorInterestInCamp($pdo, $donor_id, $camp_id);

        if ($res['already_registered']) {
            $_SESSION['error'] = "<div class='alert alert-warning d-flex align-items-center mb-0' id='campAlert'><i class='bi bi-exclamation-triangle-fill me-2'></i> You have already registered your interest for this blood camp.</div>";
        } else {
            $_SESSION['success'] = "<div class='alert alert-success d-flex align-items-center mb-0' id='campAlert'><i class='bi bi-heart-fill text-danger me-2 fs-5'></i> <strong>Thank you!</strong> You have expressed interest in this blood camp! ❤️ <small class='ms-2 text-muted'>(Note: Showing interest helps event planning and does not count as a completed donation or grant rewards.)</small></div>";
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?section=camps-section");
        exit();
    }
}

// Fetch Current Donor Data
$stmtDonor = $pdo->prepare("SELECT name, age, blood_group, contact, location, availability, verified, donor_type, points, referral_code FROM donors WHERE donor_id = ?");
$stmtDonor->execute([$donor_id]);
$donorData = $stmtDonor->fetch(PDO::FETCH_ASSOC);
$my_blood_group = $donorData['blood_group'] ?? '';
$my_availability = $donorData['availability'] ?? 'available';
$my_verified = $donorData['verified'] ?? 'no';
$my_donor_type = $donorData['donor_type'] ?? 'blood';
$my_points = (int)($donorData['points'] ?? 50);

// Emergency matching rule eligibility
$isEligibleEmergencyBloodDonor = (
    $my_availability === 'available' &&
    $my_verified === 'yes' &&
    in_array($my_donor_type, ['blood', 'both'])
);

// Fetch Verified Donations Count & Records
$stmtDonations = $pdo->prepare("
    SELECT donation_id, donation_type, blood_group_or_organ, facility_name, donation_date, verification_status, verification_date
    FROM donor_donations 
    WHERE donor_id = ? AND verification_status = 'verified'
    ORDER BY donation_date DESC
");
$stmtDonations->execute([$donor_id]);
$verifiedDonations = $stmtDonations->fetchAll(PDO::FETCH_ASSOC);
$verified_donations_count = count($verifiedDonations);

// Calculate Donor Level & Progress
function getDonorLevelInfo($verified_count) {
    if ($verified_count >= 10) {
        return [
            'name' => 'Platinum Donor',
            'badge' => '💎 Platinum Donor',
            'icon' => 'bi-gem text-info',
            'bg' => 'linear-gradient(135deg, #e0f2fe 0%, #0284c7 100%)',
            'text_color' => '#0369a1',
            'badge_class' => 'bg-info text-dark',
            'next_target' => 10,
            'prev_target' => 10,
            'next_name' => 'Max Level Reached',
            'needed' => 0,
            'progress' => 100
        ];
    } elseif ($verified_count >= 5) {
        $needed = 10 - $verified_count;
        $progress = round((($verified_count - 5) / 5) * 100);
        return [
            'name' => 'Gold Donor',
            'badge' => '🥇 Gold Donor',
            'icon' => 'bi-trophy-fill text-warning',
            'bg' => 'linear-gradient(135deg, #fef3c7 0%, #f59e0b 100%)',
            'text_color' => '#b45309',
            'badge_class' => 'bg-warning text-dark',
            'next_target' => 10,
            'prev_target' => 5,
            'next_name' => 'Platinum Donor',
            'needed' => $needed,
            'progress' => min(100, max(0, $progress))
        ];
    } elseif ($verified_count >= 2) {
        $needed = 5 - $verified_count;
        $progress = round((($verified_count - 2) / 3) * 100);
        return [
            'name' => 'Silver Donor',
            'badge' => '🥈 Silver Donor',
            'icon' => 'bi-award-fill text-secondary',
            'bg' => 'linear-gradient(135deg, #f1f5f9 0%, #94a3b8 100%)',
            'text_color' => '#475569',
            'badge_class' => 'bg-secondary text-white',
            'next_target' => 5,
            'prev_target' => 2,
            'next_name' => 'Gold Donor',
            'needed' => $needed,
            'progress' => min(100, max(0, $progress))
        ];
    } elseif ($verified_count >= 1) {
        $needed = 2 - $verified_count;
        $progress = round((($verified_count - 1) / 1) * 100);
        return [
            'name' => 'Bronze Donor',
            'badge' => '🥉 Bronze Donor',
            'icon' => 'bi-shield-shaded text-danger',
            'bg' => 'linear-gradient(135deg, #ffedd5 0%, #ea580c 100%)',
            'text_color' => '#c2410c',
            'badge_class' => 'bg-danger text-white',
            'next_target' => 2,
            'prev_target' => 1,
            'next_name' => 'Silver Donor',
            'needed' => $needed,
            'progress' => min(100, max(0, $progress))
        ];
    } else {
        return [
            'name' => 'New Donor',
            'badge' => '🌱 New Donor',
            'icon' => 'bi-sprout-fill text-success',
            'bg' => 'linear-gradient(135deg, #f0fdf4 0%, #22c55e 100%)',
            'text_color' => '#15803d',
            'badge_class' => 'bg-success text-white',
            'next_target' => 1,
            'prev_target' => 0,
            'next_name' => 'Bronze Donor',
            'needed' => 1,
            'progress' => 0
        ];
    }
}

$levelInfo = getDonorLevelInfo($verified_donations_count);

// Fetch Mystery Gifts
$stmtGifts = $pdo->prepare("SELECT * FROM mystery_gifts WHERE donor_id = ? ORDER BY gift_number ASC");
$stmtGifts->execute([$donor_id]);
$mysteryGifts = $stmtGifts->fetchAll(PDO::FETCH_ASSOC);
$hasUnlockedGift = false;
$activeGift = null;
foreach ($mysteryGifts as $g) {
    if ($g['status'] === 'unlocked' || $g['status'] === 'claimed' || $g['status'] === 'preparing' || $g['status'] === 'shipped') {
        $hasUnlockedGift = true;
        $activeGift = $g;
        break;
    }
}

// Fetch Unlocked Badges
$stmtBadges = $pdo->prepare("SELECT badge_key FROM donor_badges WHERE donor_id = ?");
$stmtBadges->execute([$donor_id]);
$myUnlockedBadgeKeys = $stmtBadges->fetchAll(PDO::FETCH_COLUMN);

// Fetch Claimed Gifts Count & Referral Count for Badge evaluation
$stmtGc = $pdo->prepare("SELECT COUNT(*) FROM mystery_gifts WHERE donor_id = ? AND status IN ('claimed','shipped','delivered')");
$stmtGc->execute([$donor_id]);
$claimedGiftsCount = (int)$stmtGc->fetchColumn();

$stmtRc = $pdo->prepare("SELECT COUNT(*) FROM donor_referrals WHERE referrer_donor_id = ? AND status = 'verified'");
$stmtRc->execute([$donor_id]);
$referralsCount = (int)$stmtRc->fetchColumn();

checkAndUnlockMysteryGift($pdo, $donor_id, $verified_donations_count);
evaluateAndGrantBadges($pdo, $donor_id, $verified_donations_count, $claimedGiftsCount, $referralsCount);

// Re-fetch Badges after auto-evaluation
$stmtBadges->execute([$donor_id]);
$myUnlockedBadgeKeys = $stmtBadges->fetchAll(PDO::FETCH_COLUMN);

// Fetch Notifications
$stmtNotifs = $pdo->prepare("SELECT * FROM donor_notifications WHERE donor_id = ? ORDER BY created_at DESC LIMIT 15");
$stmtNotifs->execute([$donor_id]);
$notifications = $stmtNotifs->fetchAll(PDO::FETCH_ASSOC);
$unreadNotifCount = 0;
foreach ($notifications as $n) {
    if (!$n['is_read']) $unreadNotifCount++;
}

// Fetch Active Match Requests
$queryAllReq = "
    SELECT dr.response_id, dr.donor_id, dr.patient_id, p.name AS patient_name, p.organ_needed, p.blood_group, dr.response, h.name AS hospital_name 
    FROM donor_responses dr
    JOIN patients p ON dr.patient_id = p.patient_id
    LEFT JOIN organ_requests orq ON p.patient_id = orq.patient_id
    LEFT JOIN hospitals h ON orq.hospital_id = h.hospital_id
    WHERE dr.donor_id = ?
    ORDER BY dr.response_id DESC
";
$stmtAll = $pdo->prepare($queryAllReq);
$stmtAll->execute([$donor_id]);
$allRequests = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

// Fetch Active Emergency Shortage Alerts strictly matching this donor's blood group
$donorEmergencyAlerts = getShortagesForDonorBloodGroup($pdo, $my_blood_group);

// Fetch this donor's responses to emergency requests
$stmtMyResponses = $pdo->prepare("SELECT request_id, status FROM donor_responses WHERE donor_id = ? AND request_id IS NOT NULL");
$stmtMyResponses->execute([$donor_id]);
$myResponses = $stmtMyResponses->fetchAll(PDO::FETCH_KEY_PAIR); // request_id => status

// Fetch Blood Camps & Registered Camps using central helper
$camps = getBloodCamps($pdo);
$stmtMyCamps = $pdo->prepare("SELECT camp_id FROM camp_registrations WHERE donor_id = ?");
$stmtMyCamps->execute([$donor_id]);
$myRegisteredCampIds = $stmtMyCamps->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donor Engagement & Rewards Dashboard - MediMatch</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7fe;
            color: #2d3748;
        }

        .sidebar-wrapper {
            background-color: #ffffff;
            box-shadow: 2px 0 20px rgba(0, 0, 0, 0.04);
            min-height: 100vh;
            padding-top: 2rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-link-custom {
            color: #4a5568;
            font-weight: 600;
            padding: 0.9rem 1.4rem;
            border-radius: 12px;
            margin-bottom: 0.5rem;
            transition: all 0.25s ease;
            cursor: pointer;
            display: flex;
            align-items: center;
        }

        .nav-link-custom:hover,
        .nav-link-custom.active {
            background: linear-gradient(135deg, #fff5f5 0%, #ffe3e3 100%);
            color: #e53e3e;
            transform: translateX(4px);
        }

        .header-bg {
            background: linear-gradient(135deg, #e53e3e 0%, #dd6b20 100%);
            color: white;
            padding: 2.2rem 2.5rem;
            border-bottom-left-radius: 28px;
            border-bottom-right-radius: 28px;
            box-shadow: 0 10px 30px rgba(229, 62, 62, 0.25);
        }

        .content-section {
            display: none;
            animation: slideUpFade 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
        }

        .content-section.active {
            display: block;
        }

        @keyframes slideUpFade {
            from {
                opacity: 0;
                transform: translateY(12px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card-custom {
            background: white;
            border: 1px solid rgba(226, 232, 240, 0.8);
            border-radius: 20px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.04);
            padding: 1.8rem;
            margin-bottom: 1.8rem;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }

        .card-custom:hover {
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.07);
        }

        .summary-card {
            border-radius: 20px;
            padding: 1.5rem;
            color: white;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
        }

        .gift-box-glow {
            animation: giftPulse 2s infinite ease-in-out;
        }

        @keyframes giftPulse {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(234, 179, 8, 0.5); }
            70% { transform: scale(1.03); box-shadow: 0 0 0 15px rgba(234, 179, 8, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(234, 179, 8, 0); }
        }

        .badge-grid-item {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            padding: 1.2rem 1rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .badge-grid-item.unlocked {
            background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%);
            border-color: #22c55e;
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.15);
        }

        .badge-grid-item.locked {
            opacity: 0.55;
            filter: grayscale(80%);
        }

        .badge-grid-item:hover {
            transform: translateY(-4px);
        }

        .notification-dropdown {
            width: 360px;
            max-height: 420px;
            overflow-y: auto;
            border-radius: 16px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }

        .timeline-step {
            display: flex;
            align-items: center;
            position: relative;
            padding-bottom: 1.2rem;
        }

        .timeline-step:last-child {
            padding-bottom: 0;
        }

        .timeline-step::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 30px;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }

        .timeline-step:last-child::before {
            display: none;
        }

        .timeline-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            margin-right: 1rem;
            z-index: 1;
        }
    </style>
</head>

<body>

    <div class="row g-0">

        <!-- Sidebar Navigation -->
        <div class="col-md-3 col-lg-2 sidebar-wrapper d-none d-md-block">
            <div class="text-center px-3 mb-4">
                <h3 class="fw-bold text-danger mb-1"><i class="bi bi-heart-pulse-fill me-2"></i>MediMatch</h3>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1 fw-bold" data-i18n="donorPortal" data-i18n-english="Donor Portal">Donor Portal</span>
            </div>

            <div class="px-3">
                <div class="nav-link-custom active" onclick="showSection('dashboard-section', this)">
                    <i class="bi bi-grid-fill me-3 fs-5"></i> <span data-i18n="dashboard" data-i18n-english="Dashboard">Dashboard</span>
                </div>
                <div class="nav-link-custom" onclick="showSection('rewards-section', this)">
                    <i class="bi bi-gift-fill me-3 fs-5 text-warning"></i> <span data-i18n="rewards" data-i18n-english="Rewards & Gifts">Rewards & Gifts</span>
                </div>
                <div class="nav-link-custom" onclick="showSection('history-section', this)">
                    <i class="bi bi-journal-check me-3 fs-5 text-success"></i> <span data-i18n="donationHistory" data-i18n-english="Donation History">Donation History</span>
                </div>
                <div class="nav-link-custom" onclick="showSection('achievements-section', this)">
                    <i class="bi bi-trophy-fill me-3 fs-5 text-primary"></i> Achievements
                </div>
                <div class="nav-link-custom" onclick="showSection('referral-section', this)">
                    <i class="bi bi-people-fill me-3 fs-5 text-info"></i> Refer & Earn
                </div>
                <div class="nav-link-custom" onclick="showSection('profile-section', this)">
                    <i class="bi bi-person-fill me-3 fs-5"></i> <span data-i18n="profileAvailability" data-i18n-english="Profile & Availability">Profile & Availability</span>
                </div>
                <div class="nav-link-custom" onclick="showSection('camps-section', this)">
                    <i class="bi bi-geo-alt-fill me-3 fs-5"></i> Blood Camps
                </div>
                <div class="nav-link-custom" onclick="showSection('shortage-section', this)">
                    <i class="bi bi-exclamation-triangle-fill me-3 fs-5 text-warning"></i> Shortages
                </div>

                <hr class="my-4 text-muted">

                <a href="logout.php" class="nav-link-custom text-danger text-decoration-none border border-danger border-opacity-25 rounded-3 bg-danger bg-opacity-10 mt-auto">
                    <i class="bi bi-box-arrow-right me-3 fs-5"></i> Logout
                </a>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="col-md-9 col-lg-10" style="min-height: 100vh;">

            <!-- Header Bar with Notifications -->
            <header class="header-bg d-flex flex-column flex-md-row align-items-md-center justify-content-between">
                <div class="mb-3 mb-md-0">
                    <h2 class="fw-bold mb-1">❤️ Welcome, <?php echo htmlspecialchars($donorData['name'] ?? 'Donor'); ?>!</h2>
                    <p class="lead mb-0 opacity-90 fs-6">Thank you for being part of MediMatch & saving lives.</p>
                </div>

                <!-- Notifications Dropdown & Quick Badges -->
                <div class="d-flex align-items-center gap-3">
                    <span class="badge <?php echo $levelInfo['badge_class']; ?> rounded-pill px-3 py-2 fs-6 shadow-sm">
                        <i class="bi <?php echo $levelInfo['icon']; ?> me-1"></i><?php echo $levelInfo['name']; ?>
                    </span>

                    <!-- Notification Dropdown -->
                    <div class="dropdown">
                        <button class="btn btn-light rounded-circle p-2 position-relative shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="width: 45px; height: 45px;">
                            <i class="bi bi-bell-fill fs-5 text-secondary"></i>
                            <?php if ($unreadNotifCount > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                    <?php echo $unreadNotifCount; ?>
                                </span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end notification-dropdown p-2 shadow">
                            <li class="dropdown-header fw-bold text-uppercase d-flex justify-content-between align-items-center border-bottom pb-2">
                                <span><i class="bi bi-bell me-1"></i> Notifications</span>
                                <span class="badge bg-secondary"><?php echo count($notifications); ?> Total</span>
                            </li>
                            <?php if (count($notifications) > 0): ?>
                                <?php foreach ($notifications as $notif): ?>
                                    <li class="my-1">
                                        <?php 
                                            $isUrgent = ($notif['type'] === 'urgent');
                                            $bgClass = $notif['is_read'] ? 'bg-light' : ($isUrgent ? 'bg-danger bg-opacity-10 border-start border-4 border-danger' : 'bg-success bg-opacity-10 border-start border-4 border-success');
                                        ?>
                                        <div class="p-2 rounded-3 border-bottom <?php echo $bgClass; ?>">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <small class="fw-bold <?php echo $isUrgent ? 'text-danger' : 'text-dark'; ?>">
                                                    <?php if ($isUrgent): ?><i class="bi bi-broadcast me-1 pulse-glow"></i><?php endif; ?>
                                                    <?php echo htmlspecialchars($notif['title']); ?>
                                                </small>
                                                <small class="text-muted" style="font-size: 0.75rem;"><?php echo date('d M, h:i A', strtotime($notif['created_at'])); ?></small>
                                            </div>
                                            <p class="small text-muted mb-1"><?php echo htmlspecialchars($notif['message']); ?></p>
                                            <div class="d-flex justify-content-between align-items-center mt-2">
                                                <?php if ($isUrgent): ?>
                                                    <button type="button" class="btn btn-sm btn-danger rounded-pill px-3 py-1 fw-bold" style="font-size: 0.75rem;" onclick="showSection('shortage-section')">
                                                        <i class="bi bi-exclamation-octagon-fill me-1"></i>VIEW EMERGENCY
                                                    </button>
                                                <?php else: ?>
                                                    <span></span>
                                                <?php endif; ?>
                                                <?php if (!$notif['is_read']): ?>
                                                    <form method="POST" action="donor_dashboard.php" class="m-0 text-end">
                                                        <input type="hidden" name="notification_id" value="<?php echo $notif['notification_id']; ?>">
                                                        <button type="submit" name="mark_notif_read" class="btn btn-link p-0 text-success small fw-bold text-decoration-none">Mark Read</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="p-3 text-center text-muted small">No notifications yet.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </header>

            <div class="container px-4 py-4">

                <?php if (!empty($message)) echo $message; ?>

                <!-- 1. SUMMARY CARDS TOP SECTION -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-lg-3">
                        <div class="summary-card" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <small class="text-uppercase fw-bold opacity-75 d-block">Verified Donations</small>
                                    <h2 class="fw-extrabold mb-0"><?php echo $verified_donations_count; ?></h2>
                                </div>
                                <i class="bi bi-droplet-fill fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="summary-card" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <small class="text-uppercase fw-bold opacity-75 d-block">MediMatch Points</small>
                                    <h2 class="fw-extrabold mb-0"><?php echo $my_points; ?></h2>
                                </div>
                                <i class="bi bi-trophy-fill fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="summary-card" style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <small class="text-uppercase fw-bold opacity-75 d-block">Donor Level</small>
                                    <h5 class="fw-bold mb-0 text-truncate"><?php echo $levelInfo['badge']; ?></h5>
                                </div>
                                <i class="bi bi-award-fill fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="summary-card <?php echo ($verified_donations_count >= 2 && $hasUnlockedGift) ? 'gift-box-glow' : ''; ?>" style="background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <small class="text-uppercase fw-bold opacity-75 d-block">Next Reward</small>
                                    <h5 class="fw-bold mb-0">
                                        <?php if ($verified_donations_count >= 2): ?>
                                            🎁 Gift Ready!
                                        <?php else: ?>
                                            <?php echo $verified_donations_count; ?> / 2 Verified
                                        <?php endif; ?>
                                    </h5>
                                </div>
                                <i class="bi bi-gift-fill fs-1 opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- DASHBOARD MAIN SECTION -->
                <div id="dashboard-section" class="content-section active">

                    <!-- AUTOMATIC EMERGENCY BLOOD ALERT BANNER -->
                    <?php if ($isEligibleEmergencyBloodDonor && count($donorEmergencyAlerts) > 0): ?>
                        <?php foreach ($donorEmergencyAlerts as $emerg): ?>
                            <div class="card-custom border-2 border-danger shadow-sm mb-4" style="background: linear-gradient(135deg, #fff5f5 0%, #ffffff 100%);">
                                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3 pb-2 border-bottom border-danger border-opacity-25">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-danger rounded-pill px-3 py-1 pulse-glow fw-bold fs-6">
                                            <i class="bi bi-broadcast me-1"></i> 🚨 EMERGENCY BLOOD ALERT
                                        </span>
                                        <span class="text-danger fw-bold"><?php echo htmlspecialchars($emerg['blood_group']); ?> Blood Urgently Needed</span>
                                    </div>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1">
                                        <i class="bi bi-patch-check-fill me-1"></i> Your Blood Group Matches
                                    </span>
                                </div>

                                <div class="row align-items-center g-3">
                                    <div class="col-md-8">
                                        <h5 class="fw-bold text-dark mb-2"><?php echo htmlspecialchars($emerg['blood_group']); ?> blood is urgently needed.</h5>
                                        
                                        <div class="d-flex flex-wrap gap-3 p-3 bg-white rounded-4 border mb-2">
                                            <div>
                                                <small class="text-muted text-uppercase d-block fw-semibold" style="font-size: 0.72rem;">Current Available Stock</small>
                                                <span class="fw-bold text-danger fs-5"><?php echo (int)$emerg['units_available']; ?> units</span>
                                            </div>
                                            <div class="border-start ps-3">
                                                <small class="text-muted text-uppercase d-block fw-semibold" style="font-size: 0.72rem;">Required</small>
                                                <span class="fw-bold text-dark fs-5"><?php echo (int)$emerg['units_needed']; ?> units</span>
                                            </div>
                                            <div class="border-start ps-3">
                                                <small class="text-muted text-uppercase d-block fw-semibold" style="font-size: 0.72rem;">Location</small>
                                                <span class="fw-bold text-dark"><i class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo htmlspecialchars($emerg['bank_name'] . (!empty($emerg['bank_location']) ? ' (' . $emerg['bank_location'] . ')' : '')); ?></span>
                                            </div>
                                        </div>

                                        <p class="text-muted small mb-1">Your registered blood group matches this requirement.</p>
                                        <p class="text-danger fw-semibold small mb-0">❤️ Your help could make a difference.</p>
                                    </div>

                                    <div class="col-md-4 text-md-end">
                                        <div class="d-flex flex-column flex-sm-row justify-content-md-end gap-2">
                                            <button type="button" class="btn btn-outline-secondary rounded-pill px-4 fw-bold" data-bs-toggle="modal" data-bs-target="#emergReqModal_<?php echo $emerg['request_id']; ?>">
                                                <i class="bi bi-eye me-1"></i> VIEW REQUEST
                                            </button>

                                            <form method="POST" action="donor_dashboard.php" class="m-0">
                                                <input type="hidden" name="patient_id" value="<?php echo $emerg['patient_id']; ?>">
                                                <button type="submit" name="respond_emergency" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm">
                                                    <i class="bi bi-heart-fill me-1"></i> I CAN HELP
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Emergency Request View Modal -->
                            <div class="modal fade" id="emergReqModal_<?php echo $emerg['request_id']; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content border-0 shadow-lg rounded-4">
                                        <div class="modal-header border-bottom-0">
                                            <h5 class="modal-title fw-bold text-dark">
                                                <i class="bi bi-broadcast text-danger me-2"></i>Emergency Blood Requirement
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body pt-0">
                                            <div class="p-3 bg-danger bg-opacity-10 border border-danger border-opacity-25 rounded-4 mb-3 text-center">
                                                <span class="badge bg-danger rounded-pill px-3 py-1 mb-2 fs-6"><?php echo htmlspecialchars($emerg['blood_group']); ?> Needed</span>
                                                <h3 class="fw-bold text-dark mb-0"><?php echo (int)$emerg['units_needed']; ?> Unit(s) Required</h3>
                                                <small class="text-danger fw-semibold">Current Network Stock: <?php echo (int)$emerg['units_available']; ?> Units</small>
                                            </div>
                                            <div class="p-3 bg-light rounded-4 border mb-3">
                                                <div class="mb-2"><strong class="text-dark"><i class="bi bi-hospital me-2 text-danger"></i>Dispensing Facility:</strong> <?php echo htmlspecialchars($emerg['bank_name']); ?></div>
                                                <div class="mb-2"><strong class="text-dark"><i class="bi bi-geo-alt me-2 text-danger"></i>Location:</strong> <?php echo htmlspecialchars($emerg['bank_location']); ?></div>
                                                <div class="mb-0"><strong class="text-dark"><i class="bi bi-shield-check me-2 text-success"></i>Privacy Notice:</strong> Protected medical request. Only donation center logistics are shared.</div>
                                            </div>
                                            <p class="small text-muted mb-0">By clicking "I CAN HELP", you submit an urgent assistance offer. The healthcare center will coordinate your authorized donation process.</p>
                                        </div>
                                        <div class="modal-footer border-top-0">
                                            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                                            <form method="POST" action="donor_dashboard.php" class="m-0">
                                                <input type="hidden" name="patient_id" value="<?php echo $emerg['patient_id']; ?>">
                                                <button type="submit" name="respond_emergency" class="btn btn-danger rounded-pill px-4 fw-bold">
                                                    <i class="bi bi-heart-fill me-1"></i> I CAN HELP ❤️
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- URGENT BLOOD REQUIREMENTS ALERT SECTION (FILTERED TO DONOR BLOOD GROUP ONLY) -->
                    <?php if (count($donorEmergencyAlerts) > 0): ?>
                        <div class="card-custom border-2 border-danger shadow-sm mb-4" style="background: linear-gradient(135deg, #fff5f5 0%, #ffffff 100%);">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <h5 class="fw-bold text-danger mb-0"><i class="bi bi-exclamation-octagon-fill me-2"></i>🚨 Urgent Matching Requirements</h5>
                                <span class="badge bg-danger rounded-pill px-3 py-1 fw-bold">Matching <?php echo htmlspecialchars($my_blood_group); ?> Profile</span>
                            </div>
                            <div class="row g-3">
                                <?php foreach ($donorEmergencyAlerts as $urg): 
                                    $reqId = (int)$urg['request_id'];
                                    $myStatus = $myResponses[$reqId] ?? null;
                                    $deficit = max(0, (int)$urg['units_needed'] - (int)$urg['units_available']);
                                ?>
                                    <div class="col-md-6">
                                        <div class="p-3 bg-white border border-danger border-opacity-25 rounded-4 shadow-sm h-100 d-flex flex-column justify-content-between">
                                            <div>
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span class="badge bg-danger rounded-pill px-3 py-1 fs-6 fw-bold"><?php echo htmlspecialchars($urg['blood_group']); ?></span>
                                                    <span class="badge bg-danger text-white text-uppercase small pulse-glow fw-bold">⚠️ Critical Requirement</span>
                                                </div>
                                                <h6 class="fw-bold text-dark mb-1">🚨 Critical Blood Requirement</h6>
                                                <div class="small text-muted mb-2">
                                                    Required: <strong class="text-dark"><?php echo (int)$urg['units_needed']; ?> units</strong> &bull; 
                                                    Available: <strong class="text-dark"><?php echo (int)$urg['units_available']; ?> units</strong> &bull; 
                                                    Shortage: <strong class="text-danger">-<?php echo $deficit; ?> units</strong>
                                                </div>
                                                <div class="small text-muted mb-2">
                                                    <div><i class="bi bi-hospital me-1 text-danger"></i><strong><?php echo htmlspecialchars($urg['bank_name']); ?></strong></div>
                                                    <div><i class="bi bi-geo-alt me-1 text-danger"></i><?php echo htmlspecialchars($urg['bank_location']); ?></div>
                                                </div>
                                                <div class="text-danger small fw-bold mb-3">
                                                    <i class="bi bi-heart-fill me-1"></i>Your registered blood group (<?php echo htmlspecialchars($my_blood_group); ?>) matches this emergency.
                                                </div>
                                            </div>

                                            <div>
                                                <?php if ($myStatus === 'Willing to Donate'): ?>
                                                    <button class="btn btn-warning rounded-pill w-100 fw-bold shadow-sm" disabled>
                                                        🟡 WILLING TO DONATE &bull; Bank Notified
                                                    </button>
                                                <?php elseif ($myStatus === 'Confirmed'): ?>
                                                    <button class="btn btn-primary rounded-pill w-100 fw-bold shadow-sm" disabled>
                                                        🔵 DONOR CONFIRMED &bull; Awaiting Donation Visit
                                                    </button>
                                                <?php elseif ($myStatus === 'Completed'): ?>
                                                    <button class="btn btn-success rounded-pill w-100 fw-bold shadow-sm" disabled>
                                                        🟢 DONATION COMPLETED & VERIFIED
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-danger rounded-pill w-100 fw-bold shadow-sm" onclick='openEmergencyModal(<?php echo json_encode($urg, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                                        <i class="bi bi-heart-fill me-2"></i>I CAN HELP ❤️
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- MATCH REQUESTS SECTION -->
                    <div class="card-custom">
                        <h5 class="fw-bold text-dark mb-4"><i class="bi bi-bell-fill text-primary me-2"></i>Match Requests</h5>
                        <?php if (count($allRequests) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Patient Name</th>
                                            <th>Request Type</th>
                                            <th>Blood Group</th>
                                            <th>Status</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($allRequests as $req): ?>
                                            <tr>
                                                <td class="fw-bold"><i class="bi bi-person-badge text-muted me-2"></i><?php echo htmlspecialchars($req['patient_name']); ?></td>
                                                <td>
                                                    <?php if (!empty($req['organ_needed'])): ?>
                                                        <span class="badge bg-primary rounded-pill px-3 py-1"><i class="bi bi-heart-pulse me-1"></i><?php echo htmlspecialchars($req['organ_needed']); ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger rounded-pill px-3 py-1"><i class="bi bi-droplet-fill me-1"></i>Blood Donation</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><span class="badge bg-danger px-2 py-1 fs-6"><?php echo htmlspecialchars($req['blood_group']); ?></span></td>
                                                <td>
                                                    <?php if ($req['response'] === 'pending'): ?>
                                                        <span class="badge bg-warning text-dark px-3 py-1 rounded-pill"><i class="bi bi-hourglass-split me-1"></i> Waiting Response</span>
                                                    <?php elseif ($req['response'] === 'accepted'): ?>
                                                        <span class="badge bg-success px-3 py-1 rounded-pill"><i class="bi bi-check-circle-fill me-1"></i> Accepted by <?php echo htmlspecialchars($req['hospital_name'] ?? 'Facility'); ?></span>
                                                    <?php elseif ($req['response'] === 'rejected'): ?>
                                                        <span class="badge bg-danger px-3 py-1 rounded-pill"><i class="bi bi-x-circle-fill me-1"></i> Rejected</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <?php if ($req['response'] === 'pending'): ?>
                                                        <div class="d-flex justify-content-end gap-2">
                                                            <form method="POST" action="donor_dashboard.php" class="m-0">
                                                                <input type="hidden" name="response_id" value="<?php echo htmlspecialchars($req['response_id']); ?>">
                                                                <input type="hidden" name="patient_id" value="<?php echo htmlspecialchars($req['patient_id']); ?>">
                                                                <button type="submit" name="action" value="reject" class="btn btn-sm btn-outline-danger rounded-pill px-3"><i class="bi bi-x-lg me-1"></i>Reject</button>
                                                            </form>
                                                            <form method="POST" action="donor_dashboard.php" class="m-0">
                                                                <input type="hidden" name="response_id" value="<?php echo htmlspecialchars($req['response_id']); ?>">
                                                                <input type="hidden" name="patient_id" value="<?php echo htmlspecialchars($req['patient_id']); ?>">
                                                                <button type="submit" name="action" value="accept" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm"><i class="bi bi-check-lg me-1"></i>Accept</button>
                                                            </form>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted fst-italic small">Completed</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center py-3 mb-0">No active match requests pending at this time.</p>
                        <?php endif; ?>
                    </div>

                    <!-- 3. YOUR MEDIMATCH IMPACT & LEVEL PROGRESS -->
                    <div class="card-custom border-2 border-primary" style="background: linear-gradient(135deg, #f0f7ff 0%, #ffffff 100%);">
                        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
                            <div>
                                <h5 class="fw-bold text-primary mb-1"><i class="bi bi-heart-pulse-fill me-2"></i>Your MediMatch Impact</h5>
                                <p class="text-muted small mb-0">"Your verified contributions are helping strengthen the MediMatch donor community."</p>
                            </div>
                            <span class="badge <?php echo $levelInfo['badge_class']; ?> rounded-pill px-4 py-2 fs-6 shadow-sm">
                                <i class="bi <?php echo $levelInfo['icon']; ?> me-1"></i><?php echo $levelInfo['name']; ?>
                            </span>
                        </div>

                        <div class="row g-3 text-center mb-4">
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-white border rounded-4 shadow-sm">
                                    <small class="text-muted text-uppercase fw-bold d-block mb-1">Donations</small>
                                    <span class="fw-bold text-success fs-4"><?php echo $verified_donations_count; ?></span>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-white border rounded-4 shadow-sm">
                                    <small class="text-muted text-uppercase fw-bold d-block mb-1">Points</small>
                                    <span class="fw-bold text-warning fs-4"><?php echo $my_points; ?></span>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-white border rounded-4 shadow-sm">
                                    <small class="text-muted text-uppercase fw-bold d-block mb-1">Badges</small>
                                    <span class="fw-bold text-primary fs-4"><?php echo count($myUnlockedBadgeKeys); ?> / 7</span>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-white border rounded-4 shadow-sm">
                                    <small class="text-muted text-uppercase fw-bold d-block mb-1">Referrals</small>
                                    <span class="fw-bold text-info fs-4"><?php echo $referralsCount; ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Progress Bar to Next Level -->
                        <div>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold text-dark small">Level Progression: <?php echo $levelInfo['name']; ?></span>
                                <span class="text-muted small"><?php echo $verified_donations_count; ?> / <?php echo $levelInfo['next_target']; ?> verified donations</span>
                            </div>
                            <div class="progress rounded-pill mb-2" style="height: 14px;">
                                <div class="progress-bar bg-success progress-bar-striped progress-bar-animated" role="progressbar" style="width: <?php echo $levelInfo['progress']; ?>%;"></div>
                            </div>
                            <small class="text-muted fst-italic">
                                <?php if ($levelInfo['needed'] > 0): ?>
                                    Complete <strong><?php echo $levelInfo['needed']; ?> more verified donation(s)</strong> to reach <strong><?php echo $levelInfo['next_name']; ?></strong>!
                                <?php else: ?>
                                    🎉 Highest Level Reached! Thank you for being a top life saver.
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>

                </div>

                <!-- 4. REWARDS & MYSTERY GIFTS SECTION -->
                <div id="rewards-section" class="content-section">
                    <div class="card-custom border-2 border-warning shadow-sm" style="background: linear-gradient(135deg, #fffdf0 0%, #ffffff 100%);">
                        <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
                            <div>
                                <h4 class="fw-bold text-warning mb-1"><i class="bi bi-gift-fill me-2"></i>Mystery Gift Rewards</h4>
                                <p class="text-muted small mb-0">Complete verified donations to unlock surprise healthcare & wellness gifts (Value ₹200–₹300).</p>
                            </div>
                            <span class="badge bg-warning text-dark rounded-pill px-3 py-2 fw-bold fs-6">
                                <i class="bi bi-stars me-1"></i>Verified Reward System
                            </span>
                        </div>

                        <!-- Mystery Gift Status Panel -->
                        <div class="row align-items-center g-4 mb-4">
                            <div class="col-md-5 text-center">
                                <div class="p-4 bg-white border border-warning border-opacity-50 rounded-4 shadow-sm gift-box-wrapper">
                                    <div class="mb-3">
                                        <i class="bi <?php echo ($verified_donations_count >= 2) ? 'bi-box2-heart-fill text-warning' : 'bi-lock-fill text-muted'; ?>" style="font-size: 4.5rem;"></i>
                                    </div>
                                    <h5 class="fw-bold text-dark mb-1">MYSTERY GIFT #1</h5>
                                    <p class="text-muted small mb-3">Healthcare & Wellness Kit (~₹200–₹300)</p>

                                    <?php if ($verified_donations_count >= 2): ?>
                                        <?php if ($activeGift && ($activeGift['status'] === 'claimed' || $activeGift['status'] === 'shipped' || $activeGift['status'] === 'delivered')): ?>
                                            <span class="badge bg-success rounded-pill px-4 py-2 fs-6 mb-3"><i class="bi bi-check-circle-fill me-1"></i>Gift Claimed & Shipped</span>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-warning btn-lg rounded-pill px-4 fw-bold shadow gift-box-glow" data-bs-toggle="modal" data-bs-target="#claimGiftModal">
                                                <i class="bi bi-gift me-2"></i>CLAIM MY MYSTERY GIFT
                                            </button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <button class="btn btn-secondary rounded-pill px-4 fw-bold" disabled>
                                            <i class="bi bi-lock-fill me-2"></i>🔒 Locked (<?php echo $verified_donations_count; ?> / 2 Verified)
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="col-md-7">
                                <h6 class="fw-bold text-dark mb-3"><i class="bi bi-info-circle-fill text-warning me-2"></i>How to Unlock Mystery Gifts</h6>
                                <ol class="list-group list-group-numbered mb-3 shadow-sm rounded-4">
                                    <li class="list-group-item border-0 d-flex align-items-center">
                                        <div class="ms-2 me-auto">
                                            <div class="fw-bold">Step 1: Set Availability & Accept Match</div>
                                            <small class="text-muted">Respond to patient matches or urgent blood requirements.</small>
                                        </div>
                                    </li>
                                    <li class="list-group-item border-0 d-flex align-items-center">
                                        <div class="ms-2 me-auto">
                                            <div class="fw-bold">Step 2: Authorized/Verified Donation Completion</div>
                                            <small class="text-muted">Hospital or system records your completed donation (+250 Pts).</small>
                                        </div>
                                    </li>
                                    <li class="list-group-item border-0 d-flex align-items-center">
                                        <div class="ms-2 me-auto">
                                            <div class="fw-bold">Step 3: Complete 2 Verified Donations</div>
                                            <small class="text-muted">Reaching 2 verified donations automatically unlocks your Mystery Gift!</small>
                                        </div>
                                    </li>
                                </ol>

                                <!-- Progress Bar -->
                                <div class="p-3 bg-white rounded-4 border">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <small class="fw-bold text-muted">Mystery Gift Progression</small>
                                        <small class="fw-bold text-success"><?php echo min(2, $verified_donations_count); ?> / 2 Donations Verified</small>
                                    </div>
                                    <div class="progress rounded-pill" style="height: 12px;">
                                        <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo min(100, ($verified_donations_count / 2) * 100); ?>%;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Active Gift Delivery Tracker (If Claimed) -->
                        <?php if ($activeGift && in_array($activeGift['status'], ['claimed', 'preparing', 'shipped', 'delivered'])): ?>
                            <div class="p-4 bg-white rounded-4 border border-success border-opacity-50 mt-4">
                                <h6 class="fw-bold text-success mb-3"><i class="bi bi-truck me-2"></i>Gift Delivery Tracking (#<?php echo htmlspecialchars($activeGift['tracking_code']); ?>)</h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="timeline-step">
                                            <div class="timeline-icon bg-success text-white"><i class="bi bi-check-lg"></i></div>
                                            <div>
                                                <small class="fw-bold d-block text-dark">Gift Unlocked & Confirmed</small>
                                                <small class="text-muted"><?php echo htmlspecialchars($activeGift['recipient_name']); ?> (<?php echo htmlspecialchars($activeGift['city']); ?>)</small>
                                            </div>
                                        </div>
                                        <div class="timeline-step">
                                            <div class="timeline-icon bg-success text-white"><i class="bi bi-box-seam-fill"></i></div>
                                            <div>
                                                <small class="fw-bold d-block text-dark">Package Prepared</small>
                                                <small class="text-muted">Healthcare wellness items selected</small>
                                            </div>
                                        </div>
                                        <div class="timeline-step">
                                            <div class="timeline-icon <?php echo in_array($activeGift['status'], ['shipped', 'delivered']) ? 'bg-success text-white' : 'bg-secondary text-white'; ?>"><i class="bi bi-truck"></i></div>
                                            <div>
                                                <small class="fw-bold d-block text-dark">Shipped 🚚</small>
                                                <small class="text-muted">In transit to destination pincode <?php echo htmlspecialchars($activeGift['pincode']); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="p-3 bg-light rounded-3 border">
                                            <small class="text-muted text-uppercase fw-bold d-block mb-1">Delivery Address</small>
                                            <p class="small text-dark mb-0 fw-semibold"><?php echo htmlspecialchars($activeGift['address']); ?>, <?php echo htmlspecialchars($activeGift['city']); ?> - <?php echo htmlspecialchars($activeGift['pincode']); ?></p>
                                            <small class="text-muted">Contact: <?php echo htmlspecialchars($activeGift['phone']); ?></small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 5. ACHIEVEMENTS & BADGES SECTION -->
                <div id="achievements-section" class="content-section">
                    <div class="card-custom">
                        <div class="d-flex align-items-center justify-content-between mb-4">
                            <div>
                                <h4 class="fw-bold text-primary mb-1"><i class="bi bi-trophy-fill me-2"></i>My Achievements</h4>
                                <p class="text-muted small mb-0">Unlock special MediMatch milestone badges as you contribute.</p>
                            </div>
                            <span class="badge bg-primary rounded-pill px-3 py-2"><?php echo count($myUnlockedBadgeKeys); ?> / 7 Unlocked</span>
                        </div>

                        <div class="row g-3">
                            <?php
                            $allBadges = [
                                'first_step' => ['title' => 'First Step', 'icon' => 'bi-award-fill text-primary', 'desc' => 'Registered & completed donor profile'],
                                'life_saver' => ['title' => 'Life Saver', 'icon' => 'bi-heart-fill text-danger', 'desc' => 'Completed 1st verified donation'],
                                'blood_hero' => ['title' => 'Blood Hero', 'icon' => 'bi-droplet-fill text-danger', 'desc' => 'Completed 2+ verified donations'],
                                'reward_unlocker' => ['title' => 'Reward Unlocker', 'icon' => 'bi-gift-fill text-warning', 'desc' => 'Unlocked & claimed 1st Mystery Gift'],
                                'community_builder' => ['title' => 'Community Builder', 'icon' => 'bi-people-fill text-info', 'desc' => 'Invited a verified donor friend'],
                                'gold_donor' => ['title' => 'Gold Donor', 'icon' => 'bi-trophy-fill text-warning', 'desc' => 'Reached Gold Donor status (5+ donations)'],
                                'platinum_donor' => ['title' => 'Platinum Donor', 'icon' => 'bi-gem text-info', 'desc' => 'Reached Platinum status (10+ donations)']
                            ];

                            foreach ($allBadges as $key => $bData):
                                $isUnlocked = in_array($key, $myUnlockedBadgeKeys);
                            ?>
                                <div class="col-6 col-md-4 col-lg-3">
                                    <div class="badge-grid-item <?php echo $isUnlocked ? 'unlocked' : 'locked'; ?>">
                                        <div class="mb-2">
                                            <i class="bi <?php echo $bData['icon']; ?> fs-1"></i>
                                        </div>
                                        <h6 class="fw-bold text-dark mb-1"><?php echo $bData['title']; ?></h6>
                                        <small class="text-muted d-block small" style="font-size: 0.75rem;"><?php echo $bData['desc']; ?></small>
                                        <?php if ($isUnlocked): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1 mt-2 small">Unlocked ✓</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary rounded-pill px-2 py-1 mt-2 small"><i class="bi bi-lock me-1"></i>Locked</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- 6. DONATION HISTORY SECTION -->
                <div id="history-section" class="content-section">
                    <div class="card-custom">
                        <div class="d-flex align-items-center justify-content-between mb-4">
                            <div>
                                <h4 class="fw-bold text-success mb-1"><i class="bi bi-journal-check me-2"></i>My Donation History</h4>
                                <p class="text-muted small mb-0">Record of all genuinely verified donations completed through MediMatch.</p>
                            </div>
                            <span class="badge bg-success rounded-pill px-3 py-2"><?php echo $verified_donations_count; ?> Verified Donations</span>
                        </div>

                        <?php if (count($verifiedDonations) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Type</th>
                                            <th>Group / Organ</th>
                                            <th>Facility Name</th>
                                            <th>Donation Date</th>
                                            <th>Status</th>
                                            <th>Feedback</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($verifiedDonations as $don): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge <?php echo ($don['donation_type'] === 'organ') ? 'bg-primary' : 'bg-danger'; ?> rounded-pill px-3 py-1">
                                                        <i class="bi <?php echo ($don['donation_type'] === 'organ') ? 'bi-heart-pulse' : 'bi-droplet-fill'; ?> me-1"></i>
                                                        <?php echo ucfirst($don['donation_type']); ?>
                                                    </span>
                                                </td>
                                                <td class="fw-bold"><?php echo htmlspecialchars($don['blood_group_or_organ']); ?></td>
                                                <td class="text-muted"><i class="bi bi-building me-1"></i><?php echo htmlspecialchars($don['facility_name']); ?></td>
                                                <td><small class="text-muted"><?php echo date('d M Y, h:i A', strtotime($don['donation_date'])); ?></small></td>
                                                <td><span class="badge bg-success rounded-pill px-3 py-1"><i class="bi bi-patch-check-fill me-1"></i>VERIFIED</span></td>
                                                <td>
                                                    <form method="POST" action="submit_feedback.php" class="d-flex gap-1 align-items-center">
                                                        <input type="hidden" name="submit_feedback" value="1">
                                                        <input type="hidden" name="donation_id" value="<?php echo (int)$don['donation_id']; ?>">
                                                        <input type="hidden" name="redirect" value="donor_dashboard.php">
                                                        <select name="rating" class="form-select form-select-sm" required aria-label="Rating">
                                                            <option value="">Rate</option>
                                                            <option value="5">5</option>
                                                            <option value="4">4</option>
                                                            <option value="3">3</option>
                                                            <option value="2">2</option>
                                                            <option value="1">1</option>
                                                        </select>
                                                        <input type="text" name="feedback" class="form-control form-control-sm" maxlength="1000" placeholder="Feedback" aria-label="Feedback">
                                                        <button type="submit" class="btn btn-outline-success btn-sm" aria-label="Submit feedback"><i class="bi bi-send"></i></button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="p-4 text-center text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                <p class="mb-0">No verified completed donations recorded yet. Accept match requests to complete your first verified donation!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 7. REFERRAL SYSTEM SECTION -->
                <div id="referral-section" class="content-section">
                    <div class="card-custom border-2 border-info" style="background: linear-gradient(135deg, #f0f9ff 0%, #ffffff 100%);">
                        <div class="d-flex align-items-center justify-content-between mb-4">
                            <div>
                                <h4 class="fw-bold text-info mb-1"><i class="bi bi-people-fill me-2"></i>Grow the Donor Community</h4>
                                <p class="text-muted small mb-0">Invite friends to join MediMatch. Earn +100 points for each verified donor onboarding!</p>
                            </div>
                            <span class="badge bg-info text-dark rounded-pill px-3 py-2 fw-bold">Referral Bonus: +100 Pts</span>
                        </div>

                        <div class="row align-items-center g-4">
                            <div class="col-md-6">
                                <div class="p-4 bg-white border border-info border-opacity-50 rounded-4 shadow-sm text-center">
                                    <small class="text-muted text-uppercase fw-bold d-block mb-2">Your Unique Referral Code</small>
                                    <h3 class="fw-extrabold text-primary mb-3 font-monospace tracking-wider"><?php echo htmlspecialchars($my_referral_code); ?></h3>
                                    <div class="d-flex justify-content-center gap-2">
                                        <button onclick="copyReferralCode('<?php echo htmlspecialchars($my_referral_code); ?>')" class="btn btn-info rounded-pill px-4 fw-bold text-white shadow-sm">
                                            <i class="bi bi-clipboard me-2"></i>Copy Code
                                        </button>
                                        <button onclick="shareReferral('<?php echo htmlspecialchars($my_referral_code); ?>')" class="btn btn-outline-info rounded-pill px-4 fw-bold">
                                            <i class="bi bi-share me-2"></i>Share Link
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 bg-white rounded-4 border">
                                    <h6 class="fw-bold text-dark mb-2"><i class="bi bi-bar-chart-line-fill text-info me-2"></i>Referral Stats</h6>
                                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                        <span class="text-muted small">Successful Referrals</span>
                                        <span class="fw-bold text-dark fs-5"><?php echo $referralsCount; ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center py-2">
                                        <span class="text-muted small">Total Points Earned</span>
                                        <span class="fw-bold text-warning fs-5"><?php echo $referralsCount * 100; ?> Pts</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PROFILE & AVAILABILITY SECTION -->
                <div id="profile-section" class="content-section">
                    <div class="row g-4">
                        <!-- Profile Form -->
                        <div class="col-lg-7">
                            <div class="card-custom">
                                <h5 class="fw-bold mb-4 text-dark"><i class="bi bi-person-fill text-primary me-2"></i>Manage Donor Profile</h5>
                                <form action="donor_dashboard.php" method="POST">
                                    <div class="row g-3">
                                        <div class="col-md-12">
                                            <label class="form-label fw-bold text-muted small">Full Legal Name</label>
                                            <input type="text" name="name" class="form-control form-control-lg" required value="<?php echo htmlspecialchars($donorData['name'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold text-muted small">Age</label>
                                            <input type="number" name="age" class="form-control form-control-lg" required min="18" max="100" value="<?php echo htmlspecialchars($donorData['age'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold text-muted small">Blood Group</label>
                                            <select name="blood_group" class="form-select form-select-lg" required>
                                                <option value="A+" <?php if ($my_blood_group == 'A+') echo 'selected'; ?>>A+</option>
                                                <option value="A-" <?php if ($my_blood_group == 'A-') echo 'selected'; ?>>A-</option>
                                                <option value="B+" <?php if ($my_blood_group == 'B+') echo 'selected'; ?>>B+</option>
                                                <option value="B-" <?php if ($my_blood_group == 'B-') echo 'selected'; ?>>B-</option>
                                                <option value="AB+" <?php if ($my_blood_group == 'AB+') echo 'selected'; ?>>AB+</option>
                                                <option value="AB-" <?php if ($my_blood_group == 'AB-') echo 'selected'; ?>>AB-</option>
                                                <option value="O+" <?php if ($my_blood_group == 'O+') echo 'selected'; ?>>O+</option>
                                                <option value="O-" <?php if ($my_blood_group == 'O-') echo 'selected'; ?>>O-</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold text-muted small">Contact Number</label>
                                            <input type="text" name="contact" class="form-control form-control-lg" required placeholder="Numbers only" pattern="[0-9]+" value="<?php echo htmlspecialchars($donorData['contact'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold text-muted small">Location (City/Area)</label>
                                            <input type="text" name="location" class="form-control form-control-lg" required placeholder="Enter location" value="<?php echo htmlspecialchars($donorData['location'] ?? ''); ?>">
                                        </div>
                                        <div class="col-12 mt-4">
                                            <button type="submit" name="update_profile" class="btn btn-primary rounded-pill px-5 shadow-sm fw-bold">Save Profile Changes</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Availability Control Card -->
                        <div class="col-lg-5">
                            <div class="card-custom text-center py-4">
                                <div class="mb-3">
                                    <i class="bi <?php echo ($my_availability === 'available') ? 'bi-check-circle-fill text-success' : 'bi-dash-circle-fill text-warning'; ?>" style="font-size: 3.8rem;"></i>
                                </div>
                                <h4 class="fw-bold text-dark mb-2">
                                    Status: 
                                    <span class="badge <?php echo ($my_availability === 'available') ? 'bg-success' : 'bg-warning text-dark'; ?> fs-6 rounded-pill px-3 py-2 text-uppercase">
                                        <?php echo ($my_availability === 'available') ? '🟢 AVAILABLE' : '🔴 NOT AVAILABLE'; ?>
                                    </span>
                                </h4>
                                <p class="text-muted small px-3 mb-4">Setting status to Available allows patients & hospitals to query your profile in the matching system.</p>
                                <form action="donor_dashboard.php" method="POST" class="d-flex flex-column gap-2 px-3">
                                    <?php if ($my_availability !== 'available'): ?>
                                        <button type="submit" name="set_available" class="btn btn-success rounded-pill py-2 fw-bold shadow-sm">
                                            <i class="bi bi-heart-pulse-fill me-2"></i>Mark as AVAILABLE
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" name="set_not_available" class="btn btn-outline-secondary rounded-pill py-2 fw-bold">
                                            <i class="bi bi-dash-circle me-2"></i>Mark as NOT AVAILABLE
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- BLOOD CAMPS SECTION -->
                <div id="camps-section" class="content-section">
                    <div class="card-custom">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4 pb-3 border-bottom">
                            <div>
                                <h4 class="fw-bold text-dark mb-1"><i class="bi bi-calendar2-heart-fill text-danger me-2"></i>Upcoming Blood Camps</h4>
                                <p class="text-muted small mb-0">Discover upcoming blood donation drives organized by certified blood banks. Show interest to receive event reminders.</p>
                            </div>
                            <span class="badge bg-danger rounded-pill px-3 py-2 fs-6">
                                <i class="bi bi-geo-alt me-1"></i> <?php echo count($camps); ?> Active Camps
                            </span>
                        </div>

                        <div class="alert alert-light border rounded-4 p-3 mb-4 text-muted small d-flex align-items-center gap-2">
                            <i class="bi bi-info-circle-fill text-danger fs-5"></i>
                            <span>Showing interest allows organizers to plan supplies and send reminders. <strong>Note:</strong> Showing interest does not automatically count as a completed donation or reward.</span>
                        </div>

                        <?php if (count($camps) > 0): ?>
                            <div class="row g-4">
                                <?php foreach ($camps as $camp): 
                                    $isInterested = in_array($camp['camp_id'], $myRegisteredCampIds);
                                    $campName = $camp['display_name'] ?? $camp['name'] ?? 'Blood Donation Camp';
                                    $orgBank = !empty($camp['organizer_bank_name']) ? $camp['organizer_bank_name'] : 'Certified Blood Bank';
                                    $timeDisplay = (!empty($camp['start_time']) ? date('g:i A', strtotime($camp['start_time'])) : '10:00 AM') . ' – ' . (!empty($camp['end_time']) ? date('g:i A', strtotime($camp['end_time'])) : '3:00 PM');
                                    $dateDisplay = !empty($camp['date']) ? date('d M Y', strtotime($camp['date'])) : 'Upcoming';
                                ?>
                                    <div class="col-md-6 col-lg-4">
                                        <div class="card-custom h-100 p-4 border rounded-4 shadow-sm d-flex flex-column" style="transition: all 0.3s ease;">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3 py-1 fw-bold">
                                                    🩸 Donation Drive
                                                </span>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1 small">
                                                    <?php echo htmlspecialchars($camp['status'] ?? 'Upcoming'); ?>
                                                </span>
                                            </div>

                                            <h5 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($campName); ?></h5>
                                            <p class="text-danger fw-semibold small mb-3"><i class="bi bi-hospital me-1"></i><?php echo htmlspecialchars($orgBank); ?></p>

                                            <div class="text-muted small mb-4 flex-grow-1">
                                                <div class="mb-1"><i class="bi bi-geo-alt-fill text-danger me-2"></i><?php echo htmlspecialchars($camp['location'] . ($camp['venue'] ? ', ' . $camp['venue'] : '')); ?></div>
                                                <div class="mb-1"><i class="bi bi-calendar-event text-danger me-2"></i>📅 <?php echo $dateDisplay; ?></div>
                                                <div class="mb-1"><i class="bi bi-clock-fill text-danger me-2"></i>⏰ <?php echo $timeDisplay; ?></div>
                                                <?php if (!empty($camp['contact'])): ?>
                                                    <div><i class="bi bi-telephone-fill text-danger me-2"></i>📞 <?php echo htmlspecialchars($camp['contact']); ?></div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="d-flex gap-2 pt-3 border-top mt-auto">
                                                <button type="button" class="btn btn-outline-secondary rounded-pill flex-fill fw-semibold btn-sm" 
                                                        data-bs-toggle="modal" data-bs-target="#donorViewCampModal_<?php echo $camp['camp_id']; ?>">
                                                    View Camp
                                                </button>

                                                <?php if ($isInterested): ?>
                                                    <button class="btn btn-success-subtle text-success border border-success-subtle rounded-pill flex-fill fw-bold btn-sm" disabled>
                                                        <i class="bi bi-check-circle-fill me-1"></i> Interested ❤️
                                                    </button>
                                                <?php else: ?>
                                                    <form method="POST" action="donor_dashboard.php" class="m-0 flex-fill">
                                                        <input type="hidden" name="camp_id" value="<?php echo htmlspecialchars($camp['camp_id']); ?>">
                                                        <button type="submit" name="register_camp" class="btn btn-danger rounded-pill w-100 fw-bold shadow-sm btn-sm">
                                                            I'm Interested ❤️
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Donor View Camp Details Modal -->
                                    <div class="modal fade" id="donorViewCampModal_<?php echo $camp['camp_id']; ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content border-0 shadow-lg rounded-4">
                                                <div class="modal-header border-bottom-0">
                                                    <h5 class="modal-title fw-bold text-dark">
                                                        <i class="bi bi-calendar2-heart-fill text-danger me-2"></i>Camp Details
                                                    </h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body pt-0">
                                                    <h4 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($campName); ?></h4>
                                                    <p class="text-danger fw-semibold small mb-3"><i class="bi bi-hospital me-1"></i>Organized by <?php echo htmlspecialchars($orgBank); ?></p>
                                                    
                                                    <div class="p-3 bg-light rounded-4 border mb-3">
                                                        <div class="mb-2"><strong class="text-dark">📍 Location:</strong> <?php echo htmlspecialchars($camp['location']); ?></div>
                                                        <?php if (!empty($camp['venue'])): ?>
                                                            <div class="mb-2"><strong class="text-dark">🏛 Venue:</strong> <?php echo htmlspecialchars($camp['venue']); ?></div>
                                                        <?php endif; ?>
                                                        <div class="mb-2"><strong class="text-dark">📅 Date:</strong> <?php echo $dateDisplay; ?></div>
                                                        <div class="mb-2"><strong class="text-dark">⏰ Time:</strong> <?php echo $timeDisplay; ?></div>
                                                        <?php if (!empty($camp['contact'])): ?>
                                                            <div class="mb-2"><strong class="text-dark">📞 Contact:</strong> <?php echo htmlspecialchars($camp['contact']); ?></div>
                                                        <?php endif; ?>
                                                        <div class="mb-0"><strong class="text-dark">👥 Expected Donors:</strong> <?php echo (int)($camp['expected_donors'] ?? 0); ?> people</div>
                                                    </div>

                                                    <?php if (!empty($camp['description'])): ?>
                                                        <div class="mb-3">
                                                            <strong class="text-dark small text-uppercase">About This Camp:</strong>
                                                            <p class="small text-muted mt-1 mb-0"><?php echo nl2br(htmlspecialchars($camp['description'])); ?></p>
                                                        </div>
                                                    <?php endif; ?>

                                                    <small class="text-muted fst-italic d-block">
                                                        Note: Expressing interest allows the organizer to notify you of updates. It does not count towards verified donation rewards until a donation is physically completed.
                                                    </small>
                                                </div>
                                                <div class="modal-footer border-top-0">
                                                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                                                    <?php if (!$isInterested): ?>
                                                        <form method="POST" action="donor_dashboard.php" class="m-0">
                                                            <input type="hidden" name="camp_id" value="<?php echo htmlspecialchars($camp['camp_id']); ?>">
                                                            <button type="submit" name="register_camp" class="btn btn-danger rounded-pill px-4 fw-bold">
                                                                I'm Interested ❤️
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted">
                                <i class="bi bi-calendar2-x fs-1 d-block mb-2 text-secondary opacity-50"></i>
                                <p class="mb-0">No active blood camps found at this time. Please check back soon!</p>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>

                <!-- 🚨 PERSONALIZED BLOOD SHORTAGES MATCHING YOUR BLOOD GROUP -->
                <div id="shortage-section" class="content-section">
                    <div class="card-custom">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4 pb-3 border-bottom">
                            <div>
                                <h4 class="fw-bold text-dark mb-1"><i class="bi bi-broadcast text-danger me-2"></i>🚨 BLOOD SHORTAGES MATCHING YOUR BLOOD GROUP</h4>
                                <p class="text-muted small mb-0">Real-time emergency shortages actively seeking <strong><?php echo htmlspecialchars($my_blood_group); ?></strong> donors.</p>
                            </div>
                            <span class="badge bg-danger rounded-pill px-3 py-2 fw-bold align-self-start align-self-md-center">
                                Filtered: <?php echo htmlspecialchars($my_blood_group); ?> Donors
                            </span>
                        </div>

                        <?php if (count($donorEmergencyAlerts) > 0): ?>
                            <div class="row g-4">
                                <?php foreach ($donorEmergencyAlerts as $sh): 
                                    $reqId = (int)$sh['request_id'];
                                    $myStatus = $myResponses[$reqId] ?? null;
                                    $deficit = max(0, (int)$sh['units_needed'] - (int)$sh['units_available']);
                                    $shJson = json_encode($sh, JSON_HEX_APOS | JSON_HEX_QUOT);
                                ?>
                                    <div class="col-md-6 col-lg-6">
                                        <div class="p-4 bg-white border border-danger border-2 rounded-4 shadow-sm h-100 d-flex flex-column justify-content-between" style="background: linear-gradient(135deg, #fffafa 0%, #ffffff 100%);">
                                            <div>
                                                <div class="d-flex justify-content-between align-items-start mb-3">
                                                    <div>
                                                        <span class="badge bg-danger rounded-pill px-3 py-2 fs-5 fw-bold"><?php echo htmlspecialchars($sh['blood_group']); ?> Emergency</span>
                                                    </div>
                                                    <span class="badge bg-danger text-white pulse-glow rounded-pill px-3 py-1 fw-bold">
                                                        🔴 Critical
                                                    </span>
                                                </div>

                                                <h5 class="fw-bold text-dark mb-2"><?php echo htmlspecialchars($sh['blood_group']); ?> Blood Urgently Needed</h5>

                                                <!-- Numbers Grid -->
                                                <div class="row g-2 text-center my-3">
                                                    <div class="col-4">
                                                        <div class="p-2 bg-light rounded-3 border">
                                                            <div class="text-muted small text-uppercase">Required</div>
                                                            <div class="fs-5 fw-bold text-dark"><?php echo (int)$sh['units_needed']; ?> units</div>
                                                        </div>
                                                    </div>
                                                    <div class="col-4">
                                                        <div class="p-2 bg-light rounded-3 border">
                                                            <div class="text-muted small text-uppercase">Available</div>
                                                            <div class="fs-5 fw-bold text-secondary"><?php echo (int)$sh['units_available']; ?> units</div>
                                                        </div>
                                                    </div>
                                                    <div class="col-4">
                                                        <div class="p-2 bg-danger bg-opacity-10 rounded-3 border border-danger-subtle">
                                                            <div class="text-danger small text-uppercase fw-bold">Shortage</div>
                                                            <div class="fs-5 fw-bold text-danger">-<?php echo $deficit; ?> unit<?php echo $deficit > 1 ? 's' : ''; ?></div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Blood Bank Location -->
                                                <div class="text-muted small mb-3" style="line-height: 1.8;">
                                                    <div><i class="bi bi-hospital-fill text-danger me-2"></i><strong><?php echo htmlspecialchars($sh['bank_name']); ?></strong></div>
                                                    <div><i class="bi bi-geo-alt-fill text-danger me-2"></i><?php echo htmlspecialchars($sh['bank_location']); ?></div>
                                                </div>

                                                <div class="alert alert-danger border-0 p-2 rounded-3 small mb-3">
                                                    <i class="bi bi-heart-fill me-1 text-danger"></i>
                                                    <strong>❤️ Your blood group matches this emergency.</strong>
                                                </div>
                                            </div>

                                            <div class="pt-3 border-top">
                                                <?php if ($myStatus === 'Willing to Donate'): ?>
                                                    <div class="d-flex align-items-center justify-content-between p-2 bg-warning bg-opacity-10 border border-warning rounded-pill px-3">
                                                        <span class="text-dark fw-bold small"><i class="bi bi-clock-history text-warning me-1"></i>🟡 WILLING TO DONATE</span>
                                                        <small class="text-muted">Response Sent</small>
                                                    </div>
                                                <?php elseif ($myStatus === 'Confirmed'): ?>
                                                    <div class="d-flex align-items-center justify-content-between p-2 bg-primary bg-opacity-10 border border-primary rounded-pill px-3">
                                                        <span class="text-primary fw-bold small"><i class="bi bi-check-circle-fill me-1"></i>🔵 DONOR CONFIRMED</span>
                                                        <small class="text-muted">Appointment Set</small>
                                                    </div>
                                                <?php elseif ($myStatus === 'Completed'): ?>
                                                    <div class="d-flex align-items-center justify-content-between p-2 bg-success bg-opacity-10 border border-success rounded-pill px-3">
                                                        <span class="text-success fw-bold small"><i class="bi bi-patch-check-fill me-1"></i>🟢 DONATION VERIFIED</span>
                                                        <small class="text-success fw-bold">+250 Pts</small>
                                                    </div>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-danger rounded-pill w-100 fw-bold shadow-sm py-2"
                                                            onclick='openEmergencyModal(<?php echo $shJson; ?>)'>
                                                        <i class="bi bi-heart-fill me-2"></i>❤️ I CAN HELP
                                                    </button>
                                                <?php endif; ?>
                                            </div>

                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <div class="bg-success bg-opacity-10 text-success rounded-circle d-inline-flex align-items-center justify-content-center p-4 mb-3" style="width: 80px; height: 80px;">
                                    <i class="bi bi-shield-check fs-1"></i>
                                </div>
                                <h5 class="fw-bold text-dark">🟢 No Current Emergency</h5>
                                <p class="text-muted mb-0">There are currently no critical blood shortages matching your registered blood group (<strong><?php echo htmlspecialchars($my_blood_group); ?></strong>). Thank you for being an active life-saving donor!</p>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>

            </div>

            <!-- Footer -->
            <footer class="text-center py-4 text-muted mt-5" style="border-top: 1px solid rgba(0,0,0,0.05);">
                &copy; 2026 MediMatch | Saving Lives Through Smart Matching
            </footer>
        </div>
    </div>

    <!-- CLAIM MYSTERY GIFT MODAL -->
    <?php if ($activeGift): ?>
        <div class="modal fade" id="claimGiftModal" tabindex="-1" aria-labelledby="claimGiftModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content rounded-4 border-0 shadow-lg">
                    <div class="modal-header border-0 bg-warning bg-opacity-10 text-dark rounded-top-4">
                        <h5 class="modal-title fw-bold" id="claimGiftModalLabel"><i class="bi bi-gift-fill text-warning me-2"></i>Claim Your Mystery Gift</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form method="POST" action="donor_dashboard.php">
                        <div class="modal-body p-4">
                            <input type="hidden" name="claim_id" value="<?php echo $activeGift['claim_id']; ?>">
                            <div class="p-3 bg-light rounded-3 mb-3 border">
                                <small class="fw-bold text-uppercase text-muted d-block mb-1">Surprise Gift Reward</small>
                                <p class="small text-dark mb-0">Your ₹200–₹300 healthcare wellness kit will be dispatched to this delivery address.</p>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold">Recipient Full Name</label>
                                <input type="text" name="recipient_name" class="form-control" required value="<?php echo htmlspecialchars($donorData['name'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Phone Number</label>
                                <input type="text" name="phone" class="form-control" required value="<?php echo htmlspecialchars($donorData['contact'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Delivery Address</label>
                                <textarea name="address" class="form-control" rows="2" required placeholder="Street address / House No."><?php echo htmlspecialchars($donorData['location'] ?? ''); ?></textarea>
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small fw-bold">City</label>
                                    <input type="text" name="city" class="form-control" required placeholder="City name" value="<?php echo htmlspecialchars($donorData['location'] ?? ''); ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-bold">Pincode</label>
                                    <input type="text" name="pincode" class="form-control" required placeholder="6-digit pincode">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer border-0 p-3 pt-0">
                            <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="claim_mystery_gift" class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm">
                                <i class="bi bi-truck me-2"></i>CONFIRM & CLAIM GIFT
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- EMERGENCY HELP CONFIRMATION MODAL -->
    <div class="modal fade" id="emergencyHelpModal" tabindex="-1" aria-labelledby="emergencyHelpModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header bg-danger text-white rounded-top-4 border-0">
                    <h5 class="modal-title fw-bold" id="emergencyHelpModalLabel">
                        <i class="bi bi-heart-pulse-fill me-2"></i>🚨 HELP WITH THIS BLOOD EMERGENCY?
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="donor_dashboard.php">
                    <input type="hidden" name="request_id" id="em_request_id" value="">
                    <div class="modal-body p-4">
                        <div class="p-3 bg-light rounded-4 border mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted fw-bold small text-uppercase">Blood Group Needed</span>
                                <span class="badge bg-danger rounded-pill px-3 py-1 fs-6" id="em_blood_group">AB-</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted fw-bold small text-uppercase">Shortage Deficit</span>
                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle rounded-pill px-3 py-1 fw-bold fs-6" id="em_shortage">-1 unit</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted small">Required / Available</span>
                                <span class="text-dark small fw-bold" id="em_stock_info">7 req / 6 available</span>
                            </div>
                            <div class="border-top pt-2 mt-2">
                                <div class="small text-dark fw-bold" id="em_bank_name">City Central Blood Bank</div>
                                <small class="text-muted d-block" id="em_location"><i class="bi bi-geo-alt me-1 text-danger"></i>Bhavanipuram Colony</small>
                            </div>
                        </div>

                        <div class="alert alert-warning border-0 rounded-4 p-3 small mb-0">
                            <i class="bi bi-info-circle-fill me-2 fs-6 text-warning"></i>
                            <strong>Important:</strong> "Your response will be sent to the blood bank. This confirms your willingness to donate; it does not mark a donation as completed." The blood bank will contact you to coordinate your donation visit.
                        </div>
                    </div>
                    <div class="modal-footer border-top-0 bg-light rounded-bottom-4">
                        <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">CANCEL</button>
                        <button type="submit" name="confirm_emergency_help" class="btn btn-danger rounded-pill px-4 fw-bold shadow-sm">
                            CONFIRM — I CAN HELP ❤️
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Section Switching Logic
        function showSection(sectionId, element) {
            document.querySelectorAll('.content-section').forEach(sec => sec.classList.remove('active'));
            document.querySelectorAll('.nav-link-custom').forEach(nav => nav.classList.remove('active'));

            const sec = document.getElementById(sectionId);
            if (sec) sec.classList.add('active');
            if (element) element.classList.add('active');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Open Emergency Help Modal with prefilled shortage details
        function openEmergencyModal(req) {
            document.getElementById('em_request_id').value = req.request_id || '';
            document.getElementById('em_blood_group').innerText = req.blood_group || '';
            const deficit = req.deficit !== undefined ? req.deficit : Math.max(0, (req.units_needed || 0) - (req.units_available || 0));
            document.getElementById('em_shortage').innerText = '-' + deficit + ' unit' + (deficit > 1 ? 's' : '');
            document.getElementById('em_stock_info').innerText = (req.units_needed || 0) + ' req / ' + (req.units_available || 0) + ' available';
            document.getElementById('em_bank_name').innerText = req.bank_name || 'City Central Blood Bank';
            document.getElementById('em_location').innerText = '📍 ' + (req.bank_location || 'Bhavanipuram Colony');
            new bootstrap.Modal(document.getElementById('emergencyHelpModal')).show();
        }

        // Referral Code Copying & Sharing
        function copyReferralCode(code) {
            navigator.clipboard.writeText(code).then(() => {
                alert('Referral Code ' + code + ' copied to clipboard!');
            }).catch(err => {
                alert('Referral Code: ' + code);
            });
        }

        function shareReferral(code) {
            if (navigator.share) {
                navigator.share({
                    title: 'Join MediMatch as a Life-Saving Donor',
                    text: 'Use my MediMatch Referral Code ' + code + ' to register and save lives!',
                    url: window.location.origin
                }).catch(() => {});
            } else {
                copyReferralCode(code);
            }
        }
    </script>

    <?php require 'chatbot.php'; ?>
    <?php require 'language_switcher.php'; ?>
</body>

</html>