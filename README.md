# Tukio Langu App

A PHP web application for generating QR codes on event cards and managing event check-ins.

## Features

- 📤 **Upload Card Designs** - Upload PNG/JPG card templates
- 📊 **Excel Import** - Import participant lists from Excel files
- 🔲 **QR Code Generation** - Automatically generate unique QR codes
- 🎴 **Card Generation** - Overlay QR codes on card designs
- 📥 **Download Options** - Download cards as PDF or ZIP
- 📱 **QR Scanner** - Scan and verify tickets at the event
- ✅ **Check-in System** - Track guest check-ins with partial support
- 📈 **Reports** - View event statistics and analytics

## Requirements

- PHP >= 7.4
- MySQL 5.7+
- GD Library (for image processing)
- Composer (for dependencies)

## Installation

### 1. Install Composer (if not installed)

Download and install from: https://getcomposer.org/download/

### 2. Install Dependencies

```bash
cd c:\xampp\htdocs\tukioqrcode
composer install
```

### 3. Create Database

- Open phpMyAdmin: http://localhost/phpmyadmin
- Import the file: `src/Database/schema.sql`

Or run in MySQL:
```sql
SOURCE c:/xampp/htdocs/tukioqrcode/src/Database/schema.sql;
```

### 4. Configure Database

Edit `config/database.php` if needed:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'tukio_qrcode');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### 5. Set Folder Permissions

Make sure these folders are writable:
- `uploads/`
- `output/`

### 6. Access the Application

Open in browser: http://localhost/tukioqrcode/public/

## Default Login

- **Username:** admin
- **Password:** admin123

## Usage

### Creating Cards

1. **Create Event** - Go to Events → Create Event
2. **Upload Files** - Go to Upload Cards
   - Select the event
   - Upload card design (PNG/JPG)
   - Upload Excel file with participant list
3. **Process Batch** - Click "Start Processing"
4. **Download** - Download cards as PDF or ZIP

### Excel Template

Your Excel file should have these columns:

| Name | Email | Phone | Ticket Type | Guests |
|------|-------|-------|-------------|--------|
| John Doe | john@email.com | +1234567890 | VIP | 2 |
| Jane Smith | jane@email.com | +0987654321 | Single | 1 |

### Scanning Tickets

1. Go to Scanner page
2. Allow camera access
3. Point camera at QR code
4. Verify and check-in guests

## Project Structure

```
tukioqrcode/
├── config/              # Configuration files
├── public/              # Web-accessible files
│   ├── api/             # API endpoints
│   ├── batches/         # Batch processing
│   ├── events/          # Event management
│   ├── participants/    # Participant management
│   ├── reports/         # Reports & analytics
│   ├── scanner/         # QR scanner
│   ├── css/             # Stylesheets
│   └── js/              # JavaScript
├── src/                 # PHP source code
│   ├── Database/        # Database connection
│   ├── Helpers/         # Helper classes
│   └── Services/        # Core services
├── templates/           # Layout templates
├── uploads/             # Uploaded files
└── output/              # Generated files
```

## License

MIT License
