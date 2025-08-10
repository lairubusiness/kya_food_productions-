<?php
/**
 * KYA Food Production - Reports Dashboard
 * Comprehensive reporting and analytics system
 */

require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

SessionManager::start();
SessionManager::requireLogin();

// Check if user has reports access permissions
if (!SessionManager::hasPermission('reports_view')) {
    header('Location: ../../dashboard.php?error=access_denied');
    exit();
}

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

// Get report statistics
try {
    // Inventory statistics
    $inventoryStats = $conn->query("
        SELECT 
            COUNT(*) as total_items,
            SUM(quantity * COALESCE(unit_cost, 0)) as total_value,
            COUNT(CASE WHEN alert_status IN ('low_stock', 'critical') THEN 1 END) as alert_items,
            COUNT(CASE WHEN expiry_date < CURDATE() THEN 1 END) as expired_items,
            COUNT(CASE WHEN DATEDIFF(expiry_date, CURDATE()) <= 7 AND status = 'active' THEN 1 END) as expiring_soon
        FROM inventory
    ")->fetch();
    
    // Section-wise inventory
    $sectionStats = $conn->query("
        SELECT 
            section,
            COUNT(*) as item_count,
            SUM(quantity * COALESCE(unit_cost, 0)) as section_value
        FROM inventory 
        GROUP BY section 
        ORDER BY section
    ")->fetchAll();
    
    // Monthly inventory trends (last 6 months)
    $monthlyTrends = $conn->query("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as items_added,
            SUM(quantity * COALESCE(unit_cost, 0)) as value_added
        FROM inventory 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month DESC
        LIMIT 6
    ")->fetchAll();
    
    // Quality control statistics (if table exists)
    $qualityStats = [];
    try {
        $qualityStats = $conn->query("
            SELECT 
                COUNT(*) as total_inspections,
                COUNT(CASE WHEN status = 'passed' THEN 1 END) as passed_inspections,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_inspections,
                COUNT(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as recent_inspections
            FROM quality_inspections
        ")->fetch();
    } catch (Exception $e) {
        $qualityStats = ['total_inspections' => 0, 'passed_inspections' => 0, 'failed_inspections' => 0, 'recent_inspections' => 0];
    }
    
    // Alert statistics
    $alertStats = $conn->query("
        SELECT 
            COUNT(CASE WHEN alert_status = 'critical' THEN 1 END) as critical_alerts,
            COUNT(CASE WHEN alert_status = 'low_stock' THEN 1 END) as low_stock_alerts,
            COUNT(CASE WHEN alert_acknowledged = 1 THEN 1 END) as acknowledged_alerts
        FROM inventory 
        WHERE alert_status != 'normal'
    ")->fetch();
    
} catch (Exception $e) {
    error_log("Reports dashboard error: " . $e->getMessage());
    $inventoryStats = ['total_items' => 0, 'total_value' => 0, 'alert_items' => 0, 'expired_items' => 0, 'expiring_soon' => 0];
    $sectionStats = [];
    $monthlyTrends = [];
    $qualityStats = ['total_inspections' => 0, 'passed_inspections' => 0, 'failed_inspections' => 0, 'recent_inspections' => 0];
    $alertStats = ['critical_alerts' => 0, 'low_stock_alerts' => 0, 'acknowledged_alerts' => 0];
}

$pageTitle = 'Reports Dashboard';
include '../../includes/header.php';
?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-chart-bar text-primary me-2"></i>
                Reports Dashboard
            </h1>
            <p class="text-muted mb-0">Comprehensive analytics and reporting for KYA Food Production</p>
        </div>
        <div>
            <button type="button" class="btn btn-outline-primary" onclick="location.reload()">
                <i class="fas fa-sync-alt me-2"></i>Refresh
            </button>
        </div>
    </div>

    <!-- Quick Stats Overview -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card">
                <div class="stats-icon primary">
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="stats-number"><?php echo number_format($inventoryStats['total_items']); ?></div>
                <div class="stats-label">Total Inventory Items</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card success">
                <div class="stats-icon success">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stats-number"><?php echo formatCurrency($inventoryStats['total_value']); ?></div>
                <div class="stats-label">Total Inventory Value</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card warning">
                <div class="stats-icon warning">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stats-number"><?php echo number_format($inventoryStats['alert_items']); ?></div>
                <div class="stats-label">Active Alerts</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card info">
                <div class="stats-icon info">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <div class="stats-number"><?php echo number_format($qualityStats['total_inspections']); ?></div>
                <div class="stats-label">Quality Inspections</div>
            </div>
        </div>
    </div>

    <!-- Report Categories -->
    <div class="row mb-4">
        <!-- Inventory Reports -->
        <div class="col-lg-4 col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-warehouse me-2"></i>Inventory Reports
                    </h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">Comprehensive inventory analysis and tracking reports</p>
                    <div class="list-group list-group-flush">
                        <a href="inventory_summary.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-chart-pie me-2"></i>Inventory Summary
                        </a>
                        <a href="stock_levels.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-layer-group me-2"></i>Stock Levels Report
                        </a>
                        <a href="valuation_report.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-calculator me-2"></i>Inventory Valuation
                        </a>
                        <a href="movement_report.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-exchange-alt me-2"></i>Stock Movement
                        </a>
                        <a href="expiry_report.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-calendar-times me-2"></i>Expiry Analysis
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quality Control Reports -->
        <div class="col-lg-4 col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-medal me-2"></i>Quality Control Reports
                    </h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">Quality inspection and compliance reporting</p>
                    <div class="list-group list-group-flush">
                        <a href="quality_summary.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-chart-line me-2"></i>Quality Summary
                        </a>
                        <a href="inspection_report.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-search me-2"></i>Inspection Reports
                        </a>
                        <a href="compliance_report.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-shield-alt me-2"></i>Compliance Status
                        </a>
                        <a href="defect_analysis.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-bug me-2"></i>Defect Analysis
                        </a>
                        <a href="grade_distribution.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-star me-2"></i>Grade Distribution
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Production Reports -->
        <div class="col-lg-4 col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-industry me-2"></i>Production Reports
                    </h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">Production efficiency and performance metrics</p>
                    <div class="list-group list-group-flush">
                        <a href="production_summary.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-chart-area me-2"></i>Production Summary
                        </a>
                        <a href="section_performance.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-tachometer-alt me-2"></i>Section Performance
                        </a>
                        <a href="efficiency_report.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-percentage me-2"></i>Efficiency Analysis
                        </a>
                        <a href="capacity_report.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-expand-arrows-alt me-2"></i>Capacity Utilization
                        </a>
                        <a href="downtime_report.php" class="list-group-item list-group-item-action">
                            <i class="fas fa-pause-circle me-2"></i>Downtime Analysis
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Analytics Dashboard -->
    <div class="row mb-4">
        <!-- Section-wise Distribution -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-pie me-2"></i>Section-wise Inventory Distribution
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($sectionStats)): ?>
                        <canvas id="sectionChart" height="300"></canvas>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-chart-pie text-muted" style="font-size: 3rem;"></i>
                            <h5 class="mt-3 text-muted">No Data Available</h5>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Monthly Trends -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-line me-2"></i>Monthly Inventory Trends
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($monthlyTrends)): ?>
                        <canvas id="trendsChart" height="300"></canvas>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-chart-line text-muted" style="font-size: 3rem;"></i>
                            <h5 class="mt-3 text-muted">No Trend Data Available</h5>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-bolt me-2"></i>Quick Report Actions
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-lg-3 col-md-6 mb-3">
                            <button type="button" class="btn btn-outline-primary w-100" onclick="generateReport('daily')">
                                <i class="fas fa-calendar-day me-2"></i>Daily Report
                            </button>
                        </div>
                        <div class="col-lg-3 col-md-6 mb-3">
                            <button type="button" class="btn btn-outline-success w-100" onclick="generateReport('weekly')">
                                <i class="fas fa-calendar-week me-2"></i>Weekly Report
                            </button>
                        </div>
                        <div class="col-lg-3 col-md-6 mb-3">
                            <button type="button" class="btn btn-outline-info w-100" onclick="generateReport('monthly')">
                                <i class="fas fa-calendar-alt me-2"></i>Monthly Report
                            </button>
                        </div>
                        <div class="col-lg-3 col-md-6 mb-3">
                            <button type="button" class="btn btn-outline-secondary w-100" onclick="openCustomReport()">
                                <i class="fas fa-cog me-2"></i>Custom Report
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-clock me-2"></i>Recent Report Activity
                    </h5>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong>Inventory Summary Report</strong>
                                <br><small class="text-muted">Generated by Admin</small>
                            </div>
                            <small class="text-muted">2 hours ago</small>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong>Quality Control Report</strong>
                                <br><small class="text-muted">Generated by QC Manager</small>
                            </div>
                            <small class="text-muted">5 hours ago</small>
                        </div>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong>Stock Movement Report</strong>
                                <br><small class="text-muted">Generated by Inventory Manager</small>
                            </div>
                            <small class="text-muted">1 day ago</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>Alert Summary
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-4">
                            <div class="border-end">
                                <h3 class="text-danger"><?php echo number_format($alertStats['critical_alerts']); ?></h3>
                                <small class="text-muted">Critical Alerts</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border-end">
                                <h3 class="text-warning"><?php echo number_format($alertStats['low_stock_alerts']); ?></h3>
                                <small class="text-muted">Low Stock Alerts</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <h3 class="text-success"><?php echo number_format($alertStats['acknowledged_alerts']); ?></h3>
                            <small class="text-muted">Acknowledged</small>
                        </div>
                    </div>
                    <div class="mt-3">
                        <a href="../inventory/stock_alerts.php" class="btn btn-outline-warning btn-sm">
                            <i class="fas fa-bell me-1"></i>View All Alerts
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Custom Report Modal -->
<div class="modal fade" id="customReportModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Generate Custom Report</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="customReportForm">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="reportType" class="form-label">Report Type</label>
                            <select class="form-select" id="reportType" required>
                                <option value="">Select report type...</option>
                                <option value="inventory">Inventory Report</option>
                                <option value="quality">Quality Control Report</option>
                                <option value="production">Production Report</option>
                                <option value="alerts">Alerts Report</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="dateRange" class="form-label">Date Range</label>
                            <select class="form-select" id="dateRange" required>
                                <option value="today">Today</option>
                                <option value="yesterday">Yesterday</option>
                                <option value="last_7_days">Last 7 Days</option>
                                <option value="last_30_days">Last 30 Days</option>
                                <option value="this_month">This Month</option>
                                <option value="last_month">Last Month</option>
                                <option value="custom">Custom Range</option>
                            </select>
                        </div>
                    </div>
                    <div class="row" id="customDateRange" style="display: none;">
                        <div class="col-md-6 mb-3">
                            <label for="startDate" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="startDate">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="endDate" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="endDate">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="sections" class="form-label">Sections (Optional)</label>
                        <select class="form-select" id="sections" multiple>
                            <option value="1">Section 1 - Raw Materials</option>
                            <option value="2">Section 2 - Processing</option>
                            <option value="3">Section 3 - Packaging</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="format" class="form-label">Export Format</label>
                        <select class="form-select" id="format" required>
                            <option value="pdf">PDF</option>
                            <option value="excel">Excel</option>
                            <option value="csv">CSV</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="generateCustomReport()">Generate Report</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Section Distribution Chart
<?php if (!empty($sectionStats)): ?>
const sectionCtx = document.getElementById('sectionChart').getContext('2d');
new Chart(sectionCtx, {
    type: 'doughnut',
    data: {
        labels: [<?php echo implode(',', array_map(function($s) { return "'Section " . $s['section'] . "'"; }, $sectionStats)); ?>],
        datasets: [{
            data: [<?php echo implode(',', array_column($sectionStats, 'item_count')); ?>],
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

// Monthly Trends Chart
<?php if (!empty($monthlyTrends)): ?>
const trendsCtx = document.getElementById('trendsChart').getContext('2d');
new Chart(trendsCtx, {
    type: 'line',
    data: {
        labels: [<?php echo implode(',', array_map(function($t) { return "'" . date('M Y', strtotime($t['month'] . '-01')) . "'"; }, array_reverse($monthlyTrends))); ?>],
        datasets: [{
            label: 'Items Added',
            data: [<?php echo implode(',', array_column(array_reverse($monthlyTrends), 'items_added')); ?>],
            borderColor: '#007bff',
            backgroundColor: 'rgba(0, 123, 255, 0.1)',
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});
<?php endif; ?>

// Quick report generation
function generateReport(type) {
    window.open(`generate_report.php?type=${type}`, '_blank');
}

// Custom report modal
function openCustomReport() {
    new bootstrap.Modal(document.getElementById('customReportModal')).show();
}

// Handle custom date range
document.getElementById('dateRange').addEventListener('change', function() {
    const customRange = document.getElementById('customDateRange');
    if (this.value === 'custom') {
        customRange.style.display = 'block';
    } else {
        customRange.style.display = 'none';
    }
});

// Generate custom report
function generateCustomReport() {
    const form = document.getElementById('customReportForm');
    const formData = new FormData(form);
    
    const params = new URLSearchParams();
    for (let [key, value] of formData.entries()) {
        params.append(key, value);
    }
    
    window.open(`generate_custom_report.php?${params.toString()}`, '_blank');
    bootstrap.Modal.getInstance(document.getElementById('customReportModal')).hide();
}

// Auto-refresh every 10 minutes
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 600000);
</script>

<?php include '../../includes/footer.php'; ?>