/**
 * KYA Food Production - Notifications JavaScript
 * Handles real-time notifications and notification management
 */

class NotificationManager {
    constructor() {
        this.pollingInterval = null;
        this.unreadCount = 0;
        this.notifications = [];
        this.isPolling = false;
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.initializeNotificationDropdown();
        this.startPolling();
        this.loadInitialNotifications();
    }

    setupEventListeners() {
        // Notification dropdown toggle
        document.addEventListener('click', (e) => {
            if (e.target.matches('.notification-toggle')) {
                e.preventDefault();
                this.toggleNotificationDropdown();
            }
        });

        // Mark as read
        document.addEventListener('click', (e) => {
            if (e.target.matches('.mark-read-btn')) {
                e.preventDefault();
                this.markAsRead(e.target.dataset.notificationId);
            }
        });

        // Mark all as read
        document.addEventListener('click', (e) => {
            if (e.target.matches('.mark-all-read-btn')) {
                e.preventDefault();
                this.markAllAsRead();
            }
        });

        // Archive notification
        document.addEventListener('click', (e) => {
            if (e.target.matches('.archive-notification-btn')) {
                e.preventDefault();
                this.archiveNotification(e.target.dataset.notificationId);
            }
        });

        // View all notifications
        document.addEventListener('click', (e) => {
            if (e.target.matches('.view-all-notifications')) {
                window.location.href = '/kya-food-production/modules/notifications/';
            }
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.notification-dropdown')) {
                this.closeNotificationDropdown();
            }
        });
    }

    initializeNotificationDropdown() {
        const dropdown = document.querySelector('.notification-dropdown');
        if (!dropdown) return;

        // Create dropdown structure if it doesn't exist
        if (!dropdown.querySelector('.notification-list')) {
            dropdown.innerHTML = `
                <div class="notification-header">
                    <h6 class="mb-0">Notifications</h6>
                    <button class="btn btn-sm btn-link mark-all-read-btn">Mark all read</button>
                </div>
                <div class="notification-list">
                    <div class="notification-loading">
                        <div class="text-center py-3">
                            <div class="spinner-border spinner-border-sm" role="status"></div>
                            <div class="mt-2">Loading notifications...</div>
                        </div>
                    </div>
                </div>
                <div class="notification-footer">
                    <button class="btn btn-sm btn-primary view-all-notifications w-100">
                        View All Notifications
                    </button>
                </div>
            `;
        }
    }

    async loadInitialNotifications() {
        try {
            const response = await fetch('/kya-food-production/api/notifications.php?action=get_recent&limit=10');
            const data = await response.json();
            
            if (data.success) {
                this.notifications = data.notifications;
                this.updateNotificationDropdown();
                this.updateUnreadCount();
            }
        } catch (error) {
            console.error('Error loading initial notifications:', error);
        }
    }

    startPolling() {
        if (this.isPolling) return;
        
        this.isPolling = true;
        this.pollingInterval = setInterval(() => {
            if (document.visibilityState === 'visible') {
                this.pollForUpdates();
            }
        }, 30000); // Poll every 30 seconds

        // Poll immediately when page becomes visible
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.pollForUpdates();
            }
        });
    }

    async pollForUpdates() {
        try {
            const countResponse = await fetch('/kya-food-production/api/notifications.php?action=get_unread_count');
            const countData = await countResponse.json();
            
            if (countData.success) {
                const newCount = countData.count;
                
                if (newCount !== this.unreadCount) {
                    const oldCount = this.unreadCount;
                    this.unreadCount = newCount;
                    
                    if (newCount > oldCount) {
                        this.showNewNotificationAlert(newCount - oldCount);
                    }
                    
                    this.updateUnreadBadge();
                    await this.loadInitialNotifications();
                }
            }
        } catch (error) {
            console.error('Error polling for updates:', error);
        }
    }

    async markAsRead(notificationId) {
        try {
            const response = await fetch('/kya-food-production/api/notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'mark_read',
                    notification_id: notificationId
                })
            });

            const data = await response.json();
            
            if (data.success) {
                const notification = this.notifications.find(n => n.id == notificationId);
                if (notification) {
                    notification.is_read = true;
                }
                
                this.updateNotificationDropdown();
                this.updateUnreadCount();
                this.showToast('Notification marked as read', 'success');
            }
        } catch (error) {
            console.error('Error marking notification as read:', error);
        }
    }

    async markAllAsRead() {
        try {
            const response = await fetch('/kya-food-production/api/notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'mark_all_read' })
            });

            const data = await response.json();
            
            if (data.success) {
                this.notifications.forEach(n => n.is_read = true);
                this.unreadCount = 0;
                
                this.updateNotificationDropdown();
                this.updateUnreadBadge();
                this.showToast(`${data.count} notifications marked as read`, 'success');
            }
        } catch (error) {
            console.error('Error marking all notifications as read:', error);
        }
    }

    updateNotificationDropdown() {
        const list = document.querySelector('.notification-list');
        if (!list) return;

        if (this.notifications.length === 0) {
            list.innerHTML = `
                <div class="notification-empty">
                    <div class="text-center py-4">
                        <i class="fas fa-bell-slash fa-2x text-muted mb-2"></i>
                        <div class="text-muted">No notifications</div>
                    </div>
                </div>
            `;
            return;
        }

        const notificationItems = this.notifications.map(notification => {
            return this.createNotificationItem(notification);
        }).join('');

        list.innerHTML = notificationItems;
    }

    createNotificationItem(notification) {
        const isUnread = !notification.is_read;
        const priorityClass = this.getPriorityClass(notification.priority);
        const timeAgo = this.formatTimeAgo(notification.created_at);
        
        return `
            <div class="notification-item ${isUnread ? 'unread' : ''}" data-notification-id="${notification.id}">
                <div class="notification-content">
                    <div class="notification-header">
                        <span class="notification-priority badge badge-${priorityClass}">${notification.priority}</span>
                        <span class="notification-time">${timeAgo}</span>
                    </div>
                    <div class="notification-title">${this.escapeHtml(notification.title)}</div>
                    <div class="notification-message">${this.escapeHtml(notification.message)}</div>
                </div>
                <div class="notification-controls">
                    ${isUnread ? `
                        <button class="btn btn-sm btn-link mark-read-btn" 
                                data-notification-id="${notification.id}" title="Mark as read">
                            <i class="fas fa-check"></i>
                        </button>
                    ` : ''}
                </div>
            </div>
        `;
    }

    updateUnreadCount() {
        this.unreadCount = this.notifications.filter(n => !n.is_read).length;
        this.updateUnreadBadge();
    }

    updateUnreadBadge() {
        const badge = document.querySelector('.notification-badge');
        if (badge) {
            badge.textContent = this.unreadCount;
            badge.style.display = this.unreadCount > 0 ? 'inline-block' : 'none';
        }
    }

    toggleNotificationDropdown() {
        const dropdown = document.querySelector('.notification-dropdown');
        if (dropdown) {
            dropdown.classList.toggle('show');
            
            if (dropdown.classList.contains('show')) {
                this.loadInitialNotifications();
            }
        }
    }

    closeNotificationDropdown() {
        const dropdown = document.querySelector('.notification-dropdown');
        if (dropdown) {
            dropdown.classList.remove('show');
        }
    }

    showNewNotificationAlert(count) {
        const alert = document.createElement('div');
        alert.className = 'notification-alert';
        alert.innerHTML = `
            <div class="alert alert-info alert-dismissible fade show">
                <i class="fas fa-bell me-2"></i>
                You have ${count} new notification${count > 1 ? 's' : ''}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        
        alert.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1060;
            max-width: 350px;
        `;
        
        document.body.appendChild(alert);
        
        setTimeout(() => {
            if (alert.parentNode) {
                alert.remove();
            }
        }, 5000);
    }

    getPriorityClass(priority) {
        const classes = {
            'critical': 'danger',
            'high': 'warning',
            'medium': 'info',
            'low': 'secondary'
        };
        return classes[priority] || 'secondary';
    }

    formatTimeAgo(dateString) {
        const now = new Date();
        const date = new Date(dateString);
        const diffInSeconds = Math.floor((now - date) / 1000);
        
        if (diffInSeconds < 60) return 'Just now';
        if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)}m ago`;
        if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)}h ago`;
        return `${Math.floor(diffInSeconds / 86400)}d ago`;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-white bg-${type === 'error' ? 'danger' : type} border-0`;
        toast.setAttribute('role', 'alert');
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        
        document.body.appendChild(toast);
        
        const bsToast = new bootstrap.Toast(toast);
        bsToast.show();
        
        toast.addEventListener('hidden.bs.toast', () => {
            toast.remove();
        });
    }

    destroy() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
        }
        this.isPolling = false;
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.notificationManager = new NotificationManager();
});

window.NotificationManager = NotificationManager;
