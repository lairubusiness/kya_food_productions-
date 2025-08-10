<?php
/**
 * KYA Food Production - Section 2 Processing Management
 * Comprehensive processing operations management for Section 2
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
                case 'start_process':
                    // Start new processing operation
                    $batch_id = 'BATCH-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    $item_id = sanitizeInput($_POST['item_id']);
                    $process_type = sanitizeInput($_POST['process_type']);
                    $process_stage = sanitizeInput($_POST['process_stage']);
                    $input_quantity = floatval($_POST['input_quantity']);
                    $equipment_used = sanitizeInput($_POST['equipment_used']);
                    $notes = sanitizeInput($_POST['notes']);
                    
                    $stmt = $conn->prepare("
                        INSERT INTO processing_logs (
                            section, batch_id, item_id, process_type, process_stage,
                            input_quantity, start_time, equipment_used, operator_id, notes, created_at
                        ) VALUES (2, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$batch_id, $item_id, $process_type, $process_stage, $input_quantity, $equipment_used, $userInfo['id'], $notes]);
                    
                    // Log activity
                    logActivity($userInfo['id'], 'processing_start', 'processing_logs', $conn->lastInsertId(), null, [
                        'batch_id' => $batch_id,
                        'process_type' => $process_type,
                        'input_quantity' => $input_quantity
                    ]);
                    
                    // Create notification
                    createNotification($userInfo['id'], 'processing', 'success', "Processing started for batch {$batch_id}", 'high');
                    
                    $_SESSION['flash_message'] = ['type' => 'success', 'message' => "Processing operation started successfully. Batch ID: {$batch_id}"];
                    break;
                    
                case 'complete_process':
                    // Complete processing operation
                    $process_id = intval($_POST['process_id']);
                    $output_quantity = floatval($_POST['output_quantity']);
                    $waste_quantity = floatval($_POST['waste_quantity']);
                    $quality_grade_output = sanitizeInput($_POST['quality_grade_output']);
                    $temperature_end = floatval($_POST['temperature_end']);
                    $humidity_end = floatval($_POST['humidity_end']);
                    $completion_notes = sanitizeInput($_POST['completion_notes']);
                    
                    // Get process data
                    $process = $conn->prepare("SELECT * FROM processing_logs WHERE id = ? AND section = 2");
                    $process->execute([$process_id]);
                    $processData = $process->fetch();
                    
                    if ($processData) {
                        $yield_percentage = ($output_quantity / max($processData['input_quantity'], 1)) * 100;
                        $duration_minutes = round((time() - strtotime($processData['start_time'])) / 60);
                        
                        $stmt = $conn->prepare("
                            UPDATE processing_logs SET 
                                output_quantity = ?, waste_quantity = ?, yield_percentage = ?,
                                end_time = NOW(), duration_minutes = ?, quality_grade_output = ?,
                                temperature_end = ?, humidity_end = ?, notes = CONCAT(COALESCE(notes, ''), '\n\nCompletion: ', ?)
                            WHERE id = ? AND section = 2
                        ");
                        $stmt->execute([$output_quantity, $waste_quantity, $yield_percentage, $duration_minutes, 
                                      $quality_grade_output, $temperature_end, $humidity_end, $completion_notes, $process_id]);
                        
                        // Log activity
                        logActivity($userInfo['id'], 'processing_complete', 'processing_logs', $process_id, null, [
                            'batch_id' => $processData['batch_id'],
                            'yield_percentage' => $yield_percentage,
                            'output_quantity' => $output_quantity
                        ]);
                        
                        // Create notification
                        createNotification($userInfo['id'], 'processing', 'success', 
                                         "Processing completed for batch {$processData['batch_id']} with {$yield_percentage}% yield", 'medium');
                        
                        $_SESSION['flash_message'] = ['type' => 'success', 'message' => "Processing operation completed successfully."];
                    }
                    break;
                    
                case 'update_process':
                    // Update process parameters
                    $process_id = intval($_POST['process_id']);
                    $temperature_start = floatval($_POST['temperature_start']);
                    $humidity_start = floatval($_POST['humidity_start']);
                    $process_stage = sanitizeInput($_POST['process_stage']);
                    $update_notes = sanitizeInput($_POST['update_notes']);
                    
                    $stmt = $conn->prepare("
                        UPDATE processing_logs SET 
                            temperature_start = ?, humidity_start = ?, process_stage = ?,
                            notes = CONCAT(COALESCE(notes, ''), '\n\nUpdate: ', ?)
                        WHERE id = ? AND section = 2
                    ");
                    $stmt->execute([$temperature_start, $humidity_start, $process_stage, $update_notes, $process_id]);
                    
                    $_SESSION['flash_message'] = ['type' => 'success', 'message' => "Process updated successfully."];
                    break;
            }
        }
    } catch (Exception $e) {
        error_log("Processing management error: " . $e->getMessage());
        $_SESSION['flash_message'] = ['type' => 'error', 'message' => "Error: " . $e->getMessage()];
    }
    
    header('Location: processing.php');
    exit();
}

// Get filter parameters
$process_type = $_GET['process_type'] ?? '';
$status = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Build query conditions
$conditions = ['pl.section = 2'];
$params = [];

if (!empty($process_type)) {
    $conditions[] = "pl.process_type = ?";
    $params[] = $process_type;
}

if (!empty($status)) {
    if ($status === 'active') {
        $conditions[] = "pl.end_time IS NULL";
    } elseif ($status === 'completed') {
        $conditions[] = "pl.end_time IS NOT NULL";
    }
}

if (!empty($date_from)) {
    $conditions[] = "DATE(pl.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $conditions[] = "DATE(pl.created_at) <= ?";
    $params[] = $date_to;
}

$whereClause = 'WHERE ' . implode(' AND ', $conditions);

// Get processing statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(*) as total_processes,
        COUNT(CASE WHEN end_time IS NULL THEN 1 END) as active_processes,
        COUNT(CASE WHEN end_time IS NOT NULL THEN 1 END) as completed_processes,
        AVG(CASE WHEN yield_percentage IS NOT NULL THEN yield_percentage END) as avg_yield,
        AVG(CASE WHEN duration_minutes IS NOT NULL THEN duration_minutes END) as avg_duration,
        SUM(input_quantity) as total_input,
        SUM(output_quantity) as total_output
    FROM processing_logs pl
    $whereClause
");
$stats->execute($params);
$statistics = $stats->fetch(PDO::FETCH_ASSOC);

// Get active processes
$activeProcesses = $conn->prepare("
    SELECT pl.*, i.item_name, i.item_code, u.username as operator_name
    FROM processing_logs pl
    LEFT JOIN inventory i ON pl.item_id = i.id
    LEFT JOIN users u ON pl.operator_id = u.id
    WHERE pl.section = 2 AND pl.end_time IS NULL
    ORDER BY pl.start_time DESC
");
$activeProcesses->execute();
$activeProcessesList = $activeProcesses->fetchAll(PDO::FETCH_ASSOC);

// Get recent processes
$recentProcesses = $conn->prepare("
    SELECT pl.*, i.item_name, i.item_code, u1.username as operator_name, u2.username as supervisor_name
    FROM processing_logs pl
    LEFT JOIN inventory i ON pl.item_id = i.id
    LEFT JOIN users u1 ON pl.operator_id = u1.id
    LEFT JOIN users u2 ON pl.supervisor_id = u2.id
    $whereClause
    ORDER BY pl.created_at DESC
    LIMIT 20
");
$recentProcesses->execute($params);
$recentProcessesList = $recentProcesses->fetchAll(PDO::FETCH_ASSOC);

// Get available inventory items for Section 2
$inventoryItems = $conn->query("
    SELECT id, item_code, item_name, quantity, unit
    FROM inventory 
    WHERE section = 2 AND status = 'active' AND quantity > 0
    ORDER BY item_name
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Section 2 - Processing Management';
include '../../includes/header.php';
?>

<style>
    .stats-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
        color: white;
        transition: transform 0.2s;
    }
    .stats-card:hover {
        transform: translateY(-5px);
    }
    .stats-card .card-body {
        padding: 1.5rem;
    }
    .stats-card h3 {
        font-size: 2rem;
        font-weight: bold;
        margin-bottom: 0.5rem;
        color: white;
    }
    .stats-card p {
        margin-bottom: 0;
        opacity: 0.9;
        color: white;
    }
    .stats-card i {
        font-size: 2.5rem;
        opacity: 0.8;
        color: white;
    }
    
    .primary-card {
        background: linear-gradient(135deg, #339af0 0%, #228be6 100%);
        color: white;
    }
    .primary-card h3, .primary-card p, .primary-card i {
        color: white;
    }
    
    .success-card {
        background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
        color: white;
    }
    .success-card h3, .success-card p, .success-card i {
        color: white;
    }
    
    .info-card {
        background: linear-gradient(135deg, #339af0 0%, #228be6 100%);
        color: white;
    }
    .info-card h3, .info-card p, .info-card i {
        color: white;
    }
    
    .warning-card {
        background: linear-gradient(135deg, #ffd43b 0%, #fab005 100%);
        color: #212529;
    }
    .warning-card h3, .warning-card p, .warning-card i {
        color: #212529;
    }
    
    .danger-card {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
        color: white;
    }
    .danger-card h3, .danger-card p, .danger-card i {
        color: white;
    }
    
    .purple-card {
        background: linear-gradient(135deg, #845ec2 0%, #6c5ce7 100%);
        color: white;
    }
    .purple-card h3, .purple-card p, .purple-card i {
        color: white;
    }
    
    /* Fix card text contrast */
    .card {
        background-color: #ffffff;
        color: #212529;
    }
    .card-header {
        background-color: #f8f9fa;
        color: #212529;
        border-bottom: 1px solid #dee2e6;
    }
    .card-body {
        color: #212529;
    }
    
    /* Fix form contrast */
    .form-label {
        color: #212529 !important;
        font-weight: 500;
    }
    
    .form-control,
    .form-select {
        background-color: #ffffff;
        border: 1px solid #ced4da;
        color: #212529;
    }
    
    .form-control:focus,
    .form-select:focus {
        background-color: #ffffff;
        border-color: #86b7fe;
        color: #212529;
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
    }
    
    /* Fix badge contrast */
    .badge {
        color: #ffffff;
    }
    .badge.bg-success {
        background-color: #198754 !important;
        color: #ffffff !important;
    }
    .badge.bg-warning {
        background-color: #ffc107 !important;
        color: #212529 !important;
    }
    .badge.bg-danger {
        background-color: #dc3545 !important;
        color: #ffffff !important;
    }
    .badge.bg-info {
        background-color: #0dcaf0 !important;
        color: #212529 !important;
    }
    .badge.bg-primary {
        background-color: #0d6efd !important;
        color: #ffffff !important;
    }
    
    /* Process status indicators */
    .process-active {
        border-left: 4px solid #28a745;
        background-color: #f8fff9;
    }
    
    .process-completed {
        border-left: 4px solid #007bff;
        background-color: #f8f9ff;
    }
    
    .process-high-yield {
        border-left: 4px solid #ffc107;
        background-color: #fffef8;
    }
    
    /* Modal styling */
    .modal-content {
        background-color: #ffffff;
        color: #212529;
    }
    
    .modal-header {
        background-color: #f8f9fa;
        color: #212529;
        border-bottom: 1px solid #dee2e6;
    }
    
    .modal-body {
        color: #212529;
    }
