<?php
/**
 * KYA Food Production - Pending Orders
 * Manage orders that require immediate attention
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

$pageTitle = "Pending Orders";

// Get pending orders
try {
    $stmt = $conn->prepare("
        SELECT o.*, 
               u1.full_name as created_by_name,
               u2.full_name as assigned_to_name,
               DATEDIFF(required_date, CURDATE()) as days_left
        FROM orders o
        LEFT JOIN users u1 ON o.created_by = u1.id
        LEFT JOIN users u2 ON o.assigned_to = u2.id
        WHERE o.status = 'pending'
        ORDER BY o.priority DESC, o.order_date ASC
    ");
    $stmt->execute();
    $pendingOrders = $stmt->fetchAll();
    
    // Get urgent orders (required date within 3 days)
    $stmt = $conn->prepare("
        SELECT COUNT(*) as urgent_count
        FROM orders 
        WHERE status = 'pending' 
        AND required_date IS NOT NULL 
        AND DATEDIFF(required_date, CURDATE()) <= 3
    ");
    $stmt->execute();
    $urgentCount = $stmt->fetchColumn();
    
} catch (Exception $e) {
    error_log("Pending orders error: " . $e->getMessage());
    $pendingOrders = [];
    $urgentCount = 0;
}

include '../../includes/header.php';
?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Pending Orders</h1>
            <p class="text-muted mb-0">Orders requiring immediate attention and processing</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-arrow-left me-2"></i>All Orders
            </a>
            <a href="create_order.php" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>New Order
            </a>
        </div>
    </div>

    <!-- Alert for urgent orders -->
    <?php if ($urgentCount > 0): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Urgent Alert:</strong> You have <?php echo $urgentCount; ?> pending order(s) with required dates within 3 days!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Stats -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card warning">
                <div class="stats-icon warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-number"><?php echo count($pendingOrders); ?></div>
                <div class="stats-label">Pending Orders</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card danger">
                <div class="stats-icon danger">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stats-number"><?php echo $urgentCount; ?></div>
                <div class="stats-label">Urgent (≤3 days)</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card info">
                <div class="stats-icon info">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div class="stats-number">
                    <?php echo count(array_filter($pendingOrders, function($o) { return !empty($o['assigned_to']); })); ?>
                </div>
                <div class="stats-label">Assigned</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card success">
                <div class="stats-icon success">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stats-number">
                    <?php echo formatCurrency(array_sum(array_column($pendingOrders, 'total_amount'))); ?>
                </div>
                <div class="stats-label">Pending Value</div>
            </div>
        </div>
    </div>

    <!-- Pending Orders Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-clock me-2"></i>Pending Orders
                <span class="badge bg-warning ms-2"><?php echo count($pendingOrders); ?></span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (!empty($pendingOrders)): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Order Date</th>
                                <th>Required Date</th>
                                <th>Priority</th>
                                <th>Total Amount</th>
                                <th>Assigned To</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingOrders as $order): ?>
                                <tr class="<?php echo $order['days_left'] !== null && $order['days_left'] <= 3 ? 'table-danger' : ($order['priority'] === 'urgent' ? 'table-warning' : ''); ?>">
                                    <td>
                                        <strong><?php echo $order['order_number']; ?></strong>
                                        <?php if ($order['priority'] === 'urgent'): ?>
                                            <i class="fas fa-exclamation-triangle text-danger ms-1" title="Urgent Priority"></i>
                                        <?php endif; ?>
                                        <?php if ($order['days_left'] !== null && $order['days_left'] <= 3): ?>
                                            <i class="fas fa-clock text-danger ms-1" title="Due Soon"></i>
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
                                            $dateClass = $order['days_left'] <= 3 ? 'text-danger fw-bold' : ($order['days_left'] <= 7 ? 'text-warning' : '');
                                            ?>
                                            <span class="<?php echo $dateClass; ?>">
                                                <?php echo formatDate($order['required_date']); ?>
                                            </span>
                                            <?php if ($order['days_left'] !== null): ?>
                                                <br><small class="<?php echo $dateClass; ?>">
                                                    <?php 
                                                    if ($order['days_left'] < 0) {
                                                        echo abs($order['days_left']) . ' days overdue';
                                                    } elseif ($order['days_left'] == 0) {
                                                        echo 'Due today';
                                                    } else {
                                                        echo $order['days_left'] . ' days left';
                                                    }
                                                    ?>
                                                </small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">No deadline</span>
                                        <?php endif; ?>
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
                                            <button type="button" class="btn btn-sm btn-outline-warning" 
                                                    onclick="showAssignModal(<?php echo $order['id']; ?>)">
                                                <i class="fas fa-user-plus me-1"></i>Assign
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="view_order.php?id=<?php echo $order['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-success" 
                                                    onclick="startProcessing(<?php echo $order['id']; ?>)" title="Start Processing">
                                                <i class="fas fa-play"></i>
                                            </button>
                                            <a href="edit_order.php?id=<?php echo $order['id']; ?>" 
                                               class="btn btn-sm btn-outline-secondary" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                    <h5 class="text-success">No Pending Orders</h5>
                    <p class="text-muted">All orders are currently being processed or completed.</p>
                    <a href="index.php" class="btn btn-outline-primary">
                        <i class="fas fa-list me-2"></i>View All Orders
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Assign Order Modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Assign Order</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="assign_order">
                    <input type="hidden" name="order_id" id="assign_order_id">
                    
                    <div class="mb-3">
                        <label for="assigned_to" class="form-label">Assign To</label>
                        <select name="assigned_to" id="assigned_to" class="form-select" required>
                            <option value="">Select user...</option>
                            <?php
                            try {
                                $stmt = $conn->prepare("SELECT id, full_name FROM users WHERE status = 'active' ORDER BY full_name");
                                $stmt->execute();
                                $users = $stmt->fetchAll();
                                foreach ($users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>"><?php echo $user['full_name']; ?></option>
                                <?php endforeach;
                            } catch (Exception $e) {
                                // Handle error silently
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Assign Order</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showAssignModal(orderId) {
    document.getElementById('assign_order_id').value = orderId;
    new bootstrap.Modal(document.getElementById('assignModal')).show();
}

function startProcessing(orderId) {
    if (confirm('Start processing this order? This will change the status to "Processing".')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="order_id" value="${orderId}">
            <input type="hidden" name="status" value="processing">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Auto-refresh every 2 minutes for pending orders
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 120000);
</script>

<?php include '../../includes/footer.php'; ?>
