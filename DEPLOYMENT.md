# Cloudways Deployment Guide for Tukio Langu App

## Pre-Deployment Checklist

### 1. Database Setup on Cloudways

1. Create a new MySQL database from Cloudways panel
2. Note down the credentials:
   - Database Name
   - Database Username
   - Database Password
   - Database Host (usually localhost)

3. Import the schema:
   ```sql
   -- Run the contents of src/Database/schema.sql in phpMyAdmin
   ```

4. Create the sms_logs and email_logs tables if not already in schema:
   ```sql
   CREATE TABLE IF NOT EXISTS sms_logs (
       id INT AUTO_INCREMENT PRIMARY KEY,
       participant_id INT NOT NULL,
       event_id INT NOT NULL,
       phone VARCHAR(50) NOT NULL,
       message TEXT NOT NULL,
       status ENUM('sent', 'failed') DEFAULT 'sent',
       response TEXT,
       sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
       FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
       FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
       INDEX idx_participant_event (participant_id, event_id)
   );

   CREATE TABLE IF NOT EXISTS email_logs (
       id INT AUTO_INCREMENT PRIMARY KEY,
       participant_id INT NOT NULL,
       event_id INT NOT NULL,
       email VARCHAR(255) NOT NULL,
       subject VARCHAR(255) NOT NULL,
       status ENUM('sent', 'failed') DEFAULT 'sent',
       response TEXT,
       sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
       FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
       FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
       INDEX idx_participant_event (participant_id, event_id)
   );
   ```

### 2. Configure Production Database

Edit `config/database.php` with your Cloudways credentials:

```php
<?php
// Production Database Configuration
define('DB_HOST', 'your-cloudways-db-host');
define('DB_NAME', 'your-database-name');
define('DB_USER', 'your-database-user');
define('DB_PASS', 'your-database-password');
define('DB_CHARSET', 'utf8mb4');
```

### 3. File Permissions

After uploading, set these permissions:
```bash
chmod 755 public/
chmod 755 output/
chmod 755 output/cards/
chmod 755 output/pdf/
chmod 755 output/qrcodes/
chmod 755 uploads/
chmod 755 uploads/designs/
chmod 755 uploads/excel/
```

### 4. Email Configuration

Update `config/mail.php` with production SMTP settings if needed.

### 5. Document Root Configuration

On Cloudways, set the document root to point to the `public` folder:
- Go to Application Settings → General
- Set **Web Application Path** to: `public`

This makes the app accessible at your domain root (e.g., https://yourdomain.com/) instead of https://yourdomain.com/public/

### 6. SSL Certificate

Enable SSL from Cloudways panel:
- Go to SSL Certificate
- Enable Let's Encrypt Free SSL

## Upload Instructions

### Option A: Using SFTP
1. Get SFTP credentials from Cloudways → Access Details
2. Upload all files to the `public_html` directory
3. The structure should be:
   ```
   public_html/
   ├── config/
   ├── output/
   ├── public/
   ├── src/
   ├── templates/
   ├── uploads/
   ├── vendor/
   └── composer.json
   ```

### Option B: Using Git
1. Push your project to GitHub/GitLab
2. Deploy from Cloudways using Git integration

## Post-Deployment

1. **Test the application**:
   - Visit your domain
   - Try logging in
   - Test QR code scanning
   - Test email/SMS sending

2. **Create admin user** (if not already in database):
   ```sql
   INSERT INTO users (username, email, password, full_name, role, status) 
   VALUES ('admin', 'admin@yourdomain.com', '$2y$10$yourhashedpassword', 'Admin User', 'admin', 'active');
   ```
   
   Or use this PHP to generate a password hash:
   ```php
   echo password_hash('your-password', PASSWORD_DEFAULT);
   ```

## Environment Detection

The app automatically detects the environment:
- **Local (XAMPP)**: If running from `/tukioqrcode/` subdirectory, BASE_PATH is `/tukioqrcode/public`
- **Production**: If not in subdirectory, BASE_PATH is empty (root)

This is configured in `config/app.php`.

## Troubleshooting

### 404 Errors
- Ensure document root is set to `public` folder
- Check that `.htaccess` is uploaded (if using Apache)

### Database Connection Errors
- Verify database credentials in `config/database.php`
- Check if database user has proper privileges

### Permission Denied Errors
- Ensure output/ and uploads/ directories are writable
- Run: `chmod -R 755 output/ uploads/`

### Email Not Sending
- Verify SMTP credentials in `config/mail.php`
- Check if Cloudways allows outgoing SMTP connections
- Consider using a transactional email service (Mailgun, SendGrid)

### SMS Not Sending
- Verify API credentials in `config/sms.php`
- Check if API endpoint is accessible from server
