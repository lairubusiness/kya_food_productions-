-- KYA Food Production Management System Database
-- Version: 1.0.0
-- Created: 2025-01-04

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Create database
CREATE DATABASE IF NOT EXISTS `kya_food_production` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `kya_food_production`;

-- Users table
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','section1_manager','section2_manager','section3_manager') NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `last_login` timestamp NULL DEFAULT NULL,
  `login_attempts` int(11) DEFAULT 0,
  `account_locked` tinyint(1) DEFAULT 0,
  `password_reset_token` varchar(255) DEFAULT NULL,
  `password_reset_expires` timestamp NULL DEFAULT NULL,
  `two_factor_enabled` tinyint(1) DEFAULT 0,
  `two_factor_secret` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventory table
CREATE TABLE `inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section` int(11) NOT NULL CHECK (`section` in (1,2,3)),
  `item_code` varchar(50) NOT NULL,
  `item_name` varchar(100) NOT NULL,
  `category` varchar(50) NOT NULL,
  `subcategory` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT 0.000,
  `unit` varchar(20) NOT NULL,
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `total_value` decimal(12,2) GENERATED ALWAYS AS (`quantity` * `unit_cost`) STORED,
  `min_threshold` decimal(10,3) NOT NULL,
  `max_threshold` decimal(10,3) NOT NULL,
  `reorder_level` decimal(10,3) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `manufacture_date` date DEFAULT NULL,
  `batch_number` varchar(50) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `storage_location` varchar(100) DEFAULT NULL,
  `storage_temperature` decimal(5,2) DEFAULT NULL,
  `storage_humidity` decimal(5,2) DEFAULT NULL,
  `quality_grade` enum('A','B','C','D') DEFAULT 'A',
  `status` enum('active','inactive','expired','damaged','recalled') DEFAULT 'active',
  `alert_status` enum('normal','low_stock','critical','expired','expiring_soon') DEFAULT 'normal',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_code` (`item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Processing logs table
CREATE TABLE `processing_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `section` int(11) NOT NULL,
  `batch_id` varchar(50) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `process_type` enum('drying','dehydration','packaging','quality_check','storage') NOT NULL,
  `process_stage` varchar(50) DEFAULT NULL,
  `input_quantity` decimal(10,3) NOT NULL,
  `output_quantity` decimal(10,3) DEFAULT NULL,
  `waste_quantity` decimal(10,3) DEFAULT 0.000,
  `yield_percentage` decimal(5,2) DEFAULT NULL,
  `start_time` timestamp NULL DEFAULT NULL,
  `end_time` timestamp NULL DEFAULT NULL,
  `duration_minutes` int(11) DEFAULT NULL,
  `temperature_start` decimal(5,2) DEFAULT NULL,
  `temperature_end` decimal(5,2) DEFAULT NULL,
  `humidity_start` decimal(5,2) DEFAULT NULL,
  `humidity_end` decimal(5,2) DEFAULT NULL,
  `quality_grade_input` enum('A','B','C','D') DEFAULT NULL,
  `quality_grade_output` enum('A','B','C','D') DEFAULT NULL,
  `equipment_used` varchar(100) DEFAULT NULL,
  `operator_id` int(11) DEFAULT NULL,
  `supervisor_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `quality_checks` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`quality_checks`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications table
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `notification_code` varchar(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `section` int(11) DEFAULT NULL,
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `type` enum('inventory_alert','expiry_warning','quality_issue','system_alert','process_complete','user_action') NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `action_required` tinyint(1) DEFAULT 0,
  `action_url` varchar(255) DEFAULT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `is_read` tinyint(1) DEFAULT 0,
  `is_archived` tinyint(1) DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `notification_code` (`notification_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Orders table
CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_number` varchar(50) NOT NULL,
  `customer_name` varchar(100) NOT NULL,
  `customer_email` varchar(100) DEFAULT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `customer_address` text DEFAULT NULL,
  `export_country` varchar(50) DEFAULT NULL,
  `order_date` date NOT NULL,
  `required_date` date DEFAULT NULL,
  `status` enum('pending','processing','quality_check','packaging','ready_to_ship','shipped','delivered','cancelled') DEFAULT 'pending',
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `total_amount` decimal(12,2) DEFAULT 0.00,
  `currency` varchar(3) DEFAULT 'USD',
  `payment_status` enum('pending','partial','paid','refunded') DEFAULT 'pending',
  `payment_method` varchar(50) DEFAULT NULL,
  `shipping_method` varchar(50) DEFAULT NULL,
  `tracking_number` varchar(100) DEFAULT NULL,
  `compliance_documents` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`compliance_documents`)),
  `special_instructions` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order items table
