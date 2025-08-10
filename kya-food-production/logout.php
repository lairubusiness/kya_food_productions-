<?php
/**
 * KYA Food Production - Logout Handler
 * Handles user logout and session cleanup
 */

require_once 'config/constants.php'; // Ensure constants are loaded first
require_once 'config/session.php';
require_once 'config/database.php';

SessionManager::start();

// Log logout activity if user is logged in
if (SessionManager::isLoggedIn()) {
    SessionManager::logActivity('user_logout');
}

// Destroy session
SessionManager::destroy();

// Redirect to login page with logout message
header('Location: login.php?logout=1');
exit();
?>
