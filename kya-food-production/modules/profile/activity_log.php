<?php
/**
 * KYA Food Production - User Activity Log
 * Comprehensive activity tracking and monitoring for user actions
 */

require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

SessionManager::start();
SessionManager::requireLogin();

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

// Get filter parameters
$action_filter = $_GET['action'] ?? '';
$table_filter = $_GET['table'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

// Build query conditions
$conditions = ['user_id = ?'];
$params = [$userInfo['id']];

if (!empty($action_filter)) {
    $conditions[] = "action LIKE ?";
    $params[] = '%' . $action_filter . '%';
}

if (!empty($table_filter)) {
    $conditions[] = "table_name = ?";
    $params[] = $table_filter;
}

if (!empty($date_from)) {
    $conditions[] = "DATE(created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $conditions[] = "DATE(created_at) <= ?";
    $params[] = $date_to;
}

if (!empty($search)) {
    $conditions[] = "(action LIKE ? OR table_name LIKE ? OR details LIKE ?)";
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = 'WHERE ' . implode(' AND ', $conditions);

// Get activity statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(*) as total_activities,
        COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today_activities,
        COUNT(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as week_activities,
        COUNT(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as month_activities,
        COUNT(DISTINCT table_name) as tables_accessed,
        COUNT(DISTINCT DATE(created_at)) as active_days
    FROM activity_logs 
    $whereClause
");
$stats->execute($params);
$statistics = $stats->fetch(PDO::FETCH_ASSOC);

// Get activity breakdown by action type
$actionBreakdown = $conn->prepare("
    SELECT 
        CASE 
            WHEN action LIKE '%login%' OR action LIKE '%logout%' THEN 'Authentication'
            WHEN action LIKE '%create%' OR action LIKE '%add%' THEN 'Create'
            WHEN action LIKE '%update%' OR action LIKE '%edit%' OR action LIKE '%modify%' THEN 'Update'
            WHEN action LIKE '%delete%' OR action LIKE '%remove%' THEN 'Delete'
            WHEN action LIKE '%view%' OR action LIKE '%access%' THEN 'View'
            ELSE 'Other'
        END as action_type,
        COUNT(*) as count
    FROM activity_logs 
    $whereClause
    GROUP BY action_type
    ORDER BY count DESC
");
$actionBreakdown->execute($params);
$actionStats = $actionBreakdown->fetchAll(PDO::FETCH_ASSOC);

// Get recent activities
$activities = $conn->prepare("
    SELECT 
        al.*,
        CASE 
            WHEN al.table_name = 'inventory' THEN 'Inventory Management'
            WHEN al.table_name = 'processing_logs' THEN 'Processing Operations'
            WHEN al.table_name = 'users' THEN 'User Management'
            WHEN al.table_name = 'suppliers' THEN 'Supplier Management'
            ELSE CONCAT(UPPER(SUBSTRING(al.table_name, 1, 1)), SUBSTRING(al.table_name, 2))
        END as module_name
    FROM activity_logs al
    $whereClause
    ORDER BY al.created_at DESC
    LIMIT 100
");
$activities->execute($params);
$activityList = $activities->fetchAll(PDO::FETCH_ASSOC);

// Get unique tables for filter
$tables = $conn->prepare("
    SELECT DISTINCT table_name 
    FROM activity_logs 
    WHERE user_id = ? 
    ORDER BY table_name
");
$tables->execute([$userInfo['id']]);
$tableList = $tables->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Activity Log - ' . htmlspecialchars($userInfo['username']);
include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Activity Log</h1>
            <p class="text-muted">Track your system activities and interactions</p>
        </div>
        <div>
            <a href="profile.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Profile
            </a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="card bg-primary text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h6 class="card-title">Total Activities</h6>
                            <h4 class="mb-0"><?php echo number_format($statistics['total_activities'] ?? 0); ?></h4>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-chart-line fa-2x opacity-75"></i>
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
                            <h6 class="card-title">Today</h6>
                            <h4 class="mb-0"><?php echo number_format($statistics['today_activities'] ?? 0); ?></h4>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-calendar-day fa-2x opacity-75"></i>
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
                            <h6 class="card-title">This Week</h6>
                            <h4 class="mb-0"><?php echo number_format($statistics['week_activities'] ?? 0); ?></h4>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-calendar-week fa-2x opacity-75"></i>
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
                            <h6 class="card-title">This Month</h6>
                            <h4 class="mb-0"><?php echo number_format($statistics['month_activities'] ?? 0); ?></h4>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-calendar-alt fa-2x opacity-75"></i>
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
                            <h6 class="card-title">Modules Used</h6>
                            <h4 class="mb-0"><?php echo number_format($statistics['tables_accessed'] ?? 0); ?></h4>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-cubes fa-2x opacity-75"></i>
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
                            <h6 class="card-title">Active Days</h6>
                            <h4 class="mb-0"><?php echo number_format($statistics['active_days'] ?? 0); ?></h4>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-user-clock fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Action Type Breakdown -->
    <?php if (!empty($actionStats)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-pie text-primary me-2"></i>Activity Breakdown
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($actionStats as $stat): ?>
                            <div class="col-md-2 col-sm-4 col-6 mb-3">
                                <div class="text-center">
                                    <div class="h4 mb-1"><?php echo number_format($stat['count']); ?></div>
                                    <div class="text-muted small"><?php echo htmlspecialchars($stat['action_type']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label for="action_filter" class="form-label">Action Type</label>
                    <select name="action" id="action_filter" class="form-select">
                        <option value="">All Actions</option>
                        <option value="login" <?php echo $action_filter == 'login' ? 'selected' : ''; ?>>Login</option>
                        <option value="create" <?php echo $action_filter == 'create' ? 'selected' : ''; ?>>Create</option>
                        <option value="update" <?php echo $action_filter == 'update' ? 'selected' : ''; ?>>Update</option>
                        <option value="delete" <?php echo $action_filter == 'delete' ? 'selected' : ''; ?>>Delete</option>
                        <option value="view" <?php echo $action_filter == 'view' ? 'selected' : ''; ?>>View</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="table_filter" class="form-label">Module</label>
                    <select name="table" id="table_filter" class="form-select">
                        <option value="">All Modules</option>
                        <?php foreach ($tableList as $table): ?>
                            <option value="<?php echo htmlspecialchars($table); ?>" <?php echo $table_filter == $table ? 'selected' : ''; ?>>
                                <?php echo ucfirst(str_replace('_', ' ', $table)); ?>
                            </option>
                        <?php endforeach; ?>
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
                           placeholder="Action, module, details..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i>Filter
                        </button>
                        <a href="activity_log.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-2"></i>Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Activity Log Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-history text-info me-2"></i>Recent Activities
            </h5>
            <span class="badge bg-primary"><?php echo count($activityList); ?> activities</span>
        </div>
        <div class="card-body">
            <?php if (empty($activityList)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                    <h6 class="text-muted">No Activities Found</h6>
                    <p class="text-muted">No activities match your current filter criteria.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Date & Time</th>
                                <th>Action</th>
                                <th>Module</th>
                                <th>Details</th>
                                <th>IP Address</th>
                                <th>User Agent</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($activityList as $activity): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <strong><?php echo date('M j, Y', strtotime($activity['created_at'])); ?></strong>
                                            <small class="text-muted"><?php echo date('g:i A', strtotime($activity['created_at'])); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            if (strpos($activity['action'], 'login') !== false) echo 'success';
                                            elseif (strpos($activity['action'], 'create') !== false || strpos($activity['action'], 'add') !== false) echo 'primary';
                                            elseif (strpos($activity['action'], 'update') !== false || strpos($activity['action'], 'edit') !== false) echo 'warning text-dark';
                                            elseif (strpos($activity['action'], 'delete') !== false || strpos($activity['action'], 'remove') !== false) echo 'danger';
                                            else echo 'secondary';
                                        ?>">
                                            <?php echo htmlspecialchars($activity['action']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo htmlspecialchars($activity['module_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="text-truncate" style="max-width: 300px;" title="<?php echo htmlspecialchars($activity['details'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($activity['details'] ?? 'No details available'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?php echo htmlspecialchars($activity['ip_address'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td>
                                        <div class="text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($activity['user_agent'] ?? ''); ?>">
                                            <small class="text-muted"><?php echo htmlspecialchars($activity['user_agent'] ?? 'N/A'); ?></small>
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

<style>
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

.text-truncate {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>

<?php include '../../includes/footer.php'; ?>
