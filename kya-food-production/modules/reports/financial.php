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
$period_filter = isset($_GET['period']) ? $_GET['period'] : 'current_month';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

try {
    // Build WHERE clause for filtering
    $whereConditions = [];
    $params = [];
    
    // Set date range based on period filter
    switch ($period_filter) {
        case 'today':
            $date_from = date('Y-m-d');
            $date_to = date('Y-m-d');
            break;
        case 'this_week':
            $date_from = date('Y-m-d', strtotime('monday this week'));
            $date_to = date('Y-m-d');
            break;
        case 'current_month':
            $date_from = date('Y-m-01');
            $date_to = date('Y-m-d');
            break;
        case 'last_month':
            $date_from = date('Y-m-01', strtotime('last month'));
            $date_to = date('Y-m-t', strtotime('last month'));
            break;
        case 'current_year':
            $date_from = date('Y-01-01');
            $date_to = date('Y-m-d');
            break;
    }
    
    if (!empty($section_filter)) {
        $whereConditions[] = "section = ?";
        $params[] = $section_filter;
    }
    
    if (!empty($date_from)) {
        $whereConditions[] = "DATE(created_at) >= ?";
        $params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $whereConditions[] = "DATE(created_at) <= ?";
        $params[] = $date_to;
    }
    
    $whereClause = '';
    if (!empty($whereConditions)) {
        $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    }
    
    // Get financial statistics
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(i.total_value), 0) as total_inventory_value,
            COALESCE(COUNT(i.id), 0) as total_items,
            COALESCE(AVG(i.unit_cost), 0) as avg_unit_cost,
            COALESCE(SUM(CASE WHEN i.quantity <= i.min_threshold THEN i.total_value ELSE 0 END), 0) as low_stock_value,
            COALESCE(SUM(CASE WHEN i.status = 'expired' THEN i.total_value ELSE 0 END), 0) as expired_value,
            COALESCE(SUM(CASE WHEN i.expiry_date IS NOT NULL AND i.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN i.total_value ELSE 0 END), 0) as expiring_value
        FROM inventory i
        {$whereClause}
    ");
    $stmt->execute($params);
    $financialStats = $stmt->fetch();
    
    // Calculate waste percentage
    $wastePercentage = $financialStats['total_inventory_value'] > 0 ? 
        round((($financialStats['expired_value'] + $financialStats['expiring_value']) / $financialStats['total_inventory_value']) * 100, 1) : 0;
    
    // Get section-wise breakdown
    $stmt = $conn->prepare("
        SELECT 
            i.section,
            COUNT(*) as item_count,
            SUM(i.total_value) as section_value,
            AVG(i.unit_cost) as avg_cost,
            SUM(CASE WHEN i.quantity <= i.min_threshold THEN i.total_value ELSE 0 END) as low_stock_value,
            SUM(CASE WHEN i.status = 'expired' THEN i.total_value ELSE 0 END) as expired_value
        FROM inventory i
        {$whereClause}
        GROUP BY i.section
        ORDER BY section_value DESC
    ");
    $stmt->execute($params);
    $sectionBreakdown = $stmt->fetchAll();
    
    // Get monthly trends
    $stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as items_added,
            SUM(total_value) as monthly_value,
            AVG(unit_cost) as avg_monthly_cost
        FROM inventory
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month ASC
    ");
    $stmt->execute();
    $monthlyTrends = $stmt->fetchAll();
    
    // Get available sections
    $stmt = $conn->prepare("SELECT DISTINCT section FROM inventory WHERE section IS NOT NULL ORDER BY section");
    $stmt->execute();
    $availableSections = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch (Exception $e) {
    error_log("Financial reports error: " . $e->getMessage());
    $financialStats = ['total_inventory_value' => 0, 'total_items' => 0, 'avg_unit_cost' => 0, 'low_stock_value' => 0, 'expired_value' => 0, 'expiring_value' => 0];
    $wastePercentage = 0;
    $sectionBreakdown = [];
    $monthlyTrends = [];
    $availableSections = [];
}

include '../../includes/header.php';
?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Financial Reports</h1>
            <p class="text-muted mb-0">Comprehensive financial analytics and cost management insights</p>
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
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Value</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">$<?php echo number_format($financialStats['total_inventory_value'], 2); ?></div>
                        </div>
                        <div class="text-primary">
                            <i class="fas fa-dollar-sign fa-2x"></i>
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
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Items</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($financialStats['total_items']); ?></div>
                        </div>
                        <div class="text-success">
                            <i class="fas fa-boxes fa-2x"></i>
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
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Avg Unit Cost</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">$<?php echo number_format($financialStats['avg_unit_cost'], 2); ?></div>
                        </div>
                        <div class="text-info">
                            <i class="fas fa-calculator fa-2x"></i>
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
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">At-Risk Value</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">$<?php echo number_format($financialStats['low_stock_value'] + $financialStats['expiring_value'], 2); ?></div>
                        </div>
                        <div class="text-warning">
                            <i class="fas fa-exclamation-triangle fa-2x"></i>
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
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Lost Value</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">$<?php echo number_format($financialStats['expired_value'], 2); ?></div>
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
                            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">Efficiency</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo 100 - $wastePercentage; ?>%</div>
                        </div>
                        <div class="text-secondary">
                            <i class="fas fa-chart-pie fa-2x"></i>
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
                <div class="col-md-3">
                    <label class="form-label">Period</label>
                    <select name="period" class="form-select" onchange="toggleCustomDates(this.value)">
                        <option value="today" <?php echo $period_filter == 'today' ? 'selected' : ''; ?>>Today</option>
                        <option value="this_week" <?php echo $period_filter == 'this_week' ? 'selected' : ''; ?>>This Week</option>
                        <option value="current_month" <?php echo $period_filter == 'current_month' ? 'selected' : ''; ?>>Current Month</option>
                        <option value="last_month" <?php echo $period_filter == 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                        <option value="current_year" <?php echo $period_filter == 'current_year' ? 'selected' : ''; ?>>Current Year</option>
                        <option value="custom" <?php echo $period_filter == 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                    </select>
                </div>
                <div class="col-md-2" id="date_from_col" style="display: <?php echo $period_filter == 'custom' ? 'block' : 'none'; ?>">
                    <label class="form-label">From Date</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                </div>
                <div class="col-md-2" id="date_to_col" style="display: <?php echo $period_filter == 'custom' ? 'block' : 'none'; ?>">
                    <label class="form-label">To Date</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">Apply Filters</button>
                    <a href="financial.php" class="btn btn-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row mb-4">
        <!-- Section Value Distribution -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">Section Value Distribution</h6>
                </div>
                <div class="card-body">
                    <canvas id="sectionChart" width="400" height="200"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Monthly Financial Trends -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">Monthly Trends (Last 12 Months)</h6>
                </div>
                <div class="card-body">
                    <canvas id="trendsChart" width="400" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Section Financial Analysis -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">Section Financial Analysis</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Section</th>
                                    <th>Items</th>
                                    <th>Total Value</th>
                                    <th>Avg Cost</th>
                                    <th>At-Risk Value</th>
                                    <th>Lost Value</th>
                                    <th>Value Share</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sectionBreakdown as $section): ?>
                                <?php 
                                    $valueShare = $financialStats['total_inventory_value'] > 0 ? 
                                        round(($section['section_value'] / $financialStats['total_inventory_value']) * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td><strong>Section <?php echo $section['section']; ?></strong></td>
                                    <td><?php echo number_format($section['item_count']); ?></td>
                                    <td>$<?php echo number_format($section['section_value'], 2); ?></td>
                                    <td>$<?php echo number_format($section['avg_cost'], 2); ?></td>
                                    <td><span class="badge bg-warning">$<?php echo number_format($section['low_stock_value'], 2); ?></span></td>
                                    <td><span class="badge bg-danger">$<?php echo number_format($section['expired_value'], 2); ?></span></td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar bg-primary" style="width: <?php echo $valueShare; ?>%">
                                                <?php echo $valueShare; ?>%
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

    <!-- Financial Summary -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h6 class="m-0 font-weight-bold text-primary">Financial Summary</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="border-left-primary p-3">
                                <h6 class="text-primary">Total Asset Value</h6>
                                <h4 class="font-weight-bold">$<?php echo number_format($financialStats['total_inventory_value'], 2); ?></h4>
                                <p class="text-muted mb-0">Current inventory valuation</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border-left-warning p-3">
                                <h6 class="text-warning">At-Risk Value</h6>
                                <h4 class="font-weight-bold">$<?php echo number_format($financialStats['low_stock_value'] + $financialStats['expiring_value'], 2); ?></h4>
                                <p class="text-muted mb-0">Low stock + expiring items</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border-left-danger p-3">
                                <h6 class="text-danger">Lost Value</h6>
                                <h4 class="font-weight-bold">$<?php echo number_format($financialStats['expired_value'], 2); ?></h4>
                                <p class="text-muted mb-0">Expired inventory</p>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border-left-success p-3">
                                <h6 class="text-success">Efficiency Rate</h6>
                                <h4 class="font-weight-bold"><?php echo 100 - $wastePercentage; ?>%</h4>
                                <p class="text-muted mb-0">Non-waste percentage</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Auto-refresh every 10 minutes
setTimeout(function() {
    location.reload();
}, 600000);

// Section Value Distribution Chart
const sectionCtx = document.getElementById('sectionChart').getContext('2d');
const sectionChart = new Chart(sectionCtx, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_map(function($s) { return 'Section ' . $s['section']; }, $sectionBreakdown)); ?>,
        datasets: [{
            data: <?php echo json_encode(array_column($sectionBreakdown, 'section_value')); ?>,
            backgroundColor: ['#007bff', '#28a745', '#ffc107', '#dc3545', '#6f42c1', '#fd7e14', '#20c997']
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom' },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.label + ': $' + context.parsed.toLocaleString();
                    }
                }
            }
        }
    }
});

// Monthly Trends Chart
const trendsCtx = document.getElementById('trendsChart').getContext('2d');
const trendsChart = new Chart(trendsCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_column($monthlyTrends, 'month')); ?>,
        datasets: [{
            label: 'Monthly Value ($)',
            data: <?php echo json_encode(array_column($monthlyTrends, 'monthly_value')); ?>,
            borderColor: '#007bff',
            backgroundColor: 'rgba(0, 123, 255, 0.1)'
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                title: { display: true, text: 'Value ($)' },
                ticks: {
                    callback: function(value) {
                        return '$' + value.toLocaleString();
                    }
                }
            }
        }
    }
});

// Toggle custom date fields
function toggleCustomDates(period) {
    const dateFromCol = document.getElementById('date_from_col');
    const dateToCol = document.getElementById('date_to_col');
    
    if (period === 'custom') {
        dateFromCol.style.display = 'block';
        dateToCol.style.display = 'block';
    } else {
        dateFromCol.style.display = 'none';
        dateToCol.style.display = 'none';
    }
}

// Export functions
function exportReport(format) {
    alert('Export functionality would generate ' + format.toUpperCase() + ' financial report with current filters');
}
</script>

<?php include '../../includes/footer.php'; ?>
