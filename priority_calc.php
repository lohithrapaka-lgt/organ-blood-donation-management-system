<?php
/**
 * Common function to calculate Priority Score
 * for the Organ and Blood Donation Management System.
 */

function calculatePriority($age, $condition, $request_type, $organ_needed, $request_date) {
    // DATA VALIDATION
    $age = intval($age);
    $condition = strtolower(trim($condition));

    // 1. CONDITION SCORE
    if ($condition === 'critical') {
        $condition_score = 100;
    } elseif ($condition === 'urgent') {
        $condition_score = 70;
    } else {
        $condition_score = 50; // normal
    }

    // 2. AGE SCORE
    if ($age < 12 || $age > 60) {
        $age_score = 30;
    } else {
        $age_score = 20;
    }

    // 3. WAITING TIME SCORE
    $waiting_score = 0;
    if (!empty($request_date)) {
        try {
            $currentDate = new DateTime();
            $requestDateObj = new DateTime($request_date);
            $interval = $currentDate->diff($requestDateObj);
            $waiting_days = intval($interval->days);
            $waiting_score = $waiting_days * 2;
        } catch (Exception $e) {
            $waiting_score = 0;
        }
    }

    // 4. ORGAN SCORE
    $organ_score = 0;
    if (strtolower(trim($request_type)) === 'organ') {
        $organ = strtolower(trim($organ_needed));
        
        if ($organ === 'heart') {
            $organ_score = 100;
        } elseif ($organ === 'liver') {
            $organ_score = 90;
        } elseif ($organ === 'lungs') {
            $organ_score = 85;
        } elseif ($organ === 'kidney') {
            $organ_score = 70;
        } else {
            $organ_score = 60;
        }
    }

    // 5. FINAL PRIORITY SCORE
    $priority_score = $condition_score + $age_score + $waiting_score + $organ_score;

    return $priority_score;
}
?>
