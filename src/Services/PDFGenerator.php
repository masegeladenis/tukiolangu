<?php

namespace App\Services;

use TCPDF;

class PDFGenerator
{
    private string $outputPath;
    private int $cardsPerPage;
    private string $orientation;
    private string $pageSize;

    public function __construct()
    {
        $this->outputPath = dirname(__DIR__, 2) . '/output/pdf';
        $this->cardsPerPage = 1;
        $this->orientation = 'L'; // Landscape
        $this->pageSize = 'A4';

        if (!is_dir($this->outputPath)) {
            mkdir($this->outputPath, 0755, true);
        }
    }

    /**
     * Generate PDF from card images
     */
    public function generateFromImages(array $imagePaths, string $filename, array $options = []): string
    {
        $pdf = new TCPDF($this->orientation, 'mm', $this->pageSize, true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('Tukio Langu App');
        $pdf->SetAuthor('Tukio');
        $pdf->SetTitle($options['title'] ?? 'Event Cards');
        
        // Remove header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set margins
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 10);

        // Get page dimensions
        $pageWidth = $pdf->getPageWidth() - 20; // Minus margins
        $pageHeight = $pdf->getPageHeight() - 20;

        foreach ($imagePaths as $imagePath) {
            if (!file_exists($imagePath)) {
                continue;
            }

            $pdf->AddPage();

            // Get image dimensions
            $imgInfo = getimagesize($imagePath);
            $imgWidth = $imgInfo[0];
            $imgHeight = $imgInfo[1];

            // Calculate fit dimensions
            $ratio = min($pageWidth / $imgWidth, $pageHeight / $imgHeight);
            $fitWidth = $imgWidth * $ratio;
            $fitHeight = $imgHeight * $ratio;

            // Center on page
            $x = (($pageWidth - $fitWidth) / 2) + 10;
            $y = (($pageHeight - $fitHeight) / 2) + 10;

            $pdf->Image($imagePath, $x, $y, $fitWidth, $fitHeight);
        }

        // Save PDF
        $outputPath = $this->outputPath . '/' . $filename . '.pdf';
        $pdf->Output($outputPath, 'F');

        return $outputPath;
    }

    /**
     * Generate PDF with multiple cards per page
     */
    public function generateMultiplePerPage(array $imagePaths, string $filename, int $cols = 2, int $rows = 2): string
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        
        $pdf->SetCreator('Tukio Langu App');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);

        $pageWidth = $pdf->getPageWidth() - 20;
        $pageHeight = $pdf->getPageHeight() - 20;
        
        $cellWidth = $pageWidth / $cols;
        $cellHeight = $pageHeight / $rows;
        $cardsPerPage = $cols * $rows;

        $currentCard = 0;
        
        foreach ($imagePaths as $imagePath) {
            if (!file_exists($imagePath)) {
                continue;
            }

            if ($currentCard % $cardsPerPage === 0) {
                $pdf->AddPage();
            }

            $positionOnPage = $currentCard % $cardsPerPage;
            $col = $positionOnPage % $cols;
            $row = floor($positionOnPage / $cols);

            $x = 10 + ($col * $cellWidth);
            $y = 10 + ($row * $cellHeight);

            // Fit image in cell with padding
            $padding = 5;
            $pdf->Image(
                $imagePath, 
                $x + $padding, 
                $y + $padding, 
                $cellWidth - ($padding * 2), 
                $cellHeight - ($padding * 2),
                '', '', '', false, 300, '', false, false, 0, 'CM'
            );

            $currentCard++;
        }

        $outputPath = $this->outputPath . '/' . $filename . '.pdf';
        $pdf->Output($outputPath, 'F');

        return $outputPath;
    }

    /**
     * Set page orientation
     */
    public function setOrientation(string $orientation): self
    {
        $this->orientation = $orientation;
        return $this;
    }

    /**
     * Set page size
     */
    public function setPageSize(string $size): self
    {
        $this->pageSize = $size;
        return $this;
    }

    /**
     * Get output path
     */
    public function getOutputPath(): string
    {
        return $this->outputPath;
    }
}
