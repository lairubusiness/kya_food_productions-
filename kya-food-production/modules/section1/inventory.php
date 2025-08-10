<?php
/**
 * KYA Food Production - Section 1 Inventory Management
 * Raw Material Handling Inventory
 */

require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

SessionManager::start();
SessionManager::requireLogin();
SessionManager::requireSection(1);

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

// Get filter parameters
$category = $_GET['category'] ?? '';
$status = $_GET['status'] ?? '';
$alert_status = $_GET['alert_status'] ?? '';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$order = $_GET['order'] ?? 'DESC';

// Build query conditions - always filter by section 1
$whereConditions = ['section = 1'];
$params = [1];

if ($category) {
    $whereConditions[] = "category = ?";
    $params[] = $category;
}

if ($status) {
    $whereConditions[] = "status = ?";
    $params[] = $status;
}

if ($alert_status) {
    $whereConditions[] = "alert_status = ?";
    $params[] = $alert_status;
}

if ($search) {
    $whereConditions[] = "(item_name LIKE ? OR item_code LIKE ? OR description LIKE ? OR supplier LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// Validate sort column
$allowedSorts = ['item_name', 'item_code', 'category', 'quantity', 'unit_cost', 'expiry_date', 'created_at', 'alert_status'];
if (!in_array($sort, $allowedSorts)) {
    $sort = 'created_at';
}

$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

// Get inventory items for Section 1
try {
    $stmt = $conn->prepare("
        SELECT i.*, u.full_name as created_by_name,
               (quantity * COALESCE(unit_cost, 0)) as total_value,
               CASE 
                   WHEN expiry_date IS NOT NULL THEN DATEDIFF(expiry_date, CURDATE())
                   ELSE NULL 
               END as days_to_expiry
        FROM inventory i
        LEFT JOIN users u ON i.created_by = u.id
        $whereClause
        ORDER BY $sort $order
    ");
    $stmt->execute($params);
    $inventoryItems = $stmt->fetchAll();
    
    // Get summary statistics for Section 1
    $summaryStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_items,
            SUM(quantity) as total_quantity,
            SUM(quantity * COALESCE(unit_cost, 0)) as total_value,
            COUNT(CASE WHEN alert_status IN ('low_stock', 'critical') THEN 1 END) as alert_items,
            COUNT(CASE WHEN DATEDIFF(expiry_date, CURDATE()) <= 7 AND status = 'active' THEN 1 END) as expiring_items,
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active_items,
            COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_items
        FROM inventory i
        $whereClause
    ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch();
    
    // Get filter options for Section 1
    $categoriesStmt = $conn->prepare("SELECT DISTINCT category FROM inventory WHERE section = 1 AND category IS NOT NULL ORDER BY category");
    $categoriesStmt->execute();
    $categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch (Exception $e) {
    error_log("Section 1 inventory error: " . $e->getMessage());
    $inventoryItems = [];
    $summary = ['total_items' => 0, 'total_quantity' => 0, 'total_value' => 0, 'alert_items' => 0, 'expiring_items' => 0, 'active_items' => 0, 'inactive_items' => 0];
    $categories = [];
}

$pageTitle = 'Section 1 - Raw Material Inventory';
include '../../includes/header.php';
?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <span class="badge me-2" style="background-color: #2c5f41;">
                    Section 1
                </span>
                Raw Material Inventory
            </h1>
            <p class="text-muted mb-0">Manage raw materials, ingredients, and supplies</p>
        </div>
        <div class="btn-group" role="group">
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
            <a href="../inventory/add_item.php?section=1" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Add New Item
            </a>
        </div>
    </div>
    
    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card border-primary">
                <div class="card-body text-center">
                    <div class="text-primary mb-2">
                        <i class="fas fa-boxes fa-2x"></i>
                    </div>
                    <h4 class="mb-1"><?php echo number_format($summary['total_items']); ?></h4>
                    <p class="text-muted mb-0">Total Items</p>
                    <small class="text-muted">Raw Materials</small>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card border-success">
                <div class="card-body text-center">
                    <div class="text-success mb-2">
                        <i class="fas fa-check-circle fa-2x"></i>
                    </div>
                    <h4 class="mb-1"><?php echo number_format($summary['active_items']); ?></h4>
                    <p class="text-muted mb-0">Active Items</p>
                    <small class="text-muted">In use</small>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card border-info">
                <div class="card-body text-center">
                    <div class="text-info mb-2">
                        <i class="fas fa-dollar-sign fa-2x"></i>
                    </div>
                    <h4 class="mb-1"><?php echo formatCurrency($summary['total_value']); ?></h4>
                    <p class="text-muted mb-0">Total Value</p>
                    <small class="text-muted">Current stock</small>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card border-warning">
                <div class="card-body text-center">
                    <div class="text-warning mb-2">
                        <i class="fas fa-exclamation-triangle fa-2x"></i>
                    </div>
                    <h4 class="mb-1"><?php echo number_format($summary['alert_items']); ?></h4>
                    <p class="text-muted mb-0">Alert Items</p>
                    <small class="text-muted">Need attention</small>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card border-danger">
                <div class="card-body text-center">
                    <div class="text-danger mb-2">
                        <i class="fas fa-clock fa-2x"></i>
                    </div>
                    <h4 class="mb-1"><?php echo number_format($summary['expiring_items']); ?></h4>
                    <p class="text-muted mb-0">Expiring Soon</p>
                    <small class="text-muted">Within 7 days</small>
                </div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card border-secondary">
                <div class="card-body text-center">
                    <div class="text-secondary mb-2">
                        <i class="fas fa-weight-hanging fa-2x"></i>
                    </div>
                    <h4 class="mb-1"><?php echo number_format($summary['total_quantity']); ?></h4>
                    <p class="text-muted mb-0">Total Quantity</p>
                    <small class="text-muted">All units</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Filters & Search</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label for="category" class="form-label">Category</label>
                    <select name="category" id="category" class="form-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($cat)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="alert_status" class="form-label">Alert Status</label>
                    <select name="alert_status" id="alert_status" class="form-select">
                        <option value="">All Alerts</option>
                        <option value="normal" <?php echo $alert_status === 'normal' ? 'selected' : ''; ?>>Normal</option>
                        <option value="low_stock" <?php echo $alert_status === 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                        <option value="critical" <?php echo $alert_status === 'critical' ? 'selected' : ''; ?>>Critical</option>
                        <option value="expiring_soon" <?php echo $alert_status === 'expiring_soon' ? 'selected' : ''; ?>>Expiring Soon</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="sort" class="form-label">Sort By</label>
                    <select name="sort" id="sort" class="form-select">
                        <option value="created_at" <?php echo $sort === 'created_at' ? 'selected' : ''; ?>>Date Added</option>
                        <option value="item_name" <?php echo $sort === 'item_name' ? 'selected' : ''; ?>>Item Name</option>
                        <option value="category" <?php echo $sort === 'category' ? 'selected' : ''; ?>>Category</option>
                        <option value="quantity" <?php echo $sort === 'quantity' ? 'selected' : ''; ?>>Quantity</option>
                        <option value="expiry_date" <?php echo $sort === 'expiry_date' ? 'selected' : ''; ?>>Expiry Date</option>
                        <option value="alert_status" <?php echo $sort === 'alert_status' ? 'selected' : ''; ?>>Alert Status</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" name="search" id="search" class="form-control" 
                           placeholder="Search items, codes, suppliers..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary d-block">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
                <input type="hidden" name="order" value="<?php echo $order; ?>">
            </form>
        </div>
    </div>
    
    <!-- Inventory Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                Raw Material Inventory 
                <span class="badge bg-primary"><?php echo count($inventoryItems); ?> items</span>
            </h5>
            <div class="btn-group btn-group-sm" role="group">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['order' => $order === 'ASC' ? 'DESC' : 'ASC'])); ?>" 
                   class="btn btn-outline-secondary">
                    <i class="fas fa-sort"></i> 
                    <?php echo $order === 'ASC' ? 'Desc' : 'Asc'; ?>
                </a>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($inventoryItems)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-boxes fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No inventory items found</h5>
                    <p class="text-muted">Try adjusting your filters or add new items to get started.</p>
                    <a href="../inventory/add_item.php?section=1" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Add First Item
                    </a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Item Details</th>
                                <th>Category</th>
                                <th>Quantity</th>
                                <th>Unit Cost</th>
                                <th>Total Value</th>
                                <th>Status</th>
                                <th>Alert</th>
                                <th>Expiry Date</th>
                                <th>Supplier</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventoryItems as $item): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                            <?php if ($item['item_code']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($item['item_code']); ?></small>
                                            <?php endif; ?>
                                            <?php if ($item['description']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars(substr($item['description'], 0, 50)) . (strlen($item['description']) > 50 ? '...' : ''); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo htmlspecialchars(ucfirst($item['category'] ?? 'Uncategorized')); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo number_format($item['quantity'], 2); ?></strong>
                                        <?php if ($item['unit']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($item['unit']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($item['minimum_stock'] && $item['quantity'] <= $item['minimum_stock']): ?>
                                            <br><small class="text-danger">Below minimum</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $item['unit_cost'] ? formatCurrency($item['unit_cost']) : '-'; ?></td>
                                    <td>
                                        <strong><?php echo $item['total_value'] ? formatCurrency($item['total_value']) : '-'; ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $item['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                            <?php echo ucfirst($item['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($item['alert_status'] !== 'normal'): ?>
                                            <span class="badge bg-<?php echo $item['alert_status'] === 'critical' ? 'danger' : 'warning'; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $item['alert_status'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Normal</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['expiry_date']): ?>
                                            <?php 
                                            $daysToExpiry = $item['days_to_expiry'];
                                            $expiryClass = $daysToExpiry <= 7 ? 'text-danger' : ($daysToExpiry <= 30 ? 'text-warning' : '');
                                            ?>
                                            <span class="<?php echo $expiryClass; ?>">
                                                <?php echo formatDate($item['expiry_date']); ?>
                                            </span>
                                            <?php if ($daysToExpiry <= 7 && $daysToExpiry > 0): ?>
                                                <br><small class="text-danger"><?php echo floor($daysToExpiry); ?> days left</small>
                                            <?php elseif ($daysToExpiry < 0): ?>
                                                <br><small class="text-danger">Expired</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($item['supplier']): ?>
                                            <small><?php echo htmlspecialchars($item['supplier']); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="../inventory/view_item.php?id=<?php echo $item['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="../inventory/edit_item.php?id=<?php echo $item['id']; ?>" 
                                               class="btn btn-sm btn-outline-secondary" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
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

<script>
// Auto-refresh every 5 minutes
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 300000);

// Add keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        window.location.href = '../inventory/add_item.php?section=1';
    }
    if (e.ctrlKey && e.key === 'f') {
        e.preventDefault();
        document.getElementById('search').focus();
    }
});
</script>

<?php include '../../includes/footer.php'; ?>