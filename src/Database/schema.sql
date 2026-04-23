-- Tukio Langu App Database Schema
-- Run this in phpMyAdmin or MySQL CLI

CREATE DATABASE IF NOT EXISTS tukio_qrcode CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tukio_qrcode;

-- Admin users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL,
    role ENUM('admin', 'scanner') DEFAULT 'scanner',
    is_active TINYINT(1) DEFAULT 1,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Events table
CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_name VARCHAR(255) NOT NULL,
    event_code VARCHAR(50) NOT NULL UNIQUE,
    event_date DATE NULL,
    event_time TIME NULL,
    event_venue VARCHAR(500) NULL,
    description TEXT NULL,
    status ENUM('draft', 'active', 'completed', 'cancelled') DEFAULT 'draft',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Ticket types table
CREATE TABLE IF NOT EXISTS ticket_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    type_name VARCHAR(100) NOT NULL,
    type_code VARCHAR(50) NOT NULL,
    max_guests INT DEFAULT 1,
    price DECIMAL(10,2) DEFAULT 0.00,
    color VARCHAR(7) DEFAULT '#3498db',
    description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);

-- Batches table (for each upload session)
CREATE TABLE IF NOT EXISTS batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    batch_name VARCHAR(255) NOT NULL,
    design_path VARCHAR(500) NOT NULL,
    excel_path VARCHAR(500) NOT NULL,
    qr_position ENUM('bottom-left', 'bottom-right', 'top-left', 'top-right') DEFAULT 'bottom-right',
    qr_size INT DEFAULT 150,
    total_cards INT DEFAULT 0,
    processed INT DEFAULT 0,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    error_message TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Participants/Attendees table with guest tracking
CREATE TABLE IF NOT EXISTS participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL,
    event_id INT NOT NULL,
    ticket_type_id INT NULL,
    
    -- Personal info
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    organization VARCHAR(255) NULL,
    
    -- Ticket info
    unique_id VARCHAR(100) NOT NULL UNIQUE,
    ticket_type VARCHAR(100) NOT NULL,
    
    -- Guest tracking
    total_guests INT DEFAULT 1,
    guests_checked_in INT DEFAULT 0,
    guests_remaining INT DEFAULT 1,
    is_fully_checked_in TINYINT(1) DEFAULT 0,
    
    -- Custom fields from Excel
    custom_field_1 VARCHAR(255) NULL,
    custom_field_2 VARCHAR(255) NULL,
    custom_field_3 VARCHAR(255) NULL,
    
    -- QR and output
    qr_data TEXT NOT NULL,
    qr_code_path VARCHAR(500) NULL,
    card_output_path VARCHAR(500) NULL,
    
    -- First check-in tracking
    first_checkin_at TIMESTAMP NULL,
    first_checkin_by INT NULL,
    
    -- Last check-in tracking
    last_checkin_at TIMESTAMP NULL,
    last_checkin_by INT NULL,
    
    -- Metadata
    status ENUM('active', 'cancelled', 'revoked') DEFAULT 'active',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id) ON DELETE SET NULL,
    FOREIGN KEY (first_checkin_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (last_checkin_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Detailed check-in logs
CREATE TABLE IF NOT EXISTS checkin_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    event_id INT NOT NULL,
    
    -- Action details
    action ENUM('check_in', 'check_out', 'denied', 'manual_override') NOT NULL,
    guests_this_checkin INT DEFAULT 1,
    
    -- Running totals at time of scan
    guests_before INT DEFAULT 0,
    guests_after INT DEFAULT 0,
    
    -- Who/where/when
    scanned_by INT NULL,
    device_info VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    gate_location VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (scanned_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Indexes for fast lookups
CREATE INDEX idx_participant_unique_id ON participants(unique_id);
CREATE INDEX idx_participant_event_id ON participants(event_id);
CREATE INDEX idx_participant_batch_id ON participants(batch_id);
CREATE INDEX idx_participant_ticket_type ON participants(ticket_type);
CREATE INDEX idx_participant_checked_in ON participants(is_fully_checked_in);
CREATE INDEX idx_participant_status ON participants(status);
CREATE INDEX idx_checkin_event ON checkin_logs(event_id);
CREATE INDEX idx_checkin_time ON checkin_logs(created_at);
CREATE INDEX idx_event_code ON events(event_code);
CREATE INDEX idx_event_status ON events(status);

-- Password reset tokens table
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_password_reset_token ON password_resets(token);
CREATE INDEX idx_password_reset_expires ON password_resets(expires_at);

-- SMS logs table for tracking sent messages
CREATE TABLE IF NOT EXISTS sms_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    event_id INT NOT NULL,
    phone VARCHAR(50) NOT NULL,
    message_type ENUM('invitation', 'reminder', 'custom') DEFAULT 'invitation',
    status ENUM('sent', 'failed', 'pending') DEFAULT 'sent',
    api_response TEXT NULL,
    sent_by INT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_sms_participant ON sms_logs(participant_id);
CREATE INDEX idx_sms_event ON sms_logs(event_id);
CREATE INDEX idx_sms_sent_at ON sms_logs(sent_at);

-- Email logs table for tracking sent emails
CREATE TABLE IF NOT EXISTS email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    event_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    message_type ENUM('invitation', 'reminder', 'custom') DEFAULT 'invitation',
    status ENUM('sent', 'failed', 'pending') DEFAULT 'sent',
    error_message TEXT NULL,
    sent_by INT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (sent_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_email_participant ON email_logs(participant_id);
CREATE INDEX idx_email_event ON email_logs(event_id);
CREATE INDEX idx_email_sent_at ON email_logs(sent_at);

-- WhatsApp share logs table for tracking shared invitations
CREATE TABLE IF NOT EXISTS whatsapp_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    event_id INT NOT NULL,
    phone VARCHAR(50) NOT NULL,
    message_type ENUM('invitation', 'reminder', 'custom') DEFAULT 'invitation',
    shared_by INT NULL,
    shared_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_whatsapp_participant ON whatsapp_logs(participant_id);
CREATE INDEX idx_whatsapp_event ON whatsapp_logs(event_id);
CREATE INDEX idx_whatsapp_shared_at ON whatsapp_logs(shared_at);

-- Insert default admin user (password: admin123)
INSERT INTO users (username, password, full_name, email, role) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin@tukio.com', 'admin');

-- Insert sample scanner user (password: scanner123)
INSERT INTO users (username, password, full_name, email, role) 
VALUES ('scanner', '$2y$10$pN8x8c8l8r8h8w8K8S8C8eNq7M3vR6tN2pY4kL5mJ8hG9fD0xCvBn', 'Gate Scanner', 'scanner@tukio.com', 'scanner');
