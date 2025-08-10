# KYA Food Production Management System

A comprehensive web-based inventory and order management system designed specifically for food production operations, featuring multi-section workflow management, real-time monitoring, and export capabilities.

## 🚀 Features

### Core Functionality
- **Multi-Section Management**: Raw Material Handling, Dehydration Processing, Packaging & Storage
- **Real-time Inventory Tracking**: Live stock levels, expiry monitoring, quality grading
- **Order Management**: Complete order lifecycle from creation to shipping
- **Quality Control**: Batch tracking, temperature monitoring, quality assessments
- **Automated Alerts**: Low stock, expiry warnings, quality issues
- **Comprehensive Reporting**: Inventory, processing, quality, and financial reports
- **User Role Management**: Admin, Section Managers with appropriate permissions
- **Activity Logging**: Complete audit trail of all system activities

### Technical Features
- **Modern Web Interface**: Responsive Bootstrap 5 design
- **Real-time Notifications**: Instant alerts and updates
- **Data Visualization**: Charts and graphs for analytics
- **Export Capabilities**: PDF reports, data export functionality
- **Security**: Role-based access control, session management, input validation
- **API Integration**: RESTful APIs for system integration

## 🛠 Technology Stack

- **Backend**: PHP 8.x (Object-Oriented Programming)
- **Database**: MySQL 8.0+ with InnoDB engine
- **Frontend**: HTML5, CSS3, JavaScript (ES6+)
- **UI Framework**: Bootstrap 5.3.2
- **Icons**: Font Awesome 6.4.0
- **Charts**: Chart.js 4.4.0
- **AJAX**: jQuery 3.7.1
- **Additional Libraries**: 
  - PHPMailer (Email functionality)
  - mPDF (PDF generation)
  - DataTables (Advanced table features)

## 📋 System Requirements

### Server Requirements
- **Web Server**: Apache 2.4+ or Nginx 1.18+
- **PHP**: Version 8.0 or higher
- **Database**: MySQL 8.0+ or MariaDB 10.5+
- **Memory**: Minimum 256MB RAM
- **Storage**: Minimum 1GB free space

### PHP Extensions Required
- PDO MySQL
- JSON
- OpenSSL
- cURL
- GD or ImageMagick
- Zip
- MBString

## 🚀 Installation

### 1. Download and Setup
```bash
# Clone or download the project to your web server directory
# For XAMPP users:
cd C:\xampp\htdocs\
# Extract or clone the kya-food-production folder
```

### 2. Database Configuration
1. Start your MySQL server (XAMPP Control Panel)
2. Open your web browser and navigate to:
   ```
   http://localhost/kya-food-production/database/setup.php
   ```
3. The setup script will automatically:
   - Create the database
   - Create all required tables
   - Insert default settings
   - Create sample users and data

### 3. Configuration
1. Update database credentials in `config/database.php` if needed
2. Configure email settings in `config/constants.php`
3. Set appropriate file permissions for uploads directory

### 4. Access the System
Navigate to: `http://localhost/kya-food-production/`

## 👥 Default User Accounts

After running the database setup, you can log in with these accounts:

| Role | Username | Password | Access |
|------|----------|----------|---------|
| System Administrator | `admin` | `admin123` | Full system access |
| Section 1 Manager | `section1_mgr` | `section1123` | Raw Material Handling |
| Section 2 Manager | `section2_mgr` | `section2123` | Dehydration Processing |
| Section 3 Manager | `section3_mgr` | `section3123` | Packaging & Storage |

**⚠️ Important**: Change these default passwords immediately after first login!

## 📁 Project Structure

