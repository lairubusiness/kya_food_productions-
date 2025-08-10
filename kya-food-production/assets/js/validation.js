/**
 * KYA Food Production - Form Validation JavaScript
 * Handles client-side form validation and user input validation
 */

class FormValidator {
    constructor() {
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.initializeBootstrapValidation();
        this.setupCustomValidators();
    }

    setupEventListeners() {
        // Form submission validation
        document.addEventListener('submit', (e) => {
            if (e.target.matches('.needs-validation')) {
                this.handleFormSubmission(e);
            }
        });

        // Real-time validation
        document.addEventListener('input', (e) => {
            if (e.target.matches('.form-control, .form-select')) {
                this.validateField(e.target);
            }
        });

        // Password strength checking
        document.addEventListener('input', (e) => {
            if (e.target.matches('input[type="password"]')) {
                this.checkPasswordStrength(e.target);
            }
        });

        // Confirm password validation
        document.addEventListener('input', (e) => {
            if (e.target.matches('.confirm-password')) {
                this.validatePasswordConfirmation(e.target);
            }
        });

        // Email validation
        document.addEventListener('blur', (e) => {
            if (e.target.matches('input[type="email"]')) {
                this.validateEmail(e.target);
            }
        });

        // Phone number formatting
        document.addEventListener('input', (e) => {
            if (e.target.matches('.phone-input')) {
                this.formatPhoneNumber(e.target);
            }
        });

        // Numeric input validation
        document.addEventListener('input', (e) => {
            if (e.target.matches('.numeric-input')) {
                this.validateNumericInput(e.target);
            }
        });

        // Date validation
        document.addEventListener('change', (e) => {
            if (e.target.matches('input[type="date"]')) {
                this.validateDate(e.target);
            }
        });
    }

