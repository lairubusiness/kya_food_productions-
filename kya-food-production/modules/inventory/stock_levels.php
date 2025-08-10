<?php
/**
 * KYA Food Production - Inventory Stock Levels
 * Comprehensive stock level monitoring and management system
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

$pageTitle = "Stock Levels - Inventory Management";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_thresholds':
                $result = updateStockThresholds($_POST);
                if ($result['success']) {
                    $successMessage = "Stock thresholds updated successfully!";
                } else {
                    $errorMessage = $result['message'];
                }
                break;
            case 'adjust_stock':
                $result = adjustStockLevel($_POST);
                if ($result['success']) {
                    $successMessage = "Stock level adjusted successfully!";
                } else {
                    $errorMessage = $result['message'];
                }
                break;
        }
    }
}

// Get stock statistics
$stats = getStockStats();

// Get stock levels by section
$stockLevels = getStockLevels();

// Get low stock alerts
$lowStockAlerts = getLowStockAlerts();

// Get critical stock items
$criticalStock = getCriticalStockItems();

function getStockStats() {
    global $conn;
    
    $stats = [
        'total_items' => 0,
        'low_stock' => 0,
        'critical_stock' => 0,
        'out_of_stock' => 0,
        'total_value' => 0,
        'avg_stock_level' => 0
    ];
    
    try {
        // Total items
        $stmt = $conn->query("SELECT COUNT(*) FROM inventory WHERE status = 'active'");
        $stats['total_items'] = $stmt->fetchColumn();
        
        // Low stock (below min threshold)
        $stmt = $conn->query("SELECT COUNT(*) FROM inventory WHERE quantity <= min_threshold AND status = 'active'");
        $stats['low_stock'] = $stmt->fetchColumn();
        
        // Critical stock (below 10% of min threshold)
        $stmt = $conn->query("SELECT COUNT(*) FROM inventory WHERE quantity <= (min_threshold * 0.1) AND status = 'active'");
        $stats['critical_stock'] = $stmt->fetchColumn();
        
        // Out of stock
        $stmt = $conn->query("SELECT COUNT(*) FROM inventory WHERE quantity = 0 AND status = 'active'");
        $stats['out_of_stock'] = $stmt->fetchColumn();
        
        // Total inventory value
        $stmt = $conn->query("SELECT COALESCE(SUM(quantity * unit_cost), 0) FROM inventory WHERE status = 'active'");
        $stats['total_value'] = $stmt->fetchColumn();
        
        // Average stock level percentage
        $stmt = $conn->query("
            SELECT AVG(
                CASE 
                    WHEN max_threshold > 0 THEN (quantity / max_threshold) * 100
                    ELSE 0 
                END
            ) FROM inventory WHERE status = 'active' AND max_threshold > 0
        ");
        $stats['avg_stock_level'] = round($stmt->fetchColumn() ?: 0, 1);
        
    } catch (Exception $e) {
        // Handle error silently
    }
    
    return $stats;
}

function getStockLevels() {
    global $conn, $userInfo;
    
    try {
        // Build section filter based on user role
        $sectionFilter = '';
        $params = [];
        
        if ($userInfo['role'] !== 'admin') {
            $userSections = getUserSections($userInfo['role']);
            if (!empty($userSections)) {
                $sectionFilter = 'WHERE section IN (' . implode(',', array_fill(0, count($userSections), '?')) . ')';
                $params = $userSections;
            }
        }
        
        $stmt = $conn->prepare("
            SELECT 
                id, section, item_code, item_name, category, quantity, unit,
                unit_cost, min_threshold, max_threshold, reorder_level,
                expiry_date, quality_grade, last_updated,
                CASE 
                    WHEN quantity = 0 THEN 'out_of_stock'
                    WHEN quantity <= (min_threshold * 0.1) THEN 'critical'
                    WHEN quantity <= min_threshold THEN 'low'
                    WHEN quantity >= max_threshold THEN 'overstocked'
                    ELSE 'normal'
                END as stock_status,
                CASE 
                    WHEN max_threshold > 0 THEN ROUND((quantity / max_threshold) * 100, 1)
                    ELSE 0 
                END as stock_percentage
            FROM inventory 
            $sectionFilter
            AND status = 'active'
            ORDER BY 
                CASE 
                    WHEN quantity = 0 THEN 1
                    WHEN quantity <= (min_threshold * 0.1) THEN 2
                    WHEN quantity <= min_threshold THEN 3
                    ELSE 4
                END,
                section, item_name
        ");
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function getLowStockAlerts() {
    global $conn, $userInfo;
    
    try {
        $sectionFilter = '';
        $params = [];
        
        if ($userInfo['role'] !== 'admin') {
            $userSections = getUserSections($userInfo['role']);
            if (!empty($userSections)) {
                $sectionFilter = 'AND section IN (' . implode(',', array_fill(0, count($userSections), '?')) . ')';
                $params = $userSections;
            }
        }
        
        $stmt = $conn->prepare("
            SELECT 
                id, section, item_code, item_name, category, quantity, unit,
                min_threshold, reorder_level, last_updated
            FROM inventory 
            WHERE quantity <= min_threshold 
            AND status = 'active'
            $sectionFilter
            ORDER BY (quantity / min_threshold) ASC
            LIMIT 10
        ");
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function getCriticalStockItems() {
    global $conn, $userInfo;
    
    try {
        $sectionFilter = '';
        $params = [];
        
        if ($userInfo['role'] !== 'admin') {
            $userSections = getUserSections($userInfo['role']);
            if (!empty($userSections)) {
                $sectionFilter = 'AND section IN (' . implode(',', array_fill(0, count($userSections), '?')) . ')';
                $params = $userSections;
            }
        }
        
        $stmt = $conn->prepare("
            SELECT 
                id, section, item_code, item_name, category, quantity, unit,
                min_threshold, expiry_date, last_updated
            FROM inventory 
            WHERE (quantity <= (min_threshold * 0.1) OR quantity = 0)
            AND status = 'active'
            $sectionFilter
            ORDER BY quantity ASC
        ");
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function updateStockThresholds($data) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            UPDATE inventory 
            SET min_threshold = ?, max_threshold = ?, reorder_level = ?, last_updated = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $data['min_threshold'], $data['max_threshold'], 
            $data['reorder_level'], $data['item_id']
        ]);
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function adjustStockLevel($data) {
    global $conn, $userInfo;
    
    try {
        $stmt = $conn->prepare("
            UPDATE inventory 
            SET quantity = quantity + ?, last_updated = NOW()
            WHERE id = ?
        ");
        
        $adjustment = $data['adjustment_type'] === 'increase' ? $data['adjustment_amount'] : -$data['adjustment_amount'];
        $stmt->execute([$adjustment, $data['item_id']]);
        
        // Log the adjustment
        logActivity("Stock Adjustment", "Item ID: {$data['item_id']}, Adjustment: {$adjustment} {$data['unit']}, Reason: {$data['reason']}", $userInfo['user_id']);
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function getUserSections($role) {
    switch ($role) {
        case 'section1_manager':
            return [1];
        case 'section2_manager':
            return [2];
        case 'section3_manager':
            return [3];
        default:
            return [1, 2, 3];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo COMPANY_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include '../../includes/header.php'; ?>

    <div class="main-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-header">
                        <h1><i class="fas fa-boxes me-3"></i>Stock Levels</h1>
                        <p class="text-muted">Monitor and manage inventory stock levels across all sections</p>
                    </div>

                    <?php if (isset($successMessage)): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="fas fa-check-circle me-2"></i><?php echo $successMessage; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($errorMessage)): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo $errorMessage; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                            <div class="stats-card primary">
                                <div class="stats-icon primary">
                                    <i class="fas fa-boxes"></i>
                                </div>
                                <div class="stats-content">
                                    <h3><?php echo number_format($stats['total_items']); ?></h3>
                                    <p>Total Items</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                            <div class="stats-card warning">
                                <div class="stats-icon warning">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="stats-content">
                                    <h3><?php echo number_format($stats['low_stock']); ?></h3>
                                    <p>Low Stock</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                            <div class="stats-card danger">
                                <div class="stats-icon danger">
                                    <i class="fas fa-exclamation-circle"></i>
                                </div>
                                <div class="stats-content">
                                    <h3><?php echo number_format($stats['critical_stock']); ?></h3>
                                    <p>Critical Stock</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                            <div class="stats-card danger">
                                <div class="stats-icon danger">
                                    <i class="fas fa-times-circle"></i>
                                </div>
                                <div class="stats-content">
                                    <h3><?php echo number_format($stats['out_of_stock']); ?></h3>
                                    <p>Out of Stock</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                            <div class="stats-card success">
                                <div class="stats-icon success">
                                    <i class="fas fa-rupee-sign"></i>
                                </div>
                                <div class="stats-content">
                                    <h3>₹<?php echo number_format($stats['total_value'], 0); ?></h3>
                                    <p>Total Value</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                            <div class="stats-card info">
                                <div class="stats-icon info">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div class="stats-content">
                                    <h3><?php echo $stats['avg_stock_level']; ?>%</h3>
                                    <p>Avg Stock Level</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Critical Stock Alerts -->
                        <?php if (!empty($criticalStock)): ?>
                        <div class="col-12 mb-4">
                            <div class="card border-danger">
                                <div class="card-header bg-danger text-white">
                                    <h5><i class="fas fa-exclamation-triangle me-2"></i>Critical Stock Alert</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Section</th>
                                                    <th>Item</th>
                                                    <th>Current Stock</th>
                                                    <th>Min Threshold</th>
                                                    <th>Status</th>
                                                    <th>Last Updated</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($criticalStock as $item): ?>
                                                <tr class="table-danger">
                                                    <td>
                                                        <span class="badge bg-secondary">Section <?php echo $item['section']; ?></span>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($item['item_name']); ?></strong><br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($item['item_code']); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="text-danger fw-bold">
                                                            <?php echo number_format($item['quantity'], 2) . ' ' . $item['unit']; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo number_format($item['min_threshold'], 2) . ' ' . $item['unit']; ?></td>
                                                    <td>
                                                        <span class="badge bg-danger">
                                                            <?php echo $item['quantity'] == 0 ? 'OUT OF STOCK' : 'CRITICAL'; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('M j, Y', strtotime($item['last_updated'])); ?></td>
                                                    <td>
                                                        <button class="btn btn-sm btn-warning" onclick="showAdjustModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['item_name']); ?>', '<?php echo $item['unit']; ?>')">
                                                            <i class="fas fa-edit"></i> Adjust
                                                        </button>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- All Stock Levels -->
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5><i class="fas fa-warehouse me-2"></i>All Stock Levels</h5>
                                    <div>
                                        <button class="btn btn-sm btn-outline-primary me-2" onclick="location.reload()">
                                            <i class="fas fa-sync-alt me-1"></i>Refresh
                                        </button>
                                        <button class="btn btn-sm btn-success" onclick="exportStockReport()">
                                            <i class="fas fa-download me-1"></i>Export
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="stockTable">
                                            <thead>
                                                <tr>
                                                    <th>Section</th>
                                                    <th>Item Details</th>
                                                    <th>Current Stock</th>
                                                    <th>Stock Level</th>
                                                    <th>Thresholds</th>
                                                    <th>Value</th>
                                                    <th>Status</th>
                                                    <th>Last Updated</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($stockLevels as $item): ?>
                                                <tr class="<?php echo $item['stock_status'] === 'critical' || $item['stock_status'] === 'out_of_stock' ? 'table-danger' : ($item['stock_status'] === 'low' ? 'table-warning' : ''); ?>">
                                                    <td>
                                                        <span class="badge bg-secondary">Section <?php echo $item['section']; ?></span>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($item['item_name']); ?></strong><br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($item['item_code']); ?></small><br>
                                                        <small class="text-info"><?php echo htmlspecialchars($item['category']); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="fw-bold <?php echo $item['quantity'] <= $item['min_threshold'] ? 'text-danger' : 'text-success'; ?>">
                                                            <?php echo number_format($item['quantity'], 2) . ' ' . $item['unit']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="progress" style="height: 20px;">
                                                            <div class="progress-bar bg-<?php 
                                                                echo $item['stock_percentage'] <= 10 ? 'danger' : 
                                                                    ($item['stock_percentage'] <= 30 ? 'warning' : 'success'); 
                                                            ?>" 
                                                                 style="width: <?php echo min($item['stock_percentage'], 100); ?>%">
                                                                <?php echo $item['stock_percentage']; ?>%
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <small>
                                                            Min: <?php echo number_format($item['min_threshold'], 2); ?><br>
                                                            Max: <?php echo number_format($item['max_threshold'], 2); ?><br>
                                                            Reorder: <?php echo number_format($item['reorder_level'], 2); ?>
                                                        </small>
                                                    </td>
                                                    <td>₹<?php echo number_format($item['quantity'] * $item['unit_cost'], 2); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            echo $item['stock_status'] === 'out_of_stock' ? 'danger' : 
                                                                ($item['stock_status'] === 'critical' ? 'danger' : 
                                                                ($item['stock_status'] === 'low' ? 'warning' : 
                                                                ($item['stock_status'] === 'overstocked' ? 'info' : 'success'))); 
                                                        ?>">
                                                            <?php echo ucwords(str_replace('_', ' ', $item['stock_status'])); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('M j, Y', strtotime($item['last_updated'])); ?></td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <button class="btn btn-outline-primary" 
                                                                    onclick="showAdjustModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['item_name']); ?>', '<?php echo $item['unit']; ?>')"
                                                                    title="Adjust Stock">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <button class="btn btn-outline-secondary" 
                                                                    onclick="showThresholdModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['item_name']); ?>', <?php echo $item['min_threshold']; ?>, <?php echo $item['max_threshold']; ?>, <?php echo $item['reorder_level']; ?>)"
                                                                    title="Update Thresholds">
                                                                <i class="fas fa-cog"></i>
                                                            </button>
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
                </div>
            </div>
        </div>
    </div>

    <!-- Stock Adjustment Modal -->
    <div class="modal fade" id="adjustStockModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Adjust Stock Level</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="adjust_stock">
                        <input type="hidden" name="item_id" id="adjust_item_id">
                        <input type="hidden" name="unit" id="adjust_unit">
                        
                        <div class="mb-3">
                            <label class="form-label">Item</label>
                            <input type="text" class="form-control" id="adjust_item_name" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Adjustment Type</label>
                            <select class="form-select" name="adjustment_type" required>
                                <option value="increase">Increase Stock</option>
                                <option value="decrease">Decrease Stock</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Adjustment Amount</label>
                            <input type="number" step="0.01" class="form-control" name="adjustment_amount" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Reason</label>
                            <select class="form-select" name="reason" required>
                                <option value="">Select Reason</option>
                                <option value="Stock Count Correction">Stock Count Correction</option>
                                <option value="Damaged Goods">Damaged Goods</option>
                                <option value="Expired Items">Expired Items</option>
                                <option value="Manual Adjustment">Manual Adjustment</option>
                                <option value="Receiving Correction">Receiving Correction</option>
                                <option value="Usage Correction">Usage Correction</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Apply Adjustment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Threshold Update Modal -->
    <div class="modal fade" id="thresholdModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Stock Thresholds</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_thresholds">
                        <input type="hidden" name="item_id" id="threshold_item_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Item</label>
                            <input type="text" class="form-control" id="threshold_item_name" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Minimum Threshold</label>
                            <input type="number" step="0.01" class="form-control" name="min_threshold" id="threshold_min" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Maximum Threshold</label>
                            <input type="number" step="0.01" class="form-control" name="max_threshold" id="threshold_max" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Reorder Level</label>
                            <input type="number" step="0.01" class="form-control" name="reorder_level" id="threshold_reorder" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Thresholds</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../../includes/footer.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh every 5 minutes
        setTimeout(function() {
            location.reload();
        }, 300000);
        
        function showAdjustModal(itemId, itemName, unit) {
            document.getElementById('adjust_item_id').value = itemId;
            document.getElementById('adjust_item_name').value = itemName;
            document.getElementById('adjust_unit').value = unit;
            new bootstrap.Modal(document.getElementById('adjustStockModal')).show();
        }
        
        function showThresholdModal(itemId, itemName, minThreshold, maxThreshold, reorderLevel) {
            document.getElementById('threshold_item_id').value = itemId;
            document.getElementById('threshold_item_name').value = itemName;
            document.getElementById('threshold_min').value = minThreshold;
            document.getElementById('threshold_max').value = maxThreshold;
            document.getElementById('threshold_reorder').value = reorderLevel;
            new bootstrap.Modal(document.getElementById('thresholdModal')).show();
        }
        
        function exportStockReport() {
            window.open('export_stock_report.php', '_blank');
        }
    </script>
</body>
</html>
