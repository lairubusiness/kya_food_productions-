<?php
/**
 * KYA Food Production - Expiry Tracking Management
 * Monitor and manage item expiration dates, expired items, and expiring soon alerts
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

// Handle expiry actions
if ($_POST['action'] ?? false) {
    $action = $_POST['action'];
    $itemId = $_POST['item_id'] ?? 0;
    
    try {
        switch ($action) {
            case 'mark_expired':
                $stmt = $conn->prepare("UPDATE inventory SET status = 'expired', updated_at = NOW(), updated_by = ? WHERE id = ?");
                $stmt->execute([$userInfo['id'], $itemId]);
                $success_message = "Item marked as expired successfully.";
                break;
                
            case 'extend_expiry':
                $newExpiryDate = $_POST['new_expiry_date'] ?? '';
                $notes = $_POST['notes'] ?? '';
                
                $stmt = $conn->prepare("UPDATE inventory SET expiry_date = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
                $stmt->execute([$newExpiryDate, $userInfo['id'], $itemId]);
                
                // Log expiry extension
                $logStmt = $conn->prepare("INSERT INTO inventory_logs (inventory_id, action, notes, created_by, created_at) VALUES (?, 'expiry_extended', ?, ?, NOW())");
                $logStmt->execute([$itemId, "Expiry extended to $newExpiryDate. $notes", $userInfo['id']]);
                
                $success_message = "Expiry date extended successfully.";
                break;
                
            case 'dispose_item':
                $disposalReason = $_POST['disposal_reason'] ?? '';
                $disposalQuantity = $_POST['disposal_quantity'] ?? 0;
                
                // Update inventory
                $stmt = $conn->prepare("UPDATE inventory SET quantity = quantity - ?, status = 'disposed', updated_at = NOW(), updated_by = ? WHERE id = ?");
                $stmt->execute([$disposalQuantity, $userInfo['id'], $itemId]);
                
                // Log disposal
                $logStmt = $conn->prepare("INSERT INTO inventory_logs (inventory_id, action, old_quantity, new_quantity, notes, created_by, created_at) VALUES (?, 'disposal', (SELECT quantity + ? FROM inventory WHERE id = ?), (SELECT quantity FROM inventory WHERE id = ?), ?, ?, NOW())");
                $logStmt->execute([$itemId, $disposalQuantity, $itemId, $itemId, "Disposed: $disposalReason", $userInfo['id']]);
                
                $success_message = "Item disposal recorded successfully.";
                break;
        }
    } catch (Exception $e) {
        error_log("Expiry tracking action error: " . $e->getMessage());
        $error_message = "An error occurred while processing the request.";
    }
}

// Get filter parameters
$section = $_GET['section'] ?? '';
$expiry_status = $_GET['expiry_status'] ?? '';
$days_range = $_GET['days_range'] ?? '30';
$search = $_GET['search'] ?? '';

// Build query conditions
$whereConditions = ["expiry_date IS NOT NULL"];
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

// Expiry status filter
if ($expiry_status) {
    switch ($expiry_status) {
        case 'expired':
            $whereConditions[] = "expiry_date < CURDATE()";
            break;
        case 'expiring_soon':
            $whereConditions[] = "expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)";
            $params[] = $days_range;
            break;
        case 'valid':
            $whereConditions[] = "expiry_date > DATE_ADD(CURDATE(), INTERVAL ? DAY)";
            $params[] = $days_range;
            break;
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

// Get expiry items
try {
    $stmt = $conn->prepare("
        SELECT i.*, 
               u.full_name as created_by_name,
               DATEDIFF(i.expiry_date, CURDATE()) as days_to_expiry,
               CASE 
                   WHEN i.expiry_date < CURDATE() THEN 'Expired'
                   WHEN DATEDIFF(i.expiry_date, CURDATE()) <= 7 THEN 'Critical'
                   WHEN DATEDIFF(i.expiry_date, CURDATE()) <= 30 THEN 'Warning'
                   ELSE 'Normal'
               END as expiry_status_display,
               (i.quantity * COALESCE(i.unit_cost, 0)) as total_value
        FROM inventory i
        LEFT JOIN users u ON i.created_by = u.id
        $whereClause
        ORDER BY 
            CASE WHEN i.expiry_date < CURDATE() THEN 1 
                 WHEN DATEDIFF(i.expiry_date, CURDATE()) <= 7 THEN 2 
                 WHEN DATEDIFF(i.expiry_date, CURDATE()) <= 30 THEN 3
                 ELSE 4 END,
            i.expiry_date ASC
    ");
    $stmt->execute($params);
    $expiryItems = $stmt->fetchAll();
    
    // Get summary statistics
    $summaryStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_items,
            COUNT(CASE WHEN expiry_date < CURDATE() THEN 1 END) as expired_items,
            COUNT(CASE WHEN DATEDIFF(expiry_date, CURDATE()) BETWEEN 0 AND 7 THEN 1 END) as critical_items,
            COUNT(CASE WHEN DATEDIFF(expiry_date, CURDATE()) BETWEEN 8 AND 30 THEN 1 END) as warning_items,
            SUM(CASE WHEN expiry_date < CURDATE() THEN quantity * COALESCE(unit_cost, 0) ELSE 0 END) as expired_value,
            SUM(quantity * COALESCE(unit_cost, 0)) as total_value
        FROM inventory i
        $whereClause
    ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch();
    
} catch (Exception $e) {
    error_log("Expiry tracking error: " . $e->getMessage());
    $expiryItems = [];
    $summary = ['total_items' => 0, 'expired_items' => 0, 'critical_items' => 0, 'warning_items' => 0, 'expired_value' => 0, 'total_value' => 0];
}

$pageTitle = 'Expiry Tracking';
include '../../includes/header.php';
?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-calendar-times text-danger me-2"></i>
                Expiry Tracking
            </h1>
            <p class="text-muted mb-0">Monitor and manage item expiration dates and expired inventory</p>
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
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="stats-number"><?php echo number_format($summary['total_items']); ?></div>
                <div class="stats-label">Total Items</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card danger">
                <div class="stats-icon danger">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stats-number"><?php echo number_format($summary['expired_items']); ?></div>
                <div class="stats-label">Expired</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card warning">
                <div class="stats-icon warning">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stats-number"><?php echo number_format($summary['critical_items']); ?></div>
                <div class="stats-label">Critical (≤7 days)</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card info">
                <div class="stats-icon info">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-number"><?php echo number_format($summary['warning_items']); ?></div>
                <div class="stats-label">Warning (≤30 days)</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card secondary">
                <div class="stats-icon secondary">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stats-number"><?php echo formatCurrency($summary['expired_value']); ?></div>
                <div class="stats-label">Expired Value</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card success">
                <div class="stats-icon success">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stats-number">
                    <?php echo $summary['total_value'] > 0 ? round((($summary['total_value'] - $summary['expired_value']) / $summary['total_value']) * 100, 1) : 100; ?>%
                </div>
                <div class="stats-label">Valid Rate</div>
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
                    <label for="expiry_status" class="form-label">Expiry Status</label>
                    <select name="expiry_status" id="expiry_status" class="form-select">
                        <option value="">All Items</option>
                        <option value="expired" <?php echo $expiry_status == 'expired' ? 'selected' : ''; ?>>Expired</option>
                        <option value="expiring_soon" <?php echo $expiry_status == 'expiring_soon' ? 'selected' : ''; ?>>Expiring Soon</option>
                        <option value="valid" <?php echo $expiry_status == 'valid' ? 'selected' : ''; ?>>Valid</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="days_range" class="form-label">Days Range</label>
                    <select name="days_range" id="days_range" class="form-select">
                        <option value="7" <?php echo $days_range == '7' ? 'selected' : ''; ?>>7 days</option>
                        <option value="15" <?php echo $days_range == '15' ? 'selected' : ''; ?>>15 days</option>
                        <option value="30" <?php echo $days_range == '30' ? 'selected' : ''; ?>>30 days</option>
                        <option value="60" <?php echo $days_range == '60' ? 'selected' : ''; ?>>60 days</option>
                        <option value="90" <?php echo $days_range == '90' ? 'selected' : ''; ?>>90 days</option>
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
    
    <!-- Expiry Items Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Expiry Items
                <span class="badge bg-primary ms-2"><?php echo count($expiryItems); ?></span>
            </h5>
            <div>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="exportData('csv')">
                    <i class="fas fa-download me-1"></i>Export CSV
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($expiryItems)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-check-circle text-success" style="font-size: 3rem;"></i>
                    <h4 class="mt-3">No Items Found</h4>
                    <p class="text-muted">No items match the current filter criteria.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Item Details</th>
                                <th>Section</th>
                                <th>Quantity</th>
                                <th>Expiry Date</th>
                                <th>Days Left</th>
                                <th>Status</th>
                                <th>Value</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expiryItems as $item): ?>
                                <?php
                                $rowClass = '';
                                if ($item['days_to_expiry'] < 0) $rowClass = 'table-danger';
                                elseif ($item['days_to_expiry'] <= 7) $rowClass = 'table-warning';
                                ?>
                                <tr class="<?php echo $rowClass; ?>">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="me-3">
                                                <?php if ($item['days_to_expiry'] < 0): ?>
                                                    <i class="fas fa-times-circle text-danger fa-lg"></i>
                                                <?php elseif ($item['days_to_expiry'] <= 7): ?>
                                                    <i class="fas fa-exclamation-triangle text-warning fa-lg"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-clock text-info fa-lg"></i>
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
                                        <strong><?php echo number_format($item['quantity']); ?></strong>
                                        <?php if ($item['unit']): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($item['unit']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="<?php echo $item['days_to_expiry'] < 0 ? 'text-danger' : ($item['days_to_expiry'] <= 7 ? 'text-warning' : ''); ?>">
                                            <?php echo formatDate($item['expiry_date']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($item['days_to_expiry'] < 0): ?>
                                            <span class="badge bg-danger">
                                                Expired <?php echo abs($item['days_to_expiry']); ?> days ago
                                            </span>
                                        <?php elseif ($item['days_to_expiry'] == 0): ?>
                                            <span class="badge bg-danger">Expires Today</span>
                                        <?php elseif ($item['days_to_expiry'] <= 7): ?>
                                            <span class="badge bg-warning">
                                                <?php echo $item['days_to_expiry']; ?> days left
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success">
                                                <?php echo $item['days_to_expiry']; ?> days left
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $item['expiry_status_display'] == 'Expired' ? 'danger' : 
                                                ($item['expiry_status_display'] == 'Critical' ? 'warning' : 
                                                ($item['expiry_status_display'] == 'Warning' ? 'info' : 'success')); 
                                        ?>">
                                            <?php echo $item['expiry_status_display']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo formatCurrency($item['total_value']); ?></strong>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <?php if ($item['days_to_expiry'] < 0 && $item['status'] !== 'expired'): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                                        onclick="markExpired(<?php echo $item['id']; ?>)" title="Mark as Expired">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($item['days_to_expiry'] <= 30): ?>
                                                <button type="button" class="btn btn-sm btn-outline-warning" 
                                                        onclick="extendExpiry(<?php echo $item['id']; ?>)" title="Extend Expiry">
                                                    <i class="fas fa-calendar-plus"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($item['days_to_expiry'] < 0): ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                        onclick="disposeItem(<?php echo $item['id']; ?>)" title="Dispose Item">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="viewItemDetails(<?php echo $item['id']; ?>)" title="View Details">
                                                <i class="fas fa-eye"></i>
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
<!-- Extend Expiry Modal -->
<div class="modal fade" id="extendExpiryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Extend Expiry Date</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="extend_expiry">
                    <input type="hidden" name="item_id" id="extendExpiryItemId">
                    
                    <div class="mb-3">
                        <label for="new_expiry_date" class="form-label">New Expiry Date</label>
                        <input type="date" class="form-control" name="new_expiry_date" id="new_expiry_date" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" name="notes" id="notes" rows="3" 
                                  placeholder="Reason for expiry extension..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Extend Expiry</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Dispose Item Modal -->
<div class="modal fade" id="disposeItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Dispose Expired Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="dispose_item">
                    <input type="hidden" name="item_id" id="disposeItemId">
                    
                    <div class="mb-3">
                        <label for="disposal_quantity" class="form-label">Disposal Quantity</label>
                        <input type="number" class="form-control" name="disposal_quantity" id="disposal_quantity" 
                               min="0" step="0.01" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="disposal_reason" class="form-label">Disposal Reason</label>
                        <select class="form-select" name="disposal_reason" id="disposal_reason" required>
                            <option value="">Select reason...</option>
                            <option value="Expired">Expired</option>
                            <option value="Damaged">Damaged</option>
                            <option value="Contaminated">Contaminated</option>
                            <option value="Quality Issues">Quality Issues</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Dispose Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Mark item as expired
function markExpired(itemId) {
    if (confirm('Are you sure you want to mark this item as expired?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="mark_expired">
            <input type="hidden" name="item_id" value="${itemId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Extend expiry modal
function extendExpiry(itemId) {
    document.getElementById('extendExpiryItemId').value = itemId;
    document.getElementById('new_expiry_date').value = '';
    document.getElementById('notes').value = '';
    new bootstrap.Modal(document.getElementById('extendExpiryModal')).show();
}

// Dispose item modal
function disposeItem(itemId) {
    document.getElementById('disposeItemId').value = itemId;
    document.getElementById('disposal_quantity').value = '';
    document.getElementById('disposal_reason').value = '';
    new bootstrap.Modal(document.getElementById('disposeItemModal')).show();
}

// View item details
function viewItemDetails(itemId) {
    window.open(`view_item.php?id=${itemId}`, '_blank');
}

// Export data
function exportData(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    window.location.href = 'export_expiry.php?' + params.toString();
}

// Auto-refresh every 5 minutes
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 300000);
</script>

<?php include '../../includes/footer.php'; ?>