<?php
/**
 * KYA Food Production - Common Functions
 * Shared utility functions across the system
 */

if (!defined('SITE_NAME')) {
    require_once __DIR__ . '/../config/constants.php';
}

/**
 * Sanitize input data
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email address
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Generate unique code
 */
function generateUniqueCode($prefix = '', $length = 8) {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $code = $prefix;
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

/**
 * Format currency
 */
function formatCurrency($amount, $currency = 'USD') {
    switch ($currency) {
        case 'USD':
            return '$' . number_format($amount, 2);
        case 'EUR':
            return '€' . number_format($amount, 2);
        case 'LKR':
            return 'Rs. ' . number_format($amount, 2);
        default:
            return $currency . ' ' . number_format($amount, 2);
    }
}

/**
 * Format date for display
 */
function formatDate($date, $format = DISPLAY_DATE_FORMAT) {
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '-';
    }
    return date($format, strtotime($date));
}

/**
 * Format datetime for display
 */
function formatDateTime($datetime, $format = DISPLAY_DATETIME_FORMAT) {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }
    return date($format, strtotime($datetime));
}

/**
 * Get time ago string
 */
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    if ($time < 31536000) return floor($time/2592000) . ' months ago';
    
    return floor($time/31536000) . ' years ago';
}

/**
 * Get status badge HTML
 */
function getStatusBadge($status, $statusConfig) {
    if (!isset($statusConfig[$status])) {
        return '<span class="badge bg-secondary">' . ucfirst($status) . '</span>';
    }
    
    $config = $statusConfig[$status];
    return '<span class="badge bg-' . $config['color'] . '">' . $config['name'] . '</span>';
}

/**
 * Get priority badge HTML
 */
function getPriorityBadge($priority) {
    $colors = [
        'low' => 'success',
        'medium' => 'info',
        'high' => 'warning',
        'urgent' => 'danger',
        'critical' => 'danger'
    ];
    
    $color = $colors[$priority] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . strtoupper($priority) . '</span>';
}

/**
 * Calculate percentage
 */
function calculatePercentage($value, $total) {
    if ($total == 0) return 0;
    return round(($value / $total) * 100, 2);
}

/**
 * Format bytes into human readable format
 */
function formatBytes($bytes, $precision = 2) {
    if ($bytes == 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $base = log($bytes, 1024);
    $index = floor($base);
    
    return round(pow(1024, $base - $index), $precision) . ' ' . $units[$index];
}

/**
 * Get section color
 */
function getSectionColor($section) {
    $colors = [
        1 => '#2c5f41',
        2 => '#4a8b3a',
        3 => '#ff6b35'
    ];
    return $colors[$section] ?? '#6c757d';
}

/**
 * Get section name
 */
function getSectionName($section) {
    return SECTIONS[$section]['name'] ?? 'Unknown Section';
}

/**
 * Check if file upload is valid
 */
function validateFileUpload($file, $allowedTypes = null) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'message' => 'File upload error'];
    }
    
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['valid' => false, 'message' => 'File size exceeds maximum limit'];
    }
    
    if ($allowedTypes) {
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExtension, $allowedTypes)) {
            return ['valid' => false, 'message' => 'File type not allowed'];
        }
    }
    
    return ['valid' => true, 'message' => 'File is valid'];
}

/**
 * Upload file
 */
function uploadFile($file, $destination, $newName = null) {
    $validation = validateFileUpload($file);
    if (!$validation['valid']) {
        return ['success' => false, 'message' => $validation['message']];
    }
    
    if (!is_dir(dirname($destination))) {
        mkdir(dirname($destination), 0755, true);
    }
    
    $fileName = $newName ?: $file['name'];
    $filePath = $destination . '/' . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        return ['success' => true, 'path' => $filePath, 'filename' => $fileName];
    }
    
    return ['success' => false, 'message' => 'Failed to move uploaded file'];
}

/**
 * Generate random password
 */
function generateRandomPassword($length = 12) {
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $password;
}

/**
 * Send email notification
 */
function sendEmailNotification($to, $subject, $message, $isHTML = true) {
    // This is a placeholder for email functionality
    // In production, implement with PHPMailer or similar
    
    $headers = [
        'From: ' . COMPANY_NAME . ' <' . (defined('SMTP_USERNAME') ? SMTP_USERNAME : 'noreply@kyafood.com') . '>',
        'Reply-To: ' . (defined('SMTP_USERNAME') ? SMTP_USERNAME : 'noreply@kyafood.com'),
        'X-Mailer: PHP/' . phpversion()
    ];
    
    if ($isHTML) {
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-type: text/html; charset=UTF-8';
    }
    
    // For development, log email instead of sending
    error_log("EMAIL TO: $to, SUBJECT: $subject, MESSAGE: $message");
    
    return true; // Return true for now
}

/**
 * Log activity
 */
function logActivity($action, $details = null, $userId = null) {
    try {
        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/../config/session.php';
        
        if (!$userId && class_exists('SessionManager')) {
            $userInfo = SessionManager::getUserInfo();
            $userId = $userInfo['id'] ?? null;
        }
        
        $db = new Database();
        $conn = $db->connect();
        
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (user_id, action, ip_address, user_agent, session_id, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $userId,
            $action . ($details ? ': ' . $details : ''),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            session_id()
        ]);
        
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
    }
}

/**
 * Create notification
 */
