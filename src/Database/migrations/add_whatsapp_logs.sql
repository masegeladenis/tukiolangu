-- Migration: Add WhatsApp logs table
-- Run this in phpMyAdmin or MySQL CLI
-- Date: 2024

USE tukio_qrcode;

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

-- Done!
SELECT 'WhatsApp logs table created successfully!' as status;
