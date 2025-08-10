<?php
/**
 * KYA Food Production - Main Dashboard
 * Central hub for all system operations
 */

require_once 'config/database.php';
require_once 'config/constants.php';
require_once 'config/session.php';

SessionManager::start();
SessionManager::requireLogin();

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

// Get dashboard statistics
try {
    // Total inventory items by section
    $inventoryStats = [];
    $sections = [1, 2, 3];
    
    foreach ($sections as $section) {
        if (SessionManager::canAccessSection($section)) {
            $stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as total_items,
                    SUM(quantity * unit_cost) as total_value,
                    COUNT(CASE WHEN alert_status IN ('low_stock', 'critical') THEN 1 END) as low_stock_items,
                    COUNT(CASE WHEN DATEDIFF(expiry_date, CURDATE()) <= 7 AND status = 'active' THEN 1 END) as expiring_items
                FROM inventory 
                WHERE section = ? AND status = 'active'
            ");
            $stmt->execute([$section]);
            $inventoryStats[$section] = $stmt->fetch();
        }
    }
    
    // Recent notifications
    $stmt = $conn->prepare("
        SELECT * FROM notifications 
        WHERE (user_id = ? OR user_id IS NULL) 
        AND is_read = FALSE 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$userInfo['id']]);
    $notifications = $stmt->fetchAll();
    
    // Recent orders (if admin or has access)
    $recentOrders = [];
    if ($userInfo['role'] === 'admin') {
        $stmt = $conn->prepare("
            SELECT o.*, u.full_name as created_by_name
            FROM orders o 
            LEFT JOIN users u ON o.created_by = u.id
            ORDER BY o.created_at DESC 
            LIMIT 5
        ");
        $stmt->execute();
        $recentOrders = $stmt->fetchAll();
    }
    
    // Processing logs for user's sections
    $recentProcessing = [];
    $userSections = $userInfo['sections'];
    if (!empty($userSections)) {
        $placeholders = str_repeat('?,', count($userSections) - 1) . '?';
        $stmt = $conn->prepare("
            SELECT pl.*, i.item_name, u.full_name as operator_name
            FROM processing_logs pl
            LEFT JOIN inventory i ON pl.item_id = i.id
            LEFT JOIN users u ON pl.operator_id = u.id
            WHERE pl.section IN ($placeholders)
            ORDER BY pl.created_at DESC
            LIMIT 5
        ");
        $stmt->execute($userSections);
        $recentProcessing = $stmt->fetchAll();
    }
    
} catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $inventoryStats = [];
    $notifications = [];
    $recentOrders = [];
    $recentProcessing = [];
}

$pageTitle = 'Dashboard';
include 'includes/header.php';
?>

