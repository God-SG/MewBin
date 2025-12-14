<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/r2_error.log');

// Include required files
try {
    require_once __DIR__ . '/R2Uploader.php';
    require_once __DIR__ . '/r2-config.php';
} catch (Exception $e) {
    error_log('R2 Helper initialization error: ' . $e->getMessage());
    throw $e;
}

// Only declare functions if they don't already exist
if (!function_exists('r2_music_url')) {

/**
 * Get URL for a music file from R2
 */
function r2_music_url($filename) {
    if (empty($filename)) return '';
    if (filter_var($filename, FILTER_VALIDATE_URL)) return $filename;

    $r2 = R2Helper::getInstance();
    $filename = basename($filename);
    return $r2->getFileUrl('music/' . $filename);
}

/**
 * Get URL for HOA uploads
 */
function r2_hoa_url($filename) {
    if (empty($filename)) return '';
    if (filter_var($filename, FILTER_VALIDATE_URL)) return $filename;

    $r2 = R2Helper::getInstance();
    $filename = basename($filename);
    return $r2->getFileUrl('hoa/' . $filename);
}

/**
 * Get URL for a background image from R2
 */
function r2_bg_url($filename) {
    if (empty($filename)) return '';
    if (filter_var($filename, FILTER_VALIDATE_URL)) return $filename;

    $r2 = R2Helper::getInstance();
    $filename = basename($filename);
    return $r2->getFileUrl('backgrounds/' . $filename);
}

/**
 * Get URL for a user's profile background from R2
 */
function r2_profile_bg_url($filename) {
    if (empty($filename)) return '';
    if (filter_var($filename, FILTER_VALIDATE_URL)) return $filename;

    $r2 = R2Helper::getInstance();
    $filename = basename($filename);
    return $r2->getFileUrl('profile_backgrounds/' . $filename);
}

/**
 * Get URL for a user's profile music from R2
 */
function r2_profile_music_url($filename) {
    if (empty($filename)) return '';
    if (filter_var($filename, FILTER_VALIDATE_URL)) return $filename;

    $r2 = R2Helper::getInstance();
    $filename = basename($filename);
    return $r2->getFileUrl('profile_music/' . $filename);
}

class R2Helper {
    private static $instance = null;
    private $r2;

    private function __construct() {
        $this->r2 = new App\R2Uploader();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Upload a file to R2
     */
    public function uploadFile($file, $directory) {
        if (!isset($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            return [
                'success' => false,
                'message' => 'No file uploaded or upload failed'
            ];
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = $directory . '/' . uniqid() . '.' . $extension;

        $publicUrl = $this->r2->uploadFile(
            $file['tmp_name'],
            $fileName,
            $file['type']
        );

        if ($publicUrl) {
            return [
                'success' => true,
                'path' => $fileName,
                'url' => $publicUrl
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to upload file to R2'
        ];
    }

    public function getFileUrl($path) {
        if (empty($path)) return '';
        if (filter_var($path, FILTER_VALIDATE_URL)) return $path;
        return $this->r2->getFileUrl($path);
    }

    public function deleteFile($path) {
        if (empty($path)) return false;
        if (!filter_var($path, FILTER_VALIDATE_URL)) {
            return $this->r2->deleteFile($path);
        }
        return false;
    }
}

/**
 * Get URL for a banner picture
 */
function r2_banner_url($filename) {
    if (empty($filename)) return '';
    if (filter_var($filename, FILTER_VALIDATE_URL)) return $filename;

    $r2 = R2Helper::getInstance();
    $filename = basename($filename);
    return $r2->getFileUrl('banner_pictures/' . $filename);
}

/**
 * UNIVERSAL R2 PATH HANDLER — NOW HOA-AWARE
 */
function r2_url($path) {
    if (empty($path)) return '';

    if (filter_var($path, FILTER_VALIDATE_URL)) {
        return $path;
    }

    $r2 = R2Helper::getInstance();
    $filename = basename($path);

    // Directory detection including HOA
    if (strpos($path, 'hoa/') !== false) {
        $key = 'hoa/' . $filename;
    } elseif (strpos($path, 'banner_pictures/') !== false) {
        $key = 'banner_pictures/' . $filename;
    } elseif (strpos($path, 'profile_pictures/') !== false) {
        $key = 'profile_pictures/' . $filename;
    } else {
        // Default fallback
        $key = 'profile_pictures/' . $filename;
    }

    return $r2->getFileUrl($key);
}

/**
 * Get URL for a profile picture
 */
function r2_profile_picture_url($filename) {
    if (empty($filename)) return '';
    if (filter_var($filename, FILTER_VALIDATE_URL)) return $filename;

    $r2 = R2Helper::getInstance();
    $filename = basename($filename);
    return $r2->getFileUrl('profile_pictures/' . $filename);
}

} // END if !function_exists
