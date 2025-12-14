<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/includes/r2-config.php';

// Import the R2Uploader class
use App\R2Uploader;

// Check if a file was uploaded
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    try {
        $r2 = new R2Uploader();
        
        // Generate a unique filename
        $file = $_FILES['file'];
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = 'test-uploads/' . uniqid() . '.' . $extension;
        
        // Upload the file
        $publicUrl = $r2->uploadFile($file['tmp_name'], $fileName, $file['type']);
        
        if ($publicUrl) {
            echo "<div style='color: green;'>File uploaded successfully!</div>";
            echo "<div>File URL: <a href='$publicUrl' target='_blank'>$publicUrl</a></div>";
            echo "<div><img src='$publicUrl' style='max-width: 300px; margin-top: 20px;'></div>";
        } else {
            echo "<div style='color: red;'>Error uploading file</div>";
        }
    } catch (Exception $e) {
        echo "<div style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Test R2 Upload</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        .upload-form { border: 2px dashed #ccc; padding: 20px; text-align: center; margin: 20px 0; }
        .btn { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        .btn:hover { background: #0056b3; }
    </style>
</head>
<body>
    <h1>Cloudflare R2 Upload Test</h1>
    
    <div class="upload-form">
        <h2>Upload a Test File</h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="file" name="file" required>
            <button type="submit" class="btn">Upload to R2</button>
        </form>
    </div>
    
    <h2>How to Use in Your Code</h2>
    <pre><code>// Include required files
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/includes/r2-config.php';

// Import the R2Uploader class
use App\R2Uploader;

// Create an instance
$r2 = new R2Uploader();

// Upload a file
$publicUrl = $r2->uploadFile(
    '/path/to/local/file.jpg',  // Local file path
    'profile_pictures/user123.jpg',  // Destination path in R2
    'image/jpeg'  // MIME type
);

// Get a file URL
$fileUrl = $r2->getFileUrl('profile_pictures/user123.jpg');

// Delete a file
$r2->deleteFile('profile_pictures/old.jpg');</code></pre>
</body>
</html>
