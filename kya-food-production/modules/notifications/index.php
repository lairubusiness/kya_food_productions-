<?php
/**
 * KYA Food Production - Notifications Management
 * Real-time notifications and alerts system
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

// Create notifications table if it doesn't exist
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type ENUM('info', 'warning', 'error', 'success') DEFAULT 'info',
            category ENUM('inventory', 'quality', 'production', 'system', 'alert') DEFAULT 'system',
            priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            is_read BOOLEAN DEFAULT FALSE,
            is_archived BOOLEAN DEFAULT FALSE,
            related_id INT NULL,
            related_type VARCHAR(50) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            read_at TIMESTAMP NULL,
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at),
            INDEX idx_is_read (is_read),
            INDEX idx_type (type),
            INDEX idx_category (category)
        )
    ");
} catch (Exception $e) {
    error_log("Notifications table creation error: " . $e->getMessage());
}

// Handle notification actions
if ($_POST['action'] ?? false) {
    $action = $_POST['action'];
    $notificationId = $_POST['notification_id'] ?? 0;
    
    try {
        switch ($action) {
            case 'mark_read':
                $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
                $stmt->execute([$notificationId, $userInfo['id']]);
                $success_message = "Notification marked as read.";
                break;
                
            case 'mark_unread':
                $stmt = $conn->prepare("UPDATE notifications SET is_read = 0, read_at = NULL WHERE id = ? AND user_id = ?");
                $stmt->execute([$notificationId, $userInfo['id']]);
                $success_message = "Notification marked as unread.";
                break;
                
            case 'archive':
                $stmt = $conn->prepare("UPDATE notifications SET is_archived = 1 WHERE id = ? AND user_id = ?");
                $stmt->execute([$notificationId, $userInfo['id']]);
                $success_message = "Notification archived.";
                break;
                
            case 'delete':
                $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
                $stmt->execute([$notificationId, $userInfo['id']]);
                $success_message = "Notification deleted.";
                break;
                
            case 'mark_all_read':
                $stmt = $conn->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
                $stmt->execute([$userInfo['id']]);
                $success_message = "All notifications marked as read.";
                break;
        }
    } catch (Exception $e) {
        error_log("Notification action error: " . $e->getMessage());
        $error_message = "An error occurred while processing the request.";
    }
}

// Generate sample notifications if none exist (for demo purposes)
try {
    $checkNotifications = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ?");
    $checkNotifications->execute([$userInfo['id']]);
    $notificationCount = $checkNotifications->fetch()['count'];
    
    if ($notificationCount == 0) {
        // Insert sample notifications
        $sampleNotifications = [
            [
                'title' => 'Low Stock Alert',
                'message' => 'Raw material "Wheat Flour" is running low. Current stock: 15 kg. Reorder level: 50 kg.',
                'type' => 'warning',
                'category' => 'inventory',
                'priority' => 'high'
            ],
            [
                'title' => 'Quality Inspection Due',
                'message' => 'Batch #QC-2024-001 requires quality inspection. Due date: Today.',
                'type' => 'info',
                'category' => 'quality',
                'priority' => 'medium'
            ],
            [
                'title' => 'Expiry Alert',
                'message' => 'Item "Milk Powder" expires in 3 days. Please take necessary action.',
                'type' => 'error',
                'category' => 'inventory',
                'priority' => 'urgent'
            ],
            [
                'title' => 'Production Target Met',
                'message' => 'Section 2 has successfully met today\'s production target of 500 units.',
                'type' => 'success',
                'category' => 'production',
                'priority' => 'low'
            ],
            [
                'title' => 'System Maintenance',
                'message' => 'Scheduled system maintenance will occur tonight from 2:00 AM to 4:00 AM.',
                'type' => 'info',
                'category' => 'system',
                'priority' => 'medium'
            ]
        ];
        
        $insertStmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, type, category, priority) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($sampleNotifications as $notification) {
            $insertStmt->execute([
                $userInfo['id'],
                $notification['title'],
                $notification['message'],
                $notification['type'],
                $notification['category'],
                $notification['priority']
            ]);
        }
    }
} catch (Exception $e) {
    error_log("Sample notifications error: " . $e->getMessage());
}

// Get filter parameters
$category = $_GET['category'] ?? '';
$type = $_GET['type'] ?? '';
$priority = $_GET['priority'] ?? '';
$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

// Build query conditions
$whereConditions = ["user_id = ?"];
$params = [$userInfo['id']];

if ($category) {
    $whereConditions[] = "category = ?";
    $params[] = $category;
}

if ($type) {
    $whereConditions[] = "type = ?";
    $params[] = $type;
}

if ($priority) {
    $whereConditions[] = "priority = ?";
    $params[] = $priority;
}

if ($status) {
    switch ($status) {
        case 'unread':
            $whereConditions[] = "is_read = 0 AND is_archived = 0";
            break;
        case 'read':
            $whereConditions[] = "is_read = 1 AND is_archived = 0";
            break;
        case 'archived':
            $whereConditions[] = "is_archived = 1";
            break;
    }
} else {
    $whereConditions[] = "is_archived = 0";
}

if ($search) {
    $whereConditions[] = "(title LIKE ? OR message LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// Get notifications
try {
    $stmt = $conn->prepare("
        SELECT *
        FROM notifications
        $whereClause
        ORDER BY 
            CASE WHEN priority = 'urgent' THEN 1 
                 WHEN priority = 'high' THEN 2 
                 WHEN priority = 'medium' THEN 3 
                 ELSE 4 END,
            is_read ASC,
            created_at DESC
        LIMIT 50
    ");
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();
    
    // Get summary statistics
    $summaryStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_notifications,
            COUNT(CASE WHEN is_read = 0 THEN 1 END) as unread_notifications,
            COUNT(CASE WHEN priority = 'urgent' THEN 1 END) as urgent_notifications,
            COUNT(CASE WHEN type = 'error' THEN 1 END) as error_notifications,
            COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as recent_notifications
        FROM notifications 
        WHERE user_id = ? AND is_archived = 0
    ");
    $summaryStmt->execute([$userInfo['id']]);
    $summary = $summaryStmt->fetch();
    
} catch (Exception $e) {
    error_log("Notifications query error: " . $e->getMessage());
    $notifications = [];
    $summary = ['total_notifications' => 0, 'unread_notifications' => 0, 'urgent_notifications' => 0, 'error_notifications' => 0, 'recent_notifications' => 0];
}

$pageTitle = 'Notifications';
include '../../includes/header.php';
?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-bell text-primary me-2"></i>
                Notifications
            </h1>
            <p class="text-muted mb-0">Manage your alerts and notifications</p>
        </div>
        <div>
            <button type="button" class="btn btn-outline-success" onclick="markAllRead()">
                <i class="fas fa-check-double me-2"></i>Mark All Read
            </button>
            <button type="button" class="btn btn-outline-primary" onclick="location.reload()">
                <i class="fas fa-sync-alt me-2"></i>Refresh
            </button>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card">
                <div class="stats-icon primary">
                    <i class="fas fa-bell"></i>
                </div>
                <div class="stats-number"><?php echo number_format($summary['total_notifications']); ?></div>
                <div class="stats-label">Total</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card warning">
                <div class="stats-icon warning">
                    <i class="fas fa-envelope"></i>
                </div>
                <div class="stats-number"><?php echo number_format($summary['unread_notifications']); ?></div>
                <div class="stats-label">Unread</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card danger">
                <div class="stats-icon danger">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stats-number"><?php echo number_format($summary['urgent_notifications']); ?></div>
                <div class="stats-label">Urgent</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card secondary">
                <div class="stats-icon secondary">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div class="stats-number"><?php echo number_format($summary['error_notifications']); ?></div>
                <div class="stats-label">Errors</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card info">
                <div class="stats-icon info">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-number"><?php echo number_format($summary['recent_notifications']); ?></div>
                <div class="stats-label">Recent (24h)</div>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card success">
                <div class="stats-icon success">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stats-number">
                    <?php echo $summary['total_notifications'] > 0 ? round((($summary['total_notifications'] - $summary['unread_notifications']) / $summary['total_notifications']) * 100, 1) : 0; ?>%
                </div>
                <div class="stats-label">Read Rate</div>
            </div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label for="category" class="form-label">Category</label>
                    <select name="category" id="category" class="form-select">
                        <option value="">All Categories</option>
                        <option value="inventory" <?php echo $category == 'inventory' ? 'selected' : ''; ?>>Inventory</option>
                        <option value="quality" <?php echo $category == 'quality' ? 'selected' : ''; ?>>Quality</option>
                        <option value="production" <?php echo $category == 'production' ? 'selected' : ''; ?>>Production</option>
                        <option value="system" <?php echo $category == 'system' ? 'selected' : ''; ?>>System</option>
                        <option value="alert" <?php echo $category == 'alert' ? 'selected' : ''; ?>>Alert</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="type" class="form-label">Type</label>
                    <select name="type" id="type" class="form-select">
                        <option value="">All Types</option>
                        <option value="info" <?php echo $type == 'info' ? 'selected' : ''; ?>>Info</option>
                        <option value="warning" <?php echo $type == 'warning' ? 'selected' : ''; ?>>Warning</option>
                        <option value="error" <?php echo $type == 'error' ? 'selected' : ''; ?>>Error</option>
                        <option value="success" <?php echo $type == 'success' ? 'selected' : ''; ?>>Success</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="priority" class="form-label">Priority</label>
                    <select name="priority" id="priority" class="form-select">
                        <option value="">All Priorities</option>
                        <option value="urgent" <?php echo $priority == 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                        <option value="high" <?php echo $priority == 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="medium" <?php echo $priority == 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="low" <?php echo $priority == 'low' ? 'selected' : ''; ?>>Low</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="unread" <?php echo $status == 'unread' ? 'selected' : ''; ?>>Unread</option>
                        <option value="read" <?php echo $status == 'read' ? 'selected' : ''; ?>>Read</option>
                        <option value="archived" <?php echo $status == 'archived' ? 'selected' : ''; ?>>Archived</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" name="search" id="search" class="form-control" 
                           placeholder="Search notifications..." 
                           value="<?php echo htmlspecialchars($search); ?>">
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
    
    <!-- Notifications List -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Notifications
                <span class="badge bg-primary ms-2"><?php echo count($notifications); ?></span>
            </h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($notifications)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-bell-slash text-muted" style="font-size: 3rem;"></i>
                    <h4 class="mt-3">No Notifications</h4>
                    <p class="text-muted">You're all caught up! No notifications match your current filters.</p>
                </div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($notifications as $notification): ?>
                        <div class="list-group-item <?php echo !$notification['is_read'] ? 'list-group-item-light border-start border-primary border-3' : ''; ?>">
                            <div class="d-flex w-100 justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center mb-2">
                                        <div class="me-3">
                                            <?php
                                            $iconClass = 'fas fa-info-circle text-info';
                                            switch ($notification['type']) {
                                                case 'warning':
                                                    $iconClass = 'fas fa-exclamation-triangle text-warning';
                                                    break;
                                                case 'error':
                                                    $iconClass = 'fas fa-times-circle text-danger';
                                                    break;
                                                case 'success':
                                                    $iconClass = 'fas fa-check-circle text-success';
                                                    break;
                                            }
                                            ?>
                                            <i class="<?php echo $iconClass; ?> fa-lg"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1 <?php echo !$notification['is_read'] ? 'fw-bold' : ''; ?>">
                                                <?php echo htmlspecialchars($notification['title']); ?>
                                            </h6>
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <span class="badge bg-<?php 
                                                    echo $notification['category'] == 'inventory' ? 'primary' : 
                                                        ($notification['category'] == 'quality' ? 'success' : 
                                                        ($notification['category'] == 'production' ? 'info' : 
                                                        ($notification['category'] == 'alert' ? 'warning' : 'secondary'))); 
                                                ?>">
                                                    <?php echo ucfirst($notification['category']); ?>
                                                </span>
                                                <span class="badge bg-<?php 
                                                    echo $notification['priority'] == 'urgent' ? 'danger' : 
                                                        ($notification['priority'] == 'high' ? 'warning' : 
                                                        ($notification['priority'] == 'medium' ? 'info' : 'secondary')); 
                                                ?>">
                                                    <?php echo ucfirst($notification['priority']); ?>
                                                </span>
                                                <?php if (!$notification['is_read']): ?>
                                                    <span class="badge bg-primary">New</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <p class="mb-2 text-muted">
                                        <?php echo htmlspecialchars($notification['message']); ?>
                                    </p>
                                    <small class="text-muted">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo formatDate($notification['created_at']); ?>
                                        <?php if ($notification['is_read'] && $notification['read_at']): ?>
                                            | Read: <?php echo formatDate($notification['read_at']); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <div class="ms-3">
                                    <div class="btn-group" role="group">
                                        <?php if (!$notification['is_read']): ?>
                                            <button type="button" class="btn btn-sm btn-outline-success" 
                                                    onclick="markRead(<?php echo $notification['id']; ?>)" title="Mark as Read">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                    onclick="markUnread(<?php echo $notification['id']; ?>)" title="Mark as Unread">
                                                <i class="fas fa-envelope"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline-warning" 
                                                onclick="archiveNotification(<?php echo $notification['id']; ?>)" title="Archive">
                                            <i class="fas fa-archive"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                onclick="deleteNotification(<?php echo $notification['id']; ?>)" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Mark notification as read
function markRead(notificationId) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="mark_read">
        <input type="hidden" name="notification_id" value="${notificationId}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// Mark notification as unread
function markUnread(notificationId) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="mark_unread">
        <input type="hidden" name="notification_id" value="${notificationId}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// Archive notification
function archiveNotification(notificationId) {
    if (confirm('Are you sure you want to archive this notification?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="archive">
            <input type="hidden" name="notification_id" value="${notificationId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Delete notification
function deleteNotification(notificationId) {
    if (confirm('Are you sure you want to delete this notification? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="notification_id" value="${notificationId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Mark all notifications as read
function markAllRead() {
    if (confirm('Are you sure you want to mark all notifications as read?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `<input type="hidden" name="action" value="mark_all_read">`;
        document.body.appendChild(form);
        form.submit();
    }
}

// Auto-refresh every 30 seconds
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 30000);

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 'r') {
        e.preventDefault();
        location.reload();
    }
});
</script>

<?php include '../../includes/footer.php'; ?>