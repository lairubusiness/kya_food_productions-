/**
 * KYA Food Production - Inventory Management JavaScript
 * Handles inventory-specific functionality
 */

class InventoryManager {
    constructor() {
        this.currentFilters = {};
        this.selectedItems = new Set();
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.initializeDataTable();
        this.setupBulkActions();
        this.initializeFormValidation();
    }

    setupEventListeners() {
        // Filter form submission
        document.addEventListener('submit', (e) => {
            if (e.target.matches('.inventory-filter-form')) {
                e.preventDefault();
                this.applyFilters(new FormData(e.target));
            }
        });

        // Quick filter buttons
        document.addEventListener('click', (e) => {
            if (e.target.matches('.quick-filter')) {
                e.preventDefault();
                this.applyQuickFilter(e.target.dataset.filter, e.target.dataset.value);
            }
        });

        // Item selection
        document.addEventListener('change', (e) => {
            if (e.target.matches('.item-checkbox')) {
                this.handleItemSelection(e.target);
            }
            if (e.target.matches('.select-all-checkbox')) {
                this.handleSelectAll(e.target);
            }
        });

        // Export buttons
        document.addEventListener('click', (e) => {
            if (e.target.matches('.export-btn')) {
                e.preventDefault();
                this.exportData(e.target.dataset.format);
            }
        });

        // Add/Edit item modal
        document.addEventListener('click', (e) => {
            if (e.target.matches('.add-item-btn')) {
                this.openAddItemModal();
            }
            if (e.target.matches('.edit-item-btn')) {
                this.openEditItemModal(e.target.dataset.itemId);
            }
        });

        // Delete confirmation
        document.addEventListener('click', (e) => {
            if (e.target.matches('.delete-item-btn')) {
                e.preventDefault();
                this.confirmDelete(e.target.dataset.itemId);
            }
        });

        // Bulk actions
        document.addEventListener('click', (e) => {
            if (e.target.matches('.bulk-action-btn')) {
                e.preventDefault();
                this.executeBulkAction(e.target.dataset.action);
            }
        });
    }

    initializeDataTable() {
        const table = document.querySelector('.inventory-table');
        if (!table) return;

        this.dataTable = new DataTable(table, {
            responsive: true,
            pageLength: 25,
            order: [[0, 'desc']],
            columnDefs: [
                { targets: [0], orderable: false }, // Checkbox column
                { targets: [-1], orderable: false }, // Actions column
                { 
                    targets: [4, 5, 6], // Quantity, Cost, Value columns
                    className: 'text-end'
                }
            ],
            language: {
                search: "Search inventory:",
                lengthMenu: "Show _MENU_ items per page",
                info: "Showing _START_ to _END_ of _TOTAL_ items",
                infoEmpty: "No items found",
                infoFiltered: "(filtered from _MAX_ total items)",
                emptyTable: "No inventory items available",
                zeroRecords: "No matching items found"
            },
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                 '<"row"<"col-sm-12"tr>>' +
                 '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
            drawCallback: () => {
                this.updateBulkActionState();
            }
        });
    }

    applyFilters(formData) {
        this.currentFilters = Object.fromEntries(formData);
        
        // Show loading state
        this.showLoading();
        
        // Build query string
        const params = new URLSearchParams(this.currentFilters);
        
        // Update URL without page reload
        const newUrl = `${window.location.pathname}?${params.toString()}`;
        history.pushState(null, '', newUrl);
        
        // Reload table data
        this.reloadTableData();
    }

    applyQuickFilter(filterType, value) {
        const form = document.querySelector('.inventory-filter-form');
        if (form) {
            const input = form.querySelector(`[name="${filterType}"]`);
            if (input) {
                input.value = value;
                this.applyFilters(new FormData(form));
            }
        }
    }

    async reloadTableData() {
        try {
            const params = new URLSearchParams(this.currentFilters);
            const response = await fetch(`/kya-food-production/api/inventory.php?action=get_filtered&${params.toString()}`);
            const data = await response.json();
            
            if (data.success) {
                this.updateTableContent(data.items);
                this.updateSummaryStats(data.summary);
            } else {
                this.showError('Failed to load inventory data');
            }
        } catch (error) {
            console.error('Error reloading table data:', error);
            this.showError('Error loading data');
        } finally {
            this.hideLoading();
        }
    }

