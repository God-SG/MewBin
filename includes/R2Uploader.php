<?php

namespace App;

class R2Uploader {
    private $uploadDir;
    private $publicUrl;

    public function __construct() {
        $config = require __DIR__ . '/r2-config.php';

        if (empty($config['r2']['local_path']) || empty($config['r2']['public_url'])) {
            throw new \Exception("R2 Uploader config missing local_path or public_url.");
        }

        $this->uploadDir = rtrim($config['r2']['local_path'], '/');
        $this->publicUrl = rtrim($config['r2']['public_url'], '/');

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0777, true);
        }
    }

    // $tmpFile = $_FILES['file']['tmp_name']
    public function uploadFile($tmpFile, $targetPath, $contentType = null) {
        $targetPath = ltrim($targetPath, '/');
        $fullPath   = $this->uploadDir . '/' . $targetPath;

        $folder = dirname($fullPath);
        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }

        // tmp file → destination
        if (!move_uploaded_file($tmpFile, $fullPath)) {
            error_log("UPLOAD FAILED: tmpFile=$tmpFile → dest=$fullPath");
            return false;
        }

        return $this->publicUrl . '/' . $targetPath;
    }

    public function deleteFile($filePath) {
        $filePath = ltrim($filePath, '/');
        $fullPath = $this->uploadDir . '/' . $filePath;

        if (file_exists($fullPath)) {
            unlink($fullPath);
            return true;
        }
        return false;
    }

    public function getFileUrl($filePath) {
        return $this->publicUrl . '/' . ltrim($filePath, '/');
    }

    public function fileExists($filePath) {
        return file_exists($this->uploadDir . '/' . ltrim($filePath, '/'));
    }
}
