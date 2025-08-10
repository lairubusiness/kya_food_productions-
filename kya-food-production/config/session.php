<?php
/**
 * KYA Food Production - Enhanced Session Management
 * Handles user sessions, authentication, and security
 */

class SessionManager {
    
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) {
            // Configure session settings
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
            ini_set('session.use_strict_mode', 1);
            ini_set('session.cookie_samesite', 'Strict');
            
            session_start();
            
            // Regenerate session ID periodically
            if (!isset($_SESSION['last_regeneration'])) {
                $_SESSION['last_regeneration'] = time();
            } else if (time() - $_SESSION['last_regeneration'] > 300) { // 5 minutes
                session_regenerate_id(true);
                $_SESSION['last_regeneration'] = time();
            }
            
            // Check session timeout
            self::checkTimeout();
        }
    }
    
    public static function checkTimeout() {
        if (isset($_SESSION['last_activity'])) {
            if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
                self::destroy();
                header('Location: login.php?timeout=1');
                exit();
            }
        }
        $_SESSION['last_activity'] = time();
    }
    
    public static function isLoggedIn() {
        return isset($_SESSION['user_id']) && isset($_SESSION['username']) && isset($_SESSION['role']);
    }
    
    public static function requireLogin() {
        if (!self::isLoggedIn()) {
            header('Location: login.php');
            exit();
        }
    }
    
    public static function requireRole($allowedRoles) {
        self::requireLogin();
        
        if (!in_array($_SESSION['role'], $allowedRoles)) {
            header('Location: dashboard.php?error=access_denied');
            exit();
        }
    }
    
    public static function requireSection($section) {
        self::requireLogin();
        
        $userSections = USER_ROLES[$_SESSION['role']]['sections'] ?? [];
        
        if ($_SESSION['role'] !== 'admin' && !in_array($section, $userSections)) {
            header('Location: dashboard.php?error=section_access_denied');
            exit();
        }
    }
    
    public static function destroy() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            
            session_destroy();
        }
    }
    
    public static function setFlashMessage($type, $message) {
        $_SESSION['flash_messages'][] = ['type' => $type, 'message' => $message];
    }
    
    public static function getFlashMessages() {
        $messages = $_SESSION['flash_messages'] ?? [];
        unset($_SESSION['flash_messages']);
        return $messages;
    }
    
    public static function getUserInfo() {
        if (!self::isLoggedIn()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role'],
            'full_name' => $_SESSION['full_name'] ?? '',
            'email' => $_SESSION['email'] ?? '',
            'sections' => USER_ROLES[$_SESSION['role']]['sections'] ?? [],
            'permissions' => USER_ROLES[$_SESSION['role']]['permissions'] ?? []
        ];
    }
    
    public static function hasPermission($permission) {
        $userInfo = self::getUserInfo();
        if (!$userInfo) return false;
        
        return in_array('all', $userInfo['permissions']) || in_array($permission, $userInfo['permissions']);
    }
    
    public static function canAccessSection($section) {
        $userInfo = self::getUserInfo();
        if (!$userInfo) return false;
        
        return $_SESSION['role'] === 'admin' || in_array($section, $userInfo['sections']);
    }
    
    public static function logActivity($action, $table_name = null, $record_id = null, $old_values = null, $new_values = null) {
        if (!self::isLoggedIn()) return;
        
        try {
            require_once __DIR__ . '/database.php';
            $db = new Database();
            $conn = $db->connect();
            
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, session_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $_SESSION['user_id'],
                $action,
                $table_name,
                $record_id,
                $old_values ? json_encode($old_values) : null,
                $new_values ? json_encode($new_values) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                session_id()
            ]);
        } catch (Exception $e) {
            error_log("Failed to log activity: " . $e->getMessage());
        }
    }
}
?>
