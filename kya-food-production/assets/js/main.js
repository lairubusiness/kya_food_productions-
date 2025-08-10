/**
 * KYA Food Production Management System
 * Main JavaScript File
 * Version: 1.0.0
 */

// Global variables
let currentUser = null;
let notificationInterval = null;

// Initialize application
document.addEventListener('DOMContentLoaded', function() {
    initializeApp();
});

function initializeApp() {
    // Initialize components
    initializeSidebar();
    initializeNotifications();
    initializeFormValidation();
    initializeDataTables();
    initializeCharts();
    
    // Start periodic updates
    startPeriodicUpdates();
    
    console.log('KYA Food Production System initialized');
}

// Sidebar functionality
function initializeSidebar() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    
    if (sidebarToggle && sidebar && mainContent) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            
            // Save state
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        });
        
        // Restore state
        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
        }
    }
    
    // Handle mobile sidebar
    handleMobileSidebar();
}

function handleMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay d-md-none';
    overlay.style.display = 'none';
    
    document.body.appendChild(overlay);
    
    // Show sidebar on mobile
    window.showMobileSidebar = function() {
        sidebar.classList.add('show');
        overlay.style.display = 'block';
    };
    
    // Hide sidebar on mobile
    window.hideMobileSidebar = function() {
        sidebar.classList.remove('show');
        overlay.style.display = 'none';
    };
    
    // Hide on overlay click
    overlay.addEventListener('click', window.hideMobileSidebar);
    
    // Update sidebar toggle for mobile
    const sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            if (window.innerWidth < 768) {
                if (sidebar.classList.contains('show')) {
                    window.hideMobileSidebar();
                } else {
                    window.showMobileSidebar();
                }
            }
        });
    }
}

// Notification system
function initializeNotifications() {
    // Check for new notifications every 30 seconds
    notificationInterval = setInterval(checkNotifications, 30000);
    
    // Initialize notification dropdown
    initializeNotificationDropdown();
}

function checkNotifications() {
    fetch('api/notifications.php?action=get_unread_count')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateNotificationBadge(data.count);
            }
        })
        .catch(error => console.error('Error checking notifications:', error));
}

function updateNotificationBadge(count) {
    const badge = document.querySelector('.notification-badge');
    const bell = document.querySelector('.notification-bell');
    
    if (count > 0) {
        if (badge) {
            badge.textContent = count;
            badge.style.display = 'flex';
        } else {
            const newBadge = document.createElement('span');
            newBadge.className = 'notification-badge';
            newBadge.textContent = count;
            bell.appendChild(newBadge);
        }
    } else {
        if (badge) {
            badge.style.display = 'none';
        }
    }
}

function initializeNotificationDropdown() {
    const notificationBell = document.querySelector('.notification-bell');
    if (notificationBell) {
        notificationBell.addEventListener('click', function() {
            loadRecentNotifications();
        });
    }
}

function loadRecentNotifications() {
    fetch('api/notifications.php?action=get_recent')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateNotificationDropdown(data.notifications);
            }
        })
        .catch(error => console.error('Error loading notifications:', error));
}

function updateNotificationDropdown(notifications) {
    const dropdown = document.querySelector('.notification-dropdown');
    if (!dropdown) return;
    
    let html = `
        <h6 class="dropdown-header">
            <i class="fas fa-bell me-2"></i>Recent Notifications
        </h6>
        <div class="dropdown-divider"></div>
    `;
    
    if (notifications.length === 0) {
        html += `
            <div class="dropdown-item-text text-center py-3">
                <i class="fas fa-check-circle text-success fa-2x mb-2"></i>
                <div>No new notifications</div>
            </div>
        `;
    } else {
        notifications.forEach(notification => {
            const priorityClass = notification.priority === 'critical' ? 'danger' : 
                                notification.priority === 'high' ? 'warning' : 'info';
            
            html += `
                <a class="dropdown-item notification-item" href="#" data-id="${notification.id}">
                    <div class="d-flex">
                        <div class="flex-shrink-0">
                            <span class="badge bg-${priorityClass}">${notification.priority.toUpperCase()}</span>
                        </div>
                        <div class="flex-grow-1 ms-2">
                            <div class="fw-semibold">${notification.title}</div>
                            <div class="small text-muted">${notification.message.substring(0, 50)}...</div>
                            <div class="small text-muted">${formatTimeAgo(notification.created_at)}</div>
                        </div>
                    </div>
                </a>
            `;
        });
    }
    
    html += `
        <div class="dropdown-divider"></div>
        <a class="dropdown-item text-center" href="modules/notifications/">
            <i class="fas fa-eye me-2"></i>View All Notifications
        </a>
    `;
    
    dropdown.innerHTML = html;
    
    // Add click handlers for notification items
    dropdown.querySelectorAll('.notification-item').forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            const notificationId = this.dataset.id;
            markNotificationAsRead(notificationId);
        });
    });
}

