<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediMatch</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-blue: #0056b3;
            --light-blue: #e8f4fd;
            --accent-red: #d32f2f;
            --dark-text: #2c3e50;
            --light-gray: #f4f7f6;
            --white: #ffffff;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: var(--light-gray);
            color: var(--dark-text);
            line-height: 1.6;
        }

        a {
            text-decoration: none;
        }

        /* Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Navbar */
        header {
            background-color: var(--white);
            box-shadow: 0 2px 15px rgba(0,0,0,0.05);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
        }

        .logo {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-blue);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo .fa-heartbeat {
            color: var(--accent-red);
            font-size: 1.8rem;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 25px;
        }

        .nav-links a {
            color: var(--dark-text);
            font-weight: 600;
            transition: var(--transition);
        }

        .nav-links a:hover {
            color: var(--primary-blue);
        }

        .nav-links .nav-btn {
            background-color: var(--primary-blue);
            color: var(--white);
            padding: 10px 24px;
            border-radius: 30px;
        }

        .nav-links .nav-btn:hover {
            background-color: #004494;
            color: var(--white);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 86, 179, 0.2);
        }

        /* Hero Section */
        .hero {
            background: linear-gradient(135deg, #e0f2fe 0%, #ffffff 100%);
            padding: 100px 0 80px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 300px;
            height: 300px;
            background: rgba(211, 47, 47, 0.05);
            border-radius: 50%;
            z-index: 0;
        }

        .hero-content {
            position: relative;
            z-index: 1;
        }

        .hero h1 {
            font-size: 3.5rem;
            margin-bottom: 20px;
            color: var(--dark-text);
            line-height: 1.2;
        }

        .hero h1 span {
            color: var(--accent-red);
        }

        .hero p {
            font-size: 1.2rem;
            color: #555;
            margin-bottom: 40px;
            max-width: 650px;
            margin-left: auto;
            margin-right: auto;
        }

        .hero-btns {
            display: flex;
            justify-content: center;
            gap: 20px;
        }

        .btn {
            padding: 14px 35px;
            font-size: 1.1rem;
            border-radius: 30px;
            font-weight: 600;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 10px;
            border: 2px solid transparent;
        }

        .btn-primary {
            background-color: var(--accent-red);
            color: var(--white);
            box-shadow: 0 4px 15px rgba(211, 47, 47, 0.3);
        }

        .btn-primary:hover {
            background-color: #b71c1c;
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(211, 47, 47, 0.4);
        }

        .btn-secondary {
            background-color: transparent;
            color: var(--primary-blue);
            border-color: var(--primary-blue);
        }

        .btn-secondary:hover {
            background-color: var(--primary-blue);
            color: var(--white);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0, 86, 179, 0.2);
        }

        /* Section Titles */
        .section-title {
            text-align: center;
            margin-bottom: 50px;
        }

        .section-title h2 {
            font-size: 2.5rem;
            color: var(--dark-text);
        }

        .section-title .divider {
            height: 4px;
            width: 70px;
            background-color: var(--primary-blue);
            margin: 15px auto;
            border-radius: 2px;
        }

        /* Roles Section */
        .roles {
            padding: 80px 0;
            background-color: var(--light-gray);
        }

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 30px;
        }

        .card {
            background: var(--white);
            padding: 40px 25px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(0,0,0,0.04);
            transition: var(--transition);
            border: 1px solid rgba(0,0,0,0.05);
            border-bottom: 4px solid transparent;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.1);
            border-bottom-color: var(--primary-blue);
        }

        .card .icon-wrapper {
            width: 80px;
            height: 80px;
            background-color: var(--light-blue);
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0 auto 25px;
            transition: var(--transition);
        }

        .card:hover .icon-wrapper {
            background-color: var(--primary-blue);
        }

        .card:nth-child(2):hover .icon-wrapper { background-color: var(--accent-red); }
        .card:hover .fa-user-injured, .card:hover .fa-hand-holding-heart, .card:hover .fa-hospital, .card:hover .fa-vials, .card:hover .fa-user-shield {
            color: var(--white);
        }

        .card i {
            font-size: 2.5rem;
            color: var(--primary-blue);
            transition: var(--transition);
        }

        .card:nth-child(2) i { color: var(--accent-red); }

        .card h3 {
            font-size: 1.4rem;
            margin-bottom: 15px;
            color: var(--dark-text);
        }

        .card p {
            color: #666;
            margin-bottom: 25px;
            font-size: 0.95rem;
            flex-grow: 1;
        }

        .card-btn {
            display: inline-block;
            padding: 10px 20px;
            background-color: var(--light-blue);
            color: var(--primary-blue);
            border-radius: 20px;
            font-weight: 600;
            transition: var(--transition);
            width: 100%;
        }
        
        .card:nth-child(2) .card-btn {
            background-color: #fdeaea;
            color: var(--accent-red);
        }

        .card:hover .card-btn {
            background-color: var(--primary-blue);
            color: var(--white);
        }
        
        .card:nth-child(2):hover .card-btn {
            background-color: var(--accent-red);
            color: var(--white);
        }

        /* Features Section */
        .features {
            padding: 80px 0;
            background-color: var(--white);
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 40px;
        }

        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 20px;
            padding: 20px;
            border-radius: 10px;
            transition: var(--transition);
        }
        
        .feature-item:hover {
            background-color: var(--light-gray);
        }

        .feature-icon {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #00a8ff 100%);
            color: var(--white);
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 1.8rem;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(0, 86, 179, 0.2);
        }

        .feature-content h4 {
            font-size: 1.2rem;
            margin-bottom: 10px;
            color: var(--dark-text);
        }

        .feature-content p {
            color: #666;
            font-size: 0.95rem;
        }

        /* About Section */
        .about {
            padding: 80px 0;
            text-align: center;
            background-color: var(--white);
            position: relative;
        }
        
        .about::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(to right, transparent, rgba(0,0,0,0.1), transparent);
        }

        .about-content {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .about-content p {
            font-size: 1.15rem;
            color: #555;
            line-height: 1.8;
        }

        /* Footer */
        footer {
            background-color: #1a252f;
            color: var(--white);
            padding: 60px 0 20px;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-bottom: 40px;
        }

        .footer-col h3 {
            margin-bottom: 25px;
            font-size: 1.3rem;
            color: var(--white);
            position: relative;
        }

        .footer-col h3::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: -8px;
            width: 40px;
            height: 2px;
            background-color: var(--accent-red);
        }

        .footer-col p {
            color: #a0aec0;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .social-links {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }

        .social-links a {
            color: var(--white);
            background: rgba(255,255,255,0.05);
            width: 40px;
            height: 40px;
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 50%;
            transition: var(--transition);
            border: 1px solid rgba(255,255,255,0.1);
        }

        .social-links a:hover {
            background: var(--primary-blue);
            transform: translateY(-3px);
            border-color: var(--primary-blue);
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 12px;
        }

        .footer-links a {
            color: #a0aec0;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .footer-links a::before {
            content: '\f105';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            font-size: 0.8rem;
            color: var(--primary-blue);
        }

        .footer-links a:hover {
            color: var(--white);
            transform: translateX(5px);
        }

        .footer-bottom {
            text-align: center;
            padding-top: 25px;
            border-top: 1px solid rgba(255,255,255,0.05);
            color: #718096;
            font-size: 0.95rem;
        }

        /* Responsive Design */
        @media (max-width: 900px) {
            .hero h1 { font-size: 2.8rem; }
            .hero p { font-size: 1.1rem; }
        }

        @media (max-width: 768px) {
            .navbar { flex-direction: column; gap: 20px; }
            .nav-links { flex-wrap: wrap; justify-content: center; width: 100%; gap: 15px; }
            .hero { padding: 70px 0 60px; }
            .hero h1 { font-size: 2.2rem; }
            .hero-btns { flex-direction: column; width: 100%; max-width: 300px; margin: 0 auto; }
            .section-title h2 { font-size: 2rem; }
        }
    </style>
</head>
<body>

    <!-- Navigation -->
    <header>
        <div class="container navbar">
            <a href="index.php" class="logo">
                <i class="fas fa-heartbeat"></i> MediMatch
            </a>
            <div class="nav-links">
                <a href="#home" data-i18n="home" data-i18n-english="Home">Home</a>
                <a href="#about" data-i18n="about" data-i18n-english="About">About</a>
                <a href="#features" data-i18n="features" data-i18n-english="Features">Features</a>
                <a href="#contact" data-i18n="contact" data-i18n-english="Contact">Contact</a>
                <a href="login.php" data-i18n="login" data-i18n-english="Login">Login</a>
                <a href="register.php" class="nav-btn" data-i18n="register" data-i18n-english="Register">Register</a>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero" id="home">
        <div class="container hero-content">
            <h1>MediMatch</h1>
            <h3 style="color: #555; margin-bottom: 15px; font-weight: 600;" data-i18n="smartSystem" data-i18n-english="Smart Organ & Blood Donation Management System">Smart Organ & Blood Donation Management System</h3>
            <p>Connecting Donors, Patients, and Hospitals to Save Lives. Join our network of donors, patients, and healthcare providers to make a life-changing impact today.</p>
            <div class="hero-btns">
                <a href="login.php" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt"></i> <span data-i18n="getStarted" data-i18n-english="Get Started">Get Started</span>
                </a>
                <a href="register.php" class="btn btn-secondary">
                    <i class="fas fa-user-plus"></i> <span data-i18n="registerNow" data-i18n-english="Register Now">Register Now</span>
                </a>
            </div>
        </div>
    </section>

    <!-- Roles Section -->
    <section class="roles" id="roles">
        <div class="container">
            <div class="section-title">
                <h2 data-i18n="accessPortals" data-i18n-english="Access Portals">Access Portals</h2>
                <div class="divider"></div>
            </div>
            
            <div class="cards-grid">
                <!-- Patient Card -->
                <a href="patient_dashboard.php" class="card">
                    <div class="icon-wrapper">
                        <i class="fas fa-user-injured"></i>
                    </div>
                    <h3>Patient</h3>
                    <p>Request blood or organs, track matching status, and manage your medical needs.</p>
                    <span class="card-btn">Patient Portal</span>
                </a>

                <!-- Donor Card -->
                <a href="donor_dashboard.php" class="card">
                    <div class="icon-wrapper">
                        <i class="fas fa-hand-holding-heart"></i>
                    </div>
                    <h3>Donor</h3>
                    <p>Pledge organs, donate blood, update your availability, and save lives.</p>
                    <span class="card-btn">Donor Portal</span>
                </a>

                <!-- Hospital Card -->
                <a href="hospital_dashboard.php" class="card">
                    <div class="icon-wrapper">
                        <i class="fas fa-hospital"></i>
                    </div>
                    <h3>Hospital</h3>
                    <p>Manage patients, coordinate organ transplants, and handle emergency requests.</p>
                    <span class="card-btn">Hospital Portal</span>
                </a>

                <!-- Blood Bank Card -->
                <a href="bloodbank_dashboard.php" class="card">
                    <div class="icon-wrapper">
                        <i class="fas fa-vials"></i>
                    </div>
                    <h3>Blood Bank</h3>
                    <p>Track blood inventory, organize donation camps, and dispatch blood units.</p>
                    <span class="card-btn">Blood Bank Portal</span>
                </a>

                <!-- Admin Card -->
                <a href="admin_dashboard.php" class="card">
                    <div class="icon-wrapper">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <h3>Admin</h3>
                    <p>System oversight, approval management, operations tracking and analytics.</p>
                    <span class="card-btn">Admin Portal</span>
                </a>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features" id="features">
        <div class="container">
            <div class="section-title">
                <h2>System Features</h2>
                <div class="divider"></div>
            </div>

            <div class="feature-grid">
                <!-- Feature 1 -->
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-microchip"></i>
                    </div>
                    <div class="feature-content">
                        <h4>Smart Organ Matching</h4>
                        <p>Automated algorithm matching donors to patients based on medical compatibility and urgency.</p>
                    </div>
                </div>

                <!-- Feature 2 -->
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-tint"></i>
                    </div>
                    <div class="feature-content">
                        <h4>Blood Availability Tracking</h4>
                        <p>Real-time inventory management across designated blood banks and hospitals.</p>
                    </div>
                </div>

                <!-- Feature 3 -->
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-sort-numeric-down"></i>
                    </div>
                    <div class="feature-content">
                        <h4>Priority-based Allocation</h4>
                        <p>Fair and systematic distribution using dynamic priority score calculation systems.</p>
                    </div>
                </div>

                <!-- Feature 4 -->
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <div class="feature-content">
                        <h4>Real-time Requests</h4>
                        <p>Instant notification and processing of emergency blood and organ requirements.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section class="about" id="about">
        <div class="container">
            <div class="section-title">
                <h2>About The System</h2>
                <div class="divider"></div>
            </div>
            <div class="about-content">
                <p>MediMatch is a centralized platform designed to bridge the gap between willing donors and patients in critical need. By integrating hospitals, blood banks, administrators, and the general public into a single unified network, we ensure that life-saving resources are allocated efficiently, transparently, and securely. Our smart matching algorithms and real-time tracking drastically reduce wait times, ultimately saving more lives.</p>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer id="contact">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-col">
                    <h3>MediMatch</h3>
                    <p>Dedicated to optimizing the distribution of blood and organs to save lives through technology and community effort.</p>
                    <div class="social-links">
                        <a href="#"><i class="fab fa-facebook-f"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-linkedin-in"></i></a>
                    </div>
                </div>
                
                <div class="footer-col">
                    <h3>Quick Links</h3>
                    <ul class="footer-links">
                        <li><a href="#home">Home</a></li>
                        <li><a href="#about">About Us</a></li>
                        <li><a href="login.php">Login to Portal</a></li>
                        <li><a href="register.php">Register as Donor</a></li>
                        <li><a href="report.php">Public Reports</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h3>Contact Us</h3>
                    <p><i class="fas fa-map-marker-alt"></i> 123 Healthcare Ave, Medical District</p>
                    <p><i class="fas fa-phone-alt"></i> +1 (555) 123-4567</p>
                    <p><i class="fas fa-envelope"></i> support@lifelinksystem.org</p>
                    <p><i class="fas fa-clock"></i> 24/7 Emergency Support</p>
                </div>
            </div>
            
            <div class="footer-bottom">
                &copy; 2026 MediMatch | Saving Lives Through Smart Matching
            </div>
        </div>
    </footer>

    <!-- Simple JS for smooth scrolling -->
    <script>
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const targetId = this.getAttribute('href');
                if (targetId === '#') return;
                
                const targetElement = document.querySelector(targetId);
                if (targetElement) {
                    targetElement.scrollIntoView({
                        behavior: 'smooth'
                    });
                }
            });
        });
    </script>
    <?php require 'language_switcher.php'; ?>
</body>
</html>
