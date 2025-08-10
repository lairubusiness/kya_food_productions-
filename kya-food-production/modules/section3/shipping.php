<?php
/**
 * KYA Food Production - Section 3 Shipping Management
 * Manage shipping operations for Section 3 using processing_logs (process_type='shipping')
 */

require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

SessionManager::start();
SessionManager::requireLogin();
SessionManager::requireSection(3);

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'start_shipping':
                    $item_id = (int)$_POST['item_id'];
                    $quantity = (float)$_POST['quantity'];
                    $carrier = sanitizeInput($_POST['carrier']);
                    $tracking_number = sanitizeInput($_POST['tracking_number']);
                    $destination = sanitizeInput($_POST['destination']);
                    $notes = sanitizeInput($_POST['notes']);

                    $batch_id = 'S3-SHP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    $process_stage = 'Dispatched';

                    $stmt = $conn->prepare("
                        INSERT INTO processing_logs (
                            section, batch_id, item_id, process_type, process_stage,
                            input_quantity, equipment_used, operator_id, notes, start_time
                        ) VALUES (3, ?, ?, 'shipping', ?, ?, ?, ?, ?, NOW())
                    ");

                    // equipment_used column reused to store carrier name; additional details in notes
                    $combinedNotes = trim("Destination: $destination\nTracking: $tracking_number\n" . ($notes ?: ''));

                    $stmt->execute([
                        $batch_id,
                        $item_id,
                        $process_stage,
                        $quantity,
                        $carrier,
                        $userInfo['id'],
                        $combinedNotes
                    ]);

                    logActivity('shipping', "Started shipping: Batch: $batch_id, Carrier: $carrier, Tracking: $tracking_number", $userInfo['id']);
                    createNotification($userInfo['id'], 3, 'info', 'normal', 'Shipping Started', "Shipment dispatched. Batch $batch_id");

                    $_SESSION['flash_message'] = ['type' => 'success', 'message' => "Shipping started successfully. Batch: $batch_id"]; 
                    break;

                case 'update_shipping':
                    $process_id = (int)$_POST['process_id'];
                    $process_stage = sanitizeInput($_POST['process_stage']); // e.g., In Transit, At Hub, Out for Delivery
                    $update_notes = sanitizeInput($_POST['update_notes']);

                    $stmt = $conn->prepare("
                        UPDATE processing_logs SET 
                            process_stage = ?,
                            notes = CONCAT(COALESCE(notes, ''), '\n\nUpdate: ', ?)
                        WHERE id = ? AND section = 3 AND process_type = 'shipping'
                    ");
                    $stmt->execute([$process_stage, $update_notes, $process_id]);

                    logActivity('shipping', "Updated shipping: Process ID: $process_id, Stage: $process_stage", $userInfo['id']);
                    $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Shipping updated successfully.'];
                    break;

                case 'complete_shipping':
                    $process_id = (int)$_POST['process_id'];
                    $delivery_notes = sanitizeInput($_POST['delivery_notes']);

                    // Fetch batch for message
                    $stmt = $conn->prepare("SELECT batch_id FROM processing_logs WHERE id = ? AND section = 3 AND process_type = 'shipping'");
                    $stmt->execute([$process_id]);
                    $process = $stmt->fetch(PDO::FETCH_ASSOC);

                    $stmt = $conn->prepare("
                        UPDATE processing_logs SET 
                            end_time = NOW(),
                            duration_minutes = TIMESTAMPDIFF(MINUTE, start_time, NOW()),
                            process_stage = 'Delivered',
                            notes = CONCAT(COALESCE(notes, ''), '\n\nDelivered: ', ?)
                        WHERE id = ? AND section = 3 AND process_type = 'shipping'
                    ");
                    $stmt->execute([$delivery_notes, $process_id]);

                    $batchLabel = $process ? $process['batch_id'] : ('ID:' . $process_id);
                    logActivity('shipping', "Completed delivery for $batchLabel", $userInfo['id']);
                    createNotification($userInfo['id'], 3, 'success', 'normal', 'Shipping Delivered', "Shipment delivered for $batchLabel");

                    $_SESSION['flash_message'] = ['type' => 'success', 'message' => 'Shipment marked as delivered.'];
                    break;
            }
        }
    } catch (Exception $e) {
        $_SESSION['flash_message'] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
    }

    header('Location: shipping.php');
    exit;
}

// Filters
$status = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$carrierFilter = $_GET['carrier'] ?? '';

$conditions = ["pl.section = 3", "pl.process_type = 'shipping'"];
$params = [];

if (!empty($status)) {
    if ($status === 'active') $conditions[] = 'pl.end_time IS NULL';
    if ($status === 'delivered') $conditions[] = 'pl.end_time IS NOT NULL';
}
if (!empty($date_from)) { $conditions[] = 'DATE(pl.start_time) >= ?'; $params[] = $date_from; }
if (!empty($date_to))   { $conditions[] = 'DATE(pl.start_time) <= ?'; $params[] = $date_to; }
if (!empty($carrierFilter)) { $conditions[] = 'pl.equipment_used = ?'; $params[] = $carrierFilter; }