    initializeBootstrapValidation() {
        // Enable Bootstrap validation styles
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

    setupCustomValidators() {
        // Custom validation rules
        this.validators = {
            required: (value) => value.trim() !== '',
            email: (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value),
            phone: (value) => /^[\+]?[1-9][\d]{0,15}$/.test(value.replace(/\s/g, '')),
            password: (value) => value.length >= 8,
            strongPassword: (value) => {
                return /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/.test(value);
            },
            numeric: (value) => /^\d*\.?\d+$/.test(value),
            positiveNumber: (value) => parseFloat(value) > 0,
            itemCode: (value) => /^[A-Z]{2}\d{3}$/.test(value),
            batchNumber: (value) => /^[A-Z]{2}\d{7}$/.test(value)
        };
    }

    handleFormSubmission(e) {
        const form = e.target;
        let isValid = true;

        // Validate all form fields
        const fields = form.querySelectorAll('.form-control, .form-select');
        fields.forEach(field => {
            if (!this.validateField(field)) {
                isValid = false;
            }
        });

        // Custom form-specific validations
        if (form.classList.contains('inventory-form')) {
            isValid = this.validateInventoryForm(form) && isValid;
        }

        if (form.classList.contains('user-form')) {
            isValid = this.validateUserForm(form) && isValid;
        }

        if (form.classList.contains('order-form')) {
            isValid = this.validateOrderForm(form) && isValid;
        }

        if (!isValid) {
            e.preventDefault();
            e.stopPropagation();
            this.showValidationErrors(form);
        }

        form.classList.add('was-validated');
    }

    validateField(field) {
        const value = field.value.trim();
        const rules = field.dataset.validate ? field.dataset.validate.split('|') : [];
        let isValid = true;
        let errorMessage = '';

        // Check HTML5 validity first
        if (!field.checkValidity()) {
            isValid = false;
            errorMessage = field.validationMessage;
        }

        // Check custom validation rules
        rules.forEach(rule => {
            if (!isValid) return;

            const [ruleName, ruleParam] = rule.split(':');
            
            if (this.validators[ruleName]) {
                if (!this.validators[ruleName](value, ruleParam)) {
                    isValid = false;
                    errorMessage = this.getErrorMessage(ruleName, field.name, ruleParam);
                }
            }
        });

        // Update field appearance
        this.updateFieldValidation(field, isValid, errorMessage);
        
        return isValid;
    }

    validateInventoryForm(form) {
        let isValid = true;

        // Validate quantity vs thresholds
        const quantity = parseFloat(form.querySelector('[name="quantity"]')?.value || 0);
        const minThreshold = parseFloat(form.querySelector('[name="min_threshold"]')?.value || 0);
        const maxThreshold = parseFloat(form.querySelector('[name="max_threshold"]')?.value || 0);

        if (minThreshold >= maxThreshold) {
            this.showFieldError(form.querySelector('[name="max_threshold"]'), 
                'Maximum threshold must be greater than minimum threshold');
            isValid = false;
        }

        // Validate expiry date
        const expiryDate = form.querySelector('[name="expiry_date"]')?.value;
        if (expiryDate && new Date(expiryDate) <= new Date()) {
            this.showFieldError(form.querySelector('[name="expiry_date"]'), 
                'Expiry date must be in the future');
            isValid = false;
        }

        // Validate unit cost
        const unitCost = parseFloat(form.querySelector('[name="unit_cost"]')?.value || 0);
        if (unitCost < 0) {
            this.showFieldError(form.querySelector('[name="unit_cost"]'), 
                'Unit cost cannot be negative');
            isValid = false;
        }

        return isValid;
    }

    validateUserForm(form) {
        let isValid = true;

        // Validate password confirmation
        const password = form.querySelector('[name="password"]')?.value;
        const confirmPassword = form.querySelector('[name="confirm_password"]')?.value;

        if (password && confirmPassword && password !== confirmPassword) {
            this.showFieldError(form.querySelector('[name="confirm_password"]'), 
                'Passwords do not match');
            isValid = false;
        }

        // Validate email uniqueness (would need server-side check in real implementation)
        const email = form.querySelector('[name="email"]')?.value;
        if (email && !this.validators.email(email)) {
            this.showFieldError(form.querySelector('[name="email"]'), 
                'Please enter a valid email address');
            isValid = false;
        }

        return isValid;
    }

    validateOrderForm(form) {
        let isValid = true;

        // Validate required date
        const orderDate = new Date(form.querySelector('[name="order_date"]')?.value);
        const requiredDate = new Date(form.querySelector('[name="required_date"]')?.value);

        if (requiredDate <= orderDate) {
            this.showFieldError(form.querySelector('[name="required_date"]'), 
                'Required date must be after order date');
            isValid = false;
        }

        // Validate total amount
        const totalAmount = parseFloat(form.querySelector('[name="total_amount"]')?.value || 0);
        if (totalAmount <= 0) {
            this.showFieldError(form.querySelector('[name="total_amount"]'), 
                'Total amount must be greater than zero');
            isValid = false;
        }

        return isValid;
    }

    checkPasswordStrength(field) {
        const password = field.value;
        const strengthIndicator = field.parentNode.querySelector('.password-strength');
        
        if (!strengthIndicator) return;

        let strength = 0;
        let feedback = [];

        // Length check
        if (password.length >= 8) strength++;
        else feedback.push('At least 8 characters');

        // Lowercase check
        if (/[a-z]/.test(password)) strength++;
        else feedback.push('One lowercase letter');

        // Uppercase check
        if (/[A-Z]/.test(password)) strength++;
        else feedback.push('One uppercase letter');

        // Number check
        if (/\d/.test(password)) strength++;
        else feedback.push('One number');

        // Special character check
        if (/[@$!%*?&]/.test(password)) strength++;
        else feedback.push('One special character');

        // Update strength indicator
        const strengthLevels = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong'];
        const strengthColors = ['danger', 'warning', 'info', 'primary', 'success'];
        
        strengthIndicator.className = `password-strength text-${strengthColors[strength - 1] || 'muted'}`;
        strengthIndicator.textContent = password ? strengthLevels[strength - 1] || 'Very Weak' : '';

        // Show feedback
        const feedbackElement = field.parentNode.querySelector('.password-feedback');
        if (feedbackElement && feedback.length > 0) {
            feedbackElement.innerHTML = `<small class="text-muted">Missing: ${feedback.join(', ')}</small>`;
        }
    }

    validatePasswordConfirmation(field) {
        const password = document.querySelector('[name="password"]')?.value;
        const confirmPassword = field.value;
        
        if (confirmPassword && password !== confirmPassword) {
            this.showFieldError(field, 'Passwords do not match');
            return false;
        } else {
            this.clearFieldError(field);
            return true;
        }
    }

    validateEmail(field) {
        const email = field.value.trim();
        
        if (email && !this.validators.email(email)) {
            this.showFieldError(field, 'Please enter a valid email address');
            return false;
        } else {
            this.clearFieldError(field);
            return true;
        }
    }

    formatPhoneNumber(field) {
        let value = field.value.replace(/\D/g, '');
        
        // Format as +1 (234) 567-8900
        if (value.length >= 10) {
            value = value.replace(/(\d{1})(\d{3})(\d{3})(\d{4})/, '+$1 ($2) $3-$4');
        } else if (value.length >= 7) {
            value = value.replace(/(\d{3})(\d{3})(\d+)/, '($1) $2-$3');
        } else if (value.length >= 4) {
            value = value.replace(/(\d{3})(\d+)/, '($1) $2');
        }
        
        field.value = value;
    }

    validateNumericInput(field) {
        const value = field.value;
        
        if (value && !this.validators.numeric(value)) {
            this.showFieldError(field, 'Please enter a valid number');
            return false;
        } else {
            this.clearFieldError(field);
            return true;
        }
    }

    validateDate(field) {
        const date = new Date(field.value);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        if (field.classList.contains('future-date') && date <= today) {
            this.showFieldError(field, 'Date must be in the future');
            return false;
        }
        
        if (field.classList.contains('past-date') && date >= today) {
            this.showFieldError(field, 'Date must be in the past');
            return false;
        }
        
        this.clearFieldError(field);
        return true;
    }

    updateFieldValidation(field, isValid, errorMessage) {
        if (isValid) {
            field.classList.remove('is-invalid');
            field.classList.add('is-valid');
            this.clearFieldError(field);
        } else {
            field.classList.remove('is-valid');
            field.classList.add('is-invalid');
            this.showFieldError(field, errorMessage);
        }
    }

    showFieldError(field, message) {
        this.clearFieldError(field);
        
        const errorDiv = document.createElement('div');
        errorDiv.className = 'invalid-feedback';
        errorDiv.textContent = message;
        
        field.parentNode.appendChild(errorDiv);
        field.classList.add('is-invalid');
    }

    clearFieldError(field) {
        const errorDiv = field.parentNode.querySelector('.invalid-feedback');
        if (errorDiv) {
            errorDiv.remove();
        }
        field.classList.remove('is-invalid');
    }

    showValidationErrors(form) {
        const invalidFields = form.querySelectorAll('.is-invalid');
        
        if (invalidFields.length > 0) {
            // Scroll to first invalid field
            invalidFields[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            invalidFields[0].focus();
            
            // Show summary alert
            this.showAlert(`Please correct ${invalidFields.length} error${invalidFields.length > 1 ? 's' : ''} in the form`, 'danger');
        }
    }

    getErrorMessage(ruleName, fieldName, param) {
        const messages = {
            required: `${this.formatFieldName(fieldName)} is required`,
            email: 'Please enter a valid email address',
            phone: 'Please enter a valid phone number',
            password: 'Password must be at least 8 characters long',
            strongPassword: 'Password must contain uppercase, lowercase, number, and special character',
            numeric: 'Please enter a valid number',
            positiveNumber: 'Please enter a positive number',
            itemCode: 'Item code must be in format: AB123',
            batchNumber: 'Batch number must be in format: AB1234567'
        };
        
        return messages[ruleName] || `Invalid ${this.formatFieldName(fieldName)}`;
    }

    formatFieldName(fieldName) {
        return fieldName.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    }

    showAlert(message, type = 'info') {
        const alertContainer = document.querySelector('.alert-container') || document.body;
        
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        alertContainer.appendChild(alert);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (alert.parentNode) {
                alert.remove();
            }
        }, 5000);
    }

    // Utility methods for external use
    validateForm(formSelector) {
        const form = document.querySelector(formSelector);
        if (form) {
            return this.handleFormSubmission({ target: form, preventDefault: () => {}, stopPropagation: () => {} });
        }
        return false;
    }

    resetForm(formSelector) {
        const form = document.querySelector(formSelector);
        if (form) {
            form.reset();
            form.classList.remove('was-validated');
            
            // Clear all validation states
            const fields = form.querySelectorAll('.form-control, .form-select');
            fields.forEach(field => {
                field.classList.remove('is-valid', 'is-invalid');
                this.clearFieldError(field);
            });
        }
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.formValidator = new FormValidator();
});

// Export for use in other modules
window.FormValidator = FormValidator;
