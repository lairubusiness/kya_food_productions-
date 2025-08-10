<?php
/**
 * KYA Food Production - Section 1 Temperature Monitor
 * Modern room-based temperature and humidity monitoring system
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

// Create temperature_logs table if it doesn't exist
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS temperature_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            section INT NOT NULL,
            room_id VARCHAR(50) NOT NULL,
            room_name VARCHAR(100) NOT NULL,
            temperature DECIMAL(5,2) NOT NULL,
            humidity DECIMAL(5,2) NOT NULL,
            recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            alert_triggered BOOLEAN DEFAULT FALSE,
            status ENUM('Normal', 'Warning', 'Critical') DEFAULT 'Normal',
            notes TEXT,
            INDEX idx_section_date (section, recorded_at),
            INDEX idx_room (room_id)
        )
    ");
    
    // Insert sample data if table is empty
    $count = $conn->query("SELECT COUNT(*) as count FROM temperature_logs WHERE section = 1")->fetch()['count'];
    if ($count == 0) {
        $rooms = [
            ['id' => 'RM_A', 'name' => 'Storage Room A', 'temp_range' => [18, 22], 'humidity_range' => [50, 70]],
            ['id' => 'RM_B', 'name' => 'Storage Room B', 'temp_range' => [20, 24], 'humidity_range' => [55, 75]],
            ['id' => 'COLD', 'name' => 'Cold Storage', 'temp_range' => [2, 6], 'humidity_range' => [80, 90]],
            ['id' => 'DRY', 'name' => 'Drying Area', 'temp_range' => [25, 30], 'humidity_range' => [20, 40]],
            ['id' => 'PREP', 'name' => 'Preparation Room', 'temp_range' => [16, 20], 'humidity_range' => [45, 65]]
        ];
        
        // Generate sample data for last 24 hours
        for ($h = 24; $h >= 0; $h--) {
            $timestamp = date('Y-m-d H:i:s', strtotime("-$h hours"));
            
            foreach ($rooms as $room) {
                $temp = rand($room['temp_range'][0] * 10, $room['temp_range'][1] * 10) / 10;
                $humidity = rand($room['humidity_range'][0] * 10, $room['humidity_range'][1] * 10) / 10;
                
                // Occasionally add some out-of-range values for alerts
                if (rand(1, 10) == 1) {
                    $temp += rand(-50, 50) / 10;
                    $humidity += rand(-200, 200) / 10;
                }
                
                $alert = false;
                $status = 'Normal';
                
                if ($temp < $room['temp_range'][0] - 2 || $temp > $room['temp_range'][1] + 2) {
                    $alert = true;
                    $status = 'Critical';
                } elseif ($temp < $room['temp_range'][0] || $temp > $room['temp_range'][1]) {
                    $status = 'Warning';
                }
                
                if ($humidity < $room['humidity_range'][0] - 10 || $humidity > $room['humidity_range'][1] + 10) {
                    $alert = true;
                    $status = 'Critical';
                } elseif ($humidity < $room['humidity_range'][0] || $humidity > $room['humidity_range'][1]) {
                    $status = 'Warning';
                }
                
                $conn->prepare("
                    INSERT INTO temperature_logs (section, room_id, room_name, temperature, humidity, recorded_at, alert_triggered, status)
                    VALUES (1, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([$room['id'], $room['name'], $temp, $humidity, $timestamp, $alert, $status]);
            }
        }
    }
} catch (Exception $e) {
    error_log("Temperature monitor setup error: " . $e->getMessage());
}

// Get current room conditions
try {
    $currentRooms = $conn->prepare("
        SELECT 
            room_id,
            room_name,
            temperature,
            humidity,
            recorded_at,
            alert_triggered,
            status,
            CASE 
                WHEN temperature < 5 THEN 'Very Cold'
                WHEN temperature < 15 THEN 'Cold'
                WHEN temperature < 25 THEN 'Normal'
                WHEN temperature < 35 THEN 'Warm'
                ELSE 'Hot'
            END as temp_category
        FROM temperature_logs tl1
        WHERE section = 1
        AND recorded_at = (
            SELECT MAX(recorded_at) 
            FROM temperature_logs tl2 
            WHERE tl2.room_id = tl1.room_id 
            AND tl2.section = 1
        )
        ORDER BY room_name
    ");
    $currentRooms->execute();
    $rooms = $currentRooms->fetchAll(PDO::FETCH_ASSOC);
    
    // Get overall statistics
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
            COUNT(DISTINCT room_id) as room_count
        FROM temperature_logs
        WHERE section = 1 AND DATE(recorded_at) = CURDATE()
    ");
    $statsStmt->execute();
    $stats = $statsStmt->fetch();
    
} catch (Exception $e) {
    error_log("Temperature monitor data error: " . $e->getMessage());
    $rooms = [];
    $stats = ['total_readings' => 0, 'avg_temp' => 0, 'alert_count' => 0, 'room_count' => 0];
}

$pageTitle = 'Temperature Monitor - Section 1';
include '../../includes/header.php';
?>

<style>
/* Modern Temperature Monitor Styles */
.temp-monitor-container {
    background-color: #f8f9fa;
    min-height: 100vh;
    padding: 20px;
}

