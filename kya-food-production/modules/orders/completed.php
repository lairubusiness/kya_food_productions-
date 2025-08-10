<?php
/**
 * KYA Food Production - Completed Orders
 * View and manage completed orders with performance analytics
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

$pageTitle = "Completed Orders";

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

// Get completed orders
try {
    $whereClause = "WHERE o.status = 'completed'";
    $params = [];
    
    if ($dateFrom) {
        $whereClause .= " AND DATE(o.completion_date) >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $whereClause .= " AND DATE(o.completion_date) <= ?";
        $params[] = $dateTo;
    }
    
    if ($search) {
        $whereClause .= " AND (o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_email LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    $stmt = $conn->prepare("
        SELECT o.*, 
               u1.full_name as created_by_name,
               u2.full_name as assigned_to_name,
               DATEDIFF(o.completion_date, o.order_date) as processing_days,
               CASE 
                   WHEN o.required_date IS NULL THEN 'N/A'
                   WHEN o.completion_date <= o.required_date THEN 'On Time'
                   ELSE 'Late'
               END as delivery_status
        FROM orders o
        LEFT JOIN users u1 ON o.created_by = u1.id
        LEFT JOIN users u2 ON o.assigned_to = u2.id
        {$whereClause}
        ORDER BY o.completion_date DESC
    ");
    $stmt->execute($params);
    $completedOrders = $stmt->fetchAll();
    
    // Get completion statistics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_completed,
            SUM(total_amount) as total_revenue,
            AVG(DATEDIFF(completion_date, order_date)) as avg_processing_days,
            COUNT(CASE WHEN completion_date <= required_date OR required_date IS NULL THEN 1 END) as on_time_count,
            COUNT(CASE WHEN completion_date > required_date THEN 1 END) as late_count
        FROM orders 
        {$whereClause}
    ");
    $stmt->execute($params);
    $stats = $stmt->fetch();
    
    $onTimeRate = $stats['total_completed'] > 0 ? 
        round(($stats['on_time_count'] / $stats['total_completed']) * 100, 1) : 0;
    
} catch (Exception $e) {
    error_log("Completed orders error: " . $e->getMessage());
    $completedOrders = [];
    $stats = ['total_completed' => 0, 'total_revenue' => 0, 'avg_processing_days' => 0, 'on_time_count' => 0, 'late_count' => 0];
    $onTimeRate = 0;
}

include '../../includes/header.php';
?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Completed Orders</h1>
            <p class="text-muted mb-0">Track completed orders and performance metrics</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-arrow-left me-2"></i>All Orders
            </a>
            <button type="button" class="btn btn-success" onclick="exportCompleted()">
                <i class="fas fa-download me-2"></i>Export Report
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="date_from" class="form-label">From Date</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $dateFrom; ?>">
                </div>
                <div class="col-md-3">
                    <label for="date_to" class="form-label">To Date</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $dateTo; ?>">
                </div>
                <div class="col-md-4">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Order number, customer name, or email...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i>Filter
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Performance Statistics -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card success">
                <div class="stats-icon success">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stats-number"><?php echo $stats['total_completed']; ?></div>
                <div class="stats-label">Completed Orders</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card primary">
                <div class="stats-icon primary">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stats-number"><?php echo formatCurrency($stats['total_revenue']); ?></div>
                <div class="stats-label">Total Revenue</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card info">
                <div class="stats-icon info">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['avg_processing_days'], 1); ?></div>
                <div class="stats-label">Avg Processing Days</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card <?php echo $onTimeRate >= 90 ? 'success' : ($onTimeRate >= 75 ? 'warning' : 'danger'); ?>">
                <div class="stats-icon <?php echo $onTimeRate >= 90 ? 'success' : ($onTimeRate >= 75 ? 'warning' : 'danger'); ?>">
                    <i class="fas fa-target"></i>
                </div>
                <div class="stats-number"><?php echo $onTimeRate; ?>%</div>
                <div class="stats-label">On-Time Delivery</div>
            </div>
        </div>
    </div>

    <!-- Completed Orders Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-check-circle me-2"></i>Completed Orders
                <span class="badge bg-success ms-2"><?php echo count($completedOrders); ?></span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (!empty($completedOrders)): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Customer</th>
                                <th>Order Date</th>
                                <th>Completion Date</th>
                                <th>Processing Time</th>
                                <th>Delivery Status</th>
                                <th>Total Amount</th>
                                <th>Assigned To</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completedOrders as $order): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo $order['order_number']; ?></strong>
                                        <?php if ($order['priority'] === 'urgent'): ?>
                                            <i class="fas fa-exclamation-triangle text-warning ms-1" title="Was Urgent"></i>
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
                                        <strong><?php echo formatDate($order['completion_date']); ?></strong>
                                        <br><small class="text-muted"><?php echo date('H:i:s', strtotime($order['completion_date'])); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $order['processing_days'] <= 3 ? 'bg-success' : ($order['processing_days'] <= 7 ? 'bg-warning' : 'bg-danger'); ?>">
                                            <?php echo $order['processing_days']; ?> days
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($order['delivery_status'] === 'On Time'): ?>
                                            <span class="badge bg-success">
                                                <i class="fas fa-check me-1"></i>On Time
                                            </span>
                                        <?php elseif ($order['delivery_status'] === 'Late'): ?>
                                            <span class="badge bg-danger">
                                                <i class="fas fa-exclamation me-1"></i>Late
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">
                                                <i class="fas fa-minus me-1"></i>N/A
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo formatCurrency($order['total_amount'], $order['currency']); ?></strong>
                                        <br><small class="text-success">
                                            <i class="fas fa-check-circle me-1"></i>
                                            <?php echo ucfirst($order['payment_status']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($order['assigned_to_name']): ?>
                                            <span class="badge bg-light text-dark"><?php echo $order['assigned_to_name']; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">System</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="view_order.php?id=<?php echo $order['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-info" 
                                                    onclick="showOrderSummary(<?php echo $order['id']; ?>)" title="Order Summary">
                                                <i class="fas fa-chart-bar"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                    onclick="generateInvoice(<?php echo $order['id']; ?>)" title="Generate Invoice">
                                                <i class="fas fa-file-invoice"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination would go here if needed -->
                
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-search fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No Completed Orders Found</h5>
                    <p class="text-muted">No orders match your current filter criteria.</p>
                    <a href="?date_from=<?php echo date('Y-m-01', strtotime('-1 month')); ?>&date_to=<?php echo date('Y-m-d'); ?>" 
                       class="btn btn-outline-primary">
                        <i class="fas fa-calendar me-2"></i>View Last Month
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Performance Insights -->
    <?php if (!empty($completedOrders)): ?>
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-chart-pie me-2"></i>Delivery Performance
                        </h6>
                    </div>
                    <div class="card-body">
                        <canvas id="deliveryChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-chart-line me-2"></i>Processing Time Distribution
                        </h6>
                    </div>
                    <div class="card-body">
                        <canvas id="processingChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Order Summary Modal -->
<div class="modal fade" id="summaryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Order Summary</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="summaryContent">
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Initialize charts if we have data
<?php if (!empty($completedOrders)): ?>
    // Delivery Performance Chart
    const deliveryCtx = document.getElementById('deliveryChart').getContext('2d');
    new Chart(deliveryCtx, {
        type: 'doughnut',
        data: {
            labels: ['On Time', 'Late'],
            datasets: [{
                data: [<?php echo $stats['on_time_count']; ?>, <?php echo $stats['late_count']; ?>],
                backgroundColor: ['#28a745', '#dc3545'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });

    // Processing Time Distribution Chart
    const processingCtx = document.getElementById('processingChart').getContext('2d');
    const processingTimes = [<?php echo implode(',', array_column($completedOrders, 'processing_days')); ?>];
    
    // Group processing times into buckets
    const buckets = {'1-3 days': 0, '4-7 days': 0, '8-14 days': 0, '15+ days': 0};
    processingTimes.forEach(days => {
        if (days <= 3) buckets['1-3 days']++;
        else if (days <= 7) buckets['4-7 days']++;
        else if (days <= 14) buckets['8-14 days']++;
        else buckets['15+ days']++;
    });
    
    new Chart(processingCtx, {
        type: 'bar',
        data: {
            labels: Object.keys(buckets),
            datasets: [{
                label: 'Number of Orders',
                data: Object.values(buckets),
                backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#dc3545'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            }
        }
    });
<?php endif; ?>

function showOrderSummary(orderId) {
    const modal = new bootstrap.Modal(document.getElementById('summaryModal'));
    modal.show();
    
    // Load order summary via AJAX (placeholder)
    document.getElementById('summaryContent').innerHTML = `
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            Order summary functionality will be implemented with detailed analytics including:
            <ul class="mb-0 mt-2">
                <li>Order timeline and milestones</li>
                <li>Processing efficiency metrics</li>
                <li>Quality control checkpoints</li>
                <li>Customer satisfaction scores</li>
            </ul>
        </div>
    `;
}

function generateInvoice(orderId) {
    // Placeholder for invoice generation
    alert('Invoice generation functionality will be implemented to create PDF invoices for completed orders.');
}

function exportCompleted() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');
    window.open('?' + params.toString(), '_blank');
}

// Auto-refresh every 5 minutes for completed orders
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 300000);
</script>

<?php include '../../includes/footer.php'; ?>