CREATE TABLE `order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(12,2) GENERATED ALWAYS AS (`quantity` * `unit_price`) STORED,
  `quality_requirements` text DEFAULT NULL,
  `packaging_requirements` text DEFAULT NULL,
  `allocated_quantity` decimal(10,3) DEFAULT 0.000,
  `fulfilled_quantity` decimal(10,3) DEFAULT 0.000,
  `status` enum('pending','allocated','processed','packaged','fulfilled') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System settings table
CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('string','number','boolean','json') DEFAULT 'string',
  `category` varchar(50) DEFAULT 'general',
  `description` text DEFAULT NULL,
  `is_editable` tinyint(1) DEFAULT 1,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity logs table
CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `session_id` varchar(128) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default users (password: admin123 for all)
INSERT INTO `users` (`username`, `password`, `role`, `full_name`, `email`, `phone`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'System Administrator', 'admin@kyafood.com', '+94-123-456-789'),
('section1_mgr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'section1_manager', 'Raw Materials Manager', 'section1@kyafood.com', '+94-123-456-791'),
('section2_mgr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'section2_manager', 'Processing Manager', 'section2@kyafood.com', '+94-123-456-792'),
('section3_mgr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'section3_manager', 'Packaging Manager', 'section3@kyafood.com', '+94-123-456-793');

-- Insert system settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `category`, `description`) VALUES
('company_name', 'KYA Food Production', 'string', 'company', 'Company name'),
('company_email', 'info@kyafood.com', 'string', 'company', 'Company email'),
('company_phone', '+94-123-456-789', 'string', 'company', 'Company phone'),
('low_stock_threshold', '10', 'number', 'inventory', 'Default low stock threshold percentage'),
('expiry_alert_days', '30', 'number', 'inventory', 'Days before expiry to send alerts'),
('auto_backup_enabled', 'true', 'boolean', 'system', 'Enable automatic database backups'),
('email_notifications', 'true', 'boolean', 'notifications', 'Enable email notifications'),
('session_timeout', '1800', 'number', 'security', 'Session timeout in seconds'),
('max_login_attempts', '5', 'number', 'security', 'Maximum login attempts before lockout');

-- Insert sample inventory data
INSERT INTO `inventory` (`section`, `item_code`, `item_name`, `category`, `description`, `quantity`, `unit`, `unit_cost`, `min_threshold`, `max_threshold`, `expiry_date`, `batch_number`, `storage_location`, `quality_grade`, `status`, `created_by`) VALUES
(1, 'RM001', 'Fresh Mangoes', 'Raw Materials', 'Premium quality fresh mangoes', 500.000, 'kg', 2.50, 50.000, 1000.000, '2025-01-15', 'MG2025001', 'Cold Storage A1', 'A', 'active', 1),
(1, 'RM002', 'Fresh Pineapples', 'Raw Materials', 'Sweet golden pineapples', 300.000, 'kg', 1.80, 30.000, 600.000, '2025-01-20', 'PA2025001', 'Cold Storage A2', 'A', 'active', 1),
(2, 'PR001', 'Dehydrated Mango Slices', 'Processed', 'Premium dehydrated mango slices', 150.000, 'kg', 8.50, 20.000, 300.000, '2025-12-31', 'DM2025001', 'Dry Storage B1', 'A', 'active', 2),
(3, 'PK001', 'Vacuum Sealed Mango Pack', 'Packaged', 'Consumer ready vacuum sealed packs', 500.000, 'pcs', 12.00, 50.000, 1000.000, '2026-01-31', 'VM2025001', 'Finished Goods C1', 'A', 'active', 3);

-- Insert sample orders
INSERT INTO `orders` (`order_number`, `customer_name`, `customer_email`, `order_date`, `required_date`, `status`, `total_amount`, `created_by`) VALUES
('ORD2025001', 'Global Foods Ltd', 'orders@globalfoods.com', '2025-01-01', '2025-01-15', 'processing', 15000.00, 1),
('ORD2025002', 'European Snacks GmbH', 'procurement@eurosnacks.de', '2025-01-02', '2025-01-20', 'pending', 8500.00, 1);

-- Insert sample notifications
INSERT INTO `notifications` (`notification_code`, `section`, `priority`, `type`, `title`, `message`, `action_required`) VALUES
('EXPIRY_001', 1, 'critical', 'expiry_warning', 'Items Expiring Soon', 'Some items in Section 1 are approaching expiry dates', 1),
('LOW_STOCK_001', 2, 'high', 'inventory_alert', 'Low Stock Alert', 'Dehydrated products running low in Section 2', 1);

COMMIT;
