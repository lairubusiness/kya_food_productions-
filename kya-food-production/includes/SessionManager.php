<?php
/**
 * KYA Food Production - Session Management Class
 * Handles user authentication, authorization, and session management
 */

class SessionManager {
    
    /**
     * Check if user is logged in
     */
    public static function isLoggedIn() {
        return isset($_SESSION['user_id']) && isset($_SESSION['username']);
    }
    
    /**
     * Get current user ID
     */
    public static function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }
    
    /**
     * Get current username
     */
    public static function getUsername() {
        return $_SESSION['username'] ?? null;
    }
    
    /**
     * Get current user role
     */
    public static function getUserRole() {
        return $_SESSION['user_role'] ?? null;
    }
    
    /**
     * Get current user's full name
     */
    public static function getFullName() {
        return $_SESSION['full_name'] ?? null;
    }
    
    /**
     * Check if user can access a specific section
     * @param int $section Section number (1-7)
     * @return bool
     */
    public static function canAccessSection($section) {
        if (!self::isLoggedIn()) {
            return false;
        }
        
        $userRole = self::getUserRole();
        
        // Admin can access all sections
        if ($userRole === 'admin') {
            return true;
        }
        
        // Section-specific access based on role
        switch ($userRole) {
            case 'section1_manager':
                return in_array($section, [1, 4, 7]); // Section 1, Inventory, Reports
            case 'section2_manager':
                return in_array($section, [2, 4, 7]); // Section 2, Inventory, Reports
            case 'section3_manager':
                return in_array($section, [3, 4, 7]); // Section 3, Inventory, Reports
            default:
                return false;
        }
    }
    
    /**
     * Check if user has specific permission
     * @param string $permission Permission name
     * @return bool
     */
    public static function hasPermission($permission) {
        if (!self::isLoggedIn()) {
            return false;
        }
        
        $userRole = self::getUserRole();
        
        // Admin has all permissions
        if ($userRole === 'admin') {
            return true;
        }
        
        // Define role-based permissions
        $rolePermissions = [
            'section1_manager' => ['section_manage', 'inventory_manage', 'reports_view'],
            'section2_manager' => ['section_manage', 'inventory_manage', 'reports_view'],
            'section3_manager' => ['section_manage', 'inventory_manage', 'reports_view']
        ];
        
        return isset($rolePermissions[$userRole]) && in_array($permission, $rolePermissions[$userRole]);
    }
    
    /**
     * Get user's accessible sections
     * @return array
     */
    public static function getAccessibleSections() {
        if (!self::isLoggedIn()) {
            return [];
        }
        
        $userRole = self::getUserRole();
        
        switch ($userRole) {
            case 'admin':
                return [1, 2, 3, 4, 5, 6, 7]; // All sections
            case 'section1_manager':
                return [1, 4, 7]; // Section 1, Inventory, Reports
            case 'section2_manager':
                return [2, 4, 7]; // Section 2, Inventory, Reports
            case 'section3_manager':
                return [3, 4, 7]; // Section 3, Inventory, Reports
            default:
                return [];
        }
    }
    
    /**
     * Login user
     * @param array $userData User data from database
     */
    public static function login($userData) {
        $_SESSION['user_id'] = $userData['id'];
        $_SESSION['username'] = $userData['username'];
        $_SESSION['user_role'] = $userData['role'];
        $_SESSION['full_name'] = $userData['full_name'];
        $_SESSION['email'] = $userData['email'] ?? '';
        $_SESSION['phone'] = $userData['phone'] ?? '';
        $_SESSION['last_login'] = date('Y-m-d H:i:s');
        
        // Regenerate session ID for security
        session_regenerate_id(true);
    }
    
    /**
     * Logout user
     */
    public static function logout() {
        // Clear all session variables
        $_SESSION = array();
        
        // Destroy the session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        // Destroy the session
        session_destroy();
    }
    
    /**
     * Check session timeout
     * @param int $timeout Timeout in seconds (default: 1800 = 30 minutes)
     * @return bool True if session is valid, false if timed out
     */
    public static function checkTimeout($timeout = 1800) {
        if (!self::isLoggedIn()) {
            return false;
        }
        
        if (isset($_SESSION['last_activity'])) {
            if (time() - $_SESSION['last_activity'] > $timeout) {
                self::logout();
                return false;
            }
        }
        
        $_SESSION['last_activity'] = time();
        return true;
    }
    
    /**
     * Set flash message
     * @param string $type Message type (success, error, warning, info)
     * @param string $message Message content
     */
    public static function setFlashMessage($type, $message) {
        $_SESSION['flash_messages'][] = [
            'type' => $type,
            'message' => $message
        ];
    }
    
    /**
     * Get and clear flash messages
     * @return array
     */
    public static function getFlashMessages() {
        $messages = $_SESSION['flash_messages'] ?? [];
        unset($_SESSION['flash_messages']);
        return $messages;
    }
    
    /**
     * Check if user has flash messages
     * @return bool
     */
    public static function hasFlashMessages() {
        return !empty($_SESSION['flash_messages']);
    }
    
    /**
     * Get section name by number
     * @param int $section Section number
     * @return string
     */
    public static function getSectionName($section) {
        $sections = [
            1 => 'Raw Material Handling',
            2 => 'Dehydration Processing', 
            3 => 'Packaging & Storage',
            4 => 'Inventory Management',
            5 => 'Processing',
            6 => 'Orders',
            7 => 'Reports'
        ];
        
        return $sections[$section] ?? 'Unknown Section';
    }
    
    /**
     * Generate CSRF token
     * @return string
     */
    public static function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Verify CSRF token
     * @param string $token Token to verify
     * @return bool
     */
    public static function verifyCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}
?>
