<?php
/**
 * KYA Food Production - Quick Database Setup
 * Run this file once to create the database and default users
 */

// Database configuration
$host = "localhost";
$username = "root";
$password = "";
$database = "kya_food_production";

try {
    // Connect to MySQL
    $pdo = new PDO("mysql:host=$host", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>KYA Food Production - Database Setup</h2>";
    echo "<div style='font-family: Arial; padding: 20px;'>";
    
    // Create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "✅ Database '$database' created successfully<br>";
    
    // Use the database
    $pdo->exec("USE `$database`");
    
    // Create users table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `users` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `username` varchar(50) NOT NULL,
            `password` varchar(255) NOT NULL,
            `role` enum('admin','section1_manager','section2_manager','section3_manager') NOT NULL,
            `full_name` varchar(100) NOT NULL,
            `email` varchar(100) DEFAULT NULL,
            `phone` varchar(20) DEFAULT NULL,
            `last_login` timestamp NULL DEFAULT NULL,
            `login_attempts` int(11) DEFAULT 0,
            `account_locked` tinyint(1) DEFAULT 0,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Users table created successfully<br>";
    
    // Create inventory table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `inventory` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `section` int(11) NOT NULL,
            `item_code` varchar(50) NOT NULL,
            `item_name` varchar(100) NOT NULL,
            `category` varchar(50) NOT NULL,
            `description` text DEFAULT NULL,
            `quantity` decimal(10,3) NOT NULL DEFAULT 0.000,
            `unit` varchar(20) NOT NULL,
            `unit_cost` decimal(10,2) DEFAULT NULL,
            `min_threshold` decimal(10,3) NOT NULL,
            `max_threshold` decimal(10,3) NOT NULL,
            `expiry_date` date DEFAULT NULL,
            `batch_number` varchar(50) DEFAULT NULL,
            `storage_location` varchar(100) DEFAULT NULL,
            `quality_grade` enum('A','B','C','D') DEFAULT 'A',
            `status` enum('active','inactive','expired','damaged','recalled') DEFAULT 'active',
            `alert_status` enum('normal','low_stock','critical','expired','expiring_soon') DEFAULT 'normal',
            `created_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `item_code` (`item_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Inventory table created successfully<br>";
    
    // Create notifications table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `notifications` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `notification_code` varchar(20) NOT NULL,
            `user_id` int(11) DEFAULT NULL,
            `section` int(11) DEFAULT NULL,
            `priority` enum('low','medium','high','critical') DEFAULT 'medium',
            `type` enum('inventory_alert','expiry_warning','quality_issue','system_alert','process_complete','user_action') NOT NULL,
            `title` varchar(200) NOT NULL,
            `message` text NOT NULL,
            `action_required` tinyint(1) DEFAULT 0,
            `is_read` tinyint(1) DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `notification_code` (`notification_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Notifications table created successfully<br>";
    
    // Create activity_logs table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `activity_logs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) DEFAULT NULL,
            `action` varchar(100) NOT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Activity logs table created successfully<br>";
    
    // Insert default users with correct password hashes
    $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
    $managerPassword = password_hash('admin123', PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO users (username, password, role, full_name, email, phone) VALUES (?, ?, ?, ?, ?, ?)");
    
    $users = [
        ['admin', $adminPassword, 'admin', 'System Administrator', 'admin@kyafood.com', '+94-123-456-789'],
        ['section1_mgr', $managerPassword, 'section1_manager', 'Raw Materials Manager', 'section1@kyafood.com', '+94-123-456-791'],
        ['section2_mgr', $managerPassword, 'section2_manager', 'Processing Manager', 'section2@kyafood.com', '+94-123-456-792'],
        ['section3_mgr', $managerPassword, 'section3_manager', 'Packaging Manager', 'section3@kyafood.com', '+94-123-456-793']
    ];
    
    foreach ($users as $user) {
        $stmt->execute($user);
    }
    echo "✅ Default users created successfully<br>";
    
    // Insert sample inventory data
    $stmt = $pdo->prepare("INSERT IGNORE INTO inventory (section, item_code, item_name, category, description, quantity, unit, unit_cost, min_threshold, max_threshold, expiry_date, batch_number, storage_location, quality_grade, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $inventory = [
        [1, 'RM001', 'Fresh Mangoes', 'Raw Materials', 'Premium quality fresh mangoes', 500.000, 'kg', 2.50, 50.000, 1000.000, '2025-01-15', 'MG2025001', 'Cold Storage A1', 'A', 'active', 1],
        [1, 'RM002', 'Fresh Pineapples', 'Raw Materials', 'Sweet golden pineapples', 300.000, 'kg', 1.80, 30.000, 600.000, '2025-01-20', 'PA2025001', 'Cold Storage A2', 'A', 'active', 1],
        [2, 'PR001', 'Dehydrated Mango Slices', 'Processed', 'Premium dehydrated mango slices', 150.000, 'kg', 8.50, 20.000, 300.000, '2025-12-31', 'DM2025001', 'Dry Storage B1', 'A', 'active', 2],
        [3, 'PK001', 'Vacuum Sealed Mango Pack', 'Packaged', 'Consumer ready vacuum sealed packs', 500.000, 'pcs', 12.00, 50.000, 1000.000, '2026-01-31', 'VM2025001', 'Finished Goods C1', 'A', 'active', 3]
    ];
    
    foreach ($inventory as $item) {
        $stmt->execute($item);
    }
    echo "✅ Sample inventory data created successfully<br>";
    
    // Insert sample notifications
    $stmt = $pdo->prepare("INSERT IGNORE INTO notifications (notification_code, section, priority, type, title, message, action_required) VALUES (?, ?, ?, ?, ?, ?, ?)");
    
    $notifications = [
        ['EXPIRY_001', 1, 'critical', 'expiry_warning', 'Items Expiring Soon', 'Some items in Section 1 are approaching expiry dates', 1],
        ['LOW_STOCK_001', 2, 'high', 'inventory_alert', 'Low Stock Alert', 'Dehydrated products running low in Section 2', 1]
    ];
    
    foreach ($notifications as $notification) {
        $stmt->execute($notification);
    }
    echo "✅ Sample notifications created successfully<br>";
    
    echo "<br><h3>🎉 Database Setup Complete!</h3>";
    echo "<p><strong>Default Login Credentials:</strong></p>";
    echo "<ul>";
    echo "<li><strong>Admin:</strong> username = <code>admin</code>, password = <code>admin123</code></li>";
    echo "<li><strong>Section 1 Manager:</strong> username = <code>section1_mgr</code>, password = <code>admin123</code></li>";
    echo "<li><strong>Section 2 Manager:</strong> username = <code>section2_mgr</code>, password = <code>admin123</code></li>";
    echo "<li><strong>Section 3 Manager:</strong> username = <code>section3_mgr</code>, password = <code>admin123</code></li>";
    echo "</ul>";
    echo "<p><a href='index.php' style='background: #2c5f41; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🚀 Go to Login Page</a></p>";
    echo "<p><em>You can delete this setup file (setup_database.php) after successful login.</em></p>";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<div style='color: red; font-family: Arial; padding: 20px;'>";
    echo "<h3>❌ Database Setup Failed</h3>";
    echo "<p>Error: " . $e->getMessage() . "</p>";
    echo "<p>Please check:</p>";
    echo "<ul>";
    echo "<li>XAMPP is running</li>";
    echo "<li>MySQL service is started</li>";
    echo "<li>Database credentials are correct</li>";
    echo "</ul>";
    echo "</div>";
}
?>
