<?php
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

SessionManager::start();
SessionManager::requireLogin();
SessionManager::requireSection(3);

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'start_packaging':
                    $item_id = (int)$_POST['item_id'];
                    $package_type = sanitizeInput($_POST['package_type']);
                    $package_size = sanitizeInput($_POST['package_size']);
                    $input_quantity = (float)$_POST['input_quantity'];
                    $units_per_package = (int)$_POST['units_per_package'];
                    $equipment_used = sanitizeInput($_POST['equipment_used']);
                    $notes = sanitizeInput($_POST['notes']);
                    
                    // Generate batch ID for packaging
                    $batch_id = 'S3-PKG-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    
                    // Insert new packaging process
                    $stmt = $conn->prepare("
                        INSERT INTO processing_logs (
                            section, batch_id, item_id, process_type, process_stage, 
                            input_quantity, equipment_used, operator_id, notes, start_time
                        ) VALUES (3, ?, ?, 'packaging', ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $process_stage = $package_type . ' - ' . $package_size;
                    $stmt->execute([$batch_id, $item_id, $process_stage, $input_quantity, $equipment_used, $userInfo['id'], $notes]);
                    
                    // Log activity
                    logActivity('packaging', 'Started packaging operation: ' . "Batch: $batch_id, Type: $package_type, Size: $package_size", $userInfo['id']);
                    
                    createNotification($userInfo['id'], 3, 'info', 'normal', 
                                     'Packaging Started', "Packaging operation started for batch $batch_id");
                    
                    $_SESSION['flash_message'] = "Packaging operation started successfully! Batch ID: $batch_id";
                    $_SESSION['flash_type'] = 'success';
                    break;
                    
                case 'complete_packaging':
                    $process_id = (int)$_POST['process_id'];
                    $output_quantity = (float)$_POST['output_quantity'];
                    $packages_produced = (int)$_POST['packages_produced'];
                    $waste_quantity = (float)$_POST['waste_quantity'];
                    $quality_grade = sanitizeInput($_POST['quality_grade_output']);
                    $completion_notes = sanitizeInput($_POST['completion_notes']);
                    
                    // Calculate yield percentage
                    $yield_percentage = $output_quantity > 0 ? ($output_quantity / ($output_quantity + $waste_quantity)) * 100 : 0;
                    $duration_minutes = 0;
                    
                    // Get start time to calculate duration
                    $stmt = $conn->prepare("SELECT start_time, batch_id FROM processing_logs WHERE id = ?");
                    $stmt->execute([$process_id]);
                    $process = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($process) {
                        $start_time = new DateTime($process['start_time']);
                        $end_time = new DateTime();
                        $duration_minutes = $end_time->diff($start_time)->i + ($end_time->diff($start_time)->h * 60);
                    }
                    
                    // Update process with completion data
                    $stmt = $conn->prepare("
                        UPDATE processing_logs SET 
                            output_quantity = ?, 
                            waste_quantity = ?, 
                            yield_percentage = ?, 
                            quality_grade_output = ?, 
                            end_time = NOW(), 
                            duration_minutes = ?,
                            notes = CONCAT(COALESCE(notes, ''), '\n\nCompletion: ', ?)
                        WHERE id = ?
                    ");
                    
                    $stmt->execute([$output_quantity, $waste_quantity, $yield_percentage, $quality_grade, 
                                   $duration_minutes, $completion_notes, $process_id]);
                    
                    // Log activity
                    logActivity('packaging', 'Completed packaging operation: ' . "Batch: {$process['batch_id']}, Packages: $packages_produced, Yield: " . number_format($yield_percentage, 1) . '%', $userInfo['id']);
                    
                    createNotification($userInfo['id'], 3, 'success', 'normal', 
                                     'Packaging Completed', "Packaging operation completed for batch {$process['batch_id']}");
                    
                    $_SESSION['flash_message'] = "Packaging operation completed successfully!";
                    $_SESSION['flash_type'] = 'success';
                    break;
                    
                case 'update_packaging':
                    $process_id = (int)$_POST['process_id'];
                    $process_stage = sanitizeInput($_POST['process_stage']);
                    $temperature = !empty($_POST['temperature_start']) ? (float)$_POST['temperature_start'] : null;
                    $humidity = !empty($_POST['humidity_start']) ? (float)$_POST['humidity_start'] : null;
                    $update_notes = sanitizeInput($_POST['update_notes']);
                    
                    // Update process parameters
                    $stmt = $conn->prepare("
                        UPDATE processing_logs SET 
                            process_stage = ?, 
                            temperature_start = ?, 
                            humidity_start = ?,
                            notes = CONCAT(COALESCE(notes, ''), '\n\nUpdate: ', ?)
                        WHERE id = ?
                    ");
                    
                    $stmt->execute([$process_stage, $temperature, $humidity, $update_notes, $process_id]);
                    
                    // Log activity
                    logActivity('packaging', 'Updated packaging parameters: ' . "Process ID: $process_id, Stage: $process_stage", $userInfo['id']);
                    
                    $_SESSION['flash_message'] = "Packaging parameters updated successfully!";
                    $_SESSION['flash_type'] = 'success';
                    break;
            }
        }
    } catch (Exception $e) {
        $_SESSION['flash_message'] = "Error: " . $e->getMessage();
        $_SESSION['flash_type'] = 'error';
    }
    
    header('Location: packaging.php');
    exit;
}

