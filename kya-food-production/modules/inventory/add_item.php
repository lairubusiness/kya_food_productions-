<?php
/**
 * KYA Food Production - Add Inventory Item
 * Form to add new inventory items to the system
 */

require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

SessionManager::start();
SessionManager::requireLogin();

// Check if user has inventory management permissions
if (!SessionManager::hasPermission('inventory_manage')) {
    header('Location: ../../dashboard.php?error=access_denied');
    exit();
}

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

$errors = [];
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate input
    $section = sanitizeInput($_POST['section'] ?? '');
    $item_code = sanitizeInput($_POST['item_code'] ?? '');
    $item_name = sanitizeInput($_POST['item_name'] ?? '');
    $category = sanitizeInput($_POST['category'] ?? '');
    $subcategory = sanitizeInput($_POST['subcategory'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? '');
    $quantity = floatval($_POST['quantity'] ?? 0);
    $unit = sanitizeInput($_POST['unit'] ?? '');
    $unit_cost = !empty($_POST['unit_cost']) ? floatval($_POST['unit_cost']) : null;
    $min_threshold = floatval($_POST['min_threshold'] ?? 0);
    $max_threshold = floatval($_POST['max_threshold'] ?? 0);
    $reorder_level = !empty($_POST['reorder_level']) ? floatval($_POST['reorder_level']) : null;
    $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    $manufacture_date = !empty($_POST['manufacture_date']) ? $_POST['manufacture_date'] : null;
    $batch_number = sanitizeInput($_POST['batch_number'] ?? '');
    $supplier_id = !empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : null;
    $storage_location = sanitizeInput($_POST['storage_location'] ?? '');
    $storage_temperature = !empty($_POST['storage_temperature']) ? floatval($_POST['storage_temperature']) : null;
    $storage_humidity = !empty($_POST['storage_humidity']) ? floatval($_POST['storage_humidity']) : null;
    $quality_grade = sanitizeInput($_POST['quality_grade'] ?? 'A');
    $status = sanitizeInput($_POST['status'] ?? 'active');
    $notes = sanitizeInput($_POST['notes'] ?? '');

    // Validation
    if (empty($section)) {
        $errors[] = "Section is required";
    } elseif ($userInfo['role'] !== 'admin' && !in_array($section, $userInfo['sections'])) {
        $errors[] = "You don't have permission to add items to this section";
    }

    if (empty($item_code)) {
        $errors[] = "Item code is required";
    } else {
        // Check if item code already exists
        try {
            $stmt = $conn->prepare("SELECT id FROM inventory WHERE item_code = ?");
            $stmt->execute([$item_code]);
            if ($stmt->fetch()) {
                $errors[] = "Item code already exists";
            }
        } catch (Exception $e) {
            $errors[] = "Error checking item code uniqueness";
        }
    }

    if (empty($item_name)) {
        $errors[] = "Item name is required";
    }

    if (empty($category)) {
        $errors[] = "Category is required";
    }

    if ($quantity < 0) {
        $errors[] = "Quantity cannot be negative";
    }

    if (empty($unit)) {
        $errors[] = "Unit is required";
    }

    if ($min_threshold < 0) {
        $errors[] = "Minimum threshold cannot be negative";
    }

    if ($max_threshold <= 0) {
        $errors[] = "Maximum threshold must be greater than 0";
    }

    if ($min_threshold >= $max_threshold) {
        $errors[] = "Minimum threshold must be less than maximum threshold";
    }

    if ($expiry_date && $manufacture_date && strtotime($expiry_date) <= strtotime($manufacture_date)) {
        $errors[] = "Expiry date must be after manufacture date";
    }

    // If no errors, insert the item
    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO inventory (
                    section, item_code, item_name, category, subcategory, description,
                    quantity, unit, unit_cost, min_threshold, max_threshold, reorder_level,
                    expiry_date, manufacture_date, batch_number, supplier_id,
                    storage_location, storage_temperature, storage_humidity,
                    quality_grade, status, notes, created_by
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            $stmt->execute([
                $section, $item_code, $item_name, $category, $subcategory, $description,
                $quantity, $unit, $unit_cost, $min_threshold, $max_threshold, $reorder_level,
                $expiry_date, $manufacture_date, $batch_number, $supplier_id,
                $storage_location, $storage_temperature, $storage_humidity,
                $quality_grade, $status, $notes, $userInfo['id']
            ]);

            $itemId = $conn->lastInsertId();

            // Check for alerts after adding the item
            checkInventoryAlerts($itemId);

            // Log the activity
            logActivity('inventory_add', "Added new inventory item: $item_name (Code: $item_code)", $userInfo['id']);

            // Create notification
            createNotification(
                $userInfo['id'],
                $section,
                'medium',
                'success',
                'New Item Added',
                "Successfully added new inventory item: $item_name",
                false,
                "modules/inventory/view_item.php?id=$itemId"
            );

            $success = true;
            
            // Redirect to inventory list after successful addition
            header('Location: index.php?success=item_added');
            exit();

        } catch (Exception $e) {
            error_log("Error adding inventory item: " . $e->getMessage());
            $errors[] = "Failed to add inventory item. Please try again.";
        }
    }
}