$whereClause = 'WHERE ' . implode(' AND ', $conditions);

// Stats
$statsStmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_shipments,
        COUNT(CASE WHEN pl.end_time IS NULL THEN 1 END) as in_transit,
        COUNT(CASE WHEN pl.end_time IS NOT NULL THEN 1 END) as delivered
    FROM processing_logs pl
    $whereClause
");
$statsStmt->execute($params);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Active shipments
$activeStmt = $conn->prepare("
    SELECT pl.*, i.item_name, i.item_code, u.full_name
    FROM processing_logs pl
    LEFT JOIN inventory i ON pl.item_id = i.id
    LEFT JOIN users u ON pl.operator_id = u.id
    $whereClause AND pl.end_time IS NULL
    ORDER BY pl.start_time DESC
");
$activeStmt->execute($params);
$activeShipments = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

// Recent shipments
$recentStmt = $conn->prepare("
    SELECT pl.*, i.item_name, i.item_code, u.full_name
    FROM processing_logs pl
    LEFT JOIN inventory i ON pl.item_id = i.id
    LEFT JOIN users u ON pl.operator_id = u.id
    $whereClause
    ORDER BY pl.created_at DESC
    LIMIT 20
");
$recentStmt->execute($params);
$recentShipments = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// Inventory items for Section 3
$itemStmt = $conn->prepare("
    SELECT id, item_name, item_code, quantity, unit 
    FROM inventory 
    WHERE section = 3 AND status = 'active' AND quantity > 0
    ORDER BY item_name
");
$itemStmt->execute();
$inventoryItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Section 3 - Shipping Management';
include '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0">Section 3 - Shipping Management</h1>
            <p class="text-muted">Dispatch, track, and complete shipments</p>
        </div>
        <div>
            <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#startShippingModal">
                <i class="fas fa-truck me-2"></i>Start Shipment
            </button>
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>
    </div>

    <!-- Flash -->
    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['flash_message']['type']; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($_SESSION['flash_message']['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_message']); ?>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body">
            <form class="row g-3" method="GET">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All</option>
                        <option value="active" <?php echo $status==='active'?'selected':''; ?>>In Transit</option>
                        <option value="delivered" <?php echo $status==='delivered'?'selected':''; ?>>Delivered</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Carrier</label>
                    <select name="carrier" class="form-select">
                        <option value="">All</option>
                        <option value="DHL" <?php echo $carrierFilter==='DHL'?'selected':''; ?>>DHL</option>
                        <option value="FedEx" <?php echo $carrierFilter==='FedEx'?'selected':''; ?>>FedEx</option>
                        <option value="UPS" <?php echo $carrierFilter==='UPS'?'selected':''; ?>>UPS</option>
                        <option value="Local Courier" <?php echo $carrierFilter==='Local Courier'?'selected':''; ?>>Local Courier</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-12 text-end">
                    <button class="btn btn-secondary"><i class="fas fa-filter me-2"></i>Apply Filters</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Stats -->
    <div class="row mb-3">
        <div class="col-md-4 mb-3">
            <div class="card border-left-primary h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-xs text-uppercase text-muted">Total Shipments</div>
                            <div class="h4 mb-0"><?php echo number_format($stats['total_shipments'] ?? 0); ?></div>
                        </div>
                        <i class="fas fa-clipboard-list fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-left-info h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-xs text-uppercase text-muted">In Transit</div>
                            <div class="h4 mb-0"><?php echo number_format($stats['in_transit'] ?? 0); ?></div>
                        </div>
                        <i class="fas fa-truck-moving fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-left-success h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-xs text-uppercase text-muted">Delivered</div>
                            <div class="h4 mb-0"><?php echo number_format($stats['delivered'] ?? 0); ?></div>
                        </div>
                        <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Shipments -->
    <div class="card mb-4">
        <div class="card-header bg-light"><strong>Active Shipments</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Batch ID</th>
                            <th>Item</th>
                            <th>Quantity</th>
                            <th>Carrier</th>
                            <th>Tracking</th>
                            <th>Stage</th>
                            <th>Operator</th>
                            <th>Started</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($activeShipments)): ?>
                            <?php foreach ($activeShipments as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['batch_id']); ?></td>
                                    <td><?php echo htmlspecialchars($row['item_name'] ?? '-') . ' (' . htmlspecialchars($row['item_code'] ?? '-') . ')'; ?></td>
                                    <td><?php echo number_format($row['input_quantity'] ?? 0, 2); ?> kg</td>
                                    <td><?php echo htmlspecialchars($row['equipment_used'] ?? '-'); ?></td>
                                    <td><?php 
                                        if (preg_match('/Tracking:\s*(.+)/', $row['notes'] ?? '', $m)) echo htmlspecialchars($m[1]); else echo '-';
                                    ?></td>
                                    <td><span class="badge bg-info"><?php echo htmlspecialchars($row['process_stage'] ?? '-'); ?></span></td>
                                    <td><?php echo htmlspecialchars($row['full_name'] ?? '-'); ?></td>
                                    <td><?php echo formatDateTime($row['start_time']); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary me-1" onclick="updateShipping(<?php echo (int)$row['id']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-success" onclick="completeShipping(<?php echo (int)$row['id']; ?>)">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="text-center text-muted">No active shipments</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Shipments -->
    <div class="card">
        <div class="card-header bg-light"><strong>Recent Shipments</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Batch ID</th>
                            <th>Item</th>
                            <th>Quantity</th>
                            <th>Carrier</th>
                            <th>Tracking</th>
                            <th>Status</th>
                            <th>Operator</th>
                            <th>Started</th>
                            <th>Completed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recentShipments)): ?>
                            <?php foreach ($recentShipments as $row): ?>
                                <tr class="<?php echo $row['end_time'] ? 'table-success' : 'table-warning'; ?>">
                                    <td><?php echo htmlspecialchars($row['batch_id']); ?></td>
                                    <td><?php echo htmlspecialchars($row['item_name'] ?? '-') . ' (' . htmlspecialchars($row['item_code'] ?? '-') . ')'; ?></td>
                                    <td><?php echo number_format($row['input_quantity'] ?? 0, 2); ?> kg</td>
                                    <td><?php echo htmlspecialchars($row['equipment_used'] ?? '-'); ?></td>
                                    <td><?php 
                                        if (preg_match('/Tracking:\s*(.+)/', $row['notes'] ?? '', $m)) echo htmlspecialchars($m[1]); else echo '-';
                                    ?></td>
                                    <td>
                                        <?php if ($row['end_time']): ?>
                                            <span class="badge bg-success">Delivered</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">In Transit</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['full_name'] ?? '-'); ?></td>
                                    <td><?php echo formatDateTime($row['start_time']); ?></td>
                                    <td><?php echo $row['end_time'] ? formatDateTime($row['end_time']) : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="text-center text-muted">No shipments found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Start Shipping Modal -->
<div class="modal fade" id="startShippingModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Start New Shipment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="start_shipping">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Inventory Item</label>
                            <select name="item_id" class="form-select" required>
                                <option value="">Select Item</option>
                                <?php foreach ($inventoryItems as $item): ?>
                                    <option value="<?php echo $item['id']; ?>">
                                        <?php echo htmlspecialchars($item['item_name']); ?> (<?php echo htmlspecialchars($item['item_code']); ?>) -
                                        <?php echo number_format($item['quantity'], 2); ?> <?php echo htmlspecialchars($item['unit']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Quantity (kg)</label>
                            <input type="number" name="quantity" class="form-control" step="0.001" min="0.001" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Carrier</label>
                            <select name="carrier" class="form-select" required>
                                <option value="">Select Carrier</option>
                                <option value="DHL">DHL</option>
                                <option value="FedEx">FedEx</option>
                                <option value="UPS">UPS</option>
                                <option value="Local Courier">Local Courier</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tracking Number</label>
                            <input type="text" name="tracking_number" class="form-control" placeholder="e.g., 1Z999...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Destination</label>
                            <input type="text" name="destination" class="form-control" placeholder="City, Address or Warehouse">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Any extra details..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-truck me-2"></i>Start Shipment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Shipping Modal -->
<div class="modal fade" id="updateShippingModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Shipment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_shipping">
                <input type="hidden" name="process_id" id="update_process_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Stage</label>
                            <input type="text" name="process_stage" id="update_process_stage" class="form-control" placeholder="e.g., In Transit, At Hub, Out for Delivery">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Update Notes</label>
                            <textarea name="update_notes" class="form-control" rows="3" placeholder="Details about this update..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Complete Shipping Modal -->
<div class="modal fade" id="completeShippingModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Mark Delivered</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="complete_shipping">
                <input type="hidden" name="process_id" id="complete_process_id">
                <div class="modal-body">
                    <label class="form-label">Delivery Notes</label>
                    <textarea name="delivery_notes" class="form-control" rows="3" placeholder="Optional notes about delivery..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-check me-2"></i>Mark Delivered</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function updateShipping(processId) {
    document.getElementById('update_process_id').value = processId;
    new bootstrap.Modal(document.getElementById('updateShippingModal')).show();
}
function completeShipping(processId) {
    document.getElementById('complete_process_id').value = processId;
    new bootstrap.Modal(document.getElementById('completeShippingModal')).show();
}
// Auto-refresh active list every 30s if there are active shipments
setInterval(function() {
    <?php echo count($activeShipments) > 0 ? 'location.reload();' : '// no active shipments'; ?>
}, 30000);
</script>

<style>
.border-left-primary { border-left: 0.25rem solid #4e73df !important; }
.border-left-info { border-left: 0.25rem solid #36b9cc !important; }
.border-left-success { border-left: 0.25rem solid #1cc88a !important; }
.text-gray-300 { color: #dddfeb !important; }
.text-xs { font-size: 0.7rem !important; }
.text-uppercase { text-transform: uppercase !important; }
</style>

<?php include '../../includes/footer.php'; ?>
