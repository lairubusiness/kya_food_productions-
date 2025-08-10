<?php
/**
 * KYA Food Production - Web Database Setup Interface
 * Run this script via web browser to initialize the database
 */

// Enable error reporting for setup
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

// Check if setup has been run
$setupComplete = false;
$errorMessage = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_database'])) {
    try {
        // Create database connection (without specifying database first)
        $host = "localhost";
        $username = "root";
        $password = "";
        
        $conn = new PDO("mysql:host=$host;charset=utf8mb4", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create database
        $conn->exec("CREATE DATABASE IF NOT EXISTS kya_food_production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $conn->exec("USE kya_food_production");
        
        // Users table
        $conn->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin', 'section1_manager', 'section2_manager', 'section3_manager') NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            email VARCHAR(100) UNIQUE,
            phone VARCHAR(20),
            profile_image VARCHAR(255),
            last_login TIMESTAMP NULL,
            login_attempts INT DEFAULT 0,
            account_locked BOOLEAN DEFAULT FALSE,
            password_reset_token VARCHAR(255),
            password_reset_expires TIMESTAMP NULL,
            two_factor_enabled BOOLEAN DEFAULT FALSE,
            two_factor_secret VARCHAR(255),
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_username (username),
            INDEX idx_role (role),
            INDEX idx_email (email)
        ) ENGINE=InnoDB");
        
        // Inventory table
        $conn->exec("CREATE TABLE IF NOT EXISTS inventory (
            id INT AUTO_INCREMENT PRIMARY KEY,
            section INT NOT NULL CHECK (section IN (1, 2, 3)),
            item_code VARCHAR(50) UNIQUE NOT NULL,
            item_name VARCHAR(100) NOT NULL,
            category VARCHAR(50) NOT NULL,
            subcategory VARCHAR(50),
            description TEXT,
            quantity DECIMAL(10,3) NOT NULL DEFAULT 0,
            unit VARCHAR(20) NOT NULL,
            unit_cost DECIMAL(10,2),
            total_value DECIMAL(12,2) GENERATED ALWAYS AS (quantity * unit_cost) STORED,
            min_threshold DECIMAL(10,3) NOT NULL,
            max_threshold DECIMAL(10,3) NOT NULL,
            reorder_level DECIMAL(10,3),
            expiry_date DATE,
            manufacture_date DATE,
            batch_number VARCHAR(50),
            supplier_id INT,
            storage_location VARCHAR(100),
            storage_temperature DECIMAL(5,2),
            storage_humidity DECIMAL(5,2),
            quality_grade ENUM('A', 'B', 'C', 'D') DEFAULT 'A',
            status ENUM('active', 'inactive', 'expired', 'damaged', 'recalled') DEFAULT 'active',
            alert_status ENUM('normal', 'low_stock', 'critical', 'expired', 'expiring_soon') DEFAULT 'normal',
            notes TEXT,
            created_by INT,
            updated_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_section (section),
            INDEX idx_item_code (item_code),
            INDEX idx_category (category),
            INDEX idx_expiry_date (expiry_date),
            INDEX idx_status (status),
            INDEX idx_alert_status (alert_status),
            FOREIGN KEY (created_by) REFERENCES users(id),
            FOREIGN KEY (updated_by) REFERENCES users(id)
        ) ENGINE=InnoDB");
        
        // Processing logs table
        $conn->exec("CREATE TABLE IF NOT EXISTS processing_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            section INT NOT NULL,
            batch_id VARCHAR(50) NOT NULL,
            item_id INT,
            process_type ENUM('drying', 'dehydration', 'packaging', 'quality_check', 'storage') NOT NULL,
            process_stage VARCHAR(50),
            input_quantity DECIMAL(10,3) NOT NULL,
            output_quantity DECIMAL(10,3),
            waste_quantity DECIMAL(10,3) DEFAULT 0,
            yield_percentage DECIMAL(5,2),
            start_time TIMESTAMP,
            end_time TIMESTAMP,
            duration_minutes INT,
            temperature_start DECIMAL(5,2),
            temperature_end DECIMAL(5,2),
            humidity_start DECIMAL(5,2),
            humidity_end DECIMAL(5,2),
            quality_grade_input ENUM('A', 'B', 'C', 'D'),
            quality_grade_output ENUM('A', 'B', 'C', 'D'),
            equipment_used VARCHAR(100),
            operator_id INT,
            supervisor_id INT,
            notes TEXT,
            quality_checks JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_section (section),
            INDEX idx_batch_id (batch_id),
            INDEX idx_process_type (process_type),
            INDEX idx_start_time (start_time),
            FOREIGN KEY (item_id) REFERENCES inventory(id),
            FOREIGN KEY (operator_id) REFERENCES users(id),
            FOREIGN KEY (supervisor_id) REFERENCES users(id)
        ) ENGINE=InnoDB");
        
        // Notifications table
        $conn->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notification_code VARCHAR(20) UNIQUE NOT NULL,
            user_id INT,
            section INT,
            priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
            type ENUM('inventory_alert', 'expiry_warning', 'quality_issue', 'system_alert', 'process_complete', 'user_action') NOT NULL,
            category VARCHAR(50),
            title VARCHAR(200) NOT NULL,
            message TEXT NOT NULL,
            action_required BOOLEAN DEFAULT FALSE,
            action_url VARCHAR(255),
            data JSON,
            is_read BOOLEAN DEFAULT FALSE,
            is_archived BOOLEAN DEFAULT FALSE,
            read_at TIMESTAMP NULL,
            expires_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_section (section),
            INDEX idx_type (type),
            INDEX idx_priority (priority),
            INDEX idx_is_read (is_read),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        
        // Orders table
        $conn->exec("CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_number VARCHAR(50) UNIQUE NOT NULL,
            customer_name VARCHAR(100) NOT NULL,
            customer_email VARCHAR(100),
            customer_phone VARCHAR(20),
            customer_address TEXT,
            export_country VARCHAR(50),
            order_date DATE NOT NULL,
            required_date DATE,
            status ENUM('pending', 'processing', 'quality_check', 'packaging', 'ready_to_ship', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
            priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            total_amount DECIMAL(12,2) DEFAULT 0,
            currency VARCHAR(3) DEFAULT 'USD',
            payment_status ENUM('pending', 'partial', 'paid', 'refunded') DEFAULT 'pending',
            payment_method VARCHAR(50),
            shipping_method VARCHAR(50),
            tracking_number VARCHAR(100),
            compliance_documents JSON,
            special_instructions TEXT,
            created_by INT,
            assigned_to INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_order_number (order_number),
            INDEX idx_customer_email (customer_email),
            INDEX idx_status (status),
            INDEX idx_order_date (order_date),
            FOREIGN KEY (created_by) REFERENCES users(id),
            FOREIGN KEY (assigned_to) REFERENCES users(id)
        ) ENGINE=InnoDB");
        
        // Order items table
        $conn->exec("CREATE TABLE IF NOT EXISTS order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            inventory_id INT NOT NULL,
            quantity DECIMAL(10,3) NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            total_price DECIMAL(12,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
            quality_requirements TEXT,
            packaging_requirements TEXT,
            allocated_quantity DECIMAL(10,3) DEFAULT 0,
            fulfilled_quantity DECIMAL(10,3) DEFAULT 0,
            status ENUM('pending', 'allocated', 'processed', 'packaged', 'fulfilled') DEFAULT 'pending',
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (inventory_id) REFERENCES inventory(id),
            INDEX idx_order_id (order_id),
            INDEX idx_inventory_id (inventory_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB");
        
        // System settings table
        $conn->exec("CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
            category VARCHAR(50) DEFAULT 'general',
            description TEXT,
            is_editable BOOLEAN DEFAULT TRUE,
            updated_by INT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_setting_key (setting_key),
            INDEX idx_category (category),
            FOREIGN KEY (updated_by) REFERENCES users(id)
        ) ENGINE=InnoDB");
        
        // Activity logs table
        $conn->exec("CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            action VARCHAR(100) NOT NULL,
            table_name VARCHAR(50),
            record_id INT,
            old_values JSON,
            new_values JSON,
            ip_address VARCHAR(45),
            user_agent TEXT,
            session_id VARCHAR(128),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_table_name (table_name),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        
        // Insert default system settings
        $defaultSettings = [
            ['company_name', 'KYA Food Production', 'string', 'company', 'Company name'],
            ['company_email', 'info@kyafood.com', 'string', 'company', 'Company email'],
            ['company_phone', '+94-123-456-789', 'string', 'company', 'Company phone'],
            ['low_stock_threshold', '10', 'number', 'inventory', 'Default low stock threshold percentage'],
            ['expiry_alert_days', '30', 'number', 'inventory', 'Days before expiry to send alerts'],
            ['auto_backup_enabled', 'true', 'boolean', 'system', 'Enable automatic database backups'],
            ['backup_frequency', 'daily', 'string', 'system', 'Backup frequency (daily, weekly, monthly)'],
            ['email_notifications', 'true', 'boolean', 'notifications', 'Enable email notifications'],
            ['sms_notifications', 'false', 'boolean', 'notifications', 'Enable SMS notifications'],
            ['two_factor_required', 'false', 'boolean', 'security', 'Require two-factor authentication'],
            ['session_timeout', '1800', 'number', 'security', 'Session timeout in seconds'],
            ['max_login_attempts', '5', 'number', 'security', 'Maximum login attempts before lockout']
        ];
        
        $stmt = $conn->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_type, category, description) VALUES (?, ?, ?, ?, ?)");
        foreach ($defaultSettings as $setting) {
            $stmt->execute($setting);
        }
        
        // Create default users
        $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT IGNORE INTO users (username, password, role, full_name, email, phone) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute(['admin', $adminPassword, 'admin', 'System Administrator', 'admin@kyafood.com', '+94-123-456-789']);
        
        // Section managers
        $managers = [
            ['section1_mgr', password_hash('section1123', PASSWORD_DEFAULT), 'section1_manager', 'Section 1 Manager', 'section1@kyafood.com', '+94-123-456-791'],
            ['section2_mgr', password_hash('section2123', PASSWORD_DEFAULT), 'section2_manager', 'Section 2 Manager', 'section2@kyafood.com', '+94-123-456-792'],
            ['section3_mgr', password_hash('section3123', PASSWORD_DEFAULT), 'section3_manager', 'Section 3 Manager', 'section3@kyafood.com', '+94-123-456-793']
        ];
        
        foreach ($managers as $manager) {
            $stmt->execute($manager);
        }
        
        // Sample inventory data
        $sampleInventory = [
            [1, 'RM001', 'Fresh Mangoes', 'Fruits', 'Tropical Fruits', 'Premium quality mangoes for dehydration', 500.000, 'kg', 2.50, 100.000, 1000.000, 200.000, '2024-09-15', '2024-08-01', 'BATCH001', 1, 'Warehouse A1', 15.0, 65.0, 'A', 'active', 'normal'],
            [1, 'RM002', 'Fresh Pineapples', 'Fruits', 'Tropical Fruits', 'Sweet pineapples for processing', 300.000, 'kg', 1.80, 50.000, 800.000, 150.000, '2024-09-10', '2024-07-28', 'BATCH002', 1, 'Warehouse A2', 12.0, 70.0, 'A', 'active', 'normal'],
            [1, 'RM003', 'Coconut', 'Fruits', 'Tree Nuts', 'Fresh coconuts for desiccated coconut production', 20.000, 'pieces', 0.75, 20.000, 500.000, 100.000, '2024-10-01', '2024-08-05', 'BATCH003', 1, 'Warehouse A3', 18.0, 60.0, 'A', 'active', 'low_stock'],
            [2, 'PR001', 'Dehydrated Mango Slices', 'Processed Fruits', 'Dehydrated', 'Premium dehydrated mango slices', 80.000, 'kg', 12.50, 10.000, 200.000, 30.000, '2025-08-01', '2024-08-10', 'BATCH101', 1, 'Processing Room B1', -5.0, 15.0, 'A', 'active', 'normal'],
            [2, 'PR002', 'Dried Pineapple Rings', 'Processed Fruits', 'Dehydrated', 'Sweet dried pineapple rings', 60.000, 'kg', 15.00, 8.000, 150.000, 25.000, '2025-07-15', '2024-08-08', 'BATCH102', 1, 'Processing Room B2', -5.0, 15.0, 'A', 'active', 'normal'],
            [3, 'PK001', 'Packaged Mango Slices 250g', 'Packaged Products', 'Retail Pack', 'Consumer ready mango slices', 400.000, 'packs', 5.50, 50.000, 1000.000, 150.000, '2025-08-01', '2024-08-15', 'BATCH201', 1, 'Packaging Area C1', 10.0, 25.0, 'A', 'active', 'normal']
        ];
        
        $stmt = $conn->prepare("INSERT IGNORE INTO inventory (section, item_code, item_name, category, subcategory, description, quantity, unit, unit_cost, min_threshold, max_threshold, reorder_level, expiry_date, manufacture_date, batch_number, created_by, storage_location, storage_temperature, storage_humidity, quality_grade, status, alert_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($sampleInventory as $item) {
            $stmt->execute($item);
        }
        
        // Sample notifications
        $sampleNotifications = [
            ['LOW_STOCK_001', null, 1, 'high', 'inventory_alert', 'Stock Alert', 'Low Stock Alert: Coconut', 'Item "Coconut" is running low. Current quantity: 20.0 pieces. Minimum threshold: 20.0 pieces', true, 'modules/inventory/view_item.php?id=3', '{"item_id": 3, "current_quantity": 20.0, "threshold": 20.0}'],
            ['SYSTEM_001', 1, null, 'medium', 'system_alert', 'System', 'Database Setup Completed', 'Database has been successfully initialized with sample data', false, null, '{"setup_time": "' . date('Y-m-d H:i:s') . '"}']
        ];
        
        $stmt = $conn->prepare("INSERT IGNORE INTO notifications (notification_code, user_id, section, priority, type, category, title, message, action_required, action_url, data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($sampleNotifications as $notification) {
            $stmt->execute($notification);
        }
        
        $setupComplete = true;
        $successMessage = "Database setup completed successfully!";
        
    } catch (Exception $e) {
        $errorMessage = "Setup failed: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup - KYA Food Production</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #2c5f41 0%, #4a8b3a 100%); min-height: 100vh; }
        .setup-container { background: white; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .logo { max-height: 80px; }
    </style>
</head>
<body class="d-flex align-items-center">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="setup-container p-5">
                    <div class="text-center mb-4">
                        <i class="fas fa-database fa-3x text-primary mb-3"></i>
                        <h2 class="fw-bold">KYA Food Production</h2>
                        <p class="text-muted">Database Setup</p>
                    </div>
                    
                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php echo htmlspecialchars($errorMessage); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($successMessage): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <?php echo htmlspecialchars($successMessage); ?>
                        </div>
                        
                        <div class="card mt-4">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0"><i class="fas fa-key me-2"></i>Default Login Credentials</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="fw-bold">Administrator</h6>
                                        <p class="mb-1"><strong>Username:</strong> admin</p>
                                        <p class="mb-3"><strong>Password:</strong> admin123</p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="fw-bold">Section Managers</h6>
                                        <p class="mb-1"><strong>Section 1:</strong> section1_mgr / section1123</p>
                                        <p class="mb-1"><strong>Section 2:</strong> section2_mgr / section2123</p>
                                        <p class="mb-1"><strong>Section 3:</strong> section3_mgr / section3123</p>
                                    </div>
                                </div>
                                <div class="alert alert-warning mt-3">
                                    <i class="fas fa-shield-alt me-2"></i>
                                    <strong>Security Notice:</strong> Please change these default passwords immediately after first login!
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-center mt-4">
                            <a href="../index.php" class="btn btn-primary btn-lg">
                                <i class="fas fa-sign-in-alt me-2"></i>Go to Login Page
                            </a>
                        </div>
                    <?php else: ?>
                        <form method="POST">
                            <div class="card">
                                <div class="card-body">
                                    <h5 class="card-title"><i class="fas fa-cog me-2"></i>Initialize Database</h5>
                                    <p class="card-text">This will create the database structure and insert sample data for the KYA Food Production Management System.</p>
                                    
                                    <div class="alert alert-info">
                                        <h6><i class="fas fa-info-circle me-2"></i>What will be created:</h6>
                                        <ul class="mb-0">
                                            <li>Database: <code>kya_food_production</code></li>
                                            <li>All required tables with proper relationships</li>
                                            <li>Default user accounts (admin and section managers)</li>
                                            <li>Sample inventory data</li>
                                            <li>System settings and configurations</li>
                                        </ul>
                                    </div>
                                    
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        <strong>Requirements:</strong> Make sure XAMPP/MySQL is running before proceeding.
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-center mt-4">
                                <button type="submit" name="setup_database" class="btn btn-success btn-lg">
                                    <i class="fas fa-play me-2"></i>Setup Database
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