// Get categories for dropdown
try {
    $categoriesStmt = $conn->prepare("SELECT DISTINCT category FROM inventory ORDER BY category");
    $categoriesStmt->execute();
    $categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $categories = [];
}

// Get suppliers for dropdown
try {
    $suppliersStmt = $conn->prepare("SELECT id, name FROM suppliers ORDER BY name");
    $suppliersStmt->execute();
    $suppliers = $suppliersStmt->fetchAll();
} catch (Exception $e) {
    $suppliers = [];
}

$pageTitle = 'Add New Inventory Item';
include '../../includes/header.php';
?>

<style>
    /* Fix form contrast */
    .form-label {
        color: #212529 !important;
        font-weight: 500;
    }
    
    .form-control,
    .form-select {
        background-color: #ffffff;
        border: 1px solid #ced4da;
        color: #212529;
    }
    
    .form-control:focus,
    .form-select:focus {
        background-color: #ffffff;
        border-color: #86b7fe;
        color: #212529;
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
    }
    
    /* Fix card text contrast */
    .card {
        background-color: #ffffff;
        color: #212529;
        border: 1px solid #dee2e6;
    }
    
    .card-header {
        background-color: #f8f9fa;
        color: #212529;
        border-bottom: 1px solid #dee2e6;
    }
    
    .card-body {
        color: #212529;
        background-color: #ffffff;
    }
    
    /* Fix text colors */
    .text-muted {
        color: #6c757d !important;
    }
    
    .text-danger {
        color: #dc3545 !important;
    }
    
    .text-success {
        color: #198754 !important;
    }
    
    /* Fix button contrast */
    .btn-primary {
        background-color: #0d6efd;
        border-color: #0d6efd;
        color: #ffffff;
    }
    
    .btn-primary:hover {
        background-color: #0b5ed7;
        border-color: #0a58ca;
        color: #ffffff;
    }
    
    .btn-secondary {
        background-color: #6c757d;
        border-color: #6c757d;
        color: #ffffff;
    }
    
    .btn-secondary:hover {
        background-color: #5c636a;
        border-color: #565e64;
        color: #ffffff;
    }
    
    /* Fix alert contrast */
    .alert {
        border: 1px solid transparent;
        border-radius: 0.375rem;
    }
    
    .alert-success {
        background-color: #d1e7dd;
        border-color: #badbcc;
        color: #0f5132;
    }
    
    .alert-danger {
        background-color: #f8d7da;
        border-color: #f5c2c7;
        color: #842029;
    }
    
    .alert-warning {
        background-color: #fff3cd;
        border-color: #ffecb5;
        color: #664d03;
    }
    
    .alert-info {
        background-color: #d1ecf1;
        border-color: #b8daff;
        color: #055160;
    }
    
    /* Fix validation feedback */
    .invalid-feedback {
        color: #dc3545;
        font-size: 0.875em;
    }
    
    .valid-feedback {
        color: #198754;
        font-size: 0.875em;
    }
    
    /* Fix section headers */
    .section-header {
        background-color: #f8f9fa;
        color: #212529;
        padding: 0.75rem 1rem;
        border-radius: 0.375rem;
        border: 1px solid #dee2e6;
        margin-bottom: 1rem;
    }
    
    .section-header h5 {
        color: #212529;
        margin-bottom: 0;
    }
    
    .section-header i {
        color: #0d6efd;
    }
    
    /* Fix required field indicators */
    .required {
        color: #dc3545;
    }
    
    /* Fix help text */
    .form-text {
        color: #6c757d;
        font-size: 0.875em;
    }
    
    /* Fix input group text */
    .input-group-text {
        background-color: #e9ecef;
        border: 1px solid #ced4da;
        color: #212529;
    }
    
    /* Fix placeholder text */
    .form-control::placeholder,
    .form-select::placeholder {
        color: #6c757d;
        opacity: 1;
    }
    
    /* Fix disabled form elements */
    .form-control:disabled,
    .form-select:disabled {
        background-color: #e9ecef;
        color: #6c757d;
        opacity: 1;
    }
