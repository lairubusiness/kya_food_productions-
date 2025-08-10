<?php
/**
 * KYA Food Production - Inventory Management Dashboard
 * Main inventory overview and management interface
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

// Get filter parameters
$section = $_GET['section'] ?? '';
$category = $_GET['category'] ?? '';
$status = $_GET['status'] ?? '';
$alert_status = $_GET['alert_status'] ?? '';
$search = $_GET['search'] ?? '';

// Build query conditions
$whereConditions = [];
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
    $whereConditions[] = "(item_name LIKE ? OR item_code LIKE ? OR description LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get inventory items
try {
    $stmt = $conn->prepare("
        SELECT i.*, u.full_name as created_by_name
        FROM inventory i
        LEFT JOIN users u ON i.created_by = u.id
        $whereClause
        ORDER BY i.created_at DESC
    ");
    $stmt->execute($params);
    $inventoryItems = $stmt->fetchAll();
    
    // Get summary statistics
    $summaryStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_items,
            SUM(quantity * COALESCE(unit_cost, 0)) as total_value,
            COUNT(CASE WHEN alert_status IN ('low_stock', 'critical') THEN 1 END) as alert_items,
            COUNT(CASE WHEN DATEDIFF(expiry_date, CURDATE()) <= 7 AND status = 'active' THEN 1 END) as expiring_items
        FROM inventory i
        $whereClause
    ");
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch();
    
    // Get filter options
    $categoriesStmt = $conn->prepare("SELECT DISTINCT category FROM inventory ORDER BY category");
    $categoriesStmt->execute();
    $categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch (Exception $e) {
    error_log("Inventory index error: " . $e->getMessage());
    $inventoryItems = [];
    $summary = ['total_items' => 0, 'total_value' => 0, 'alert_items' => 0, 'expiring_items' => 0];
    $categories = [];
}

$pageTitle = 'Inventory Management';
include '../../includes/header.php';
?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Inventory Management</h1>
            <p class="text-muted mb-0">Manage and monitor your inventory across all sections</p>
        </div>
        <div>
            <?php if (SessionManager::hasPermission('inventory_manage')): ?>
                <a href="add_item.php" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>Add New Item
                </a>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card">
                <div class="stats-icon primary">
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="stats-number"><?php echo number_format($summary['total_items']); ?></div>
                <div class="stats-label">Total Items</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card success">
                <div class="stats-icon success">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stats-number"><?php echo formatCurrency($summary['total_value']); ?></div>
                <div class="stats-label">Total Value</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card warning">
                <div class="stats-icon warning">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stats-number"><?php echo number_format($summary['alert_items']); ?></div>
                <div class="stats-label">Alert Items</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card danger">
                <div class="stats-icon danger">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-number"><?php echo number_format($summary['expiring_items']); ?></div>
                <div class="stats-label">Expiring Soon</div>
            </div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label for="section" class="form-label">Section</label>
                    <select name="section" id="section" class="form-select">
                        <option value="">All Sections</option>
                        <?php if ($userInfo['role'] === 'admin' || in_array(1, $userInfo['sections'])): ?>
                            <option value="1" <?php echo $section == '1' ? 'selected' : ''; ?>>Section 1 - Raw Materials</option>
                        <?php endif; ?>
                        <?php if ($userInfo['role'] === 'admin' || in_array(2, $userInfo['sections'])): ?>
                            <option value="2" <?php echo $section == '2' ? 'selected' : ''; ?>>Section 2 - Processing</option>
                        <?php endif; ?>
                        <?php if ($userInfo['role'] === 'admin' || in_array(3, $userInfo['sections'])): ?>
                            <option value="3" <?php echo $section == '3' ? 'selected' : ''; ?>>Section 3 - Packaging</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="category" class="form-label">Category</label>
                    <select name="category" id="category" class="form-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">All Status</option>
                        <?php foreach (INVENTORY_STATUS as $key => $statusInfo): ?>
                            <option value="<?php echo $key; ?>" <?php echo $status === $key ? 'selected' : ''; ?>>
                                <?php echo $statusInfo['name']; ?>
                            </option>
                        <?php endforeach; ?>
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
                <div class="col-md-3">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" name="search" id="search" class="form-control" 
                           placeholder="Search items..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary d-block">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Inventory Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Inventory Items</h5>
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportData('csv')">
                    <i class="fas fa-download me-1"></i>Export CSV
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportData('pdf')">
                    <i class="fas fa-file-pdf me-1"></i>Export PDF
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($inventoryItems)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-boxes fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No inventory items found</h5>
                    <p class="text-muted">Try adjusting your filters or add new inventory items.</p>
                    <?php if (SessionManager::hasPermission('inventory_manage')): ?>
                        <a href="add_item.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>Add First Item
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 data-table">
                        <thead>
                            <tr>
                                <th>Item Code</th>
                                <th>Item Name</th>
                                <th>Section</th>
                                <th>Category</th>
                                <th>Quantity</th>
                                <th>Unit Cost</th>
                                <th>Total Value</th>
                                <th>Status</th>
                                <th>Alert</th>
                                <th>Expiry Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventoryItems as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['item_code']); ?></strong>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                            <?php if ($item['description']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars(substr($item['description'], 0, 50)); ?>...</small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge" style="background-color: <?php echo getSectionColor($item['section']); ?>">
                                            Section <?php echo $item['section']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['category']); ?></td>
                                    <td>
                                        <strong><?php echo number_format($item['quantity'], 3); ?></strong>
                                        <small class="text-muted"><?php echo htmlspecialchars($item['unit']); ?></small>
                                    </td>
                                    <td><?php echo $item['unit_cost'] ? formatCurrency($item['unit_cost']) : '-'; ?></td>
                                    <td>
                                        <strong><?php echo $item['total_value'] ? formatCurrency($item['total_value']) : '-'; ?></strong>
                                    </td>
                                    <td><?php echo getStatusBadge($item['status'], INVENTORY_STATUS); ?></td>
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
                                            $daysToExpiry = (strtotime($item['expiry_date']) - time()) / (24 * 60 * 60);
                                            $expiryClass = $daysToExpiry <= 7 ? 'text-danger' : ($daysToExpiry <= 30 ? 'text-warning' : '');
                                            ?>
                                            <span class="<?php echo $expiryClass; ?>">
                                                <?php echo formatDate($item['expiry_date']); ?>
                                            </span>
                                            <?php if ($daysToExpiry <= 7 && $daysToExpiry > 0): ?>
                                                <br><small class="text-danger"><?php echo floor($daysToExpiry); ?> days left</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="view_item.php?id=<?php echo $item['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if (SessionManager::hasPermission('inventory_manage')): ?>
                                                <a href="edit_item.php?id=<?php echo $item['id']; ?>" 
                                                   class="btn btn-sm btn-outline-secondary" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            <?php endif; ?>
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
function exportData(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    window.location.href = 'export_data.php?' + params.toString();
}

// Auto-refresh every 5 minutes
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 300000);
</script>

<?php include '../../includes/footer.php'; ?>
