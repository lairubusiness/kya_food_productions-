<?php
/**
 * KYA Food Production - Orders Management Dashboard
 * Main orders overview and management interface
 */

require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

SessionManager::start();
SessionManager::requireLogin();

// Check if user has orders management permissions (admin only for now)
if (!SessionManager::hasPermission('admin')) {
    header('Location: ../../dashboard.php?error=access_denied');
    exit();
}

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

$pageTitle = "Orders Management";

// Handle form submissions
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_status':
                $result = updateOrderStatus($_POST['order_id'], $_POST['status']);
                if ($result['success']) {
                    $successMessage = "Order status updated successfully!";
                } else {
                    $errorMessage = $result['message'];
                }
                break;
            case 'assign_order':
                $result = assignOrder($_POST['order_id'], $_POST['assigned_to']);
                if ($result['success']) {
                    $successMessage = "Order assigned successfully!";
                } else {
                    $errorMessage = $result['message'];
                }
                break;
        }
    }
}

// Get filter parameters
$status = $_GET['status'] ?? '';
$priority = $_GET['priority'] ?? '';
$search = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Get orders data
$orders = getOrders($status, $priority, $search, $date_from, $date_to);
$stats = getOrderStats();

function getOrders($status = '', $priority = '', $search = '', $dateFrom = '', $dateTo = '') {
    global $conn;
    
    try {
        $whereConditions = [];
        $params = [];
        
        if ($status) {
            $whereConditions[] = "o.status = ?";
            $params[] = $status;
        }
        
        if ($priority) {
            $whereConditions[] = "o.priority = ?";
            $params[] = $priority;
        }
        
        if ($search) {
            $whereConditions[] = "(o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_email LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        if ($dateFrom) {
            $whereConditions[] = "o.order_date >= ?";
            $params[] = $dateFrom;
        }
        
        if ($dateTo) {
            $whereConditions[] = "o.order_date <= ?";
            $params[] = $dateTo;
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        $stmt = $conn->prepare("
            SELECT o.*, 
                   u1.full_name as created_by_name,
                   u2.full_name as assigned_to_name
            FROM orders o
            LEFT JOIN users u1 ON o.created_by = u1.id
            LEFT JOIN users u2 ON o.assigned_to = u2.id
            $whereClause
            ORDER BY o.order_date DESC, o.created_at DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Get orders error: " . $e->getMessage());
        return [];
    }
}

function getOrderStats() {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_orders,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_orders,
                COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing_orders,
                COUNT(CASE WHEN status IN ('ready_to_ship', 'shipped') THEN 1 END) as shipping_orders,
                COUNT(CASE WHEN status = 'delivered' THEN 1 END) as delivered_orders,
                COUNT(CASE WHEN priority = 'urgent' AND status NOT IN ('delivered', 'cancelled') THEN 1 END) as urgent_orders,
                COALESCE(SUM(total_amount), 0) as total_value,
                COUNT(CASE WHEN order_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as recent_orders
            FROM orders
        ");
        $stmt->execute();
        return $stmt->fetch();
        
    } catch (Exception $e) {
        error_log("Get order stats error: " . $e->getMessage());
        return [
            'total_orders' => 0,
            'pending_orders' => 0,
            'processing_orders' => 0,
            'shipping_orders' => 0,
            'delivered_orders' => 0,
            'urgent_orders' => 0,
            'total_value' => 0,
            'recent_orders' => 0
        ];
    }
}

function updateOrderStatus($orderId, $status) {
    global $conn, $userInfo;
    
    try {
        $stmt = $conn->prepare("
            UPDATE orders 
            SET status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$status, $orderId]);
        
        if ($stmt->rowCount() > 0) {
            logActivity('order_status_updated', "Order status updated to: {$status}", $userInfo['id']);
            return ['success' => true];
        } else {
            return ['success' => false, 'message' => 'Order not found'];
        }
        
    } catch (Exception $e) {
        error_log("Update order status error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update order status'];
    }
}

function assignOrder($orderId, $assignedTo) {
    global $conn, $userInfo;
    
    try {
        $stmt = $conn->prepare("
            UPDATE orders 
            SET assigned_to = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$assignedTo, $orderId]);
        
        if ($stmt->rowCount() > 0) {
            logActivity('order_assigned', "Order assigned to user ID: {$assignedTo}", $userInfo['id']);
            return ['success' => true];
        } else {
            return ['success' => false, 'message' => 'Order not found'];
        }
        
    } catch (Exception $e) {
        error_log("Assign order error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to assign order'];
    }
}

include '../../includes/header.php';
?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Orders Management</h1>
            <p class="text-muted mb-0">Manage customer orders and export operations</p>
        </div>
        <div>
            <a href="create_order.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>New Order
            </a>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if ($successMessage): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo $successMessage; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($errorMessage): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $errorMessage; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card">
                <div class="stats-icon primary">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['total_orders']); ?></div>
                <div class="stats-label">Total Orders</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card warning">
                <div class="stats-icon warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['pending_orders']); ?></div>
                <div class="stats-label">Pending</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card info">
                <div class="stats-icon info">
                    <i class="fas fa-cogs"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['processing_orders']); ?></div>
                <div class="stats-label">Processing</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card success">
                <div class="stats-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['delivered_orders']); ?></div>
                <div class="stats-label">Delivered</div>
            </div>
        </div>
    </div>

    <!-- Secondary Stats Row -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card danger">
                <div class="stats-icon danger">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['urgent_orders']); ?></div>
                <div class="stats-label">Urgent Orders</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card secondary">
                <div class="stats-icon secondary">
                    <i class="fas fa-shipping-fast"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['shipping_orders']); ?></div>
                <div class="stats-label">Ready/Shipping</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card success">
                <div class="stats-icon success">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stats-number"><?php echo formatCurrency($stats['total_value']); ?></div>
                <div class="stats-label">Total Value</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card info">
                <div class="stats-icon info">
                    <i class="fas fa-calendar-week"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['recent_orders']); ?></div>
                <div class="stats-label">This Week</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="processing" <?php echo $status === 'processing' ? 'selected' : ''; ?>>Processing</option>
                        <option value="quality_check" <?php echo $status === 'quality_check' ? 'selected' : ''; ?>>Quality Check</option>
                        <option value="packaging" <?php echo $status === 'packaging' ? 'selected' : ''; ?>>Packaging</option>
                        <option value="ready_to_ship" <?php echo $status === 'ready_to_ship' ? 'selected' : ''; ?>>Ready to Ship</option>
                        <option value="shipped" <?php echo $status === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                        <option value="delivered" <?php echo $status === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                        <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="priority" class="form-label">Priority</label>
                    <select name="priority" id="priority" class="form-select">
                        <option value="">All Priority</option>
                        <option value="low" <?php echo $priority === 'low' ? 'selected' : ''; ?>>Low</option>
                        <option value="medium" <?php echo $priority === 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="urgent" <?php echo $priority === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="date_from" class="form-label">From Date</label>
                    <input type="date" name="date_from" id="date_from" class="form-control" value="<?php echo $date_from; ?>">
                </div>
                <div class="col-md-2">
                    <label for="date_to" class="form-label">To Date</label>
                    <input type="date" name="date_to" id="date_to" class="form-control" value="<?php echo $date_to; ?>">
                </div>
                <div class="col-md-3">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" name="search" id="search" class="form-control" 
                           placeholder="Order number, customer name..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary d-block">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Orders Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">Orders List</h5>
            <div>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportOrders()">
                    <i class="fas fa-download me-1"></i>Export
                </button>
            </div>
        </div>
        <div class="card-body">
            <?php if (!empty($orders)): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Order Date</th>
                                <th>Required Date</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Total Amount</th>
                                <th>Assigned To</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr class="<?php echo $order['priority'] === 'urgent' ? 'table-warning' : ''; ?>">
                                    <td>
                                        <strong><?php echo $order['order_number']; ?></strong>
                                        <?php if ($order['priority'] === 'urgent'): ?>
                                            <i class="fas fa-exclamation-triangle text-danger ms-1" title="Urgent"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo $order['customer_name']; ?></strong>
                                            <?php if ($order['customer_email']): ?>
                                                <br><small class="text-muted"><?php echo $order['customer_email']; ?></small>
                                            <?php endif; ?>
                                            <?php if ($order['export_country']): ?>
                                                <br><span class="badge bg-info"><?php echo $order['export_country']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo formatDate($order['order_date']); ?></td>
                                    <td>
                                        <?php if ($order['required_date']): ?>
                                            <?php 
                                            $daysLeft = (strtotime($order['required_date']) - time()) / (24 * 60 * 60);
                                            $dateClass = $daysLeft <= 3 ? 'text-danger' : ($daysLeft <= 7 ? 'text-warning' : '');
                                            ?>
                                            <span class="<?php echo $dateClass; ?>">
                                                <?php echo formatDate($order['required_date']); ?>
                                            </span>
                                            <?php if ($daysLeft > 0 && $daysLeft <= 7): ?>
                                                <br><small class="<?php echo $dateClass; ?>"><?php echo floor($daysLeft); ?> days left</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $statusColors = [
                                            'pending' => 'warning',
                                            'processing' => 'info',
                                            'quality_check' => 'primary',
                                            'packaging' => 'secondary',
                                            'ready_to_ship' => 'success',
                                            'shipped' => 'success',
                                            'delivered' => 'success',
                                            'cancelled' => 'danger'
                                        ];
                                        $statusColor = $statusColors[$order['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $statusColor; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo getPriorityBadge($order['priority']); ?>
                                    </td>
                                    <td>
                                        <strong><?php echo formatCurrency($order['total_amount'], $order['currency']); ?></strong>
                                        <?php if ($order['payment_status'] !== 'paid'): ?>
                                            <br><small class="text-muted">Payment: <?php echo ucfirst($order['payment_status']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($order['assigned_to_name']): ?>
                                            <span class="badge bg-light text-dark"><?php echo $order['assigned_to_name']; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="view_order.php?id=<?php echo $order['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="edit_order.php?id=<?php echo $order['id']; ?>" 
                                               class="btn btn-sm btn-outline-secondary" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-info" 
                                                    onclick="showStatusModal(<?php echo $order['id']; ?>, '<?php echo $order['status']; ?>')" title="Update Status">
                                                <i class="fas fa-sync"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No orders found</h5>
                    <p class="text-muted">No orders match your current filters.</p>
                    <a href="create_order.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Create First Order
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Update Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Order Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="order_id" id="status_order_id">
                    
                    <div class="mb-3">
                        <label for="status_select" class="form-label">New Status</label>
                        <select name="status" id="status_select" class="form-select" required>
                            <option value="pending">Pending</option>
                            <option value="processing">Processing</option>
                            <option value="quality_check">Quality Check</option>
                            <option value="packaging">Packaging</option>
                            <option value="ready_to_ship">Ready to Ship</option>
                            <option value="shipped">Shipped</option>
                            <option value="delivered">Delivered</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showStatusModal(orderId, currentStatus) {
    document.getElementById('status_order_id').value = orderId;
    document.getElementById('status_select').value = currentStatus;
    new bootstrap.Modal(document.getElementById('statusModal')).show();
}

function exportOrders() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');
    window.location.href = 'export_orders.php?' + params.toString();
}

// Auto-refresh every 5 minutes
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 300000);
</script>

<?php include '../../includes/footer.php'; ?>