    updateTableContent(items) {
        if (this.dataTable) {
            this.dataTable.clear();
            
            items.forEach(item => {
                this.dataTable.row.add([
                    `<input type="checkbox" class="item-checkbox" value="${item.id}">`,
                    `<strong>${item.item_code}</strong>`,
                    `<div><strong>${item.item_name}</strong><br><small class="text-muted">${item.description || ''}</small></div>`,
                    `<span class="badge" style="background-color: ${this.getSectionColor(item.section)}">Section ${item.section}</span>`,
                    item.category,
                    `<strong>${this.formatNumber(item.quantity)}</strong> <small class="text-muted">${item.unit}</small>`,
                    item.unit_cost ? this.formatCurrency(item.unit_cost) : '-',
                    item.total_value ? this.formatCurrency(item.total_value) : '-',
                    this.getStatusBadge(item.status),
                    this.getAlertBadge(item.alert_status),
                    item.expiry_date ? this.formatDate(item.expiry_date) : '-',
                    this.getActionButtons(item.id)
                ]);
            });
            
            this.dataTable.draw();
        }
    }

    updateSummaryStats(summary) {
        if (summary) {
            this.updateStatElement('[data-stat="total-items"]', this.formatNumber(summary.total_items));
            this.updateStatElement('[data-stat="total-value"]', this.formatCurrency(summary.total_value));
            this.updateStatElement('[data-stat="alert-items"]', this.formatNumber(summary.alert_items));
            this.updateStatElement('[data-stat="expiring-items"]', this.formatNumber(summary.expiring_items));
        }
    }

    updateStatElement(selector, value) {
        const element = document.querySelector(selector);
        if (element) {
            element.textContent = value;
        }
    }

    handleItemSelection(checkbox) {
        const itemId = checkbox.value;
        
        if (checkbox.checked) {
            this.selectedItems.add(itemId);
        } else {
            this.selectedItems.delete(itemId);
        }
        
        this.updateBulkActionState();
    }

    handleSelectAll(checkbox) {
        const itemCheckboxes = document.querySelectorAll('.item-checkbox');
        
        itemCheckboxes.forEach(cb => {
            cb.checked = checkbox.checked;
            this.handleItemSelection(cb);
        });
    }

    updateBulkActionState() {
        const bulkActions = document.querySelector('.bulk-actions');
        const selectedCount = document.querySelector('.selected-count');
        
        if (bulkActions) {
            bulkActions.style.display = this.selectedItems.size > 0 ? 'block' : 'none';
        }
        
        if (selectedCount) {
            selectedCount.textContent = this.selectedItems.size;
        }
    }

    setupBulkActions() {
        // Bulk action dropdown
        const bulkActionSelect = document.querySelector('.bulk-action-select');
        if (bulkActionSelect) {
            bulkActionSelect.addEventListener('change', (e) => {
                const executeBtn = document.querySelector('.execute-bulk-action');
                if (executeBtn) {
                    executeBtn.disabled = !e.target.value;
                }
            });
        }
    }

