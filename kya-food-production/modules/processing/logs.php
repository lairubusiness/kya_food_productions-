<?php
/**
 * KYA Food Production - Processing Logs
 * Display and manage processing logs with filtering and search capabilities
 */

require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

SessionManager::start();
SessionManager::requireLogin();

// Check if user has processing permissions
if (!SessionManager::hasPermission('processing_manage') && !SessionManager::hasPermission('processing_view')) {
    header('Location: ../../dashboard.php?error=access_denied');
    exit();
}

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

// Get filter parameters
$section = $_GET['section'] ?? ($userInfo['role'] === 'admin' ? '' : $userInfo['section']);
$process_type = $_GET['process_type'] ?? '';
$batch_id = $_GET['batch_id'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

// Build query conditions
$conditions = [];
$params = [];

if (!empty($section)) {
    $conditions[] = "pl.section = ?";
    $params[] = $section;
} elseif ($userInfo['role'] !== 'admin') {
    $conditions[] = "pl.section = ?";
    $params[] = $userInfo['section'];
}

if (!empty($process_type)) {
    $conditions[] = "pl.process_type = ?";
    $params[] = $process_type;
}

if (!empty($batch_id)) {
    $conditions[] = "pl.batch_id LIKE ?";
    $params[] = "%$batch_id%";
}

if (!empty($date_from)) {
    $conditions[] = "DATE(pl.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $conditions[] = "DATE(pl.created_at) <= ?";
    $params[] = $date_to;
}

if (!empty($search)) {
    $conditions[] = "(pl.batch_id LIKE ? OR pl.notes LIKE ? OR i.item_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get summary statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_logs,
        COUNT(DISTINCT pl.batch_id) as total_batches,
        SUM(pl.input_quantity) as total_input,
        SUM(pl.output_quantity) as total_output,
        AVG(pl.yield_percentage) as avg_yield,
        COUNT(CASE WHEN pl.end_time IS NULL THEN 1 END) as active_processes
    FROM processing_logs pl
    LEFT JOIN inventory i ON pl.item_id = i.id
    $whereClause
";

$statsStmt = $conn->prepare($statsQuery);
$statsStmt->execute($params);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get processing logs
$logsQuery = "
    SELECT 
        pl.*,
        i.item_name,
        i.item_code,
        u1.username as operator_name,
        u2.username as supervisor_name
    FROM processing_logs pl
    LEFT JOIN inventory i ON pl.item_id = i.id
    LEFT JOIN users u1 ON pl.operator_id = u1.id
    LEFT JOIN users u2 ON pl.supervisor_id = u2.id
    $whereClause
    ORDER BY pl.created_at DESC
    LIMIT 100
";

$logsStmt = $conn->prepare($logsQuery);
$logsStmt->execute($params);
$logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Processing Logs';
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
    
    /* Fix table text contrast */
    .table-dark {
        background-color: #212529;
        color: #ffffff;
    }
    .table-dark th,
    .table-dark td {
        color: #ffffff;
        border-color: #454d55;
    }
    .table-dark .text-muted {
        color: #adb5bd !important;
    }
    
    /* Fix filter section contrast */
    .bg-light {
        background-color: #f8f9fa !important;
        color: #212529 !important;
    }
    .bg-light .form-label,
    .bg-light .form-control,
    .bg-light .form-select {
        color: #212529 !important;
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
    
    /* Fix no data message */
    .no-data {
        background-color: #f8f9fa;
        color: #6c757d;
        padding: 3rem;
        text-align: center;
        border-radius: 0.375rem;
    }
    
    /* Fix progress bars */
    .progress {
        background-color: #e9ecef;
    }
    .progress-bar {
        color: #ffffff;
    }
</style>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Processing Logs</h1>
            <p class="text-muted mb-0">Monitor and track processing activities across all sections</p>
        </div>
        <div>
            <?php if (SessionManager::hasPermission('processing_manage')): ?>
                <a href="add_log.php" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>Add New Log
                </a>
            <?php endif; ?>
            <button onclick="exportData('csv')" class="btn btn-outline-secondary">
                <i class="fas fa-download me-2"></i>Export CSV
            </button>
        </div>
    </div>
    
    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card primary-card">
                <div class="stats-icon primary">
                    <i class="fas fa-list"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['total_logs']); ?></div>
                <div class="stats-label">Total Logs</div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card info-card">
                <div class="stats-icon info">
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['total_batches']); ?></div>
                <div class="stats-label">Total Batches</div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card success-card">
                <div class="stats-icon success">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['total_input'], 1); ?></div>
                <div class="stats-label">Total Input</div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card warning-card">
                <div class="stats-icon warning">
                    <i class="fas fa-arrow-up"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['total_output'], 1); ?></div>
                <div class="stats-label">Total Output</div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card secondary-card">
                <div class="stats-icon secondary">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['avg_yield'], 1); ?>%</div>
                <div class="stats-label">Avg Yield</div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card <?php echo $stats['active_processes'] > 0 ? 'danger-card' : 'success-card'; ?>">
                <div class="stats-icon <?php echo $stats['active_processes'] > 0 ? 'danger' : 'success'; ?>">
                    <i class="fas fa-cog"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['active_processes']); ?></div>
                <div class="stats-label">Active Processes</div>
            </div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body bg-light">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label for="section" class="form-label">Section</label>
                    <select name="section" id="section" class="form-select">
                        <option value="">All Sections</option>
                        <?php if ($userInfo['role'] === 'admin' || in_array(1, $userInfo['sections'])): ?>
                            <option value="1" <?php echo $section == '1' ? 'selected' : ''; ?>>Section 1 - Raw Material</option>
                        <?php endif; ?>
                        <?php if ($userInfo['role'] === 'admin' || in_array(2, $userInfo['sections'])): ?>
                            <option value="2" <?php echo $section == '2' ? 'selected' : ''; ?>>Section 2 - Processing</option>
                        <?php endif; ?>
                        <?php if ($userInfo['role'] === 'admin' || in_array(3, $userInfo['sections'])): ?>
                            <option value="3" <?php echo $section == '3' ? 'selected' : ''; ?>>Section 3 - Packaging</option>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="process_type" class="form-label">Process Type</label>
                    <select name="process_type" id="process_type" class="form-select">
                        <option value="">All Types</option>
                        <option value="drying" <?php echo $process_type == 'drying' ? 'selected' : ''; ?>>Drying</option>
                        <option value="dehydration" <?php echo $process_type == 'dehydration' ? 'selected' : ''; ?>>Dehydration</option>
                        <option value="packaging" <?php echo $process_type == 'packaging' ? 'selected' : ''; ?>>Packaging</option>
                        <option value="quality_check" <?php echo $process_type == 'quality_check' ? 'selected' : ''; ?>>Quality Check</option>
                        <option value="storage" <?php echo $process_type == 'storage' ? 'selected' : ''; ?>>Storage</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="batch_id" class="form-label">Batch ID</label>
                    <input type="text" name="batch_id" id="batch_id" class="form-control" 
                           value="<?php echo htmlspecialchars($batch_id); ?>" placeholder="Search batch">
                </div>
                
                <div class="col-md-2">
                    <label for="date_from" class="form-label">From Date</label>
                    <input type="date" name="date_from" id="date_from" class="form-control" 
                           value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                
                <div class="col-md-2">
                    <label for="date_to" class="form-label">To Date</label>
                    <input type="date" name="date_to" id="date_to" class="form-control" 
                           value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                
                <div class="col-md-2">
                    <label for="search" class="form-label">Search</label>
                    <div class="input-group">
                        <input type="text" name="search" id="search" class="form-control" 
                               value="<?php echo htmlspecialchars($search); ?>" placeholder="Search logs...">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Processing Logs Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-clipboard-list me-2"></i>Processing Logs
                <span class="badge bg-primary ms-2"><?php echo count($logs); ?> records</span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (!empty($logs)): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Batch ID</th>
                                <th>Process Type</th>
                                <th>Item</th>
                                <th>Input/Output</th>
                                <th>Yield %</th>
                                <th>Duration</th>
                                <th>Temperature</th>
                                <th>Quality</th>
                                <th>Operator</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($log['batch_id']); ?></strong>
                                        <?php if ($log['process_stage']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($log['process_stage']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo ucfirst(str_replace('_', ' ', $log['process_type'])); ?>
                                        </span>
                                        <br><small class="text-muted">Section <?php echo $log['section']; ?></small>
                                    </td>
                                    <td>
                                        <?php if ($log['item_name']): ?>
                                            <strong><?php echo htmlspecialchars($log['item_name']); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($log['item_code']); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex justify-content-between">
                                            <span class="text-success">In: <?php echo number_format($log['input_quantity'], 2); ?></span>
                                            <?php if ($log['output_quantity']): ?>
                                                <span class="text-primary">Out: <?php echo number_format($log['output_quantity'], 2); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($log['waste_quantity'] > 0): ?>
                                            <small class="text-danger">Waste: <?php echo number_format($log['waste_quantity'], 2); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($log['yield_percentage']): ?>
                                            <span class="badge bg-<?php echo $log['yield_percentage'] >= 90 ? 'success' : ($log['yield_percentage'] >= 75 ? 'warning' : 'danger'); ?>">
                                                <?php echo number_format($log['yield_percentage'], 1); ?>%
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($log['start_time'] && $log['end_time']): ?>
                                            <?php 
                                            $duration = (strtotime($log['end_time']) - strtotime($log['start_time'])) / 60;
                                            echo number_format($duration, 0) . ' min';
                                            ?>
                                        <?php elseif ($log['start_time']): ?>
                                            <span class="badge bg-warning">In Progress</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($log['temperature_start'] || $log['temperature_end']): ?>
                                            <small>
                                                <?php if ($log['temperature_start']): ?>
                                                    Start: <?php echo $log['temperature_start']; ?>°C<br>
                                                <?php endif; ?>
                                                <?php if ($log['temperature_end']): ?>
                                                    End: <?php echo $log['temperature_end']; ?>°C
                                                <?php endif; ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($log['quality_grade_input'] || $log['quality_grade_output']): ?>
                                            <small>
                                                <?php if ($log['quality_grade_input']): ?>
                                                    In: <span class="badge bg-secondary"><?php echo $log['quality_grade_input']; ?></span><br>
                                                <?php endif; ?>
                                                <?php if ($log['quality_grade_output']): ?>
                                                    Out: <span class="badge bg-secondary"><?php echo $log['quality_grade_output']; ?></span>
                                                <?php endif; ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($log['operator_name']): ?>
                                            <small>
                                                <strong><?php echo htmlspecialchars($log['operator_name']); ?></strong>
                                                <?php if ($log['supervisor_name']): ?>
                                                    <br>Sup: <?php echo htmlspecialchars($log['supervisor_name']); ?>
                                                <?php endif; ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$log['end_time'] && $log['start_time']): ?>
                                            <span class="badge bg-warning">Active</span>
                                        <?php elseif ($log['end_time']): ?>
                                            <span class="badge bg-success">Completed</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Pending</span>
                                        <?php endif; ?>
                                        <br><small class="text-muted"><?php echo formatDateTime($log['created_at']); ?></small>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="view_log.php?id=<?php echo $log['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if (SessionManager::hasPermission('processing_manage')): ?>
                                                <a href="edit_log.php?id=<?php echo $log['id']; ?>" 
                                                   class="btn btn-sm btn-outline-secondary" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
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
                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No Processing Logs Found</h5>
                    <p class="text-muted">No processing logs match your current filters.</p>
                    <?php if (SessionManager::hasPermission('processing_manage')): ?>
                        <a href="add_log.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>Add First Log
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function exportData(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    window.location.href = 'export_logs.php?' + params.toString();
}

// Auto-refresh every 2 minutes for active processes
setInterval(function() {
    if (document.visibilityState === 'visible') {
        const activeProcesses = <?php echo $stats['active_processes']; ?>;
        if (activeProcesses > 0) {
            location.reload();
        }
    }
}, 120000);

// Set default date range to last 30 days if no filters are set
document.addEventListener('DOMContentLoaded', function() {
    const dateFrom = document.getElementById('date_from');
    const dateTo = document.getElementById('date_to');
    
    if (!dateFrom.value && !dateTo.value && !window.location.search.includes('date_')) {
        const today = new Date();
        const thirtyDaysAgo = new Date(today.getTime() - (30 * 24 * 60 * 60 * 1000));
        
        dateTo.value = today.toISOString().split('T')[0];
        dateFrom.value = thirtyDaysAgo.toISOString().split('T')[0];
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
