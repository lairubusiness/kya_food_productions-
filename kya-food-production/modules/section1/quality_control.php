<?php
/**
 * KYA Food Production - Section 1 Quality Control
 * Raw Material Quality Control Management
 */

require_once '../../config/database.php';
require_once '../../config/constants.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

SessionManager::start();
SessionManager::requireLogin();
SessionManager::requireSection(1);

$userInfo = SessionManager::getUserInfo();
$db = new Database();
$conn = $db->connect();

// Get filter parameters
$status = $_GET['status'] ?? '';
$grade = $_GET['grade'] ?? '';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'inspection_date';
$order = $_GET['order'] ?? 'DESC';

// Build query conditions
$whereConditions = ['section = 1'];
$params = [1];

if ($status) {
    $whereConditions[] = "status = ?";
    $params[] = $status;
}

if ($grade) {
    $whereConditions[] = "quality_grade = ?";
    $params[] = $grade;
}

if ($search) {
    $whereConditions[] = "(batch_number LIKE ? OR notes LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// Get quality inspections
try {
    // Create quality_inspections table if needed
    $conn->exec("
        CREATE TABLE IF NOT EXISTS quality_inspections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            section INT NOT NULL,
            batch_number VARCHAR(100),
            inspection_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            inspector_id INT,
            quality_grade ENUM('A', 'B', 'C', 'D', 'F') DEFAULT 'A',
            status ENUM('passed', 'failed', 'pending', 'conditional') DEFAULT 'pending',
            temperature DECIMAL(5,2),
            humidity DECIMAL(5,2),
            ph_level DECIMAL(4,2),
            contamination_check ENUM('pass', 'fail') DEFAULT 'pass',
            visual_inspection ENUM('pass', 'fail') DEFAULT 'pass',
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_section (section)
        )
    ");

    $stmt = $conn->prepare("
        SELECT qi.*, u.full_name as inspector_name
        FROM quality_inspections qi
        LEFT JOIN users u ON qi.inspector_id = u.id
        $whereClause
        ORDER BY $sort $order
        LIMIT 50
    ");
    $stmt->execute($params);
    $qualityInspections = $stmt->fetchAll();
    
    // Get summary statistics
    $summaryStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_inspections,
            COUNT(CASE WHEN status = 'passed' THEN 1 END) as passed_inspections,
            COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_inspections,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_inspections,
            COUNT(CASE WHEN quality_grade = 'A' THEN 1 END) as grade_a_count,
            COUNT(CASE WHEN quality_grade = 'B' THEN 1 END) as grade_b_count,
            COUNT(CASE WHEN quality_grade = 'C' THEN 1 END) as grade_c_count,
            COUNT(CASE WHEN quality_grade IN ('D', 'F') THEN 1 END) as grade_poor_count
        FROM quality_inspections
        WHERE section = 1 AND DATE(inspection_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $summaryStmt->execute();
    $summary = $summaryStmt->fetch();
    
} catch (Exception $e) {
    error_log("Quality control error: " . $e->getMessage());
    $qualityInspections = [];
    $summary = [
        'total_inspections' => 0, 'passed_inspections' => 0, 'failed_inspections' => 0, 
        'pending_inspections' => 0, 'grade_a_count' => 0, 'grade_b_count' => 0, 
        'grade_c_count' => 0, 'grade_poor_count' => 0
    ];
}

$pageTitle = 'Section 1 - Quality Control';
include '../../includes/header.php';
?>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <span class="badge me-2" style="background-color: #2c5f41;">
                    Section 1
                </span>
                Quality Control
            </h1>
            <p class="text-muted mb-0">Raw material quality inspection and compliance monitoring</p>
        </div>
        <div class="btn-group" role="group">
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newInspectionModal">
                <i class="fas fa-plus me-2"></i>New Inspection
            </button>
        </div>
    </div>
    
    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card border-primary">
                <div class="card-body text-center">
                    <div class="text-primary mb-2">
                        <i class="fas fa-clipboard-check fa-2x"></i>
                    </div>
                    <h4 class="mb-1"><?php echo number_format($summary['total_inspections']); ?></h4>
                    <p class="text-muted mb-0">Total Inspections</p>
                    <small class="text-muted">Last 30 days</small>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card border-success">
                <div class="card-body text-center">
                    <div class="text-success mb-2">
                        <i class="fas fa-check-circle fa-2x"></i>
                    </div>
                    <h4 class="mb-1"><?php echo number_format($summary['passed_inspections']); ?></h4>
                    <p class="text-muted mb-0">Passed</p>
                    <small class="text-success">
                        <?php echo $summary['total_inspections'] > 0 ? round(($summary['passed_inspections'] / $summary['total_inspections']) * 100, 1) : 0; ?>% pass rate
                    </small>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card border-danger">
                <div class="card-body text-center">
                    <div class="text-danger mb-2">
                        <i class="fas fa-times-circle fa-2x"></i>
                    </div>
                    <h4 class="mb-1"><?php echo number_format($summary['failed_inspections']); ?></h4>
                    <p class="text-muted mb-0">Failed</p>
                    <small class="text-danger">Requires attention</small>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card border-warning">
                <div class="card-body text-center">
                    <div class="text-warning mb-2">
                        <i class="fas fa-clock fa-2x"></i>
                    </div>
                    <h4 class="mb-1"><?php echo number_format($summary['pending_inspections']); ?></h4>
                    <p class="text-muted mb-0">Pending</p>
                    <small class="text-warning">Awaiting results</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Quality Grade Distribution -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Quality Grade Distribution (Last 30 Days)</h5>
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col-3">
                    <div class="text-success mb-2">
                        <i class="fas fa-star fa-2x"></i>
                    </div>
                    <h4 class="text-success"><?php echo $summary['grade_a_count']; ?></h4>
                    <p class="text-muted mb-0">Grade A</p>
                    <small class="text-muted">Excellent</small>
                </div>
                <div class="col-3">
                    <div class="text-info mb-2">
                        <i class="fas fa-star fa-2x"></i>
                    </div>
                    <h4 class="text-info"><?php echo $summary['grade_b_count']; ?></h4>
                    <p class="text-muted mb-0">Grade B</p>
                    <small class="text-muted">Good</small>
                </div>
                <div class="col-3">
                    <div class="text-warning mb-2">
                        <i class="fas fa-star fa-2x"></i>
                    </div>
                    <h4 class="text-warning"><?php echo $summary['grade_c_count']; ?></h4>
                    <p class="text-muted mb-0">Grade C</p>
                    <small class="text-muted">Fair</small>
                </div>
                <div class="col-3">
                    <div class="text-danger mb-2">
                        <i class="fas fa-exclamation-triangle fa-2x"></i>
                    </div>
                    <h4 class="text-danger"><?php echo $summary['grade_poor_count']; ?></h4>
                    <p class="text-muted mb-0">Grade D/F</p>
                    <small class="text-muted">Poor/Failed</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Filters & Search</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="passed" <?php echo $status === 'passed' ? 'selected' : ''; ?>>Passed</option>
                        <option value="failed" <?php echo $status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="conditional" <?php echo $status === 'conditional' ? 'selected' : ''; ?>>Conditional</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="grade" class="form-label">Quality Grade</label>
                    <select name="grade" id="grade" class="form-select">
                        <option value="">All Grades</option>
                        <option value="A" <?php echo $grade === 'A' ? 'selected' : ''; ?>>Grade A</option>
                        <option value="B" <?php echo $grade === 'B' ? 'selected' : ''; ?>>Grade B</option>
                        <option value="C" <?php echo $grade === 'C' ? 'selected' : ''; ?>>Grade C</option>
                        <option value="D" <?php echo $grade === 'D' ? 'selected' : ''; ?>>Grade D</option>
                        <option value="F" <?php echo $grade === 'F' ? 'selected' : ''; ?>>Grade F</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" name="search" id="search" class="form-control" 
                           placeholder="Batch number, notes..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary d-block">
                        <i class="fas fa-search"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Quality Inspections Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                Quality Inspections 
                <span class="badge bg-primary"><?php echo count($qualityInspections); ?> records</span>
            </h5>
        </div>
        <div class="card-body p-0">
            <?php if (empty($qualityInspections)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-clipboard-check fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No quality inspections found</h5>
                    <p class="text-muted">Start by creating your first quality inspection.</p>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newInspectionModal">
                        <i class="fas fa-plus me-2"></i>Create First Inspection
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Inspection Date</th>
                                <th>Batch Number</th>
                                <th>Grade</th>
                                <th>Status</th>
                                <th>Test Results</th>
                                <th>Environmental</th>
                                <th>Inspector</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($qualityInspections as $inspection): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo formatDateTime($inspection['inspection_date']); ?></strong>
                                    </td>
                                    <td>
                                        <?php if ($inspection['batch_number']): ?>
                                            <strong><?php echo htmlspecialchars($inspection['batch_number']); ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $gradeColors = ['A' => 'success', 'B' => 'info', 'C' => 'warning', 'D' => 'danger', 'F' => 'danger'];
                                        $gradeColor = $gradeColors[$inspection['quality_grade']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $gradeColor; ?>">
                                            Grade <?php echo $inspection['quality_grade']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $statusColors = ['passed' => 'success', 'failed' => 'danger', 'pending' => 'warning', 'conditional' => 'info'];
                                        $statusColor = $statusColors[$inspection['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $statusColor; ?>">
                                            <?php echo ucfirst($inspection['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <?php if ($inspection['contamination_check']): ?>
                                                <span class="badge badge-sm bg-<?php echo $inspection['contamination_check'] === 'pass' ? 'success' : 'danger'; ?>">
                                                    Contamination: <?php echo ucfirst($inspection['contamination_check']); ?>
                                                </span><br>
                                            <?php endif; ?>
                                            <?php if ($inspection['visual_inspection']): ?>
                                                <span class="badge badge-sm bg-<?php echo $inspection['visual_inspection'] === 'pass' ? 'success' : 'danger'; ?>">
                                                    Visual: <?php echo ucfirst($inspection['visual_inspection']); ?>
                                                </span><br>
                                            <?php endif; ?>
                                            <?php if ($inspection['ph_level']): ?>
                                                <small class="text-muted">pH: <?php echo $inspection['ph_level']; ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($inspection['temperature'] || $inspection['humidity']): ?>
                                            <div class="small">
                                                <?php if ($inspection['temperature']): ?>
                                                    <i class="fas fa-thermometer-half text-info"></i> <?php echo $inspection['temperature']; ?>°C<br>
                                                <?php endif; ?>
                                                <?php if ($inspection['humidity']): ?>
                                                    <i class="fas fa-tint text-primary"></i> <?php echo $inspection['humidity']; ?>%
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($inspection['inspector_name']): ?>
                                            <small><?php echo htmlspecialchars($inspection['inspector_name']); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-sm btn-outline-primary" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- New Inspection Modal -->
<div class="modal fade" id="newInspectionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">New Quality Inspection</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="newInspectionForm">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="batch_number" class="form-label">Batch Number</label>
                                <input type="text" class="form-control" id="batch_number" name="batch_number" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="quality_grade" class="form-label">Quality Grade</label>
                                <select class="form-select" id="quality_grade" name="quality_grade" required>
                                    <option value="">Select Grade</option>
                                    <option value="A">Grade A - Excellent</option>
                                    <option value="B">Grade B - Good</option>
                                    <option value="C">Grade C - Fair</option>
                                    <option value="D">Grade D - Poor</option>
                                    <option value="F">Grade F - Failed</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="temperature" class="form-label">Temperature (°C)</label>
                                <input type="number" step="0.1" class="form-control" id="temperature" name="temperature">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="humidity" class="form-label">Humidity (%)</label>
                                <input type="number" step="0.1" class="form-control" id="humidity" name="humidity">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Inspection</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Auto-refresh every 5 minutes
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 300000);

// Handle new inspection form
document.getElementById('newInspectionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    alert('Quality inspection functionality would be implemented here.');
    bootstrap.Modal.getInstance(document.getElementById('newInspectionModal')).hide();
});
</script>

<?php include '../../includes/footer.php'; ?>