<div class="content-area">
    <!-- Welcome Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1" style="color: #212529;">Welcome back, <?php echo htmlspecialchars($userInfo['full_name']); ?>!</h1>
                    <p class="text-muted mb-0">Here's what's happening in your food production system today.</p>
                </div>
                <div class="text-end">
                    <small class="text-muted">Last login: <?php echo date('M j, Y g:i A'); ?></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <?php foreach ($inventoryStats as $section => $stats): ?>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="stats-card <?php echo $section == 1 ? 'success' : ($section == 2 ? 'info' : 'warning'); ?>">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stats-icon <?php echo $section == 1 ? 'success' : ($section == 2 ? 'info' : 'warning'); ?>">
                                <i class="fas <?php echo $section == 1 ? 'fa-warehouse' : ($section == 2 ? 'fa-industry' : 'fa-box'); ?>"></i>
                            </div>
                            <div class="stats-number"><?php echo number_format($stats['total_items']); ?></div>
                            <div class="stats-label"><?php echo SECTIONS[$section]['name']; ?> Items</div>
                            <div class="stats-change">
                                <small class="text-muted">
                                    Value: $<?php echo number_format($stats['total_value'] ?? 0, 2); ?>
                                </small>
                            </div>
                        </div>
                        <div class="text-end">
                            <?php if ($stats['low_stock_items'] > 0): ?>
                                <span class="badge bg-danger"><?php echo $stats['low_stock_items']; ?> Low Stock</span>
                            <?php endif; ?>
                            <?php if ($stats['expiring_items'] > 0): ?>
                                <span class="badge bg-warning mt-1"><?php echo $stats['expiring_items']; ?> Expiring</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card danger">
                <div class="stats-icon danger">
                    <i class="fas fa-bell"></i>
                </div>
                <div class="stats-number"><?php echo count($notifications); ?></div>
                <div class="stats-label">Active Alerts</div>
                <div class="stats-change">
                    <small class="text-muted">Requires attention</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Row -->
    <div class="row">
        <!-- Recent Notifications -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-bell me-2"></i>Recent Notifications</h5>
                    <a href="modules/notifications/" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($notifications)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                            <p class="text-muted">No new notifications</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($notifications as $notification): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1">
                                                <span class="badge bg-<?php echo $notification['priority'] === 'critical' ? 'danger' : ($notification['priority'] === 'high' ? 'warning' : 'info'); ?> me-2">
                                                    <?php echo strtoupper($notification['priority']); ?>
                                                </span>
                                                <?php echo htmlspecialchars($notification['title']); ?>
                                            </h6>
                                            <p class="mb-1 small"><?php echo htmlspecialchars($notification['message']); ?></p>
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i>
                                                <?php echo date('M j, g:i A', strtotime($notification['created_at'])); ?>
                                            </small>
                                        </div>
                                        <?php if ($notification['action_required']): ?>
                                            <span class="badge bg-danger">Action Required</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Processing Activity -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-cogs me-2"></i>Recent Processing</h5>
                    <a href="modules/reports/processing_report.php" class="btn btn-sm btn-outline-primary">View Reports</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentProcessing)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-industry text-muted fa-3x mb-3"></i>
                            <p class="text-muted">No recent processing activity</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recentProcessing as $process): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1">
                                                <span class="badge bg-info me-2">Section <?php echo $process['section']; ?></span>
                                                <?php echo htmlspecialchars($process['process_type']); ?>
                                            </h6>
                                            <p class="mb-1 small">
                                                <strong>Item:</strong> <?php echo htmlspecialchars($process['item_name'] ?? 'N/A'); ?><br>
                                                <strong>Batch:</strong> <?php echo htmlspecialchars($process['batch_id']); ?><br>
                                                <strong>Operator:</strong> <?php echo htmlspecialchars($process['operator_name'] ?? 'N/A'); ?>
                                            </p>
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i>
                                                <?php echo date('M j, g:i A', strtotime($process['created_at'])); ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <?php if ($process['yield_percentage']): ?>
                                                <span class="badge bg-success"><?php echo number_format($process['yield_percentage'], 1); ?>% Yield</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Orders (Admin Only) -->
    <?php if ($userInfo['role'] === 'admin' && !empty($recentOrders)): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-shopping-cart me-2"></i>Recent Orders</h5>
                        <a href="modules/orders/" class="btn btn-sm btn-outline-primary">Manage Orders</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Customer</th>
                                        <th>Status</th>
                                        <th>Amount</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentOrders as $order): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($order['order_number']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo ORDER_STATUS[$order['status']]['color']; ?>">
                                                    <?php echo ORDER_STATUS[$order['status']]['name']; ?>
                                                </span>
                                            </td>
                                            <td>$<?php echo number_format($order['total_amount'], 2); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($order['order_date'])); ?></td>
                                            <td>
                                                <a href="modules/orders/view_order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php if (SessionManager::hasPermission('inventory_manage')): ?>
                            <div class="col-md-3 mb-3">
                                <a href="modules/inventory/add_item.php" class="btn btn-primary btn-lg w-100">
                                    <i class="fas fa-plus-circle mb-2 d-block"></i>
                                    Add Inventory Item
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($userInfo['role'] === 'admin'): ?>
                            <div class="col-md-3 mb-3">
                                <a href="modules/orders/create_order.php" class="btn btn-success btn-lg w-100">
                                    <i class="fas fa-shopping-cart mb-2 d-block"></i>
                                    Create Order
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <div class="col-md-3 mb-3">
                            <a href="modules/reports/" class="btn btn-info btn-lg w-100">
                                <i class="fas fa-chart-bar mb-2 d-block"></i>
                                View Reports
                            </a>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <a href="modules/inventory/stock_alerts.php" class="btn btn-warning btn-lg w-100">
                                <i class="fas fa-exclamation-triangle mb-2 d-block"></i>
                                Stock Alerts
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-refresh notifications every 30 seconds
setInterval(function() {
    // You can implement AJAX refresh here
}, 30000);

// Mark notification as read when clicked
document.querySelectorAll('.list-group-item').forEach(function(item) {
    item.addEventListener('click', function() {
        // Implement mark as read functionality
    });
});
</script>

<?php include 'includes/footer.php'; ?>
