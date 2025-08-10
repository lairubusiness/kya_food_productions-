<?php
/**
 * KYA Food Production - Account Unlock Script
 * Run this to unlock locked user accounts
 */

// Database configuration
$host = "localhost";
$username = "root";
$password = "";
$database = "kya_food_production";

try {
    // Connect to database
    $pdo = new PDO("mysql:host=$host;dbname=$database", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>KYA Food Production - Account Unlock</h2>";
    echo "<div style='font-family: Arial; padding: 20px;'>";
    
    // Reset all account locks and login attempts
    $stmt = $pdo->prepare("UPDATE users SET account_locked = 0, login_attempts = 0 WHERE account_locked = 1");
    $stmt->execute();
    $unlockedCount = $stmt->rowCount();
    
    // Also reset login attempts for all users
    $stmt = $pdo->prepare("UPDATE users SET login_attempts = 0");
    $stmt->execute();
    
    echo "✅ Successfully unlocked $unlockedCount locked accounts<br>";
    echo "✅ Reset login attempts for all users<br>";
    
    // Show current user status
    $stmt = $pdo->query("SELECT username, account_locked, login_attempts, is_active FROM users");
    $users = $stmt->fetchAll();
    
    echo "<br><h3>Current User Status:</h3>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #f0f0f0;'><th style='padding: 10px;'>Username</th><th style='padding: 10px;'>Account Locked</th><th style='padding: 10px;'>Login Attempts</th><th style='padding: 10px;'>Active</th></tr>";
    
    foreach ($users as $user) {
        $lockStatus = $user['account_locked'] ? '🔒 Locked' : '🔓 Unlocked';
        $activeStatus = $user['is_active'] ? '✅ Active' : '❌ Inactive';
        echo "<tr>";
        echo "<td style='padding: 10px;'><strong>{$user['username']}</strong></td>";
        echo "<td style='padding: 10px;'>{$lockStatus}</td>";
        echo "<td style='padding: 10px;'>{$user['login_attempts']}</td>";
        echo "<td style='padding: 10px;'>{$activeStatus}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<br><h3>🎉 All Accounts Unlocked!</h3>";
    echo "<p><strong>You can now login with these credentials:</strong></p>";
    echo "<ul>";
    echo "<li><strong>Admin:</strong> username = <code>admin</code>, password = <code>admin123</code></li>";
    echo "<li><strong>Section 1 Manager:</strong> username = <code>section1_mgr</code>, password = <code>admin123</code></li>";
    echo "<li><strong>Section 2 Manager:</strong> username = <code>section2_mgr</code>, password = <code>admin123</code></li>";
    echo "<li><strong>Section 3 Manager:</strong> username = <code>section3_mgr</code>, password = <code>admin123</code></li>";
    echo "</ul>";
    
    echo "<p><a href='index.php' style='background: #2c5f41; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🚀 Go to Login Page</a></p>";
    echo "<p><em>You can delete this unlock file after successful login.</em></p>";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<div style='color: red; font-family: Arial; padding: 20px;'>";
    echo "<h3>❌ Account Unlock Failed</h3>";
    echo "<p>Error: " . $e->getMessage() . "</p>";
    echo "</div>";
}
?>
