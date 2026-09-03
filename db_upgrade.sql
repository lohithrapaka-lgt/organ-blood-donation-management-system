-- Upgrade Organ & Blood Donation System Schema

-- Set the database to use
USE organ_blood_donation;

-- STEP 1: CREATE USERS TABLE (FOR AUTHENTICATION)
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    age INT,
    role ENUM('patient','donor','bloodbank','hospital','admin') NOT NULL,
    reference_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- STEP 2: MODIFY EXISTING TABLES

-- 1. Modify `patients` table to update the `status` ENUM
ALTER TABLE patients
MODIFY COLUMN status ENUM('pending', 'approved', 'fulfilled', 'waiting_for_donor', 'donor_matched') DEFAULT 'pending';

-- 2. Modify `hospitals` table to add `status` column
ALTER TABLE hospitals
ADD COLUMN status ENUM('pending','approved') DEFAULT 'pending';

-- 3. Modify `blood_banks` table to add `status` column
ALTER TABLE blood_banks
ADD COLUMN status ENUM('pending','approved') DEFAULT 'pending';

-- 4. Modify `blood_inventory` table to add `expiry_date` column
ALTER TABLE blood_inventory
ADD COLUMN expiry_date DATE;

-- STEP 3: CREATE ORGAN INVENTORY TABLE
CREATE TABLE IF NOT EXISTS organ_inventory (
    organ_id INT AUTO_INCREMENT PRIMARY KEY,
    hospital_id INT NOT NULL,
    organ_type VARCHAR(100) NOT NULL,
    units_available INT DEFAULT 0,
    FOREIGN KEY (hospital_id) REFERENCES hospitals(hospital_id) ON DELETE CASCADE
);

-- STEP 4: CREATE BLOOD CAMPS SYSTEM

-- 1. Create table `blood_camps`
CREATE TABLE IF NOT EXISTS blood_camps (
    camp_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255),
    location VARCHAR(255),
    date DATE
);

-- 2. Create table `camp_registrations`
CREATE TABLE IF NOT EXISTS camp_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    donor_id INT,
    camp_id INT,
    FOREIGN KEY (donor_id) REFERENCES donors(donor_id) ON DELETE CASCADE,
    FOREIGN KEY (camp_id) REFERENCES blood_camps(camp_id) ON DELETE CASCADE
);
