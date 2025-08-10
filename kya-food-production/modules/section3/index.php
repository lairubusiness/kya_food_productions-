<?php
/**
 * KYA Food Production - Section 3 Dashboard
 * Processing operations management and monitoring for Section 3
 */

require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

SessionManager::start();
SessionManager::requireLogin();
SessionManager::requireSection(3);

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

// Get processing statistics for Section 3
$processingStats = $conn->prepare("
    SELECT 
        COUNT(*) as total_processes,
        COUNT(CASE WHEN end_time IS NULL THEN 1 END) as active_processes,
        COUNT(CASE WHEN end_time IS NOT NULL THEN 1 END) as completed_processes,
        AVG(CASE WHEN yield_percentage IS NOT NULL THEN yield_percentage END) as avg_yield,
        SUM(input_quantity) as total_input,
        SUM(output_quantity) as total_output
    FROM processing_logs 
    WHERE section = 3
");
$processingStats->execute();
$processing = $processingStats->fetch(PDO::FETCH_ASSOC);

// Get inventory statistics for Section 3
$inventoryStats = $conn->prepare("
    SELECT 
        COUNT(*) as total_items,
        COUNT(CASE WHEN alert_status = 'low_stock' THEN 1 END) as low_stock_items,
        COUNT(CASE WHEN alert_status = 'critical' THEN 1 END) as critical_items,
        COUNT(CASE WHEN alert_status = 'expired' THEN 1 END) as expired_items,
        SUM(total_value) as total_value
    FROM inventory 
    WHERE section = 3
");
$inventoryStats->execute();
$inventory = $inventoryStats->fetch(PDO::FETCH_ASSOC);

// Get recent processing activities
$recentActivities = $conn->prepare("
    SELECT 
        pl.*,
        i.item_name,
        u.username as operator_name
    FROM processing_logs pl
    LEFT JOIN inventory i ON pl.item_id = i.id
    LEFT JOIN users u ON pl.operator_id = u.id
    WHERE pl.section = 3
    ORDER BY pl.created_at DESC
    LIMIT 10
");
$recentActivities->execute();
$activities = $recentActivities->fetchAll(PDO::FETCH_ASSOC);

// Get process type distribution
$processTypes = $conn->prepare("
    SELECT 
        process_type,
        COUNT(*) as count,
        AVG(yield_percentage) as avg_yield
    FROM processing_logs 
    WHERE section = 3 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY process_type
    ORDER BY count DESC
");
$processTypes->execute();
$processDistribution = $processTypes->fetchAll(PDO::FETCH_ASSOC);

// Get items requiring attention
$alertItems = $conn->prepare("
    SELECT *
    FROM inventory 
    WHERE section = 3 AND alert_status IN ('low_stock', 'critical', 'expired', 'expiring_soon')
    ORDER BY 
        CASE alert_status 
            WHEN 'critical' THEN 1 
            WHEN 'expired' THEN 2 
            WHEN 'expiring_soon' THEN 3 
            WHEN 'low_stock' THEN 4 
        END,
        created_at DESC
    LIMIT 10
");
$alertItems->execute();
$alerts = $alertItems->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Section 3 - Dashboard';
include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Section 3 Dashboard</h1>
            <p class="text-muted">Processing operations management and monitoring</p>
        </div>
        <div>
            <a href="processing.php" class="btn btn-primary me-2">
                <i class="fas fa-cogs me-2"></i>Processing Management
            </a>
            <a href="batch_tracking.php" class="btn btn-outline-primary">
                <i class="fas fa-clipboard-list me-2"></i>Batch Tracking
            </a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <!-- Processing Statistics -->
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card bg-primary text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Total Processes</h6>
                            <h4 class="mb-0"><?php echo number_format($processing['total_processes'] ?? 0); ?></h4>
                            <small class="opacity-75">All time</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-cogs fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card bg-warning text-dark h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Active Processes</h6>
                            <h4 class="mb-0"><?php echo number_format($processing['active_processes'] ?? 0); ?></h4>
                            <small>Currently running</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-play-circle fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card bg-success text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Average Yield</h6>
                            <h4 class="mb-0"><?php echo number_format($processing['avg_yield'] ?? 0, 1); ?>%</h4>
                            <small class="opacity-75">Processing efficiency</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-percentage fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card bg-info text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Total Output</h6>
                            <h4 class="mb-0"><?php echo number_format($processing['total_output'] ?? 0, 1); ?> kg</h4>
                            <small class="opacity-75">Production volume</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-weight fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Inventory Alert Cards -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card bg-secondary text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Total Items</h6>
                            <h4 class="mb-0"><?php echo number_format($inventory['total_items'] ?? 0); ?></h4>
                            <small class="opacity-75">Inventory count</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-boxes fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card bg-warning text-dark h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Low Stock</h6>
                            <h4 class="mb-0"><?php echo number_format($inventory['low_stock_items'] ?? 0); ?></h4>
                            <small>Items need reorder</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-exclamation-triangle fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card bg-danger text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Critical Items</h6>
                            <h4 class="mb-0"><?php echo number_format($inventory['critical_items'] ?? 0); ?></h4>
                            <small class="opacity-75">Immediate attention</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-exclamation-circle fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card bg-dark text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Total Value</h6>
                            <h4 class="mb-0">₹<?php echo number_format($inventory['total_value'] ?? 0, 0); ?></h4>
                            <small class="opacity-75">Inventory worth</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-rupee-sign fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Row -->
    <div class="row">
        <!-- Recent Activities -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-history text-info me-2"></i>Recent Processing Activities
                    </h5>
                    <a href="processing.php" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body">
                    <?php if (empty($activities)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                            <h6 class="text-muted">No Recent Activities</h6>
                            <p class="text-muted">No processing activities recorded yet.</p>
                            <a href="processing.php" class="btn btn-primary">
                                <i class="fas fa-plus me-2"></i>Start Processing
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($activities as $activity): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1">
                                                <span class="badge bg-secondary me-2">
                                                    <?php echo ucfirst(str_replace('_', ' ', $activity['process_type'])); ?>
                                                </span>
                                                <?php echo htmlspecialchars($activity['batch_id']); ?>
                                            </h6>
                                            <p class="mb-1 small">
                                                <?php echo htmlspecialchars($activity['item_name'] ?? 'Unknown Item'); ?>
                                                <?php if ($activity['process_stage']): ?>
                                                    - <?php echo htmlspecialchars($activity['process_stage']); ?>
                                                <?php endif; ?>
                                            </p>
                                            <small class="text-muted">
                                                <?php echo timeAgo($activity['created_at']); ?>
                                                <?php if ($activity['operator_name']): ?>
                                                    by <?php echo htmlspecialchars($activity['operator_name']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <?php if ($activity['yield_percentage']): ?>
                                            <span class="badge bg-<?php echo $activity['yield_percentage'] >= 90 ? 'success' : ($activity['yield_percentage'] >= 75 ? 'warning' : 'danger'); ?>">
                                                <?php echo number_format($activity['yield_percentage'], 1); ?>%
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Process Type Distribution -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-pie text-primary me-2"></i>Process Distribution (Last 30 Days)
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($processDistribution)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-chart-pie fa-3x text-muted mb-3"></i>
                            <h6 class="text-muted">No Process Data</h6>
                            <p class="text-muted">No processing activities in the last 30 days.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($processDistribution as $process): ?>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h6 class="mb-1"><?php echo ucfirst(str_replace('_', ' ', $process['process_type'])); ?></h6>
                                    <small class="text-muted">Avg Yield: <?php echo number_format($process['avg_yield'] ?? 0, 1); ?>%</small>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-primary"><?php echo number_format($process['count']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Items Requiring Attention -->
    <?php if (!empty($alerts)): ?>
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-exclamation-triangle text-warning me-2"></i>Items Requiring Attention
                    </h5>
                    <a href="../inventory/index.php?section=3" class="btn btn-sm btn-outline-warning">View All</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Current Stock</th>
                                    <th>Alert Type</th>
                                    <th>Expiry Date</th>
                                    <th>Action Required</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alerts as $alert): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($alert['item_name']); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($alert['item_code']); ?></small>
                                        </td>
                                        <td>
                                            <?php echo number_format($alert['quantity'], 2); ?> <?php echo htmlspecialchars($alert['unit']); ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $alert['alert_status'] == 'critical' ? 'danger' : 
                                                    ($alert['alert_status'] == 'expired' ? 'dark' : 
                                                    ($alert['alert_status'] == 'expiring_soon' ? 'warning' : 'secondary')); 
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $alert['alert_status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($alert['expiry_date']): ?>
                                                <?php echo date('M j, Y', strtotime($alert['expiry_date'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($alert['alert_status'] == 'low_stock' || $alert['alert_status'] == 'critical'): ?>
                                                <small class="text-primary">Reorder needed</small>
                                            <?php elseif ($alert['alert_status'] == 'expired'): ?>
                                                <small class="text-danger">Remove from inventory</small>
                                            <?php elseif ($alert['alert_status'] == 'expiring_soon'): ?>
                                                <small class="text-warning">Use soon</small>
                                            <?php endif; ?>
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
</div>

<style>
.card-body {
    background-color: #ffffff;
    color: #212529;
}

.table {
    background-color: #ffffff;
    color: #212529;
}

.list-group-item {
    background-color: #ffffff;
    color: #212529;
    border-color: #dee2e6;
}
</style>

<?php include '../../includes/footer.php'; ?>