</style>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Add New Inventory Item</h1>
            <p class="text-muted mb-0">Add a new item to the inventory system</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Inventory
            </a>
        </div>
    </div>

    <!-- Error Messages -->
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Please correct the following errors:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Add Item Form -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-plus-circle me-2"></i>Item Information
            </h5>
        </div>
        <div class="card-body">
            <form method="POST" id="addItemForm">
                <div class="row">
                    <!-- Basic Information -->
                    <div class="col-12">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-info-circle me-2"></i>Basic Information
                        </h6>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="section" class="form-label">Section <span class="text-danger">*</span></label>
                        <select name="section" id="section" class="form-select" required>
                            <option value="">Select Section</option>
                            <?php if ($userInfo['role'] === 'admin' || in_array(1, $userInfo['sections'])): ?>
                                <option value="1" <?php echo (isset($_POST['section']) && $_POST['section'] == '1') ? 'selected' : ''; ?>>
                                    Section 1 - Raw Material Handling
                                </option>
                            <?php endif; ?>
                            <?php if ($userInfo['role'] === 'admin' || in_array(2, $userInfo['sections'])): ?>
                                <option value="2" <?php echo (isset($_POST['section']) && $_POST['section'] == '2') ? 'selected' : ''; ?>>
                                    Section 2 - Processing & Drying
                                </option>
                            <?php endif; ?>
                            <?php if ($userInfo['role'] === 'admin' || in_array(3, $userInfo['sections'])): ?>
                                <option value="3" <?php echo (isset($_POST['section']) && $_POST['section'] == '3') ? 'selected' : ''; ?>>
                                    Section 3 - Packaging
                                </option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="item_code" class="form-label">Item Code <span class="text-danger">*</span></label>
                        <input type="text" name="item_code" id="item_code" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['item_code'] ?? ''); ?>" 
                               placeholder="e.g., RAW001, PRO002" required>
                        <div class="form-text">Unique identifier for the item</div>
                    </div>

                    <div class="col-md-8 mb-3">
                        <label for="item_name" class="form-label">Item Name <span class="text-danger">*</span></label>
                        <input type="text" name="item_name" id="item_name" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['item_name'] ?? ''); ?>" 
                               placeholder="Enter item name" required>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="batch_number" class="form-label">Batch Number</label>
                        <input type="text" name="batch_number" id="batch_number" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['batch_number'] ?? ''); ?>" 
                               placeholder="e.g., B2024001">
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="category" class="form-label">Category <span class="text-danger">*</span></label>
                        <input type="text" name="category" id="category" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['category'] ?? ''); ?>" 
                               placeholder="e.g., Raw Materials, Finished Goods" 
                               list="categoryList" required>
                        <datalist id="categoryList">
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="subcategory" class="form-label">Subcategory</label>
                        <input type="text" name="subcategory" id="subcategory" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['subcategory'] ?? ''); ?>" 
                               placeholder="Enter subcategory">
                    </div>

                    <div class="col-12 mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea name="description" id="description" class="form-control" rows="3" 
                                  placeholder="Enter item description"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <!-- Quantity & Pricing -->
                    <div class="col-12 mt-4">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-calculator me-2"></i>Quantity & Pricing
                        </h6>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="quantity" class="form-label">Current Quantity <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" id="quantity" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['quantity'] ?? '0'); ?>" 
                               step="0.001" min="0" required>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="unit" class="form-label">Unit <span class="text-danger">*</span></label>
                        <select name="unit" id="unit" class="form-select" required>
                            <option value="">Select Unit</option>
                            <option value="kg" <?php echo (isset($_POST['unit']) && $_POST['unit'] == 'kg') ? 'selected' : ''; ?>>Kilograms (kg)</option>
                            <option value="g" <?php echo (isset($_POST['unit']) && $_POST['unit'] == 'g') ? 'selected' : ''; ?>>Grams (g)</option>
                            <option value="l" <?php echo (isset($_POST['unit']) && $_POST['unit'] == 'l') ? 'selected' : ''; ?>>Liters (l)</option>
                            <option value="ml" <?php echo (isset($_POST['unit']) && $_POST['unit'] == 'ml') ? 'selected' : ''; ?>>Milliliters (ml)</option>
                            <option value="pcs" <?php echo (isset($_POST['unit']) && $_POST['unit'] == 'pcs') ? 'selected' : ''; ?>>Pieces (pcs)</option>
                            <option value="boxes" <?php echo (isset($_POST['unit']) && $_POST['unit'] == 'boxes') ? 'selected' : ''; ?>>Boxes</option>
                            <option value="bags" <?php echo (isset($_POST['unit']) && $_POST['unit'] == 'bags') ? 'selected' : ''; ?>>Bags</option>
                        </select>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="unit_cost" class="form-label">Unit Cost</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" name="unit_cost" id="unit_cost" class="form-control" 
                                   value="<?php echo htmlspecialchars($_POST['unit_cost'] ?? ''); ?>" 
                                   step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>

                    <!-- Thresholds -->
                    <div class="col-md-4 mb-3">
                        <label for="min_threshold" class="form-label">Minimum Threshold <span class="text-danger">*</span></label>
                        <input type="number" name="min_threshold" id="min_threshold" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['min_threshold'] ?? ''); ?>" 
                               step="0.001" min="0" required>
                        <div class="form-text">Alert when stock falls below this level</div>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="max_threshold" class="form-label">Maximum Threshold <span class="text-danger">*</span></label>
                        <input type="number" name="max_threshold" id="max_threshold" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['max_threshold'] ?? ''); ?>" 
                               step="0.001" min="0" required>
                        <div class="form-text">Maximum stock capacity</div>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="reorder_level" class="form-label">Reorder Level</label>
                        <input type="number" name="reorder_level" id="reorder_level" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['reorder_level'] ?? ''); ?>" 
                               step="0.001" min="0">
                        <div class="form-text">Automatic reorder trigger level</div>
                    </div>

                    <!-- Dates -->
                    <div class="col-12 mt-4">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-calendar me-2"></i>Dates
                        </h6>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="manufacture_date" class="form-label">Manufacture Date</label>
                        <input type="date" name="manufacture_date" id="manufacture_date" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['manufacture_date'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="expiry_date" class="form-label">Expiry Date</label>
                        <input type="date" name="expiry_date" id="expiry_date" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['expiry_date'] ?? ''); ?>">
                    </div>

                    <!-- Storage & Quality -->
                    <div class="col-12 mt-4">
                        <h6 class="text-primary mb-3">
                            <i class="fas fa-warehouse me-2"></i>Storage & Quality
                        </h6>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="storage_location" class="form-label">Storage Location</label>
                        <input type="text" name="storage_location" id="storage_location" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['storage_location'] ?? ''); ?>" 
                               placeholder="e.g., Warehouse A, Shelf 1">
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="supplier_id" class="form-label">Supplier</label>
                        <select name="supplier_id" id="supplier_id" class="form-select">
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>" 
                                        <?php echo (isset($_POST['supplier_id']) && $_POST['supplier_id'] == $supplier['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="storage_temperature" class="form-label">Storage Temperature (°C)</label>
                        <input type="number" name="storage_temperature" id="storage_temperature" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['storage_temperature'] ?? ''); ?>" 
                               step="0.1" placeholder="e.g., 25.0">
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="storage_humidity" class="form-label">Storage Humidity (%)</label>
                        <input type="number" name="storage_humidity" id="storage_humidity" class="form-control" 
                               value="<?php echo htmlspecialchars($_POST['storage_humidity'] ?? ''); ?>" 
                               step="0.1" min="0" max="100" placeholder="e.g., 60.0">
                    </div>

                    <div class="col-md-4 mb-3">
                        <label for="quality_grade" class="form-label">Quality Grade</label>
                        <select name="quality_grade" id="quality_grade" class="form-select">
                            <option value="A" <?php echo (isset($_POST['quality_grade']) && $_POST['quality_grade'] == 'A') ? 'selected' : ''; ?>>Grade A - Premium</option>
                            <option value="B" <?php echo (isset($_POST['quality_grade']) && $_POST['quality_grade'] == 'B') ? 'selected' : ''; ?>>Grade B - Standard</option>
                            <option value="C" <?php echo (isset($_POST['quality_grade']) && $_POST['quality_grade'] == 'C') ? 'selected' : ''; ?>>Grade C - Basic</option>
                            <option value="D" <?php echo (isset($_POST['quality_grade']) && $_POST['quality_grade'] == 'D') ? 'selected' : ''; ?>>Grade D - Below Standard</option>
                        </select>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select name="status" id="status" class="form-select">
                            <option value="active" <?php echo (isset($_POST['status']) && $_POST['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo (isset($_POST['status']) && $_POST['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="col-12 mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea name="notes" id="notes" class="form-control" rows="3" 
                                  placeholder="Additional notes or comments"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="row mt-4">
                    <div class="col-12">
                        <hr>
                        <div class="d-flex justify-content-between">
                            <a href="index.php" class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Add Item
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-generate item code based on section and category
    const sectionSelect = document.getElementById('section');
    const categoryInput = document.getElementById('category');
    const itemCodeInput = document.getElementById('item_code');
    
    function generateItemCode() {
        const section = sectionSelect.value;
        const category = categoryInput.value;
        
        if (section && category) {
            const sectionPrefix = {
                '1': 'RAW',
                '2': 'PRO',
                '3': 'PKG'
            };
            
            const categoryPrefix = category.substring(0, 3).toUpperCase();
            const timestamp = Date.now().toString().slice(-4);
            
            const code = `${sectionPrefix[section]}-${categoryPrefix}-${timestamp}`;
            itemCodeInput.value = code;
        }
    }
    
    sectionSelect.addEventListener('change', generateItemCode);
    categoryInput.addEventListener('blur', generateItemCode);
    
    // Form validation
    const form = document.getElementById('addItemForm');
    form.addEventListener('submit', function(e) {
        const minThreshold = parseFloat(document.getElementById('min_threshold').value) || 0;
        const maxThreshold = parseFloat(document.getElementById('max_threshold').value) || 0;
        
        if (minThreshold >= maxThreshold) {
            e.preventDefault();
            alert('Minimum threshold must be less than maximum threshold');
            return false;
        }
        
        const manufactureDate = document.getElementById('manufacture_date').value;
        const expiryDate = document.getElementById('expiry_date').value;
        
        if (manufactureDate && expiryDate && new Date(expiryDate) <= new Date(manufactureDate)) {
            e.preventDefault();
            alert('Expiry date must be after manufacture date');
            return false;
        }
    });
    
    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            if (alert.classList.contains('show')) {
                alert.classList.remove('show');
                alert.classList.add('fade');
            }
        });
    }, 5000);
});
</script>

<?php include '../../includes/footer.php'; ?>
