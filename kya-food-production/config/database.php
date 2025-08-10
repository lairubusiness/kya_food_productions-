<?php
/**
 * KYA Food Production - Enhanced Database Configuration
 * Handles database connections and setup
 */

class Database {
    private $host = "localhost";
    private $username = "root";
    private $password = "";
    private $database = "kya_food_production";
    private $connection;
    
    public function connect() {
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->database . ";charset=utf8mb4";
            $this->connection = new PDO($dsn, $this->username, $this->password);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            return $this->connection;
        } catch(PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Connection failed. Please try again later.");
        }
    }
    
    public function getConnection() {
        return $this->connection;
    }
}

// Database creation and table setup
function setupDatabase() {
    $db = new Database();
    $conn = $db->connect();
    
    // Create database if not exists
    $sql = "CREATE DATABASE IF NOT EXISTS kya_food_production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    $conn->exec($sql);
    $conn->exec("USE kya_food_production");
    
    // Users table with enhanced security
    $sql = "CREATE TABLE IF NOT EXISTS users (
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
    ) ENGINE=InnoDB";
    $conn->exec($sql);
    
    // Enhanced inventory table
    $sql = "CREATE TABLE IF NOT EXISTS inventory (
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
    ) ENGINE=InnoDB";
    $conn->exec($sql);
    
    // Processing logs table
    $sql = "CREATE TABLE IF NOT EXISTS processing_logs (
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
    ) ENGINE=InnoDB";
    $conn->exec($sql);
    
    // Enhanced notifications table
    $sql = "CREATE TABLE IF NOT EXISTS notifications (
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
    ) ENGINE=InnoDB";
    $conn->exec($sql);
    
    // Orders table
    $sql = "CREATE TABLE IF NOT EXISTS orders (
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
    ) ENGINE=InnoDB";
    $conn->exec($sql);
    
    // Order items table
    $sql = "CREATE TABLE IF NOT EXISTS order_items (
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
    ) ENGINE=InnoDB";
    $conn->exec($sql);
    
    // System settings table
    $sql = "CREATE TABLE IF NOT EXISTS system_settings (
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
    ) ENGINE=InnoDB";
    $conn->exec($sql);
    
    // Activity logs table
    $sql = "CREATE TABLE IF NOT EXISTS activity_logs (
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
    ) ENGINE=InnoDB";
    $conn->exec($sql);
    
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
    
    // Create triggers for automatic notifications
    $conn->exec("
        DELIMITER $$
        CREATE TRIGGER IF NOT EXISTS inventory_alert_trigger 
        AFTER UPDATE ON inventory
        FOR EACH ROW
        BEGIN
            -- Low stock alert
            IF NEW.quantity <= NEW.min_threshold AND OLD.quantity > OLD.min_threshold THEN
                INSERT INTO notifications (notification_code, section, type, priority, title, message, action_required, data)
                VALUES (
                    CONCAT('LOW_STOCK_', NEW.id, '_', UNIX_TIMESTAMP()),
                    NEW.section,
                    'inventory_alert',
                    'high',
                    CONCAT('Low Stock Alert: ', NEW.item_name),
                    CONCAT('Item \"', NEW.item_name, '\" is running low. Current quantity: ', NEW.quantity, ' ', NEW.unit, '. Minimum threshold: ', NEW.min_threshold, ' ', NEW.unit),
                    TRUE,
                    JSON_OBJECT('item_id', NEW.id, 'current_quantity', NEW.quantity, 'threshold', NEW.min_threshold)
                );
            END IF;
            
            -- Expiry alert
            IF NEW.expiry_date IS NOT NULL AND DATEDIFF(NEW.expiry_date, CURDATE()) <= 7 AND NEW.status = 'active' THEN
                INSERT INTO notifications (notification_code, section, type, priority, title, message, action_required, data)
                VALUES (
                    CONCAT('EXPIRY_', NEW.id, '_', UNIX_TIMESTAMP()),
                    NEW.section,
                    'expiry_warning',
                    'critical',
                    CONCAT('Expiry Alert: ', NEW.item_name),
                    CONCAT('Item \"', NEW.item_name, '\" will expire on ', NEW.expiry_date, '. Please take immediate action.'),
                    TRUE,
                    JSON_OBJECT('item_id', NEW.id, 'expiry_date', NEW.expiry_date, 'days_remaining', DATEDIFF(NEW.expiry_date, CURDATE()))
                );
            END IF;
        END$$
        DELIMITER ;
    ");
    
    // Create default admin user
    $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT IGNORE INTO users (username, password, role, full_name, email, phone) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute(['admin', $adminPassword, 'admin', 'System Administrator', 'admin@kyafood.com', '+94-123-456-789']);
    
    // Create sample section managers
    $managers = [
        ['section1_mgr', password_hash('section1123', PASSWORD_DEFAULT), 'section1_manager', 'Section 1 Manager', 'section1@kyafood.com', '+94-123-456-791'],
        ['section2_mgr', password_hash('section2123', PASSWORD_DEFAULT), 'section2_manager', 'Section 2 Manager', 'section2@kyafood.com', '+94-123-456-792'],
        ['section3_mgr', password_hash('section3123', PASSWORD_DEFAULT), 'section3_manager', 'Section 3 Manager', 'section3@kyafood.com', '+94-123-456-793']
    ];
    
    foreach ($managers as $manager) {
        $stmt->execute($manager);
    }
    
    echo "Database setup completed successfully!\n";
    echo "Default Login Credentials:\n";
    echo "Admin: username='admin', password='admin123'\n";
    echo "Section Managers: username='section1_mgr', password='section1123' (and similarly for section2_mgr, section3_mgr)\n";
}

// Uncomment to run database setup
// setupDatabase();
?>
