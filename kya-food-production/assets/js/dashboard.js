/**
 * KYA Food Production - Dashboard JavaScript
 * Handles dashboard-specific functionality including charts, real-time updates, and interactions
 */

class Dashboard {
    constructor() {
        this.charts = {};
        this.updateInterval = null;
        this.init();
    }

    init() {
        this.initializeCharts();
        this.setupEventListeners();
        this.startRealTimeUpdates();
        this.setupNotificationPolling();
    }

    initializeCharts() {
        // Initialize Inventory Chart
        this.initInventoryChart();
        
        // Initialize Processing Chart
        this.initProcessingChart();
        
        // Initialize Orders Chart (if admin)
        if (this.isAdmin()) {
            this.initOrdersChart();
        }
        
        // Initialize Alerts Chart
        this.initAlertsChart();
    }

    initInventoryChart() {
        const ctx = document.getElementById('inventoryChart');
        if (!ctx) return;

        this.charts.inventory = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: [],
                datasets: [{
                    data: [],
                    backgroundColor: [
                        '#2c5f41',
                        '#4a8b3a', 
                        '#ff6b35',
                        '#17a2b8',
                        '#ffc107'
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                return `${label}: $${value.toLocaleString()}`;
                            }
                        }
                    }
                },
                animation: {
                    animateScale: true,
                    animateRotate: true
                }
            }
        });

        this.loadInventoryData();
    }

    initProcessingChart() {
        const ctx = document.getElementById('processingChart');
        if (!ctx) return;

        this.charts.processing = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Daily Output (kg)',
                    data: [],
                    borderColor: '#4a8b3a',
                    backgroundColor: 'rgba(74, 139, 58, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#4a8b3a',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        borderColor: '#4a8b3a',
                        borderWidth: 1
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#6c757d'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        },
                        ticks: {
                            color: '#6c757d',
                            callback: function(value) {
                                return value + ' kg';
                            }
                        }
                    }
                },
                interaction: {
                    mode: 'nearest',
                    axis: 'x',
                    intersect: false
                }
            }
        });

        this.loadProcessingData();
    }

    initOrdersChart() {
        const ctx = document.getElementById('ordersChart');
        if (!ctx) return;

        this.charts.orders = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: [],
                datasets: [{
                    label: 'Orders',
                    data: [],
                    backgroundColor: '#17a2b8',
                    borderColor: '#17a2b8',
                    borderWidth: 1,
                    borderRadius: 4,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff'
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#6c757d'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        },
                        ticks: {
                            color: '#6c757d',
                            stepSize: 1
                        }
                    }
                }
            }
        });

        this.loadOrdersData();
    }

    initAlertsChart() {
        const ctx = document.getElementById('alertsChart');
        if (!ctx) return;

        this.charts.alerts = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: [],
                datasets: [{
                    data: [],
                    backgroundColor: [
                        '#ffc107',
                        '#dc3545',
                        '#6c757d',
                        '#fd7e14'
                    ],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            usePointStyle: true
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                return `${label}: ${value} items`;
                            }
                        }
                    }
                }
            }
        });

        this.loadAlertsData();
    }

    async loadInventoryData() {
        try {
            const response = await fetch('/kya-food-production/api/dashboard_stats.php?action=get_chart_data&chart=inventory');
            const data = await response.json();
            
            if (data.success && this.charts.inventory) {
                this.charts.inventory.data.labels = data.labels;
                this.charts.inventory.data.datasets[0].data = data.values;
                this.charts.inventory.data.datasets[0].backgroundColor = data.colors;
                this.charts.inventory.update('none');
            }
        } catch (error) {
            console.error('Error loading inventory data:', error);
        }
    }

    async loadProcessingData() {
        try {
            const response = await fetch('/kya-food-production/api/dashboard_stats.php?action=get_chart_data&chart=processing');
            const data = await response.json();
            
            if (data.success && this.charts.processing) {
                this.charts.processing.data.labels = data.labels;
                this.charts.processing.data.datasets[0].data = data.values;
                this.charts.processing.update('none');
            }
        } catch (error) {
            console.error('Error loading processing data:', error);
        }
    }

    async loadOrdersData() {
        try {
            const response = await fetch('/kya-food-production/api/dashboard_stats.php?action=get_chart_data&chart=orders');
            const data = await response.json();
            
            if (data.success && this.charts.orders) {
                this.charts.orders.data.labels = data.labels;
                this.charts.orders.data.datasets[0].data = data.values;
                this.charts.orders.update('none');
            }
        } catch (error) {
            console.error('Error loading orders data:', error);
        }
    }

    async loadAlertsData() {
        try {
            const response = await fetch('/kya-food-production/api/dashboard_stats.php?action=get_chart_data&chart=alerts');
            const data = await response.json();
            
            if (data.success && this.charts.alerts) {
                this.charts.alerts.data.labels = data.labels;
                this.charts.alerts.data.datasets[0].data = data.values;
                this.charts.alerts.data.datasets[0].backgroundColor = data.colors;
                this.charts.alerts.update('none');
            }
        } catch (error) {
            console.error('Error loading alerts data:', error);
        }
    }

    async updateDashboardStats() {
        try {
            const response = await fetch('/kya-food-production/api/dashboard_stats.php?action=get_stats');
            const data = await response.json();
            
            if (data.success) {
                this.updateStatsCards(data.stats);
                this.updateNotificationCount(data.stats.notifications);
            }
        } catch (error) {
            console.error('Error updating dashboard stats:', error);
        }
    }

    updateStatsCards(stats) {
        // Update inventory summary
        if (stats.inventory_summary) {
            this.updateStatCard('total-items', stats.inventory_summary.total_items);
            this.updateStatCard('total-value', this.formatCurrency(stats.inventory_summary.total_value));
            this.updateStatCard('alert-items', stats.inventory_summary.low_stock_items);
            this.updateStatCard('expiring-items', stats.inventory_summary.expiring_items);
        }

        // Update order summary (if admin)
        if (stats.order_summary) {
            this.updateStatCard('total-orders', stats.order_summary.total_orders);
            this.updateStatCard('order-value', this.formatCurrency(stats.order_summary.total_value));
            this.updateStatCard('active-orders', stats.order_summary.active_orders);
            this.updateStatCard('completed-orders', stats.order_summary.completed_orders);
        }
    }

    updateStatCard(cardId, value) {
        const element = document.querySelector(`[data-stat="${cardId}"]`);
        if (element) {
            element.textContent = value;
            element.classList.add('updated');
            setTimeout(() => element.classList.remove('updated'), 1000);
        }
    }

    updateNotificationCount(notifications) {
        const badge = document.querySelector('.notification-badge');
        if (badge && notifications) {
            const count = notifications.unread_notifications || 0;
            badge.textContent = count;
            badge.style.display = count > 0 ? 'inline-block' : 'none';
        }
    }

    setupEventListeners() {
        // Refresh button
        document.addEventListener('click', (e) => {
            if (e.target.matches('.refresh-dashboard')) {
                e.preventDefault();
                this.refreshDashboard();
            }
        });

        // Chart period selectors
        document.addEventListener('change', (e) => {
            if (e.target.matches('.chart-period-selector')) {
                const chartType = e.target.dataset.chart;
                const period = e.target.value;
                this.updateChartPeriod(chartType, period);
            }
        });

        // Export buttons
        document.addEventListener('click', (e) => {
            if (e.target.matches('.export-chart')) {
                e.preventDefault();
                const chartType = e.target.dataset.chart;
                this.exportChart(chartType);
            }
        });

        // Quick action cards
        document.addEventListener('click', (e) => {
            if (e.target.closest('.quick-action-card')) {
                const card = e.target.closest('.quick-action-card');
                card.classList.add('clicked');
                setTimeout(() => card.classList.remove('clicked'), 200);
            }
        });
    }

    startRealTimeUpdates() {
        // Update dashboard every 5 minutes
        this.updateInterval = setInterval(() => {
            if (document.visibilityState === 'visible') {
                this.updateDashboardStats();
                this.refreshCharts();
            }
        }, 300000); // 5 minutes

        // Update on page visibility change
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.updateDashboardStats();
            }
        });
    }

    setupNotificationPolling() {
        // Poll for new notifications every 30 seconds
        setInterval(async () => {
            if (document.visibilityState === 'visible') {
                try {
                    const response = await fetch('/kya-food-production/api/notifications.php?action=get_unread_count');
                    const data = await response.json();
                    
                    if (data.success) {
                        this.updateNotificationCount({ unread_notifications: data.count });
                        
                        // Show notification if count increased
                        const currentCount = parseInt(localStorage.getItem('notificationCount') || '0');
                        if (data.count > currentCount) {
                            this.showNotificationAlert(data.count - currentCount);
                        }
                        localStorage.setItem('notificationCount', data.count.toString());
                    }
                } catch (error) {
                    console.error('Error polling notifications:', error);
                }
            }
        }, 30000); // 30 seconds
    }

    showNotificationAlert(newCount) {
        // Create a subtle notification alert
        const alert = document.createElement('div');
        alert.className = 'alert alert-info alert-dismissible fade show position-fixed';
        alert.style.cssText = 'top: 20px; right: 20px; z-index: 1060; max-width: 300px;';
        alert.innerHTML = `
            <i class="fas fa-bell me-2"></i>
            You have ${newCount} new notification${newCount > 1 ? 's' : ''}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(alert);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (alert.parentNode) {
                alert.remove();
            }
        }, 5000);
    }

    refreshDashboard() {
        this.showLoading();
        
        Promise.all([
            this.updateDashboardStats(),
            this.refreshCharts()
        ]).then(() => {
            this.hideLoading();
            this.showSuccessMessage('Dashboard refreshed successfully');
        }).catch((error) => {
            this.hideLoading();
            this.showErrorMessage('Failed to refresh dashboard');
            console.error('Dashboard refresh error:', error);
        });
    }

    refreshCharts() {
        const promises = [];
        
        if (this.charts.inventory) promises.push(this.loadInventoryData());
        if (this.charts.processing) promises.push(this.loadProcessingData());
        if (this.charts.orders) promises.push(this.loadOrdersData());
        if (this.charts.alerts) promises.push(this.loadAlertsData());
        
        return Promise.all(promises);
    }

    updateChartPeriod(chartType, period) {
        // Implementation for updating chart data based on selected period
        console.log(`Updating ${chartType} chart for period: ${period}`);
        // This would make an API call with the period parameter
    }

    exportChart(chartType) {
        const chart = this.charts[chartType];
        if (chart) {
            const url = chart.toBase64Image();
            const link = document.createElement('a');
            link.download = `${chartType}-chart.png`;
            link.href = url;
            link.click();
        }
    }

    showLoading() {
        const loader = document.querySelector('.dashboard-loader');
        if (loader) {
            loader.style.display = 'flex';
        }
    }

    hideLoading() {
        const loader = document.querySelector('.dashboard-loader');
        if (loader) {
            loader.style.display = 'none';
        }
    }

    showSuccessMessage(message) {
        this.showToast(message, 'success');
    }

    showErrorMessage(message) {
        this.showToast(message, 'error');
    }

    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-white bg-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'primary'} border-0`;
        toast.setAttribute('role', 'alert');
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    <i class="fas fa-${type === 'error' ? 'exclamation-circle' : type === 'success' ? 'check-circle' : 'info-circle'} me-2"></i>
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        
        const container = document.querySelector('.toast-container') || document.body;
        container.appendChild(toast);
        
        const bsToast = new bootstrap.Toast(toast);
        bsToast.show();
        
        toast.addEventListener('hidden.bs.toast', () => {
            toast.remove();
        });
    }

    formatCurrency(amount) {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD'
        }).format(amount || 0);
    }

    isAdmin() {
        return document.body.dataset.userRole === 'admin';
    }

    destroy() {
        // Clean up intervals and event listeners
        if (this.updateInterval) {
            clearInterval(this.updateInterval);
        }
        
        // Destroy charts
        Object.values(this.charts).forEach(chart => {
            if (chart) chart.destroy();
        });
        
        this.charts = {};
    }
}

// Initialize dashboard when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    if (document.querySelector('.dashboard-container')) {
        window.dashboard = new Dashboard();
    }
});

// Export for use in other modules
window.Dashboard = Dashboard;
