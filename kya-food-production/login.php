<?php
/**
 * KYA Food Production - Login Handler
 * Processes user authentication
 */

require_once 'config/database.php';
require_once 'config/constants.php';
require_once 'config/session.php';

SessionManager::start();

// If user is already logged in, redirect to dashboard
if (SessionManager::isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']);
    
    if (empty($username) || empty($password)) {
        $error_message = 'Please enter both username and password.';
    } else {
        try {
            $db = new Database();
            $conn = $db->connect();
            
            // Check if account is locked
            $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user) {
                // Check if account is locked
                if ($user['account_locked']) {
                    $error_message = 'Account is locked. Please contact administrator.';
                } elseif (!$user['is_active']) {
                    $error_message = 'Account is inactive. Please contact administrator.';
                } elseif (password_verify($password, $user['password'])) {
                    // Successful login
                    
                    // Reset login attempts
                    $stmt = $conn->prepare("UPDATE users SET login_attempts = 0, last_login = NOW() WHERE id = ?");
                    $stmt->execute([$user['id']]);
                    
                    // Set session variables
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['last_activity'] = time();
                    
                    // Log successful login
                    SessionManager::logActivity('user_login');
                    
                    // Set remember me cookie if requested
                    if ($remember_me) {
                        $token = bin2hex(random_bytes(32));
                        setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/', '', false, true); // 30 days
                        
                        // Store token in database (you might want to create a remember_tokens table)
                        $stmt = $conn->prepare("UPDATE users SET password_reset_token = ? WHERE id = ?");
                        $stmt->execute([$token, $user['id']]);
                    }
                    
                    // Redirect to dashboard
                    header('Location: dashboard.php');
                    exit();
                } else {
                    // Failed login - increment attempts
                    $attempts = $user['login_attempts'] + 1;
                    $locked = ($attempts >= MAX_LOGIN_ATTEMPTS);
                    
                    $stmt = $conn->prepare("UPDATE users SET login_attempts = ?, account_locked = ? WHERE id = ?");
                    $stmt->execute([$attempts, $locked, $user['id']]);
                    
                    if ($locked) {
                        $error_message = 'Account locked due to too many failed attempts. Please contact administrator.';
                        
                        // Log account lockout
                        SessionManager::logActivity('account_locked', 'users', $user['id']);
                    } else {
                        $remaining = MAX_LOGIN_ATTEMPTS - $attempts;
                        $error_message = "Invalid credentials. $remaining attempts remaining.";
                    }
                }
            } else {
                $error_message = 'Invalid username or password.';
            }
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $error_message = 'Login failed. Please try again.';
        }
    }
}

// Check for URL parameters
$timeout = isset($_GET['timeout']);
$logout = isset($_GET['logout']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="container-fluid h-100">
            <div class="row h-100">
                <!-- Left Side - Company Info -->
                <div class="col-lg-6 d-flex align-items-center justify-content-center" style="background-color:rgb(16, 145, 20);">
                    <div class="text-center text-white p-5">
                        <div class="company-logo mb-4">
                            <img src="assets/images/logo.png" alt="KYA Food Production" class="img-fluid" style="max-height: 100px;">
                        </div>
                        <h1 class="h2 mb-3"><?php echo COMPANY_NAME; ?></h1>
                        <h2 class="h4 mb-4">Management System</h2>
                        <p class="mb-4">
                            Complete inventory and order management solution for food production operations
                        </p>
                        <div class="features-list">
                            <div class="feature-item mb-2">
                                <i class="fas fa-warehouse me-2"></i>
                                <span>Raw Material Handling</span>
                            </div>
                            <div class="feature-item mb-2">
                                <i class="fas fa-cogs me-2"></i>
                                <span>Processing Management</span>
                            </div>
                            <div class="feature-item mb-2">
                                <i class="fas fa-box me-2"></i>
                                <span>Packaging & Distribution</span>
                            </div>
                            <div class="feature-item mb-2">
                                <i class="fas fa-chart-line me-2"></i>
                                <span>Real-time Analytics</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Right Side - Login Form -->
                <div class="col-lg-6 d-flex align-items-center justify-content-center">
                    <div class="login-form-container p-5">
                        <div class="text-center mb-5">
                            <h3 class="fw-bold text-dark">Welcome Back</h3>
                            <p class="text-muted">Please sign in to your account</p>
                        </div>
                        
                        <!-- Alert Messages -->
                        <?php if ($timeout): ?>
                            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                                <i class="fas fa-clock me-2"></i>Your session has expired. Please log in again.
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($logout): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i>You have been successfully logged out.
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($error_message): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Login Form -->
                        <form method="POST" class="needs-validation" novalidate>
                            <div class="mb-4">
                                <label for="username" class="form-label fw-semibold">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-user text-muted"></i>
                                    </span>
                                    <input type="text" class="form-control border-start-0 ps-0" id="username" name="username" 
                                           value="<?php echo htmlspecialchars($username ?? ''); ?>" required>
                                    <div class="invalid-feedback">Please enter your username.</div>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label for="password" class="form-label fw-semibold">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-lock text-muted"></i>
                                    </span>
                                    <input type="password" class="form-control border-start-0 ps-0" id="password" name="password" required>
                                    <button class="btn btn-outline-secondary border-start-0" type="button" id="togglePassword">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <div class="invalid-feedback">Please enter your password.</div>
                                </div>
                            </div>
                            
                            <div class="mb-4 form-check">
                                <input type="checkbox" class="form-check-input" id="rememberMe" name="remember_me">
                                <label class="form-check-label" for="rememberMe">Remember me</label>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-lg w-100 mb-3">
                                <i class="fas fa-sign-in-alt me-2"></i>Sign In
                            </button>
                            
                            <div class="text-center">
                                <a href="#" class="text-decoration-none" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal">
                                    Forgot your password?
                                </a>
                            </div>
                        </form>
                        
                        <!-- Demo Credentials -->
                        <div class="demo-credentials mt-5 p-3 bg-info bg-opacity-10 rounded">
                            <h6 class="fw-bold text-info mb-2">Demo Credentials:</h6>
                            <small class="d-block"><strong>Admin:</strong> admin / admin123</small>
                            <small class="d-block"><strong>Section 1:</strong> section1_mgr / section1123</small>
                            <small class="d-block"><strong>Section 2:</strong> section2_mgr / section2123</small>
                            <small class="d-block"><strong>Section 3:</strong> section3_mgr / section3123</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Forgot Password Modal -->
    <div class="modal fade" id="forgotPasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="forgotPasswordForm">
                        <div class="mb-3">
                            <label for="resetEmail" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="resetEmail" name="email" required>
                        </div>
                        <div class="text-muted small mb-3">
                            Enter your email address and we'll send you a link to reset your password.
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="sendResetLink">Send Reset Link</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="assets/js/main.js"></script>
    
    <script>
        // Form validation
        (function() {
            'use strict';
            window.addEventListener('load', function() {
                var forms = document.getElementsByClassName('needs-validation');
                var validation = Array.prototype.filter.call(forms, function(form) {
                    form.addEventListener('submit', function(event) {
                        if (form.checkValidity() === false) {
                            event.preventDefault();
                            event.stopPropagation();
                        }
                        form.classList.add('was-validated');
                    }, false);
                });
            }, false);
        })();
        
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const password = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (password.type === 'password') {
                password.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                password.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    </script>
</body>
</html>
