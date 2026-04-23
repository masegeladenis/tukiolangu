<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class EmailService
{
    private PHPMailer $mailer;
    
    public function __construct()
    {
        $this->mailer = new PHPMailer(true);
        $this->configure();
    }
    
    /**
     * Configure PHPMailer with Gmail SMTP
     */
    private function configure(): void
    {
        // Server settings
        $this->mailer->isSMTP();
        $this->mailer->Host = MAIL_HOST;
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = MAIL_USERNAME;
        $this->mailer->Password = MAIL_PASSWORD;
        $this->mailer->SMTPSecure = MAIL_ENCRYPTION === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $this->mailer->Port = MAIL_PORT;
        
        // Default sender
        $this->mailer->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        
        // Email format
        $this->mailer->isHTML(true);
        $this->mailer->CharSet = 'UTF-8';
    }
    
    /**
     * Send an email
     */
    public function send(string $to, string $subject, string $htmlBody, string $textBody = ''): array
    {
        try {
            // Clear previous recipients
            $this->mailer->clearAddresses();
            
            // Add recipient
            $this->mailer->addAddress($to);
            
            // Content
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $htmlBody;
            $this->mailer->AltBody = $textBody ?: strip_tags($htmlBody);
            
            $this->mailer->send();
            
            return [
                'success' => true,
                'message' => 'Email sent successfully'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to send email: ' . $this->mailer->ErrorInfo
            ];
        }
    }
    
    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail(string $to, string $userName, string $resetLink): array
    {
        $subject = 'Password Reset - ' . APP_NAME;
        
        $htmlBody = $this->getPasswordResetTemplate($userName, $resetLink);
        
        $textBody = "Hello {$userName},\n\n";
        $textBody .= "You requested a password reset for your account.\n\n";
        $textBody .= "Click the link below to reset your password:\n";
        $textBody .= "{$resetLink}\n\n";
        $textBody .= "This link will expire in 1 hour.\n\n";
        $textBody .= "If you didn't request this, please ignore this email.\n\n";
        $textBody .= "Regards,\n" . APP_NAME;
        
        return $this->send($to, $subject, $htmlBody, $textBody);
    }
    
    /**
     * Get HTML template for password reset email
     */
    private function getPasswordResetTemplate(string $userName, string $resetLink): string
    {
        $year = date('Y');
        $appName = APP_NAME;
        
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Password Reset</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f3f4f6; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;">
    
    <!-- Preheader Text (hidden) -->
    <div style="display: none; max-height: 0; overflow: hidden;">
        Reset your password for {$appName}. This link expires in 1 hour.
    </div>
    
    <!-- Email Container -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                
                <!-- Email Card -->
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 480px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);">
                    
                    <!-- Logo Section -->
                    <tr>
                        <td align="center" style="padding: 40px 40px 32px 40px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center" style="width: 56px; height: 56px; background-color: #6366f1; border-radius: 12px;">
                                        <span style="font-size: 28px; color: #ffffff;">🔐</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Title -->
                    <tr>
                        <td align="center" style="padding: 0 40px 16px 40px;">
                            <h1 style="margin: 0; font-size: 24px; font-weight: 600; color: #111827; line-height: 1.3;">Reset Your Password</h1>
                        </td>
                    </tr>
                    
                    <!-- Greeting -->
                    <tr>
                        <td style="padding: 0 40px 24px 40px;">
                            <p style="margin: 0; font-size: 15px; color: #4b5563; line-height: 1.6; text-align: center;">
                                Hi <strong style="color: #111827;">{$userName}</strong>,<br>
                                We received a request to reset your password.
                            </p>
                        </td>
                    </tr>
                    
                    <!-- CTA Button -->
                    <tr>
                        <td align="center" style="padding: 0 40px 32px 40px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center" style="background-color: #6366f1; border-radius: 8px;">
                                        <a href="{$resetLink}" target="_blank" style="display: inline-block; padding: 14px 32px; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none; border-radius: 8px;">Reset Password</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Expiry Notice -->
                    <tr>
                        <td style="padding: 0 40px 24px 40px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #fef3c7; border-radius: 8px;">
                                <tr>
                                    <td style="padding: 12px 16px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="vertical-align: top; padding-right: 10px;">
                                                    <span style="font-size: 16px;">⏱️</span>
                                                </td>
                                                <td>
                                                    <p style="margin: 0; font-size: 13px; color: #92400e; line-height: 1.5;">
                                                        This link will expire in <strong>1 hour</strong>. After that, you'll need to request a new one.
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Alternative Link -->
                    <tr>
                        <td style="padding: 0 40px 32px 40px;">
                            <p style="margin: 0 0 8px 0; font-size: 13px; color: #6b7280; line-height: 1.5;">
                                If the button doesn't work, copy and paste this link:
                            </p>
                            <p style="margin: 0; font-size: 12px; color: #6366f1; word-break: break-all; line-height: 1.5; background-color: #f3f4f6; padding: 12px; border-radius: 6px;">
                                {$resetLink}
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Divider -->
                    <tr>
                        <td style="padding: 0 40px;">
                            <div style="height: 1px; background-color: #e5e7eb;"></div>
                        </td>
                    </tr>
                    
                    <!-- Security Notice -->
                    <tr>
                        <td style="padding: 24px 40px 32px 40px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="vertical-align: top; padding-right: 10px;">
                                        <span style="font-size: 16px;">🛡️</span>
                                    </td>
                                    <td>
                                        <p style="margin: 0; font-size: 13px; color: #6b7280; line-height: 1.6;">
                                            If you didn't request this password reset, please ignore this email. Your account is still secure.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f9fafb; padding: 24px 40px; border-radius: 0 0 12px 12px;">
                            <p style="margin: 0 0 8px 0; font-size: 13px; color: #6b7280; text-align: center; line-height: 1.5;">
                                {$appName}
                            </p>
                            <p style="margin: 0; font-size: 12px; color: #9ca3af; text-align: center; line-height: 1.5;">
                                © {$year} All rights reserved.
                            </p>
                        </td>
                    </tr>
                    
                </table>
                
                <!-- Footer Links -->
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 480px;">
                    <tr>
                        <td align="center" style="padding: 24px 20px;">
                            <p style="margin: 0; font-size: 12px; color: #9ca3af; line-height: 1.6;">
                                This is an automated message. Please do not reply to this email.
                            </p>
                        </td>
                    </tr>
                </table>
                
            </td>
        </tr>
    </table>
    
</body>
</html>
HTML;
    }
    
    /**
     * Send event invitation email to participant
     */
    public function sendInvitationEmail(array $participant, array $event, ?string $cardPath = null): array
    {
        $subject = "You're Invited: " . $event['event_name'];
        
        // Generate download link for the card
        $downloadToken = substr(md5($participant['unique_id'] . $participant['created_at']), 0, 16);
        $downloadLink = APP_URL . '/public/api/download-card.php?id=' . urlencode($participant['unique_id']) . '&token=' . $downloadToken;
        
        $htmlBody = $this->getInvitationTemplate($participant, $event, $downloadLink, !empty($cardPath));
        
        $eventDate = !empty($event['event_date']) ? date('F j, Y', strtotime($event['event_date'])) : 'TBA';
        $eventTime = !empty($event['event_time']) ? date('g:i A', strtotime($event['event_time'])) : '';
        
        $textBody = "Hello {$participant['name']},\n\n";
        $textBody .= "You are cordially invited to {$event['event_name']}!\n\n";
        $textBody .= "Event Details:\n";
        $textBody .= "Date: {$eventDate}\n";
        if ($eventTime) $textBody .= "Time: {$eventTime}\n";
        if (!empty($event['event_venue'])) $textBody .= "Venue: {$event['event_venue']}\n";
        $textBody .= "\nYour Ticket Details:\n";
        $textBody .= "Ticket Type: {$participant['ticket_type']}\n";
        $textBody .= "Ticket ID: {$participant['unique_id']}\n";
        $textBody .= "Guests Allowed: {$participant['total_guests']}\n\n";
        if ($cardPath) {
            $textBody .= "Download your invitation card: {$downloadLink}\n\n";
        }
        $textBody .= "Please keep this email for your records.\n\n";
        $textBody .= "We look forward to seeing you!\n\n";
        $textBody .= "Regards,\n" . APP_NAME;
        
        $result = $this->send($participant['email'], $subject, $htmlBody, $textBody);
        
        return $result;
    }
    
    /**
     * Get HTML template for invitation email
     */
    private function getInvitationTemplate(array $participant, array $event, string $downloadLink = '', bool $hasCard = false): string
    {
        $year = date('Y');
        $appName = APP_NAME;
        $participantName = htmlspecialchars($participant['name']);
        $eventName = htmlspecialchars($event['event_name']);
        $ticketType = htmlspecialchars($participant['ticket_type']);
        $ticketId = htmlspecialchars($participant['unique_id']);
        $totalGuests = (int) $participant['total_guests'];
        
        $eventDate = !empty($event['event_date']) ? date('l, F j, Y', strtotime($event['event_date'])) : 'To Be Announced';
        $eventDay = !empty($event['event_date']) ? date('d', strtotime($event['event_date'])) : '--';
        $eventMonth = !empty($event['event_date']) ? strtoupper(date('M', strtotime($event['event_date']))) : '---';
        $eventTime = !empty($event['event_time']) ? date('g:i A', strtotime($event['event_time'])) : '';
        $eventVenue = !empty($event['event_venue']) ? htmlspecialchars($event['event_venue']) : 'To Be Announced';
        $eventDescription = !empty($event['description']) ? htmlspecialchars($event['description']) : '';
        
        $organization = !empty($participant['organization']) ? htmlspecialchars($participant['organization']) : '';
        $organizationLine = $organization ? "<p style=\"margin: 4px 0 0 0; font-size: 13px; color: #6b7280;\">{$organization}</p>" : '';
        
        $downloadSection = $this->getDownloadCardSection($downloadLink, $hasCard);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>You're Invited - {$eventName}</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
</head>
<body style="margin: 0; padding: 0; font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f1f5f9; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;">
    
    <!-- Preheader -->
    <div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">
        🎉 You're invited to {$eventName}! Your exclusive ticket awaits - view details inside.
        &nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
    </div>
    
    <!-- Email Container -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f1f5f9;">
        <tr>
            <td align="center" style="padding: 40px 16px;">
                
                <!-- Main Card -->
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 520px; background: #ffffff; border-radius: 24px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);">
                    
                    <!-- Header Banner -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%); padding: 40px 32px; text-align: center;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center" style="padding-bottom: 16px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" style="background: rgba(255,255,255,0.2); border-radius: 100px;">
                                            <tr>
                                                <td style="padding: 6px 16px;">
                                                    <p style="margin: 0; font-size: 11px; font-weight: 600; color: #ffffff; text-transform: uppercase; letter-spacing: 2px;">✨ You're Invited ✨</p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center">
                                        <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #ffffff; line-height: 1.3;">{$eventName}</h1>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Greeting -->
                    <tr>
                        <td style="padding: 32px 32px 24px 32px;">
                            <p style="margin: 0; font-size: 16px; color: #374151; line-height: 1.6;">
                                Dear <strong style="color: #111827;">{$participantName}</strong>,
                            </p>
                            <p style="margin: 12px 0 0 0; font-size: 15px; color: #6b7280; line-height: 1.7;">
                                We're excited to invite you to this special event! Here are your ticket details.
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Event Details Card -->
                    <tr>
                        <td style="padding: 0 32px 24px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-radius: 16px; border: 1px solid #e2e8f0;">
                                <tr>
                                    <td style="padding: 24px;">
                                        
                                        <!-- Date & Time Row -->
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 16px;">
                                            <tr>
                                                <!-- Date Block -->
                                                <td style="width: 72px; vertical-align: top;">
                                                    <table role="presentation" cellpadding="0" cellspacing="0" style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); border-radius: 12px; text-align: center; width: 64px; box-shadow: 0 4px 14px -3px rgba(99, 102, 241, 0.5);">
                                                        <tr>
                                                            <td style="padding: 10px 8px 2px 8px;">
                                                                <p style="margin: 0; font-size: 26px; font-weight: 800; color: #ffffff; line-height: 1;">{$eventDay}</p>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <td style="padding: 0 8px 8px 8px;">
                                                                <p style="margin: 0; font-size: 11px; font-weight: 700; color: rgba(255,255,255,0.9); letter-spacing: 1px;">{$eventMonth}</p>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                                <!-- Date Text -->
                                                <td style="vertical-align: middle; padding-left: 16px;">
                                                    <p style="margin: 0; font-size: 15px; font-weight: 600; color: #1e293b;">{$eventDate}</p>
                                                    <p style="margin: 4px 0 0 0; font-size: 14px; color: #64748b;">{$eventTime}</p>
                                                </td>
                                            </tr>
                                        </table>
                                        
                                        <!-- Venue -->
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background: #ffffff; border-radius: 10px; border: 1px solid #e2e8f0;">
                                            <tr>
                                                <td style="padding: 14px 16px;">
                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                                        <tr>
                                                            <td style="width: 36px; vertical-align: top;">
                                                                <div style="width: 32px; height: 32px; background: linear-gradient(135deg, #f472b6 0%, #ec4899 100%); border-radius: 8px; text-align: center; line-height: 32px;">
                                                                    <span style="font-size: 16px;">📍</span>
                                                                </div>
                                                            </td>
                                                            <td style="padding-left: 12px; vertical-align: middle;">
                                                                <p style="margin: 0; font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px;">Venue</p>
                                                                <p style="margin: 2px 0 0 0; font-size: 15px; font-weight: 600; color: #1e293b;">{$eventVenue}</p>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                        
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Ticket Card -->
                    <tr>
                        <td style="padding: 0 32px 24px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background: linear-gradient(145deg, #1e293b 0%, #334155 100%); border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(30, 41, 59, 0.3);">
                                
                                <!-- Ticket Header -->
                                <tr>
                                    <td style="padding: 20px 24px 16px 24px; border-bottom: 2px dashed rgba(255,255,255,0.15);">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td>
                                                    <p style="margin: 0; font-size: 11px; color: rgba(255,255,255,0.6); text-transform: uppercase; letter-spacing: 2px;">Your Ticket</p>
                                                    <p style="margin: 4px 0 0 0; font-size: 18px; font-weight: 700; color: #ffffff;">{$participantName}</p>
                                                    {$organizationLine}
                                                </td>
                                                <td style="text-align: right; vertical-align: top;">
                                                    <span style="display: inline-block; background: linear-gradient(135deg, #8b5cf6 0%, #6366f1 100%); padding: 6px 14px; border-radius: 100px; font-size: 12px; font-weight: 600; color: #ffffff;">{$ticketType}</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                
                                <!-- Ticket Body -->
                                <tr>
                                    <td style="padding: 20px 24px 24px 24px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="width: 60%; vertical-align: top;">
                                                    <p style="margin: 0; font-size: 11px; color: rgba(255,255,255,0.6); text-transform: uppercase; letter-spacing: 0.5px;">Ticket ID</p>
                                                    <p style="margin: 4px 0 0 0; font-size: 14px; font-weight: 700; color: #ffffff; font-family: 'SF Mono', Monaco, 'Courier New', monospace;">{$ticketId}</p>
                                                </td>
                                                <td style="width: 40%; text-align: right; vertical-align: top;">
                                                    <p style="margin: 0; font-size: 11px; color: rgba(255,255,255,0.6); text-transform: uppercase; letter-spacing: 0.5px;">Guests</p>
                                                    <p style="margin: 2px 0 0 0; font-size: 28px; font-weight: 800; color: #a5b4fc;">{$totalGuests}</p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Download Card Button -->
                    {$downloadSection}
                    
                    <!-- Check-in Reminder -->
                    <tr>
                        <td style="padding: 0 32px 32px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border-radius: 12px; border: 1px solid #a7f3d0;">
                                <tr>
                                    <td style="padding: 18px 20px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="width: 40px; vertical-align: top;">
                                                    <div style="width: 36px; height: 36px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 8px; text-align: center; line-height: 36px;">
                                                        <span style="font-size: 18px; color: #ffffff;">✓</span>
                                                    </div>
                                                </td>
                                                <td style="padding-left: 14px; vertical-align: middle;">
                                                    <p style="margin: 0; font-size: 14px; font-weight: 600; color: #065f46;">Quick Check-in</p>
                                                    <p style="margin: 4px 0 0 0; font-size: 13px; color: #047857; line-height: 1.5;">
                                                        Show your QR code at the entrance for instant access.
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background: #f8fafc; padding: 24px 32px; border-top: 1px solid #e2e8f0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center">
                                        <p style="margin: 0 0 6px 0; font-size: 15px; color: #374151; font-weight: 500;">
                                            We look forward to seeing you! 🎉
                                        </p>
                                        <p style="margin: 0; font-size: 13px; color: #9ca3af;">
                                            {$appName} &bull; &copy; {$year}
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                </table>
                
                <!-- Footer Note -->
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 520px;">
                    <tr>
                        <td align="center" style="padding: 24px 20px;">
                            <p style="margin: 0; font-size: 12px; color: #64748b; line-height: 1.6;">
                                This invitation was sent because you're registered for this event.<br>
                                Questions? Reply to this email or contact the event organizer.
                            </p>
                        </td>
                    </tr>
                </table>
                
            </td>
        </tr>
    </table>
    
