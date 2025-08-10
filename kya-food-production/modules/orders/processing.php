<?php
/**
 * KYA Food Production - Processing Orders
 * Manage orders currently being processed
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

$pageTitle = "Processing Orders";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_progress') {
        $orderId = $_POST['order_id'] ?? 0;
        $progress = $_POST['progress'] ?? 0;
        $notes = $_POST['notes'] ?? '';
        
        try {
            $stmt = $conn->prepare("
                UPDATE orders 
                SET progress_percentage = ?, 
                    notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$progress, $notes, $orderId]);
            
            logActivity('order_progress_updated', "Order progress updated to {$progress}%", $orderId);
            
            header('Location: processing.php?success=progress_updated');
            exit();
        } catch (Exception $e) {
            error_log("Progress update error: " . $e->getMessage());
            $error = "Failed to update progress";
        }
    }
    
    if ($action === 'complete_order') {
        $orderId = $_POST['order_id'] ?? 0;
        
        try {
            $stmt = $conn->prepare("
                UPDATE orders 
                SET status = 'completed',
                    progress_percentage = 100,
                    completion_date = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$orderId]);
            
            logActivity('order_completed', "Order marked as completed", $orderId);
            createNotification('Order Completed', "Order has been successfully completed", 'success');
            
            header('Location: processing.php?success=order_completed');
            exit();
        } catch (Exception $e) {
            error_log("Order completion error: " . $e->getMessage());
            $error = "Failed to complete order";
        }
    }
}

// Get processing orders
try {
    $stmt = $conn->prepare("
        SELECT o.*, 
               u1.full_name as created_by_name,
               u2.full_name as assigned_to_name,
               DATEDIFF(required_date, CURDATE()) as days_left
        FROM orders o
        LEFT JOIN users u1 ON o.created_by = u1.id
        LEFT JOIN users u2 ON o.assigned_to = u2.id
        WHERE o.status = 'processing'
        ORDER BY o.priority DESC, o.order_date ASC
    ");
    $stmt->execute();
    $processingOrders = $stmt->fetchAll();
    
    // Get processing statistics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_processing,
            AVG(progress_percentage) as avg_progress,
            COUNT(CASE WHEN required_date IS NOT NULL AND DATEDIFF(required_date, CURDATE()) <= 3 THEN 1 END) as urgent_count,
            COUNT(CASE WHEN progress_percentage >= 75 THEN 1 END) as near_completion
        FROM orders 
        WHERE status = 'processing'
    ");
    $stmt->execute();
    $stats = $stmt->fetch();
    
} catch (Exception $e) {
    error_log("Processing orders error: " . $e->getMessage());
    $processingOrders = [];
    $stats = ['total_processing' => 0, 'avg_progress' => 0, 'urgent_count' => 0, 'near_completion' => 0];
}

include '../../includes/header.php';
?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Processing Orders</h1>
            <p class="text-muted mb-0">Orders currently being processed and their progress</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-arrow-left me-2"></i>All Orders
            </a>
            <a href="pending.php" class="btn btn-outline-warning me-2">
                <i class="fas fa-clock me-2"></i>Pending Orders
            </a>
        </div>
    </div>

    <!-- Processing Statistics -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card info">
                <div class="stats-icon info">
                    <i class="fas fa-cogs"></i>
                </div>
                <div class="stats-number"><?php echo $stats['total_processing']; ?></div>
                <div class="stats-label">Processing Orders</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card success">
                <div class="stats-icon success">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['avg_progress'], 1); ?>%</div>
                <div class="stats-label">Average Progress</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card warning">
                <div class="stats-icon warning">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stats-number"><?php echo $stats['urgent_count']; ?></div>
                <div class="stats-label">Urgent (≤3 days)</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card primary">
                <div class="stats-icon primary">
                    <i class="fas fa-flag-checkered"></i>
                </div>
                <div class="stats-number"><?php echo $stats['near_completion']; ?></div>
                <div class="stats-label">Near Completion</div>
            </div>
        </div>
    </div>

    <!-- Processing Orders Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-cogs me-2"></i>Processing Orders
                <span class="badge bg-info ms-2"><?php echo count($processingOrders); ?></span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (!empty($processingOrders)): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Progress</th>
                                <th>Required Date</th>
                                <th>Priority</th>
                                <th>Assigned To</th>
                                <th>Total Amount</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($processingOrders as $order): ?>
                                <tr class="<?php echo $order['days_left'] !== null && $order['days_left'] <= 3 ? 'table-warning' : ''; ?>">
                                    <td>
                                        <strong><?php echo $order['order_number']; ?></strong>
                                        <?php if ($order['priority'] === 'urgent'): ?>
                                            <i class="fas fa-exclamation-triangle text-danger ms-1" title="Urgent Priority"></i>
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
                                    <td>
                                        <div class="progress mb-1" style="height: 20px;">
                                            <div class="progress-bar <?php echo $order['progress_percentage'] >= 75 ? 'bg-success' : ($order['progress_percentage'] >= 50 ? 'bg-info' : 'bg-warning'); ?>" 
                                                 role="progressbar" 
                                                 style="width: <?php echo $order['progress_percentage']; ?>%">
                                                <?php echo $order['progress_percentage']; ?>%
                                            </div>
                                        </div>
                                        <small class="text-muted">
                                            <?php 
                                            if ($order['progress_percentage'] >= 90) echo 'Almost complete';
                                            elseif ($order['progress_percentage'] >= 75) echo 'Near completion';
                                            elseif ($order['progress_percentage'] >= 50) echo 'Good progress';
                                            elseif ($order['progress_percentage'] >= 25) echo 'In progress';
                                            else echo 'Just started';
                                            ?>
                                        </small>
                                    </td>
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
                                        <?php if ($order['assigned_to_name']): ?>
                                            <span class="badge bg-light text-dark"><?php echo $order['assigned_to_name']; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo formatCurrency($order['total_amount'], $order['currency']); ?></strong>
                                        <?php if ($order['payment_status'] !== 'paid'): ?>
                                            <br><small class="text-muted">Payment: <?php echo ucfirst($order['payment_status']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="view_order.php?id=<?php echo $order['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-info" 
                                                    onclick="showProgressModal(<?php echo $order['id']; ?>, <?php echo $order['progress_percentage']; ?>)" 
                                                    title="Update Progress">
                                                <i class="fas fa-chart-line"></i>
                                            </button>
                                            <?php if ($order['progress_percentage'] >= 100): ?>
                                                <button type="button" class="btn btn-sm btn-success" 
                                                        onclick="completeOrder(<?php echo $order['id']; ?>)" title="Mark Complete">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-cogs fa-3x text-info mb-3"></i>
                    <h5 class="text-info">No Orders Being Processed</h5>
                    <p class="text-muted">No orders are currently in processing status.</p>
                    <a href="pending.php" class="btn btn-outline-warning">
                        <i class="fas fa-clock me-2"></i>View Pending Orders
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Update Progress Modal -->
<div class="modal fade" id="progressModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Order Progress</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_progress">
                    <input type="hidden" name="order_id" id="progress_order_id">
                    
                    <div class="mb-3">
                        <label for="progress" class="form-label">Progress Percentage</label>
                        <input type="range" class="form-range" id="progress" name="progress" 
                               min="0" max="100" step="5" oninput="updateProgressDisplay(this.value)">
                        <div class="d-flex justify-content-between">
                            <small class="text-muted">0%</small>
                            <span id="progress-display" class="fw-bold">50%</span>
                            <small class="text-muted">100%</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="notes" class="form-label">Progress Notes</label>
                        <textarea name="notes" id="notes" class="form-control" rows="3" 
                                  placeholder="Add notes about current progress..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Progress</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showProgressModal(orderId, currentProgress) {
    document.getElementById('progress_order_id').value = orderId;
    document.getElementById('progress').value = currentProgress;
    updateProgressDisplay(currentProgress);
    new bootstrap.Modal(document.getElementById('progressModal')).show();
}

function updateProgressDisplay(value) {
    document.getElementById('progress-display').textContent = value + '%';
    
    // Update progress bar color based on value
    const progressBar = document.querySelector('.progress-bar');
    if (progressBar) {
        progressBar.className = 'progress-bar';
        if (value >= 75) {
            progressBar.classList.add('bg-success');
        } else if (value >= 50) {
            progressBar.classList.add('bg-info');
        } else {
            progressBar.classList.add('bg-warning');
        }
        progressBar.style.width = value + '%';
        progressBar.textContent = value + '%';
    }
}

function completeOrder(orderId) {
    if (confirm('Mark this order as completed? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="complete_order">
            <input type="hidden" name="order_id" value="${orderId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Auto-refresh every 3 minutes for processing orders
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 180000);
</script>

<?php include '../../includes/footer.php'; ?>
