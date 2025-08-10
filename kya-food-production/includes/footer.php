        </main>
    </div>
    
    <!-- Loading Overlay -->
    <div class="loading-overlay d-none" id="loadingOverlay">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>
    
    <!-- JavaScript Dependencies -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.js"></script>
    <script src="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>assets/js/main.js"></script>
    <script src="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>assets/js/dashboard.js"></script>
    <script src="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>assets/js/notifications.js"></script>
    <script src="<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>assets/js/validation.js"></script>
    
    <script>
        // Initialize sidebar toggle
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('collapsed');
                    mainContent.classList.toggle('expanded');
                    
                    // Save state to localStorage
                    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                });
            }
            
            // Restore sidebar state
            const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            if (sidebarCollapsed) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            }
            
            // Auto-hide alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    if (alert.classList.contains('alert-success') || alert.classList.contains('alert-info')) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                });
            }, 5000);
            
            // Initialize tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Initialize popovers
            const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
            popoverTriggerList.map(function (popoverTriggerEl) {
                return new bootstrap.Popover(popoverTriggerEl);
            });
        });
        
        // Global loading functions
        function showLoading() {
            document.getElementById('loadingOverlay').classList.remove('d-none');
        }
        
        function hideLoading() {
            document.getElementById('loadingOverlay').classList.add('d-none');
        }
        
        // Global AJAX error handler
        $(document).ajaxError(function(event, xhr, settings, thrownError) {
            hideLoading();
            console.error('AJAX Error:', thrownError);
            
            if (xhr.status === 401) {
                window.location.href = 'login.php';
            } else if (xhr.status === 403) {
                showAlert('danger', 'Access denied. You do not have permission to perform this action.');
            } else {
                showAlert('danger', 'An error occurred. Please try again.');
            }
        });
        
        // Global alert function
        function showAlert(type, message, autoHide = true) {
            const alertHtml = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            
            // Find or create alert container
            let alertContainer = document.querySelector('.flash-messages');
            if (!alertContainer) {
                alertContainer = document.createElement('div');
                alertContainer.className = 'flash-messages';
                document.querySelector('.content-area').prepend(alertContainer);
            }
            
            alertContainer.insertAdjacentHTML('beforeend', alertHtml);
            
            // Auto-hide success and info alerts
            if (autoHide && (type === 'success' || type === 'info')) {
                setTimeout(function() {
                    const alert = alertContainer.lastElementChild;
                    if (alert) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                }, 5000);
            }
        }
        
        // Format currency
        function formatCurrency(amount, currency = 'USD') {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: currency
            }).format(amount);
        }
        
        // Format date
        function formatDate(dateString, format = 'short') {
            const date = new Date(dateString);
            const options = format === 'short' 
                ? { year: 'numeric', month: 'short', day: 'numeric' }
                : { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' };
            
            return date.toLocaleDateString('en-US', options);
        }
        
        // Confirm action
        function confirmAction(message, callback) {
            if (confirm(message)) {
                callback();
            }
        }
        
        // Session timeout warning
        let sessionWarningShown = false;
        setInterval(function() {
            // Check if session is about to expire (25 minutes)
            const sessionStart = <?php echo $_SESSION['last_activity'] ?? time(); ?>;
            const currentTime = Math.floor(Date.now() / 1000);
            const sessionAge = currentTime - sessionStart;
            
            if (sessionAge > 1500 && !sessionWarningShown) { // 25 minutes
                sessionWarningShown = true;
                if (confirm('Your session will expire soon. Click OK to extend your session.')) {
                    // Make a request to extend session
                    fetch('<?php echo strpos($_SERVER['REQUEST_URI'], '/modules/') !== false ? '../../' : ''; ?>api/extend_session.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        }
                    }).then(response => {
                        if (response.ok) {
                            sessionWarningShown = false;
                        }
                    });
                }
            }
        }, 60000); // Check every minute
    </script>
    
    <!-- Page-specific JavaScript -->
    <?php if (isset($pageScript)): ?>
        <script src="<?php echo $pageScript; ?>"></script>
    <?php endif; ?>
    
    <!-- Inline JavaScript -->
    <?php if (isset($inlineScript)): ?>
        <script><?php echo $inlineScript; ?></script>
    <?php endif; ?>
</body>
</html>