// Get filter parameters
$package_type = $_GET['package_type'] ?? '';
$status = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build WHERE clause for filtering
$whereConditions = ["pl.section = 3", "pl.process_type = 'packaging'"];
$params = [];

if (!empty($package_type)) {
    $whereConditions[] = "pl.process_stage LIKE ?";
    $params[] = "%$package_type%";
}

if (!empty($status)) {
    if ($status === 'active') {
        $whereConditions[] = "pl.end_time IS NULL";
    } elseif ($status === 'completed') {
        $whereConditions[] = "pl.end_time IS NOT NULL";
    }
}

if (!empty($date_from)) {
    $whereConditions[] = "DATE(pl.start_time) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $whereConditions[] = "DATE(pl.start_time) <= ?";
    $params[] = $date_to;
}

$whereClause = implode(' AND ', $whereConditions);

// Get packaging statistics
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_operations,
        COUNT(CASE WHEN pl.end_time IS NULL THEN 1 END) as active_operations,
        COUNT(CASE WHEN pl.end_time IS NOT NULL THEN 1 END) as completed_operations,
        SUM(CASE WHEN pl.end_time IS NOT NULL THEN pl.output_quantity ELSE 0 END) as total_packaged,
        AVG(CASE WHEN pl.end_time IS NOT NULL THEN pl.yield_percentage ELSE NULL END) as avg_yield,
        SUM(CASE WHEN pl.end_time IS NOT NULL THEN pl.waste_quantity ELSE 0 END) as total_waste
    FROM processing_logs pl
    WHERE $whereClause
