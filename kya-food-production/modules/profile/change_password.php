<?php
/**
 * KYA Food Production - Change Password
 * Secure password change functionality for user accounts
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

$success_message = '';
$error_message = '';
$validation_errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validation
        if (empty($current_password)) {
            $validation_errors['current_password'] = 'Current password is required.';
        }
        
        if (empty($new_password)) {
            $validation_errors['new_password'] = 'New password is required.';
        } elseif (strlen($new_password) < 8) {
            $validation_errors['new_password'] = 'New password must be at least 8 characters long.';
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/', $new_password)) {
            $validation_errors['new_password'] = 'New password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.';
        }
        
        if (empty($confirm_password)) {
            $validation_errors['confirm_password'] = 'Please confirm your new password.';
        } elseif ($new_password !== $confirm_password) {
            $validation_errors['confirm_password'] = 'New password and confirmation do not match.';
        }
        
        if ($current_password === $new_password) {
            $validation_errors['new_password'] = 'New password must be different from current password.';
        }
        
        // If no validation errors, proceed with password change
        if (empty($validation_errors)) {
            // Get current user data
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$userInfo['id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found.');
            }
            
            // Verify current password
            if (!password_verify($current_password, $user['password'])) {
                $validation_errors['current_password'] = 'Current password is incorrect.';
            } else {
                // Hash new password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                // Update password in database
                $update_stmt = $conn->prepare("
                    UPDATE users 
                    SET password = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                
                if ($update_stmt->execute([$hashed_password, $userInfo['id']])) {
                    // Log activity
                    logActivity($userInfo['id'], 'password_changed', 'users', $userInfo['id'], null, [
                        'user_id' => $userInfo['id'],
                        'username' => $userInfo['username']
                    ]);
                    
                    $success_message = 'Password changed successfully! Please use your new password for future logins.';
                    
                    // Clear form data on success
                    $_POST = [];
                } else {
                    throw new Exception('Failed to update password. Please try again.');
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Change password error: " . $e->getMessage());
        $error_message = 'An error occurred: ' . $e->getMessage();
    }
}

$pageTitle = 'Change Password - ' . htmlspecialchars($userInfo['username']);
include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Change Password</h1>
            <p class="text-muted">Update your account password securely</p>
        </div>
        <div>
            <a href="profile.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Profile
            </a>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <!-- Success Message -->
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Password Requirements Info -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-info-circle text-info me-2"></i>Password Requirements
                    </h5>
                </div>
                <div class="card-body">
                    <p class="mb-2">Your new password must meet the following requirements:</p>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-check text-success me-2"></i>At least 8 characters long</li>
                        <li><i class="fas fa-check text-success me-2"></i>Contains at least one uppercase letter (A-Z)</li>
                        <li><i class="fas fa-check text-success me-2"></i>Contains at least one lowercase letter (a-z)</li>
                        <li><i class="fas fa-check text-success me-2"></i>Contains at least one number (0-9)</li>
                        <li><i class="fas fa-check text-success me-2"></i>Contains at least one special character (@$!%*?&)</li>
                        <li><i class="fas fa-check text-success me-2"></i>Different from your current password</li>
                    </ul>
                </div>
            </div>

            <!-- Change Password Form -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-key text-primary me-2"></i>Change Password
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="changePasswordForm">
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <div class="input-group">
                                <input type="password" 
                                       class="form-control <?php echo isset($validation_errors['current_password']) ? 'is-invalid' : ''; ?>" 
                                       id="current_password" 
                                       name="current_password" 
                                       required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('current_password')">
                                    <i class="fas fa-eye" id="current_password_icon"></i>
                                </button>
                                <?php if (isset($validation_errors['current_password'])): ?>
                                    <div class="invalid-feedback">
                                        <?php echo htmlspecialchars($validation_errors['current_password']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <div class="input-group">
                                <input type="password" 
                                       class="form-control <?php echo isset($validation_errors['new_password']) ? 'is-invalid' : ''; ?>" 
                                       id="new_password" 
                                       name="new_password" 
                                       required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password')">
                                    <i class="fas fa-eye" id="new_password_icon"></i>
                                </button>
                                <?php if (isset($validation_errors['new_password'])): ?>
                                    <div class="invalid-feedback">
                                        <?php echo htmlspecialchars($validation_errors['new_password']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div id="password_strength" class="mt-2"></div>
                        </div>

                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" 
                                       class="form-control <?php echo isset($validation_errors['confirm_password']) ? 'is-invalid' : ''; ?>" 
                                       id="confirm_password" 
                                       name="confirm_password" 
                                       required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                                    <i class="fas fa-eye" id="confirm_password_icon"></i>
                                </button>
                                <?php if (isset($validation_errors['confirm_password'])): ?>
                                    <div class="invalid-feedback">
                                        <?php echo htmlspecialchars($validation_errors['confirm_password']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div id="password_match" class="mt-2"></div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-save me-2"></i>Change Password
                            </button>
                            <a href="profile.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.card-body {
    background-color: #ffffff;
    color: #212529;
}

.password-strength-weak {
    color: #dc3545;
}

.password-strength-medium {
    color: #ffc107;
}

.password-strength-strong {
    color: #28a745;
}

.password-match-success {
    color: #28a745;
}

.password-match-error {
    color: #dc3545;
}
</style>

<script>
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const icon = document.getElementById(fieldId + '_icon');
    
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

function checkPasswordStrength(password) {
    let strength = 0;
    let feedback = [];
    
    if (password.length >= 8) strength++;
    else feedback.push('At least 8 characters');
    
    if (/[a-z]/.test(password)) strength++;
    else feedback.push('Lowercase letter');
    
    if (/[A-Z]/.test(password)) strength++;
    else feedback.push('Uppercase letter');
    
    if (/\d/.test(password)) strength++;
    else feedback.push('Number');
    
    if (/[@$!%*?&]/.test(password)) strength++;
    else feedback.push('Special character');
    
    return { strength, feedback };
}

function updatePasswordStrength() {
    const password = document.getElementById('new_password').value;
    const strengthDiv = document.getElementById('password_strength');
    
    if (password.length === 0) {
        strengthDiv.innerHTML = '';
        return;
    }
    
    const result = checkPasswordStrength(password);
    let strengthText = '';
    let strengthClass = '';
    
    if (result.strength < 3) {
        strengthText = 'Weak';
        strengthClass = 'password-strength-weak';
    } else if (result.strength < 5) {
        strengthText = 'Medium';
        strengthClass = 'password-strength-medium';
    } else {
        strengthText = 'Strong';
        strengthClass = 'password-strength-strong';
    }
    
    let html = `<small class="${strengthClass}">Password Strength: ${strengthText}</small>`;
    
    if (result.feedback.length > 0) {
        html += `<br><small class="text-muted">Missing: ${result.feedback.join(', ')}</small>`;
    }
    
    strengthDiv.innerHTML = html;
}

function checkPasswordMatch() {
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    const matchDiv = document.getElementById('password_match');
    
    if (confirmPassword.length === 0) {
        matchDiv.innerHTML = '';
        return;
    }
    
    if (newPassword === confirmPassword) {
        matchDiv.innerHTML = '<small class="password-match-success"><i class="fas fa-check me-1"></i>Passwords match</small>';
    } else {
        matchDiv.innerHTML = '<small class="password-match-error"><i class="fas fa-times me-1"></i>Passwords do not match</small>';
    }
}

// Event listeners
document.getElementById('new_password').addEventListener('input', updatePasswordStrength);
document.getElementById('confirm_password').addEventListener('input', checkPasswordMatch);
document.getElementById('new_password').addEventListener('input', checkPasswordMatch);

// Form validation
document.getElementById('changePasswordForm').addEventListener('submit', function(e) {
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    if (newPassword !== confirmPassword) {
        e.preventDefault();
        alert('New password and confirmation do not match.');
        return false;
    }
    
    const result = checkPasswordStrength(newPassword);
    if (result.strength < 5) {
        e.preventDefault();
        alert('Please ensure your password meets all requirements.');
        return false;
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
