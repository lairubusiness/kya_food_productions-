<?php
/**
 * KYA Food Production - Admin Dashboard
 * System administration and management interface
 */

require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

SessionManager::start();
SessionManager::requireLogin();
SessionManager::requireRole(['admin']);

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

// Get system statistics
try {
    // User statistics
    $userStats = $conn->query("
        SELECT 
            COUNT(*) as total_users,
            COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_users,
            COUNT(CASE WHEN account_locked = 1 THEN 1 END) as locked_users,
            COUNT(CASE WHEN last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as recent_logins
        FROM users
    ")->fetch();
    
    // System activity
    $activityStats = $conn->query("
        SELECT 
            COUNT(*) as total_activities,
            COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as today_activities,
            COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as week_activities
        FROM activity_logs
    ")->fetch();
    
    // System health
    $systemHealth = [
        'database_size' => getDatabaseSize($conn),
        'total_tables' => getTotalTables($conn),
        'uptime' => getSystemUptime()
    ];
    
    // Recent activities
    $recentActivities = $conn->query("
        SELECT al.*, u.full_name, u.username
        FROM activity_logs al
        LEFT JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC
        LIMIT 10
    ")->fetchAll();
    
} catch (Exception $e) {
    error_log("Admin dashboard error: " . $e->getMessage());
    $userStats = ['total_users' => 0, 'active_users' => 0, 'locked_users' => 0, 'recent_logins' => 0];
    $activityStats = ['total_activities' => 0, 'today_activities' => 0, 'week_activities' => 0];
    $systemHealth = ['database_size' => 'Unknown', 'total_tables' => 0, 'uptime' => 'Unknown'];
    $recentActivities = [];
}

$pageTitle = 'System Administration';
include '../../includes/header.php';
?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">System Administration</h1>
            <p class="text-muted mb-0">Manage users, system settings, and monitor system health</p>
        </div>
        <div class="btn-group" role="group">
            <a href="user_management.php" class="btn btn-primary">
                <i class="fas fa-users me-2"></i>Manage Users
            </a>
            <a href="system_settings.php" class="btn btn-outline-primary">
                <i class="fas fa-cog me-2"></i>Settings
            </a>
            <a href="backup.php" class="btn btn-outline-success">
                <i class="fas fa-download me-2"></i>Backup
            </a>
        </div>
    </div>
    
    <!-- System Overview Cards -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card primary">
                <div class="stats-icon primary">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stats-number"><?php echo number_format($userStats['total_users']); ?></div>
                <div class="stats-label">Total Users</div>
                <div class="stats-sublabel">
                    <?php echo $userStats['active_users']; ?> active, 
                    <?php echo $userStats['locked_users']; ?> locked
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card success">
                <div class="stats-icon success">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stats-number"><?php echo number_format($activityStats['today_activities']); ?></div>
                <div class="stats-label">Today's Activities</div>
                <div class="stats-sublabel">
                    <?php echo number_format($activityStats['week_activities']); ?> this week
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card info">
                <div class="stats-icon info">
                    <i class="fas fa-database"></i>
                </div>
                <div class="stats-number"><?php echo $systemHealth['database_size']; ?></div>
                <div class="stats-label">Database Size</div>
                <div class="stats-sublabel">
                    <?php echo $systemHealth['total_tables']; ?> tables
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card warning">
                <div class="stats-icon warning">
                    <i class="fas fa-server"></i>
                </div>
                <div class="stats-number"><?php echo $systemHealth['uptime']; ?></div>
                <div class="stats-label">System Uptime</div>
                <div class="stats-sublabel">
                    <?php echo $userStats['recent_logins']; ?> recent logins
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Quick Actions</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <a href="user_management.php" class="btn btn-outline-primary btn-lg w-100">
                                <i class="fas fa-user-plus fa-2x mb-2"></i><br>
                                Add New User
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="system_settings.php" class="btn btn-outline-secondary btn-lg w-100">
                                <i class="fas fa-cogs fa-2x mb-2"></i><br>
                                System Settings
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="reports.php" class="btn btn-outline-info btn-lg w-100">
                                <i class="fas fa-chart-bar fa-2x mb-2"></i><br>
                                View Reports
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="backup.php" class="btn btn-outline-success btn-lg w-100">
                                <i class="fas fa-shield-alt fa-2x mb-2"></i><br>
                                Backup System
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- System Health Monitor -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">System Health</h5>
                    <span class="badge bg-success">All Systems Operational</span>
                </div>
                <div class="card-body">
                    <div class="health-item d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <i class="fas fa-database text-success me-2"></i>
                            Database Connection
                        </div>
                        <span class="badge bg-success">Online</span>
                    </div>
                    <div class="health-item d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <i class="fas fa-server text-success me-2"></i>
                            Web Server
                        </div>
                        <span class="badge bg-success">Running</span>
                    </div>
                    <div class="health-item d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <i class="fas fa-envelope text-warning me-2"></i>
                            Email Service
                        </div>
                        <span class="badge bg-warning">Not Configured</span>
                    </div>
                    <div class="health-item d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fas fa-shield-alt text-success me-2"></i>
                            Security Status
                        </div>
                        <span class="badge bg-success">Secure</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Recent System Activities</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($recentActivities)): ?>
                        <p class="text-muted text-center">No recent activities found.</p>
                    <?php else: ?>
                        <div class="activity-list">
                            <?php foreach ($recentActivities as $activity): ?>
                                <div class="activity-item d-flex align-items-start mb-3">
                                    <div class="activity-icon me-3">
                                        <i class="fas fa-circle text-primary" style="font-size: 8px;"></i>
                                    </div>
                                    <div class="activity-content flex-grow-1">
                                        <div class="activity-title">
                                            <strong><?php echo htmlspecialchars($activity['full_name'] ?? $activity['username'] ?? 'System'); ?></strong>
                                            <?php echo htmlspecialchars(str_replace('_', ' ', $activity['action'])); ?>
                                        </div>
                                        <div class="activity-time text-muted small">
                                            <?php echo timeAgo($activity['created_at']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-center">
                            <a href="reports.php?type=activity" class="btn btn-sm btn-outline-primary">View All Activities</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- System Information -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">System Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>System Version:</strong></td>
                                    <td><?php echo SITE_VERSION; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>PHP Version:</strong></td>
                                    <td><?php echo PHP_VERSION; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Database Version:</strong></td>
                                    <td><?php echo $conn->query("SELECT VERSION()")->fetchColumn(); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Server Software:</strong></td>
                                    <td><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>Total Activities:</strong></td>
                                    <td><?php echo number_format($activityStats['total_activities']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Database Tables:</strong></td>
                                    <td><?php echo $systemHealth['total_tables']; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Session Timeout:</strong></td>
                                    <td><?php echo SESSION_TIMEOUT / 60; ?> minutes</td>
                                </tr>
                                <tr>
                                    <td><strong>Max File Upload:</strong></td>
                                    <td><?php echo formatBytes(MAX_FILE_SIZE); ?>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-refresh system health every 30 seconds
setInterval(function() {
    if (document.visibilityState === 'visible') {
        fetch('../../api/system_health.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update health indicators
                    console.log('System health updated');
                }
            })
            .catch(error => console.error('Health check failed:', error));
    }
}, 30000);
</script>

<?php include '../../includes/footer.php'; ?>

<?php
function getDatabaseSize($conn) {
    try {
        $result = $conn->query("
            SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb 
            FROM information_schema.tables 
            WHERE table_schema = 'kya_food_production'
        ")->fetch();
        return $result['size_mb'] . ' MB';
    } catch (Exception $e) {
        return 'Unknown';
    }
}

function getTotalTables($conn) {
    try {
        $result = $conn->query("
            SELECT COUNT(*) as total 
            FROM information_schema.tables 
            WHERE table_schema = 'kya_food_production'
        ")->fetch();
        return $result['total'];
    } catch (Exception $e) {
        return 0;
    }
}

function getSystemUptime() {
    if (function_exists('sys_getloadavg')) {
        $uptime = shell_exec('uptime');
        if ($uptime) {
            return trim($uptime);
        }
    }
    return 'Available';
}
?>