function markNotificationAsRead(notificationId) {
    fetch('api/notifications.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'mark_read',
            notification_id: notificationId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            checkNotifications(); // Refresh notification count
        }
    })
    .catch(error => console.error('Error marking notification as read:', error));
}

// Form validation
function initializeFormValidation() {
    // Bootstrap form validation
    const forms = document.querySelectorAll('.needs-validation');
    forms.forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });
    
    // Custom validation rules
    addCustomValidationRules();
}

function addCustomValidationRules() {
    // Email validation
    const emailInputs = document.querySelectorAll('input[type="email"]');
    emailInputs.forEach(input => {
        input.addEventListener('blur', function() {
            validateEmail(this);
        });
    });
    
    // Phone validation
    const phoneInputs = document.querySelectorAll('input[type="tel"], input[name*="phone"]');
    phoneInputs.forEach(input => {
        input.addEventListener('blur', function() {
            validatePhone(this);
        });
    });
    
    // Number validation
    const numberInputs = document.querySelectorAll('input[type="number"]');
    numberInputs.forEach(input => {
        input.addEventListener('blur', function() {
            validateNumber(this);
        });
    });
}

function validateEmail(input) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const isValid = emailRegex.test(input.value);
    
    if (input.value && !isValid) {
        input.setCustomValidity('Please enter a valid email address');
    } else {
        input.setCustomValidity('');
    }
}

function validatePhone(input) {
    const phoneRegex = /^[\+]?[1-9][\d]{0,15}$/;
    const isValid = phoneRegex.test(input.value.replace(/[\s\-\(\)]/g, ''));
    
    if (input.value && !isValid) {
        input.setCustomValidity('Please enter a valid phone number');
    } else {
        input.setCustomValidity('');
    }
}

function validateNumber(input) {
    const value = parseFloat(input.value);
    const min = parseFloat(input.getAttribute('min'));
    const max = parseFloat(input.getAttribute('max'));
    
    if (input.value) {
        if (isNaN(value)) {
            input.setCustomValidity('Please enter a valid number');
        } else if (min !== null && value < min) {
            input.setCustomValidity(`Value must be at least ${min}`);
        } else if (max !== null && value > max) {
            input.setCustomValidity(`Value must be no more than ${max}`);
        } else {
            input.setCustomValidity('');
        }
    }
}

// DataTables initialization
function initializeDataTables() {
    if (typeof $.fn.DataTable !== 'undefined') {
        $('.data-table').each(function() {
            const table = $(this);
            const options = {
                responsive: true,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                order: [[0, 'desc']],
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    infoEmpty: "No entries available",
                    infoFiltered: "(filtered from _MAX_ total entries)",
                    paginate: {
                        first: "First",
                        last: "Last",
                        next: "Next",
                        previous: "Previous"
                    }
                }
            };
            
            // Merge custom options if provided
            const customOptions = table.data('table-options');
            if (customOptions) {
                Object.assign(options, customOptions);
            }
            
            table.DataTable(options);
        });
    }
}

// Chart initialization
function initializeCharts() {
    if (typeof Chart !== 'undefined') {
        // Set global chart defaults
        Chart.defaults.font.family = "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif";
        Chart.defaults.color = '#6c757d';
        Chart.defaults.borderColor = '#dee2e6';
        
        // Initialize charts
        initializeDashboardCharts();
    }
}

