<?php
/**
 * KYA Food Production - Section 1 Dashboard
 * Raw Material Handling Management
 */

require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

SessionManager::start();
SessionManager::requireLogin();
SessionManager::requireSection(1);

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

// Get Section 1 statistics
try {
    // Inventory statistics for Section 1
    $inventoryStats = $conn->query("
        SELECT 
            COUNT(*) as total_items,
            SUM(quantity * COALESCE(unit_cost, 0)) as total_value,
            COUNT(CASE WHEN alert_status IN ('low_stock', 'critical') THEN 1 END) as alert_items,
            COUNT(CASE WHEN DATEDIFF(expiry_date, CURDATE()) <= 7 AND status = 'active' THEN 1 END) as expiring_items,
            AVG(CASE WHEN storage_temperature IS NOT NULL THEN storage_temperature END) as avg_temperature,
            AVG(CASE WHEN storage_humidity IS NOT NULL THEN storage_humidity END) as avg_humidity
        FROM inventory 
        WHERE section = 1
    ")->fetch();
    
    // Recent processing activities
    $recentProcessing = $conn->query("
        SELECT pl.*, i.item_name, u1.full_name as operator_name, u2.full_name as supervisor_name
        FROM processing_logs pl
        LEFT JOIN inventory i ON pl.item_id = i.id
        LEFT JOIN users u1 ON pl.operator_id = u1.id
        LEFT JOIN users u2 ON pl.supervisor_id = u2.id
        WHERE pl.section = 1
        ORDER BY pl.created_at DESC
        LIMIT 5
    ")->fetchAll();
    
    // Items requiring attention
    $attentionItems = $conn->query("
        SELECT *
        FROM inventory 
        WHERE section = 1 
        AND (
            alert_status IN ('low_stock', 'critical', 'expiring_soon') 
            OR DATEDIFF(expiry_date, CURDATE()) <= 7
        )
        ORDER BY 
            CASE alert_status
                WHEN 'critical' THEN 1
                WHEN 'low_stock' THEN 2
                WHEN 'expiring_soon' THEN 3
                ELSE 4
            END,
            expiry_date ASC
        LIMIT 10
    ")->fetchAll();
    
    // Quality distribution
    $qualityStats = $conn->query("
        SELECT 
            quality_grade,
            COUNT(*) as count,
            SUM(quantity * COALESCE(unit_cost, 0)) as value
        FROM inventory 
        WHERE section = 1 AND status = 'active'
        GROUP BY quality_grade
        ORDER BY quality_grade
    ")->fetchAll();
    
} catch (Exception $e) {
    error_log("Section 1 dashboard error: " . $e->getMessage());
    $inventoryStats = ['total_items' => 0, 'total_value' => 0, 'alert_items' => 0, 'expiring_items' => 0, 'avg_temperature' => 0, 'avg_humidity' => 0];
    $recentProcessing = [];
    $attentionItems = [];
    $qualityStats = [];
}

$pageTitle = 'Section 1 - Raw Material Handling';
include '../../includes/header.php';
?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <span class="badge me-2" style="background-color: <?php echo SECTIONS[1]['color']; ?>">
                    Section 1
                </span>
                Raw Material Handling
            </h1>
            <p class="text-muted mb-0">Manage incoming raw materials, storage, and quality control</p>
        </div>
        <div class="btn-group" role="group">
            <a href="inventory.php" class="btn btn-primary">
                <i class="fas fa-boxes me-2"></i>Inventory
            </a>
            <a href="storage.php" class="btn btn-outline-primary">
                <i class="fas fa-warehouse me-2"></i>Storage
            </a>
            <a href="quality_control.php" class="btn btn-outline-success">
                <i class="fas fa-check-circle me-2"></i>Quality Control
            </a>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card primary">
                <div class="stats-icon primary">
                    <i class="fas fa-apple-alt"></i>
                </div>
                <div class="stats-number"><?php echo number_format($inventoryStats['total_items']); ?></div>
                <div class="stats-label">Raw Material Items</div>
                <div class="stats-sublabel">Active inventory</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card success">
                <div class="stats-icon success">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stats-number"><?php echo formatCurrency($inventoryStats['total_value']); ?></div>
                <div class="stats-label">Total Value</div>
                <div class="stats-sublabel">Current inventory value</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card warning">
                <div class="stats-icon warning">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stats-number"><?php echo number_format($inventoryStats['alert_items']); ?></div>
                <div class="stats-label">Alert Items</div>
                <div class="stats-sublabel">Require attention</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card danger">
                <div class="stats-icon danger">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-number"><?php echo number_format($inventoryStats['expiring_items']); ?></div>
                <div class="stats-label">Expiring Soon</div>
                <div class="stats-sublabel">Within 7 days</div>
            </div>
        </div>
    </div>
    
    <!-- Environmental Monitoring -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Storage Conditions</h5>
                    <span class="badge bg-success">Optimal</span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6">
                            <div class="text-center">
                                <div class="display-6 text-info">
                                    <i class="fas fa-thermometer-half"></i>
                                </div>
                                <h4 class="text-info"><?php echo number_format($inventoryStats['avg_temperature'], 1); ?>°C</h4>
                                <p class="text-muted mb-0">Average Temperature</p>
                                <small class="text-success">Within Range</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="text-center">
                                <div class="display-6 text-primary">
                                    <i class="fas fa-tint"></i>
                                </div>
                                <h4 class="text-primary"><?php echo number_format($inventoryStats['avg_humidity'], 1); ?>%</h4>
                                <p class="text-muted mb-0">Average Humidity</p>
                                <small class="text-success">Optimal Level</small>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between">
                        <a href="temperature_monitoring.php" class="btn btn-sm btn-outline-info">
                            <i class="fas fa-chart-line me-1"></i>View Trends
                        </a>
                        <a href="storage.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-warehouse me-1"></i>Storage Details
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Quality Distribution</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($qualityStats)): ?>
                        <p class="text-muted text-center">No quality data available</p>
                    <?php else: ?>
                        <?php foreach ($qualityStats as $quality): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <span class="badge bg-<?php echo getQualityColor($quality['quality_grade']); ?>">
                                        Grade <?php echo $quality['quality_grade']; ?>
                                    </span>
                                </div>
                                <div class="text-end">
                                    <div><strong><?php echo number_format($quality['count']); ?> items</strong></div>
                                    <small class="text-muted"><?php echo formatCurrency($quality['value']); ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <hr>
                    <div class="text-center">
                        <a href="quality_control.php" class="btn btn-sm btn-outline-success">
                            <i class="fas fa-clipboard-check me-1"></i>Quality Reports
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Items Requiring Attention -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Items Requiring Attention</h5>
                    <span class="badge bg-warning"><?php echo count($attentionItems); ?> items</span>
                </div>
                <div class="card-body">
                    <?php if (empty($attentionItems)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                            <h5 class="text-success">All Good!</h5>
                            <p class="text-muted">No items require immediate attention.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Issue</th>
                                        <th>Quantity</th>
                                        <th>Expiry Date</th>
                                        <th>Location</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attentionItems as $item): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($item['item_name']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($item['item_code']); ?></small>
                                            </td>
                                            <td>
                                                <?php if ($item['alert_status'] !== 'normal'): ?>
                                                    <span class="badge bg-<?php echo $item['alert_status'] === 'critical' ? 'danger' : 'warning'; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $item['alert_status'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php 
                                                $daysToExpiry = $item['expiry_date'] ? (strtotime($item['expiry_date']) - time()) / (24 * 60 * 60) : null;
                                                if ($daysToExpiry !== null && $daysToExpiry <= 7): 
                                                ?>
                                                    <span class="badge bg-danger">Expires in <?php echo floor($daysToExpiry); ?> days</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo number_format($item['quantity'], 2); ?></strong>
                                                <small class="text-muted"><?php echo htmlspecialchars($item['unit']); ?></small>
                                            </td>
                                            <td>
                                                <?php if ($item['expiry_date']): ?>
                                                    <span class="<?php echo $daysToExpiry <= 7 ? 'text-danger' : ''; ?>">
                                                        <?php echo formatDate($item['expiry_date']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($item['storage_location'] ?? '-'); ?></td>
                                            <td>
                                                <a href="../inventory/view_item.php?id=<?php echo $item['id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Processing Activities -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Recent Processing Activities</h5>
                    <a href="../processing/logs.php?section=1" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body">
                    <?php if (empty($recentProcessing)): ?>
                        <p class="text-muted text-center">No recent processing activities.</p>
                    <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($recentProcessing as $process): ?>
                                <div class="timeline-item">
                                    <div class="timeline-marker bg-primary"></div>
                                    <div class="timeline-content">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1">
                                                    <?php echo ucfirst(str_replace('_', ' ', $process['process_type'])); ?>
                                                    <?php if ($process['item_name']): ?>
                                                        - <?php echo htmlspecialchars($process['item_name']); ?>
                                                    <?php endif; ?>
                                                </h6>
                                                <p class="text-muted mb-1">
                                                    Batch: <?php echo htmlspecialchars($process['batch_id']); ?>
                                                    <?php if ($process['yield_percentage']): ?>
                                                        | Yield: <?php echo number_format($process['yield_percentage'], 1); ?>%
                                                    <?php endif; ?>
                                                </p>
                                                <?php if ($process['operator_name']): ?>
                                                    <small class="text-muted">
                                                        Operator: <?php echo htmlspecialchars($process['operator_name']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted"><?php echo timeAgo($process['created_at']); ?></small>
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
</div>

<script>
// Auto-refresh dashboard every 5 minutes
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 300000);

function getQualityColor(grade) {
    switch(grade) {
        case 'A': return 'success';
        case 'B': return 'primary';
        case 'C': return 'warning';
        case 'D': return 'danger';
        default: return 'secondary';
    }
}
</script>

<?php include '../../includes/footer.php'; ?>

<?php
function getQualityColor($grade) {
    switch($grade) {
        case 'A': return 'success';
        case 'B': return 'primary';
        case 'C': return 'warning';
        case 'D': return 'danger';
        default: return 'secondary';
    }
}
?>