    async executeBulkAction(action) {
        if (this.selectedItems.size === 0) {
            this.showWarning('Please select items first');
            return;
        }

        const confirmed = await this.confirmBulkAction(action, this.selectedItems.size);
        if (!confirmed) return;

        try {
            this.showLoading();
            
            const response = await fetch('/kya-food-production/api/inventory.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'bulk_action',
                    bulk_action: action,
                    item_ids: Array.from(this.selectedItems)
                })
            });

            const data = await response.json();
            
            if (data.success) {
                this.showSuccess(`Bulk action completed: ${data.message}`);
                this.reloadTableData();
                this.clearSelection();
            } else {
                this.showError(data.message || 'Bulk action failed');
            }
        } catch (error) {
            console.error('Bulk action error:', error);
            this.showError('Error executing bulk action');
        } finally {
            this.hideLoading();
        }
    }

    async confirmBulkAction(action, count) {
        const actionNames = {
            'activate': 'activate',
            'deactivate': 'deactivate',
            'delete': 'delete',
            'export': 'export'
        };

        const actionName = actionNames[action] || action;
        
        return confirm(`Are you sure you want to ${actionName} ${count} selected item${count > 1 ? 's' : ''}?`);
    }

    clearSelection() {
        this.selectedItems.clear();
        document.querySelectorAll('.item-checkbox').forEach(cb => cb.checked = false);
        document.querySelector('.select-all-checkbox').checked = false;
        this.updateBulkActionState();
    }

    async openAddItemModal() {
        const modal = document.querySelector('#addItemModal');
        if (modal) {
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();
        }
    }

    async openEditItemModal(itemId) {
        try {
            const response = await fetch(`/kya-food-production/api/inventory.php?action=get_item&id=${itemId}`);
            const data = await response.json();
            
            if (data.success) {
                this.populateEditForm(data.item);
                const modal = document.querySelector('#editItemModal');
                if (modal) {
                    const bsModal = new bootstrap.Modal(modal);
                    bsModal.show();
                }
            } else {
                this.showError('Failed to load item data');
            }
        } catch (error) {
            console.error('Error loading item:', error);
            this.showError('Error loading item data');
        }
    }

    populateEditForm(item) {
        const form = document.querySelector('#editItemForm');
        if (!form) return;

        Object.keys(item).forEach(key => {
            const input = form.querySelector(`[name="${key}"]`);
            if (input) {
                input.value = item[key] || '';
            }
        });
    }

    async confirmDelete(itemId) {
        const confirmed = confirm('Are you sure you want to delete this item? This action cannot be undone.');
        if (!confirmed) return;

        try {
            this.showLoading();
            
            const response = await fetch('/kya-food-production/api/inventory.php', {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'delete',
                    id: itemId
                })
            });

            const data = await response.json();
            
            if (data.success) {
                this.showSuccess('Item deleted successfully');
                this.reloadTableData();
            } else {
                this.showError(data.message || 'Failed to delete item');
            }
        } catch (error) {
            console.error('Delete error:', error);
            this.showError('Error deleting item');
        } finally {
            this.hideLoading();
        }
    }

    async exportData(format) {
        try {
            const params = new URLSearchParams(this.currentFilters);
            params.set('export', format);
            
            const response = await fetch(`/kya-food-production/modules/inventory/export_data.php?${params.toString()}`);
            
            if (response.ok) {
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `inventory_export_${new Date().toISOString().split('T')[0]}.${format}`;
                a.click();
                window.URL.revokeObjectURL(url);
                
                this.showSuccess(`Inventory data exported as ${format.toUpperCase()}`);
            } else {
                this.showError('Export failed');
            }
        } catch (error) {
            console.error('Export error:', error);
            this.showError('Error exporting data');
        }
    }

    initializeFormValidation() {
        const forms = document.querySelectorAll('.needs-validation');
        forms.forEach(form => {
            form.addEventListener('submit', (e) => {
                if (!form.checkValidity()) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                form.classList.add('was-validated');
            });
        });
    }

    // Utility methods
    getSectionColor(section) {
        const colors = {
            1: '#2c5f41',
            2: '#4a8b3a',
            3: '#ff6b35'
        };
        return colors[section] || '#6c757d';
    }

    getStatusBadge(status) {
        const statusConfig = {
            'active': { class: 'success', text: 'Active' },
            'inactive': { class: 'secondary', text: 'Inactive' },
            'expired': { class: 'danger', text: 'Expired' },
            'damaged': { class: 'warning', text: 'Damaged' },
            'recalled': { class: 'dark', text: 'Recalled' }
        };
        
        const config = statusConfig[status] || { class: 'secondary', text: status };
        return `<span class="badge bg-${config.class}">${config.text}</span>`;
    }

    getAlertBadge(alertStatus) {
        if (alertStatus === 'normal') {
            return '<span class="badge bg-success">Normal</span>';
        }
        
        const alertConfig = {
            'low_stock': { class: 'warning', text: 'Low Stock' },
            'critical': { class: 'danger', text: 'Critical' },
            'expired': { class: 'dark', text: 'Expired' },
            'expiring_soon': { class: 'warning', text: 'Expiring Soon' }
        };
        
        const config = alertConfig[alertStatus] || { class: 'secondary', text: alertStatus };
        return `<span class="badge bg-${config.class}">${config.text}</span>`;
    }

    getActionButtons(itemId) {
        return `
            <div class="btn-group" role="group">
                <a href="view_item.php?id=${itemId}" class="btn btn-sm btn-outline-primary" title="View">
                    <i class="fas fa-eye"></i>
                </a>
                <button type="button" class="btn btn-sm btn-outline-secondary edit-item-btn" 
                        data-item-id="${itemId}" title="Edit">
                    <i class="fas fa-edit"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger delete-item-btn" 
                        data-item-id="${itemId}" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;
    }

    formatNumber(value) {
        return new Intl.NumberFormat().format(value || 0);
    }

    formatCurrency(value) {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD'
        }).format(value || 0);
    }

    formatDate(dateString) {
        return new Date(dateString).toLocaleDateString();
    }

    showLoading() {
        const loader = document.querySelector('.loading-overlay');
        if (loader) loader.style.display = 'flex';
    }

    hideLoading() {
        const loader = document.querySelector('.loading-overlay');
        if (loader) loader.style.display = 'none';
    }

    showSuccess(message) {
        this.showAlert(message, 'success');
    }

    showError(message) {
        this.showAlert(message, 'danger');
    }

    showWarning(message) {
        this.showAlert(message, 'warning');
    }

    showAlert(message, type) {
        const alertContainer = document.querySelector('.alert-container');
        if (!alertContainer) return;

        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        alertContainer.appendChild(alert);
        
        setTimeout(() => {
            if (alert.parentNode) {
                alert.remove();
            }
        }, 5000);
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    if (document.querySelector('.inventory-container')) {
        window.inventoryManager = new InventoryManager();
    }
});

window.InventoryManager = InventoryManager;
