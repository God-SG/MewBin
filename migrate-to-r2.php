<?php
/**
 * Cloudflare R2 Migration Tool
 * 
 * This script will migrate files from your local uploads directory to Cloudflare R2.
 * It includes progress tracking and will skip already uploaded files.
 */

// Load required files
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/includes/r2-config.php';

use App\R2Uploader;

class R2MigrationTool {
    private $r2;
    private $uploadDir;
    private $stats = [
        'total' => 0,
        'uploaded' => 0,
        'skipped' => 0,
        'failed' => 0,
        'start_time' => null,
        'end_time' => null
    ];

        $this->printHeader();
        
        if (!is_dir($this->uploadDir)) {
            die("Error: Uploads directory not found at: " . $this->uploadDir . "\n");
        }

        $this->log("Starting migration from: " . $this->uploadDir);
        $this->log("Scanning directory...");

        // Get all files
        $files = $this->getFilesRecursively($this->uploadDir);
        $this->stats['total'] = count($files);

        $this->log("Found {$this->stats['total']} files to process\n");

        if ($this->stats['total'] === 0) {
            $this->log("No files found to migrate. Exiting.");
            return;
        }

        // Process files
        foreach ($files as $i => $file) {
            $relativePath = $this->getRelativePath($file);
            $fileNumber = $i + 1;
            
            $this->log("[{$fileNumber}/{$this->stats['total']}] Processing: {$relativePath}", false);

            // Check if file exists in R2 (with error handling)
            try {
                if ($this->r2->fileExists($relativePath)) {
                    $this->log(" [SKIP] Already exists in R2");
                    $this->stats['skipped']++;
                    continue;
                }
            } catch (Exception $e) {
                $this->log(" [WARNING] Could not check if file exists: " . $e->getMessage());
                // Continue with upload attempt
            }

            // Upload the file
            try {
                $contentType = $this->getMimeType($file);
                $publicUrl = $this->r2->uploadFile($file, $relativePath, $contentType);
                
                if ($publicUrl) {
                    $this->log(" [OK] Uploaded to: {$publicUrl}");
                    $this->stats['uploaded']++;
                } else {
                    $this->log(" [FAIL] Unknown error uploading file");
                    $this->stats['failed']++;
                }
            } catch (Exception $e) {
                $this->log(" [ERROR] " . $e->getMessage());
                $this->stats['failed']++;
            }

            // Add a small delay to avoid rate limiting
            usleep(100000); // 0.1 second
        }

        $this->stats['end_time'] = microtime(true);
        $this->printSummary();
    }

    private function getFilesRecursively($dir) {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function getRelativePath($filePath) {
        return ltrim(str_replace('\\', '/', substr($filePath, strlen($this->uploadDir))), '/');
    }
    
    /**
     * Get MIME type of a file with fallback for systems without mime_content_type()
     */
    private function getMimeType($file) {
        if (function_exists('mime_content_type')) {
            return mime_content_type($file);
        }
        
        // Fallback MIME type detection
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mimeTypes = [
            'png'  => 'image/png',
            'jpeg' => 'image/jpeg',
            'jpg'  => 'image/jpeg',
            'gif'  => 'image/gif',
            'bmp'  => 'image/bmp',
            'webp' => 'image/webp',
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip'  => 'application/zip',
            'mp3'  => 'audio/mpeg',
            'mp4'  => 'video/mp4',
            'mov'  => 'video/quicktime',
            'avi'  => 'video/x-msvideo',
            'wav'  => 'audio/wav',
            'txt'  => 'text/plain',
            'html' => 'text/html',
            'css'  => 'text/css',
            'js'   => 'application/javascript',
            'json' => 'application/json',
        ];
        
        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    private function log($message, $newline = true) {
        echo $message . ($newline ? "\n" : '');
        // Flush output buffer to see progress in real-time
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    private function printHeader() {
        echo "========================================\n";
        echo "  Cloudflare R2 Migration Tool\n";
        echo "========================================\n\n";
    }

    private function printSummary() {
        $duration = $this->stats['end_time'] - $this->stats['start_time'];
        $minutes = floor($duration / 60);
        $seconds = $duration % 60;
        
        echo "\n\n";
        echo "========================================\n";
        echo "  Migration Complete!\n";
        echo "========================================\n";
        echo "Total files:       " . $this->stats['total'] . "\n";
        echo "Successfully uploaded: " . $this->stats['uploaded'] . "\n";
        echo "Skipped (already exists): " . $this->stats['skipped'] . "\n";
        echo "Failed:           " . $this->stats['failed'] . "\n";
        echo "Time taken:       {$minutes}m " . round($seconds) . "s\n";
        echo "========================================\n";
        
        if ($this->stats['failed'] > 0) {
            echo "\nNote: Some files failed to upload. You can run the script again to retry failed uploads.\n";
        }
    }
}

// Check if running from command line
if (php_sapi_name() === 'cli') {
    $migration = new R2MigrationTool();
    $migration->run();
} else {
    // If accessed via web, show a simple interface
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_migration'])) {
        // Set unlimited execution time and increase memory limit
        set_time_limit(0);
        ini_set('memory_limit', '1024M');
        
        // Start output buffering
        ob_implicit_flush(true);
        ob_end_flush();
        
        // Run the migration
        $migration = new R2MigrationTool();
        $migration->run();
    } else {
        // Show the web interface
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Cloudflare R2 Migration Tool</title>
            <style>
                body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
                .container { background: #f5f5f5; padding: 20px; border-radius: 5px; }
                pre { background: #fff; padding: 15px; border: 1px solid #ddd; border-radius: 4px; overflow-x: auto; }
                button { background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; }
                button:hover { background: #0056b3; }
                .warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
            </style>
        </head>
        <body>
            <h1>Cloudflare R2 Migration Tool</h1>
            
            <div class="warning">
                <strong>Important:</strong> This process might take a long time depending on the number of files. 
                Please do not close this window until the migration is complete.
            </div>
            
            <div class="container">
                <h2>Migration Status</h2>
                <pre id="output">Click the button below to start the migration...</pre>
                
                <form method="post" id="migrationForm">
                    <button type="submit" name="start_migration" id="startButton">Start Migration</button>
                </form>
            </div>
            
            <script>
                // Auto-scroll the output
                function scrollToBottom() {
                    var output = document.getElementById('output');
                    output.scrollTop = output.scrollHeight;
                }
                
                // Handle form submission
                document.getElementById('migrationForm').addEventListener('submit', function(e) {
                    e.preventDefault();
                    var button = document.getElementById('startButton');
                    button.disabled = true;
                    button.textContent = 'Migration in progress...';
                    
                    // Submit the form via AJAX to see real-time output
                    var formData = new FormData(this);
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '', true);
                    
                    xhr.onprogress = function() {
                        var response = xhr.responseText;
                        document.getElementById('output').textContent = response;
                        scrollToBottom();
                    };
                    
                    xhr.onload = function() {
                        document.getElementById('output').textContent = xhr.responseText;
                        button.textContent = 'Migration Complete!';
                        scrollToBottom();
                    };
                    
                    xhr.send(formData);
                });
                
                // Initial scroll
                scrollToBottom();
            </script>
        </body>
        </html>
        <?php
    }
}
