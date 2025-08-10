<?php
/**
 * KYA Food Production - Inventory Reports
 * Comprehensive inventory analytics and reporting
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

$pageTitle = "Inventory Reports";

// Get filter parameters
$section = $_GET['section'] ?? '';
$category = $_GET['category'] ?? '';
$status = $_GET['status'] ?? '';
$alertLevel = $_GET['alert_level'] ?? '';

// Handle export
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    header('Content-Type: application/json');
    echo json_encode(['message' => 'Export functionality will be implemented for ' . $exportType]);
    exit();
}

try {
    // Build WHERE clause based on filters and permissions
    $whereConditions = ["1=1"];
    $params = [];
    
    if ($section && ($userRole === 'admin' || in_array($section, $userSections))) {
        $whereConditions[] = "section = ?";
        $params[] = $section;
    } elseif ($userRole !== 'admin' && !empty($userSections)) {
        $whereConditions[] = "section IN (" . implode(',', array_map('intval', $userSections)) . ")";
    }
    
    if ($category) {
        $whereConditions[] = "category = ?";
        $params[] = $category;
    }
    
    if ($status) {
        $whereConditions[] = "status = ?";
        $params[] = $status;
    }
    
    if ($alertLevel) {
        switch ($alertLevel) {
            case 'critical':
                $whereConditions[] = "quantity <= min_threshold";
                break;
            case 'low':
                $whereConditions[] = "quantity > min_threshold AND quantity <= (min_threshold * 1.5)";
                break;
            case 'expiring':
                $whereConditions[] = "expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
                break;
        }
    }
    
    $whereClause = "WHERE " . implode(" AND ", $whereConditions);
    
    // Get inventory statistics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_items,
            SUM(quantity * unit_cost) as total_value,
            COUNT(CASE WHEN quantity <= min_threshold THEN 1 END) as critical_items,
            COUNT(CASE WHEN quantity > min_threshold AND quantity <= (min_threshold * 1.5) THEN 1 END) as low_stock_items,
            COUNT(CASE WHEN expiry_date IS NOT NULL AND expiry_date <= CURDATE() THEN 1 END) as expired_items,
            COUNT(CASE WHEN expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date > CURDATE() THEN 1 END) as expiring_soon,
            AVG(quantity) as avg_quantity,
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active_items
        FROM inventory 
        {$whereClause}
    ");
    $stmt->execute($params);
    $inventoryStats = $stmt->fetch();
    
    // Get category distribution
    $stmt = $conn->prepare("
        SELECT 
            category,
            COUNT(*) as item_count,
            SUM(quantity * unit_cost) as category_value,
            AVG(quantity) as avg_quantity
        FROM inventory 
        {$whereClause}
        GROUP BY category
        ORDER BY category_value DESC
    ");
    $stmt->execute($params);
    $categoryDistribution = $stmt->fetchAll();
    
    // Get critical alerts
    $stmt = $conn->prepare("
        SELECT 
            item_name,
            item_code,
            category,
            section,
            quantity,
            min_threshold,
            expiry_date,
            CASE 
                WHEN quantity <= min_threshold THEN 'Critical Stock'
                WHEN expiry_date IS NOT NULL AND expiry_date <= CURDATE() THEN 'Expired'
                WHEN expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'Expiring Soon'
                ELSE 'Low Stock'
            END as alert_type
        FROM inventory 
        {$whereClause}
        AND (
            quantity <= min_threshold 
            OR (expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
        )
        ORDER BY 
            CASE 
                WHEN expiry_date IS NOT NULL AND expiry_date <= CURDATE() THEN 1
                WHEN quantity <= min_threshold THEN 2
                WHEN expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 3
                ELSE 4
            END,
            expiry_date ASC,
            quantity ASC
        LIMIT 50
    ");
    $stmt->execute($params);
    $criticalAlerts = $stmt->fetchAll();
    
    // Get available categories for filter
    $stmt = $conn->prepare("SELECT DISTINCT category FROM inventory WHERE category IS NOT NULL ORDER BY category");
    $stmt->execute();
    $availableCategories = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch (Exception $e) {
    error_log("Inventory reports error: " . $e->getMessage());
    $inventoryStats = ['total_items' => 0, 'total_value' => 0, 'critical_items' => 0, 'low_stock_items' => 0, 'expired_items' => 0, 'expiring_soon' => 0, 'avg_quantity' => 0, 'active_items' => 0];
    $categoryDistribution = [];
    $criticalAlerts = [];
    $availableCategories = [];
}

include '../../includes/header.php';
?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Inventory Reports</h1>
            <p class="text-muted mb-0">Comprehensive inventory analytics and stock management insights</p>
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
                    <label for="category" class="form-label">Category</label>
                    <select class="form-select" id="category" name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($availableCategories as $cat): ?>
                            <option value="<?php echo $cat; ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                <?php echo ucfirst($cat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="discontinued" <?php echo $status === 'discontinued' ? 'selected' : ''; ?>>Discontinued</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="alert_level" class="form-label">Alert Level</label>
                    <select class="form-select" id="alert_level" name="alert_level">
                        <option value="">All Items</option>
                        <option value="critical" <?php echo $alertLevel === 'critical' ? 'selected' : ''; ?>>Critical Stock</option>
                        <option value="low" <?php echo $alertLevel === 'low' ? 'selected' : ''; ?>>Low Stock</option>
                        <option value="expiring" <?php echo $alertLevel === 'expiring' ? 'selected' : ''; ?>>Expiring Soon</option>
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
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid">
                        <a href="inventory.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-2"></i>Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Inventory Statistics -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card primary">
                <div class="stats-icon primary">
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="stats-number"><?php echo number_format($inventoryStats['total_items']); ?></div>
                <div class="stats-label">Total Items</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card success">
                <div class="stats-icon success">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stats-number"><?php echo formatCurrency($inventoryStats['total_value']); ?></div>
                <div class="stats-label">Total Value</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card danger">
                <div class="stats-icon danger">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stats-number"><?php echo number_format($inventoryStats['critical_items']); ?></div>
                <div class="stats-label">Critical Stock</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card warning">
                <div class="stats-icon warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-number"><?php echo number_format($inventoryStats['expiring_soon']); ?></div>
                <div class="stats-label">Expiring Soon</div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-chart-pie me-2"></i>Category Distribution by Value
                    </h6>
                </div>
                <div class="card-body">
                    <canvas id="categoryChart" width="400" height="300"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-chart-bar me-2"></i>Stock Status Overview
                    </h6>
                </div>
                <div class="card-body">
                    <canvas id="stockStatusChart" width="400" height="300"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Critical Alerts -->
    <?php if (!empty($criticalAlerts)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-exclamation-triangle me-2"></i>Critical Alerts
                <span class="badge bg-danger ms-2"><?php echo count($criticalAlerts); ?></span>
            </h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Category</th>
                            <th>Section</th>
                            <th>Current Stock</th>
                            <th>Min Threshold</th>
                            <th>Expiry Date</th>
                            <th>Alert Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($criticalAlerts as $alert): ?>
                        <tr class="<?php echo $alert['alert_type'] === 'Expired' ? 'table-danger' : ($alert['alert_type'] === 'Critical Stock' ? 'table-warning' : ''); ?>">
                            <td>
                                <strong><?php echo $alert['item_name']; ?></strong>
                                <br><small class="text-muted"><?php echo $alert['item_code']; ?></small>
                            </td>
                            <td><?php echo ucfirst($alert['category']); ?></td>
                            <td>Section <?php echo $alert['section']; ?></td>
                            <td>
                                <span class="<?php echo $alert['quantity'] <= $alert['min_threshold'] ? 'text-danger fw-bold' : ''; ?>">
                                    <?php echo number_format($alert['quantity'], 1); ?>
                                </span>
                            </td>
                            <td><?php echo number_format($alert['min_threshold'], 1); ?></td>
                            <td>
                                <?php if ($alert['expiry_date']): ?>
                                    <?php 
                                    $daysToExpiry = (strtotime($alert['expiry_date']) - time()) / (60 * 60 * 24);
                                    $dateClass = $daysToExpiry <= 0 ? 'text-danger fw-bold' : ($daysToExpiry <= 7 ? 'text-warning fw-bold' : '');
                                    ?>
                                    <span class="<?php echo $dateClass; ?>">
                                        <?php echo formatDate($alert['expiry_date']); ?>
                                    </span>
                                    <?php if ($daysToExpiry <= 7): ?>
                                        <br><small class="<?php echo $dateClass; ?>">
                                            <?php 
                                            if ($daysToExpiry <= 0) {
                                                echo 'Expired ' . abs(floor($daysToExpiry)) . ' days ago';
                                            } else {
                                                echo floor($daysToExpiry) . ' days left';
                                            }
                                            ?>
                                        </small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $alertClasses = [
                                    'Expired' => 'bg-danger',
                                    'Critical Stock' => 'bg-danger',
                                    'Expiring Soon' => 'bg-warning',
                                    'Low Stock' => 'bg-warning'
                                ];
                                $alertClass = $alertClasses[$alert['alert_type']] ?? 'bg-secondary';
                                ?>
                                <span class="badge <?php echo $alertClass; ?>">
                                    <?php echo $alert['alert_type']; ?>
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
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Category Distribution Chart
<?php if (!empty($categoryDistribution)): ?>
const categoryCtx = document.getElementById('categoryChart').getContext('2d');
new Chart(categoryCtx, {
    type: 'doughnut',
    data: {
        labels: [<?php echo '"' . implode('","', array_map('ucfirst', array_column($categoryDistribution, 'category'))) . '"'; ?>],
        datasets: [{
            data: [<?php echo implode(',', array_column($categoryDistribution, 'category_value')); ?>],
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

// Stock Status Chart
const stockStatusCtx = document.getElementById('stockStatusChart').getContext('2d');
new Chart(stockStatusCtx, {
    type: 'bar',
    data: {
        labels: ['Active Items', 'Critical Stock', 'Low Stock', 'Expired', 'Expiring Soon'],
        datasets: [{
            label: 'Count',
            data: [
                <?php echo $inventoryStats['active_items']; ?>,
                <?php echo $inventoryStats['critical_items']; ?>,
                <?php echo $inventoryStats['low_stock_items']; ?>,
                <?php echo $inventoryStats['expired_items']; ?>,
                <?php echo $inventoryStats['expiring_soon']; ?>
            ],
            backgroundColor: ['#28a745', '#dc3545', '#ffc107', '#6c757d', '#fd7e14'],
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

// Auto-refresh every 5 minutes
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 300000);
</script>

<?php include '../../includes/footer.php'; ?>
