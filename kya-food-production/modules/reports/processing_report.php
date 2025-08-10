<?php
/**
 * KYA Food Production - Processing Report
 * Comprehensive analytics and reporting for processing operations
 */

require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

SessionManager::start();
SessionManager::requireLogin();

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

// Get filter parameters
$section = $_GET['section'] ?? '';
$process_type = $_GET['process_type'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Build query conditions
$conditions = [];
$params = [];

if (!empty($section)) {
    $conditions[] = "pl.section = ?";
    $params[] = $section;
} elseif ($userInfo['role'] !== 'admin') {
    // For non-admin users, filter by their accessible sections
    $userSections = $userInfo['sections'] ?? [];
    if (!empty($userSections)) {
        $placeholders = str_repeat('?,', count($userSections) - 1) . '?';
        $conditions[] = "pl.section IN ($placeholders)";
        $params = array_merge($params, $userSections);
    }
}

if (!empty($process_type)) {
    $conditions[] = "pl.process_type = ?";
    $params[] = $process_type;
}

if (!empty($date_from)) {
    $conditions[] = "DATE(pl.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $conditions[] = "DATE(pl.created_at) <= ?";
    $params[] = $date_to;
}

$whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// Get key statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_processes,
        COUNT(DISTINCT pl.batch_id) as total_batches,
        AVG(pl.yield_percentage) as avg_yield,
        AVG(pl.duration_minutes) as avg_duration,
        AVG(pl.waste_quantity) as avg_waste,
        COUNT(CASE WHEN pl.end_time IS NULL THEN 1 END) as active_processes
    FROM processing_logs pl
    $whereClause
";

$statsStmt = $conn->prepare($statsQuery);
$statsStmt->execute($params);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get process type distribution
$processDistQuery = "
    SELECT 
        pl.process_type,
        COUNT(*) as count,
        AVG(pl.yield_percentage) as avg_yield
    FROM processing_logs pl
    $whereClause
    GROUP BY pl.process_type
    ORDER BY count DESC
";

$processDistStmt = $conn->prepare($processDistQuery);
$processDistStmt->execute($params);
$processDistribution = $processDistStmt->fetchAll(PDO::FETCH_ASSOC);

// Get daily processing trends
$trendsQuery = "
    SELECT 
        DATE(pl.created_at) as process_date,
        COUNT(*) as daily_processes,
        SUM(pl.input_quantity) as daily_input,
        SUM(pl.output_quantity) as daily_output,
        AVG(pl.yield_percentage) as daily_yield
    FROM processing_logs pl
    $whereClause
    GROUP BY DATE(pl.created_at)
    ORDER BY process_date DESC
    LIMIT 30
";

$trendsStmt = $conn->prepare($trendsQuery);
$trendsStmt->execute($params);
$trends = $trendsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get section performance
$sectionQuery = "
    SELECT 
        pl.section,
        COUNT(*) as total_processes,
        AVG(pl.yield_percentage) as avg_yield,
        AVG(pl.duration_minutes) as avg_duration,
        SUM(pl.input_quantity) as total_input,
        SUM(pl.output_quantity) as total_output
    FROM processing_logs pl
    $whereClause
    GROUP BY pl.section
    ORDER BY pl.section
";

$sectionStmt = $conn->prepare($sectionQuery);
$sectionStmt->execute($params);
$sectionPerformance = $sectionStmt->fetchAll(PDO::FETCH_ASSOC);

// Get top performing batches
$topBatchesQuery = "
    SELECT 
        pl.batch_id,
        pl.process_type,
        pl.yield_percentage,
        pl.input_quantity,
        pl.output_quantity,
        i.item_name,
        pl.created_at
    FROM processing_logs pl
    LEFT JOIN inventory i ON pl.item_id = i.id
    $whereClause
    ORDER BY pl.yield_percentage DESC
    LIMIT 10
";

$topBatchesStmt = $conn->prepare($topBatchesQuery);
$topBatchesStmt->execute($params);
$topBatches = $topBatchesStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Processing Report';
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
    
    /* Fix progress bars */
    .progress {
        background-color: #e9ecef;
    }
    .progress-bar {
        color: #ffffff;
    }
    
    /* Chart containers */
    .chart-container {
        position: relative;
        height: 400px;
        background-color: #ffffff;
        border-radius: 0.375rem;
        padding: 1rem;
    }
</style>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-industry text-primary me-2"></i>
                Processing Report
            </h1>
            <p class="text-muted mb-0">Comprehensive analytics and performance metrics</p>
        </div>
        <div>
            <button onclick="exportReport('pdf')" class="btn btn-outline-primary me-2">
                <i class="fas fa-file-pdf me-2"></i>Export PDF
            </button>
            <button onclick="exportReport('excel')" class="btn btn-outline-success">
                <i class="fas fa-file-excel me-2"></i>Export Excel
            </button>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
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
                
                <div class="col-md-3">
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
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary d-block w-100">
                        <i class="fas fa-search me-2"></i>Generate Report
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Overall Statistics -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card primary-card">
                <div class="stats-icon primary">
                    <i class="fas fa-cogs"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['total_processes']); ?></div>
                <div class="stats-label">Total Processes</div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card success-card">
                <div class="stats-icon success">
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['total_batches']); ?></div>
                <div class="stats-label">Total Batches</div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card info-card">
                <div class="stats-icon info">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['avg_yield'], 1); ?>%</div>
                <div class="stats-label">Avg Yield</div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card warning-card">
                <div class="stats-icon warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['avg_duration'], 0); ?></div>
                <div class="stats-label">Avg Duration (min)</div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card danger-card">
                <div class="stats-icon danger">
                    <i class="fas fa-trash"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['avg_waste'], 1); ?></div>
                <div class="stats-label">Avg Waste</div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card <?php echo $stats['active_processes'] > 0 ? 'danger-card' : 'success-card'; ?>">
                <div class="stats-icon <?php echo $stats['active_processes'] > 0 ? 'danger' : 'success'; ?>">
                    <i class="fas fa-play"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['active_processes']); ?></div>
                <div class="stats-label">Active Processes</div>
            </div>
        </div>
    </div>
    
    <!-- Charts Row -->
    <div class="row mb-4">
        <!-- Process Type Distribution -->
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-chart-pie me-2"></i>Process Type Distribution
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="processTypeChart" height="300"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Daily Processing Trends -->
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-chart-line me-2"></i>Daily Processing Trends (Last 30 Days)
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="dailyTrendsChart" height="300"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Section Performance -->
    <?php if (!empty($sectionPerformance)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-building me-2"></i>Section Performance Analysis
            </h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Section</th>
                            <th>Processes</th>
                            <th>Batches</th>
                            <th>Total Input</th>
                            <th>Total Output</th>
                            <th>Avg Yield</th>
                            <th>Avg Duration</th>
                            <th>Efficiency</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sectionPerformance as $stat): ?>
                            <tr>
                                <td>
                                    <strong>Section <?php echo $stat['section']; ?></strong>
                                    <br><small class="text-muted"><?php echo getSectionName($stat['section']); ?></small>
                                </td>
                                <td><?php echo number_format($stat['total_processes']); ?></td>
                                <td><?php echo number_format($stat['total_batches']); ?></td>
                                <td><?php echo number_format($stat['total_input'], 2); ?></td>
                                <td><?php echo number_format($stat['total_output'], 2); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $stat['avg_yield'] >= 90 ? 'success' : ($stat['avg_yield'] >= 75 ? 'warning' : 'danger'); ?>">
                                        <?php echo number_format($stat['avg_yield'], 1); ?>%
                                    </span>
                                </td>
                                <td><?php echo number_format($stat['avg_duration'], 0); ?> min</td>
                                <td>
                                    <?php 
                                    $efficiency = ($stat['total_output'] / max($stat['total_input'], 1)) * 100;
                                    ?>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-<?php echo $efficiency >= 90 ? 'success' : ($efficiency >= 75 ? 'warning' : 'danger'); ?>" 
                                             style="width: <?php echo min($efficiency, 100); ?>%">
                                            <?php echo number_format($efficiency, 1); ?>%
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Top Performing Batches -->
    <?php if (!empty($topBatches)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-trophy me-2"></i>Top Performing Batches
            </h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Batch ID</th>
                            <th>Process Type</th>
                            <th>Yield %</th>
                            <th>Input</th>
                            <th>Output</th>
                            <th>Duration</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topBatches as $batch): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($batch['batch_id']); ?></strong></td>
                                <td>
                                    <span class="badge bg-info">
                                        <?php echo ucfirst(str_replace('_', ' ', $batch['process_type'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-success">
                                        <?php echo number_format($batch['yield_percentage'], 1); ?>%
                                    </span>
                                </td>
                                <td><?php echo number_format($batch['input_quantity'], 2); ?></td>
                                <td><?php echo number_format($batch['output_quantity'], 2); ?></td>
                                <td><?php echo number_format($batch['duration_minutes'], 0); ?> min</td>
                                <td><?php echo formatDate($batch['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Process Type Distribution Chart
<?php if (!empty($processDistribution)): ?>
const processTypeCtx = document.getElementById('processTypeChart').getContext('2d');
new Chart(processTypeCtx, {
    type: 'doughnut',
    data: {
        labels: [<?php echo implode(',', array_map(function($p) { return "'" . ucfirst(str_replace('_', ' ', $p['process_type'])) . "'"; }, $processDistribution)); ?>],
        datasets: [{
            data: [<?php echo implode(',', array_column($processDistribution, 'count')); ?>],
            backgroundColor: ['#007bff', '#28a745', '#ffc107', '#dc3545', '#6f42c1'],
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
<?php endif; ?>

// Daily Trends Chart
<?php if (!empty($trends)): ?>
const dailyTrendsCtx = document.getElementById('dailyTrendsChart').getContext('2d');
new Chart(dailyTrendsCtx, {
    type: 'line',
    data: {
        labels: [<?php echo implode(',', array_map(function($t) { return "'" . date('M j', strtotime($t['process_date'])) . "'"; }, array_reverse($trends))); ?>],
        datasets: [{
            label: 'Daily Processes',
            data: [<?php echo implode(',', array_column(array_reverse($trends), 'daily_processes')); ?>],
            borderColor: '#007bff',
            backgroundColor: 'rgba(0, 123, 255, 0.1)',
            tension: 0.4
        }, {
            label: 'Avg Yield %',
            data: [<?php echo implode(',', array_column(array_reverse($trends), 'daily_yield')); ?>],
            borderColor: '#28a745',
            backgroundColor: 'rgba(40, 167, 69, 0.1)',
            tension: 0.4,
            yAxisID: 'y1'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                position: 'left'
            },
            y1: {
                type: 'linear',
                display: true,
                position: 'right',
                grid: {
                    drawOnChartArea: false,
                },
                beginAtZero: true,
                max: 100
            }
        }
    }
});
<?php endif; ?>

// Export functions
function exportReport(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    window.open('export_processing_report.php?' + params.toString(), '_blank');
}
</script>

<?php include '../../includes/footer.php'; ?>
