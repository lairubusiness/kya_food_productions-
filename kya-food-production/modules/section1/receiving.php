<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

// Check if user is logged in and has access to Section 1
if (!isset($_SESSION['user_id']) || !SessionManager::canAccessSection(1)) {
    header('Location: ../../login.php');
    exit();
}

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

$pageTitle = "Raw Material Receiving - Section 1";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_receiving':
                $result = addReceivingRecord($_POST);
                if ($result['success']) {
                    $successMessage = "Receiving record added successfully!";
                } else {
                    $errorMessage = $result['message'];
                }
                break;
            case 'update_status':
                $result = updateReceivingStatus($_POST['receiving_id'], $_POST['status']);
                if ($result['success']) {
                    $successMessage = "Status updated successfully!";
                } else {
                    $errorMessage = $result['message'];
                }
                break;
        }
    }
}

// Get receiving statistics
$stats = getReceivingStats();

// Get recent receiving records
$recentReceiving = getRecentReceivingRecords(20);

// Get pending approvals
$pendingApprovals = getPendingReceivingApprovals();

function addReceivingRecord($data) {
    global $conn, $userInfo;
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO receiving_records (
                supplier_name, item_name, item_code, category, quantity, 
                unit, unit_cost, total_cost, batch_number, expiry_date, 
                quality_grade, temperature, humidity, received_by, 
                received_date, status, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending', ?)
        ");
        
        $total_cost = $data['quantity'] * $data['unit_cost'];
        
        $stmt->execute([
            $data['supplier_name'], $data['item_name'], $data['item_code'],
            $data['category'], $data['quantity'], $data['unit'],
            $data['unit_cost'], $total_cost, $data['batch_number'],
            $data['expiry_date'], $data['quality_grade'], $data['temperature'],
            $data['humidity'], $userInfo['user_id'], $data['notes']
        ]);
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function getReceivingStats() {
    global $conn;
    
    $stats = [
        'today_received' => 0,
        'pending_approval' => 0,
        'total_value' => 0,
        'quality_passed' => 0,
        'suppliers_active' => 0,
        'avg_quality' => 0
    ];
    
    try {
        // Today's received items
        $stmt = $conn->query("SELECT COUNT(*) FROM receiving_records WHERE DATE(received_date) = CURDATE()");
        $stats['today_received'] = $stmt->fetchColumn();
        
        // Pending approvals
        $stmt = $conn->query("SELECT COUNT(*) FROM receiving_records WHERE status = 'pending'");
        $stats['pending_approval'] = $stmt->fetchColumn();
        
        // Total value this month
        $stmt = $conn->query("SELECT COALESCE(SUM(total_cost), 0) FROM receiving_records WHERE MONTH(received_date) = MONTH(CURDATE())");
        $stats['total_value'] = $stmt->fetchColumn();
        
        // Quality passed
        $stmt = $conn->query("SELECT COUNT(*) FROM receiving_records WHERE quality_grade >= 'B' AND DATE(received_date) = CURDATE()");
        $stats['quality_passed'] = $stmt->fetchColumn();
        
        // Active suppliers
        $stmt = $conn->query("SELECT COUNT(DISTINCT supplier_name) FROM receiving_records WHERE DATE(received_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
        $stats['suppliers_active'] = $stmt->fetchColumn();
        
        // Average quality
        $stmt = $conn->query("SELECT AVG(CASE quality_grade WHEN 'A+' THEN 5 WHEN 'A' THEN 4 WHEN 'B' THEN 3 WHEN 'C' THEN 2 ELSE 1 END) FROM receiving_records WHERE DATE(received_date) = CURDATE()");
        $stats['avg_quality'] = round($stmt->fetchColumn() ?: 0, 1);
        
    } catch (Exception $e) {
        // Handle error silently
    }
    
    return $stats;
}

function getRecentReceivingRecords($limit = 20) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT r.*, u.full_name as received_by_name 
            FROM receiving_records r 
            LEFT JOIN users u ON r.received_by = u.user_id 
            ORDER BY r.received_date DESC 
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function getPendingReceivingApprovals() {
    global $conn;
    
    try {
        $stmt = $conn->query("
            SELECT r.*, u.full_name as received_by_name 
            FROM receiving_records r 
            LEFT JOIN users u ON r.received_by = u.user_id 
            WHERE r.status = 'pending' 
            ORDER BY r.received_date ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function updateReceivingStatus($receiving_id, $status) {
    global $conn, $userInfo;
    
    try {
        $stmt = $conn->prepare("UPDATE receiving_records SET status = ?, approved_date = NOW(), approved_by = ? WHERE id = ?");
        $stmt->execute([$status, $userInfo['user_id'], $receiving_id]);
        
        // If approved, add to inventory
        if ($status === 'approved') {
            $stmt = $conn->prepare("SELECT * FROM receiving_records WHERE id = ?");
            $stmt->execute([$receiving_id]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($record) {
                addToInventory($record);
            }
        }
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function addToInventory($record) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO inventory (
                section, item_code, item_name, category, quantity, unit,
                unit_cost, expiry_date, batch_number, quality_grade, status
            ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ON DUPLICATE KEY UPDATE 
            quantity = quantity + VALUES(quantity),
            unit_cost = VALUES(unit_cost),
            last_updated = NOW()
        ");
        
        $stmt->execute([
            $record['item_code'], $record['item_name'], $record['category'],
            $record['quantity'], $record['unit'], $record['unit_cost'],
            $record['expiry_date'], $record['batch_number'], $record['quality_grade']
        ]);
    } catch (Exception $e) {
        // Handle error
    }
}

// Create table if not exists
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS receiving_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_name VARCHAR(255) NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            item_code VARCHAR(100) NOT NULL,
            category VARCHAR(100) NOT NULL,
            quantity DECIMAL(10,2) NOT NULL,
            unit VARCHAR(50) NOT NULL,
            unit_cost DECIMAL(10,2) NOT NULL,
            total_cost DECIMAL(10,2) NOT NULL,
            batch_number VARCHAR(100),
            expiry_date DATE,
            quality_grade ENUM('A+', 'A', 'B', 'C', 'D') DEFAULT 'B',
            temperature DECIMAL(5,2),
            humidity DECIMAL(5,2),
            received_by INT NOT NULL,
            received_date DATETIME NOT NULL,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            approved_by INT,
            approved_date DATETIME,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (received_by) REFERENCES users(user_id),
            FOREIGN KEY (approved_by) REFERENCES users(user_id)
        )
    ");
} catch (Exception $e) {
    // Handle error
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
                        <h1><i class="fas fa-truck-loading me-3"></i>Raw Material Receiving</h1>
                        <p class="text-muted">Manage incoming raw materials and quality control</p>
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
                            <div class="stats-card success">
                                <div class="stats-icon success">
                                    <i class="fas fa-truck"></i>
                                </div>
                                <div class="stats-content">
                                    <h3><?php echo number_format($stats['today_received']); ?></h3>
                                    <p>Today Received</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                            <div class="stats-card warning">
                                <div class="stats-icon warning">
                                    <i class="fas fa-clock"></i>
                                </div>
                                <div class="stats-content">
                                    <h3><?php echo number_format($stats['pending_approval']); ?></h3>
                                    <p>Pending Approval</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                            <div class="stats-card info">
                                <div class="stats-icon info">
                                    <i class="fas fa-dollar-sign"></i>
                                </div>
                                <div class="stats-content">
                                    <h3>₹<?php echo number_format($stats['total_value'], 0); ?></h3>
                                    <p>Monthly Value</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                            <div class="stats-card success">
                                <div class="stats-icon success">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="stats-content">
                                    <h3><?php echo number_format($stats['quality_passed']); ?></h3>
                                    <p>Quality Passed</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                            <div class="stats-card primary">
                                <div class="stats-icon primary">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="stats-content">
                                    <h3><?php echo number_format($stats['suppliers_active']); ?></h3>
                                    <p>Active Suppliers</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                            <div class="stats-card info">
                                <div class="stats-icon info">
                                    <i class="fas fa-star"></i>
                                </div>
                                <div class="stats-content">
                                    <h3><?php echo $stats['avg_quality']; ?>/5</h3>
                                    <p>Avg Quality</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Add New Receiving Form -->
                        <div class="col-lg-4 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5><i class="fas fa-plus me-2"></i>New Receiving Record</h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="add_receiving">
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Supplier Name</label>
                                            <input type="text" class="form-control" name="supplier_name" required>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Item Name</label>
                                            <input type="text" class="form-control" name="item_name" required>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Item Code</label>
                                            <input type="text" class="form-control" name="item_code" required>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Category</label>
                                            <select class="form-select" name="category" required>
                                                <option value="">Select Category</option>
                                                <option value="Raw Materials">Raw Materials</option>
                                                <option value="Spices">Spices</option>
                                                <option value="Grains">Grains</option>
                                                <option value="Vegetables">Vegetables</option>
                                                <option value="Packaging">Packaging</option>
                                            </select>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Quantity</label>
                                                    <input type="number" step="0.01" class="form-control" name="quantity" required>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Unit</label>
                                                    <select class="form-select" name="unit" required>
                                                        <option value="kg">kg</option>
                                                        <option value="tons">tons</option>
                                                        <option value="pieces">pieces</option>
                                                        <option value="boxes">boxes</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Unit Cost (₹)</label>
                                            <input type="number" step="0.01" class="form-control" name="unit_cost" required>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Batch Number</label>
                                            <input type="text" class="form-control" name="batch_number">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Expiry Date</label>
                                            <input type="date" class="form-control" name="expiry_date">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Quality Grade</label>
                                            <select class="form-select" name="quality_grade">
                                                <option value="A+">A+ (Excellent)</option>
                                                <option value="A">A (Good)</option>
                                                <option value="B" selected>B (Average)</option>
                                                <option value="C">C (Below Average)</option>
                                                <option value="D">D (Poor)</option>
                                            </select>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Temperature (°C)</label>
                                                    <input type="number" step="0.1" class="form-control" name="temperature">
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Humidity (%)</label>
                                                    <input type="number" step="0.1" class="form-control" name="humidity">
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Notes</label>
                                            <textarea class="form-control" name="notes" rows="3"></textarea>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-success w-100">
                                            <i class="fas fa-plus me-2"></i>Add Receiving Record
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Receiving Records -->
                        <div class="col-lg-8 mb-4">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5><i class="fas fa-list me-2"></i>Recent Receiving Records</h5>
                                    <button class="btn btn-sm btn-outline-primary" onclick="location.reload()">
                                        <i class="fas fa-sync-alt me-1"></i>Refresh
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Supplier</th>
                                                    <th>Item</th>
                                                    <th>Quantity</th>
                                                    <th>Quality</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recentReceiving as $record): ?>
                                                <tr>
                                                    <td><?php echo date('M j, Y', strtotime($record['received_date'])); ?></td>
                                                    <td><?php echo htmlspecialchars($record['supplier_name']); ?></td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($record['item_name']); ?></strong><br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($record['item_code']); ?></small>
                                                    </td>
                                                    <td><?php echo number_format($record['quantity'], 2) . ' ' . $record['unit']; ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $record['quality_grade'] >= 'B' ? 'success' : 'warning'; ?>">
                                                            <?php echo $record['quality_grade']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            echo $record['status'] === 'approved' ? 'success' : 
                                                                ($record['status'] === 'rejected' ? 'danger' : 'warning'); 
                                                        ?>">
                                                            <?php echo ucfirst($record['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($record['status'] === 'pending' && $userInfo['role'] === 'admin'): ?>
                                                        <div class="btn-group btn-group-sm">
                                                            <form method="POST" style="display: inline;">
                                                                <input type="hidden" name="action" value="update_status">
                                                                <input type="hidden" name="receiving_id" value="<?php echo $record['id']; ?>">
                                                                <input type="hidden" name="status" value="approved">
                                                                <button type="submit" class="btn btn-success btn-sm" title="Approve">
                                                                    <i class="fas fa-check"></i>
                                                                </button>
                                                            </form>
                                                            <form method="POST" style="display: inline;">
                                                                <input type="hidden" name="action" value="update_status">
                                                                <input type="hidden" name="receiving_id" value="<?php echo $record['id']; ?>">
                                                                <input type="hidden" name="status" value="rejected">
                                                                <button type="submit" class="btn btn-danger btn-sm" title="Reject">
                                                                    <i class="fas fa-times"></i>
                                                                </button>
                                                            </form>
                                                        </div>
                                                        <?php endif; ?>
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

                    <!-- Pending Approvals -->
                    <?php if (!empty($pendingApprovals) && $userInfo['role'] === 'admin'): ?>
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5><i class="fas fa-clock me-2"></i>Pending Approvals</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Supplier</th>
                                                    <th>Item Details</th>
                                                    <th>Quantity</th>
                                                    <th>Cost</th>
                                                    <th>Quality</th>
                                                    <th>Received By</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($pendingApprovals as $record): ?>
                                                <tr>
                                                    <td><?php echo date('M j, Y H:i', strtotime($record['received_date'])); ?></td>
                                                    <td><?php echo htmlspecialchars($record['supplier_name']); ?></td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($record['item_name']); ?></strong><br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($record['item_code']); ?></small><br>
                                                        <small class="text-info"><?php echo htmlspecialchars($record['category']); ?></small>
                                                    </td>
                                                    <td><?php echo number_format($record['quantity'], 2) . ' ' . $record['unit']; ?></td>
                                                    <td>₹<?php echo number_format($record['total_cost'], 2); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $record['quality_grade'] >= 'B' ? 'success' : 'warning'; ?>">
                                                            <?php echo $record['quality_grade']; ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($record['received_by_name']); ?></td>
                                                    <td>
                                                        <div class="btn-group btn-group-sm">
                                                            <form method="POST" style="display: inline;">
                                                                <input type="hidden" name="action" value="update_status">
                                                                <input type="hidden" name="receiving_id" value="<?php echo $record['id']; ?>">
                                                                <input type="hidden" name="status" value="approved">
                                                                <button type="submit" class="btn btn-success btn-sm" title="Approve & Add to Inventory">
                                                                    <i class="fas fa-check me-1"></i>Approve
                                                                </button>
                                                            </form>
                                                            <form method="POST" style="display: inline;">
                                                                <input type="hidden" name="action" value="update_status">
                                                                <input type="hidden" name="receiving_id" value="<?php echo $record['id']; ?>">
                                                                <input type="hidden" name="status" value="rejected">
                                                                <button type="submit" class="btn btn-danger btn-sm" title="Reject">
                                                                    <i class="fas fa-times me-1"></i>Reject
                                                                </button>
                                                            </form>
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
                    <?php endif; ?>
                </div>
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
        
        // Calculate total cost automatically
        document.querySelector('input[name="quantity"]').addEventListener('input', calculateTotal);
        document.querySelector('input[name="unit_cost"]').addEventListener('input', calculateTotal);
        
        function calculateTotal() {
            const quantity = parseFloat(document.querySelector('input[name="quantity"]').value) || 0;
            const unitCost = parseFloat(document.querySelector('input[name="unit_cost"]').value) || 0;
            const total = quantity * unitCost;
            
            // You can display this total if needed
            console.log('Total Cost: ₹' + total.toFixed(2));
        }
    </script>
</body>
</html>