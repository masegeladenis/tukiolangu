<?php

namespace App\Services;

/**
 * WhatsApp Service for generating share links
 * Uses WhatsApp Click-to-Chat API (wa.me) for manual sharing
 * This avoids automation that could get your number banned
 */
class WhatsAppService
{
    private string $baseUrl = 'https://wa.me/';
    private string $appUrl;
    
    public function __construct()
    {
        $this->appUrl = APP_URL;
    }
    
    /**
     * Generate WhatsApp share URL for a participant
     * Opens WhatsApp with pre-filled message (user must click send)
     */
    public function generateShareUrl(array $participant, array $event): array
    {
        $phone = $participant['phone'] ?? '';
        
        if (empty($phone)) {
            return [
                'success' => false,
                'message' => 'No phone number available'
            ];
        }
        
        // Format phone number for WhatsApp (remove + and spaces)
        $formattedPhone = $this->formatPhoneNumber($phone);
        
        // Build the invitation message with download link
        $message = $this->buildShareMessage($participant, $event);
        
        // Generate the wa.me URL
        $shareUrl = $this->baseUrl . $formattedPhone . '?text=' . urlencode($message);
        
        return [
            'success' => true,
            'url' => $shareUrl,
            'phone' => $formattedPhone,
            'message' => $message
        ];
    }
    
    /**
     * Generate card download link for participant
     */
    public function getCardDownloadLink(array $participant): string
    {
        $uniqueId = $participant['unique_id'];
        $token = substr(md5($uniqueId . $participant['created_at']), 0, 16);
        
        return $this->appUrl . '/public/api/download-card.php?id=' . urlencode($uniqueId) . '&token=' . $token;
    }
    
    /**
     * Format phone number for WhatsApp
     * WhatsApp requires format: country code + number (no + sign, no spaces)
     */
    private function formatPhoneNumber(string $phone): string
    {
        // Remove any spaces, dashes, plus signs, or special characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // If starts with 0, assume Tanzania and replace with 255
        if (str_starts_with($phone, '0')) {
            $phone = '255' . substr($phone, 1);
        }
        
        // If doesn't start with country code, assume Tanzania
        if (!str_starts_with($phone, '255') && strlen($phone) === 9) {
            $phone = '255' . $phone;
        }
        
        return $phone;
    }
    
    /**
     * Build the WhatsApp invitation message
     */
    private function buildShareMessage(array $participant, array $event): string
    {
        $eventName = $event['event_name'] ?? 'Event';
        $participantName = $participant['name'] ?? 'Guest';
        
        // Build simple message without link
        $message = "Habari {$participantName},\n\n";
        $message .= "Pokea kadi ya mwaliko wa {$eventName}.\n\n";
        $message .= "Asante";
        
        return $message;
    }
    
    /**
     * Build a custom WhatsApp message
     */
    public function buildCustomMessage(string $phone, string $message): array
    {
        if (empty($phone)) {
            return [
                'success' => false,
                'message' => 'No phone number provided'
            ];
        }
        
        $formattedPhone = $this->formatPhoneNumber($phone);
        $shareUrl = $this->baseUrl . $formattedPhone . '?text=' . urlencode($message);
        
        return [
            'success' => true,
            'url' => $shareUrl,
            'phone' => $formattedPhone,
            'message' => $message
        ];
    }
}
