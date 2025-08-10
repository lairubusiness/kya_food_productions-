<?php
/**
 * KYA Food Production - Stock Alerts Management
 * Monitor and manage stock alerts, low stock warnings, and critical inventory levels
 */

require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

SessionManager::start();
SessionManager::requireLogin();

// Check if user has inventory management permissions
if (!SessionManager::hasPermission('inventory_manage')) {
    header('Location: ../../dashboard.php?error=access_denied');
    exit();
}

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

// Handle alert actions
if ($_POST['action'] ?? false) {
    $action = $_POST['action'];
    $itemId = $_POST['item_id'] ?? 0;
    
    try {
        switch ($action) {
            case 'acknowledge':
                $stmt = $conn->prepare("UPDATE inventory SET alert_acknowledged = 1, alert_acknowledged_by = ?, alert_acknowledged_at = NOW() WHERE id = ?");
                $stmt->execute([$userInfo['id'], $itemId]);
                $success_message = "Alert acknowledged successfully.";
                break;
                
            case 'update_stock':
                $newQuantity = $_POST['new_quantity'] ?? 0;
                $notes = $_POST['notes'] ?? '';
                
                // Update inventory quantity
                $stmt = $conn->prepare("UPDATE inventory SET quantity = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                $stmt->execute([$newQuantity, $userInfo['id'], $itemId]);
                
                // Log stock update
                $logStmt = $conn->prepare("INSERT INTO inventory_logs (inventory_id, action, old_quantity, new_quantity, notes, created_by, created_at) VALUES (?, 'stock_update', (SELECT quantity FROM inventory WHERE id = ?), ?, ?, ?, NOW())");
                $logStmt->execute([$itemId, $itemId, $newQuantity, $notes, $userInfo['id']]);
                
                // Recalculate alert status
                $updateAlertStmt = $conn->prepare("
                    UPDATE inventory 
                    SET alert_status = CASE 
                        WHEN quantity <= critical_level THEN 'critical'
                        WHEN quantity <= reorder_level THEN 'low_stock'
                        ELSE 'normal'
                    END,
                    alert_acknowledged = 0
                    WHERE id = ?
                ");
                $updateAlertStmt->execute([$itemId]);
                
                $success_message = "Stock quantity updated successfully.";
                break;
                
            case 'set_reorder_level':
                $reorderLevel = $_POST['reorder_level'] ?? 0;
                $criticalLevel = $_POST['critical_level'] ?? 0;
                
                $stmt = $conn->prepare("UPDATE inventory SET reorder_level = ?, critical_level = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                $stmt->execute([$reorderLevel, $criticalLevel, $userInfo['id'], $itemId]);
                
                // Recalculate alert status
                $updateAlertStmt = $conn->prepare("
                    UPDATE inventory 
                    SET alert_status = CASE 
                        WHEN quantity <= ? THEN 'critical'
                        WHEN quantity <= ? THEN 'low_stock'
                        ELSE 'normal'
                    END
                    WHERE id = ?
                ");
                $updateAlertStmt->execute([$criticalLevel, $reorderLevel, $itemId]);
                
                $success_message = "Reorder levels updated successfully.";
                break;
        }
    } catch (Exception $e) {
        error_log("Stock alerts action error: " . $e->getMessage());
        $error_message = "An error occurred while processing the request.";
    }
}

// Get filter parameters
$section = $_GET['section'] ?? '';
$alert_type = $_GET['alert_type'] ?? '';
$acknowledged = $_GET['acknowledged'] ?? '';
$search = $_GET['search'] ?? '';

// Build query conditions
$whereConditions = ["alert_status != 'normal'"];
$params = [];

// Section filter based on user permissions
if ($userInfo['role'] !== 'admin') {
    $userSections = $userInfo['sections'];
    if (!empty($userSections)) {
        $placeholders = str_repeat('?,', count($userSections) - 1) . '?';
        $whereConditions[] = "section IN ($placeholders)";
        $params = array_merge($params, $userSections);
    }
} elseif ($section) {
    $whereConditions[] = "section = ?";
    $params[] = $section;
}

if ($alert_type) {
    $whereConditions[] = "alert_status = ?";
    $params[] = $alert_type;
}

if ($acknowledged !== '') {
    if ($acknowledged === '1') {
        $whereConditions[] = "alert_acknowledged = 1";
    } else {
        $whereConditions[] = "(alert_acknowledged = 0 OR alert_acknowledged IS NULL)";
    }
}

if ($search) {
    $whereConditions[] = "(item_name LIKE ? OR item_code LIKE ? OR description LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// Get stock alerts
try {
    $stmt = $conn->prepare("
        SELECT i.*, 
               u.full_name as created_by_name,
               ack_user.full_name as acknowledged_by_name,
               CASE 
                   WHEN i.quantity <= i.critical_level THEN 'Critical'
                   WHEN i.quantity <= i.reorder_level THEN 'Low Stock'
                   ELSE 'Normal'
               END as alert_type_display,
               CASE 
                   WHEN i.expiry_date IS NOT NULL AND DATEDIFF(i.expiry_date, CURDATE()) <= 7 THEN 1
                   ELSE 0
               END as expiring_soon
        FROM inventory i
        LEFT JOIN users u ON i.created_by = u.id
        LEFT JOIN users ack_user ON i.alert_acknowledged_by = ack_user.id
        $whereClause
        ORDER BY 
            CASE WHEN i.alert_status = 'critical' THEN 1 
                 WHEN i.alert_status = 'low_stock' THEN 2 
                 ELSE 3 END,
            i.alert_acknowledged ASC,
            i.updated_at DESC
    ");
    $stmt->execute($params);
    $alertItems = $stmt->fetchAll();
    
    // Get summary statistics
    $summaryStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_alerts,
            COUNT(CASE WHEN alert_status = 'critical' THEN 1 END) as critical_alerts,
            COUNT(CASE WHEN alert_status = 'low_stock' THEN 1 END) as low_stock_alerts,
            COUNT(CASE WHEN alert_acknowledged = 1 THEN 1 END) as acknowledged_alerts,
            COUNT(CASE WHEN DATEDIFF(expiry_date, CURDATE()) <= 7 AND status = 'active' THEN 1 END) as expiring_alerts
        FROM inventory i
        $whereClause
    ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch();
    
} catch (Exception $e) {
    error_log("Stock alerts error: " . $e->getMessage());
    $alertItems = [];
    $summary = ['total_alerts' => 0, 'critical_alerts' => 0, 'low_stock_alerts' => 0, 'acknowledged_alerts' => 0, 'expiring_alerts' => 0];
}

$pageTitle = 'Stock Alerts';
include '../../includes/header.php';
?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                Stock Alerts
            </h1>
            <p class="text-muted mb-0">Monitor and manage inventory alerts, low stock warnings, and critical levels</p>
        </div>
        <div>
            <button type="button" class="btn btn-outline-primary" onclick="location.reload()">
                <i class="fas fa-sync-alt me-2"></i>Refresh
            </button>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Inventory
            </a>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card">
                <div class="stats-icon primary">
                    <i class="fas fa-bell"></i>
                </div>
                <div class="stats-number"><?php echo number_format($summary['total_alerts']); ?></div>
                <div class="stats-label">Total Alerts</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card danger">
                <div class="stats-icon danger">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <div class="stats-number"><?php echo number_format($summary['critical_alerts']); ?></div>
                <div class="stats-label">Critical</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card warning">
                <div class="stats-icon warning">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stats-number"><?php echo number_format($summary['low_stock_alerts']); ?></div>
                <div class="stats-label">Low Stock</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card info">
                <div class="stats-icon info">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-number"><?php echo number_format($summary['expiring_alerts']); ?></div>
                <div class="stats-label">Expiring Soon</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card success">
                <div class="stats-icon success">
                    <i class="fas fa-check"></i>
                </div>
                <div class="stats-number"><?php echo number_format($summary['acknowledged_alerts']); ?></div>
                <div class="stats-label">Acknowledged</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card secondary">
                <div class="stats-icon secondary">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stats-number">
                    <?php echo $summary['total_alerts'] > 0 ? round(($summary['acknowledged_alerts'] / $summary['total_alerts']) * 100, 1) : 0; ?>%
                </div>
                <div class="stats-label">Response Rate</div>
            </div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <?php if ($userInfo['role'] === 'admin'): ?>
                <div class="col-md-2">
                    <label for="section" class="form-label">Section</label>
                    <select name="section" id="section" class="form-select">
                        <option value="">All Sections</option>
                        <option value="1" <?php echo $section == '1' ? 'selected' : ''; ?>>Section 1 - Raw Materials</option>
                        <option value="2" <?php echo $section == '2' ? 'selected' : ''; ?>>Section 2 - Processing</option>
                        <option value="3" <?php echo $section == '3' ? 'selected' : ''; ?>>Section 3 - Packaging</option>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="col-md-2">
                    <label for="alert_type" class="form-label">Alert Type</label>
                    <select name="alert_type" id="alert_type" class="form-select">
                        <option value="">All Alerts</option>
                        <option value="critical" <?php echo $alert_type == 'critical' ? 'selected' : ''; ?>>Critical</option>
                        <option value="low_stock" <?php echo $alert_type == 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="acknowledged" class="form-label">Status</label>
                    <select name="acknowledged" id="acknowledged" class="form-select">
                        <option value="">All Status</option>
                        <option value="0" <?php echo $acknowledged === '0' ? 'selected' : ''; ?>>Unacknowledged</option>
                        <option value="1" <?php echo $acknowledged === '1' ? 'selected' : ''; ?>>Acknowledged</option>
                    </select>
                </div>
                
                <div class="col-md-4">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" name="search" id="search" class="form-control" 
                           placeholder="Search by item name, code, or description..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary d-block">
                        <i class="fas fa-search"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Alerts Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Stock Alerts
                <span class="badge bg-primary ms-2"><?php echo count($alertItems); ?></span>
            </h5>
            <div>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="exportData('csv')">
                    <i class="fas fa-download me-1"></i>Export CSV
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($alertItems)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-check-circle text-success" style="font-size: 3rem;"></i>
                    <h4 class="mt-3">No Active Alerts</h4>
                    <p class="text-muted">All inventory levels are within normal ranges.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Item Details</th>
                                <th>Section</th>
                                <th>Current Stock</th>
                                <th>Levels</th>
                                <th>Alert Type</th>
                                <th>Status</th>
                                <th>Expiry</th>
                                <th>Last Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alertItems as $item): ?>
                                <tr class="<?php echo $item['alert_status'] === 'critical' ? 'table-danger' : 'table-warning'; ?>">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="me-3">
                                                <?php if ($item['alert_status'] === 'critical'): ?>
                                                    <i class="fas fa-exclamation-circle text-danger fa-lg"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-exclamation-triangle text-warning fa-lg"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    Code: <?php echo htmlspecialchars($item['item_code']); ?>
                                                    <?php if ($item['category']): ?>
                                                        | <?php echo htmlspecialchars($item['category']); ?>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            Section <?php echo $item['section']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong class="<?php echo $item['alert_status'] === 'critical' ? 'text-danger' : 'text-warning'; ?>">
                                            <?php echo number_format($item['quantity']); ?>
                                        </strong>
                                        <?php if ($item['unit']): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($item['unit']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small>
                                            Reorder: <strong><?php echo number_format($item['reorder_level'] ?? 0); ?></strong><br>
                                            Critical: <strong class="text-danger"><?php echo number_format($item['critical_level'] ?? 0); ?></strong>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $item['alert_status'] === 'critical' ? 'danger' : 'warning'; ?>">
                                            <?php echo $item['alert_type_display']; ?>
                                        </span>
                                        <?php if ($item['expiring_soon']): ?>
                                            <br><span class="badge bg-info mt-1">Expiring Soon</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['alert_acknowledged']): ?>
                                            <span class="badge bg-success">Acknowledged</span>
                                            <br>
                                            <small class="text-muted">
                                                by <?php echo htmlspecialchars($item['acknowledged_by_name'] ?? 'Unknown'); ?>
                                                <br><?php echo formatDate($item['alert_acknowledged_at']); ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['expiry_date']): ?>
                                            <?php 
                                            $daysToExpiry = (strtotime($item['expiry_date']) - time()) / (24 * 60 * 60);
                                            $expiryClass = $daysToExpiry <= 7 ? 'text-danger' : ($daysToExpiry <= 30 ? 'text-warning' : '');
                                            ?>
                                            <span class="<?php echo $expiryClass; ?>">
                                                <?php echo formatDate($item['expiry_date']); ?>
                                            </span>
                                            <?php if ($daysToExpiry <= 7 && $daysToExpiry > 0): ?>
                                                <br><small class="text-danger"><?php echo floor($daysToExpiry); ?> days left</small>
                                            <?php elseif ($daysToExpiry <= 0): ?>
                                                <br><small class="text-danger"><strong>EXPIRED</strong></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php echo formatDate($item['updated_at']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="viewItemDetails(<?php echo $item['id']; ?>)" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if (!$item['alert_acknowledged']): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success" 
                                                        onclick="acknowledgeAlert(<?php echo $item['id']; ?>)" title="Acknowledge">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline-warning" 
                                                    onclick="updateStock(<?php echo $item['id']; ?>)" title="Update Stock">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-info" 
                                                    onclick="setReorderLevel(<?php echo $item['id']; ?>)" title="Set Levels">
                                                <i class="fas fa-cog"></i>
                                            </button>
                                        </div>
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

<!-- Modals -->
<!-- Item Details Modal -->
<div class="modal fade" id="itemDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Item Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="itemDetailsContent">
                <!-- Content loaded via JavaScript -->
            </div>
        </div>
    </div>
</div>

<!-- Update Stock Modal -->
<div class="modal fade" id="updateStockModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Update Stock Quantity</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_stock">
                    <input type="hidden" name="item_id" id="updateStockItemId">
                    
                    <div class="mb-3">
                        <label for="new_quantity" class="form-label">New Quantity</label>
                        <input type="number" class="form-control" name="new_quantity" id="new_quantity" 
                               min="0" step="0.01" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" name="notes" id="notes" rows="3" 
                                  placeholder="Reason for stock update..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Set Reorder Level Modal -->
<div class="modal fade" id="reorderLevelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Set Reorder Levels</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="set_reorder_level">
                    <input type="hidden" name="item_id" id="reorderLevelItemId">
                    
                    <div class="mb-3">
                        <label for="reorder_level" class="form-label">Reorder Level</label>
                        <input type="number" class="form-control" name="reorder_level" id="reorder_level" 
                               min="0" step="0.01" required>
                        <div class="form-text">Quantity at which to trigger low stock alert</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="critical_level" class="form-label">Critical Level</label>
                        <input type="number" class="form-control" name="critical_level" id="critical_level" 
                               min="0" step="0.01" required>
                        <div class="form-text">Quantity at which to trigger critical alert</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Levels</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Acknowledge alert
function acknowledgeAlert(itemId) {
    if (confirm('Are you sure you want to acknowledge this alert?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="acknowledge">
            <input type="hidden" name="item_id" value="${itemId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Update stock modal
function updateStock(itemId) {
    document.getElementById('updateStockItemId').value = itemId;
    document.getElementById('new_quantity').value = '';
    document.getElementById('notes').value = '';
    new bootstrap.Modal(document.getElementById('updateStockModal')).show();
}

// Set reorder level modal
function setReorderLevel(itemId) {
    document.getElementById('reorderLevelItemId').value = itemId;
    document.getElementById('reorder_level').value = '';
    document.getElementById('critical_level').value = '';
    new bootstrap.Modal(document.getElementById('reorderLevelModal')).show();
}

// View item details
function viewItemDetails(itemId) {
    const modal = new bootstrap.Modal(document.getElementById('itemDetailsModal'));
    const content = document.getElementById('itemDetailsContent');
    
    content.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    modal.show();
    
    // Load item details via AJAX (you can implement this endpoint)
    fetch(`get_item_details.php?id=${itemId}`)
        .then(response => response.text())
        .then(data => {
            content.innerHTML = data;
        })
        .catch(error => {
            content.innerHTML = '<div class="alert alert-danger">Error loading item details.</div>';
        });
}

// Export data
function exportData(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    window.location.href = 'export_alerts.php?' + params.toString();
}

// Auto-refresh every 2 minutes for alerts
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 120000);

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 'r') {
        e.preventDefault();
        location.reload();
    }
});
</script>

<?php include '../../includes/footer.php'; ?>