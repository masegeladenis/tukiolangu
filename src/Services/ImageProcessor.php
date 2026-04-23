<?php

namespace App\Services;

class ImageProcessor
{
    private string $outputPath;
    private int $qrSize;
    private string $qrPosition;
    private int $padding;

    public function __construct()
    {
        $this->outputPath = dirname(__DIR__, 2) . '/output/cards';
        $this->qrSize = 150;
        $this->qrPosition = 'bottom-right';
        $this->padding = 20;

        if (!is_dir($this->outputPath)) {
            mkdir($this->outputPath, 0755, true);
        }
    }

    /**
     * Overlay QR code on design image.
     *
     * @param array $labels Optional. Keys: ticket_type (string), name (string), guests (int)
     *                      These are rendered as a label strip below the QR code on the card.
     */
    public function overlayQRCode(string $designPath, $qrImage, string $outputFilename, array $labels = []): string
    {
        // Load design image
        $designInfo = getimagesize($designPath);
        $designMime = $designInfo['mime'];

        switch ($designMime) {
            case 'image/jpeg':
            case 'image/jpg':
                $design = imagecreatefromjpeg($designPath);
                break;
            case 'image/png':
                $design = imagecreatefrompng($designPath);
                break;
            default:
                throw new \Exception("Unsupported image format: {$designMime}");
        }

        if (!$design) {
            throw new \Exception("Failed to load design image");
        }

        $designWidth  = imagesx($design);
        $designHeight = imagesy($design);

        // Handle QR image (can be resource or file path)
        if (is_string($qrImage)) {
            $qr = imagecreatefrompng($qrImage);
        } else {
            $qr = $qrImage;
        }

        if (!$qr) {
            throw new \Exception("Failed to load QR code image");
        }

        $qrWidth  = imagesx($qr);
        $qrHeight = imagesy($qr);

        // Build label lines to draw below the QR code
        // GD built-in font metrics: font5 = 9×15px, font3 = 7×13px
        $labelLines = [];
        if (!empty($labels['ticket_type'])) {
            $labelLines[] = ['text' => strtoupper($labels['ticket_type']), 'font' => 5];
        }
        if (!empty($labels['name'])) {
            // Truncate long names so they fit within the QR width
            $maxChars = (int) floor($qrWidth / 7);
            $name = strlen($labels['name']) > $maxChars
                ? substr($labels['name'], 0, $maxChars - 1) . '…'
                : $labels['name'];
            $labelLines[] = ['text' => $name, 'font' => 3];
        }
        if (!empty($labels['guests']) && (int) $labels['guests'] > 1) {
            $labelLines[] = ['text' => 'Guests: ' . (int) $labels['guests'], 'font' => 3];
        }

        // Calculate extra vertical space needed for labels
        // Each line: font height + 4px gap; plus 6px top padding + 4px bottom padding
        $fontHeights = [1 => 8, 2 => 9, 3 => 13, 4 => 15, 5 => 15];
        $labelAreaHeight = 0;
        if (!empty($labelLines)) {
            $labelAreaHeight = 8; // top + bottom padding (6+4 split later as 6 top)
            foreach ($labelLines as $line) {
                $labelAreaHeight += $fontHeights[$line['font']] + 4;
            }
        }

        // Calculate QR position (labels expand the white box downward)
        list($x, $y) = $this->calculatePosition(
            $designWidth,
            $designHeight,
            $qrWidth,
            $qrHeight + $labelAreaHeight   // reserve space below for labels
        );

        // Create output image with alpha support
        $output = imagecreatetruecolor($designWidth, $designHeight);
        imagealphablending($output, true);
        imagesavealpha($output, true);

        // Copy design to output
        imagecopy($output, $design, 0, 0, 0, 0, $designWidth, $designHeight);

        $white = imagecolorallocate($output, 255, 255, 255);
        $black = imagecolorallocate($output, 20, 20, 20);
        $bgPadding = 5;

        // White background covers QR + label area
        imagefilledrectangle(
            $output,
            $x - $bgPadding,
            $y - $bgPadding,
            $x + $qrWidth + $bgPadding,
            $y + $qrHeight + $labelAreaHeight + $bgPadding,
            $white
        );

        // Overlay QR code
        imagecopy($output, $qr, $x, $y, 0, 0, $qrWidth, $qrHeight);

        // Draw label lines below the QR code
        if (!empty($labelLines)) {
            $fontWidths = [1 => 5, 2 => 6, 3 => 7, 4 => 8, 5 => 9];
            $lineY = $y + $qrHeight + 6; // 6px gap after QR
            foreach ($labelLines as $line) {
                $font     = $line['font'];
                $text     = $line['text'];
                $textW    = strlen($text) * $fontWidths[$font];
                $textX    = (int) ($x + ($qrWidth - $textW) / 2);
                $textX    = max($x, $textX); // don't go left of QR area
                imagestring($output, $font, $textX, $lineY, $text, $black);
                $lineY += $fontHeights[$font] + 4;
            }
        }

        // Save output
        $outputPath = $this->outputPath . '/' . $outputFilename . '.png';
        imagepng($output, $outputPath, 9);

        // Clean up
        imagedestroy($design);
        imagedestroy($qr);
        imagedestroy($output);

        return $outputPath;
    }

    /**
     * Calculate QR code position on design
     */
    private function calculatePosition(int $designW, int $designH, int $qrW, int $qrH): array
    {
        switch ($this->qrPosition) {
            case 'top-left':
                return [$this->padding, $this->padding];
            case 'top-right':
                return [$designW - $qrW - $this->padding, $this->padding];
            case 'bottom-left':
                return [$this->padding, $designH - $qrH - $this->padding];
            case 'bottom-right':
            default:
                return [$designW - $qrW - $this->padding, $designH - $qrH - $this->padding];
        }
    }

    /**
     * Set QR code position
     */
    public function setQRPosition(string $position): self
    {
        $valid = ['top-left', 'top-right', 'bottom-left', 'bottom-right'];
        if (in_array($position, $valid)) {
            $this->qrPosition = $position;
        }
        return $this;
    }

    /**
     * Set QR code size
     */
    public function setQRSize(int $size): self
    {
        $this->qrSize = $size;
        return $this;
    }

    /**
     * Set padding from edges
     */
    public function setPadding(int $padding): self
    {
        $this->padding = $padding;
        return $this;
    }

    /**
     * Get output path
     */
    public function getOutputPath(): string
    {
        return $this->outputPath;
    }

    /**
     * Resize image maintaining aspect ratio
     */
    public function resize(string $imagePath, int $maxWidth, int $maxHeight): string
    {
        $info = getimagesize($imagePath);
        $originalWidth = $info[0];
        $originalHeight = $info[1];
        $mime = $info['mime'];

        // Calculate new dimensions
        $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
        $newWidth = (int)($originalWidth * $ratio);
        $newHeight = (int)($originalHeight * $ratio);

        // Load original
        switch ($mime) {
            case 'image/jpeg':
                $original = imagecreatefromjpeg($imagePath);
                break;
            case 'image/png':
                $original = imagecreatefrompng($imagePath);
                break;
            default:
                return $imagePath;
        }

        // Create resized image
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagecopyresampled(
            $resized, $original,
            0, 0, 0, 0,
            $newWidth, $newHeight,
            $originalWidth, $originalHeight
        );

        // Save resized
        $pathInfo = pathinfo($imagePath);
        $resizedPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_resized.png';
        imagepng($resized, $resizedPath);

        imagedestroy($original);
        imagedestroy($resized);

        return $resizedPath;
    }
}
