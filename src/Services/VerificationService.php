<?php

namespace App\Services;

use App\Database\Connection;

class VerificationService
{
    private $db;

    public function __construct()
    {
        $this->db = Connection::getInstance();
    }

    /**
     * Verify a ticket by unique ID or QR data
     */
    public function verify(string $qrData): array
    {
        // Try to decode as JSON first
        $decoded = json_decode($qrData, true);
        $uniqueId = $decoded['id'] ?? $qrData;

        // Lookup participant
        $participant = $this->db->queryOne("
            SELECT 
                p.*,
                e.event_name,
                e.event_date,
                e.event_venue,
                e.status as event_status,
                e.event_code
            FROM participants p
            JOIN events e ON p.event_id = e.id
            WHERE p.unique_id = :unique_id
            LIMIT 1
        ", ['unique_id' => $uniqueId]);

        if (!$participant) {
            return [
                'success' => false,
                'status' => 'not_found',
                'message' => 'Ticket not found in system',
                'scanned_id' => $uniqueId
            ];
        }

        // Check ticket status
        if ($participant['status'] !== 'active') {
            return [
                'success' => false,
                'status' => 'invalid',
                'message' => 'Ticket has been ' . $participant['status'],
                'data' => [
                    'unique_id' => $participant['unique_id'],
                    'name' => $participant['name'],
                    'status' => $participant['status']
                ]
            ];
        }

        // Check event status
        if ($participant['event_status'] !== 'active') {
            return [
                'success' => false,
                'status' => 'event_inactive',
                'message' => 'This event is not currently active',
                'data' => [
                    'event_name' => $participant['event_name'],
                    'event_status' => $participant['event_status']
                ]
            ];
        }

        // Get check-in history
        $history = $this->db->query("
            SELECT 
                guests_this_checkin,
                created_at,
                gate_location
            FROM checkin_logs 
            WHERE participant_id = :id AND action = 'check_in'
            ORDER BY created_at DESC
            LIMIT 5
        ", ['id' => $participant['id']]);

        // Determine status
        $totalGuests = (int) $participant['total_guests'];
        $guestsCheckedIn = (int) $participant['guests_checked_in'];
        $guestsRemaining = (int) $participant['guests_remaining'];
        $isFullyChecked = (bool) $participant['is_fully_checked_in'];

        $baseData = [
            'unique_id' => $participant['unique_id'],
            'name' => $participant['name'],
            'email' => $participant['email'],
            'phone' => $participant['phone'],
            'organization' => $participant['organization'],
            'ticket_type' => $participant['ticket_type'],
            'event_name' => $participant['event_name'],
            'event_date' => $participant['event_date'],
            'event_venue' => $participant['event_venue'],
            'total_guests' => $totalGuests,
            'guests_checked_in' => $guestsCheckedIn,
            'guests_remaining' => $guestsRemaining,
            'checkin_history' => $history
        ];

        if ($isFullyChecked || $guestsRemaining <= 0) {
            return [
                'success' => true,
                'status' => 'fully_checked',
                'message' => 'All guests have already checked in',
                'can_checkin' => false,
                'data' => array_merge($baseData, [
                    'first_checkin_at' => $participant['first_checkin_at'],
                    'last_checkin_at' => $participant['last_checkin_at']
                ])
            ];
        } elseif ($guestsCheckedIn > 0) {
            return [
                'success' => true,
                'status' => 'partial_checked',
                'message' => "{$guestsCheckedIn} of {$totalGuests} guests already checked in. {$guestsRemaining} remaining.",
                'can_checkin' => true,
                'data' => array_merge($baseData, [
                    'max_can_checkin' => $guestsRemaining,
                    'first_checkin_at' => $participant['first_checkin_at']
                ])
            ];
        } else {
            return [
                'success' => true,
                'status' => 'valid',
                'message' => 'Valid ticket. Ready for check-in.',
                'can_checkin' => true,
                'data' => array_merge($baseData, [
                    'max_can_checkin' => $totalGuests
                ])
            ];
        }
    }

    /**
     * Process check-in
     */
    public function checkIn(string $uniqueId, int $guestsCount = 1, ?int $scannedBy = null, string $gateLocation = 'Main Entrance', string $notes = ''): array
    {
        $db = $this->db->getConnection();

        try {
            $db->beginTransaction();

            // Get participant with lock
            $stmt = $db->prepare("
                SELECT * FROM participants 
                WHERE unique_id = :unique_id 
                FOR UPDATE
            ");
            $stmt->execute(['unique_id' => $uniqueId]);
            $participant = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$participant) {
                $db->rollBack();
                return ['success' => false, 'message' => 'Participant not found'];
            }

            if ($participant['status'] !== 'active') {
                $db->rollBack();
                return ['success' => false, 'message' => 'Ticket is ' . $participant['status']];
            }

            $guestsRemaining = (int) $participant['guests_remaining'];
            $guestsCheckedIn = (int) $participant['guests_checked_in'];
            $totalGuests = (int) $participant['total_guests'];

            if ($guestsCount > $guestsRemaining) {
                $db->rollBack();
                return [
                    'success' => false,
                    'message' => "Cannot check in {$guestsCount} guests. Only {$guestsRemaining} remaining.",
                    'guests_remaining' => $guestsRemaining
                ];
            }

            // Calculate new values
            $newGuestsChecked = $guestsCheckedIn + $guestsCount;
            $newGuestsRemaining = $guestsRemaining - $guestsCount;
            $isFullyChecked = ($newGuestsRemaining <= 0) ? 1 : 0;

            // Update participant
            $updateStmt = $db->prepare("
                UPDATE participants SET
                    guests_checked_in = :guests_checked_in,
                    guests_remaining = :guests_remaining,
                    is_fully_checked_in = :is_fully_checked,
                    first_checkin_at = COALESCE(first_checkin_at, NOW()),
                    first_checkin_by = COALESCE(first_checkin_by, :scanned_by),
                    last_checkin_at = NOW(),
                    last_checkin_by = :scanned_by2,
                    updated_at = NOW()
                WHERE id = :id
            ");

            $updateStmt->execute([
                'guests_checked_in' => $newGuestsChecked,
                'guests_remaining' => $newGuestsRemaining,
                'is_fully_checked' => $isFullyChecked,
                'scanned_by' => $scannedBy,
                'scanned_by2' => $scannedBy,
                'id' => $participant['id']
            ]);

            // Log check-in
            $logStmt = $db->prepare("
                INSERT INTO checkin_logs (
                    participant_id, event_id, action, 
                    guests_this_checkin, guests_before, guests_after,
                    scanned_by, device_info, ip_address, gate_location, notes
                ) VALUES (
                    :participant_id, :event_id, 'check_in',
                    :guests_this, :guests_before, :guests_after,
                    :scanned_by, :device, :ip, :gate, :notes
                )
            ");

            $logStmt->execute([
                'participant_id' => $participant['id'],
                'event_id' => $participant['event_id'],
                'guests_this' => $guestsCount,
                'guests_before' => $guestsCheckedIn,
                'guests_after' => $newGuestsChecked,
                'scanned_by' => $scannedBy,
                'device' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                'gate' => $gateLocation,
                'notes' => $notes
            ]);

            $db->commit();

            return [
                'success' => true,
                'status' => $isFullyChecked ? 'complete' : 'partial',
                'message' => $isFullyChecked
                    ? "All {$totalGuests} guests checked in - COMPLETE!"
                    : "{$guestsCount} guest(s) checked in. {$newGuestsRemaining} remaining.",
                'data' => [
                    'unique_id' => $participant['unique_id'],
                    'name' => $participant['name'],
                    'ticket_type' => $participant['ticket_type'],
                    'guests_just_checked' => $guestsCount,
                    'total_guests' => $totalGuests,
                    'guests_checked_in' => $newGuestsChecked,
                    'guests_remaining' => $newGuestsRemaining,
                    'is_fully_checked' => (bool) $isFullyChecked,
                    'checkin_time' => date('Y-m-d H:i:s')
                ]
            ];

        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return [
                'success' => false,
                'message' => 'System error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get event statistics
     */
    public function getEventStats(int $eventId): array
    {
        $stats = $this->db->queryOne("
            SELECT 
                COUNT(*) as total_participants,
                SUM(total_guests) as total_guests,
                SUM(guests_checked_in) as guests_checked_in,
                SUM(guests_remaining) as guests_remaining,
                SUM(is_fully_checked_in) as fully_checked_count,
                COUNT(CASE WHEN guests_checked_in > 0 THEN 1 END) as partial_checked_count
            FROM participants
            WHERE event_id = :event_id AND status = 'active'
        ", ['event_id' => $eventId]);

        return [
            'total_participants' => (int) ($stats['total_participants'] ?? 0),
            'total_guests' => (int) ($stats['total_guests'] ?? 0),
            'guests_checked_in' => (int) ($stats['guests_checked_in'] ?? 0),
            'guests_remaining' => (int) ($stats['guests_remaining'] ?? 0),
            'fully_checked_count' => (int) ($stats['fully_checked_count'] ?? 0),
            'partial_checked_count' => (int) ($stats['partial_checked_count'] ?? 0),
            'checkin_percentage' => $stats['total_guests'] > 0 
                ? round(($stats['guests_checked_in'] / $stats['total_guests']) * 100, 1) 
                : 0
        ];
    }
}
