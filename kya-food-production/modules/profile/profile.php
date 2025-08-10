<?php
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Start session and check if user is logged in
SessionManager::start();
if (!SessionManager::isLoggedIn()) {
    header('Location: ../../auth/login.php');
    exit();
}

// Get user information
$user_info = SessionManager::getUserInfo();
$user_id = $user_info['id'];
$user_role = $user_info['role'];

// Initialize database connection
$database = new Database();
$db = $database->connect();

// Handle form submissions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_phone':
                $phone = trim($_POST['phone']);
                
                try {
                    $update_stmt = $db->prepare("UPDATE users SET phone = ?, updated_at = NOW() WHERE id = ?");
                    
                    if ($update_stmt->execute([$phone, $user_id])) {
                        $success_message = 'Phone number updated successfully!';
                        
                        // Log activity
                        SessionManager::logActivity("Updated phone number", "users", $user_id);
                    } else {
                        $error_message = 'Failed to update phone number. Please try again.';
                    }
                } catch (PDOException $e) {
                    $error_message = 'Database error: ' . $e->getMessage();
                }
                break;
                
            case 'change_password':
                $current_password = $_POST['current_password'];
                $new_password = $_POST['new_password'];
                $confirm_password = $_POST['confirm_password'];
                
                // Validate passwords
                if (strlen($new_password) < 8) {
                    $error_message = 'New password must be at least 8 characters long.';
                } elseif ($new_password !== $confirm_password) {
                    $error_message = 'New passwords do not match.';
                } else {
                    try {
                        // Verify current password
                        $verify_stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
                        $verify_stmt->execute([$user_id]);
                        $user_data = $verify_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($user_data && password_verify($current_password, $user_data['password'])) {
                            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                            $update_stmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                            
                            if ($update_stmt->execute([$hashed_password, $user_id])) {
                                $success_message = 'Password changed successfully!';
                                
                                // Log activity
                                SessionManager::logActivity("Changed password", "users", $user_id);
                            } else {
                                $error_message = 'Failed to change password. Please try again.';
                            }
                        } else {
                            $error_message = 'Current password is incorrect.';
                        }
                    } catch (PDOException $e) {
                        $error_message = 'Database error: ' . $e->getMessage();
                    }
                }
                break;
        }
    }
}

// Fetch complete user data AFTER processing any updates
try {
    $user_stmt = $db->prepare("
        SELECT u.*, 
               DATE_FORMAT(u.created_at, '%M %d, %Y') as member_since,
               DATE_FORMAT(u.last_login, '%M %d, %Y at %h:%i %p') as last_login_formatted,
               DATE_FORMAT(u.birthday, '%M %d, %Y') as birthday_formatted
        FROM users u 
        WHERE u.id = ?
    ");
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // If user not found, try a simpler query without date formatting
        $simple_stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $simple_stmt->execute([$user_id]);
        $user = $simple_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Add formatted dates manually
            $user['member_since'] = $user['created_at'] ? date('F j, Y', strtotime($user['created_at'])) : 'Unknown';
            $user['last_login_formatted'] = $user['last_login'] ? date('F j, Y \a\t g:i A', strtotime($user['last_login'])) : 'Never';
            $user['birthday_formatted'] = $user['birthday'] ? date('F j, Y', strtotime($user['birthday'])) : null;
        } else {
            $error_message = 'User not found in database.';
            // Create a default user array to prevent undefined variable errors
            $user = [
                'id' => $user_id,
                'username' => $user_info['username'] ?? 'Unknown',
                'email' => $user_info['email'] ?? 'Unknown',
                'role' => $user_info['role'] ?? 'Unknown',
                'full_name' => $user_info['full_name'] ?? 'Unknown User',
                'first_name' => explode(' ', $user_info['full_name'] ?? 'Unknown User')[0],
                'last_name' => explode(' ', $user_info['full_name'] ?? 'Unknown User', 2)[1] ?? '',
                'phone' => '',
                'position' => null,
                'birthday' => null,
                'birthday_formatted' => null,
                'member_since' => 'Unknown',
                'last_login_formatted' => 'Never'
            ];
        }
    }
    
} catch (PDOException $e) {
    $error_message = 'Failed to fetch user data: ' . $e->getMessage();
    // Create a default user array to prevent undefined variable errors
    $user = [
        'id' => $user_id,
        'username' => $user_info['username'] ?? 'Unknown',
        'email' => $user_info['email'] ?? 'Unknown',
        'role' => $user_info['role'] ?? 'Unknown',
        'full_name' => $user_info['full_name'] ?? 'Unknown User',
        'first_name' => explode(' ', $user_info['full_name'] ?? 'Unknown User')[0],
        'last_name' => explode(' ', $user_info['full_name'] ?? 'Unknown User', 2)[1] ?? '',
        'phone' => '',
        'position' => null,
        'birthday' => null,
        'birthday_formatted' => null,
        'member_since' => 'Unknown',
        'last_login_formatted' => 'Never'
    ];
}

