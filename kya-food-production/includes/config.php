<?php
/**
 * KYA Food Production - Main Configuration File
 * Includes all necessary configuration files and establishes database connection
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include configuration files
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

// Initialize database connection
try {
    $database = new Database();
    $conn = $database->connect();
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}

// Set error reporting for development
// Define environment if not already defined
if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', 'development'); // Change to 'production' for live environment
}

if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Set timezone
if (defined('DEFAULT_TIMEZONE')) {
    date_default_timezone_set(DEFAULT_TIMEZONE);
}
?>
if (defined('DEFAULT_TIMEZONE')) {
    date_default_timezone_set(DEFAULT_TIMEZONE);
}
?>
