<?php
/**
 * KYA Food Production - Section 3 Processing Management
 * Comprehensive processing operations management for Section 3
 */

require_once '../../config/database.php';
require_once '../../config/constants.php';
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
                case 'start_process':
                    $item_id = (int)$_POST['item_id'];
                    $process_type = sanitizeInput($_POST['process_type']);
                    $process_stage = sanitizeInput($_POST['process_stage']);
                    $input_quantity = (float)$_POST['input_quantity'];
                    $equipment_used = sanitizeInput($_POST['equipment_used']);
                    $notes = sanitizeInput($_POST['notes']);
                    
                    // Generate batch ID
                    $batch_id = 'S3-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    
                    // Insert new process
                    $stmt = $conn->prepare("
                        INSERT INTO processing_logs (
                            section, batch_id, item_id, process_type, process_stage, 
                            input_quantity, equipment_used, operator_id, notes, start_time
                        ) VALUES (3, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $stmt->execute([
                        $batch_id, $item_id, $process_type, $process_stage,
                        $input_quantity, $equipment_used, $userInfo['id'], $notes
                    ]);
                    
                    // Log activity
                    logActivity($userInfo['id'], 'start_process', 'processing_logs', $conn->lastInsertId(), null, [
                        'batch_id' => $batch_id,
                        'process_type' => $process_type,
                        'section' => 3
                    ]);
                    
                    $_SESSION['flash_message'] = ['type' => 'success', 'message' => "Process started successfully with Batch ID: $batch_id"];
                    break;
                    
                case 'complete_process':
                    $process_id = (int)$_POST['process_id'];
                    $output_quantity = (float)$_POST['output_quantity'];
                    $waste_quantity = (float)$_POST['waste_quantity'];
                    $quality_grade_output = sanitizeInput($_POST['quality_grade_output']);
                    $temperature_end = $_POST['temperature_end'] ? (float)$_POST['temperature_end'] : null;
                    $humidity_end = $_POST['humidity_end'] ? (float)$_POST['humidity_end'] : null;
                    $completion_notes = sanitizeInput($_POST['completion_notes']);
                    
                    // Get process details
                    $processStmt = $conn->prepare("SELECT * FROM processing_logs WHERE id = ? AND section = 3");
                    $processStmt->execute([$process_id]);
                    $process = $processStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($process) {
                        // Calculate yield and duration
                        $yield_percentage = ($process['input_quantity'] > 0) ? 
                            (($output_quantity / $process['input_quantity']) * 100) : 0;
                        
                        $start_time = new DateTime($process['start_time']);
                        $end_time = new DateTime();
                        $duration_minutes = $end_time->diff($start_time)->i + 
                                          ($end_time->diff($start_time)->h * 60) + 
                                          ($end_time->diff($start_time)->days * 24 * 60);
                        
                        // Update process
                        $updateStmt = $conn->prepare("
                            UPDATE processing_logs SET 
                                output_quantity = ?, waste_quantity = ?, yield_percentage = ?,
                                quality_grade_output = ?, temperature_end = ?, humidity_end = ?,
                                end_time = NOW(), duration_minutes = ?,
                                notes = CONCAT(COALESCE(notes, ''), '\n\nCompletion: ', ?)
                            WHERE id = ? AND section = 3
                        ");
                        
                        $updateStmt->execute([
                            $output_quantity, $waste_quantity, $yield_percentage,
                            $quality_grade_output, $temperature_end, $humidity_end,
                            $duration_minutes, $completion_notes, $process_id
                        ]);
                        
                        // Log activity
                        logActivity($userInfo['id'], 'complete_process', 'processing_logs', $process_id, null, [
                            'batch_id' => $process['batch_id'],
                            'yield_percentage' => $yield_percentage,
                            'section' => 3
                        ]);
                        
                        $_SESSION['flash_message'] = ['type' => 'success', 'message' => "Process completed successfully with {$yield_percentage}% yield."];
                    }
                    break;
                    
                case 'update_process':
                    $process_id = (int)$_POST['process_id'];
                    $temperature_start = $_POST['temperature_start'] ? (float)$_POST['temperature_start'] : null;
                    $humidity_start = $_POST['humidity_start'] ? (float)$_POST['humidity_start'] : null;
                    $process_stage = sanitizeInput($_POST['process_stage']);
                    $update_notes = sanitizeInput($_POST['update_notes']);
                    
                    $updateStmt = $conn->prepare("
                        UPDATE processing_logs SET 
                            temperature_start = ?, humidity_start = ?, process_stage = ?,
                            notes = CONCAT(COALESCE(notes, ''), '\n\nUpdate: ', ?)
                        WHERE id = ? AND section = 3
                    ");
                    
                    $updateStmt->execute([
                        $temperature_start, $humidity_start, $process_stage,
                        $update_notes, $process_id
                    ]);
                    
                    $_SESSION['flash_message'] = ['type' => 'success', 'message' => "Process updated successfully."];
                    break;
            }
        }
    } catch (Exception $e) {
        error_log("Processing error: " . $e->getMessage());
        $_SESSION['flash_message'] = ['type' => 'error', 'message' => "Error: " . $e->getMessage()];
    }
    
    header('Location: processing.php');
    exit();
}

