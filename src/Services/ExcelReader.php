<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use App\Helpers\Utils;

class ExcelReader
{
    private array $requiredColumns = ['name'];
    private array $optionalColumns = ['email', 'phone', 'ticket_type', 'guests', 'organization'];
    private array $columnMapping = [];

    /**
     * Read Excel file and return data as array
     */
    public function read(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \Exception("File not found: {$filePath}");
        }

        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (count($rows) < 2) {
            throw new \Exception("Excel file must have at least a header row and one data row");
        }

        // First row is header
        $headers = array_map('strtolower', array_map('trim', $rows[0]));
        $this->mapColumns($headers);

        // Validate required columns
        $this->validateColumns($headers);

        // Parse data rows
        $data = [];
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            
            // Skip empty rows
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $data[] = $this->parseRow($row, $headers);
        }

        return $data;
    }

    /**
     * Map column names to their indices
     */
    private function mapColumns(array $headers): void
    {
        $this->columnMapping = [];
        
        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeColumnName($header);
            $this->columnMapping[$normalized] = $index;
        }
    }

    /**
     * Normalize column name for matching
     */
    private function normalizeColumnName(string $name): string
    {
        $name = strtolower(trim($name));
        
        // Common aliases
        $aliases = [
            'full name' => 'name',
            'fullname' => 'name',
            'participant' => 'name',
            'attendee' => 'name',
            'email address' => 'email',
            'e-mail' => 'email',
            'phone number' => 'phone',
            'telephone' => 'phone',
            'mobile' => 'phone',
            'tel' => 'phone',
            'ticket' => 'ticket_type',
            'ticket type' => 'ticket_type',
            'type' => 'ticket_type',
            'category' => 'ticket_type',
            'guest count' => 'guests',
            'number of guests' => 'guests',
            'pax' => 'guests',
            'company' => 'organization',
            'org' => 'organization',
            'institution' => 'organization',
        ];

        return $aliases[$name] ?? $name;
    }

    /**
     * Validate that required columns exist
     */
    private function validateColumns(array $headers): void
    {
        foreach ($this->requiredColumns as $required) {
            $found = false;
            foreach ($headers as $header) {
                if ($this->normalizeColumnName($header) === $required) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new \Exception("Required column missing: {$required}");
            }
        }
    }

    /**
     * Check if row is empty
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (!empty(trim((string)$cell))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Parse a single row into structured data
     */
    private function parseRow(array $row, array $headers): array
    {
        $data = [
            'name' => '',
            'email' => '',
            'phone' => '',
            'ticket_type' => 'Standard',
            'guests' => 1,
            'organization' => '',
            'custom_field_1' => '',
            'custom_field_2' => '',
            'custom_field_3' => '',
        ];

        $customFieldIndex = 1;

        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeColumnName($header);
            $value = isset($row[$index]) ? trim((string)$row[$index]) : '';

            if (array_key_exists($normalized, $data)) {
                if ($normalized === 'guests') {
                    $data[$normalized] = max(1, (int)$value);
                } elseif ($normalized === 'phone') {
                    // Format phone number: remove leading 0 and add 255
                    $data[$normalized] = Utils::formatPhoneNumber($value);
                } else {
                    $data[$normalized] = $value;
                }
            } else {
                // Store as custom field
                if ($customFieldIndex <= 3 && !empty($value)) {
                    $data["custom_field_{$customFieldIndex}"] = $value;
                    $customFieldIndex++;
                }
            }
        }

        // Ensure name is not empty
        if (empty($data['name'])) {
            $data['name'] = 'Unknown';
        }

        // Ensure ticket type is not empty
        if (empty($data['ticket_type'])) {
            $data['ticket_type'] = 'Standard';
        }

        return $data;
    }

    /**
     * Get preview of Excel file (first 5 rows)
     */
    public function preview(string $filePath, int $rows = 5): array
    {
        if (!file_exists($filePath)) {
            throw new \Exception("File not found: {$filePath}");
        }

        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $allRows = $worksheet->toArray();

        $preview = [];
        for ($i = 0; $i < min($rows + 1, count($allRows)); $i++) {
            $preview[] = $allRows[$i];
        }

        return [
            'headers' => $preview[0] ?? [],
            'data' => array_slice($preview, 1),
            'total_rows' => count($allRows) - 1
        ];
    }

    /**
     * Count total data rows
     */
    public function countRows(string $filePath): int
    {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        return $worksheet->getHighestRow() - 1; // Exclude header
    }
}
