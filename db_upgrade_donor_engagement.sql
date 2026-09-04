-- db_upgrade_donor_engagement.sql
-- MediMatch Donor Engagement, Impact, Points, Badges & Mystery Rewards Upgrade

USE organ_blood_donation;

-- 1. Upgrade `donors` table with points and referral columns
ALTER TABLE donors 
ADD COLUMN IF NOT EXISTS referral_code VARCHAR(50) NULL UNIQUE,
ADD COLUMN IF NOT EXISTS referred_by VARCHAR(50) NULL,
ADD COLUMN IF NOT EXISTS points INT DEFAULT 50;

-- 2. Table `donor_donations`: Tracks verified completed donations
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

-- 3. Table `mystery_gifts`: Tracks mystery gifts, claims, and delivery progress
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

-- 4. Table `donor_badges`: Tracks unlocked achievement badges
CREATE TABLE IF NOT EXISTS donor_badges (
    badge_id INT AUTO_INCREMENT PRIMARY KEY,
    donor_id INT NOT NULL,
    badge_key VARCHAR(50) NOT NULL,
    unlocked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (donor_id) REFERENCES donors(donor_id) ON DELETE CASCADE,
    UNIQUE KEY unique_donor_badge (donor_id, badge_key)
);

-- 5. Table `donor_notifications`: Tracks donor notifications
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

-- 6. Table `donor_referrals`: Tracks referrals
CREATE TABLE IF NOT EXISTS donor_referrals (
    referral_id INT AUTO_INCREMENT PRIMARY KEY,
    referrer_donor_id INT NOT NULL,
    referred_donor_id INT NOT NULL,
    status ENUM('pending', 'verified') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (referrer_donor_id) REFERENCES donors(donor_id) ON DELETE CASCADE,
    FOREIGN KEY (referred_donor_id) REFERENCES donors(donor_id) ON DELETE CASCADE
);