");
$stmt->execute($params);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get active packaging operations
$stmt = $conn->prepare("
    SELECT pl.*, i.item_name, i.item_code, u.full_name
    FROM processing_logs pl
    LEFT JOIN inventory i ON pl.item_id = i.id
    LEFT JOIN users u ON pl.operator_id = u.id
    WHERE $whereClause AND pl.end_time IS NULL
    ORDER BY pl.start_time DESC
");
$stmt->execute($params);
$activePackaging = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent packaging operations
$stmt = $conn->prepare("
    SELECT pl.*, i.item_name, i.item_code, u.full_name
    FROM processing_logs pl
    LEFT JOIN inventory i ON pl.item_id = i.id
    LEFT JOIN users u ON pl.operator_id = u.id
    WHERE $whereClause
    ORDER BY pl.created_at DESC
    LIMIT 20
");
$stmt->execute($params);
$recentPackaging = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get inventory items for Section 3
$stmt = $conn->prepare("
    SELECT id, item_name, item_code, quantity, unit 
    FROM inventory 
    WHERE section = 3 AND status = 'active' AND quantity > 0
    ORDER BY item_name
");
$stmt->execute();
$inventoryItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Section 3 - Packaging Management';
include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">
                <i class="fas fa-box text-primary me-2"></i>Packaging Management
            </h1>
            <p class="text-muted mb-0">Section 3 - Packaging Operations & Quality Control</p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#startPackagingModal">
                <i class="fas fa-plus me-2"></i>Start Packaging
            </button>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['flash_type'] === 'error' ? 'danger' : $_SESSION['flash_type']; ?> alert-dismissible fade show" role="alert">
            <?php 
            echo htmlspecialchars($_SESSION['flash_message']); 
            unset($_SESSION['flash_message'], $_SESSION['flash_type']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Operations</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['total_operations']); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-boxes fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Active Operations</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['active_operations']); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-play fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Completed</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['completed_operations']); ?></div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-check fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Packaged</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['total_packaged'], 2); ?> kg</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-weight fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card border-left-secondary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">Average Yield</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['avg_yield'] ?? 0, 1); ?>%</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-chart-line fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Total Waste</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($stats['total_waste'], 2); ?> kg</div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-trash fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="package_type" class="form-label">Package Type</label>
                    <select name="package_type" id="package_type" class="form-select">
                        <option value="">All Types</option>
                        <option value="Pouch" <?php echo $package_type == 'Pouch' ? 'selected' : ''; ?>>Pouch</option>
                        <option value="Jar" <?php echo $package_type == 'Jar' ? 'selected' : ''; ?>>Jar</option>
                        <option value="Box" <?php echo $package_type == 'Box' ? 'selected' : ''; ?>>Box</option>
                        <option value="Bag" <?php echo $package_type == 'Bag' ? 'selected' : ''; ?>>Bag</option>
                        <option value="Bottle" <?php echo $package_type == 'Bottle' ? 'selected' : ''; ?>>Bottle</option>
                        <option value="Can" <?php echo $package_type == 'Can' ? 'selected' : ''; ?>>Can</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="completed" <?php echo $status == 'completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="date_from" class="form-label">From Date</label>
                    <input type="date" name="date_from" id="date_from" class="form-control" 
                           value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                
                <div class="col-md-2">
                    <label for="date_to" class="form-label">To Date</label>
                    <input type="date" name="date_to" id="date_to" class="form-control" 
                           value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i>Filter
                        </button>
                        <a href="packaging.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-2"></i>Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Main Content Row -->
    <div class="row">
        <!-- Active Packaging Operations -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-play text-warning me-2"></i>Active Packaging Operations
                    </h5>
                    <span class="badge bg-warning text-dark"><?php echo count($activePackaging); ?> running</span>
                </div>
                <div class="card-body">
                    <?php if (empty($activePackaging)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-pause-circle fa-3x text-muted mb-3"></i>
                            <h6 class="text-muted">No Active Packaging Operations</h6>
                            <p class="text-muted">All packaging lines are currently idle.</p>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#startPackagingModal">
                                <i class="fas fa-play me-2"></i>Start Packaging
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Batch ID</th>
                                        <th>Package Type</th>
                                        <th>Item</th>
                                        <th>Started</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activePackaging as $operation): ?>
                                        <tr class="packaging-active">
                                            <td><strong><?php echo htmlspecialchars($operation['batch_id']); ?></strong></td>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?php echo htmlspecialchars($operation['process_stage']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($operation['item_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo timeAgo($operation['start_time']); ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-primary" 
                                                            onclick="updatePackaging(<?php echo $operation['id']; ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-success" 
                                                            onclick="completePackaging(<?php echo $operation['id']; ?>)">
                                                        <i class="fas fa-check"></i>
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
        
        <!-- Recent Packaging Operations -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-history text-info me-2"></i>Recent Packaging Operations
                    </h5>
                    <a href="../processing/logs.php?section=3&process_type=packaging" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body">
                    <?php if (empty($recentPackaging)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                            <h6 class="text-muted">No Recent Operations</h6>
                            <p class="text-muted">No packaging activities recorded recently.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Batch ID</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Yield</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentPackaging as $operation): ?>
                                        <tr class="<?php echo $operation['end_time'] ? 'packaging-completed' : 'packaging-active'; ?>">
                                            <td><strong><?php echo htmlspecialchars($operation['batch_id']); ?></strong></td>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?php echo htmlspecialchars($operation['process_stage']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($operation['end_time']): ?>
                                                    <span class="badge bg-success">Completed</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">Active</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($operation['yield_percentage']): ?>
                                                    <span class="badge bg-<?php echo $operation['yield_percentage'] >= 95 ? 'success' : ($operation['yield_percentage'] >= 85 ? 'warning' : 'danger'); ?>">
                                                        <?php echo number_format($operation['yield_percentage'], 1); ?>%
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo formatDate($operation['created_at']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Start Packaging Modal -->
<div class="modal fade" id="startPackagingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Start New Packaging Operation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="start_packaging">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="item_id" class="form-label">Inventory Item</label>
                            <select name="item_id" id="item_id" class="form-select" required>
                                <option value="">Select Item</option>
                                <?php foreach ($inventoryItems as $item): ?>
                                    <option value="<?php echo $item['id']; ?>">
                                        <?php echo htmlspecialchars($item['item_name']); ?> 
                                        (<?php echo htmlspecialchars($item['item_code']); ?>) - 
                                        <?php echo number_format($item['quantity'], 2); ?> <?php echo htmlspecialchars($item['unit']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="package_type" class="form-label">Package Type</label>
                            <select name="package_type" id="package_type" class="form-select" required>
                                <option value="">Select Package Type</option>
                                <option value="Pouch">Pouch</option>
                                <option value="Jar">Jar</option>
                                <option value="Box">Box</option>
                                <option value="Bag">Bag</option>
                                <option value="Bottle">Bottle</option>
                                <option value="Can">Can</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="package_size" class="form-label">Package Size</label>
                            <select name="package_size" id="package_size" class="form-select" required>
                                <option value="">Select Size</option>
                                <option value="Small (50g)">Small (50g)</option>
                                <option value="Medium (100g)">Medium (100g)</option>
                                <option value="Large (250g)">Large (250g)</option>
                                <option value="Extra Large (500g)">Extra Large (500g)</option>
                                <option value="Bulk (1kg)">Bulk (1kg)</option>
                                <option value="Industrial (5kg)">Industrial (5kg)</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="input_quantity" class="form-label">Input Quantity (kg)</label>
                            <input type="number" name="input_quantity" id="input_quantity" class="form-control" 
                                   step="0.001" min="0.001" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="units_per_package" class="form-label">Units per Package</label>
                            <input type="number" name="units_per_package" id="units_per_package" class="form-control" 
                                   min="1" value="1" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="equipment_used" class="form-label">Equipment/Line Used</label>
                            <select name="equipment_used" id="equipment_used" class="form-select" required>
                                <option value="">Select Equipment</option>
                                <option value="Packaging Line A">Packaging Line A</option>
                                <option value="Packaging Line B">Packaging Line B</option>
                                <option value="Manual Packaging Station">Manual Packaging Station</option>
                                <option value="Automated Packaging System">Automated Packaging System</option>
                                <option value="Quality Control Station">Quality Control Station</option>
                            </select>
                        </div>
                        
                        <div class="col-md-12">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea name="notes" id="notes" class="form-control" rows="3" 
                                      placeholder="Additional notes about this packaging operation..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-play me-2"></i>Start Packaging
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Complete Packaging Modal -->
<div class="modal fade" id="completePackagingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Complete Packaging Operation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="complete_packaging">
                <input type="hidden" name="process_id" id="complete_process_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="output_quantity" class="form-label">Output Quantity (kg)</label>
                            <input type="number" name="output_quantity" id="output_quantity" class="form-control" 
                                   step="0.001" min="0" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="packages_produced" class="form-label">Packages Produced</label>
                            <input type="number" name="packages_produced" id="packages_produced" class="form-control" 
                                   min="0" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="waste_quantity" class="form-label">Waste Quantity (kg)</label>
                            <input type="number" name="waste_quantity" id="waste_quantity" class="form-control" 
                                   step="0.001" min="0" value="0">
                        </div>
                        
                        <div class="col-md-6">
                            <label for="quality_grade_output" class="form-label">Output Quality Grade</label>
                            <select name="quality_grade_output" id="quality_grade_output" class="form-select" required>
                                <option value="">Select Grade</option>
                                <option value="A">Grade A (Premium)</option>
                                <option value="B">Grade B (Good)</option>
                                <option value="C">Grade C (Standard)</option>
                                <option value="D">Grade D (Below Standard)</option>
                            </select>
                        </div>
                        
                        <div class="col-md-12">
                            <label for="completion_notes" class="form-label">Completion Notes</label>
                            <textarea name="completion_notes" id="completion_notes" class="form-control" rows="3" 
                                      placeholder="Notes about the completion of this packaging operation..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check me-2"></i>Complete Packaging
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Packaging Modal -->
<div class="modal fade" id="updatePackagingModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Packaging Parameters</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_packaging">
                <input type="hidden" name="process_id" id="update_process_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="temperature_start" class="form-label">Temperature (°C)</label>
                            <input type="number" name="temperature_start" id="temperature_start" class="form-control" 
                                   step="0.1">
                        </div>
                        
                        <div class="col-md-6">
                            <label for="humidity_start" class="form-label">Humidity (%)</label>
                            <input type="number" name="humidity_start" id="humidity_start" class="form-control" 
                                   step="0.1" min="0" max="100">
                        </div>
                        
                        <div class="col-md-12">
                            <label for="process_stage" class="form-label">Process Stage</label>
                            <input type="text" name="process_stage" id="update_process_stage" class="form-control" 
                                   placeholder="e.g., Final Sealing, Quality Check">
                        </div>
                        
                        <div class="col-md-12">
                            <label for="update_notes" class="form-label">Update Notes</label>
                            <textarea name="update_notes" id="update_notes" class="form-control" rows="3" 
                                      placeholder="Notes about this update..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Update Packaging
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* Packaging-specific styling */
.packaging-active {
    background-color: #fff3cd !important;
    border-left: 4px solid #ffc107 !important;
}

.packaging-completed {
    background-color: #d1edff !important;
    border-left: 4px solid #0d6efd !important;
}

.card {
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15) !important;
    border: 1px solid #e3e6f0 !important;
}

.card-header {
    background-color: #f8f9fc !important;
    border-bottom: 1px solid #e3e6f0 !important;
}

.text-gray-800 {
    color: #5a5c69 !important;
}

.text-gray-300 {
    color: #dddfeb !important;
}

.border-left-primary {
    border-left: 0.25rem solid #4e73df !important;
}

.border-left-success {
    border-left: 0.25rem solid #1cc88a !important;
}

.border-left-info {
    border-left: 0.25rem solid #36b9cc !important;
}

.border-left-warning {
    border-left: 0.25rem solid #f6c23e !important;
}

.border-left-danger {
    border-left: 0.25rem solid #e74a3b !important;
}

.border-left-secondary {
    border-left: 0.25rem solid #858796 !important;
}

.font-weight-bold {
    font-weight: 700 !important;
}

.text-uppercase {
    text-transform: uppercase !important;
}

.text-xs {
    font-size: 0.7rem !important;
}
</style>

<script>
function completePackaging(processId) {
    document.getElementById('complete_process_id').value = processId;
    new bootstrap.Modal(document.getElementById('completePackagingModal')).show();
}

function updatePackaging(processId) {
    document.getElementById('update_process_id').value = processId;
    new bootstrap.Modal(document.getElementById('updatePackagingModal')).show();
}

// Auto-refresh active packaging operations every 30 seconds
setInterval(function() {
    if (<?php echo count($activePackaging); ?> > 0) {
        location.reload();
    }
}, 30000);

// Calculate estimated packages when input quantity changes
document.addEventListener('DOMContentLoaded', function() {
    const inputQuantity = document.getElementById('input_quantity');
    const packageSize = document.getElementById('package_size');
    const unitsPerPackage = document.getElementById('units_per_package');
    
    function calculateEstimatedPackages() {
        const quantity = parseFloat(inputQuantity.value) || 0;
        const size = packageSize.value;
        const units = parseInt(unitsPerPackage.value) || 1;
        
        if (quantity > 0 && size) {
            // Extract weight from size string (e.g., "Small (50g)" -> 0.05 kg)
            const match = size.match(/\((\d+)([gk]g?)\)/);
            if (match) {
                let weightPerPackage = parseFloat(match[1]);
                const unit = match[2];
                
                if (unit === 'g') {
                    weightPerPackage = weightPerPackage / 1000; // Convert to kg
                } else if (unit === 'kg') {
                    // Already in kg
                }
                
                const estimatedPackages = Math.floor(quantity / (weightPerPackage * units));
                
                // Show estimated packages in a small info text
                let infoElement = document.getElementById('estimated-packages');
                if (!infoElement) {
                    infoElement = document.createElement('small');
                    infoElement.id = 'estimated-packages';
                    infoElement.className = 'text-muted';
                    unitsPerPackage.parentNode.appendChild(infoElement);
                }
                infoElement.textContent = `Estimated packages: ${estimatedPackages}`;
            }
        }
    }
    
    if (inputQuantity && packageSize && unitsPerPackage) {
        inputQuantity.addEventListener('input', calculateEstimatedPackages);
        packageSize.addEventListener('change', calculateEstimatedPackages);
        unitsPerPackage.addEventListener('input', calculateEstimatedPackages);
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