// Define role descriptions
$role_descriptions = [
    'admin' => 'System Administrator - Full access to all sections and user management',
    'section1_manager' => 'Section 1 Manager - Food Storage Section',
    'section2_manager' => 'Section 2 Manager - Food Cutting Section', 
    'section3_manager' => 'Section 3 Manager - Food Dehydration Section',
    'section4_manager' => 'Section 4 Manager - Packing and Storage Section',
    'inventory_manager' => 'Inventory Manager - Monitoring expiration dates and stock levels'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - KYA Food Production</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --light-bg: #f8f9fa;
            --dark-text: #2c3e50;
        }

        body {
            background-color: var(--light-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .profile-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 100" fill="white" opacity="0.1"><polygon points="0,0 1000,0 1000,60 0,100"/></svg>');
            background-size: cover;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            margin: 0 auto 1.5rem;
            border: 4px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(10px);
            position: relative;
            z-index: 1;
        }

        .profile-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border: none;
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .profile-card .card-header {
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            padding: 1.25rem 1.5rem;
            font-weight: 600;
        }

        .profile-card .card-header i {
            margin-right: 0.5rem;
        }

        .form-control, .form-select {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }

        .form-control:disabled {
            background-color: #f8f9fa;
            border-color: #dee2e6;
            color: #6c757d;
        }

        .btn-primary {
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 10px;
            padding: 0.75rem 2rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.4);
        }

        .alert {
            border: none;
            border-radius: 10px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background: linear-gradient(45deg, rgba(39, 174, 96, 0.1), rgba(46, 204, 113, 0.1));
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        .alert-danger {
            background: linear-gradient(45deg, rgba(231, 76, 60, 0.1), rgba(192, 57, 43, 0.1));
            color: var(--danger-color);
            border-left: 4px solid var(--danger-color);
        }

        .info-row {
            padding: 0.75rem 0;
            border-bottom: 1px solid #f1f3f4;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: var(--dark-text);
            margin-bottom: 0.25rem;
        }

        .info-value {
            color: #6c757d;
            font-size: 1rem;
        }

        .role-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .role-admin { background: linear-gradient(45deg, #e74c3c, #c0392b); color: white; }
        .role-section1 { background: linear-gradient(45deg, #3498db, #2980b9); color: white; }
        .role-section2 { background: linear-gradient(45deg, #27ae60, #229954); color: white; }
        .role-section3 { background: linear-gradient(45deg, #f39c12, #e67e22); color: white; }
        .role-section4 { background: linear-gradient(45deg, #9b59b6, #8e44ad); color: white; }
        .role-inventory { background: linear-gradient(45deg, #1abc9c, #16a085); color: white; }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
        }

        .user-info h1 {
            font-size: 2.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .user-info p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .readonly-notice {
            background: linear-gradient(45deg, rgba(255, 193, 7, 0.1), rgba(255, 152, 0, 0.1));
            border: 1px solid #ffc107;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            color: #856404;
        }

        @media (max-width: 768px) {
            .profile-header {
                padding: 2rem 0;
            }
            
            .profile-avatar {
                width: 80px;
                height: 80px;
                font-size: 2rem;
            }
            
            .user-info h1 {
                font-size: 1.75rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background: var(--primary-color); box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <div class="container-fluid">
            <a class="navbar-brand" href="../../dashboard.php">
                <i class="fas fa-industry"></i> KYA Food Production
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="../../dashboard.php">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a class="nav-link" href="../../auth/logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <!-- Profile Header -->
    <div class="profile-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-3 text-center">
                    <div class="profile-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                </div>
                <div class="col-md-9">
                    <div class="user-info">
                        <h1><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h1>
                        <p class="mb-2">
                            <span class="role-badge role-<?php echo str_replace('_manager', '', $user['role']); ?>">
                                <?php echo htmlspecialchars($user['role']); ?>
                            </span>
                        </p>
                        <p class="mb-2"><i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($user['email']); ?></p>
                        <p class="mb-2"><i class="fas fa-user-tag me-2"></i><?php echo htmlspecialchars($user['username']); ?></p>
                        <p class="mb-0"><i class="fas fa-calendar me-2"></i>Member since <?php echo $user['member_since']; ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i><?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Profile Information -->
            <div class="col-lg-8">
                <div class="profile-card">
                    <div class="card-header">
                        <i class="fas fa-user"></i>Profile Information
                    </div>
                    <div class="card-body">
                        <div class="readonly-notice">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Note:</strong> Most profile information can only be updated by the System Administrator. You can only update your phone number and password.
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-row">
                                    <div class="info-label">First Name</div>
                                    <div class="info-value"><?php echo htmlspecialchars($user['first_name']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <div class="info-label">Last Name</div>
                                    <div class="info-value"><?php echo htmlspecialchars($user['last_name']); ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-row">
                                    <div class="info-label">Username</div>
                                    <div class="info-value"><?php echo htmlspecialchars($user['username']); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <div class="info-label">Email Address</div>
                                    <div class="info-value"><?php echo htmlspecialchars($user['email']); ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-row">
                                    <div class="info-label">Phone Number</div>
                                    <div class="info-value"><?php echo htmlspecialchars($user['phone'] ?? 'Not specified'); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <div class="info-label">Birthday</div>
                                    <div class="info-value"><?php echo $user['birthday_formatted'] ?? 'Not specified'; ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-row">
                                    <div class="info-label">Position</div>
                                    <div class="info-value"><?php echo htmlspecialchars($user['position'] ?? 'Not specified'); ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <div class="info-label">Role & Description</div>
                                    <div class="info-value">
                                        <span class="role-badge role-<?php echo str_replace('_manager', '', $user['role']); ?> me-2">
                                            <?php echo htmlspecialchars($user['role']); ?>
                                        </span>
                                        <br><small class="text-muted mt-1 d-block"><?php echo $role_descriptions[$user['role']] ?? 'Role description not available'; ?></small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-row">
                                    <div class="info-label">Last Login</div>
                                    <div class="info-value"><?php echo $user['last_login_formatted'] ?? 'Never'; ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <div class="info-label">Account Created</div>
                                    <div class="info-value"><?php echo $user['member_since']; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Editable Information -->
            <div class="col-lg-4">
                <!-- Phone Number Update -->
                <div class="profile-card">
                    <div class="card-header">
                        <i class="fas fa-phone"></i>Update Phone Number
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="update_phone">
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" 
                                       placeholder="+1 (555) 123-4567">
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Update Phone
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Password Change -->
                <div class="profile-card">
                    <div class="card-header">
                        <i class="fas fa-lock"></i>Change Password
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="change_password">
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current Password *</label>
                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                            </div>
                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password *</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" 
                                       minlength="8" required>
                                <div class="form-text">Minimum 8 characters required.</div>
                            </div>
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm New Password *</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                       minlength="8" required>
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-key me-2"></i>Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Security Tips -->
                <div class="profile-card">
                    <div class="card-header">
                        <i class="fas fa-shield-alt"></i>Security Tips
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Use a strong, unique password</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Include uppercase and lowercase letters</li>
                            <li class="mb-2"><i class="fas fa-check text-success me-2"></i>Add numbers and special characters</li>
                            <li class="mb-0"><i class="fas fa-check text-success me-2"></i>Avoid personal information</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.remove();
                }, 500);
            });
        }, 5000);
    </script>
</body>
</html>