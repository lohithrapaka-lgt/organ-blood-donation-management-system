<?php
/**
 * get_camp_donors.php
 * Secure AJAX endpoint: returns interested donors for a specific camp.
 * Only the blood bank that owns the camp can access its donor list.
 */
session_start();

header('Content-Type: application/json; charset=utf-8');

// Auth guard
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'bloodbank') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$camp_id  = isset($_GET['camp_id']) ? (int)$_GET['camp_id'] : 0;
$bank_id  = (int)$_SESSION['ref_id'];

if ($camp_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid camp ID']);
    exit();
}

try {
    $pdo = new PDO('mysql:host=localhost;dbname=organ_blood_donation;charset=utf8', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── Security: verify the camp belongs to THIS blood bank ──────────────────
    $stmtOwn = $pdo->prepare("SELECT camp_id, COALESCE(camp_name, name) AS camp_name, location, venue, date, start_time, end_time, expected_donors FROM blood_camps WHERE camp_id = ? AND blood_bank_id = ?");
    $stmtOwn->execute([$camp_id, $bank_id]);
    $camp = $stmtOwn->fetch(PDO::FETCH_ASSOC);

    if (!$camp) {
        http_response_code(403);
        echo json_encode(['error' => 'Camp not found or you do not own this camp']);
        exit();
    }

    // ── Fetch interested donors via camp_registrations → donors ───────────────
    $stmtDonors = $pdo->prepare("
        SELECT 
            cr.id              AS reg_id,
            cr.status          AS interest_status,
            cr.created_at      AS interested_at,
            d.donor_id,
            d.name,
            d.blood_group,
            d.contact,
            d.donor_type,
            d.verified,
            d.availability
        FROM camp_registrations cr
        JOIN donors d ON cr.donor_id = d.donor_id
        WHERE cr.camp_id = ?
        ORDER BY cr.created_at ASC
    ");
    $stmtDonors->execute([$camp_id]);
    $donors = $stmtDonors->fetchAll(PDO::FETCH_ASSOC);

    // ── Blood-group summary ───────────────────────────────────────────────────
    $groups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    $bgSummary = array_fill_keys($groups, 0);
    foreach ($donors as $d) {
        $bg = $d['blood_group'];
        if (isset($bgSummary[$bg])) $bgSummary[$bg]++;
    }

    // Mask contact for privacy (show only last 4 digits)
    foreach ($donors as &$d) {
        $contact = $d['contact'] ?? '';
        if (strlen($contact) >= 4) {
            $d['contact_masked'] = str_repeat('*', max(0, strlen($contact) - 4)) . substr($contact, -4);
        } else {
            $d['contact_masked'] = '****';
        }
        $d['interested_at_fmt'] = date('d M Y, g:i A', strtotime($d['interested_at']));
        unset($d['contact']); // never send raw contact via AJAX
    }
    unset($d);

    echo json_encode([
        'success'      => true,
        'camp'         => $camp,
        'donors'       => $donors,
        'total'        => count($donors),
        'expected'     => (int)($camp['expected_donors'] ?? 0),
        'bg_summary'   => $bgSummary,
        'groups_order' => $groups
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