function createNotification($userId, $section, $priority, $type, $title, $message, $actionRequired = false, $actionUrl = null, $data = null) {
    try {
        require_once __DIR__ . '/../config/database.php';
        
        $db = new Database();
        $conn = $db->connect();
        
        $notificationCode = strtoupper($type) . '_' . time() . '_' . rand(1000, 9999);
        
        $stmt = $conn->prepare("
            INSERT INTO notifications (notification_code, user_id, section, priority, type, title, message, action_required, action_url, data)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $notificationCode,
            $userId,
            $section,
            $priority,
            $type,
            $title,
            $message,
            $actionRequired,
            $actionUrl,
            $data ? json_encode($data) : null
        ]);
        
        return $conn->lastInsertId();
        
    } catch (Exception $e) {
        error_log("Failed to create notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Check inventory alerts
 */
function checkInventoryAlerts($itemId = null) {
    try {
        require_once __DIR__ . '/../config/database.php';
        
        $db = new Database();
        $conn = $db->connect();
        
        $whereClause = $itemId ? "WHERE id = ?" : "WHERE status = 'active'";
        $params = $itemId ? [$itemId] : [];
        
        $stmt = $conn->prepare("
            SELECT id, item_name, quantity, unit, min_threshold, expiry_date, section
            FROM inventory 
            $whereClause
        ");
        $stmt->execute($params);
        $items = $stmt->fetchAll();
        
        foreach ($items as $item) {
            // Check low stock
            if ($item['quantity'] <= $item['min_threshold']) {
                createNotification(
                    null, // Send to all users in section
                    $item['section'],
                    'high',
                    'inventory_alert',
                    'Low Stock Alert: ' . $item['item_name'],
                    "Item \"{$item['item_name']}\" is running low. Current quantity: {$item['quantity']} {$item['unit']}. Minimum threshold: {$item['min_threshold']} {$item['unit']}",
                    true,
                    "modules/inventory/view_item.php?id={$item['id']}",
                    ['item_id' => $item['id'], 'current_quantity' => $item['quantity'], 'threshold' => $item['min_threshold']]
                );
            }
            
            // Check expiry
            if ($item['expiry_date']) {
                $daysToExpiry = (strtotime($item['expiry_date']) - time()) / (24 * 60 * 60);
                if ($daysToExpiry <= CRITICAL_EXPIRY_DAYS && $daysToExpiry > 0) {
                    createNotification(
                        null,
                        $item['section'],
                        'critical',
                        'expiry_warning',
                        'Expiry Alert: ' . $item['item_name'],
                        "Item \"{$item['item_name']}\" will expire on {$item['expiry_date']}. Please take immediate action.",
                        true,
                        "modules/inventory/view_item.php?id={$item['id']}",
                        ['item_id' => $item['id'], 'expiry_date' => $item['expiry_date'], 'days_remaining' => floor($daysToExpiry)]
                    );
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Failed to check inventory alerts: " . $e->getMessage());
    }
}

/**
 * Get dashboard statistics
 */
function getDashboardStats($userRole = null, $userSections = []) {
    try {
        require_once __DIR__ . '/../config/database.php';
        
        $db = new Database();
        $conn = $db->connect();
        
        $stats = [];
        
        // Inventory stats
        if ($userRole === 'admin' || !empty($userSections)) {
            $sectionFilter = $userRole === 'admin' ? '' : 'WHERE section IN (' . implode(',', $userSections) . ')';
            
            $stmt = $conn->prepare("
                SELECT 
                    section,
                    COUNT(*) as total_items,
                    SUM(quantity * unit_cost) as total_value,
                    COUNT(CASE WHEN alert_status IN ('low_stock', 'critical') THEN 1 END) as low_stock_items,
                    COUNT(CASE WHEN DATEDIFF(expiry_date, CURDATE()) <= 7 AND status = 'active' THEN 1 END) as expiring_items
                FROM inventory 
                $sectionFilter
                GROUP BY section
            ");
            $stmt->execute();
            $stats['inventory'] = $stmt->fetchAll();
        }
        
        // Notification stats
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_notifications,
                COUNT(CASE WHEN is_read = FALSE THEN 1 END) as unread_notifications,
                COUNT(CASE WHEN priority = 'critical' AND is_read = FALSE THEN 1 END) as critical_notifications
            FROM notifications
        ");
        $stmt->execute();
        $stats['notifications'] = $stmt->fetch();
        
        // Order stats (admin only)
        if ($userRole === 'admin') {
            $stmt = $conn->prepare("
                SELECT 
                    status,
                    COUNT(*) as count,
                    SUM(total_amount) as total_value
                FROM orders 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY status
            ");
            $stmt->execute();
            $stats['orders'] = $stmt->fetchAll();
        }
        
        return $stats;
        
    } catch (Exception $e) {
        error_log("Failed to get dashboard stats: " . $e->getMessage());
        return [];
    }
}

/**
 * Export data to CSV
 */
function exportToCSV($data, $filename, $headers = null) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    if ($headers) {
        fputcsv($output, $headers);
    } elseif (!empty($data)) {
        fputcsv($output, array_keys($data[0]));
    }
    
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}

/**
 * Backup database
 */
function backupDatabase() {
    try {
        $backupDir = __DIR__ . '/../backups/';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $filename = 'kya_food_production_backup_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = $backupDir . $filename;
        
        // This is a simplified backup - in production, use mysqldump
        $command = "mysqldump -h localhost -u root kya_food_production > $filepath";
        exec($command, $output, $returnVar);
        
        if ($returnVar === 0) {
            return ['success' => true, 'filename' => $filename, 'path' => $filepath];
        } else {
            return ['success' => false, 'message' => 'Backup command failed'];
        }
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
?>