// Get filter parameters
$process_type = $_GET['process_type'] ?? '';
$status = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query conditions
$conditions = ['pl.section = 3'];
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
        COUNT(CASE WHEN pl.end_time IS NULL THEN 1 END) as active_processes,
        COUNT(CASE WHEN pl.end_time IS NOT NULL THEN 1 END) as completed_processes,
        AVG(CASE WHEN pl.yield_percentage IS NOT NULL THEN pl.yield_percentage END) as avg_yield,
        AVG(CASE WHEN pl.duration_minutes IS NOT NULL THEN pl.duration_minutes END) as avg_duration,
        SUM(pl.output_quantity) as total_output
    FROM processing_logs pl
    $whereClause
");
$stats->execute($params);
$statistics = $stats->fetch(PDO::FETCH_ASSOC);

// Get active processes
$activeProcesses = $conn->prepare("
    SELECT pl.*, i.item_name
    FROM processing_logs pl
    LEFT JOIN inventory i ON pl.item_id = i.id
    WHERE pl.section = 3 AND pl.end_time IS NULL
    ORDER BY pl.start_time ASC
");
$activeProcesses->execute();
$activeProcessesList = $activeProcesses->fetchAll(PDO::FETCH_ASSOC);

// Get recent processes
$recentProcesses = $conn->prepare("
    SELECT pl.*, i.item_name
    FROM processing_logs pl
    LEFT JOIN inventory i ON pl.item_id = i.id
    $whereClause
    ORDER BY pl.created_at DESC
    LIMIT 20
");
$recentProcesses->execute($params);
$recentProcessesList = $recentProcesses->fetchAll(PDO::FETCH_ASSOC);

// Get inventory items for Section 3
$inventoryItems = $conn->prepare("
    SELECT * FROM inventory 
    WHERE section = 3 AND status = 'active' AND quantity > 0
    ORDER BY item_name
");
$inventoryItems->execute();
$inventoryItems = $inventoryItems->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Section 3 - Processing Management';
include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Section 3 - Processing Management</h1>
            <p class="text-muted">Monitor and manage processing operations</p>
        </div>
        <div>
            <button type="button" class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#startProcessModal">
                <i class="fas fa-play me-2"></i>Start New Process
            </button>
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
                            <h6 class="card-title">Total Processes</h6>
                            <h4 class="mb-0"><?php echo number_format($statistics['total_processes'] ?? 0); ?></h4>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-cogs fa-2x opacity-75"></i>
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
                            <h6 class="card-title">Active</h6>
                            <h4 class="mb-0"><?php echo number_format($statistics['active_processes'] ?? 0); ?></h4>
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
                            <h4 class="mb-0"><?php echo number_format($statistics['completed_processes'] ?? 0); ?></h4>
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
                            <h6 class="card-title">Avg Duration</h6>
                            <h4 class="mb-0"><?php echo number_format($statistics['avg_duration'] ?? 0, 0); ?> min</h4>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-clock fa-2x opacity-75"></i>
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
