<?php
/**
 * KYA Food Production - Production Reports
 * Comprehensive production analytics and reporting
 */

require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

SessionManager::start();
SessionManager::requireLogin();

// Check access permissions
if (!SessionManager::canAccessSection('reports')) {
    header('Location: ../../dashboard.php?error=access_denied');
    exit();
}

$userInfo = SessionManager::getUserInfo();
$userRole = $_SESSION['role'] ?? '';
$userSections = $userInfo['sections'] ?? [];

$db = new Database();
$conn = $db->connect();

$pageTitle = "Production Reports";

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$section = $_GET['section'] ?? '';
$processType = $_GET['process_type'] ?? '';

// Handle export
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    // Export functionality will be implemented here
    header('Content-Type: application/json');
    echo json_encode(['message' => 'Export functionality will be implemented for ' . $exportType]);
    exit();
}

try {
    // Build WHERE clause based on filters and permissions
    $whereConditions = ["1=1"];
    $params = [];
    
    if ($dateFrom) {
        $whereConditions[] = "DATE(created_at) >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $whereConditions[] = "DATE(created_at) <= ?";
        $params[] = $dateTo;
    }
    
    if ($section && ($userRole === 'admin' || in_array($section, $userSections))) {
        $whereConditions[] = "section = ?";
        $params[] = $section;
    } elseif ($userRole !== 'admin' && !empty($userSections)) {
        $whereConditions[] = "section IN (" . implode(',', array_map('intval', $userSections)) . ")";
    }
    
    if ($processType) {
        $whereConditions[] = "process_type = ?";
        $params[] = $processType;
    }
    
    $whereClause = "WHERE " . implode(" AND ", $whereConditions);
    
    // Get production statistics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_processes,
            COUNT(DISTINCT batch_id) as total_batches,
            AVG(CASE WHEN output_quantity > 0 AND input_quantity > 0 THEN (output_quantity / input_quantity) * 100 END) as avg_yield,
            AVG(CASE WHEN duration_minutes > 0 THEN duration_minutes END) as avg_duration,
            SUM(CASE WHEN waste_quantity IS NOT NULL THEN waste_quantity ELSE 0 END) as total_waste,
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active_processes
        FROM processing_logs 
        {$whereClause}
    ");
    $stmt->execute($params);
    $productionStats = $stmt->fetch();
    
    // Get process type distribution
    $stmt = $conn->prepare("
        SELECT 
            process_type,
            COUNT(*) as process_count,
            AVG(CASE WHEN output_quantity > 0 AND input_quantity > 0 THEN (output_quantity / input_quantity) * 100 END) as avg_yield
        FROM processing_logs 
        {$whereClause}
        GROUP BY process_type
        ORDER BY process_count DESC
    ");
    $stmt->execute($params);
    $processTypes = $stmt->fetchAll();
    
    // Get daily production trends
    $stmt = $conn->prepare("
        SELECT 
            DATE(created_at) as production_date,
            COUNT(*) as daily_processes,
            COUNT(DISTINCT batch_id) as daily_batches,
            SUM(input_quantity) as daily_input,
            SUM(output_quantity) as daily_output,
            AVG(CASE WHEN output_quantity > 0 AND input_quantity > 0 THEN (output_quantity / input_quantity) * 100 END) as daily_yield
        FROM processing_logs 
        {$whereClause}
        GROUP BY DATE(created_at)
        ORDER BY production_date DESC
        LIMIT 30
    ");
    $stmt->execute($params);
    $dailyTrends = $stmt->fetchAll();
    
    // Get section performance (if user has access)
    $sectionPerformance = [];
    if ($userRole === 'admin' || count($userSections) > 1) {
        $sectionWhereClause = $whereClause;
        if ($userRole !== 'admin' && !empty($userSections)) {
            $sectionWhereClause .= " AND section IN (" . implode(',', array_map('intval', $userSections)) . ")";
        }
        
        $stmt = $conn->prepare("
            SELECT 
                section,
                COUNT(*) as processes,
                COUNT(DISTINCT batch_id) as batches,
                SUM(input_quantity) as total_input,
                SUM(output_quantity) as total_output,
                AVG(CASE WHEN output_quantity > 0 AND input_quantity > 0 THEN (output_quantity / input_quantity) * 100 END) as avg_yield,
                AVG(duration_minutes) as avg_duration,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) / COUNT(*) * 100 as completion_rate
            FROM processing_logs 
            {$sectionWhereClause}
            GROUP BY section
            ORDER BY total_output DESC
        ");
        $stmt->execute($params);
        $sectionPerformance = $stmt->fetchAll();
    }
    
    // Get top performing batches
    $stmt = $conn->prepare("
        SELECT 
            batch_id,
            process_type,
            section,
            input_quantity,
            output_quantity,
            CASE WHEN output_quantity > 0 AND input_quantity > 0 THEN (output_quantity / input_quantity) * 100 ELSE 0 END as yield_percentage,
            duration_minutes,
            quality_grade,
            created_at
        FROM processing_logs 
        {$whereClause}
        AND output_quantity > 0 AND input_quantity > 0
        ORDER BY (output_quantity / input_quantity) DESC
        LIMIT 10
    ");
    $stmt->execute($params);
    $topBatches = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Production reports error: " . $e->getMessage());
    $productionStats = ['total_processes' => 0, 'total_batches' => 0, 'avg_yield' => 0, 'avg_duration' => 0, 'total_waste' => 0, 'active_processes' => 0];
    $processTypes = [];
    $dailyTrends = [];
    $sectionPerformance = [];
    $topBatches = [];
}

include '../../includes/header.php';
?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Production Reports</h1>
            <p class="text-muted mb-0">Comprehensive production analytics and performance metrics</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-arrow-left me-2"></i>Reports Dashboard
            </a>
            <div class="btn-group">
                <button type="button" class="btn btn-success dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fas fa-download me-2"></i>Export
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="?export=pdf&<?php echo http_build_query($_GET); ?>">
                        <i class="fas fa-file-pdf me-2"></i>PDF Report
                    </a></li>
                    <li><a class="dropdown-item" href="?export=excel&<?php echo http_build_query($_GET); ?>">
                        <i class="fas fa-file-excel me-2"></i>Excel Report
                    </a></li>
                    <li><a class="dropdown-item" href="?export=csv&<?php echo http_build_query($_GET); ?>">
                        <i class="fas fa-file-csv me-2"></i>CSV Data
                    </a></li>
                </ul>
            </div>
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
                <?php if ($userRole === 'admin' || count($userSections) > 1): ?>
                <div class="col-md-2">
                    <label for="section" class="form-label">Section</label>
                    <select class="form-select" id="section" name="section">
                        <option value="">All Sections</option>
                        <?php 
                        $availableSections = $userRole === 'admin' ? [1, 2, 3] : $userSections;
                        foreach ($availableSections as $sec): ?>
                            <option value="<?php echo $sec; ?>" <?php echo $section == $sec ? 'selected' : ''; ?>>
                                Section <?php echo $sec; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-2">
                    <label for="process_type" class="form-label">Process Type</label>
                    <select class="form-select" id="process_type" name="process_type">
                        <option value="">All Types</option>
                        <option value="drying" <?php echo $processType === 'drying' ? 'selected' : ''; ?>>Drying</option>
                        <option value="dehydration" <?php echo $processType === 'dehydration' ? 'selected' : ''; ?>>Dehydration</option>
                        <option value="packaging" <?php echo $processType === 'packaging' ? 'selected' : ''; ?>>Packaging</option>
                        <option value="quality_check" <?php echo $processType === 'quality_check' ? 'selected' : ''; ?>>Quality Check</option>
                        <option value="storage" <?php echo $processType === 'storage' ? 'selected' : ''; ?>>Storage</option>
                    </select>
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

    <!-- Production Statistics -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card primary">
                <div class="stats-icon primary">
                    <i class="fas fa-cogs"></i>
                </div>
                <div class="stats-number"><?php echo number_format($productionStats['total_processes']); ?></div>
                <div class="stats-label">Total Processes</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card info">
                <div class="stats-icon info">
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="stats-number"><?php echo number_format($productionStats['total_batches']); ?></div>
                <div class="stats-label">Total Batches</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card success">
                <div class="stats-icon success">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stats-number"><?php echo number_format($productionStats['avg_yield'], 1); ?>%</div>
                <div class="stats-label">Average Yield</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card warning">
                <div class="stats-icon warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-number"><?php echo number_format($productionStats['avg_duration'], 0); ?></div>
                <div class="stats-label">Avg Duration (min)</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card danger">
                <div class="stats-icon danger">
                    <i class="fas fa-trash"></i>
                </div>
                <div class="stats-number"><?php echo number_format($productionStats['total_waste'], 1); ?></div>
                <div class="stats-label">Total Waste</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card secondary">
                <div class="stats-icon secondary">
                    <i class="fas fa-play"></i>
                </div>
                <div class="stats-number"><?php echo number_format($productionStats['active_processes']); ?></div>
                <div class="stats-label">Active Processes</div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-chart-pie me-2"></i>Process Type Distribution
                    </h6>
                </div>
                <div class="card-body">
                    <canvas id="processTypeChart" width="400" height="300"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-chart-line me-2"></i>Daily Production Trends
                    </h6>
                </div>
                <div class="card-body">
                    <canvas id="dailyTrendsChart" width="400" height="300"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Section Performance (if applicable) -->
    <?php if (!empty($sectionPerformance)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-chart-bar me-2"></i>Section Performance Analysis
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
                            <th>Input/Output</th>
                            <th>Yield</th>
                            <th>Avg Duration</th>
                            <th>Completion Rate</th>
                            <th>Efficiency</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sectionPerformance as $section): ?>
                        <tr>
                            <td><strong>Section <?php echo $section['section']; ?></strong></td>
                            <td><?php echo number_format($section['processes']); ?></td>
                            <td><?php echo number_format($section['batches']); ?></td>
                            <td>
                                <small class="text-muted">In:</small> <?php echo number_format($section['total_input'], 1); ?><br>
                                <small class="text-muted">Out:</small> <?php echo number_format($section['total_output'], 1); ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $section['avg_yield'] >= 90 ? 'bg-success' : ($section['avg_yield'] >= 75 ? 'bg-warning' : 'bg-danger'); ?>">
                                    <?php echo number_format($section['avg_yield'], 1); ?>%
                                </span>
                            </td>
                            <td><?php echo number_format($section['avg_duration'], 0); ?> min</td>
                            <td>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-info" style="width: <?php echo $section['completion_rate']; ?>%">
                                        <?php echo number_format($section['completion_rate'], 1); ?>%
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php 
                                $efficiency = ($section['avg_yield'] * $section['completion_rate']) / 100;
                                $efficiencyClass = $efficiency >= 80 ? 'success' : ($efficiency >= 60 ? 'warning' : 'danger');
                                ?>
                                <span class="badge bg-<?php echo $efficiencyClass; ?>">
                                    <?php echo number_format($efficiency, 1); ?>%
                                </span>
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
    <div class="card">
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
                            <th>Section</th>
                            <th>Input/Output</th>
                            <th>Yield</th>
                            <th>Duration</th>
                            <th>Quality</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topBatches as $batch): ?>
                        <tr>
                            <td><strong><?php echo $batch['batch_id']; ?></strong></td>
                            <td>
                                <span class="badge bg-secondary"><?php echo ucfirst($batch['process_type']); ?></span>
                            </td>
                            <td>Section <?php echo $batch['section']; ?></td>
                            <td>
                                <small class="text-muted">In:</small> <?php echo number_format($batch['input_quantity'], 1); ?><br>
                                <small class="text-muted">Out:</small> <?php echo number_format($batch['output_quantity'], 1); ?>
                            </td>
                            <td>
                                <span class="badge bg-success">
                                    <?php echo number_format($batch['yield_percentage'], 1); ?>%
                                </span>
                            </td>
                            <td><?php echo number_format($batch['duration_minutes']); ?> min</td>
                            <td>
                                <?php if ($batch['quality_grade']): ?>
                                    <span class="badge <?php echo $batch['quality_grade'] === 'A+' ? 'bg-success' : ($batch['quality_grade'] === 'A' ? 'bg-info' : 'bg-warning'); ?>">
                                        <?php echo $batch['quality_grade']; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
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
<?php if (!empty($processTypes)): ?>
const processTypeCtx = document.getElementById('processTypeChart').getContext('2d');
new Chart(processTypeCtx, {
    type: 'doughnut',
    data: {
        labels: [<?php echo '"' . implode('","', array_map('ucfirst', array_column($processTypes, 'process_type'))) . '"'; ?>],
        datasets: [{
            data: [<?php echo implode(',', array_column($processTypes, 'process_count')); ?>],
            backgroundColor: [
                '#007bff', '#28a745', '#ffc107', '#dc3545', '#17a2b8',
                '#6f42c1', '#fd7e14', '#20c997', '#e83e8c', '#6c757d'
            ],
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

// Daily Production Trends Chart
<?php if (!empty($dailyTrends)): ?>
const dailyTrendsCtx = document.getElementById('dailyTrendsChart').getContext('2d');
new Chart(dailyTrendsCtx, {
    type: 'line',
    data: {
        labels: [<?php echo '"' . implode('","', array_reverse(array_column($dailyTrends, 'production_date'))) . '"'; ?>],
        datasets: [{
            label: 'Daily Processes',
            data: [<?php echo implode(',', array_reverse(array_column($dailyTrends, 'daily_processes'))); ?>],
            borderColor: '#007bff',
            backgroundColor: 'rgba(0, 123, 255, 0.1)',
            tension: 0.4,
            yAxisID: 'y'
        }, {
            label: 'Daily Yield (%)',
            data: [<?php echo implode(',', array_reverse(array_column($dailyTrends, 'daily_yield'))); ?>],
            borderColor: '#28a745',
            backgroundColor: 'rgba(40, 167, 69, 0.1)',
            tension: 0.4,
            yAxisID: 'y1'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            mode: 'index',
            intersect: false,
        },
        scales: {
            x: {
                display: true,
                title: {
                    display: true,
                    text: 'Date'
                }
            },
            y: {
                type: 'linear',
                display: true,
                position: 'left',
                title: {
                    display: true,
                    text: 'Number of Processes'
                }
            },
            y1: {
                type: 'linear',
                display: true,
                position: 'right',
                title: {
                    display: true,
                    text: 'Yield Percentage (%)'
                },
                grid: {
                    drawOnChartArea: false,
                }
            }
        }
    }
});
<?php endif; ?>

// Auto-refresh every 5 minutes
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 300000);
</script>

<?php include '../../includes/footer.php'; ?>
