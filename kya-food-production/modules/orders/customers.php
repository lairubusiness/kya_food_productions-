<?php
/**
 * KYA Food Production - Customer Management
 * Manage customers and their order history
 */

require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

SessionManager::start();
SessionManager::requireLogin();

// Check if user has orders management permissions (admin only for now)
if (!SessionManager::hasPermission('admin')) {
    header('Location: ../../dashboard.php?error=access_denied');
    exit();
}

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

$pageTitle = "Customer Management";

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_customer') {
        $name = $_POST['customer_name'] ?? '';
        $email = $_POST['customer_email'] ?? '';
        $phone = $_POST['customer_phone'] ?? '';
        $address = $_POST['customer_address'] ?? '';
        $country = $_POST['export_country'] ?? '';
        $company = $_POST['company'] ?? '';
        
        try {
            // Check if customer already exists
            $stmt = $conn->prepare("SELECT id FROM orders WHERE customer_email = ? LIMIT 1");
            $stmt->execute([$email]);
            
            if (!$stmt->fetch()) {
                // Create a placeholder order entry to establish customer
                $stmt = $conn->prepare("
                    INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, 
                                      customer_address, export_country, company, status, total_amount, 
                                      created_by, order_date, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', 0, ?, NOW(), NOW())
                ");
                $orderNumber = 'CUST-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $stmt->execute([
                    $orderNumber, $name, $email, $phone, $address, $country, $company, $userInfo['id']
                ]);
                
                logActivity('customer_added', "New customer added: {$name}", $userInfo['id']);
                header('Location: customers.php?success=customer_added');
                exit();
            } else {
                $error = "Customer with this email already exists";
            }
        } catch (Exception $e) {
            error_log("Add customer error: " . $e->getMessage());
            $error = "Failed to add customer";
        }
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$country = $_GET['country'] ?? '';

