<?php
/**
 * KYA Food Production - Inventory Transfers
 * Stock transfer management between sections
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

$pageTitle = "Stock Transfers - Inventory Management";

// Create transfers table if it doesn't exist
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS inventory_transfers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transfer_number VARCHAR(20) UNIQUE NOT NULL,
            item_id INT NOT NULL,
            from_section INT NOT NULL,
            to_section INT NOT NULL,
            quantity DECIMAL(10,2) NOT NULL,
            unit VARCHAR(20) NOT NULL,
            reason TEXT,
            status ENUM('pending', 'approved', 'rejected', 'completed') DEFAULT 'pending',
            requested_by INT NOT NULL,
            approved_by INT NULL,
            transferred_by INT NULL,
            request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            approval_date TIMESTAMP NULL,
            transfer_date TIMESTAMP NULL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (item_id) REFERENCES inventory(id),
            FOREIGN KEY (requested_by) REFERENCES users(id),
            FOREIGN KEY (approved_by) REFERENCES users(id),
            FOREIGN KEY (transferred_by) REFERENCES users(id),
            INDEX idx_transfer_status (status),
            INDEX idx_transfer_sections (from_section, to_section),
            INDEX idx_transfer_date (request_date)
        )
    ");
} catch (Exception $e) {
    error_log("Error creating transfers table: " . $e->getMessage());
}

// Handle form submissions
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_transfer':
                $result = createTransfer($_POST);
                if ($result['success']) {
                    $successMessage = "Transfer request created successfully! Transfer Number: " . $result['transfer_number'];
                } else {
                    $errorMessage = $result['message'];
                }
                break;
            case 'approve_transfer':
                $result = approveTransfer($_POST['transfer_id']);
                if ($result['success']) {
                    $successMessage = "Transfer approved successfully!";
                } else {
                    $errorMessage = $result['message'];
                }
                break;
            case 'reject_transfer':
                $result = rejectTransfer($_POST['transfer_id'], $_POST['rejection_reason'] ?? '');
                if ($result['success']) {
                    $successMessage = "Transfer rejected successfully!";
                } else {
                    $errorMessage = $result['message'];
                }
                break;
            case 'complete_transfer':
                $result = completeTransfer($_POST['transfer_id']);
                if ($result['success']) {
                    $successMessage = "Transfer completed successfully!";
                } else {
                    $errorMessage = $result['message'];
                }
                break;
        }
    }
}

// Get filter parameters
$status = $_GET['status'] ?? '';
$section = $_GET['section'] ?? '';
$search = $_GET['search'] ?? '';

// Get transfers data
$transfers = getTransfers($status, $section, $search);
$stats = getTransferStats();

function createTransfer($data) {
    global $conn, $userInfo;
    
    try {
        // Validate input
        $itemId = (int)$data['item_id'];
        $fromSection = (int)$data['from_section'];
        $toSection = (int)$data['to_section'];
        $quantity = (float)$data['quantity'];
        $reason = sanitizeInput($data['reason']);
        
        if ($fromSection === $toSection) {
            return ['success' => false, 'message' => 'Cannot transfer to the same section'];
        }
        
        // Check if item exists and has sufficient quantity
        $stmt = $conn->prepare("SELECT * FROM inventory WHERE id = ? AND section = ?");
        $stmt->execute([$itemId, $fromSection]);
        $item = $stmt->fetch();
        
        if (!$item) {
            return ['success' => false, 'message' => 'Item not found in source section'];
        }
        
        if ($item['quantity'] < $quantity) {
            return ['success' => false, 'message' => 'Insufficient quantity available'];
        }
        
        // Generate transfer number
        $transferNumber = 'TRF' . date('Ymd') . sprintf('%04d', rand(1, 9999));
        
        // Create transfer record
        $stmt = $conn->prepare("
            INSERT INTO inventory_transfers 
            (transfer_number, item_id, from_section, to_section, quantity, unit, reason, requested_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $transferNumber,
            $itemId,
            $fromSection,
            $toSection,
            $quantity,
            $item['unit'],
            $reason,
            $userInfo['id']
        ]);
        
        // Log activity
        logActivity(
            'transfer_requested',
            "Transfer request created: {$item['item_name']} ({$quantity} {$item['unit']}) from Section {$fromSection} to Section {$toSection}",
            $userInfo['id']
        );
        
        return ['success' => true, 'transfer_number' => $transferNumber];
        
    } catch (Exception $e) {
        error_log("Transfer creation error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to create transfer request'];
    }
}

function approveTransfer($transferId) {
    global $conn, $userInfo;
    
    try {
        // Get transfer details
        $stmt = $conn->prepare("
            SELECT t.*, i.item_name, i.item_code 
            FROM inventory_transfers t
            JOIN inventory i ON t.item_id = i.id
            WHERE t.id = ? AND t.status = 'pending'
        ");
        $stmt->execute([$transferId]);
        $transfer = $stmt->fetch();
        
        if (!$transfer) {
            return ['success' => false, 'message' => 'Transfer not found or already processed'];
        }
        
        // Update transfer status
        $stmt = $conn->prepare("
            UPDATE inventory_transfers 
            SET status = 'approved', approved_by = ?, approval_date = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$userInfo['id'], $transferId]);
        
        // Log activity
        logActivity(
            'transfer_approved',
            "Transfer #{$transfer['transfer_number']} approved",
            $userInfo['id']
        );
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Transfer approval error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to approve transfer'];
    }
}

function rejectTransfer($transferId, $reason) {
    global $conn, $userInfo;
    
    try {
        $stmt = $conn->prepare("
            UPDATE inventory_transfers 
            SET status = 'rejected', approved_by = ?, approval_date = NOW(), notes = ?
            WHERE id = ? AND status = 'pending'
        ");
        $stmt->execute([$userInfo['id'], $reason, $transferId]);
        
        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'message' => 'Transfer not found or already processed'];
        }
        
        logActivity('transfer_rejected', "Transfer #{$transferId} rejected: {$reason}", $userInfo['id']);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Transfer rejection error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to reject transfer'];
    }
}

function completeTransfer($transferId) {
    global $conn, $userInfo;
    
    try {
        $conn->beginTransaction();
        
        // Get transfer details
        $stmt = $conn->prepare("
            SELECT t.*, i.item_name, i.item_code 
            FROM inventory_transfers t
            JOIN inventory i ON t.item_id = i.id
            WHERE t.id = ? AND t.status = 'approved'
        ");
        $stmt->execute([$transferId]);
        $transfer = $stmt->fetch();
        
        if (!$transfer) {
            $conn->rollBack();
            return ['success' => false, 'message' => 'Transfer not found or not approved'];
        }
        
        // Check if source still has sufficient quantity
        $stmt = $conn->prepare("SELECT quantity FROM inventory WHERE id = ?");
        $stmt->execute([$transfer['item_id']]);
        $currentQuantity = $stmt->fetchColumn();
        
        if ($currentQuantity < $transfer['quantity']) {
            $conn->rollBack();
            return ['success' => false, 'message' => 'Insufficient quantity in source section'];
        }
        
        // Reduce quantity from source
        $stmt = $conn->prepare("
            UPDATE inventory 
            SET quantity = quantity - ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$transfer['quantity'], $transfer['item_id']]);
        
        // Check if item exists in destination section
        $stmt = $conn->prepare("
            SELECT id FROM inventory 
            WHERE item_code = ? AND section = ?
        ");
        $stmt->execute([$transfer['item_code'], $transfer['to_section']]);
        $destinationItem = $stmt->fetch();
        
        if ($destinationItem) {
            // Update existing item in destination
            $stmt = $conn->prepare("
                UPDATE inventory 
                SET quantity = quantity + ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$transfer['quantity'], $destinationItem['id']]);
        } else {
            // Create new item in destination section
            $stmt = $conn->prepare("
                INSERT INTO inventory 
                (section, item_code, item_name, category, subcategory, description, 
                 quantity, unit, unit_cost, min_threshold, max_threshold, reorder_level,
                 expiry_date, manufacture_date, batch_number, supplier_id, 
                 storage_conditions, quality_grade, status, created_by)
                SELECT ?, item_code, item_name, category, subcategory, description,
                       ?, unit, unit_cost, min_threshold, max_threshold, reorder_level,
                       expiry_date, manufacture_date, batch_number, supplier_id,
                       storage_conditions, quality_grade, status, ?
                FROM inventory WHERE id = ?
            ");
            $stmt->execute([$transfer['to_section'], $transfer['quantity'], $userInfo['id'], $transfer['item_id']]);
        }
        
        // Update transfer status
        $stmt = $conn->prepare("
            UPDATE inventory_transfers 
            SET status = 'completed', transferred_by = ?, transfer_date = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$userInfo['id'], $transferId]);
        
        $conn->commit();
        
        // Log activity
        logActivity(
            'transfer_completed',
            "Transfer #{$transfer['transfer_number']} completed: {$transfer['item_name']} ({$transfer['quantity']} {$transfer['unit']}) from Section {$transfer['from_section']} to Section {$transfer['to_section']}",
            $userInfo['id']
        );
        
        // Check inventory alerts
        checkInventoryAlerts($transfer['item_id']);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Transfer completion error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to complete transfer'];
    }
}

function getTransfers($status = '', $section = '', $search = '') {
    global $conn, $userInfo;
    
    try {
        $whereConditions = [];
        $params = [];
        
        // Section filter based on user permissions
        if ($userInfo['role'] !== 'admin') {
            $userSections = $userInfo['sections'];
            if (!empty($userSections)) {
                $placeholders = str_repeat('?,', count($userSections) - 1) . '?';
                $whereConditions[] = "(t.from_section IN ($placeholders) OR t.to_section IN ($placeholders))";
                $params = array_merge($params, $userSections, $userSections);
            }
        } elseif ($section) {
            $whereConditions[] = "(t.from_section = ? OR t.to_section = ?)";
            $params[] = $section;
            $params[] = $section;
        }
        
        if ($status) {
            $whereConditions[] = "t.status = ?";
            $params[] = $status;
        }
        
        if ($search) {
            $whereConditions[] = "(i.item_name LIKE ? OR i.item_code LIKE ? OR t.transfer_number LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        $stmt = $conn->prepare("
            SELECT t.*, 
                   i.item_name, i.item_code, i.unit,
                   u1.full_name as requested_by_name,
                   u2.full_name as approved_by_name,
                   u3.full_name as transferred_by_name
            FROM inventory_transfers t
            JOIN inventory i ON t.item_id = i.id
            LEFT JOIN users u1 ON t.requested_by = u1.id
            LEFT JOIN users u2 ON t.approved_by = u2.id
            LEFT JOIN users u3 ON t.transferred_by = u3.id
            $whereClause
            ORDER BY t.request_date DESC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Get transfers error: " . $e->getMessage());
        return [];
    }
}

function getTransferStats() {
    global $conn, $userInfo;
    
    try {
        $sectionFilter = '';
        $params = [];
        
        if ($userInfo['role'] !== 'admin') {
            $userSections = $userInfo['sections'];
            if (!empty($userSections)) {
                $placeholders = str_repeat('?,', count($userSections) - 1) . '?';
                $sectionFilter = "WHERE (from_section IN ($placeholders) OR to_section IN ($placeholders))";
                $params = array_merge($params, $userSections, $userSections);
            }
        }
        
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_transfers,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_transfers,
                COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_transfers,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_transfers,
                COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_transfers,
                COUNT(CASE WHEN request_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as recent_transfers
            FROM inventory_transfers
            $sectionFilter
        ");
        $stmt->execute($params);
        return $stmt->fetch();
        
    } catch (Exception $e) {
        error_log("Get transfer stats error: " . $e->getMessage());
        return [
            'total_transfers' => 0,
            'pending_transfers' => 0,
            'approved_transfers' => 0,
            'completed_transfers' => 0,
            'rejected_transfers' => 0,
            'recent_transfers' => 0
        ];
    }
}

function getAvailableItems($section) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT id, item_code, item_name, quantity, unit
            FROM inventory 
            WHERE section = ? AND status = 'active' AND quantity > 0
            ORDER BY item_name
        ");
        $stmt->execute([$section]);
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        return [];
    }
}

?>

<?php include '../../includes/header.php'; ?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Stock Transfers</h1>
            <p class="text-muted mb-0">Manage inventory transfers between sections</p>
        </div>
        <div>
            <?php if (SessionManager::hasPermission('inventory_manage')): ?>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newTransferModal">
                    <i class="fas fa-exchange-alt me-2"></i>New Transfer
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if ($successMessage): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo $successMessage; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($errorMessage): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $errorMessage; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card">
                <div class="stats-icon primary">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['total_transfers']); ?></div>
                <div class="stats-label">Total Transfers</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card warning">
                <div class="stats-icon warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['pending_transfers']); ?></div>
                <div class="stats-label">Pending</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card info">
                <div class="stats-icon info">
                    <i class="fas fa-check"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['approved_transfers']); ?></div>
                <div class="stats-label">Approved</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card success">
                <div class="stats-icon success">
                    <i class="fas fa-check-double"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['completed_transfers']); ?></div>
                <div class="stats-label">Completed</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card danger">
                <div class="stats-icon danger">
                    <i class="fas fa-times"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['rejected_transfers']); ?></div>
                <div class="stats-label">Rejected</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card secondary">
                <div class="stats-icon secondary">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['recent_transfers']); ?></div>
                <div class="stats-label">Recent (24h)</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    </select>
                </div>
                <?php if ($userInfo['role'] === 'admin'): ?>
                <div class="col-md-3">
                    <label for="section" class="form-label">Section</label>
                    <select name="section" id="section" class="form-select">
                        <option value="">All Sections</option>
                        <option value="1" <?php echo $section === '1' ? 'selected' : ''; ?>>Section 1 - Raw Materials</option>
                        <option value="2" <?php echo $section === '2' ? 'selected' : ''; ?>>Section 2 - Processing</option>
                        <option value="3" <?php echo $section === '3' ? 'selected' : ''; ?>>Section 3 - Packaging</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-4">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" name="search" id="search" class="form-control" 
                           placeholder="Search by item name, code, or transfer number..." 
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

    <!-- Transfers Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">Transfer Records</h5>
        </div>
        <div class="card-body">
            <?php if (!empty($transfers)): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Transfer #</th>
                                <th>Item</th>
                                <th>From → To</th>
                                <th>Quantity</th>
                                <th>Status</th>
                                <th>Requested By</th>
                                <th>Request Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transfers as $transfer): ?>
                                <tr class="<?php echo $transfer['status'] === 'rejected' ? 'table-danger' : ($transfer['status'] === 'completed' ? 'table-success' : ''); ?>">
                                    <td>
                                        <strong><?php echo $transfer['transfer_number']; ?></strong>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo $transfer['item_name']; ?></strong>
                                            <br><small class="text-muted"><?php echo $transfer['item_code']; ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary">Section <?php echo $transfer['from_section']; ?></span>
                                        <i class="fas fa-arrow-right mx-2"></i>
                                        <span class="badge bg-primary">Section <?php echo $transfer['to_section']; ?></span>
                                    </td>
                                    <td>
                                        <strong><?php echo number_format($transfer['quantity'], 2); ?></strong>
                                        <small class="text-muted"><?php echo $transfer['unit']; ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        $statusColors = [
                                            'pending' => 'warning',
                                            'approved' => 'info',
                                            'completed' => 'success',
                                            'rejected' => 'danger'
                                        ];
                                        $statusColor = $statusColors[$transfer['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $statusColor; ?>">
                                            <?php echo ucfirst($transfer['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $transfer['requested_by_name']; ?>
                                        <br><small class="text-muted"><?php echo formatDateTime($transfer['request_date']); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($transfer['approval_date']): ?>
                                            <small class="text-muted">
                                                <?php echo $transfer['status'] === 'approved' ? 'Approved' : 'Processed'; ?>: 
                                                <?php echo formatDateTime($transfer['approval_date']); ?>
                                            </small>
                                        <?php else: ?>
                                            <small class="text-muted">Awaiting approval</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="viewTransfer(<?php echo $transfer['id']; ?>)" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            
                                            <?php if ($transfer['status'] === 'pending' && SessionManager::hasPermission('inventory_manage')): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success" 
                                                        onclick="approveTransfer(<?php echo $transfer['id']; ?>)" title="Approve">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                                        onclick="rejectTransfer(<?php echo $transfer['id']; ?>)" title="Reject">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($transfer['status'] === 'approved' && SessionManager::hasPermission('inventory_manage')): ?>
                                                <button type="button" class="btn btn-sm btn-outline-info" 
                                                        onclick="completeTransfer(<?php echo $transfer['id']; ?>)" title="Complete Transfer">
                                                    <i class="fas fa-check-double"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-exchange-alt fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No transfers found</h5>
                    <p class="text-muted">No transfer records match your current filters.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- New Transfer Modal -->
<div class="modal fade" id="newTransferModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Transfer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="transferForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_transfer">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="from_section" class="form-label">From Section <span class="text-danger">*</span></label>
                                <select name="from_section" id="from_section" class="form-select" required onchange="loadItems()">
                                    <option value="">Select source section</option>
                                    <?php if ($userInfo['role'] === 'admin' || in_array(1, $userInfo['sections'])): ?>
                                        <option value="1">Section 1 - Raw Materials</option>
                                    <?php endif; ?>
                                    <?php if ($userInfo['role'] === 'admin' || in_array(2, $userInfo['sections'])): ?>
                                        <option value="2">Section 2 - Processing</option>
                                    <?php endif; ?>
                                    <?php if ($userInfo['role'] === 'admin' || in_array(3, $userInfo['sections'])): ?>
                                        <option value="3">Section 3 - Packaging</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="to_section" class="form-label">To Section <span class="text-danger">*</span></label>
                                <select name="to_section" id="to_section" class="form-select" required>
                                    <option value="">Select destination section</option>
                                    <?php if ($userInfo['role'] === 'admin' || in_array(1, $userInfo['sections'])): ?>
                                        <option value="1">Section 1 - Raw Materials</option>
                                    <?php endif; ?>
                                    <?php if ($userInfo['role'] === 'admin' || in_array(2, $userInfo['sections'])): ?>
                                        <option value="2">Section 2 - Processing</option>
                                    <?php endif; ?>
                                    <?php if ($userInfo['role'] === 'admin' || in_array(3, $userInfo['sections'])): ?>
                                        <option value="3">Section 3 - Packaging</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="item_id" class="form-label">Item <span class="text-danger">*</span></label>
                        <select name="item_id" id="item_id" class="form-select" required onchange="updateItemInfo()">
                            <option value="">Select an item to transfer</option>
                        </select>
                        <div id="item_info" class="mt-2" style="display: none;">
                            <small class="text-muted">
                                Available: <span id="available_quantity"></span> <span id="item_unit"></span>
                            </small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="quantity" class="form-label">Quantity <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="quantity" id="quantity" class="form-control" required min="0.01">
                    </div>
                    
                    <div class="mb-3">
                        <label for="reason" class="form-label">Reason for Transfer <span class="text-danger">*</span></label>
                        <textarea name="reason" id="reason" class="form-control" rows="3" required 
                                  placeholder="Explain why this transfer is needed..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Transfer Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Transfer Details Modal -->
<div class="modal fade" id="transferDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Transfer Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="transferDetailsContent">
                <!-- Content loaded via JavaScript -->
            </div>
        </div>
    </div>
</div>

<!-- Reject Transfer Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reject Transfer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="reject_transfer">
                    <input type="hidden" name="transfer_id" id="reject_transfer_id">
                    
                    <div class="mb-3">
                        <label for="rejection_reason" class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                        <textarea name="rejection_reason" id="rejection_reason" class="form-control" rows="3" required 
                                  placeholder="Explain why this transfer is being rejected..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Transfer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let availableItems = {};

// Load items when source section changes
function loadItems() {
    const fromSection = document.getElementById('from_section').value;
    const itemSelect = document.getElementById('item_id');
    const itemInfo = document.getElementById('item_info');
    
    // Clear current options
    itemSelect.innerHTML = '<option value="">Loading items...</option>';
    itemInfo.style.display = 'none';
    
    if (!fromSection) {
        itemSelect.innerHTML = '<option value="">Select source section first</option>';
        return;
    }
    
    // Fetch items via AJAX
    fetch('get_section_items.php?section=' + fromSection)
        .then(response => response.json())
        .then(data => {
            itemSelect.innerHTML = '<option value="">Select an item to transfer</option>';
            availableItems = {};
            
            data.forEach(item => {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = `${item.item_name} (${item.item_code})`;
                itemSelect.appendChild(option);
                
                availableItems[item.id] = {
                    quantity: item.quantity,
                    unit: item.unit
                };
            });
        })
        .catch(error => {
            console.error('Error loading items:', error);
            itemSelect.innerHTML = '<option value="">Error loading items</option>';
        });
}

// Update item info when item is selected
function updateItemInfo() {
    const itemId = document.getElementById('item_id').value;
    const itemInfo = document.getElementById('item_info');
    const quantityInput = document.getElementById('quantity');
    
    if (itemId && availableItems[itemId]) {
        const item = availableItems[itemId];
        document.getElementById('available_quantity').textContent = item.quantity;
        document.getElementById('item_unit').textContent = item.unit;
        quantityInput.max = item.quantity;
        itemInfo.style.display = 'block';
    } else {
        itemInfo.style.display = 'none';
        quantityInput.max = '';
    }
}

// Transfer management functions
function viewTransfer(transferId) {
    fetch('get_transfer_details.php?id=' + transferId)
        .then(response => response.json())
        .then(data => {
            document.getElementById('transferDetailsContent').innerHTML = data.html;
            new bootstrap.Modal(document.getElementById('transferDetailsModal')).show();
        })
        .catch(error => {
            console.error('Error loading transfer details:', error);
            alert('Error loading transfer details');
        });
}

function approveTransfer(transferId) {
    if (confirm('Are you sure you want to approve this transfer?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="approve_transfer">
            <input type="hidden" name="transfer_id" value="${transferId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function rejectTransfer(transferId) {
    document.getElementById('reject_transfer_id').value = transferId;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

function completeTransfer(transferId) {
    if (confirm('Are you sure you want to complete this transfer? This will move the inventory between sections.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="complete_transfer">
            <input type="hidden" name="transfer_id" value="${transferId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Form validation
document.getElementById('transferForm').addEventListener('submit', function(e) {
    const fromSection = document.getElementById('from_section').value;
    const toSection = document.getElementById('to_section').value;
    const quantity = parseFloat(document.getElementById('quantity').value);
    const itemId = document.getElementById('item_id').value;
    
    if (fromSection === toSection) {
        e.preventDefault();
        alert('Source and destination sections cannot be the same');
        return;
    }
    
    if (itemId && availableItems[itemId]) {
        const maxQuantity = parseFloat(availableItems[itemId].quantity);
        if (quantity > maxQuantity) {
            e.preventDefault();
            alert(`Quantity cannot exceed available stock (${maxQuantity})`);
            return;
        }
    }
});

// Auto-refresh every 3 minutes
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 180000);
</script>

<?php include '../../includes/footer.php'; ?>