function initializeDashboardCharts() {
    // Inventory chart
    const inventoryChart = document.getElementById('inventoryChart');
    if (inventoryChart) {
        createInventoryChart(inventoryChart);
    }
    
    // Processing chart
    const processingChart = document.getElementById('processingChart');
    if (processingChart) {
        createProcessingChart(processingChart);
    }
    
    // Orders chart
    const ordersChart = document.getElementById('ordersChart');
    if (ordersChart) {
        createOrdersChart(ordersChart);
    }
}

function createInventoryChart(canvas) {
    fetch('api/dashboard_stats.php?chart=inventory')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                new Chart(canvas, {
                    type: 'doughnut',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            data: data.values,
                            backgroundColor: [
                                '#2c5f41',
                                '#4a8b3a',
                                '#ff6b35',
                                '#17a2b8',
                                '#ffc107'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }
        })
        .catch(error => console.error('Error loading inventory chart:', error));
}

function createProcessingChart(canvas) {
    fetch('api/dashboard_stats.php?chart=processing')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Processing Volume',
                            data: data.values,
                            borderColor: '#2c5f41',
                            backgroundColor: 'rgba(44, 95, 65, 0.1)',
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            }
        })
        .catch(error => console.error('Error loading processing chart:', error));
}

function createOrdersChart(canvas) {
    fetch('api/dashboard_stats.php?chart=orders')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                new Chart(canvas, {
                    type: 'bar',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            label: 'Orders',
                            data: data.values,
                            backgroundColor: '#ff6b35'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                });
            }
        })
        .catch(error => console.error('Error loading orders chart:', error));
}

// Periodic updates
function startPeriodicUpdates() {
    // Update dashboard stats every 5 minutes
    setInterval(function() {
        if (window.location.pathname.includes('dashboard.php')) {
            updateDashboardStats();
        }
    }, 300000);
}

function updateDashboardStats() {
    fetch('api/dashboard_stats.php?action=get_stats')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateStatsCards(data.stats);
            }
        })
        .catch(error => console.error('Error updating dashboard stats:', error));
}

function updateStatsCards(stats) {
    Object.keys(stats).forEach(key => {
        const element = document.querySelector(`[data-stat="${key}"]`);
        if (element) {
            element.textContent = stats[key];
        }
    });
}

// Utility functions
function formatTimeAgo(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diffInSeconds = Math.floor((now - date) / 1000);
    
    if (diffInSeconds < 60) {
        return 'Just now';
    } else if (diffInSeconds < 3600) {
        const minutes = Math.floor(diffInSeconds / 60);
        return `${minutes} minute${minutes > 1 ? 's' : ''} ago`;
    } else if (diffInSeconds < 86400) {
        const hours = Math.floor(diffInSeconds / 3600);
        return `${hours} hour${hours > 1 ? 's' : ''} ago`;
    } else {
        const days = Math.floor(diffInSeconds / 86400);
        return `${days} day${days > 1 ? 's' : ''} ago`;
    }
}

function formatCurrency(amount, currency = 'USD') {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currency
    }).format(amount);
}

function formatNumber(number, decimals = 0) {
    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    }).format(number);
}

function showAlert(type, message, autoHide = true) {
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    let alertContainer = document.querySelector('.flash-messages');
    if (!alertContainer) {
        alertContainer = document.createElement('div');
        alertContainer.className = 'flash-messages';
        const contentArea = document.querySelector('.content-area');
        if (contentArea) {
            contentArea.insertBefore(alertContainer, contentArea.firstChild);
        }
    }
    
    alertContainer.insertAdjacentHTML('beforeend', alertHtml);
    
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

function showLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.classList.remove('d-none');
    }
}

function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.classList.add('d-none');
    }
}

function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

// Export functions for global use
window.KYASystem = {
    showAlert,
    showLoading,
    hideLoading,
    confirmAction,
    formatCurrency,
    formatNumber,
    formatTimeAgo,
    markNotificationAsRead,
    updateNotificationBadge
};