</body>
</html>
HTML;
    }
    
    /**
     * Helper to generate time section HTML
     */
    private function getTimeSection(string $eventTime): string
    {
        if (empty($eventTime)) {
            return '';
        }
        
        return <<<HTML
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 12px;">
                                            <tr>
                                                <td style="width: 32px; vertical-align: top;">
                                                    <span style="font-size: 18px;">🕐</span>
                                                </td>
                                                <td>
                                                    <p style="margin: 0; font-size: 14px; color: #6b7280;">Time</p>
                                                    <p style="margin: 2px 0 0 0; font-size: 15px; font-weight: 600; color: #111827;">{$eventTime}</p>
                                                </td>
                                            </tr>
                                        </table>
HTML;
    }
    
    /**
     * Helper to generate download card section HTML
     */
    private function getDownloadCardSection(string $downloadLink, bool $hasCard): string
    {
        if (!$hasCard || empty($downloadLink)) {
            return '';
        }
        
        return <<<HTML
                    <tr>
                        <td style="padding: 0 32px 24px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center">
                                        <table role="presentation" cellpadding="0" cellspacing="0" style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); border-radius: 12px; box-shadow: 0 4px 14px -3px rgba(99, 102, 241, 0.4);">
                                            <tr>
                                                <td style="padding: 14px 32px;">
                                                    <a href="{$downloadLink}" target="_blank" style="display: block; text-decoration: none;">
                                                        <table role="presentation" cellpadding="0" cellspacing="0">
                                                            <tr>
                                                                <td style="vertical-align: middle; padding-right: 10px;">
                                                                    <span style="font-size: 18px;">📥</span>
                                                                </td>
                                                                <td style="vertical-align: middle;">
                                                                    <p style="margin: 0; font-size: 14px; font-weight: 700; color: #ffffff;">Download Your Card</p>
                                                                    <p style="margin: 2px 0 0 0; font-size: 12px; color: rgba(255,255,255,0.8);">Get your QR code for check-in</p>
                                                                </td>
                                                            </tr>
                                                        </table>
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
HTML;
    }
}