</style>

<div class="content-area">
    <!-- Flash Messages -->
    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['flash_message']['type']; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_SESSION['flash_message']['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_message']); ?>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <span class="badge me-2 bg-warning text-dark">Section 2</span>
                Processing Management
            </h1>
            <p class="text-muted mb-0">Manage food processing operations, drying, dehydration, and quality control</p>
        </div>
        <div class="btn-group" role="group">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#startProcessModal">
                <i class="fas fa-play me-2"></i>Start Process
            </button>
            <a href="../processing/logs.php?section=2" class="btn btn-outline-primary">
                <i class="fas fa-clipboard-list me-2"></i>View All Logs
            </a>
            <a href="batch_tracking.php" class="btn btn-outline-info">
                <i class="fas fa-boxes me-2"></i>Batch Tracking
            </a>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-dashboard me-2"></i>Dashboard
            </a>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stats-card primary-card">
                <div class="card-body text-center">
                    <i class="fas fa-cogs mb-2"></i>
                    <h3><?php echo number_format($statistics['total_processes']); ?></h3>
                    <p>Total Processes</p>
                </div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stats-card <?php echo $statistics['active_processes'] > 0 ? 'warning-card' : 'success-card'; ?>">
                <div class="card-body text-center">
                    <i class="fas fa-play mb-2"></i>
                    <h3><?php echo number_format($statistics['active_processes']); ?></h3>
                    <p>Active</p>
                </div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stats-card success-card">
                <div class="card-body text-center">
                    <i class="fas fa-check mb-2"></i>
                    <h3><?php echo number_format($statistics['completed_processes']); ?></h3>
                    <p>Completed</p>
                </div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stats-card info-card">
                <div class="card-body text-center">
                    <i class="fas fa-percentage mb-2"></i>
                    <h3><?php echo number_format($statistics['avg_yield'], 1); ?>%</h3>
                    <p>Avg Yield</p>
                </div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stats-card purple-card">
                <div class="card-body text-center">
                    <i class="fas fa-clock mb-2"></i>
                    <h3><?php echo number_format($statistics['avg_duration'], 0); ?></h3>
                    <p>Avg Duration (min)</p>
                </div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card stats-card danger-card">
                <div class="card-body text-center">
                    <i class="fas fa-arrow-up mb-2"></i>
                    <h3><?php echo number_format($statistics['total_output'], 1); ?></h3>
                    <p>Total Output (kg)</p>
                </div>
            </div>
        </div>
    </div>
