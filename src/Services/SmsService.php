<?php

namespace App\Services;

/**
 * SMS Service for sending SMS via messaging-service.co.tz API
 */
class SmsService
{
    private string $baseUrl;
    private string $authToken;
    private string $senderId;
    private bool $testMode;
    
    public function __construct()
    {
        $this->baseUrl = SMS_API_URL;
        $this->authToken = SMS_AUTH_TOKEN;
        $this->senderId = SMS_SENDER_ID;
        $this->testMode = SMS_TEST_MODE;
    }
    
    /**
     * Send a single SMS
     */
    public function send(string $to, string $message): array
    {
        // Format phone number (ensure it starts with country code)
        $to = $this->formatPhoneNumber($to);
        
        // Choose endpoint based on test mode
        $endpoint = $this->testMode 
            ? '/api/sms/v1/test/text/single' 
            : '/api/sms/v1/text/single';
        
        $payload = [
            'from' => $this->senderId,
            'to' => $to,
            'text' => $message
        ];
        
        return $this->makeRequest($endpoint, $payload);
    }
    
    /**
     * Send SMS to multiple recipients
     */
    public function sendBulk(array $recipients, string $message): array
    {
        // Format all phone numbers
        $formattedRecipients = array_map(function($to) use ($message) {
            return [
                'to' => $this->formatPhoneNumber($to),
                'text' => $message
            ];
        }, $recipients);
        
        // Choose endpoint based on test mode
        $endpoint = $this->testMode 
            ? '/api/sms/v1/test/text/multi' 
            : '/api/sms/v1/text/multi';
        
        $payload = [
            'from' => $this->senderId,
            'messages' => $formattedRecipients
        ];
        
        return $this->makeRequest($endpoint, $payload);
    }
    
    /**
     * Check SMS balance
     */
    public function getBalance(): array
    {
        return $this->makeRequest('/api/sms/v1/balance', null, 'GET');
    }
    
    /**
     * Format phone number to international format
     */
    private function formatPhoneNumber(string $phone): string
    {
        // Remove any spaces, dashes, or special characters
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
     * Make HTTP request to the API
     */
    private function makeRequest(string $endpoint, ?array $payload = null, string $method = 'POST'): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $headers = [
            'Authorization: Basic ' . $this->authToken,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        if ($method === 'POST' && $payload !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'message' => 'cURL Error: ' . $error,
                'http_code' => 0
            ];
        }
        
        $data = json_decode($response, true);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'message' => 'Request successful',
                'http_code' => $httpCode,
                'data' => $data,
                'test_mode' => $this->testMode
            ];
        }
        
        return [
            'success' => false,
            'message' => $data['message'] ?? 'Request failed',
            'http_code' => $httpCode,
            'data' => $data
        ];
    }
    
    /**
     * Enable/disable test mode
     */
    public function setTestMode(bool $enabled): void
    {
        $this->testMode = $enabled;
    }
    
    /**
     * Check if test mode is enabled
     */
    public function isTestMode(): bool
    {
        return $this->testMode;
    }
    
    /**
     * Send invitation SMS to a participant
     */
    public function sendInvitation(array $participant, array $event): array
    {
        $phone = $participant['phone'] ?? '';
        
        if (empty($phone)) {
            return [
                'success' => false,
                'message' => 'No phone number available'
            ];
        }
        
        $message = $this->buildInvitationMessage($participant, $event);
        
        return $this->send($phone, $message);
    }
    
    /**
     * Send invitation SMS to multiple participants
     */
    public function sendBulkInvitations(array $participants, array $event): array
    {
        $sent = 0;
        $failed = 0;
        $errors = [];
        
        foreach ($participants as $participant) {
            $result = $this->sendInvitation($participant, $event);
            
            if ($result['success']) {
                $sent++;
            } else {
                $failed++;
                $errors[] = $participant['name'] . ': ' . $result['message'];
            }
        }
        
        return [
            'success' => $failed === 0,
            'message' => "Sent: {$sent}, Failed: {$failed}",
            'sent' => $sent,
            'failed' => $failed,
            'errors' => $errors
        ];
    }
    
    /**
     * Build invitation SMS message
     * Structured format with event details
     */
    private function buildInvitationMessage(array $participant, array $event): string
    {
        $eventName = $event['event_name'] ?? 'Event';
        $eventVenue = $event['event_venue'] ?? '';
        $participantName = $participant['name'] ?? 'Guest';
        $ticketType = $participant['ticket_type'] ?? 'Standard';
        $ticketId = $participant['unique_id'] ?? '';
        $totalGuests = $participant['total_guests'] ?? 1;
        
        // Format date in day/month/year format
        $eventDate = !empty($event['event_date']) 
            ? date('d/m/Y', strtotime($event['event_date'])) 
            : 'TBA';
        
        // Format time with period of day
        $eventTime = '';
        $timePeriod = '';
        if (!empty($event['event_time'])) {
            $hour = (int) date('G', strtotime($event['event_time']));
            $eventTime = date('g:i A', strtotime($event['event_time']));
            
            if ($hour >= 5 && $hour < 12) {
                $timePeriod = 'Asubuhi';
            } elseif ($hour >= 12 && $hour < 16) {
                $timePeriod = 'Mchana';
            } elseif ($hour >= 16 && $hour < 19) {
                $timePeriod = 'Jioni';
            } else {
                $timePeriod = 'Usiku';
            }
        }
        
        // Build structured message
        $message = "Mpendwa {$participantName},\n";
        $message .= "Unakaribishwa Kuhudhuria {$eventName}\n\n";
        
        $message .= "Taarifa za Tukio:\n";
        $message .= "Tarehe: {$eventDate}\n";
        if ($eventTime) {
            $message .= "Muda: {$eventTime} {$timePeriod}\n";
        }
        if ($eventVenue) {
            $message .= "Mahali: {$eventVenue}\n";
        }
        
        $message .= "\nTiketi Yako:\n";
        $message .= "Aina: {$ticketType}\n";
        $message .= "Utambulisho: {$ticketId}\n";
        $message .= "Idadi: {$totalGuests}\n\n";
        
        $message .= "Onyesha kadi yako au ujumbe huu kwenye lango la kuingia kwa ajili ya kuingia.\n";
        $message .= "Karibu sana!\n\n";
        $message .= "©Tukio Langu App";
        
        return $message;
    }
}
