-- Profile System Upgrade SQL
USE organ_blood_donation;

-- 1. Upgrade Patients
ALTER TABLE patients 
ADD COLUMN IF NOT EXISTS contact VARCHAR(20) NULL,
ADD COLUMN IF NOT EXISTS location VARCHAR(255) NULL;

-- 2. Upgrade Donors
ALTER TABLE donors 
ADD COLUMN IF NOT EXISTS location VARCHAR(255) NULL;

-- 3. Upgrade Hospitals
ALTER TABLE hospitals 
ADD COLUMN IF NOT EXISTS specialization VARCHAR(255) NULL,
ADD COLUMN IF NOT EXISTS license_no VARCHAR(100) NULL;

-- 4. Upgrade Blood Banks
ALTER TABLE blood_banks 
ADD COLUMN IF NOT EXISTS license_no VARCHAR(100) NULL,
ADD COLUMN IF NOT EXISTS capacity INT DEFAULT 0;