```
kya-food-production/
├── index.php                          # Main landing page
├── login.php                          # Authentication page
├── dashboard.php                       # Main dashboard
├── logout.php                         # Logout handler
├── README.md                          # This file
├── .htaccess                          # Apache configuration
├── config/                            # Configuration files
│   ├── database.php                   # Database connection
│   ├── constants.php                  # System constants
│   └── session.php                    # Session management
├── assets/                            # Static assets
│   ├── css/                          # Stylesheets
│   ├── js/                           # JavaScript files
│   ├── images/                       # Images and logos
│   └── uploads/                      # File uploads
├── includes/                          # Common includes
│   ├── header.php                    # Common header
│   ├── footer.php                    # Common footer
│   ├── sidebar.php                   # Navigation sidebar
│   ├── functions.php                 # Common functions
│   └── auth_check.php                # Authentication check
├── modules/                          # Feature modules
│   ├── admin/                        # Administration
│   ├── section1/                     # Raw Material Handling
│   ├── section2/                     # Dehydration Processing
│   ├── section3/                     # Packaging & Storage
│   ├── inventory/                    # Inventory Management
│   ├── orders/                       # Order Management
│   ├── reports/                      # Reports & Analytics
│   ├── notifications/                # Notification Center
│   └── profile/                      # User Profile
├── api/                              # API endpoints
├── database/                         # Database files
│   ├── setup.php                     # Database setup script
│   ├── kya_food_production.sql       # Database structure
│   └── sample_data.sql               # Sample data
├── docs/                             # Documentation
└── vendor/                           # Third-party libraries
```

## 🔧 Configuration

### Database Settings
Edit `config/database.php`:
```php
private $host = "localhost";
private $username = "root";
private $password = "";
private $database = "kya_food_production";
```

### Email Settings
Edit `config/constants.php`:
```php
define('SMTP_HOST', 'your-smtp-server.com');
define('SMTP_USERNAME', 'your-email@domain.com');
define('SMTP_PASSWORD', 'your-email-password');
```

### System Settings
Access Admin Panel → System Settings to configure:
- Company information
- Notification preferences
- Security settings
- Backup configuration

## 📊 Key Features Guide

### Inventory Management
- **Multi-section tracking**: Separate inventory for each production section
- **Real-time alerts**: Automatic notifications for low stock and expiring items
- **Quality grading**: A, B, C, D grade classification
- **Batch tracking**: Complete traceability from raw materials to finished products

### Processing Management
- **Batch processing logs**: Track input/output quantities, yield percentages
- **Quality control**: Monitor temperature, humidity, processing conditions
- **Equipment monitoring**: Track equipment usage and maintenance
- **Yield analysis**: Calculate and analyze processing efficiency

### Order Management
- **Complete order lifecycle**: From creation to delivery
- **Export documentation**: Compliance documents for international shipping
- **Payment tracking**: Monitor payment status and methods
- **Shipping integration**: Track shipments and delivery status

### Reporting & Analytics
- **Real-time dashboards**: Live statistics and KPIs
- **Comprehensive reports**: Inventory, processing, quality, financial reports
- **Data visualization**: Charts and graphs for trend analysis
- **Export capabilities**: PDF, Excel export functionality

## 🔒 Security Features

- **Role-based access control**: Different permissions for different user roles
- **Session management**: Secure session handling with timeout
- **Input validation**: Comprehensive validation and sanitization
- **SQL injection protection**: Prepared statements and parameterized queries
- **XSS protection**: Output encoding and CSP headers
- **Activity logging**: Complete audit trail of user actions

## 🚨 Troubleshooting

### Common Issues

**Database Connection Error**
- Check MySQL service is running
- Verify database credentials in `config/database.php`
- Ensure database exists and user has proper permissions

**Permission Denied Errors**
- Check file permissions on uploads directory
- Ensure web server has read/write access to necessary directories

**Login Issues**
- Verify user accounts exist in database
- Check for account lockouts due to failed login attempts
- Clear browser cache and cookies

**Performance Issues**
- Enable PHP OPcache
- Optimize MySQL configuration
- Enable gzip compression in .htaccess

## 📞 Support

For technical support or questions:
- **Email**: support@kyafood.com
- **Documentation**: Check the `docs/` directory
- **System Logs**: Check `logs/` directory for error logs

## 📄 License

This project is proprietary software developed for KYA Food Production. All rights reserved.

## 🔄 Version History

- **v1.0.0** (August 2024) - Initial release
  - Core inventory management
  - Multi-section workflow
  - Order management system
  - User authentication and authorization
  - Real-time notifications
  - Comprehensive reporting

---

**KYA Food Production Management System** - Streamlining food production operations with modern technology.
