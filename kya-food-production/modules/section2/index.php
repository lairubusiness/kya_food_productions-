<?php
/**
 * KYA Food Production - Section 2 Dashboard
 * Processing Operations Management
 */

require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

SessionManager::start();
SessionManager::requireLogin();
SessionManager::requireSection(2);

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

// Get Section 2 statistics
try {
    // Processing statistics for Section 2
    $processingStats = $conn->query("
        SELECT 
            COUNT(*) as total_processes,
            COUNT(DISTINCT batch_id) as total_batches,
            AVG(yield_percentage) as avg_yield,
            AVG(duration_minutes) as avg_duration,
            COUNT(CASE WHEN end_time IS NULL THEN 1 END) as active_processes,
            SUM(input_quantity) as total_input,
            SUM(output_quantity) as total_output,
            COUNT(CASE WHEN yield_percentage >= 90 THEN 1 END) as high_yield_processes
        FROM processing_logs 
        WHERE section = 2 AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ")->fetch();
    
    // Inventory statistics for Section 2
    $inventoryStats = $conn->query("
        SELECT 
            COUNT(*) as total_items,
            SUM(quantity * COALESCE(unit_cost, 0)) as total_value,
            COUNT(CASE WHEN alert_status IN ('low_stock', 'critical') THEN 1 END) as alert_items,
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active_items
        FROM inventory 
        WHERE section = 2
    ")->fetch();
    
    // Recent processing activities
    $recentProcessing = $conn->query("
        SELECT pl.*, i.item_name, u1.username as operator_name, u2.username as supervisor_name
        FROM processing_logs pl
        LEFT JOIN inventory i ON pl.item_id = i.id
        LEFT JOIN users u1 ON pl.operator_id = u1.id
        LEFT JOIN users u2 ON pl.supervisor_id = u2.id
        WHERE pl.section = 2
        ORDER BY pl.created_at DESC
        LIMIT 5
    ")->fetchAll();
    
    // Active processes
    $activeProcesses = $conn->query("
        SELECT pl.*, i.item_name, u1.username as operator_name
        FROM processing_logs pl
        LEFT JOIN inventory i ON pl.item_id = i.id
        LEFT JOIN users u1 ON pl.operator_id = u1.id
        WHERE pl.section = 2 AND pl.end_time IS NULL
        ORDER BY pl.start_time DESC
        LIMIT 10
    ")->fetchAll();
    
    // Process type distribution
    $processTypes = $conn->query("
        SELECT 
            process_type,
            COUNT(*) as count,
            AVG(yield_percentage) as avg_yield
        FROM processing_logs 
        WHERE section = 2 AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY process_type
        ORDER BY count DESC
    ")->fetchAll();
    
    // Equipment usage
    $equipmentUsage = $conn->query("
        SELECT 
            equipment_used,
            COUNT(*) as usage_count,
            AVG(yield_percentage) as avg_yield,
            AVG(duration_minutes) as avg_duration
        FROM processing_logs 
        WHERE section = 2 
        AND equipment_used IS NOT NULL 
        AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY equipment_used
        ORDER BY usage_count DESC
        LIMIT 5
    ")->fetchAll();
    
} catch (Exception $e) {
    error_log("Section 2 dashboard error: " . $e->getMessage());
    $processingStats = ['total_processes' => 0, 'total_batches' => 0, 'avg_yield' => 0, 'avg_duration' => 0, 'active_processes' => 0, 'total_input' => 0, 'total_output' => 0, 'high_yield_processes' => 0];
    $inventoryStats = ['total_items' => 0, 'total_value' => 0, 'alert_items' => 0, 'active_items' => 0];
    $recentProcessing = [];
    $activeProcesses = [];
    $processTypes = [];
    $equipmentUsage = [];
}

$pageTitle = 'Section 2 - Processing Operations';
include '../../includes/header.php';
?>

<style>
    .stats-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
        color: white;
        transition: transform 0.2s;
    }
    .stats-card:hover {
        transform: translateY(-5px);
    }
    .stats-card .card-body {
        padding: 1.5rem;
    }
    .stats-card h3 {
        font-size: 2rem;
        font-weight: bold;
        margin-bottom: 0.5rem;
        color: white;
    }
    .stats-card p {
        margin-bottom: 0;
        opacity: 0.9;
        color: white;
    }
    .stats-card i {
        font-size: 2.5rem;
        opacity: 0.8;
        color: white;
    }
    
    .primary-card {
        background: linear-gradient(135deg, #339af0 0%, #228be6 100%);
        color: white;
    }
    .primary-card h3, .primary-card p, .primary-card i {
        color: white;
    }
    
    .success-card {
        background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
        color: white;
    }
    .success-card h3, .success-card p, .success-card i {
        color: white;
    }
    
    .info-card {
        background: linear-gradient(135deg, #339af0 0%, #228be6 100%);
        color: white;
    }
    .info-card h3, .info-card p, .info-card i {
        color: white;
    }
    
    .warning-card {
        background: linear-gradient(135deg, #ffd43b 0%, #fab005 100%);
        color: #212529;
    }
    .warning-card h3, .warning-card p, .warning-card i {
        color: #212529;
    }
    
    .danger-card {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
        color: white;
    }
    .danger-card h3, .danger-card p, .danger-card i {
        color: white;
    }
    
    .purple-card {
        background: linear-gradient(135deg, #845ec2 0%, #6c5ce7 100%);
        color: white;
    }
    .purple-card h3, .purple-card p, .purple-card i {
        color: white;
    }
    
    /* Fix card text contrast */
    .card {
        background-color: #ffffff;
        color: #212529;
    }
    .card-header {
        background-color: #f8f9fa;
        color: #212529;
        border-bottom: 1px solid #dee2e6;
    }
    .card-body {
        color: #212529;
    }
    
    /* Fix badge contrast */
    .badge {
        color: #ffffff;
    }
    .badge.bg-success {
        background-color: #198754 !important;
        color: #ffffff !important;
    }
    .badge.bg-warning {
        background-color: #ffc107 !important;
        color: #212529 !important;
    }
    .badge.bg-danger {
        background-color: #dc3545 !important;
        color: #ffffff !important;
    }
    .badge.bg-info {
        background-color: #0dcaf0 !important;
        color: #212529 !important;
    }
    .badge.bg-primary {
        background-color: #0d6efd !important;
        color: #ffffff !important;
    }
    
    /* Fix text muted contrast */
    .text-muted {
        color: #6c757d !important;
    }
    
    /* Timeline styles */
    .timeline {
        position: relative;
        padding-left: 30px;
    }
    .timeline::before {
        content: '';
        position: absolute;
        left: 15px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: #dee2e6;
    }
    .timeline-item {
        position: relative;
        margin-bottom: 20px;
    }
    .timeline-marker {
        position: absolute;
        left: -22px;
        top: 5px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        border: 2px solid #fff;
        box-shadow: 0 0 0 2px #dee2e6;
    }
    .timeline-content {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        border-left: 3px solid #0d6efd;
    }
</style>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <span class="badge me-2 bg-warning text-dark">
                    Section 2
                </span>
                Processing Operations
            </h1>
            <p class="text-muted mb-0">Manage food processing, drying, dehydration, and quality control</p>
        </div>
        <div class="btn-group" role="group">
            <a href="../processing/logs.php?section=2" class="btn btn-primary">
                <i class="fas fa-clipboard-list me-2"></i>Processing Logs
            </a>
            <a href="../inventory/index.php?section=2" class="btn btn-outline-primary">
                <i class="fas fa-boxes me-2"></i>Inventory
            </a>
            <a href="equipment.php" class="btn btn-outline-success">
                <i class="fas fa-cogs me-2"></i>Equipment
            </a>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stats-card primary-card">
                <div class="card-body text-center">
                    <i class="fas fa-cogs mb-2"></i>
                    <h3><?php echo number_format($processingStats['total_processes']); ?></h3>
                    <p>Total Processes</p>
                    <small class="text-white-50">Last 30 days</small>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stats-card success-card">
                <div class="card-body text-center">
                    <i class="fas fa-boxes mb-2"></i>
                    <h3><?php echo number_format($processingStats['total_batches']); ?></h3>
                    <p>Total Batches</p>
                    <small class="text-white-50">Processed</small>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stats-card info-card">
                <div class="card-body text-center">
                    <i class="fas fa-percentage mb-2"></i>
                    <h3><?php echo number_format($processingStats['avg_yield'], 1); ?>%</h3>
                    <p>Average Yield</p>
                    <small class="text-white-50">Efficiency</small>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stats-card <?php echo $processingStats['active_processes'] > 0 ? 'warning-card' : 'success-card'; ?>">
                <div class="card-body text-center">
                    <i class="fas fa-play mb-2"></i>
                    <h3><?php echo number_format($processingStats['active_processes']); ?></h3>
                    <p>Active Processes</p>
                    <small class="<?php echo $processingStats['active_processes'] > 0 ? 'text-dark' : 'text-white-50'; ?>">Running now</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Secondary Statistics -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stats-card purple-card">
                <div class="card-body text-center">
                    <i class="fas fa-arrow-down mb-2"></i>
                    <h3><?php echo number_format($processingStats['total_input'], 1); ?></h3>
                    <p>Total Input</p>
                    <small class="text-white-50">Kg processed</small>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stats-card success-card">
                <div class="card-body text-center">
                    <i class="fas fa-arrow-up mb-2"></i>
                    <h3><?php echo number_format($processingStats['total_output'], 1); ?></h3>
                    <p>Total Output</p>
                    <small class="text-white-50">Kg produced</small>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stats-card info-card">
                <div class="card-body text-center">
                    <i class="fas fa-clock mb-2"></i>
                    <h3><?php echo number_format($processingStats['avg_duration'], 0); ?></h3>
                    <p>Avg Duration</p>
                    <small class="text-white-50">Minutes</small>
                </div>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card stats-card danger-card">
                <div class="card-body text-center">
                    <i class="fas fa-trophy mb-2"></i>
                    <h3><?php echo number_format($processingStats['high_yield_processes']); ?></h3>
                    <p>High Yield</p>
                    <small class="text-white-50">≥90% efficiency</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Main Content Row -->
    <div class="row">
        <!-- Active Processes -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-play text-warning me-2"></i>Active Processes
                    </h5>
                    <span class="badge bg-warning text-dark"><?php echo count($activeProcesses); ?> running</span>
                </div>
                <div class="card-body">
                    <?php if (empty($activeProcesses)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-pause-circle fa-3x text-muted mb-3"></i>
                            <h6 class="text-muted">No Active Processes</h6>
                            <p class="text-muted">All processing operations are currently idle.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Batch ID</th>
                                        <th>Process Type</th>
                                        <th>Item</th>
                                        <th>Started</th>
                                        <th>Operator</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activeProcesses as $process): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($process['batch_id']); ?></strong></td>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?php echo ucfirst(str_replace('_', ' ', $process['process_type'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($process['item_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo timeAgo($process['start_time']); ?></td>
                                            <td><?php echo htmlspecialchars($process['operator_name'] ?? 'N/A'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
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
                        <i class="fas fa-chart-pie text-primary me-2"></i>Process Type Distribution
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($processTypes)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-chart-pie fa-3x text-muted mb-3"></i>
                            <h6 class="text-muted">No Process Data</h6>
                            <p class="text-muted">No processing data available for the last 30 days.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($processTypes as $type): ?>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <strong><?php echo ucfirst(str_replace('_', ' ', $type['process_type'])); ?></strong>
                                    <br><small class="text-muted">Avg Yield: <?php echo number_format($type['avg_yield'], 1); ?>%</small>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-primary"><?php echo number_format($type['count']); ?></span>
                                    <div class="progress mt-1" style="width: 100px; height: 6px;">
                                        <div class="progress-bar" style="width: <?php echo min(($type['count'] / max(array_column($processTypes, 'count'))) * 100, 100); ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
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
                    <h5 class="mb-0">
                        <i class="fas fa-history text-info me-2"></i>Recent Processing Activities
                    </h5>
                    <a href="../processing/logs.php?section=2" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body">
                    <?php if (empty($recentProcessing)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                            <h6 class="text-muted">No Recent Activities</h6>
                            <p class="text-muted">No processing activities recorded recently.</p>
                        </div>
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
                                                    <?php if ($process['duration_minutes']): ?>
                                                        | Duration: <?php echo number_format($process['duration_minutes'], 0); ?> min
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

// Update active processes every 30 seconds
setInterval(function() {
    if (document.visibilityState === 'visible' && <?php echo $processingStats['active_processes']; ?> > 0) {
        fetch(window.location.href)
            .then(response => response.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newActiveProcesses = doc.querySelector('.card:has(.fa-play)');
                const currentActiveProcesses = document.querySelector('.card:has(.fa-play)');
                if (newActiveProcesses && currentActiveProcesses) {
                    currentActiveProcesses.innerHTML = newActiveProcesses.innerHTML;
                }
            })
            .catch(error => console.log('Auto-refresh error:', error));
    }
}, 30000);
</script>

<?php include '../../includes/footer.php'; ?>