// Get customers (unique customers from orders)
try {
    $whereClause = "WHERE 1=1";
    $params = [];
    
    if ($search) {
        $whereClause .= " AND (customer_name LIKE ? OR customer_email LIKE ? OR company LIKE ?)";
        $searchParam = "%{$search}%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    if ($country) {
        $whereClause .= " AND export_country = ?";
        $params[] = $country;
    }
    
    $stmt = $conn->prepare("
        SELECT 
            customer_name,
            customer_email,
            customer_phone,
            customer_address,
            export_country,
            company,
            COUNT(*) as total_orders,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
            SUM(total_amount) as total_spent,
            MAX(order_date) as last_order_date,
            MIN(order_date) as first_order_date
        FROM orders 
        {$whereClause}
        GROUP BY customer_email, customer_name, customer_phone, customer_address, export_country, company
        HAVING customer_name IS NOT NULL AND customer_name != ''
        ORDER BY total_spent DESC, last_order_date DESC
    ");
    $stmt->execute($params);
    $customers = $stmt->fetchAll();
    
    // Get customer statistics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT customer_email) as total_customers,
            COUNT(DISTINCT export_country) as total_countries,
            AVG(total_amount) as avg_order_value,
            COUNT(*) as total_orders
        FROM orders 
        {$whereClause}
        AND customer_name IS NOT NULL AND customer_name != ''
    ");
    $stmt->execute($params);
    $stats = $stmt->fetch();
    
    // Get top countries
    $stmt = $conn->prepare("
        SELECT export_country, COUNT(DISTINCT customer_email) as customer_count
        FROM orders 
        WHERE export_country IS NOT NULL AND export_country != ''
        GROUP BY export_country
        ORDER BY customer_count DESC
        LIMIT 10
    ");
    $stmt->execute();
    $topCountries = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Customers error: " . $e->getMessage());
    $customers = [];
    $stats = ['total_customers' => 0, 'total_countries' => 0, 'avg_order_value' => 0, 'total_orders' => 0];
    $topCountries = [];
}

include '../../includes/header.php';
?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Customer Management</h1>
            <p class="text-muted mb-0">Manage customers and analyze their order history</p>
        </div>
        <div>
            <a href="index.php" class="btn btn-outline-secondary me-2">
                <i class="fas fa-arrow-left me-2"></i>All Orders
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                <i class="fas fa-user-plus me-2"></i>Add Customer
            </button>
        </div>
    </div>

    <!-- Customer Statistics -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card primary">
                <div class="stats-icon primary">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stats-number"><?php echo $stats['total_customers']; ?></div>
                <div class="stats-label">Total Customers</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card info">
                <div class="stats-icon info">
                    <i class="fas fa-globe"></i>
                </div>
                <div class="stats-number"><?php echo $stats['total_countries']; ?></div>
                <div class="stats-label">Countries</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card success">
                <div class="stats-icon success">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stats-number"><?php echo formatCurrency($stats['avg_order_value']); ?></div>
                <div class="stats-label">Avg Order Value</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card warning">
                <div class="stats-icon warning">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="stats-number"><?php echo $stats['total_orders']; ?></div>
                <div class="stats-label">Total Orders</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label for="search" class="form-label">Search Customers</label>
                    <input type="text" class="form-control" id="search" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Name, email, or company...">
                </div>
                <div class="col-md-3">
                    <label for="country" class="form-label">Country</label>
                    <select class="form-select" id="country" name="country">
                        <option value="">All Countries</option>
                        <?php foreach ($topCountries as $countryData): ?>
                            <option value="<?php echo $countryData['export_country']; ?>" 
                                    <?php echo $country === $countryData['export_country'] ? 'selected' : ''; ?>>
                                <?php echo $countryData['export_country']; ?> (<?php echo $countryData['customer_count']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i>Filter
                        </button>
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid">
                        <a href="customers.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times me-2"></i>Clear
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Customers Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="fas fa-users me-2"></i>Customers
                <span class="badge bg-primary ms-2"><?php echo count($customers); ?></span>
            </h5>
        </div>
        <div class="card-body">
            <?php if (!empty($customers)): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Contact Info</th>
                                <th>Country</th>
                                <th>Orders</th>
                                <th>Total Spent</th>
                                <th>Customer Since</th>
                                <th>Last Order</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customers as $customer): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo $customer['customer_name']; ?></strong>
                                            <?php if ($customer['company']): ?>
                                                <br><small class="text-muted"><?php echo $customer['company']; ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <i class="fas fa-envelope me-1"></i><?php echo $customer['customer_email']; ?>
                                            <?php if ($customer['customer_phone']): ?>
                                                <br><i class="fas fa-phone me-1"></i><?php echo $customer['customer_phone']; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($customer['export_country']): ?>
                                            <span class="badge bg-info"><?php echo $customer['export_country']; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">Domestic</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?php echo $customer['total_orders']; ?></strong> total
                                            <br><small class="text-success">
                                                <?php echo $customer['completed_orders']; ?> completed
                                            </small>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo formatCurrency($customer['total_spent']); ?></strong>
                                        <?php if ($customer['total_orders'] > 0): ?>
                                            <br><small class="text-muted">
                                                Avg: <?php echo formatCurrency($customer['total_spent'] / $customer['total_orders']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatDate($customer['first_order_date']); ?></td>
                                    <td>
                                        <?php echo formatDate($customer['last_order_date']); ?>
                                        <br><small class="text-muted">
                                            <?php echo timeAgo($customer['last_order_date']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="viewCustomerOrders('<?php echo addslashes($customer['customer_email']); ?>')" 
                                                    title="View Orders">
                                                <i class="fas fa-shopping-cart"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-info" 
                                                    onclick="showCustomerDetails('<?php echo addslashes($customer['customer_email']); ?>')" 
                                                    title="Customer Details">
                                                <i class="fas fa-user"></i>
                                            </button>
                                            <a href="mailto:<?php echo $customer['customer_email']; ?>" 
                                               class="btn btn-sm btn-outline-success" title="Send Email">
                                                <i class="fas fa-envelope"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No Customers Found</h5>
                    <p class="text-muted">No customers match your current filter criteria.</p>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                        <i class="fas fa-user-plus me-2"></i>Add First Customer
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top Countries Chart -->
    <?php if (!empty($topCountries)): ?>
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-globe me-2"></i>Top Export Countries
                        </h6>
                    </div>
                    <div class="card-body">
                        <canvas id="countriesChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h6 class="card-title mb-0">
                            <i class="fas fa-chart-bar me-2"></i>Customer Value Distribution
                        </h6>
                    </div>
                    <div class="card-body">
                        <canvas id="valueChart" width="400" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_customer">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="customer_name" class="form-label">Customer Name *</label>
                            <input type="text" class="form-control" id="customer_name" name="customer_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="customer_email" class="form-label">Email Address *</label>
                            <input type="email" class="form-control" id="customer_email" name="customer_email" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="customer_phone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="customer_phone" name="customer_phone">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="company" class="form-label">Company</label>
                            <input type="text" class="form-control" id="company" name="company">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="export_country" class="form-label">Country</label>
                            <select class="form-select" id="export_country" name="export_country">
                                <option value="">Select Country...</option>
                                <option value="USA">United States</option>
                                <option value="UK">United Kingdom</option>
                                <option value="Germany">Germany</option>
                                <option value="France">France</option>
                                <option value="Japan">Japan</option>
                                <option value="Australia">Australia</option>
                                <option value="Canada">Canada</option>
                                <option value="Singapore">Singapore</option>
                                <option value="UAE">United Arab Emirates</option>
                                <option value="India">India</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="customer_address" class="form-label">Address</label>
                            <textarea class="form-control" id="customer_address" name="customer_address" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Customer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Customer Details Modal -->
<div class="modal fade" id="customerDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Customer Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="customerDetailsContent">
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Initialize charts if we have data
<?php if (!empty($topCountries)): ?>
    // Top Countries Chart
    const countriesCtx = document.getElementById('countriesChart').getContext('2d');
    new Chart(countriesCtx, {
        type: 'doughnut',
        data: {
            labels: [<?php echo '"' . implode('","', array_column($topCountries, 'export_country')) . '"'; ?>],
            datasets: [{
                data: [<?php echo implode(',', array_column($topCountries, 'customer_count')); ?>],
                backgroundColor: [
                    '#007bff', '#28a745', '#ffc107', '#dc3545', '#17a2b8',
                    '#6f42c1', '#fd7e14', '#20c997', '#e83e8c', '#6c757d'
                ],
                borderWidth: 2
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

    // Customer Value Distribution Chart
    const valueCtx = document.getElementById('valueChart').getContext('2d');
    const customerValues = [<?php echo implode(',', array_column($customers, 'total_spent')); ?>];
    
    // Group customers by spending ranges
    const valueBuckets = {'$0-$1K': 0, '$1K-$5K': 0, '$5K-$10K': 0, '$10K+': 0};
    customerValues.forEach(value => {
        if (value < 1000) valueBuckets['$0-$1K']++;
        else if (value < 5000) valueBuckets['$1K-$5K']++;
        else if (value < 10000) valueBuckets['$5K-$10K']++;
        else valueBuckets['$10K+']++;
    });
    
    new Chart(valueCtx, {
        type: 'bar',
        data: {
            labels: Object.keys(valueBuckets),
            datasets: [{
                label: 'Number of Customers',
                data: Object.values(valueBuckets),
                backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#dc3545'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            }
        }
    });
<?php endif; ?>

function viewCustomerOrders(customerEmail) {
    window.location.href = 'index.php?search=' + encodeURIComponent(customerEmail);
}

function showCustomerDetails(customerEmail) {
    const modal = new bootstrap.Modal(document.getElementById('customerDetailsModal'));
    modal.show();
    
    // Load customer details via AJAX (placeholder)
    document.getElementById('customerDetailsContent').innerHTML = `
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            Customer details functionality will be implemented with:
            <ul class="mb-0 mt-2">
                <li>Complete order history timeline</li>
                <li>Payment history and outstanding amounts</li>
                <li>Shipping preferences and addresses</li>
                <li>Customer communication log</li>
                <li>Performance metrics and loyalty status</li>
            </ul>
        </div>
    `;
}

// Auto-refresh every 10 minutes for customer data
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 600000);
</script>

<?php include '../../includes/footer.php'; ?>
