<?php

namespace App\Services;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Color\Color;

class QRCodeGenerator
{
    private int $size;
    private int $margin;
    private string $outputPath;

    public function __construct(int $size = 150, int $margin = 10)
    {
        $this->size = $size;
        $this->margin = $margin;
        $this->outputPath = dirname(__DIR__, 2) . '/output/qrcodes';
        
        if (!is_dir($this->outputPath)) {
            mkdir($this->outputPath, 0755, true);
        }
    }

    /**
     * Generate QR code from data
     */
    public function generate(array $data, string $filename): string
    {
        // Encode data as JSON
        $qrData = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        // Create QR code
        $qrCode = new QrCode(
            data: $qrData,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: $this->size,
            margin: $this->margin,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            foregroundColor: new Color(0, 0, 0),
            backgroundColor: new Color(255, 255, 255)
        );
        
        // Write to file
        $writer = new PngWriter();
        $result = $writer->write($qrCode);
        
        $filePath = $this->outputPath . '/' . $filename . '.png';
        $result->saveToFile($filePath);
        
        return $filePath;
    }

    /**
     * Generate QR code and return as base64
     */
    public function generateBase64(array $data): string
    {
        $qrData = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        $qrCode = new QrCode(
            data: $qrData,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: $this->size,
            margin: $this->margin,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            foregroundColor: new Color(0, 0, 0),
            backgroundColor: new Color(255, 255, 255)
        );
        
        $writer = new PngWriter();
        $result = $writer->write($qrCode);
        
        return $result->getDataUri();
    }

    /**
     * Generate QR code and return GD image resource
     */
    public function generateGdImage(array $data)
    {
        $qrData = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        $qrCode = new QrCode(
            data: $qrData,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: $this->size,
            margin: $this->margin,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            foregroundColor: new Color(0, 0, 0),
            backgroundColor: new Color(255, 255, 255)
        );
        
        $writer = new PngWriter();
        $result = $writer->write($qrCode);
        
        // Create GD image from string
        return imagecreatefromstring($result->getString());
    }

    /**
     * Set QR code size
     */
    public function setSize(int $size): self
    {
        $this->size = $size;
        return $this;
    }

    /**
     * Set QR code margin
     */
    public function setMargin(int $margin): self
    {
        $this->margin = $margin;
        return $this;
    }
}
