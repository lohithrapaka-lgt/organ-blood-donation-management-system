# organ-blood-donation-management-system

# Organ and Blood Donation Management System

## 📌 Project Overview

The **Organ and Blood Donation Management System** is a web-based application designed to manage and simplify the process of organ and blood donation.

The system provides a centralized platform that connects **patients, donors, hospitals, blood banks, and administrators**. It helps manage donor information, patient requests, blood inventory, organ requests, donor matching, priority-based allocation, and request status tracking.

The main goal of this project is to reduce manual work, improve coordination between different users, and make the donation process faster, more transparent, and efficient.

---

## 🎯 Objectives

The main objectives of the system are:

- To provide a centralized platform for organ and blood donation management.
- To maintain donor and patient information in a structured database.
- To allow patients to submit blood and organ requests.
- To allow donors to register and update their availability.
- To automatically identify suitable donors for requests.
- To prioritize patient requests based on medical urgency and other factors.
- To manage blood bank inventory efficiently.
- To allow hospitals to monitor and process requests.
- To provide administrators with complete system management capabilities.
- To provide secure authentication and role-based access.
- To reduce delays caused by manual communication and record keeping.

---

## 👥 User Roles

The system contains different modules for different types of users.

### 1. Patient

Patients can:

- Register and login securely.
- Submit blood requests.
- Submit organ requests.
- Provide required medical information.
- View their request status.
- View their priority score.
- Track request fulfillment.
- Get matched with suitable donors.

### 2. Donor

Donors can:

- Register as donors.
- Login securely.
- Provide blood group information.
- Provide organ donation information.
- Update their availability status.
- Receive matching requests.
- Manage their donor information.

### 3. Hospital

Hospitals can:

- Login to the system.
- View patient requests.
- Verify and process requests.
- Monitor organ and blood requirements.
- Manage donor-related information.
- Allocate available resources based on priority.
- Track request status.

### 4. Blood Bank

Blood banks can:

- Manage blood inventory.
- Add available blood units.
- Update blood quantities.
- Monitor blood group availability.
- Process blood requests.
- Maintain inventory records.

### 5. Administrator

The administrator can:

- Manage users.
- Monitor system activities.
- Manage patient and donor information.
- Monitor requests.
- Manage system data.
- Maintain data accuracy.
- Oversee the complete donation and allocation process.

---

## ⚙️ Main Features

### 🔐 Authentication

The system provides registration and login functionality for different users.

### 🩸 Blood Donation Management

The system manages:

- Blood donor information
- Blood groups
- Blood requests
- Blood availability
- Blood bank inventory
- Blood request status

### 🫀 Organ Donation Management

The system manages:

- Organ donor information
- Organ types
- Organ requests
- Donor availability
- Organ matching
- Request processing

### 🤝 Donor Matching

The system identifies suitable donors based on information such as:

- Blood group
- Organ type
- Donor availability
- Patient requirements

### ⭐ Priority-Based Allocation

Patient requests are assigned a priority score.

The score considers factors such as:

- Medical condition
- Patient age
- Waiting time
- Organ type

Higher-priority requests can be processed before lower-priority requests.

### 📊 Request Tracking

Patients and authorized users can track request statuses such as:

- Pending
- Approved
- Fulfilled
- Rejected

### 🏥 Hospital Management

Hospitals can monitor requests and manage the allocation process.

### 🏦 Blood Inventory Management

Blood banks can maintain and update available blood stock.

---

## 🧮 Priority Calculation

The system uses a priority scoring mechanism to help determine the urgency of patient requests.

The score considers four major factors:

### Medical Condition

- Critical → 100 points
- Urgent → 70 points
- Normal → 50 points

### Age

- Below 12 or above 60 → 30 points
- Other ages → 20 points

### Waiting Time

Waiting time contributes additional points based on the number of days waiting.

### Organ Type

Different organs have different priority values, for example:

- Heart → 100 points
- Liver → 90 points
- Lungs → 85 points
- Kidney → 70 points
- Other organs → 60 points

The final priority score is calculated using these factors, and pending requests can be sorted according to their priority.

---

## 🏗️ System Architecture

The project follows a basic client-server architecture.

```text
                    ┌─────────────────────┐
                    │       Users         │
                    │                     │
                    │ Patient / Donor     │
                    │ Hospital / Admin    │
                    │ Blood Bank          │
                    └──────────┬──────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │    Web Interface    │
                    │ HTML / CSS / JS      │
                    └──────────┬──────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │    PHP Backend      │
                    │                     │
                    │ Authentication      │
                    │ Matching Logic      │
                    │ Priority Calculation│
                    │ Request Processing  │
                    └──────────┬──────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │     MySQL Database  │
                    │                     │
                    │ Users               │
                    │ Patients            │
                    │ Donors              │
                    │ Requests            │
                    │ Inventory           │
                    │ Hospitals           │
                    └─────────────────────┘



🛠️ Technologies Used

| Technology | Purpose                  |
| ---------- | ------------------------ |
| HTML       | Structure of web pages   |
| CSS        | Styling and page design  |
| JavaScript | Client-side interaction  |
| PHP        | Backend development      |
| MySQL      | Database management      |
| XAMPP      | Local development server |
| Apache     | Web server               |


Project Structure

organ-blood-donation-management-system/
│
├── index.php
├── login.php
├── register.php
├── logout.php
│
├── admin_dashboard.php
├── patient_dashboard.php
├── donor_dashboard.php
├── hospital_dashboard.php
├── bloodbank_dashboard.php
│
├── matching_system.php
├── match_logic.php
├── priority_calc.php
├── update_priority.php
│
├── report.php
│
├── schema.sql
├── db_dump.php
├── db_upgrade.sql
├── db_upgrade_profiles.sql
├── dummy_data.sql
│
├── test_bb.php
├── test_bloodbank.php
│
└── scratch/
    ├── create_test_user.php
    ├── list_users.php
    ├── populate_inventory.php
    ├── setup_matching_test.php
    ├── update_admin_schema.php
    ├── update_patient_status_enum.php
    └── verify_matching.php


Requirements

Before running the project, install:

XAMPP
PHP 8.x
MySQL
Apache
A modern web browser such as Google Chrome, Microsoft Edge, or Mozilla Firefox
Git (if cloning from GitHub)
