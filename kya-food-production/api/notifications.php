<?php
/**
 * KYA Food Production - Notifications API
 * Handles notification-related API requests
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/functions.php';

// Start session and check authentication
SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userInfo = SessionManager::getUserInfo();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $db = new Database();
    $conn = $db->connect();
    
    switch ($method) {
        case 'GET':
            handleGetRequest($conn, $action, $userInfo);
            break;
            
        case 'POST':
            handlePostRequest($conn, $action, $userInfo);
            break;
            
        case 'PUT':
            handlePutRequest($conn, $action, $userInfo);
            break;
            
        case 'DELETE':
            handleDeleteRequest($conn, $action, $userInfo);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    error_log("Notifications API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}

function handleGetRequest($conn, $action, $userInfo) {
    switch ($action) {
        case 'get_unread_count':
            getUnreadCount($conn, $userInfo);
            break;
            
        case 'get_recent':
            getRecentNotifications($conn, $userInfo);
            break;
            
        case 'get_all':
            getAllNotifications($conn, $userInfo);
            break;
            
        case 'get_by_id':
            getNotificationById($conn, $_GET['id'] ?? 0, $userInfo);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function handlePostRequest($conn, $action, $userInfo) {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    
    switch ($action) {
        case 'mark_read':
            markAsRead($conn, $input['notification_id'] ?? 0, $userInfo);
            break;
            
        case 'mark_all_read':
            markAllAsRead($conn, $userInfo);
            break;
            
        case 'create':
            createNotification($conn, $input, $userInfo);
            break;
            
        case 'archive':
            archiveNotification($conn, $input['notification_id'] ?? 0, $userInfo);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function handlePutRequest($conn, $action, $userInfo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'update':
            updateNotification($conn, $input, $userInfo);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function handleDeleteRequest($conn, $action, $userInfo) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'delete':
            deleteNotification($conn, $input['notification_id'] ?? 0, $userInfo);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function getUnreadCount($conn, $userInfo) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM notifications 
        WHERE (user_id = ? OR user_id IS NULL) 
        AND is_read = FALSE 
        AND (expires_at IS NULL OR expires_at > NOW())
    ");
    $stmt->execute([$userInfo['id']]);
    $result = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'count' => (int)$result['count']
    ]);
}

function getRecentNotifications($conn, $userInfo) {
    $limit = $_GET['limit'] ?? 10;
    
    $stmt = $conn->prepare("
        SELECT id, notification_code, priority, type, category, title, message, 
               action_required, action_url, data, is_read, created_at
        FROM notifications 
        WHERE (user_id = ? OR user_id IS NULL) 
        AND is_archived = FALSE
        AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY created_at DESC 
        LIMIT ?
    ");
    $stmt->execute([$userInfo['id'], (int)$limit]);
    $notifications = $stmt->fetchAll();
    
    // Format data for each notification
    foreach ($notifications as &$notification) {
        $notification['data'] = $notification['data'] ? json_decode($notification['data'], true) : null;
        $notification['is_read'] = (bool)$notification['is_read'];
        $notification['action_required'] = (bool)$notification['action_required'];
        $notification['created_at_formatted'] = timeAgo($notification['created_at']);
    }
    
    echo json_encode([
        'success' => true,
        'notifications' => $notifications
    ]);
}

function getAllNotifications($conn, $userInfo) {
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 25);
    $offset = ($page - 1) * $limit;
    
    $filter = $_GET['filter'] ?? 'all'; // all, unread, read, archived
    $type = $_GET['type'] ?? '';
    $priority = $_GET['priority'] ?? '';
    
    $whereConditions = ["(user_id = ? OR user_id IS NULL)"];
    $params = [$userInfo['id']];
    
    switch ($filter) {
        case 'unread':
            $whereConditions[] = "is_read = FALSE";
            break;
        case 'read':
            $whereConditions[] = "is_read = TRUE";
            break;
        case 'archived':
            $whereConditions[] = "is_archived = TRUE";
            break;
        default:
            $whereConditions[] = "is_archived = FALSE";
    }
    
    if ($type) {
        $whereConditions[] = "type = ?";
        $params[] = $type;
    }
    
    if ($priority) {
        $whereConditions[] = "priority = ?";
        $params[] = $priority;
    }
    
    $whereClause = "WHERE " . implode(" AND ", $whereConditions);
    
    // Get total count
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM notifications $whereClause");
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch()['total'];
    
    // Get notifications
    $stmt = $conn->prepare("
        SELECT id, notification_code, priority, type, category, title, message, 
               action_required, action_url, data, is_read, is_archived, created_at
        FROM notifications 
        $whereClause
        ORDER BY created_at DESC 
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();
    
    // Format data
    foreach ($notifications as &$notification) {
        $notification['data'] = $notification['data'] ? json_decode($notification['data'], true) : null;
        $notification['is_read'] = (bool)$notification['is_read'];
        $notification['is_archived'] = (bool)$notification['is_archived'];
        $notification['action_required'] = (bool)$notification['action_required'];
        $notification['created_at_formatted'] = timeAgo($notification['created_at']);
    }
    
    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($totalCount / $limit),
            'total_count' => (int)$totalCount,
            'per_page' => $limit
        ]
    ]);
}

function getNotificationById($conn, $id, $userInfo) {
    $stmt = $conn->prepare("
        SELECT id, notification_code, user_id, section, priority, type, category, 
               title, message, action_required, action_url, data, is_read, 
               is_archived, read_at, expires_at, created_at
        FROM notifications 
        WHERE id = ? AND (user_id = ? OR user_id IS NULL)
    ");
    $stmt->execute([$id, $userInfo['id']]);
    $notification = $stmt->fetch();
    
    if (!$notification) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Notification not found']);
        return;
    }
    
    $notification['data'] = $notification['data'] ? json_decode($notification['data'], true) : null;
    $notification['is_read'] = (bool)$notification['is_read'];
    $notification['is_archived'] = (bool)$notification['is_archived'];
    $notification['action_required'] = (bool)$notification['action_required'];
    
    echo json_encode([
        'success' => true,
        'notification' => $notification
    ]);
}

function markAsRead($conn, $notificationId, $userInfo) {
    $stmt = $conn->prepare("
        UPDATE notifications 
        SET is_read = TRUE, read_at = NOW() 
        WHERE id = ? AND (user_id = ? OR user_id IS NULL)
    ");
    $stmt->execute([$notificationId, $userInfo['id']]);
    
    if ($stmt->rowCount() > 0) {
        SessionManager::logActivity('notification_read', "Notification ID: $notificationId");
        echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Notification not found']);
    }
}

function markAllAsRead($conn, $userInfo) {
    $stmt = $conn->prepare("
        UPDATE notifications 
        SET is_read = TRUE, read_at = NOW() 
        WHERE (user_id = ? OR user_id IS NULL) AND is_read = FALSE
    ");
    $stmt->execute([$userInfo['id']]);
    
    $count = $stmt->rowCount();
    SessionManager::logActivity('notifications_mark_all_read', "Marked $count notifications as read");
    
    echo json_encode([
        'success' => true, 
        'message' => "Marked $count notifications as read",
        'count' => $count
    ]);
}

function createNotification($conn, $input, $userInfo) {
    // Only admins can create notifications
    if ($userInfo['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        return;
    }
    
    $required = ['title', 'message', 'type', 'priority'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Field '$field' is required"]);
            return;
        }
    }
    
    $notificationCode = strtoupper($input['type']) . '_' . time() . '_' . rand(1000, 9999);
    
    $stmt = $conn->prepare("
        INSERT INTO notifications (notification_code, user_id, section, priority, type, 
                                 category, title, message, action_required, action_url, data)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $notificationCode,
        $input['user_id'] ?? null,
        $input['section'] ?? null,
        $input['priority'],
        $input['type'],
        $input['category'] ?? null,
        $input['title'],
        $input['message'],
        !empty($input['action_required']),
        $input['action_url'] ?? null,
        !empty($input['data']) ? json_encode($input['data']) : null
    ]);
    
    $notificationId = $conn->lastInsertId();
    
    SessionManager::logActivity('notification_created', "Notification ID: $notificationId");
    
    echo json_encode([
        'success' => true,
        'message' => 'Notification created successfully',
        'notification_id' => $notificationId
    ]);
}

function archiveNotification($conn, $notificationId, $userInfo) {
    $stmt = $conn->prepare("
        UPDATE notifications 
        SET is_archived = TRUE 
        WHERE id = ? AND (user_id = ? OR user_id IS NULL)
    ");
    $stmt->execute([$notificationId, $userInfo['id']]);
    
    if ($stmt->rowCount() > 0) {
        SessionManager::logActivity('notification_archived', "Notification ID: $notificationId");
        echo json_encode(['success' => true, 'message' => 'Notification archived']);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Notification not found']);
    }
}

function updateNotification($conn, $input, $userInfo) {
    // Only admins can update notifications
    if ($userInfo['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        return;
    }
    
    if (empty($input['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Notification ID is required']);
        return;
    }
    
    $updateFields = [];
    $params = [];
    
    $allowedFields = ['title', 'message', 'priority', 'type', 'category', 'action_required', 'action_url'];
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updateFields[] = "$field = ?";
            $params[] = $input[$field];
        }
    }
    
    if (empty($updateFields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No fields to update']);
        return;
    }
    
    $params[] = $input['id'];
    
    $stmt = $conn->prepare("
        UPDATE notifications 
        SET " . implode(', ', $updateFields) . "
        WHERE id = ?
    ");
    $stmt->execute($params);
    
    if ($stmt->rowCount() > 0) {
        SessionManager::logActivity('notification_updated', "Notification ID: {$input['id']}");
        echo json_encode(['success' => true, 'message' => 'Notification updated successfully']);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Notification not found']);
    }
}

function deleteNotification($conn, $notificationId, $userInfo) {
    // Only admins can delete notifications
    if ($userInfo['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        return;
    }
    
    $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ?");
    $stmt->execute([$notificationId]);
    
    if ($stmt->rowCount() > 0) {
        SessionManager::logActivity('notification_deleted', "Notification ID: $notificationId");
        echo json_encode(['success' => true, 'message' => 'Notification deleted successfully']);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Notification not found']);
    }
}
?>
