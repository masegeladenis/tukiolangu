<?php

namespace App\Services;

use ZipArchive;

class ZipGenerator
{
    private string $outputPath;

    public function __construct()
    {
        $this->outputPath = dirname(__DIR__, 2) . '/output';
        
        if (!is_dir($this->outputPath)) {
            mkdir($this->outputPath, 0755, true);
        }
    }

    /**
     * Create ZIP file from array of files
     */
    public function create(array $filePaths, string $zipName): string
    {
        $zipPath = $this->outputPath . '/' . $zipName . '.zip';

        $zip = new ZipArchive();
        
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("Cannot create ZIP file");
        }

        foreach ($filePaths as $filePath) {
            if (file_exists($filePath)) {
                $zip->addFile($filePath, basename($filePath));
            }
        }

        $zip->close();

        return $zipPath;
    }

    /**
     * Create ZIP from directory
     */
    public function createFromDirectory(string $directory, string $zipName): string
    {
        if (!is_dir($directory)) {
            throw new \Exception("Directory not found: {$directory}");
        }

        $zipPath = $this->outputPath . '/' . $zipName . '.zip';

        $zip = new ZipArchive();
        
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("Cannot create ZIP file");
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($directory) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }

        $zip->close();

        return $zipPath;
    }

    /**
     * Get output path
     */
    public function getOutputPath(): string
    {
        return $this->outputPath;
    }
}
