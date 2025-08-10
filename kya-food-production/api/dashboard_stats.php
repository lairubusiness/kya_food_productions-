<?php
/**
 * KYA Food Production - Dashboard Statistics API
 * Provides real-time statistics for dashboard widgets
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/functions.php';

// Start session and check authentication
SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userInfo = SessionManager::getUserInfo();
$action = $_GET['action'] ?? 'get_stats';
$chart = $_GET['chart'] ?? '';

try {
    $db = new Database();
    $conn = $db->connect();
    
    switch ($action) {
        case 'get_stats':
            getDashboardStats($conn, $userInfo);
            break;
            
        case 'get_chart_data':
            getChartData($conn, $chart, $userInfo);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log("Dashboard Stats API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}

function getDashboardStats($conn, $userInfo) {
    $stats = [];
    
    // Get user's accessible sections
    $userSections = $userInfo['sections'];
    $isAdmin = $userInfo['role'] === 'admin';
    
    // Inventory statistics
    if ($isAdmin || !empty($userSections)) {
        $sectionFilter = $isAdmin ? '' : 'WHERE section IN (' . implode(',', array_map('intval', $userSections)) . ')';
        
        $stmt = $conn->prepare("
            SELECT 
                section,
                COUNT(*) as total_items,
                SUM(quantity * COALESCE(unit_cost, 0)) as total_value,
                COUNT(CASE WHEN alert_status IN ('low_stock', 'critical') THEN 1 END) as low_stock_items,
                COUNT(CASE WHEN DATEDIFF(expiry_date, CURDATE()) <= 7 AND status = 'active' THEN 1 END) as expiring_items,
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active_items,
                COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_items
            FROM inventory 
            $sectionFilter
            GROUP BY section
            ORDER BY section
        ");
        $stmt->execute();
        $inventoryStats = $stmt->fetchAll();
        
        $stats['inventory_by_section'] = $inventoryStats;
        
        // Overall inventory summary
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_items,
                SUM(quantity * COALESCE(unit_cost, 0)) as total_value,
                COUNT(CASE WHEN alert_status IN ('low_stock', 'critical') THEN 1 END) as low_stock_items,
                COUNT(CASE WHEN DATEDIFF(expiry_date, CURDATE()) <= 7 AND status = 'active' THEN 1 END) as expiring_items,
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active_items
            FROM inventory 
            $sectionFilter
        ");
        $stmt->execute();
        $stats['inventory_summary'] = $stmt->fetch();
    }
    
    // Notification statistics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_notifications,
            COUNT(CASE WHEN is_read = FALSE THEN 1 END) as unread_notifications,
            COUNT(CASE WHEN priority = 'critical' AND is_read = FALSE THEN 1 END) as critical_notifications,
            COUNT(CASE WHEN priority = 'high' AND is_read = FALSE THEN 1 END) as high_priority_notifications
        FROM notifications
        WHERE (user_id = ? OR user_id IS NULL)
        AND is_archived = FALSE
        AND (expires_at IS NULL OR expires_at > NOW())
    ");
    $stmt->execute([$userInfo['id']]);
    $stats['notifications'] = $stmt->fetch();
    
    // Processing statistics (last 30 days)
    if ($isAdmin || !empty($userSections)) {
        $sectionFilter = $isAdmin ? '' : 'WHERE section IN (' . implode(',', array_map('intval', $userSections)) . ')';
        $whereClause = $sectionFilter ? $sectionFilter . ' AND' : 'WHERE';
        
        $stmt = $conn->prepare("
            SELECT 
                section,
                COUNT(*) as total_processes,
                AVG(yield_percentage) as avg_yield,
                SUM(input_quantity) as total_input,
                SUM(output_quantity) as total_output,
                SUM(waste_quantity) as total_waste
            FROM processing_logs 
            $whereClause created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY section
            ORDER BY section
        ");
        $stmt->execute();
        $stats['processing'] = $stmt->fetchAll();
    }
    
    // Order statistics (admin only)
    if ($isAdmin) {
        $stmt = $conn->prepare("
            SELECT 
                status,
                COUNT(*) as count,
                SUM(total_amount) as total_value
            FROM orders 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY status
            ORDER BY 
                CASE status
                    WHEN 'pending' THEN 1
                    WHEN 'processing' THEN 2
                    WHEN 'quality_check' THEN 3
                    WHEN 'packaging' THEN 4
                    WHEN 'ready_to_ship' THEN 5
                    WHEN 'shipped' THEN 6
                    WHEN 'delivered' THEN 7
                    WHEN 'cancelled' THEN 8
                END
        ");
        $stmt->execute();
        $stats['orders'] = $stmt->fetchAll();
        
        // Order summary
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_orders,
                SUM(total_amount) as total_value,
                COUNT(CASE WHEN status IN ('pending', 'processing') THEN 1 END) as active_orders,
                COUNT(CASE WHEN status = 'delivered' THEN 1 END) as completed_orders
            FROM orders 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute();
        $stats['order_summary'] = $stmt->fetch();
    }
    
    // Recent activity
    $stmt = $conn->prepare("
        SELECT action, created_at, COUNT(*) as count
        FROM activity_logs 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY action, DATE(created_at)
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $stats['recent_activity'] = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'generated_at' => date('Y-m-d H:i:s')
    ]);
}

function getChartData($conn, $chart, $userInfo) {
    $userSections = $userInfo['sections'];
    $isAdmin = $userInfo['role'] === 'admin';
    
    switch ($chart) {
        case 'inventory':
            getInventoryChartData($conn, $userSections, $isAdmin);
            break;
            
        case 'processing':
            getProcessingChartData($conn, $userSections, $isAdmin);
            break;
            
        case 'orders':
            getOrdersChartData($conn, $isAdmin);
            break;
            
        case 'alerts':
            getAlertsChartData($conn, $userSections, $isAdmin);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid chart type']);
    }
}

function getInventoryChartData($conn, $userSections, $isAdmin) {
    $sectionFilter = $isAdmin ? '' : 'WHERE section IN (' . implode(',', array_map('intval', $userSections)) . ')';
    
    $stmt = $conn->prepare("
        SELECT 
            CASE section
                WHEN 1 THEN 'Raw Materials'
                WHEN 2 THEN 'Processing'
                WHEN 3 THEN 'Packaging'
                ELSE 'Other'
            END as section_name,
            COUNT(*) as item_count,
            SUM(quantity * COALESCE(unit_cost, 0)) as total_value
        FROM inventory 
        $sectionFilter
        AND status = 'active'
        GROUP BY section
        ORDER BY section
    ");
    $stmt->execute();
    $data = $stmt->fetchAll();
    
    $labels = [];
    $values = [];
    $colors = ['#2c5f41', '#4a8b3a', '#ff6b35', '#17a2b8', '#ffc107'];
    
    foreach ($data as $index => $row) {
        $labels[] = $row['section_name'];
        $values[] = (float)$row['total_value'];
    }
    
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'values' => $values,
        'colors' => array_slice($colors, 0, count($labels))
    ]);
}

function getProcessingChartData($conn, $userSections, $isAdmin) {
    $sectionFilter = $isAdmin ? '' : 'WHERE section IN (' . implode(',', array_map('intval', $userSections)) . ')';
    $whereClause = $sectionFilter ? $sectionFilter . ' AND' : 'WHERE';
    
    $stmt = $conn->prepare("
        SELECT 
            DATE(created_at) as process_date,
            SUM(output_quantity) as daily_output,
            AVG(yield_percentage) as avg_yield
        FROM processing_logs 
        $whereClause created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY process_date
    ");
    $stmt->execute();
    $data = $stmt->fetchAll();
    
    $labels = [];
    $values = [];
    
    foreach ($data as $row) {
        $labels[] = date('M j', strtotime($row['process_date']));
        $values[] = (float)$row['daily_output'];
    }
    
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'values' => $values
    ]);
}

function getOrdersChartData($conn, $isAdmin) {
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        return;
    }
    
    $stmt = $conn->prepare("
        SELECT 
            DATE(created_at) as order_date,
            COUNT(*) as order_count,
            SUM(total_amount) as daily_value
        FROM orders 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY order_date
    ");
    $stmt->execute();
    $data = $stmt->fetchAll();
    
    $labels = [];
    $values = [];
    
    foreach ($data as $row) {
        $labels[] = date('M j', strtotime($row['order_date']));
        $values[] = (int)$row['order_count'];
    }
    
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'values' => $values
    ]);
}

function getAlertsChartData($conn, $userSections, $isAdmin) {
    $sectionFilter = $isAdmin ? '' : 'WHERE section IN (' . implode(',', array_map('intval', $userSections)) . ')';
    
    $stmt = $conn->prepare("
        SELECT 
            alert_status,
            COUNT(*) as count
        FROM inventory 
        $sectionFilter
        AND status = 'active'
        AND alert_status != 'normal'
        GROUP BY alert_status
    ");
    $stmt->execute();
    $data = $stmt->fetchAll();
    
    $labels = [];
    $values = [];
    $colors = [
        'low_stock' => '#ffc107',
        'critical' => '#dc3545',
        'expired' => '#6c757d',
        'expiring_soon' => '#fd7e14'
    ];
    
    $chartColors = [];
    foreach ($data as $row) {
        $labels[] = ucfirst(str_replace('_', ' ', $row['alert_status']));
        $values[] = (int)$row['count'];
        $chartColors[] = $colors[$row['alert_status']] ?? '#6c757d';
    }
    
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'values' => $values,
        'colors' => $chartColors
    ]);
}
?>
