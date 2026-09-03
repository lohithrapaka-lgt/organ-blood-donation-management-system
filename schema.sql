CREATE DATABASE IF NOT EXISTS organ_blood_donation;
USE organ_blood_donation;

CREATE TABLE patients (
    patient_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    age INT NOT NULL,
    blood_group ENUM('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-') NOT NULL,
    request_type ENUM('blood', 'organ') NOT NULL,
    organ_needed VARCHAR(255) NULL,
    `condition` ENUM('critical', 'urgent', 'normal') NOT NULL,
    request_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'approved', 'fulfilled') DEFAULT 'pending',
    priority_score INT DEFAULT 0
);

CREATE TABLE donors (
    donor_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    age INT NOT NULL,
    blood_group ENUM('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-') NOT NULL,
    donor_type ENUM('blood', 'organ', 'both') NOT NULL,
    organ_type VARCHAR(255) NULL,
    availability ENUM('available', 'not_available') DEFAULT 'available',
    verified ENUM('yes', 'no') DEFAULT 'no',
    contact VARCHAR(255) NOT NULL
);

CREATE TABLE blood_banks (
    bank_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    location VARCHAR(255) NOT NULL,
    contact VARCHAR(255) NOT NULL
);

CREATE TABLE blood_inventory (
    inventory_id INT AUTO_INCREMENT PRIMARY KEY,
    bank_id INT NOT NULL,
    blood_group ENUM('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-') NOT NULL,
    units_available INT DEFAULT 0,
    FOREIGN KEY (bank_id) REFERENCES blood_banks(bank_id) ON DELETE CASCADE
);

CREATE TABLE hospitals (
    hospital_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    location VARCHAR(255) NOT NULL,
    contact VARCHAR(255) NOT NULL
);

CREATE TABLE organ_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    hospital_id INT NULL,
    organ_type VARCHAR(255) NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'fulfilled') DEFAULT 'pending',
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
    FOREIGN KEY (hospital_id) REFERENCES hospitals(hospital_id) ON DELETE SET NULL
);

CREATE TABLE blood_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    patient_name VARCHAR(255) NOT NULL,
    age INT NOT NULL,
    bank_id INT NULL,
    blood_group ENUM('A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-') NOT NULL,
    priority_score INT DEFAULT 0,
    units_needed INT NOT NULL,
    status ENUM('pending', 'approved', 'fulfilled') DEFAULT 'pending',
    request_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE,
    FOREIGN KEY (bank_id) REFERENCES blood_banks(bank_id) ON DELETE SET NULL
);

CREATE TABLE donor_responses (
    response_id INT AUTO_INCREMENT PRIMARY KEY,
    donor_id INT NOT NULL,
    patient_id INT NOT NULL,
    response ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
    FOREIGN KEY (donor_id) REFERENCES donors(donor_id) ON DELETE CASCADE,
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id) ON DELETE CASCADE
);

CREATE TABLE admin (
    admin_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
);
