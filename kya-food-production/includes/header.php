<?php
/**
 * KYA Food Production - Common Header
 * Shared header component for all authenticated pages
 */

if (!defined('SITE_NAME')) {
    require_once __DIR__ . '/../config/constants.php';
}

if (!class_exists('SessionManager')) {
    require_once __DIR__ . '/../config/session.php';
}

// Ensure user is logged in
SessionManager::requireLogin();
$userInfo = SessionManager::getUserInfo();

// Get current page for navigation highlighting
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

// Get unread notifications count
$unreadNotifications = 0;
try {
    require_once __DIR__ . '/../config/database.php';
    $db = new Database();
    $conn = $db->connect();
    
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM notifications 
        WHERE (user_id = ? OR user_id IS NULL) 
        AND is_read = FALSE
    ");
    $stmt->execute([$userInfo['id']]);
    $result = $stmt->fetch();
    $unreadNotifications = $result['count'] ?? 0;
} catch (Exception $e) {
    error_log("Header notification count error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo SITE_NAME; ?></title>
    
    <!-- CSS Dependencies -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.css" rel="stylesheet">
    <link href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>assets/css/style.css" rel="stylesheet">
    <link href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>assets/css/dashboard.css" rel="stylesheet">
    <link href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>assets/css/responsive.css" rel="stylesheet">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>assets/images/favicon.ico">
    
    <!-- Meta Tags -->
    <meta name="description" content="KYA Food Production Management System">
    <meta name="author" content="KYA Food Production">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Modern Header Enhancement Styles -->
    <style>
        /* Enhanced Modern Header Styles */
        .modern-header-wrapper {
            background: linear-gradient(135deg, #1a3009 0%, #2d5016 50%, #4a7c2a 100%);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            position: relative;
            overflow: hidden;
        }
        
        .enhanced-sidebar {
            background: linear-gradient(180deg, #1a3009 0%, #2d5016 100%);
            border-right: 3px solid #4a7c2a;
            box-shadow: 4px 0 15px rgba(0, 0, 0, 0.1);
        }
        
        .modern-sidebar-header {
            background: rgba(255, 255, 255, 0.05);
            border-bottom: 2px solid rgba(255, 255, 255, 0.1);
            padding: 25px 20px;
            text-align: center;
        }
        
        .enhanced-logo {
            height: 50px;
            filter: drop-shadow(0 2px 8px rgba(0, 0, 0, 0.3));
        }
        
        .modern-sidebar-title {
            color: #ffffff;
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 5px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            letter-spacing: 0.5px;
        }
        
        .modern-sidebar-subtitle {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.9rem;
            font-weight: 400;
            margin-bottom: 0;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        }
        
        .enhanced-nav-section {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 15px 20px 8px;
            margin-top: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .enhanced-nav-link {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            transition: all 0.3s ease;
            border-radius: 0 25px 25px 0;
            margin: 2px 0 2px 10px;
        }
        
        .enhanced-nav-link:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.1);
            transform: translateX(5px);
            text-decoration: none;
        }
        
        .enhanced-nav-link.active {
            background: linear-gradient(90deg, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0.05) 100%);
            color: #ffffff;
            border-left: 4px solid #ffffff;
            font-weight: 600;
        }
        
        .enhanced-nav-icon {
            width: 20px;
            text-align: center;
            margin-right: 12px;
            font-size: 1.1rem;
        }
        
        .modern-main-header {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-bottom: 3px solid #e9ecef;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
            padding: 15px 25px;
            position: relative;
        }
        
        .modern-main-header::before {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #2d5016 0%, #4a7c2a 50%, #2d5016 100%);
        }
        
        .header-brand {
            display: flex;
            align-items: center;
            color: #2d5016;
            font-weight: 700;
            font-size: 1.3rem;
            text-decoration: none;
        }
        
        .brand-icon {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, #2d5016 0%, #4a7c2a 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            color: #ffffff;
            font-size: 1.2rem;
            box-shadow: 0 3px 10px rgba(45, 80, 22, 0.3);
        }
        
        .modern-notification-btn {
            background: linear-gradient(135deg, #2d5016 0%, #4a7c2a 100%);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            box-shadow: 0 3px 12px rgba(45, 80, 22, 0.3);
            position: relative;
        }
        
        .modern-notification-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(45, 80, 22, 0.4);
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #dc3545;
            color: #ffffff;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
            border: 2px solid #ffffff;
        }
        
        .modern-user-toggle {
            display: flex;
            align-items: center;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border: 2px solid #e9ecef;
            border-radius: 25px;
            padding: 8px 15px 8px 8px;
            color: #2d5016;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.1);
        }
        
        .modern-user-toggle:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
            border-color: #2d5016;
            color: #1a3009;
            text-decoration: none;
        }
        
        .modern-user-avatar {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, #2d5016 0%, #4a7c2a 100%);
            color: #ffffff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.1rem;
            margin-right: 10px;
            box-shadow: 0 2px 8px rgba(45, 80, 22, 0.3);
        }
        
        .modern-user-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: #2d5016;
            margin-bottom: 2px;
            line-height: 1.2;
        }
        
        .modern-user-role {
            font-size: 0.8rem;
            color: #6c757d;
            line-height: 1;
        }
        
        .sidebar-toggle-btn {
            background: linear-gradient(135deg, #2d5016 0%, #4a7c2a 100%);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            transition: all 0.3s ease;
            box-shadow: 0 3px 10px rgba(45, 80, 22, 0.3);
        }
        
        .sidebar-toggle-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 15px rgba(45, 80, 22, 0.4);
        }
        
        /* Enhanced Dropdown Styles with Proper Z-Index */
        .dropdown-menu {
            background: #ffffff;
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            padding: 10px 0;
            margin-top: 10px;
            min-width: 250px;
            z-index: 1050;
        }
        
        .dropdown-item {
            padding: 12px 20px;
            color: #2d5016;
            transition: all 0.3s ease;
            border-radius: 0;
            position: relative;
        }
        
        .dropdown-item:hover {
            background: linear-gradient(90deg, rgba(45, 80, 22, 0.1) 0%, transparent 100%);
            color: #1a3009;
            padding-left: 25px;
        }
        
        .dropdown-item i {
            width: 20px;
            text-align: center;
            margin-right: 10px;
            color: #4a7c2a;
        }
        
        .dropdown-divider {
            margin: 8px 15px;
            border-top: 1px solid #e9ecef;
        }
        
        .dropdown-toggle::after {
            display: none;
        }
        
        /* Modern System and Actions Buttons */
        .modern-system-btn,
        .modern-actions-btn {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            color: #ffffff;
            padding: 10px 12px;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            position: relative;
            overflow: hidden;
        }
        
        .modern-system-btn::before,
        .modern-actions-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .modern-system-btn:hover,
        .modern-actions-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.4);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
            color: #ffffff;
        }
        
        .modern-system-btn:hover::before,
        .modern-actions-btn:hover::before {
            left: 100%;
        }
        
        .modern-system-btn:focus,
        .modern-actions-btn:focus {
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.3);
            outline: none;
        }
        
        /* System Button Specific Styling */
        .modern-system-btn {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.2), rgba(25, 135, 84, 0.2));
            border-color: rgba(40, 167, 69, 0.3);
        }
        
        .modern-system-btn:hover {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.3), rgba(25, 135, 84, 0.3));
            border-color: rgba(40, 167, 69, 0.5);
        }
        
        /* Actions Button Specific Styling */
        .modern-actions-btn {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.2), rgba(255, 143, 0, 0.2));
            border-color: rgba(255, 193, 7, 0.3);
        }
        
        .modern-actions-btn:hover {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.3), rgba(255, 143, 0, 0.3));
            border-color: rgba(255, 193, 7, 0.5);
        }
        
        /* Enhanced Dropdown Headers */
        .dropdown-header {
            font-weight: 600;
            color: #2d5016;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 20px 8px;
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 5px;
        }
        
        /* Improved Dropdown Items with Icons */
        .dropdown-item {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #2d5016;
            transition: all 0.3s ease;
            border-radius: 0;
            position: relative;
            font-size: 0.95rem;
        }
        
        .dropdown-item:hover {
            background: linear-gradient(90deg, rgba(45, 80, 22, 0.1) 0%, transparent 100%);
            color: #1a3009;
            padding-left: 25px;
            transform: translateX(3px);
        }
        
        .dropdown-item i {
            width: 20px;
            text-align: center;
            margin-right: 10px;
            transition: transform 0.3s ease;
        }
        
        .dropdown-item:hover i {
            transform: scale(1.1);
        }
        
        .dropdown-item.text-danger {
            color: #dc3545;
        }
        
        .dropdown-item.text-danger:hover {
            background: linear-gradient(90deg, rgba(220, 53, 69, 0.1) 0%, transparent 100%);
            color: #b02a37;
        }
        
        .dropdown-item.text-muted {
            color: #6c757d;
            font-style: italic;
        }
        
        /* Responsive adjustments for system buttons */
        @media (max-width: 768px) {
            .modern-system-btn,
            .modern-actions-btn {
                padding: 8px 10px;
                font-size: 1rem;
            }
            
            .dropdown-header {
                font-size: 0.85rem;
                padding: 10px 15px 6px;
            }
            
            .dropdown-item {
                padding: 10px 15px;
                font-size: 0.9rem;
            }
        }
        
        /* System Powers Dropdown */
        .system-powers-menu {
            min-width: 350px;
        }
        
        .system-powers-menu .dropdown-header {
            padding: 15px 20px;
            font-size: 1.1rem;
            text-align: center;
        }
        
        .system-powers-menu .dropdown-item {
            padding: 10px 20px;
            font-size: 1rem;
        }
        
        .system-powers-menu .dropdown-item i {
            width: 25px;
            margin-right: 15px;
        }
        
        /* System Powers Button - Most Prominent */
        .modern-powers-btn {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 15px;
            color: #ffffff;
            padding: 12px 16px;
            font-size: 1.2rem;
            font-weight: 600;
            transition: all 0.4s ease;
            backdrop-filter: blur(15px);
            position: relative;
            overflow: hidden;
            box-shadow: 0 6px 20px rgba(255, 107, 107, 0.3);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .modern-powers-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.6s ease;
        }
        
        .modern-powers-btn:hover {
            background: linear-gradient(135deg, #ff5252 0%, #d63031 100%);
            border-color: rgba(255, 255, 255, 0.5);
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 12px 35px rgba(255, 107, 107, 0.4);
            color: #ffffff;
        }
        
        .modern-powers-btn:hover::before {
            left: 100%;
        }
        
        .modern-powers-btn:focus {
            box-shadow: 0 0 0 4px rgba(255, 107, 107, 0.4);
            outline: none;
        }
        
        .modern-powers-btn i {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        /* Enhanced Z-Index Management */
        .dropdown-menu {
            z-index: 1060 !important;
            position: absolute !important;
        }
        
        .dropdown.show .dropdown-menu {
            z-index: 1070 !important;
        }
        
        /* System Powers Menu Specific Styling */
        .system-powers-menu {
            min-width: 380px !important;
            max-height: 80vh;
            overflow-y: auto;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border: 2px solid rgba(255, 107, 107, 0.2);
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.2);
        }
        
        .system-powers-menu .dropdown-header {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            color: white;
            margin: -10px -20px 10px -20px;
            padding: 15px 20px;
            font-size: 1.1rem;
            font-weight: 700;
            text-align: center;
            border-radius: 13px 13px 0 0;
            box-shadow: 0 4px 15px rgba(255, 107, 107, 0.3);
        }
        
        .system-powers-menu .dropdown-header:not(:first-child) {
            background: #2d5016;
            margin: 10px -20px 5px -20px;
            padding: 10px 20px;
            font-size: 0.95rem;
            border-radius: 0;
        }
        
        .system-powers-menu .dropdown-item {
            padding: 12px 25px;
            font-size: 1rem;
            transition: all 0.3s ease;
            border-radius: 8px;
            margin: 2px 10px;
        }
        
        .system-powers-menu .dropdown-item:hover {
            background: linear-gradient(90deg, rgba(255, 107, 107, 0.1) 0%, transparent 100%);
            transform: translateX(5px);
            padding-left: 30px;
        }
        
        .system-powers-menu .dropdown-item i {
            width: 25px;
            margin-right: 15px;
            font-size: 1.1rem;
        }
        
        /* Direct Logout Button - Prominent and Accessible */
        .modern-logout-btn {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 12px;
            color: #ffffff !important;
            padding: 10px 15px;
            font-size: 1.1rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
            display: flex;
            align-items: center;
        }
        
        .modern-logout-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .modern-logout-btn:hover {
            background: linear-gradient(135deg, #c82333 0%, #a71e2a 100%);
            border-color: rgba(255, 255, 255, 0.5);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(220, 53, 69, 0.4);
            color: #ffffff !important;
            text-decoration: none;
        }
        
        .modern-logout-btn:hover::before {
            left: 100%;
        }
        
        .modern-logout-btn:focus {
            box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.4);
            outline: none;
            color: #ffffff !important;
            text-decoration: none;
        }
        
        .modern-logout-btn i {
            margin-right: 8px;
        }
        
        /* Responsive logout button */
        @media (max-width: 768px) {
            .modern-logout-btn {
                padding: 8px 12px;
                font-size: 1rem;
            }
        }
        
        /* System Family Banner Styling */
        .system-family-banner {
            background: linear-gradient(135deg, #1a3009 0%, #2d5016 50%, #4a7c2a 100%);
            padding: 1.5rem 2rem;
            border-radius: 0 0 20px 20px;
            box-shadow: 0 8px 32px rgba(45, 80, 22, 0.4);
            margin-bottom: 1rem;
            position: relative;
            overflow: hidden;
        }
        
        .system-family-banner::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.05)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
            pointer-events: none;
        }
        
        .system-brand {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
            position: relative;
            z-index: 2;
        }
        
        .system-logo {
            height: 60px;
            width: auto;
            margin-right: 1.5rem;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.3));
        }
        
        .brand-text {
            color: white;
        }
        
        .system-title {
            font-size: 2.2rem;
            font-weight: 700;
            margin: 0;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            background: linear-gradient(45deg, #ffffff, #e8f5e8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .system-subtitle {
            font-size: 1.1rem;
            margin: 0;
            opacity: 0.9;
            font-weight: 300;
            letter-spacing: 1px;
        }
        
        .system-modules {
            display: flex;
            flex-wrap: wrap;
            gap: 0.8rem;
            position: relative;
            z-index: 2;
        }
        
        .module-badge {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 500;
            border: 1px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .module-badge:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        
        .module-section1 { border-left: 4px solid #28a745; }
        .module-section2 { border-left: 4px solid #007bff; }
        .module-section3 { border-left: 4px solid #ffc107; }
        .module-inventory { border-left: 4px solid #17a2b8; }
        .module-quality { border-left: 4px solid #6f42c1; }
        .module-analytics { border-left: 4px solid #fd7e14; }
        
        .system-status-indicators {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            position: relative;
            z-index: 2;
        }
        
        .status-indicator {
            display: flex;
            align-items: center;
            color: white;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .status-indicator i {
            margin-right: 0.5rem;
            width: 16px;
        }
        
        .status-online i { color: #28a745; animation: pulse-green 2s infinite; }
        .status-monitoring i { color: #17a2b8; animation: blink 3s infinite; }
        .status-secure i { color: #ffc107; }
        
        @keyframes pulse-green {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        @keyframes blink {
            0%, 90%, 100% { opacity: 1; }
            95% { opacity: 0.3; }
        }
        
        .main-nav-header {
            background: rgba(255, 255, 255, 0.95);
            padding: 1rem 2rem;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            margin-bottom: 1rem;
        }
        
        .current-page-info {
            flex: 1;
            text-align: center;
        }
        
        .breadcrumb-container {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .current-user {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2d5016;
            margin-bottom: 0.2rem;
        }
        
        .current-role {
            font-size: 0.9rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Responsive System Family Banner */
        @media (max-width: 768px) {
            .system-family-banner {
                padding: 1rem;
            }
            
            .system-brand {
                flex-direction: column;
                text-align: center;
                margin-bottom: 1rem;
            }
            
            .system-logo {
                height: 40px;
                margin-right: 0;
                margin-bottom: 0.5rem;
            }
            
            .system-title {
                font-size: 1.8rem;
            }
            
            .system-subtitle {
                font-size: 1rem;
            }
            
            .system-modules {
                justify-content: center;
            }
            
            .module-badge {
                font-size: 0.75rem;
                padding: 0.4rem 0.8rem;
            }
            
            .system-status-indicators {
                flex-direction: row;
                justify-content: center;
                gap: 1rem;
                margin-top: 1rem;
            }
            
            .status-indicator {
                font-size: 0.8rem;
            }
            
            .main-nav-header {
                padding: 0.8rem 1rem;
            }
            
            .current-user {
                font-size: 1rem;
            }
            
            .current-role {
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body class="dashboard-body">
    <div class="dashboard-container">
        <!-- Enhanced Modern Sidebar -->
        <nav class="sidebar enhanced-sidebar" id="sidebar">
            <div class="sidebar-header modern-sidebar-header">
                <img src="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>assets/images/logo.png" alt="KYA" class="sidebar-logo enhanced-logo">
                <h4 class="sidebar-title modern-sidebar-title">KYA Food Production</h4>
                <p class="sidebar-subtitle modern-sidebar-subtitle">Management System</p>
            </div>
            
            <div class="sidebar-nav">
                <!-- Dashboard -->
                <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>dashboard.php" 
                   class="nav-link enhanced-nav-link <?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt enhanced-nav-icon"></i>
                    <span class="nav-text">Dashboard</span>
                </a>
                
                <!-- Section 1 - Raw Materials Dropdown -->
                <?php if (SessionManager::canAccessSection(1)): ?>
                    <div class="nav-section enhanced-nav-section">Section 1 - Raw Materials</div>
                    
                    <!-- Main Section Link -->
                    <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/section1/" 
                       class="nav-link enhanced-nav-link <?php echo $currentDir === 'section1' ? 'active' : ''; ?>">
                        <i class="fas fa-warehouse enhanced-nav-icon"></i>
                        <span class="nav-text">Raw Materials</span>
                    </a>
                    
                    <!-- Dropdown for Section 1 Sub-items -->
                    <div class="nav-dropdown">
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/section1/inventory.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-boxes enhanced-nav-icon"></i>
                            <span class="nav-text">Inventory Management</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/section1/temperature_monitor.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-thermometer-half enhanced-nav-icon"></i>
                            <span class="nav-text">Temperature Monitor</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/section1/quality_control.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-check-circle enhanced-nav-icon"></i>
                            <span class="nav-text">Quality Control</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/section1/receiving.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-truck-loading enhanced-nav-icon"></i>
                            <span class="nav-text">Material Receiving</span>
                        </a>
                    </div>
                <?php endif; ?>
                
                <!-- Section 2 - Processing Dropdown -->
                <?php if (SessionManager::canAccessSection(2)): ?>
                    <div class="nav-section enhanced-nav-section">Section 2 - Processing</div>
                    
                    <!-- Main Section Link -->
                    <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/section2/" 
                       class="nav-link enhanced-nav-link <?php echo $currentDir === 'section2' ? 'active' : ''; ?>">
                        <i class="fas fa-cogs enhanced-nav-icon"></i>
                        <span class="nav-text">Processing</span>
                    </a>
                    
                    <!-- Dropdown for Section 2 Sub-items -->
                    <div class="nav-dropdown">
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/section2/batch_tracking.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-barcode enhanced-nav-icon"></i>
                            <span class="nav-text">Batch Tracking</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/section2/production_logs.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-clipboard-list enhanced-nav-icon"></i>
                            <span class="nav-text">Production Logs</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/section2/equipment.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-tools enhanced-nav-icon"></i>
                            <span class="nav-text">Equipment Status</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/section2/recipes.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-book enhanced-nav-icon"></i>
                            <span class="nav-text">Recipe Management</span>
                        </a>
                    </div>
                <?php endif; ?>
                
                <!-- Section 3 - Packaging Dropdown -->
                <?php if (SessionManager::canAccessSection(3)): ?>
                    <div class="nav-section enhanced-nav-section">Section 3 - Packaging</div>
                    
                    <!-- Main Section Link -->
                    <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/section3/" 
                       class="nav-link enhanced-nav-link <?php echo $currentDir === 'section3' ? 'active' : ''; ?>">
                        <i class="fas fa-box enhanced-nav-icon"></i>
                        <span class="nav-text">Packaging</span>
                    </a>
                    
                    <!-- Dropdown for Section 3 Sub-items -->
                    <div class="nav-dropdown">
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/section3/packaging_lines.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-industry enhanced-nav-icon"></i>
                            <span class="nav-text">Packaging Lines</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/section3/labeling.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-tags enhanced-nav-icon"></i>
                            <span class="nav-text">Labeling System</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/section3/shipping.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-shipping-fast enhanced-nav-icon"></i>
                            <span class="nav-text">Shipping</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/section3/quality_check.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-search enhanced-nav-icon"></i>
                            <span class="nav-text">Final Quality Check</span>
                        </a>
                    </div>
                <?php endif; ?>
                
                <!-- Inventory Management Dropdown -->
                <?php if (SessionManager::hasPermission('inventory_manage')): ?>
                    <div class="nav-section enhanced-nav-section">Inventory</div>
                    
                    <!-- Main Inventory Link -->
                    <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/inventory/" 
                       class="nav-link enhanced-nav-link <?php echo $currentDir === 'inventory' ? 'active' : ''; ?>">
                        <i class="fas fa-boxes enhanced-nav-icon"></i>
                        <span class="nav-text">Inventory</span>
                    </a>
                    
                    <!-- Dropdown for Inventory Sub-items -->
                    <div class="nav-dropdown">
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/inventory/stock_levels.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-layer-group enhanced-nav-icon"></i>
                            <span class="nav-text">Stock Levels</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/inventory/expiry_tracking.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-calendar-times enhanced-nav-icon"></i>
                            <span class="nav-text">Expiry Tracking</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/inventory/stock_alerts.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-exclamation-triangle enhanced-nav-icon"></i>
                            <span class="nav-text">Stock Alerts</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/inventory/transfers.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-exchange-alt enhanced-nav-icon"></i>
                            <span class="nav-text">Stock Transfers</span>
                        </a>
                    </div>
                <?php endif; ?>
                
                <!-- Orders Dropdown (Admin only) -->
                <?php if ($userInfo['role'] === 'admin'): ?>
                    <div class="nav-section enhanced-nav-section">Orders</div>
                    
                    <!-- Main Orders Link -->
                    <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/orders/" 
                       class="nav-link enhanced-nav-link <?php echo $currentDir === 'orders' ? 'active' : ''; ?>">
                        <i class="fas fa-shopping-cart enhanced-nav-icon"></i>
                        <span class="nav-text">Orders</span>
                    </a>
                    
                    <!-- Dropdown for Orders Sub-items -->
                    <div class="nav-dropdown">
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/orders/pending.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-clock enhanced-nav-icon"></i>
                            <span class="nav-text">Pending Orders</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/orders/processing.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-spinner enhanced-nav-icon"></i>
                            <span class="nav-text">Processing</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/orders/completed.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-check-double enhanced-nav-icon"></i>
                            <span class="nav-text">Completed</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/orders/customers.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-users enhanced-nav-icon"></i>
                            <span class="nav-text">Customer Management</span>
                        </a>
                    </div>
                    
                    <!-- Reports Dropdown -->
                    <div class="nav-section enhanced-nav-section">Reports</div>
                    
                    <!-- Main Reports Link -->
                    <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/reports/" 
                       class="nav-link enhanced-nav-link <?php echo $currentDir === 'reports' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-bar enhanced-nav-icon"></i>
                        <span class="nav-text">Reports</span>
                    </a>
                    
                    <!-- Dropdown for Reports Sub-items -->
                    <div class="nav-dropdown">
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/reports/production.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-chart-line enhanced-nav-icon"></i>
                            <span class="nav-text">Production Reports</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/reports/inventory.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-chart-pie enhanced-nav-icon"></i>
                            <span class="nav-text">Inventory Reports</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/reports/quality.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-chart-area enhanced-nav-icon"></i>
                            <span class="nav-text">Quality Reports</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/reports/financial.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-dollar-sign enhanced-nav-icon"></i>
                            <span class="nav-text">Financial Reports</span>
                        </a>
                    </div>
                <?php endif; ?>
                
                <!-- Notifications -->
                <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/notifications/" 
                   class="nav-link enhanced-nav-link <?php echo $currentDir === 'notifications' ? 'active' : ''; ?>">
                    <i class="fas fa-bell enhanced-nav-icon"></i>
                    <span class="nav-text">Notifications</span>
                    <?php if ($unreadNotifications > 0): ?>
                        <span class="badge bg-danger ms-auto"><?php echo $unreadNotifications; ?></span>
                    <?php endif; ?>
                </a>
                
                <!-- Admin Panel Dropdown (Admin only) -->
                <?php if ($userInfo['role'] === 'admin'): ?>
                    <div class="nav-section enhanced-nav-section">Administration</div>
                    
                    <!-- Main Admin Link -->
                    <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/admin/" 
                       class="nav-link enhanced-nav-link <?php echo $currentDir === 'admin' ? 'active' : ''; ?>">
                        <i class="fas fa-cog enhanced-nav-icon"></i>
                        <span class="nav-text">Admin Panel</span>
                    </a>
                    
                    <!-- Dropdown for Admin Sub-items -->
                    <div class="nav-dropdown">
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/admin/users.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-users enhanced-nav-icon"></i>
                            <span class="nav-text">User Management</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/admin/permissions.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-key enhanced-nav-icon"></i>
                            <span class="nav-text">Permissions</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/admin/system_settings.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-sliders-h enhanced-nav-icon"></i>
                            <span class="nav-text">System Settings</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/admin/backup.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-database enhanced-nav-icon"></i>
                            <span class="nav-text">Backup & Restore</span>
                        </a>
                        <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/admin/logs.php" 
                           class="nav-link enhanced-nav-sub-link">
                            <i class="fas fa-file-alt enhanced-nav-icon"></i>
                            <span class="nav-text">System Logs</span>
                        </a>
                    </div>
                <?php endif; ?>
                
                <!-- Profile Dropdown -->
                <div class="nav-section enhanced-nav-section">Account</div>
                
                <!-- Main Profile Link -->
                <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/profile/" 
                   class="nav-link enhanced-nav-link <?php echo $currentDir === 'profile' ? 'active' : ''; ?>">
                    <i class="fas fa-user enhanced-nav-icon"></i>
                    <span class="nav-text">My Profile</span>
                </a>
                
                <!-- Dropdown for Profile Sub-items -->
                <div class="nav-dropdown">
                    <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/profile/edit.php" 
                       class="nav-link enhanced-nav-sub-link">
                        <i class="fas fa-edit enhanced-nav-icon"></i>
                        <span class="nav-text">Edit Profile</span>
                    </a>
                    <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/profile/change_password.php" 
                       class="nav-link enhanced-nav-sub-link">
                        <i class="fas fa-key enhanced-nav-icon"></i>
                        <span class="nav-text">Change Password</span>
                    </a>
                    <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/profile/activity_log.php" 
                       class="nav-link enhanced-nav-sub-link">
                        <i class="fas fa-history enhanced-nav-icon"></i>
                        <span class="nav-text">Activity Log</span>
                    </a>
                    <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/profile/preferences.php" 
                       class="nav-link enhanced-nav-sub-link">
                        <i class="fas fa-cogs enhanced-nav-icon"></i>
                        <span class="nav-text">Preferences</span>
                    </a>
                </div>
            </div>
        </nav>
        
        <!-- Main Content Area -->
        <div class="main-content" id="mainContent">
            <!-- Enhanced Header with System Family Display -->
            <header class="main-header">
                <div class="container-fluid">
                    <!-- System Family Banner -->
                    <div class="system-family-banner">
                        <div class="d-flex align-items-center justify-content-between">
                            <div class="system-family-info">
                                <div class="system-brand">
                                    <img src="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>assets/images/logo.png" 
                                         alt="KYA Logo" class="system-logo">
                                    <div class="brand-text">
                                        <h1 class="system-title">KYA Food Production</h1>
                                        <p class="system-subtitle">Integrated Management System Family</p>
                                    </div>
                                </div>
                                <div class="system-modules">
                                    <span class="module-badge module-section1">Section 1: Raw Materials</span>
                                    <span class="module-badge module-section2">Section 2: Processing</span>
                                    <span class="module-badge module-section3">Section 3: Packaging</span>
                                    <span class="module-badge module-inventory">Inventory Control</span>
                                    <span class="module-badge module-quality">Quality Assurance</span>
                                    <span class="module-badge module-analytics">Analytics Engine</span>
                                </div>
                            </div>
                            
                            <!-- System Status Indicators -->
                            <div class="system-status-indicators">
                                <div class="status-indicator status-online">
                                    <i class="fas fa-circle"></i>
                                    <span>System Online</span>
                                </div>
                                <div class="status-indicator status-monitoring">
                                    <i class="fas fa-eye"></i>
                                    <span>24/7 Monitoring</span>
                                </div>
                                <div class="status-indicator status-secure">
                                    <i class="fas fa-shield-alt"></i>
                                    <span>Secure Access</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Main Navigation Header -->
                    <div class="main-nav-header">
                        <div class="d-flex align-items-center justify-content-between">
                            <!-- Navigation Toggle -->
                            <button class="sidebar-toggle-btn" onclick="toggleSidebar()">
                                <i class="fas fa-bars"></i>
                            </button>
                            
                            <!-- Current Page Info -->
                            <div class="current-page-info">
                                <div class="breadcrumb-container">
                                    <span class="current-user">Welcome, <?php echo htmlspecialchars($userInfo['full_name']); ?></span>
                                    <span class="current-role"><?php echo USER_ROLES[$userInfo['role']]['name']; ?></span>
                                </div>
                            </div>
                            
                            <!-- Header Action Buttons -->
                            <div class="d-flex align-items-center gap-3">
                                <!-- Direct Logout Button -->
                                <a href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>logout.php" 
                                   class="modern-logout-btn" 
                                   onclick="return confirm('Are you sure you want to logout?')" 
                                   title="Logout">
                                    <i class="fas fa-sign-out-alt me-2"></i>
                                    <span class="d-none d-lg-inline">Logout</span>
                                </a>
                                
                                <!-- Notifications -->
                                <div class="dropdown">
                                    <button class="modern-notification-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
                                        <i class="fas fa-bell"></i>
                                        <?php if ($unreadNotifications > 0): ?>
                                            <span class="notification-badge"><?php echo $unreadNotifications; ?></span>
                                        <?php endif; ?>
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-end dropdown-menu" style="min-width: 300px;">
                                        <h6 class="dropdown-header d-flex justify-content-between align-items-center">
                                            <span>Notifications</span>
                                            <?php if ($unreadNotifications > 0): ?>
                                                <span class="badge bg-danger"><?php echo $unreadNotifications; ?></span>
                                            <?php endif; ?>
                                        </h6>
                                        <div class="dropdown-divider"></div>
                                        <?php if ($unreadNotifications > 0): ?>
                                            <a class="dropdown-item text-muted small" href="#">
                                                <i class="fas fa-info-circle me-2"></i>You have <?php echo $unreadNotifications; ?> unread notifications
                                            </a>
                                            <div class="dropdown-divider"></div>
                                        <?php endif; ?>
                                        <a class="dropdown-item" href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/notifications/">
                                            <i class="fas fa-eye me-2"></i>View All Notifications
                                        </a>
                                        <a class="dropdown-item" href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/notifications/?mark_all_read=1">
                                            <i class="fas fa-check-double me-2"></i>Mark All as Read
                                        </a>
                                    </div>
                                </div>
                                
                                <!-- User Profile Button -->
                                <div class="dropdown">
                                    <button class="modern-user-toggle dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="User Menu">
                                        <div class="modern-user-avatar">
                                            <?php echo strtoupper(substr($userInfo['full_name'], 0, 1)); ?>
                                        </div>
                                        <div class="d-none d-md-block">
                                            <div class="modern-user-name"><?php echo htmlspecialchars($userInfo['full_name']); ?></div>
                                            <div class="modern-user-role"><?php echo USER_ROLES[$userInfo['role']]['name']; ?></div>
                                        </div>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu">
                                        <li>
                                            <h6 class="dropdown-header">
                                                <i class="fas fa-user me-2"></i><?php echo htmlspecialchars($userInfo['full_name']); ?>
                                            </h6>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a class="dropdown-item" href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/profile/">
                                                <i class="fas fa-user-circle me-2"></i>My Profile
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/profile/profile.php">
                                                <i class="fas fa-edit me-2"></i>Edit Profile
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/profile/profile.php#security">
                                                <i class="fas fa-key me-2"></i>Change Password
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/profile/activity_log.php">
                                                <i class="fas fa-history me-2"></i>Activity Log
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item" href="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>modules/profile/preferences.php">
                                                <i class="fas fa-cog me-2"></i>Preferences
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </header>
            
            <!-- Enhanced Flash Messages -->
            <?php
            $flashMessages = SessionManager::getFlashMessages();
            if (!empty($flashMessages)):
            ?>
                <div class="flash-messages" style="padding: 0 25px; margin-top: 15px;">
                    <?php foreach ($flashMessages as $message): ?>
                        <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show" role="alert" style="border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); border-left: 4px solid;">
                            <?php echo htmlspecialchars($message['message']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Loading Overlay -->
            <div id="loadingOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100" style="background: rgba(0,0,0,0.5); z-index: 9999;">
                <div class="d-flex justify-content-center align-items-center h-100">
                    <div class="spinner-border text-light" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>

            <!-- Main Content Wrapper -->
            <div class="content-wrapper" style="padding: 20px;">

    <!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.js"></script>
    
    <!-- Custom JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Bootstrap dropdowns
            const dropdownElementList = document.querySelectorAll('.dropdown-toggle');
            const dropdownList = [...dropdownElementList].map(dropdownToggleEl => new bootstrap.Dropdown(dropdownToggleEl));
            
            // Sidebar toggle functionality
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            if (sidebarToggle && sidebar && mainContent) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('collapsed');
                    mainContent.classList.toggle('expanded');
                    
                    // Save state to localStorage
                    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                });
                
                // Restore sidebar state
                const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                if (sidebarCollapsed) {
                    sidebar.classList.add('collapsed');
                    mainContent.classList.add('expanded');
                }
            }
            
            // Auto-hide alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    if (alert.classList.contains('alert-success') || alert.classList.contains('alert-info')) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                });
            }, 5000);
            
            // Initialize tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Initialize popovers
            const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
            popoverTriggerList.map(function (popoverTriggerEl) {
                return new bootstrap.Popover(popoverTriggerEl);
            });
        });
        
        // Global loading functions
        function showLoading() {
            document.getElementById('loadingOverlay').classList.remove('d-none');
        }
        
        function hideLoading() {
            document.getElementById('loadingOverlay').classList.add('d-none');
        }
        
        // Dropdown enhancement
        document.addEventListener('show.bs.dropdown', function (event) {
            const dropdown = event.target;
            dropdown.style.zIndex = '1060';
        });
        
        document.addEventListener('hide.bs.dropdown', function (event) {
            const dropdown = event.target;
            dropdown.style.zIndex = '';
        });
        
        // Enhanced header functionality
        function refreshPage() {
            if (confirm('Are you sure you want to refresh the page? Any unsaved changes will be lost.')) {
                location.reload();
            }
        }
        
        // Initialize dropdown functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Enhanced dropdown behavior
            const dropdowns = document.querySelectorAll('.dropdown-toggle');
            dropdowns.forEach(dropdown => {
                dropdown.addEventListener('click', function(e) {
                    e.preventDefault();
                    const dropdownMenu = this.nextElementSibling;
                    if (dropdownMenu) {
                        dropdownMenu.classList.toggle('show');
                    }
                });
            });
            
            // Close dropdowns when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.dropdown')) {
                    document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                        menu.classList.remove('show');
                    });
                }
            });
            
            // Navigation dropdown functionality
            const navLinks = document.querySelectorAll('.enhanced-nav-link.has-dropdown');
            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const dropdown = this.nextElementSibling;
                    if (dropdown && dropdown.classList.contains('nav-dropdown')) {
                        dropdown.classList.toggle('show');
                        this.classList.toggle('expanded');
                    }
                });
            });
        });
    </script>
