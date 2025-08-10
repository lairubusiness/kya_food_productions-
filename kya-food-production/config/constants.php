<?php
/**
 * KYA Food Production - System Constants
 * Global constants and configuration values
 */

define('SITE_NAME', 'KYA Food Production Management System');
define('SITE_VERSION', '1.0.0');
define('COMPANY_NAME', 'KYA Food Production');
define('DEFAULT_TIMEZONE', 'Asia/Colombo');
define('DATE_FORMAT', 'Y-m-d');
define('DATETIME_FORMAT', 'Y-m-d H:i:s');
define('DISPLAY_DATE_FORMAT', 'd/m/Y');
define('DISPLAY_DATETIME_FORMAT', 'd/m/Y H:i');

// File upload settings
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif']);
define('ALLOWED_DOCUMENT_TYPES', ['pdf', 'doc', 'docx', 'xls', 'xlsx']);
define('UPLOAD_PATH', __DIR__ . '/../assets/uploads/');

// Email settings
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-app-password');

// Security settings
define('SESSION_TIMEOUT', 1800); // 30 minutes
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900); // 15 minutes
define('PASSWORD_MIN_LENGTH', 8);

// Inventory settings
define('DEFAULT_LOW_STOCK_PERCENTAGE', 10);
define('EXPIRY_WARNING_DAYS', 30);
define('CRITICAL_EXPIRY_DAYS', 7);

// Section definitions
define('SECTIONS', [
    1 => ['name' => 'Raw Material Handling', 'code' => 'RMH', 'color' => '#2c5f41'],
    2 => ['name' => 'Dehydration Processing', 'code' => 'DHP', 'color' => '#4a8b3a'],
    3 => ['name' => 'Packaging & Storage', 'code' => 'PKS', 'color' => '#ff6b35']
]);

// User roles and permissions
define('USER_ROLES', [
    'admin' => [
        'name' => 'System Administrator',
        'sections' => [1, 2, 3],
        'permissions' => ['all']
    ],
    'section1_manager' => [
        'name' => 'Section 1 Manager',
        'sections' => [1],
        'permissions' => ['section_manage', 'inventory_manage', 'reports_view']
    ],
    'section2_manager' => [
        'name' => 'Section 2 Manager', 
        'sections' => [2],
        'permissions' => ['section_manage', 'inventory_manage', 'reports_view']
    ],
    'section3_manager' => [
        'name' => 'Section 3 Manager',
        'sections' => [3], 
        'permissions' => ['section_manage', 'inventory_manage', 'reports_view']
    ]
]);

// Status definitions
define('INVENTORY_STATUS', [
    'active' => ['name' => 'Active', 'color' => 'success'],
    'inactive' => ['name' => 'Inactive', 'color' => 'secondary'],
    'expired' => ['name' => 'Expired', 'color' => 'danger'],
    'damaged' => ['name' => 'Damaged', 'color' => 'warning'],
    'recalled' => ['name' => 'Recalled', 'color' => 'dark']
]);

define('ORDER_STATUS', [
    'pending' => ['name' => 'Pending', 'color' => 'warning'],
    'processing' => ['name' => 'Processing', 'color' => 'info'],
    'quality_check' => ['name' => 'Quality Check', 'color' => 'primary'],
    'packaging' => ['name' => 'Packaging', 'color' => 'secondary'],
    'ready_to_ship' => ['name' => 'Ready to Ship', 'color' => 'success'],
    'shipped' => ['name' => 'Shipped', 'color' => 'success'],
    'delivered' => ['name' => 'Delivered', 'color' => 'success'],
    'cancelled' => ['name' => 'Cancelled', 'color' => 'danger']
]);

// Set default timezone
date_default_timezone_set(DEFAULT_TIMEZONE);
?>
