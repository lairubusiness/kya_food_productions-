<?php
/**
 * KYA Food Production - Section 1 Temperature Monitoring
 * Real-time temperature and humidity monitoring for raw material storage
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
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$location = $_GET['location'] ?? '';

// Create temperature_logs table if it doesn't exist
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS temperature_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            section INT NOT NULL,
            location VARCHAR(100) NOT NULL,
            temperature DECIMAL(5,2) NOT NULL,
            humidity DECIMAL(5,2) NOT NULL,
            recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            alert_triggered BOOLEAN DEFAULT FALSE,
            notes TEXT,
            INDEX idx_section_date (section, recorded_at),
            INDEX idx_location (location)
        )
    ");
    
    // Insert sample data if table is empty
    $count = $conn->query("SELECT COUNT(*) as count FROM temperature_logs WHERE section = 1")->fetch()['count'];
    if ($count == 0) {
        // Generate sample temperature data for the last 7 days
        for ($i = 7; $i >= 0; $i--) {
            $date = date('Y-m-d H:i:s', strtotime("-$i days"));
            for ($h = 0; $h < 24; $h += 2) { // Every 2 hours
                $timestamp = date('Y-m-d H:i:s', strtotime("$date +$h hours"));
                $locations = ['Storage Room A', 'Storage Room B', 'Cold Storage', 'Drying Area'];
                
                foreach ($locations as $loc) {
                    $baseTemp = ($loc == 'Cold Storage') ? 4 : 22;
                    $baseHumidity = ($loc == 'Drying Area') ? 30 : 60;
                    
                    $temp = $baseTemp + rand(-30, 30) / 10; // ±3°C variation
                    $humidity = $baseHumidity + rand(-100, 100) / 10; // ±10% variation
                    $alert = ($temp < 2 || $temp > 25 || $humidity < 20 || $humidity > 80) ? 1 : 0;
                    
                    $conn->prepare("
                        INSERT INTO temperature_logs (section, location, temperature, humidity, recorded_at, alert_triggered)
                        VALUES (1, ?, ?, ?, ?, ?)
                    ")->execute([$loc, $temp, $humidity, $timestamp, $alert]);
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("Temperature monitoring setup error: " . $e->getMessage());
}

// Build query conditions
$whereConditions = ['section = 1'];
$params = [1];

if ($date_from) {
    $whereConditions[] = "DATE(recorded_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $whereConditions[] = "DATE(recorded_at) <= ?";
    $params[] = $date_to;
}

if ($location) {
    $whereConditions[] = "location = ?";
    $params[] = $location;
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

// Get temperature monitoring data
try {
    // Current conditions (latest readings)
    $currentConditions = $conn->prepare("
        SELECT 
            location,
            temperature,
            humidity,
            recorded_at,
            alert_triggered,
            CASE 
                WHEN temperature < 2 OR temperature > 25 THEN 'Temperature Alert'
                WHEN humidity < 20 OR humidity > 80 THEN 'Humidity Alert'
                ELSE 'Normal'
            END as status
        FROM temperature_logs tl1
        WHERE section = 1
        AND recorded_at = (
            SELECT MAX(recorded_at) 
            FROM temperature_logs tl2 
            WHERE tl2.location = tl1.location 
            AND tl2.section = 1
        )
        ORDER BY location
    ");
    $currentConditions->execute();
    $currentReadings = $currentConditions->fetchAll();
    
    // Statistics
    $statsStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_readings,
            AVG(temperature) as avg_temp,
            MIN(temperature) as min_temp,
            MAX(temperature) as max_temp,
            AVG(humidity) as avg_humidity,
            MIN(humidity) as min_humidity,
            MAX(humidity) as max_humidity,
            COUNT(CASE WHEN alert_triggered = 1 THEN 1 END) as alert_count,
            COUNT(DISTINCT location) as location_count
        FROM temperature_logs
        $whereClause
    ");
    $statsStmt->execute($params);
    $stats = $statsStmt->fetch();
    
    // Hourly trends for charts (last 24 hours)
    $trendsStmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(recorded_at, '%Y-%m-%d %H:00:00') as hour,
            location,
            AVG(temperature) as avg_temp,
            AVG(humidity) as avg_humidity
        FROM temperature_logs
        WHERE section = 1 
        AND recorded_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY DATE_FORMAT(recorded_at, '%Y-%m-%d %H:00:00'), location
        ORDER BY hour, location
    ");
    $trendsStmt->execute();
    $hourlyTrends = $trendsStmt->fetchAll();
    
    // Recent alerts
    $alertsStmt = $conn->prepare("
        SELECT *
        FROM temperature_logs
        WHERE section = 1 
        AND alert_triggered = 1
        AND recorded_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
        ORDER BY recorded_at DESC
        LIMIT 20
    ");
    $alertsStmt->execute();
    $recentAlerts = $alertsStmt->fetchAll();
    
    // Get unique locations for filter
    $locationsStmt = $conn->prepare("SELECT DISTINCT location FROM temperature_logs WHERE section = 1 ORDER BY location");
    $locationsStmt->execute();
    $locations = $locationsStmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch (Exception $e) {
    error_log("Temperature monitoring data error: " . $e->getMessage());
    $currentReadings = [];
    $stats = ['total_readings' => 0, 'avg_temp' => 0, 'min_temp' => 0, 'max_temp' => 0, 'avg_humidity' => 0, 'min_humidity' => 0, 'max_humidity' => 0, 'alert_count' => 0, 'location_count' => 0];
    $hourlyTrends = [];
    $recentAlerts = [];
    $locations = [];
}

$pageTitle = 'Section 1 - Temperature Monitoring';
include '../../includes/header.php';
?>

<style>
    .stats-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
        color: white;
        transition: transform 0.2s;
    }
    .stats-card:hover {
        transform: translateY(-5px);
    }
    .stats-card .card-body {
        padding: 1.5rem;
    }
    .stats-card h3 {
        font-size: 2rem;
        font-weight: bold;
        margin-bottom: 0.5rem;
        color: white;
    }
    .stats-card p {
        margin-bottom: 0;
        opacity: 0.9;
        color: white;
    }
    .stats-card i {
        font-size: 2.5rem;
        opacity: 0.8;
        color: white;
    }
    
    .alert-card {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
        color: white;
    }
    .alert-card h3, .alert-card p, .alert-card i {
        color: white;
    }
    
    .success-card {
        background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
        color: white;
    }
    .success-card h3, .success-card p, .success-card i {
        color: white;
    }
    
    .info-card {
        background: linear-gradient(135deg, #339af0 0%, #228be6 100%);
        color: white;
    }
    .info-card h3, .info-card p, .info-card i {
        color: white;
    }
    
    .warning-card {
        background: linear-gradient(135deg, #ffd43b 0%, #fab005 100%);
        color: #212529;
    }
    .warning-card h3, .warning-card p, .warning-card i {
        color: #212529;
    }
    
    .dark-card {
        background: linear-gradient(135deg, #495057 0%, #343a40 100%);
        color: white;
    }
    .dark-card h3, .dark-card p, .dark-card i {
        color: white;
    }
    
    .purple-card {
        background: linear-gradient(135deg, #845ec2 0%, #6c5ce7 100%);
        color: white;
    }
    .purple-card h3, .purple-card p, .purple-card i {
        color: white;
    }
    
    /* Fix table text contrast */
    .table-dark {
        background-color: #212529;
        color: #ffffff;
    }
    .table-dark th,
    .table-dark td {
        color: #ffffff;
        border-color: #454d55;
    }
    .table-dark .text-muted {
        color: #adb5bd !important;
    }
    
    /* Fix filter section contrast */
    .bg-light {
        background-color: #f8f9fa !important;
        color: #212529 !important;
    }
    .bg-light .form-label,
    .bg-light .form-control,
    .bg-light .form-select {
        color: #212529 !important;
    }
    
    /* Fix card text contrast */
    .card {
        background-color: #ffffff;
        color: #212529;
    }
    .card-header {
        background-color: #f8f9fa;
        color: #212529;
        border-bottom: 1px solid #dee2e6;
    }
    .card-body {
        color: #212529;
    }
    
    /* Fix badge contrast */
    .badge {
        color: #ffffff;
    }
    .badge.bg-success {
        background-color: #198754 !important;
        color: #ffffff !important;
    }
    .badge.bg-warning {
        background-color: #ffc107 !important;
        color: #212529 !important;
    }
    .badge.bg-danger {
        background-color: #dc3545 !important;
        color: #ffffff !important;
    }
    .badge.bg-info {
        background-color: #0dcaf0 !important;
        color: #212529 !important;
    }
    
    /* Fix text muted contrast */
    .text-muted {
        color: #6c757d !important;
    }
    
    /* Fix alert section */
    .alert-section {
        background-color: #ffffff;
        color: #212529;
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
        padding: 1rem;
    }
    
    /* Fix no data message */
    .no-data {
        background-color: #f8f9fa;
        color: #6c757d;
        padding: 3rem;
        text-align: center;
        border-radius: 0.375rem;
    }
