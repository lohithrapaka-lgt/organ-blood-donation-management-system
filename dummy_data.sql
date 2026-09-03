USE organ_blood_donation;

-- --------------------------------------------------------
-- 1. Insert Admin
-- --------------------------------------------------------
INSERT INTO admin (username, password) VALUES
('admin', 'admin123');

-- --------------------------------------------------------
-- 2. Insert Blood Banks
-- --------------------------------------------------------
INSERT INTO blood_banks (name, location, contact) VALUES
('City Central Blood Bank', 'Downtown', '123-456-7890'),
('Red Cross Regional', 'North Suburbs', '234-567-8901'),
('Hope Blood Center', 'Westside', '345-678-9012');

-- --------------------------------------------------------
-- 3. Insert Hospitals
-- --------------------------------------------------------
INSERT INTO hospitals (name, location, contact) VALUES
('General Hospital', 'Downtown', '456-789-0123'),
('St. Mary Medical Center', 'Southside', '567-890-1234'),
('University City Hospital', 'Eastside', '678-901-2345');

-- --------------------------------------------------------
-- 4. Insert Patients
-- --------------------------------------------------------
INSERT INTO patients (name, age, blood_group, request_type, organ_needed, `condition`, status, priority_score) VALUES
-- Blood Requests (1-6)
('Timmy Smith', 8, 'O-', 'blood', NULL, 'critical', 'pending', 50),
('John Doe', 35, 'A+', 'blood', NULL, 'urgent', 'approved', 30),
('Mary Johnson', 65, 'B+', 'blood', NULL, 'normal', 'fulfilled', 10),
('Sarah Connor', 45, 'AB-', 'blood', NULL, 'critical', 'pending', 60),
('David Lee', 28, 'O+', 'blood', NULL, 'urgent', 'pending', 25),
('Emma Watson', 10, 'A-', 'blood', NULL, 'normal', 'approved', 15),

-- Organ Requests (7-12)
('Robert Brown', 72, 'O+', 'organ', 'Kidney', 'critical', 'pending', 80),
('Michael Davis', 40, 'A+', 'organ', 'Liver', 'urgent', 'approved', 65),
('Jennifer Taylor', 32, 'B-', 'organ', 'Heart', 'critical', 'pending', 90),
('William Wilson', 55, 'AB+', 'organ', 'Lung', 'normal', 'pending', 40),
('James Moore', 68, 'O-', 'organ', 'Kidney', 'urgent', 'fulfilled', 75),
('Charles White', 50, 'A-', 'organ', 'Pancreas', 'critical', 'pending', 85);

-- --------------------------------------------------------
-- 5. Insert Donors
-- --------------------------------------------------------
INSERT INTO donors (name, age, blood_group, donor_type, organ_type, availability, verified, contact) VALUES
-- Blood Donors (1-4)
('Alice Walker', 30, 'O-', 'blood', NULL, 'available', 'yes', 'alice@example.com'),
('Bob Builder', 45, 'A+', 'blood', NULL, 'not_available', 'yes', 'bob@example.com'),
('Charlie Chaplin', 25, 'B+', 'blood', NULL, 'available', 'no', 'charlie@example.com'),
('Diana Prince', 28, 'AB-', 'blood', NULL, 'available', 'yes', 'diana@example.com'),

-- Organ Donors (5-8)
('Eve Adams', 50, 'O+', 'organ', 'Kidney', 'available', 'yes', 'eve@example.com'),
('Frank Castle', 38, 'A+', 'organ', 'Liver', 'not_available', 'yes', 'frank@example.com'),
('Grace Kelly', 42, 'B-', 'organ', 'Heart', 'available', 'yes', 'grace@example.com'),
('Harry Potter', 35, 'AB+', 'organ', 'Lung', 'available', 'no', 'harry@example.com'),

-- Both Donors (9-12)
('Ivy Poison', 29, 'O-', 'both', 'Kidney', 'available', 'yes', 'ivy@example.com'),
('Jack Sparrow', 40, 'A-', 'both', 'Liver', 'available', 'yes', 'jack@example.com'),
('Kevin Bacon', 55, 'B+', 'both', 'Cornea', 'not_available', 'yes', 'kevin@example.com'),
('Laura Croft', 33, 'O+', 'both', 'Bone Marrow', 'available', 'no', 'laura@example.com');

-- --------------------------------------------------------
-- 6. Insert Blood Inventory (Covering edge cases)
-- --------------------------------------------------------
INSERT INTO blood_inventory (bank_id, blood_group, units_available) VALUES
-- Bank 1: High stock, low stock, zero stock
(1, 'A+', 20),  (1, 'A-', 2),  (1, 'B+', 15), (1, 'B-', 0),
(1, 'AB+', 10), (1, 'AB-', 5), (1, 'O+', 0),  (1, 'O-', 1),

-- Bank 2: Partial stock
(2, 'O+', 5), (2, 'A+', 0), (2, 'B-', 2),

-- Bank 3: Focus on specific groups
(3, 'O-', 25), (3, 'A-', 10);

-- --------------------------------------------------------
-- 7. Insert Organ Requests
-- --------------------------------------------------------
INSERT INTO organ_requests (patient_id, hospital_id, organ_type, status) VALUES
-- Match patient IDs 7-12
(7, 1, 'Kidney', 'pending'),      -- No available donor confirmed yet
(8, 2, 'Liver', 'approved'),      -- Donor found and approved
(9, 3, 'Heart', 'rejected'),      -- Donor was found but medically incompatible/rejected
(10, 1, 'Lung', 'pending'),       -- Still waiting
(11, 2, 'Kidney', 'fulfilled'),   -- Surgery complete
(12, 3, 'Pancreas', 'pending');   -- No matched donor available

-- --------------------------------------------------------
-- 8. Insert Blood Requests
-- --------------------------------------------------------
INSERT INTO blood_requests (patient_id, patient_name, age, bank_id, blood_group, priority_score, units_needed, status) VALUES
-- Match patient IDs 1-6
(1, 'Timmy Smith', 8, 1, 'O-', 50, 2, 'pending'),    -- Edge Case: Patient needs 2, Bank 1 only has 1 (Low stock)
(2, 'John Doe', 35, 1, 'A+', 30, 3, 'approved'),   -- Normal Case: High stock available
(3, 'Mary Johnson', 65, NULL, 'B+', 10, 1, 'fulfilled'),-- Completed request
(4, 'Sarah Connor', 45, 1, 'AB-', 60, 4, 'pending'),   -- Waiting for confirmation
(5, 'David Lee', 28, 1, 'O+', 25, 2, 'pending'),    -- Edge Case: Zero stock available in Bank 1
(6, 'Emma Watson', 10, 3, 'A-', 15, 1, 'approved');   -- Approved standard request

-- --------------------------------------------------------
-- 9. Insert Donor Responses
-- --------------------------------------------------------
INSERT INTO donor_responses (donor_id, patient_id, response) VALUES
(5, 7, 'pending'),    -- Eve Adams (O+, Kidney) matched with Robert Brown. Waiting for evaluation.
(10, 8, 'accepted'),  -- Jack Sparrow (A-, Liver) matched with Michael Davis. Request is Approved.
(7, 9, 'rejected'),   -- Grace Kelly (B-, Heart) matched with Jennifer Taylor. Found medically incompatible.
(9, 11, 'accepted'),  -- Ivy Poison (O-, Kidney) matched with James Moore. Request is fulfilled.
(8, 10, 'pending');   -- Harry Potter (AB+, Lung) matched with William Wilson. Pending evaluation.
