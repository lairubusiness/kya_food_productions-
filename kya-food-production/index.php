<?php
/**
 * KYA Food Production Management System
 * Main Landing Page
 * Version: 1.0.0
 */

// Start session and check if user is already logged in
session_start();

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    header('Location: dashboard.php');
    exit();
}

// Include constants
require_once 'config/constants.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="assets/images/favicon.ico">
</head>
<body class="landing-page">
    <div class="landing-container">
        <div class="container-fluid h-100">
            <div class="row h-100">
                <!-- Left Side - Company Info -->
                <div class="col-lg-6 d-flex align-items-center justify-content-center bg-primary-gradient">
                    <div class="text-center text-white p-5">
                        <div class="company-logo mb-4">
                            <img src="assets/images/logo.png" alt="KYA Food Production" class="img-fluid" style="max-height: 120px;">
                        </div>
                        <h1 class="display-4 fw-bold mb-3"><?php echo COMPANY_NAME; ?></h1>
                        <h2 class="h3 mb-4">Management System</h2>
                        <p class="lead mb-4">
                            Complete inventory and order management solution for food production operations
                        </p>
                        <div class="features-list">
                            <div class="feature-item mb-3">
                                <i class="fas fa-warehouse me-3"></i>
                                <span>Raw Material Handling</span>
                            </div>
                            <div class="feature-item mb-3">
                                <i class="fas fa-industry me-3"></i>
                                <span>Dehydration Processing</span>
                            </div>
                            <div class="feature-item mb-3">
                                <i class="fas fa-box me-3"></i>
                                <span>Packaging & Storage</span>
                            </div>
                            <div class="feature-item mb-3">
                                <i class="fas fa-chart-line me-3"></i>
                                <span>Real-time Analytics</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Right Side - Login Form -->
                <div class="col-lg-6 d-flex align-items-center justify-content-center bg-light">
                    <div class="login-form-container p-5">
                        <div class="text-center mb-5">
                            <h3 class="fw-bold text-dark">Welcome Back</h3>
                            <p class="text-muted">Please sign in to your account</p>
                        </div>
                        
                        <!-- Login Form -->
                        <form id="loginForm" action="login.php" method="POST" class="needs-validation" novalidate>
                            <div class="mb-4">
                                <label for="username" class="form-label fw-semibold">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-user text-muted"></i>
                                    </span>
                                    <input type="text" class="form-control border-start-0 ps-0" id="username" name="username" required>
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
    
    <!-- Alert Container -->
    <div id="alertContainer" class="position-fixed top-0 end-0 p-3" style="z-index: 1100;"></div>
    
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
        
        // Handle URL parameters for messages
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('logout') === '1') {
            showAlert('success', 'You have been successfully logged out.');
        }
        if (urlParams.get('timeout') === '1') {
            showAlert('warning', 'Your session has expired. Please log in again.');
        }
        if (urlParams.get('error') === 'invalid_credentials') {
            showAlert('danger', 'Invalid username or password.');
        }
        
        function showAlert(type, message) {
            const alertHtml = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            document.getElementById('alertContainer').innerHTML = alertHtml;
        }
    </script>
</body>
</html>
