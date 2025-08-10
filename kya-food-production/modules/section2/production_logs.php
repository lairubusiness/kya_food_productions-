<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../classes/SessionManager.php';

// Check if user is logged in
if (!SessionManager::isLoggedIn()) {
    header('Location: ../../login.php');
    exit();
}

$userInfo = SessionManager::getUserInfo();

// Check if user has access to Section 2
if (!in_array($userInfo['role'], ['admin', 'section2_mgr'])) {
    SessionManager::setFlashMessage('You do not have permission to access this section.', 'error');
    header('Location: ../../dashboard.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Create production_logs table if it doesn't exist
$createTableQuery = "
CREATE TABLE IF NOT EXISTS production_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id VARCHAR(50) NOT NULL,
    section_id INT DEFAULT 2,
    process_type ENUM('processing', 'drying', 'dehydration', 'quality_check', 'packaging') NOT NULL,
    item_id INT,
    item_name VARCHAR(255),
    input_quantity DECIMAL(10,2),
    output_quantity DECIMAL(10,2),
    yield_percentage DECIMAL(5,2),
    start_time DATETIME,
    end_time DATETIME,
    duration_minutes INT,
    temperature DECIMAL(5,2),
    humidity DECIMAL(5,2),
    quality_grade ENUM('A', 'B', 'C', 'Rejected') DEFAULT 'A',
    operator_id INT,
    supervisor_id INT,
    status ENUM('Active', 'Completed', 'Pending', 'Failed') DEFAULT 'Active',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE SET NULL,
    FOREIGN KEY (operator_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_batch_id (batch_id),
    INDEX idx_section_id (section_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
)";

try {
    $db->exec($createTableQuery);
} catch (PDOException $e) {
    // Table might already exist, continue
}

// Generate sample data if table is empty
$checkDataQuery = "SELECT COUNT(*) FROM production_logs WHERE section_id = 2";
$stmt = $db->prepare($checkDataQuery);
$stmt->execute();
$dataCount = $stmt->fetchColumn();

if ($dataCount == 0) {
    // Generate sample production logs
    $sampleLogs = [
        ['BATCH-S2-001', 'processing', 'Raw Vegetables', 500.00, 450.00, 90.00, '2024-01-15 08:00:00', '2024-01-15 12:00:00', 240, 65.5, 45.2, 'A', 'Active'],
        ['BATCH-S2-002', 'drying', 'Processed Fruits', 300.00, 280.00, 93.33, '2024-01-15 09:30:00', '2024-01-15 15:30:00', 360, 70.0, 40.0, 'A', 'Completed'],
        ['BATCH-S2-003', 'dehydration', 'Mixed Vegetables', 400.00, 360.00, 90.00, '2024-01-15 10:00:00', '2024-01-15 18:00:00', 480, 75.5, 35.8, 'B', 'Completed'],
        ['BATCH-S2-004', 'quality_check', 'Dried Fruits', 200.00, 195.00, 97.50, '2024-01-15 11:00:00', '2024-01-15 13:00:00', 120, 25.0, 50.0, 'A', 'Completed'],
        ['BATCH-S2-005', 'processing', 'Organic Vegetables', 600.00, 540.00, 90.00, '2024-01-15 14:00:00', NULL, NULL, 68.0, 42.5, 'A', 'Active'],
        ['BATCH-S2-006', 'drying', 'Seasonal Fruits', 350.00, 315.00, 90.00, '2024-01-14 08:00:00', '2024-01-14 16:00:00', 480, 72.0, 38.0, 'A', 'Completed'],
        ['BATCH-S2-007', 'dehydration', 'Root Vegetables', 450.00, 405.00, 90.00, '2024-01-14 09:00:00', '2024-01-14 17:00:00', 480, 78.0, 33.0, 'B', 'Completed'],
        ['BATCH-S2-008', 'processing', 'Leafy Greens', 250.00, 225.00, 90.00, '2024-01-14 10:30:00', '2024-01-14 14:30:00', 240, 62.0, 48.0, 'A', 'Completed']
    ];
    
    $insertQuery = "INSERT INTO production_logs (batch_id, section_id, process_type, item_name, input_quantity, output_quantity, yield_percentage, start_time, end_time, duration_minutes, temperature, humidity, quality_grade, operator_id, supervisor_id, status, notes) VALUES (?, 2, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $db->prepare($insertQuery);
    
    foreach ($sampleLogs as $log) {
        $stmt->execute([
            $log[0], $log[1], $log[2], $log[3], $log[4], $log[5], $log[6], $log[7], $log[8], $log[9], $log[10], $log[11], 
            $userInfo['id'], $userInfo['id'], $log[12], 'Sample production log entry for demonstration'
        ]);
    }
}

// Handle filters
$processTypeFilter = $_GET['process_type'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$batchFilter = $_GET['batch_id'] ?? '';
$dateFromFilter = $_GET['date_from'] ?? '';
$dateToFilter = $_GET['date_to'] ?? '';
$searchFilter = $_GET['search'] ?? '';

// Build query with filters
$whereConditions = ['section_id = 2'];
$params = [];

if (!empty($processTypeFilter)) {
    $whereConditions[] = 'process_type = ?';
    $params[] = $processTypeFilter;
}

if (!empty($statusFilter)) {
    $whereConditions[] = 'status = ?';
    $params[] = $statusFilter;
}

if (!empty($batchFilter)) {
    $whereConditions[] = 'batch_id LIKE ?';
    $params[] = '%' . $batchFilter . '%';
}

if (!empty($dateFromFilter)) {
    $whereConditions[] = 'DATE(created_at) >= ?';
    $params[] = $dateFromFilter;
}

if (!empty($dateToFilter)) {
    $whereConditions[] = 'DATE(created_at) <= ?';
    $params[] = $dateToFilter;
}

if (!empty($searchFilter)) {
    $whereConditions[] = '(item_name LIKE ? OR batch_id LIKE ? OR notes LIKE ?)';
    $params[] = '%' . $searchFilter . '%';
    $params[] = '%' . $searchFilter . '%';
    $params[] = '%' . $searchFilter . '%';
}

$whereClause = implode(' AND ', $whereConditions);

// Get summary statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_logs,
        COUNT(DISTINCT batch_id) as total_batches,
        COALESCE(SUM(input_quantity), 0) as total_input,
        COALESCE(SUM(output_quantity), 0) as total_output,
        COALESCE(AVG(yield_percentage), 0) as avg_yield,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_processes
    FROM production_logs 
    WHERE $whereClause
";

$stmt = $db->prepare($statsQuery);
$stmt->execute($params);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get production logs
$logsQuery = "
    SELECT 
        pl.*,
        u1.full_name as operator_name,
        u2.full_name as supervisor_name
    FROM production_logs pl
    LEFT JOIN users u1 ON pl.operator_id = u1.id
    LEFT JOIN users u2 ON pl.supervisor_id = u2.id
    WHERE $whereClause
    ORDER BY pl.created_at DESC, pl.start_time DESC
    LIMIT 50
";

$stmt = $db->prepare($logsQuery);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get process types for filter
$processTypes = ['processing', 'drying', 'dehydration', 'quality_check', 'packaging'];
$statuses = ['Active', 'Completed', 'Pending', 'Failed'];

$pageTitle = "Production Logs - Section 2";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../../assets/css/style.css" rel="stylesheet">
    <link href="../../assets/css/dashboard.css" rel="stylesheet">
    <style>
        .production-logs-header {
            background: linear-gradient(135deg, #2d5016 0%, #4a7c2a 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(45, 80, 22, 0.3);
        }
        
        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #4a7c2a;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .stats-number {
            font-size: 2rem;
            font-weight: bold;
            color: #2d5016;
            margin-bottom: 0.5rem;
        }
        
        .stats-label {
            color: #666;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stats-icon {
            font-size: 2.5rem;
            opacity: 0.7;
            color: #4a7c2a;
        }
        
        .filters-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }
        
        .logs-table {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .table th {
            background: #f8f9fa;
            border: none;
            font-weight: 600;
            color: #2d5016;
            padding: 1rem;
        }
        
        .table td {
            padding: 1rem;
            vertical-align: middle;
            border-color: #e9ecef;
        }
        
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-active { background: #d4edda; color: #155724; }
        .status-completed { background: #cce5ff; color: #004085; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-failed { background: #f8d7da; color: #721c24; }
        
        .quality-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .quality-a { background: #d4edda; color: #155724; }
        .quality-b { background: #fff3cd; color: #856404; }
        .quality-c { background: #ffeaa7; color: #b8860b; }
        .quality-rejected { background: #f8d7da; color: #721c24; }
        
        .yield-progress {
            width: 60px;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .yield-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .yield-excellent { background: #28a745; }
        .yield-good { background: #17a2b8; }
        .yield-average { background: #ffc107; }
        .yield-poor { background: #dc3545; }
        
        .auto-refresh {
            position: fixed;
            top: 100px;
            right: 20px;
            z-index: 1000;
        }
        
        .refresh-indicator {
            background: #4a7c2a;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            box-shadow: 0 4px 15px rgba(74, 124, 42, 0.3);
        }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container-fluid px-4">
        <!-- Header -->
        <div class="production-logs-header text-center">
            <h1 class="mb-3">
                <i class="fas fa-clipboard-list me-3"></i>
                Production Logs - Section 2
            </h1>
            <p class="mb-0 opacity-90">Monitor and track all production activities and processes</p>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="stats-card text-center">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stats-number"><?php echo number_format($stats['total_logs']); ?></div>
                            <div class="stats-label">Total Logs</div>
                        </div>
                        <i class="fas fa-list-alt stats-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="stats-card text-center">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stats-number"><?php echo number_format($stats['total_batches']); ?></div>
                            <div class="stats-label">Total Batches</div>
                        </div>
                        <i class="fas fa-boxes stats-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="stats-card text-center">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stats-number"><?php echo number_format($stats['total_input'], 1); ?></div>
                            <div class="stats-label">Total Input (kg)</div>
                        </div>
                        <i class="fas fa-arrow-down stats-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="stats-card text-center">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stats-number"><?php echo number_format($stats['total_output'], 1); ?></div>
                            <div class="stats-label">Total Output (kg)</div>
                        </div>
                        <i class="fas fa-arrow-up stats-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="stats-card text-center">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stats-number"><?php echo number_format($stats['avg_yield'], 1); ?>%</div>
                            <div class="stats-label">Average Yield</div>
                        </div>
                        <i class="fas fa-percentage stats-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="stats-card text-center">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stats-number"><?php echo number_format($stats['active_processes']); ?></div>
                            <div class="stats-label">Active Processes</div>
                        </div>
                        <i class="fas fa-play-circle stats-icon"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filters-card">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Process Type</label>
                    <select name="process_type" class="form-select">
                        <option value="">All Types</option>
                        <?php foreach ($processTypes as $type): ?>
                            <option value="<?php echo $type; ?>" <?php echo $processTypeFilter === $type ? 'selected' : ''; ?>>
                                <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?php echo $status; ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>>
                                <?php echo $status; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Batch ID</label>
                    <input type="text" name="batch_id" class="form-control" value="<?php echo htmlspecialchars($batchFilter); ?>" placeholder="Search batch...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo $dateFromFilter; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo $dateToFilter; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Search</label>
                    <div class="input-group">
                        <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($searchFilter); ?>" placeholder="Search...">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            </form>
            <div class="mt-3">
                <a href="?" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-times me-1"></i>Clear Filters
                </a>
                <button type="button" class="btn btn-success btn-sm ms-2" onclick="exportData()">
                    <i class="fas fa-download me-1"></i>Export CSV
                </button>
            </div>
        </div>
        
        <!-- Production Logs Table -->
        <div class="logs-table">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Batch ID</th>
                            <th>Process Type</th>
                            <th>Item Name</th>
                            <th>Input/Output (kg)</th>
                            <th>Yield %</th>
                            <th>Duration</th>
                            <th>Temp/Humidity</th>
                            <th>Quality</th>
                            <th>Operator</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-4">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">No production logs found matching your criteria.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($log['batch_id']); ?></strong>
                                        <br>
                                        <small class="text-muted"><?php echo date('M j, Y', strtotime($log['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <i class="fas fa-cog me-2 text-primary"></i>
                                        <?php echo ucfirst(str_replace('_', ' ', $log['process_type'])); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['item_name']); ?></td>
                                    <td>
                                        <div class="d-flex justify-content-between">
                                            <span class="text-danger">↓ <?php echo number_format($log['input_quantity'], 1); ?></span>
                                            <span class="text-success">↑ <?php echo number_format($log['output_quantity'], 1); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="yield-progress me-2">
                                                <?php 
                                                $yieldClass = 'yield-poor';
                                                if ($log['yield_percentage'] >= 95) $yieldClass = 'yield-excellent';
                                                elseif ($log['yield_percentage'] >= 90) $yieldClass = 'yield-good';
                                                elseif ($log['yield_percentage'] >= 80) $yieldClass = 'yield-average';
                                                ?>
                                                <div class="yield-bar <?php echo $yieldClass; ?>" style="width: <?php echo $log['yield_percentage']; ?>%"></div>
                                            </div>
                                            <small><?php echo number_format($log['yield_percentage'], 1); ?>%</small>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($log['duration_minutes']): ?>
                                            <?php echo floor($log['duration_minutes'] / 60); ?>h <?php echo $log['duration_minutes'] % 60; ?>m
                                        <?php else: ?>
                                            <span class="text-muted">In Progress</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small>
                                            <i class="fas fa-thermometer-half text-danger"></i> <?php echo $log['temperature']; ?>°C<br>
                                            <i class="fas fa-tint text-primary"></i> <?php echo $log['humidity']; ?>%
                                        </small>
                                    </td>
                                    <td>
                                        <span class="quality-badge quality-<?php echo strtolower($log['quality_grade']); ?>">
                                            Grade <?php echo $log['quality_grade']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small>
                                            <strong><?php echo htmlspecialchars($log['operator_name'] ?? 'N/A'); ?></strong><br>
                                            <span class="text-muted">Supervisor: <?php echo htmlspecialchars($log['supervisor_name'] ?? 'N/A'); ?></span>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($log['status']); ?>">
                                            <?php echo $log['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-outline-primary" onclick="viewLog(<?php echo $log['id']; ?>)" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($log['status'] === 'Active'): ?>
                                                <button class="btn btn-outline-success" onclick="completeLog(<?php echo $log['id']; ?>)" title="Mark Complete">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Auto Refresh Indicator -->
    <div class="auto-refresh">
        <div class="refresh-indicator">
            <i class="fas fa-sync-alt me-2"></i>
            Auto-refresh: <span id="countdown">30</span>s
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh functionality
        let countdown = 30;
        const countdownElement = document.getElementById('countdown');
        
        function updateCountdown() {
            countdown--;
            countdownElement.textContent = countdown;
            
            if (countdown <= 0) {
                location.reload();
            }
        }
        
        // Update countdown every second
        setInterval(updateCountdown, 1000);
        
        // Export functionality
        function exportData() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            window.location.href = '?' + params.toString();
        }
        
        // View log details
        function viewLog(logId) {
            // Implementation for viewing log details
            alert('View log details for ID: ' + logId);
        }
        
        // Complete log
        function completeLog(logId) {
            if (confirm('Mark this production log as completed?')) {
                // Implementation for completing log
                alert('Log marked as completed: ' + logId);
                location.reload();
            }
        }
        
        // Refresh page manually
        function refreshPage() {
            location.reload();
        }
    </script>
</body>
</html>
