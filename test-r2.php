<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include required files
require_once __DIR__ . '/includes/r2-config.php';
require_once __DIR__ . '/includes/R2Uploader.php';

// Test R2 connection
function testR2Connection() {
    try {
        $config = require __DIR__ . '/includes/r2-config.php';
        $r2Config = $config['r2'];
        
        echo "<h2>Testing R2 Configuration</h2>";
        echo "<pre>Config: " . print_r($r2Config, true) . "</pre>";
        
        // Test creating an S3 client
        $s3Client = new Aws\S3\S3Client([
            'version' => 'latest',
            'region'  => $r2Config['region'],
            'endpoint' => "https://" . $r2Config['account_id'] . ".r2.cloudflarestorage.com",
            'credentials' => [
                'key'    => $r2Config['key'],
                'secret' => $r2Config['secret'],
            ],
            'http' => [
                'verify' => false // Disable SSL verification (for development only)
            ],
            'use_path_style_endpoint' => true // Important for R2 compatibility
        ]);
        
        echo "<p style='color: green;'>✅ Successfully connected to R2</p>";
        
        // Test listing buckets
        $buckets = $s3Client->listBuckets();
        echo "<h3>Available Buckets:</h3>";
        echo "<pre>" . print_r($buckets['Buckets'], true) . "</pre>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>R2 Connection Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        pre { background: #f4f4f4; padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>R2 Connection Test</h1>
    <?php testR2Connection(); ?>
</body>
</html>