</style>

<div class="content-area">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <span class="badge me-2" style="background-color: <?php echo SECTIONS[1]['color']; ?>">
                    Section 1
                </span>
                Temperature Monitoring
            </h1>
            <p class="text-muted mb-0">Real-time monitoring of storage conditions for raw materials</p>
        </div>
        <div class="btn-group" role="group">
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
            <button onclick="exportData('csv')" class="btn btn-outline-primary">
                <i class="fas fa-download me-2"></i>Export Data
            </button>
            <button onclick="location.reload()" class="btn btn-primary">
                <i class="fas fa-sync-alt me-2"></i>Refresh
            </button>
        </div>
    </div>
    
    <!-- Current Status Alert -->
    <?php 
    $criticalAlerts = array_filter($currentReadings, function($reading) {
        return $reading['alert_triggered'] || $reading['status'] !== 'Normal';
    });
    ?>
    <?php if (!empty($criticalAlerts)): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Environmental Alert!</strong> 
            <?php echo count($criticalAlerts); ?> location(s) have conditions outside optimal ranges.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card">
                <div class="stats-icon primary">
                    <i class="fas fa-thermometer-half"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['avg_temp'], 1); ?>°C</div>
                <div class="stats-label">Avg Temperature</div>
                <div class="stats-sublabel">
                    <?php echo number_format($stats['min_temp'], 1); ?>° - <?php echo number_format($stats['max_temp'], 1); ?>°
                </div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card info">
                <div class="stats-icon info">
                    <i class="fas fa-tint"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['avg_humidity'], 1); ?>%</div>
                <div class="stats-label">Avg Humidity</div>
                <div class="stats-sublabel">
                    <?php echo number_format($stats['min_humidity'], 1); ?>% - <?php echo number_format($stats['max_humidity'], 1); ?>%
                </div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card <?php echo $stats['alert_count'] > 0 ? 'danger' : 'success'; ?>">
                <div class="stats-icon <?php echo $stats['alert_count'] > 0 ? 'danger' : 'success'; ?>">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['alert_count']); ?></div>
                <div class="stats-label">Alerts</div>
                <div class="stats-sublabel">In selected period</div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card secondary">
                <div class="stats-icon secondary">
                    <i class="fas fa-map-marker-alt"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['location_count']); ?></div>
                <div class="stats-label">Locations</div>
                <div class="stats-sublabel">Monitored</div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card warning">
                <div class="stats-icon warning">
                    <i class="fas fa-database"></i>
                </div>
                <div class="stats-number"><?php echo number_format($stats['total_readings']); ?></div>
                <div class="stats-label">Total Readings</div>
                <div class="stats-sublabel">In selected period</div>
            </div>
        </div>
        
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stats-card success">
                <div class="stats-icon success">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stats-number">
                    <?php echo !empty($currentReadings) ? timeAgo($currentReadings[0]['recorded_at']) : 'N/A'; ?>
                </div>
                <div class="stats-label">Last Update</div>
                <div class="stats-sublabel">Most recent reading</div>
            </div>
        </div>
    </div>
    
    <!-- Current Conditions -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-thermometer-half me-2"></i>Current Conditions
                        <span class="badge bg-primary ms-2"><?php echo count($currentReadings); ?> locations</span>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($currentReadings)): ?>
                        <div class="row">
                            <?php foreach ($currentReadings as $reading): ?>
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <div class="card h-100 <?php echo $reading['alert_triggered'] ? 'border-danger' : 'border-success'; ?>">
                                        <div class="card-body text-center">
                                            <h6 class="card-title">
                                                <i class="fas fa-map-marker-alt me-1"></i>
                                                <?php echo htmlspecialchars($reading['location']); ?>
                                            </h6>
                                            <div class="row">
                                                <div class="col-6">
                                                    <div class="display-6 <?php echo ($reading['temperature'] < 2 || $reading['temperature'] > 25) ? 'text-danger' : 'text-info'; ?>">
                                                        <?php echo number_format($reading['temperature'], 1); ?>°C
                                                    </div>
                                                    <small class="text-muted">Temperature</small>
                                                </div>
                                                <div class="col-6">
                                                    <div class="display-6 <?php echo ($reading['humidity'] < 20 || $reading['humidity'] > 80) ? 'text-danger' : 'text-success'; ?>">
                                                        <?php echo number_format($reading['humidity'], 1); ?>%
                                                    </div>
                                                    <small class="text-muted">Humidity</small>
                                                </div>
                                            </div>
                                            <hr>
                                            <span class="badge bg-<?php echo $reading['status'] === 'Normal' ? 'success' : 'warning'; ?>">
                                                <?php echo $reading['status']; ?>
                                            </span>
                                            <br><small class="text-muted">
                                                <?php echo formatDateTime($reading['recorded_at']); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-thermometer-half fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Current Readings</h5>
                            <p class="text-muted">No temperature data available for the selected criteria.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="location" class="form-label">Location</label>
                    <select name="location" id="location" class="form-select">
                        <option value="">All Locations</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo htmlspecialchars($loc); ?>" 
                                    <?php echo $location === $loc ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($loc); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label for="date_from" class="form-label">From Date</label>
                    <input type="date" name="date_from" id="date_from" class="form-control" 
                           value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                
                <div class="col-md-3">
                    <label for="date_to" class="form-label">To Date</label>
                    <input type="date" name="date_to" id="date_to" class="form-control" 
                           value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary d-block w-100">
                        <i class="fas fa-search me-2"></i>Filter Data
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Charts Row -->
    <div class="row mb-4">
        <!-- Temperature Trends -->
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-chart-line me-2"></i>Temperature Trends (24 Hours)
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="temperatureChart" height="300"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Humidity Trends -->
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-chart-area me-2"></i>Humidity Trends (24 Hours)
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="humidityChart" height="300"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Alerts -->
    <?php if (!empty($recentAlerts)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>Recent Alerts
                        <span class="badge bg-danger ms-2"><?php echo count($recentAlerts); ?> alerts</span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead class="table-dark">
                                <tr>
                                    <th>Location</th>
                                    <th>Alert Type</th>
                                    <th>Value</th>
                                    <th>Threshold</th>
                                    <th>Time</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentAlerts as $alert): ?>
                                    <tr>
                                        <td>
                                            <i class="fas fa-map-marker-alt me-1"></i>
                                            <?php echo htmlspecialchars($alert['location']); ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $alert['alert_type'] === 'temperature' ? 'danger' : 'warning'; ?>">
                                                <i class="fas fa-<?php echo $alert['alert_type'] === 'temperature' ? 'thermometer-half' : 'tint'; ?> me-1"></i>
                                                <?php echo ucfirst($alert['alert_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <strong class="text-<?php echo $alert['alert_type'] === 'temperature' ? 'danger' : 'warning'; ?>">
                                                <?php echo number_format($alert['value'], 1); ?><?php echo $alert['alert_type'] === 'temperature' ? '°C' : '%'; ?>
                                            </strong>
                                        </td>
                                        <td class="text-muted">
                                            <?php echo $alert['threshold']; ?>
                                        </td>
                                        <td>
                                            <?php echo formatDateTime($alert['recorded_at']); ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-danger">Active</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
        <div class="alert-section no-data">
            <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
            <h5 class="text-success">No Active Alerts</h5>
            <p class="text-muted">All environmental conditions are within normal ranges.</p>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Temperature Trends Chart
<?php if (!empty($hourlyTrends)): ?>
const tempCtx = document.getElementById('temperatureChart').getContext('2d');
const tempData = <?php echo json_encode($hourlyTrends); ?>;

// Group data by location
const tempByLocation = {};
tempData.forEach(item => {
    if (!tempByLocation[item.location]) {
        tempByLocation[item.location] = {
            labels: [],
            data: []
        };
    }
    tempByLocation[item.location].labels.push(new Date(item.hour).toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'}));
    tempByLocation[item.location].data.push(parseFloat(item.avg_temp));
});

const tempDatasets = Object.keys(tempByLocation).map((location, index) => {
    const colors = ['#007bff', '#28a745', '#ffc107', '#dc3545', '#6f42c1'];
    return {
        label: location,
        data: tempByLocation[location].data,
        borderColor: colors[index % colors.length],
        backgroundColor: colors[index % colors.length] + '20',
        tension: 0.4,
        fill: false
    };
});

new Chart(tempCtx, {
    type: 'line',
    data: {
        labels: Object.values(tempByLocation)[0]?.labels || [],
        datasets: tempDatasets
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: false,
                title: {
                    display: true,
                    text: 'Temperature (°C)'
                }
            },
            x: {
                title: {
                    display: true,
                    text: 'Time'
                }
            }
        },
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Humidity Trends Chart
const humidityCtx = document.getElementById('humidityChart').getContext('2d');

const humidityByLocation = {};
tempData.forEach(item => {
    if (!humidityByLocation[item.location]) {
        humidityByLocation[item.location] = {
            labels: [],
            data: []
        };
    }
    humidityByLocation[item.location].labels.push(new Date(item.hour).toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit'}));
    humidityByLocation[item.location].data.push(parseFloat(item.avg_humidity));
});

const humidityDatasets = Object.keys(humidityByLocation).map((location, index) => {
    const colors = ['#007bff', '#28a745', '#ffc107', '#dc3545', '#6f42c1'];
    return {
        label: location,
        data: humidityByLocation[location].data,
        borderColor: colors[index % colors.length],
        backgroundColor: colors[index % colors.length] + '20',
        tension: 0.4,
        fill: true
    };
});

new Chart(humidityCtx, {
    type: 'line',
    data: {
        labels: Object.values(humidityByLocation)[0]?.labels || [],
        datasets: humidityDatasets
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                max: 100,
                title: {
                    display: true,
                    text: 'Humidity (%)'
                }
            },
            x: {
                title: {
                    display: true,
                    text: 'Time'
                }
            }
        },
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});
<?php endif; ?>

// Export function
function exportData(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    window.open('export_temperature_data.php?' + params.toString(), '_blank');
}

// Auto-refresh every 2 minutes
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 120000);

// Real-time status indicator
function updateStatusIndicator() {
    const alertCount = <?php echo count($criticalAlerts); ?>;
    const statusElement = document.querySelector('.navbar .badge');
    if (statusElement && alertCount > 0) {
        statusElement.classList.add('bg-danger');
        statusElement.textContent = alertCount + ' alerts';
    }
}

updateStatusIndicator();
</script>

<?php include '../../includes/footer.php'; ?>
