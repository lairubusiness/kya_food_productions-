<?php
/**
 * KYA Food Production - Section 2 Batch Tracking
 * Comprehensive batch management and tracking system for Section 2
 */

require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

SessionManager::start();
SessionManager::requireLogin();
SessionManager::requireSection(2);

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'update_batch_status':
                    $batch_id = sanitizeInput($_POST['batch_id']);
                    $status = sanitizeInput($_POST['status']);
                    $notes = sanitizeInput($_POST['notes']);
                    
                    // Update all processes in this batch
                    $stmt = $conn->prepare("
                        UPDATE processing_logs SET 
                            notes = CONCAT(COALESCE(notes, ''), '\n\nStatus Update: ', ?, ' - ', ?)
                        WHERE batch_id = ? AND section = 2
                    ");
                    $stmt->execute([$status, $notes, $batch_id]);
                    
                    // Log activity
                    logActivity($userInfo['id'], 'batch_status_update', 'processing_logs', null, null, [
                        'batch_id' => $batch_id,
                        'status' => $status,
                        'notes' => $notes
                    ]);
                    
                    $_SESSION['flash_message'] = ['type' => 'success', 'message' => "Batch status updated successfully."];
                    break;
                    
                case 'add_batch_note':
                    $batch_id = sanitizeInput($_POST['batch_id']);
                    $note = sanitizeInput($_POST['note']);
                    
                    // Add note to the latest process in this batch
                    $stmt = $conn->prepare("
                        UPDATE processing_logs SET 
                            notes = CONCAT(COALESCE(notes, ''), '\n\nNote (', NOW(), '): ', ?)
                        WHERE batch_id = ? AND section = 2
                        ORDER BY created_at DESC LIMIT 1
                    ");
                    $stmt->execute([$note, $batch_id]);
                    
                    $_SESSION['flash_message'] = ['type' => 'success', 'message' => "Note added to batch successfully."];
                    break;
            }
        }
    } catch (Exception $e) {
        error_log("Batch tracking error: " . $e->getMessage());
        $_SESSION['flash_message'] = ['type' => 'error', 'message' => "Error: " . $e->getMessage()];
    }
    
    header('Location: batch_tracking.php');
    exit();
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$process_type = $_GET['process_type'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

// Build query conditions
$conditions = ['pl.section = 2'];
$params = [];

if (!empty($status_filter)) {
    if ($status_filter === 'active') {
        $conditions[] = "pl.end_time IS NULL";
    } elseif ($status_filter === 'completed') {
        $conditions[] = "pl.end_time IS NOT NULL";
    }
}

if (!empty($process_type)) {
    $conditions[] = "pl.process_type = ?";
    $params[] = $process_type;
}

if (!empty($date_from)) {
    $conditions[] = "DATE(pl.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $conditions[] = "DATE(pl.created_at) <= ?";
    $params[] = $date_to;
}

if (!empty($search)) {
    $conditions[] = "(pl.batch_id LIKE ? OR i.item_name LIKE ? OR pl.notes LIKE ?)";
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = 'WHERE ' . implode(' AND ', $conditions);

// Get batch statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(DISTINCT pl.batch_id) as total_batches,
        COUNT(DISTINCT CASE WHEN pl.end_time IS NULL THEN pl.batch_id END) as active_batches,
        COUNT(DISTINCT CASE WHEN pl.end_time IS NOT NULL THEN pl.batch_id END) as completed_batches,
        AVG(CASE WHEN pl.yield_percentage IS NOT NULL THEN pl.yield_percentage END) as avg_yield,
        COUNT(CASE WHEN pl.yield_percentage >= 90 THEN 1 END) as high_yield_processes,
        SUM(pl.input_quantity) as total_input,
        SUM(pl.output_quantity) as total_output
    FROM processing_logs pl
    LEFT JOIN inventory i ON pl.item_id = i.id
    $whereClause
");
$stats->execute($params);
$statistics = $stats->fetch(PDO::FETCH_ASSOC);

// Get batch summary data
$batches = $conn->prepare("
    SELECT 
        pl.batch_id,
        MIN(pl.start_time) as batch_start,
        MAX(pl.end_time) as batch_end,
        COUNT(*) as process_count,
        GROUP_CONCAT(DISTINCT pl.process_type) as process_types,
        GROUP_CONCAT(DISTINCT i.item_name) as items,
        SUM(pl.input_quantity) as total_input,
        SUM(pl.output_quantity) as total_output,
        AVG(pl.yield_percentage) as avg_yield,
        CASE 
            WHEN COUNT(CASE WHEN pl.end_time IS NULL THEN 1 END) > 0 THEN 'Active'
            ELSE 'Completed'
        END as batch_status,
        MAX(pl.created_at) as last_activity,
        GROUP_CONCAT(DISTINCT u.username) as operators
    FROM processing_logs pl
    LEFT JOIN inventory i ON pl.item_id = i.id
    LEFT JOIN users u ON pl.operator_id = u.id
    $whereClause
    GROUP BY pl.batch_id
    ORDER BY MAX(pl.created_at) DESC
    LIMIT 50
");
$batches->execute($params);
$batchList = $batches->fetchAll(PDO::FETCH_ASSOC);

// Get recent batch activities
$recentActivities = $conn->prepare("
    SELECT 
        pl.batch_id,
        pl.process_type,
        pl.process_stage,
        pl.start_time,
        pl.end_time,
        pl.yield_percentage,
        i.item_name,
        u.username as operator_name,
        CASE 
            WHEN pl.end_time IS NULL THEN 'Active'
            ELSE 'Completed'
        END as status
    FROM processing_logs pl
    LEFT JOIN inventory i ON pl.item_id = i.id
    LEFT JOIN users u ON pl.operator_id = u.id
    WHERE pl.section = 2
    ORDER BY pl.created_at DESC
    LIMIT 20
");
$recentActivities->execute();
$activities = $recentActivities->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Section 2 - Batch Tracking';
include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Section 2 - Batch Tracking</h1>
            <p class="text-muted">Monitor and manage batch processing operations</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['flash_message']['type']; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_SESSION['flash_message']['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_message']); ?>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card bg-primary text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Total Batches</h6>
                            <h4 class="mb-0"><?php echo number_format($statistics['total_batches'] ?? 0); ?></h4>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-boxes fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card bg-warning text-dark h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Active Batches</h6>
                            <h4 class="mb-0"><?php echo number_format($statistics['active_batches'] ?? 0); ?></h4>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-play-circle fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card bg-success text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Completed</h6>
                            <h4 class="mb-0"><?php echo number_format($statistics['completed_batches'] ?? 0); ?></h4>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-check-circle fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card bg-info text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Avg Yield</h6>
                            <h4 class="mb-0"><?php echo number_format($statistics['avg_yield'] ?? 0, 1); ?>%</h4>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-percentage fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card bg-secondary text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">High Yield</h6>
                            <h4 class="mb-0"><?php echo number_format($statistics['high_yield_processes'] ?? 0); ?></h4>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-trophy fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card bg-dark text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Total Output</h6>
                            <h4 class="mb-0"><?php echo number_format($statistics['total_output'] ?? 0, 1); ?> kg</h4>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-weight fa-2x opacity-75"></i>
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
                <div class="col-md-2">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="process_type" class="form-label">Process Type</label>
                    <select name="process_type" id="process_type" class="form-select">
                        <option value="">All Types</option>
                        <option value="drying" <?php echo $process_type == 'drying' ? 'selected' : ''; ?>>Drying</option>
                        <option value="dehydration" <?php echo $process_type == 'dehydration' ? 'selected' : ''; ?>>Dehydration</option>
                        <option value="packaging" <?php echo $process_type == 'packaging' ? 'selected' : ''; ?>>Packaging</option>
                        <option value="quality_check" <?php echo $process_type == 'quality_check' ? 'selected' : ''; ?>>Quality Check</option>
                        <option value="storage" <?php echo $process_type == 'storage' ? 'selected' : ''; ?>>Storage</option>
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
                
                <div class="col-md-2">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" name="search" id="search" class="form-control" 
                           placeholder="Batch ID, Item, Notes..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i>Filter
                        </button>
                        <a href="batch_tracking.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-2"></i>Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Main Content Row -->
    <div class="row">
        <!-- Batch Overview -->
        <div class="col-lg-8 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-list text-primary me-2"></i>Batch Overview
                    </h5>
                    <span class="badge bg-primary"><?php echo count($batchList); ?> batches</span>
                </div>
                <div class="card-body">
                    <?php if (empty($batchList)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <h6 class="text-muted">No Batches Found</h6>
                            <p class="text-muted">No batches match your current filter criteria.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Batch ID</th>
                                        <th>Process Types</th>
                                        <th>Items</th>
                                        <th>Input/Output</th>
                                        <th>Yield</th>
                                        <th>Status</th>
                                        <th>Last Activity</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($batchList as $batch): ?>
                                        <tr class="<?php echo $batch['batch_status'] == 'Active' ? 'table-warning' : ''; ?>">
                                            <td>
                                                <strong><?php echo htmlspecialchars($batch['batch_id']); ?></strong>
                                                <br><small class="text-muted"><?php echo $batch['process_count']; ?> processes</small>
                                            </td>
                                            <td>
                                                <?php 
                                                $types = explode(',', $batch['process_types']);
                                                foreach ($types as $type): 
                                                ?>
                                                    <span class="badge bg-secondary me-1">
                                                        <?php echo ucfirst(str_replace('_', ' ', trim($type))); ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($batch['items'] ?? 'N/A'); ?></small>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-column">
                                                    <small><strong>In:</strong> <?php echo number_format($batch['total_input'] ?? 0, 2); ?> kg</small>
                                                    <small><strong>Out:</strong> <?php echo number_format($batch['total_output'] ?? 0, 2); ?> kg</small>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($batch['avg_yield']): ?>
                                                    <span class="badge bg-<?php echo $batch['avg_yield'] >= 90 ? 'success' : ($batch['avg_yield'] >= 75 ? 'warning' : 'danger'); ?>">
                                                        <?php echo number_format($batch['avg_yield'], 1); ?>%
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $batch['batch_status'] == 'Active' ? 'warning text-dark' : 'success'; ?>">
                                                    <?php echo $batch['batch_status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small><?php echo timeAgo($batch['last_activity']); ?></small>
                                                <?php if ($batch['operators']): ?>
                                                    <br><small class="text-muted">by <?php echo htmlspecialchars($batch['operators']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-primary" 
                                                            onclick="updateBatchStatus('<?php echo htmlspecialchars($batch['batch_id']); ?>')">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-info" 
                                                            onclick="addBatchNote('<?php echo htmlspecialchars($batch['batch_id']); ?>')">
                                                        <i class="fas fa-sticky-note"></i>
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
        
        <!-- Recent Activities -->
        <div class="col-lg-4 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-clock text-info me-2"></i>Recent Activities
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($activities)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-history fa-3x text-muted mb-3"></i>
                            <h6 class="text-muted">No Recent Activities</h6>
                            <p class="text-muted">No batch activities recorded recently.</p>
                        </div>
                    <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($activities as $activity): ?>
                                <div class="timeline-item mb-3">
                                    <div class="d-flex">
                                        <div class="timeline-marker me-3">
                                            <div class="bg-<?php echo $activity['status'] == 'Active' ? 'warning' : 'success'; ?> rounded-circle" 
                                                 style="width: 12px; height: 12px;"></div>
                                        </div>
                                        <div class="timeline-content flex-grow-1">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <h6 class="mb-1">
                                                        <strong><?php echo htmlspecialchars($activity['batch_id']); ?></strong>
                                                        <span class="badge bg-secondary ms-2">
                                                            <?php echo ucfirst(str_replace('_', ' ', $activity['process_type'])); ?>
                                                        </span>
                                                    </div>
                                                    <small class="text-muted"><?php echo timeAgo($activity['start_time']); ?></small>
                                                </div>
                                                <?php if ($activity['yield_percentage']): ?>
                                                    <span class="badge bg-<?php echo $activity['yield_percentage'] >= 90 ? 'success' : ($activity['yield_percentage'] >= 75 ? 'warning' : 'danger'); ?>">
                                                        <?php echo number_format($activity['yield_percentage'], 1); ?>%
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="mb-1 small">
                                                <?php echo htmlspecialchars($activity['item_name'] ?? 'Unknown Item'); ?>
                                                <?php if ($activity['process_stage']): ?>
                                                    - <?php echo htmlspecialchars($activity['process_stage']); ?>
                                                <?php endif; ?>
                                            </p>
                                            <?php if ($activity['operator_name']): ?>
                                                <small class="text-muted">Operator: <?php echo htmlspecialchars($activity['operator_name']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Update Batch Status Modal -->
<div class="modal fade" id="updateBatchStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Batch Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_batch_status">
                <input type="hidden" name="batch_id" id="update_batch_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select name="status" id="batch_status" class="form-select" required>
                            <option value="">Select Status</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Quality Check">Quality Check</option>
                            <option value="On Hold">On Hold</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="notes" class="form-label">Status Notes</label>
                        <textarea name="notes" id="status_notes" class="form-control" rows="3" 
                                  placeholder="Add notes about this status update..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Batch Note Modal -->
<div class="modal fade" id="addBatchNoteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Batch Note</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_batch_note">
                <input type="hidden" name="batch_id" id="note_batch_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="note" class="form-label">Note</label>
                        <textarea name="note" id="batch_note" class="form-control" rows="4" 
                                  placeholder="Add a note to this batch..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Add Note
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.timeline-item {
    position: relative;
}

.timeline-marker {
    position: relative;
    z-index: 1;
}

.timeline-item:not(:last-child)::before {
    content: '';
    position: absolute;
    left: 5px;
    top: 20px;
    bottom: -15px;
    width: 2px;
    background-color: #dee2e6;
    z-index: 0;
}

.card-body {
    background-color: #ffffff;
    color: #212529;
}

.table {
    background-color: #ffffff;
    color: #212529;
}

.table-light {
    background-color: #f8f9fa;
    color: #212529;
}

.timeline-content {
    background-color: #ffffff;
    color: #212529;
}
</style>

<script>
function updateBatchStatus(batchId) {
    document.getElementById('update_batch_id').value = batchId;
    new bootstrap.Modal(document.getElementById('updateBatchStatusModal')).show();
}

function addBatchNote(batchId) {
    document.getElementById('note_batch_id').value = batchId;
    new bootstrap.Modal(document.getElementById('addBatchNoteModal')).show();
}

// Auto-refresh if there are active batches
setInterval(function() {
    if (<?php echo $statistics['active_batches'] ?? 0; ?> > 0) {
        location.reload();
    }
}, 60000); // Refresh every 60 seconds
</script>

<?php include '../../includes/footer.php'; ?>
