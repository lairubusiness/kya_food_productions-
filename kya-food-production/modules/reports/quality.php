<?php
session_start();
require_once '../../includes/config.php';
require_once '../../includes/SessionManager.php';

// Check if user is logged in and has access to reports
if (!SessionManager::isLoggedIn()) {
    header('Location: ../../login.php');
    exit();
}

if (!SessionManager::canAccessSection(7)) { // Section 7 for Reports
    header('Location: ../../dashboard.php');
    exit();
}

// Get filter parameters
$section_filter = isset($_GET['section']) ? $_GET['section'] : '';
$grade_filter = isset($_GET['grade']) ? $_GET['grade'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

try {
    // Build WHERE clause for filtering
    $whereConditions = [];
    $params = [];
    
    if (!empty($section_filter)) {
        $whereConditions[] = "qi.section = ?";
        $params[] = $section_filter;
    }
    
    if (!empty($grade_filter)) {
        $whereConditions[] = "qi.quality_grade = ?";
        $params[] = $grade_filter;
    }
    
    if (!empty($status_filter)) {
        $whereConditions[] = "qi.status = ?";
        $params[] = $status_filter;
    }
    
    if (!empty($date_from)) {
        $whereConditions[] = "DATE(qi.inspection_date) >= ?";
        $params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $whereConditions[] = "DATE(qi.inspection_date) <= ?";
        $params[] = $date_to;
    }
    
    $whereClause = '';
    if (!empty($whereConditions)) {
        $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    }
    
    // Get quality statistics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_inspections,
            COUNT(CASE WHEN quality_grade IN ('A+', 'A') THEN 1 END) as high_quality,
            COUNT(CASE WHEN quality_grade IN ('B+', 'B') THEN 1 END) as medium_quality,
            COUNT(CASE WHEN quality_grade IN ('C', 'D') THEN 1 END) as low_quality,
            COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_inspections,
            COUNT(CASE WHEN status = 'passed' THEN 1 END) as passed_inspections,
            ROUND(AVG(CASE 
                WHEN quality_grade = 'A+' THEN 100
                WHEN quality_grade = 'A' THEN 95
                WHEN quality_grade = 'B+' THEN 85
                WHEN quality_grade = 'B' THEN 75
                WHEN quality_grade = 'C' THEN 65
                WHEN quality_grade = 'D' THEN 55
                ELSE 50
            END), 1) as avg_quality_score,
            COUNT(CASE WHEN DATE(inspection_date) = CURDATE() THEN 1 END) as today_inspections
        FROM quality_inspections qi
        {$whereClause}
    ");
    $stmt->execute($params);
    $qualityStats = $stmt->fetch();
    
    // Calculate pass rate
    $passRate = $qualityStats['total_inspections'] > 0 ? 
        round(($qualityStats['passed_inspections'] / $qualityStats['total_inspections']) * 100, 1) : 0;
    
    // Get quality grade distribution
    $stmt = $conn->prepare("
        SELECT 
            quality_grade,
            COUNT(*) as count,
            ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM quality_inspections qi2 {$whereClause})), 1) as percentage
        FROM quality_inspections qi
        {$whereClause}
        GROUP BY quality_grade
        ORDER BY 
            CASE quality_grade
                WHEN 'A+' THEN 1
                WHEN 'A' THEN 2
                WHEN 'B+' THEN 3
                WHEN 'B' THEN 4
                WHEN 'C' THEN 5
                WHEN 'D' THEN 6
                ELSE 7
            END
    ");
    $stmt->execute($params);
    $gradeDistribution = $stmt->fetchAll();
    
    // Get section performance
    $stmt = $conn->prepare("
        SELECT 
            qi.section,
            COUNT(*) as total_inspections,
            COUNT(CASE WHEN status = 'passed' THEN 1 END) as passed,
            COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed,
            ROUND(AVG(CASE 
                WHEN quality_grade = 'A+' THEN 100
                WHEN quality_grade = 'A' THEN 95
                WHEN quality_grade = 'B+' THEN 85
                WHEN quality_grade = 'B' THEN 75
                WHEN quality_grade = 'C' THEN 65
                WHEN quality_grade = 'D' THEN 55
                ELSE 50
            END), 1) as avg_score,
            ROUND((COUNT(CASE WHEN status = 'passed' THEN 1 END) * 100.0 / COUNT(*)), 1) as pass_rate
        FROM quality_inspections qi
        {$whereClause}
        GROUP BY qi.section
        ORDER BY pass_rate DESC, avg_score DESC
    ");
    $stmt->execute($params);
    $sectionPerformance = $stmt->fetchAll();
    
    // Get recent failed inspections
    $stmt = $conn->prepare("
        SELECT 
            qi.id,
            qi.batch_id,
            qi.item_name,
            qi.section,
            qi.quality_grade,
            qi.inspection_date,
            qi.inspector_notes,
            u.full_name as inspector_name
        FROM quality_inspections qi
        LEFT JOIN users u ON qi.inspector_id = u.id
        {$whereClause}
        AND qi.status = 'failed'
        ORDER BY qi.inspection_date DESC
        LIMIT 20
    ");
    $stmt->execute($params);
    $failedInspections = $stmt->fetchAll();
    
    // Get daily inspection trends (last 30 days)
    $stmt = $conn->prepare("
        SELECT 
            DATE(inspection_date) as inspection_date,
            COUNT(*) as total_inspections,
            COUNT(CASE WHEN status = 'passed' THEN 1 END) as passed_inspections,
            COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_inspections,
            ROUND(AVG(CASE 
                WHEN quality_grade = 'A+' THEN 100
                WHEN quality_grade = 'A' THEN 95
                WHEN quality_grade = 'B+' THEN 85
                WHEN quality_grade = 'B' THEN 75
                WHEN quality_grade = 'C' THEN 65
                WHEN quality_grade = 'D' THEN 55
                ELSE 50
            END), 1) as avg_score
        FROM quality_inspections qi
        WHERE inspection_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(inspection_date)
        ORDER BY inspection_date ASC
    ");
    $stmt->execute();
    $dailyTrends = $stmt->fetchAll();
    
    // Get available sections for filter
    $stmt = $conn->prepare("SELECT DISTINCT section FROM quality_inspections WHERE section IS NOT NULL ORDER BY section");
    $stmt->execute();
    $availableSections = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch (Exception $e) {
    error_log("Quality reports error: " . $e->getMessage());
    $qualityStats = ['total_inspections' => 0, 'high_quality' => 0, 'medium_quality' => 0, 'low_quality' => 0, 'failed_inspections' => 0, 'passed_inspections' => 0, 'avg_quality_score' => 0, 'today_inspections' => 0];
    $passRate = 0;
    $gradeDistribution = [];
    $sectionPerformance = [];
    $failedInspections = [];
    $dailyTrends = [];
    $availableSections = [];
}

include '../../includes/header.php';
?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Quality Control Reports</h1>
            <p class="text-muted mb-0">Comprehensive quality analytics and inspection insights</p>
        </div>
        <div>
            <button class="btn btn-primary me-2" onclick="exportReport('pdf')">
                <i class="fas fa-file-pdf"></i> Export PDF
            </button>
            <button class="btn btn-success me-2" onclick="exportReport('excel')">
                <i class="fas fa-file-excel"></i> Export Excel
            </button>
            <button class="btn btn-info" onclick="exportReport('csv')">
                <i class="fas fa-file-csv"></i> Export CSV
            </button>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card stats-card border-left-primary">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Inspections</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($qualityStats['total_inspections']); ?></div>
                        </div>
                        <div class="text-primary">
                            <i class="fas fa-search fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stats-card border-left-success">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Pass Rate</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $passRate; ?>%</div>
                        </div>
                        <div class="text-success">
                            <i class="fas fa-check-circle fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stats-card border-left-info">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Avg Quality Score</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $qualityStats['avg_quality_score']; ?></div>
                        </div>
                        <div class="text-info">
                            <i class="fas fa-star fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stats-card border-left-warning">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">High Quality</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($qualityStats['high_quality']); ?></div>
                        </div>
                        <div class="text-warning">
                            <i class="fas fa-medal fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stats-card border-left-danger">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Failed Inspections</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($qualityStats['failed_inspections']); ?></div>
                        </div>
                        <div class="text-danger">
                            <i class="fas fa-times-circle fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card stats-card border-left-secondary">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">Today's Inspections</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($qualityStats['today_inspections']); ?></div>
                        </div>
                        <div class="text-secondary">
                            <i class="fas fa-calendar-day fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="m-0 font-weight-bold text-primary">Filters</h6>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Section</label>
                    <select name="section" class="form-select">
                        <option value="">All Sections</option>
                        <?php foreach ($availableSections as $section): ?>
                            <option value="<?php echo $section; ?>" <?php echo $section_filter == $section ? 'selected' : ''; ?>>
                                Section <?php echo $section; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Quality Grade</label>
                    <select name="grade" class="form-select">
                        <option value="">All Grades</option>
                        <option value="A+" <?php echo $grade_filter == 'A+' ? 'selected' : ''; ?>>A+</option>
                        <option value="A" <?php echo $grade_filter == 'A' ? 'selected' : ''; ?>>A</option>
                        <option value="B+" <?php echo $grade_filter == 'B+' ? 'selected' : ''; ?>>B+</option>
                        <option value="B" <?php echo $grade_filter == 'B' ? 'selected' : ''; ?>>B</option>
                        <option value="C" <?php echo $grade_filter == 'C' ? 'selected' : ''; ?>>C</option>
                        <option value="D" <?php echo $grade_filter == 'D' ? 'selected' : ''; ?>>D</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="passed" <?php echo $status_filter == 'passed' ? 'selected' : ''; ?>>Passed</option>
                        <option value="failed" <?php echo $status_filter == 'failed' ? 'selected' : ''; ?>>Failed</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">From Date</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To Date</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">Apply Filters</button>
                    <a href="quality.php" class="btn btn-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row mb-4">
        <!-- Quality Grade Distribution -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">Quality Grade Distribution</h6>
                </div>
                <div class="card-body">
                    <canvas id="gradeChart" width="400" height="200"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Daily Quality Trends -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">Daily Quality Trends (Last 30 Days)</h6>
                </div>
                <div class="card-body">
                    <canvas id="trendsChart" width="400" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Section Performance -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">Section Performance Analysis</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Section</th>
                                    <th>Total Inspections</th>
                                    <th>Passed</th>
                                    <th>Failed</th>
                                    <th>Pass Rate</th>
                                    <th>Avg Score</th>
                                    <th>Performance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sectionPerformance as $section): ?>
                                <tr>
                                    <td><strong>Section <?php echo $section['section']; ?></strong></td>
                                    <td><?php echo number_format($section['total_inspections']); ?></td>
                                    <td><span class="badge bg-success"><?php echo number_format($section['passed']); ?></span></td>
                                    <td><span class="badge bg-danger"><?php echo number_format($section['failed']); ?></span></td>
                                    <td><?php echo $section['pass_rate']; ?>%</td>
                                    <td><?php echo $section['avg_score']; ?></td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar <?php echo $section['pass_rate'] >= 90 ? 'bg-success' : ($section['pass_rate'] >= 70 ? 'bg-warning' : 'bg-danger'); ?>" 
                                                 style="width: <?php echo $section['pass_rate']; ?>%">
                                                <?php echo $section['pass_rate']; ?>%
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
        </div>
    </div>

    <!-- Recent Failed Inspections -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">Recent Failed Inspections</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Batch ID</th>
                                    <th>Item</th>
                                    <th>Section</th>
                                    <th>Grade</th>
                                    <th>Inspection Date</th>
                                    <th>Inspector</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($failedInspections)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">No failed inspections found</td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($failedInspections as $inspection): ?>
                                    <tr class="table-danger">
                                        <td><strong><?php echo htmlspecialchars($inspection['batch_id']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($inspection['item_name']); ?></td>
                                        <td>Section <?php echo $inspection['section']; ?></td>
                                        <td>
                                            <span class="badge bg-danger"><?php echo $inspection['quality_grade']; ?></span>
                                        </td>
                                        <td><?php echo formatDateTime($inspection['inspection_date']); ?></td>
                                        <td><?php echo htmlspecialchars($inspection['inspector_name'] ?? 'Unknown'); ?></td>
                                        <td><?php echo htmlspecialchars($inspection['inspector_notes'] ?? '-'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Auto-refresh every 5 minutes
setTimeout(function() {
    location.reload();
}, 300000);

// Quality Grade Distribution Chart
const gradeCtx = document.getElementById('gradeChart').getContext('2d');
const gradeChart = new Chart(gradeCtx, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_column($gradeDistribution, 'quality_grade')); ?>,
        datasets: [{
            data: <?php echo json_encode(array_column($gradeDistribution, 'count')); ?>,
            backgroundColor: [
                '#28a745', // A+ - Green
                '#20c997', // A - Teal
                '#ffc107', // B+ - Yellow
                '#fd7e14', // B - Orange
                '#dc3545', // C - Red
                '#6c757d'  // D - Gray
            ]
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Daily Trends Chart
const trendsCtx = document.getElementById('trendsChart').getContext('2d');
const trendsChart = new Chart(trendsCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_column($dailyTrends, 'inspection_date')); ?>,
        datasets: [{
            label: 'Total Inspections',
            data: <?php echo json_encode(array_column($dailyTrends, 'total_inspections')); ?>,
            borderColor: '#007bff',
            backgroundColor: 'rgba(0, 123, 255, 0.1)',
            yAxisID: 'y'
        }, {
            label: 'Average Score',
            data: <?php echo json_encode(array_column($dailyTrends, 'avg_score')); ?>,
            borderColor: '#28a745',
            backgroundColor: 'rgba(40, 167, 69, 0.1)',
            yAxisID: 'y1'
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                type: 'linear',
                display: true,
                position: 'left',
                title: {
                    display: true,
                    text: 'Number of Inspections'
                }
            },
            y1: {
                type: 'linear',
                display: true,
                position: 'right',
                title: {
                    display: true,
                    text: 'Average Score'
                },
                grid: {
                    drawOnChartArea: false,
                }
            }
        }
    }
});

// Export functions
function exportReport(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    
    // In a real implementation, this would trigger a server-side export
    alert('Export functionality would generate ' + format.toUpperCase() + ' report with current filters');
    
    // Example of what the actual implementation might look like:
    // window.location.href = 'quality.php?' + params.toString();
}
</script>

<?php include '../../includes/footer.php'; ?>