.monitor-header {
    background: linear-gradient(135deg, #2d5016 0%, #4a7c2a 100%);
    color: #ffffff;
    padding: 25px;
    border-radius: 15px;
    margin-bottom: 25px;
    box-shadow: 0 8px 25px rgba(45, 80, 22, 0.3);
}

.monitor-title {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 8px;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
}

.monitor-subtitle {
    font-size: 1.1rem;
    opacity: 0.9;
    margin-bottom: 0;
}

/* Room Grid Layout */
.rooms-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.room-card {
    background: #ffffff;
    border-radius: 20px;
    padding: 25px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    border: 2px solid transparent;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.room-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 6px;
    background: linear-gradient(90deg, #2d5016 0%, #4a7c2a 100%);
}

.room-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
    border-color: #4a7c2a;
}

.room-card.status-critical {
    border-color: #dc3545;
    background: linear-gradient(135deg, #ffffff 0%, #fff5f5 100%);
}

.room-card.status-critical::before {
    background: linear-gradient(90deg, #dc3545 0%, #e74c3c 100%);
}

.room-card.status-warning {
    border-color: #ffc107;
    background: linear-gradient(135deg, #ffffff 0%, #fffbf0 100%);
}

.room-card.status-warning::before {
    background: linear-gradient(90deg, #ffc107 0%, #ffca2c 100%);
}

.room-header {
    display: flex;
    justify-content: between;
    align-items: center;
    margin-bottom: 20px;
}

.room-name {
    font-size: 1.3rem;
    font-weight: 700;
    color: #2d5016;
    margin-bottom: 5px;
}

.room-id {
    font-size: 0.9rem;
    color: #6c757d;
    font-weight: 500;
}

.room-status {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.room-status.normal {
    background: #d4edda;
    color: #155724;
}

.room-status.warning {
    background: #fff3cd;
    color: #856404;
}

.room-status.critical {
    background: #f8d7da;
    color: #721c24;
}

.temp-humidity-display {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin-bottom: 20px;
}

.metric-box {
    text-align: center;
    padding: 20px;
    border-radius: 15px;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

.metric-value {
    font-size: 2.5rem;
    font-weight: 800;
    color: #2d5016;
    margin-bottom: 5px;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.metric-label {
    font-size: 0.9rem;
    color: #6c757d;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.metric-unit {
    font-size: 1.2rem;
    color: #495057;
    font-weight: 600;
}

.last-updated {
    text-align: center;
    color: #6c757d;
    font-size: 0.85rem;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #e9ecef;
}

/* Statistics Overview */
.stats-overview {
    background: linear-gradient(135deg, #2d5016 0%, #4a7c2a 100%);
    color: #ffffff;
    padding: 25px;
    border-radius: 15px;
    margin-bottom: 25px;
    box-shadow: 0 8px 25px rgba(45, 80, 22, 0.3);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.stat-item {
    text-align: center;
    padding: 20px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: #ffffff;
    margin-bottom: 8px;
}

.stat-label {
    font-size: 0.9rem;
    color: rgba(255, 255, 255, 0.8);
    font-weight: 500;
}

/* Alert Panel */
.alert-panel {
    background: #ffffff;
    border-radius: 15px;
    padding: 25px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    margin-bottom: 25px;
}

.alert-header {
    display: flex;
    align-items: center;
    margin-bottom: 20px;
}

.alert-icon {
    width: 45px;
    height: 45px;
    background: linear-gradient(135deg, #dc3545 0%, #e74c3c 100%);
    color: #ffffff;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    margin-right: 15px;
}

.alert-title {
    font-size: 1.4rem;
    font-weight: 700;
    color: #dc3545;
    margin-bottom: 5px;
}

.alert-count {
    font-size: 1rem;
    color: #6c757d;
}

/* Controls Panel */
.controls-panel {
    background: #ffffff;
    border-radius: 15px;
    padding: 25px;
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    margin-bottom: 25px;
}

.controls-header {
    font-size: 1.3rem;
    font-weight: 700;
    color: #2d5016;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
}

.controls-icon {
    width: 35px;
    height: 35px;
    background: linear-gradient(135deg, #2d5016 0%, #4a7c2a 100%);
    color: #ffffff;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    font-size: 1.1rem;
}

.control-group {
    margin-bottom: 20px;
}

.control-label {
    font-weight: 600;
    color: #495057;
    margin-bottom: 8px;
    font-size: 0.95rem;
}

.modern-form-control {
    border: 2px solid #e9ecef;
    border-radius: 10px;
    padding: 12px 15px;
    transition: all 0.3s ease;
    background: #ffffff;
}

.modern-form-control:focus {
    border-color: #4a7c2a;
    box-shadow: 0 0 0 0.2rem rgba(74, 124, 42, 0.25);
    background: #ffffff;
}

.modern-btn {
    padding: 12px 25px;
    border-radius: 10px;
    font-weight: 600;
    transition: all 0.3s ease;
    border: none;
}

.modern-btn-primary {
    background: linear-gradient(135deg, #2d5016 0%, #4a7c2a 100%);
    color: #ffffff;
    box-shadow: 0 4px 15px rgba(45, 80, 22, 0.3);
}

.modern-btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(45, 80, 22, 0.4);
    background: linear-gradient(135deg, #4a7c2a 0%, #2d5016 100%);
}

/* Responsive Design */
@media (max-width: 768px) {
    .rooms-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .temp-humidity-display {
        grid-template-columns: 1fr;
        gap: 10px;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }
    
    .monitor-header {
        padding: 20px;
    }
    
    .monitor-title {
        font-size: 1.5rem;
    }
}

@media (max-width: 576px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .temp-monitor-container {
        padding: 15px;
    }
}
</style>

<div class="content-area temp-monitor-container">
    <!-- Modern Header -->
    <div class="monitor-header">
        <h1 class="monitor-title">
            <i class="fas fa-thermometer-half me-3"></i>
            Temperature Monitor
        </h1>
        <p class="monitor-subtitle">Real-time monitoring of storage room conditions</p>
    </div>
    
    <!-- Statistics Overview -->
    <div class="stats-overview">
        <h3 class="text-white mb-4">
            <i class="fas fa-chart-line me-2"></i>
            Today's Overview
        </h3>
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-value"><?php echo count($rooms); ?></div>
                <div class="stat-label">Active Rooms</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo number_format($stats['avg_temp'] ?? 0, 1); ?>°C</div>
                <div class="stat-label">Avg Temperature</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo $stats['alert_count'] ?? 0; ?></div>
                <div class="stat-label">Active Alerts</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo $stats['total_readings'] ?? 0; ?></div>
                <div class="stat-label">Today's Readings</div>
            </div>
        </div>
    </div>
    
    <!-- Alert Panel -->
    <?php 
    $criticalRooms = array_filter($rooms, function($room) { return $room['status'] === 'Critical'; });
    if (!empty($criticalRooms)): 
    ?>
    <div class="alert-panel">
        <div class="alert-header">
            <div class="alert-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div>
                <div class="alert-title">Critical Alerts</div>
                <div class="alert-count"><?php echo count($criticalRooms); ?> rooms require immediate attention</div>
            </div>
        </div>
        <div class="row">
            <?php foreach ($criticalRooms as $room): ?>
                <div class="col-md-6 mb-3">
                    <div class="alert alert-danger">
                        <strong><?php echo htmlspecialchars($room['room_name']); ?></strong><br>
                        Temperature: <?php echo $room['temperature']; ?>°C | 
                        Humidity: <?php echo $room['humidity']; ?>%
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Room Monitoring Grid -->
    <div class="rooms-grid">
        <?php foreach ($rooms as $room): ?>
            <div class="room-card status-<?php echo strtolower($room['status']); ?>">
                <div class="room-header">
                    <div>
                        <h4 class="room-name"><?php echo htmlspecialchars($room['room_name']); ?></h4>
                        <div class="room-id">ID: <?php echo htmlspecialchars($room['room_id']); ?></div>
                    </div>
                    <span class="room-status <?php echo strtolower($room['status']); ?>">
                        <?php echo $room['status']; ?>
                    </span>
                </div>
                
                <div class="temp-humidity-display">
                    <div class="metric-box">
                        <div class="metric-value">
                            <?php echo number_format($room['temperature'], 1); ?>
                            <span class="metric-unit">°C</span>
                        </div>
                        <div class="metric-label">Temperature</div>
                    </div>
                    <div class="metric-box">
                        <div class="metric-value">
                            <?php echo number_format($room['humidity'], 1); ?>
                            <span class="metric-unit">%</span>
                        </div>
                        <div class="metric-label">Humidity</div>
                    </div>
                </div>
                
                <div class="last-updated">
                    <i class="fas fa-clock me-1"></i>
                    Last updated: <?php echo date('M j, Y g:i A', strtotime($room['recorded_at'])); ?>
                </div>
                
                <?php if ($room['alert_triggered']): ?>
                    <div class="mt-3">
                        <div class="alert alert-warning mb-0 py-2">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Alert:</strong> Conditions outside normal range
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Controls Panel -->
    <div class="controls-panel">
        <div class="controls-header">
            <div class="controls-icon">
                <i class="fas fa-sliders-h"></i>
            </div>
            Monitor Controls
        </div>
        
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label class="control-label">Date From</label>
                <input type="date" name="date_from" class="form-control modern-form-control" 
                       value="<?php echo htmlspecialchars($_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'))); ?>">
            </div>
            <div class="col-md-4">
                <label class="control-label">Date To</label>
                <input type="date" name="date_to" class="form-control modern-form-control" 
                       value="<?php echo htmlspecialchars($_GET['date_to'] ?? date('Y-m-d')); ?>">
            </div>
            <div class="col-md-4">
                <label class="control-label">Room Filter</label>
                <select name="room_id" class="form-control modern-form-control">
                    <option value="">All Rooms</option>
                    <?php foreach ($rooms as $room): ?>
                        <option value="<?php echo htmlspecialchars($room['room_id']); ?>" 
                                <?php echo ($_GET['room_id'] ?? '') === $room['room_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($room['room_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <button type="submit" class="modern-btn modern-btn-primary me-2">
                    <i class="fas fa-filter me-2"></i>Apply Filters
                </button>
                <button type="button" class="modern-btn modern-btn-primary" onclick="location.reload()">
                    <i class="fas fa-sync-alt me-2"></i>Refresh Data
                </button>
                <a href="temperature_monitoring.php" class="modern-btn modern-btn-primary">
                    <i class="fas fa-chart-area me-2"></i>Detailed Analytics
                </a>
            </div>
        </form>
    </div>
    
    <?php if (empty($rooms)): ?>
        <div class="alert alert-info text-center py-5">
            <i class="fas fa-info-circle fa-3x mb-3 text-info"></i>
            <h4>No Room Data Available</h4>
            <p class="mb-0">Temperature monitoring data will appear here once sensors are connected.</p>
        </div>
    <?php endif; ?>
</div>

<script>
// Auto-refresh every 30 seconds
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 30000);

// Add real-time clock
function updateClock() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('en-US', {
        hour12: true,
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
    
    // Update any clock elements if they exist
    const clockElements = document.querySelectorAll('.live-clock');
    clockElements.forEach(el => el.textContent = timeString);
}

setInterval(updateClock, 1000);
updateClock();

// Add smooth animations on load
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.room-card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.6s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
