<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$user = null;
$username = null;
if (isset($_COOKIE['login_token'])) {
    include 'database.php';
    $token = $_COOKIE['login_token'];
    $stmt = $conn->prepare("SELECT username FROM users WHERE login_token = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->bind_result($username);
        if ($stmt->fetch()) {
            $user = $username;
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['giftCode'])) {
    if (!$user) {
        echo json_encode(['status' => 'fail', 'message' => 'You must be logged in to redeem a code.']);
        exit;
    }
    include 'database.php';
    if ($conn->connect_errno) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
        exit;
    }

    $code = trim($_POST['giftCode']);
    $stmt = $conn->prepare("UPDATE `redeem_codes` SET used = 1, used_by = ? WHERE code = ? AND used = 0");
    if (!$stmt) {
        echo json_encode(['status' => 'fail', 'message' => 'Prepare failed: ' . $conn->error]);
        $conn->close();
        exit;
    }
    $stmt->bind_param('ss', $user, $code);
    if (!$stmt->execute()) {
        echo json_encode(['status' => 'fail', 'message' => 'Execute failed: ' . $stmt->error]);
        $stmt->close();
        $conn->close();
        exit;
    }

    if ($stmt->affected_rows === 1) {
        $stmt->close();
        $stmt2 = $conn->prepare("SELECT rank FROM `redeem_codes` WHERE code = ?");
        if (!$stmt2) {
            echo json_encode(['status' => 'fail', 'message' => 'Prepare2 failed: ' . $conn->error]);
            $conn->close();
            exit;
        }
        $stmt2->bind_param('s', $code);
        $stmt2->execute();
        $stmt2->bind_result($rank);
        $stmt2->fetch();
        $stmt2->close();

        $stmt3 = $conn->prepare("UPDATE `users` SET rank = ? WHERE username = ?");
        if ($stmt3) {
            $stmt3->bind_param('ss', $rank, $user);
            $stmt3->execute();
            $stmt3->close();
        }

        echo json_encode(['status' => 'success', 'message' => 'Successfully redeemed code! You have been upgraded to ' . htmlspecialchars($rank)]);
    } else {
        $stmt->close();
        $stmt4 = $conn->prepare("SELECT used_by FROM `redeem_codes` WHERE code = ?");
        if ($stmt4) {
            $stmt4->bind_param('s', $code);
            $stmt4->execute();
            $stmt4->bind_result($used_by);
            if ($stmt4->fetch() && $used_by) {
                echo json_encode(['status' => 'fail', 'message' => 'This code has already been redeemed by ' . htmlspecialchars($used_by)]);
            } else {
                echo json_encode(['status' => 'fail', 'message' => 'Code not found']);
            }
            $stmt4->close();
        } else {
            echo json_encode(['status' => 'fail', 'message' => 'Code not found']);
        }
    }
    $conn->close();
    exit;
}

if (!$user) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Login Required</title>';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.0.1/dist/darkly/bootstrap.min.css" referrerpolicy="no-referrer"/>';
    echo '<style>body { background: #000000 !important; color: #fff; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 0; }</style>';
    echo '</head><body>';
    echo '<div style="color:#ff99ff;text-align:center;margin-top:60px;font-size:1.3em;background:rgba(13,13,13,0.85);max-width:500px;margin-left:auto;margin-right:auto;padding:40px;border-radius:12px;border:1px solid #333;box-shadow:0 8px 32px rgba(0,0,0,0.6);">You must be logged in to redeem a code.</div>';
    echo '</body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Redeem Your Gift - MewBin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.0.1/dist/darkly/bootstrap.min.css" referrerpolicy="no-referrer"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.12.0/css/all.min.css" referrerpolicy="no-referrer"/>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <style>
        body {
            background: #000000 !important;
            color: #fff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }
        
        body #particles-js {
            position: fixed !important;
            width: 100% !important;
            height: 100% !important;
            top: 0 !important;
            left: 0 !important;
            z-index: -1 !important;
            background: linear-gradient(to bottom, rgba(5, 5, 8, 0.95) 0%, #000000 100%) !important;
            opacity: 1 !important;
            display: block !important;
        }
        
        #particles-js canvas {
            -webkit-filter: drop-shadow(0 0 8px #8a2be2) drop-shadow(0 0 16px #8a2be2) !important;
            filter: drop-shadow(0 0 8px #8a2be2) drop-shadow(0 0 16px #8a2be2) !important;
            will-change: filter;
            pointer-events: none;
        }

        .gradient-overlay {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            background:radial-gradient(circle at 10% 20%, rgba(42, 0, 51, 0.2) 0%, transparent 20%), radial-gradient(circle at 90% 80%, rgba(90, 13, 122, 0.15) 0%, transparent 20%), radial-gradient(circle at 50% 50%, rgba(58, 0, 82, 0.1) 0%, transparent 50%);
            z-index: -1 !important;
            pointer-events: none !important;
            opacity: 1 !important;
        }

        .redeem-container {
            max-width: 480px;
            margin: 100px auto 40px auto;
            background: rgba(13, 13, 13, 0.85);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.6);
            padding: 40px 36px;
            border: 1px solid #333;
            position: relative;
            z-index: 1;
            text-align: center;
        }

        .redeem-title {
            font-size: 2.2rem;
            font-weight: 700;
            background: linear-gradient(90deg, #8a2be2, #ff99ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 24px;
            text-align: center;
            text-shadow: 0 0 20px rgba(138, 43, 226, 0.3);
        }

        .gift-icon {
            font-size: 4.5em;
            margin-bottom: 20px;
            background: linear-gradient(90deg, #8a2be2, #ff99ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .gift-img {
            width: 100px;
            margin-bottom: 20px;
            filter: drop-shadow(0 0 10px rgba(138, 43, 226, 0.5));
        }

        .form-label {
            color: #fff;
            font-weight: 600;
            font-size: 1.2rem;
            margin-bottom: 12px;
            display: block;
        }

        .form-control {
            background: #111 !important;
            color: #fff !important;
            border: 1.5px solid #333 !important;
            border-radius: 10px;
            margin-bottom: 20px;
            padding: 14px 18px;
            transition: all 0.3s ease;
            font-size: 1.1em;
            text-align: center;
        }

        .form-control:focus {
            background: #181818 !important;
            border-color: #8a2be2 !important;
            outline: none;
            box-shadow: 0 0 15px rgba(138, 43, 226, 0.4) !important;
            transform: scale(1.02);
        }

        .btn-redeem {
            background: linear-gradient(90deg, #8a2be2 0%, #ff99ff 100%);
            border: none;
            color: #fff;
            font-weight: 600;
            border-radius: 10px;
            padding: 14px 32px;
            font-size: 1.2em;
            transition: all 0.3s ease;
            width: 100%;
            margin-top: 15px;
        }

        .btn-redeem:hover {
            background: linear-gradient(90deg, #ff99ff 0%, #8a2be2 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(138, 43, 226, 0.4);
        }

        .message-area {
            margin-top: 20px;
            min-height: 24px;
            color: #ff99ff;
            font-weight: 500;
        }

        .popup-notification {
            position: fixed;
            top: 30px;
            left: 50%;
            transform: translateX(-50%);
            min-width: 280px;
            background: rgba(13, 13, 13, 0.95);
            color: #fff;
            padding: 18px 32px;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.8);
            z-index: 9999;
            font-size: 1.1em;
            font-weight: 600;
            display: none;
            border: 1px solid #333;
            text-align: center;
            backdrop-filter: blur(10px);
        }

        .popup-success { 
            background: rgba(30, 126, 52, 0.95) !important;
            border-color: #1e7e34 !important;
            color: #fff !important;
        }
        
        .popup-fail { 
            background: rgba(220, 53, 69, 0.95) !important;
            border-color: #dc3545 !important;
            color: #fff !important;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .redeem-container {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        .redeem-footer {
            text-align: center;
            margin-top: 25px;
            color: #aaa;
            font-size: 0.95em;
            line-height: 1.5;
        }

        .info-icon {
            color: #8a2be2;
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <div id="particles-js"></div>
    <div class="gradient-overlay"></div>
    <?php include('navbar.php'); ?>
    
    <div class="redeem-container">
        <div class="redeem-title">
            <i class="fas fa-gift"></i> Redeem Your Gift
        </div>

        <div>
            <i class="fas fa-gift gift-icon"></i>
        </div>
        
        <form id="redeemForm" autocomplete="off">
            <div class="mb-3">
                <label for="giftCode" class="form-label">Gift Code:</label>
                <input type="text" class="form-control" id="giftCode" name="giftCode" placeholder="Enter your gift code" required autofocus>
            </div>
            <button type="submit" class="btn btn-redeem">
                <i class="fas fa-gift"></i> Redeem Gift
            </button>
        </form>
        <div class="message-area" id="messageArea"></div>
        
        <div class="redeem-footer">
            <i class="fas fa-info-circle info-icon"></i> Enter your gift code to redeem your special reward.
        </div>
    </div>
    
    <div id="popupNotification" class="popup-notification"></div>
	<script>

        function showPopup(message, type) {
            var popup = document.getElementById('popupNotification');
            popup.textContent = message;
            popup.className = 'popup-notification popup-' + type;
            popup.style.display = 'block';
            setTimeout(function() {
                popup.style.display = 'none';
            }, 2200);
        }

        document.getElementById('redeemForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var code = document.getElementById('giftCode').value.trim();
            if (!code) {
                showPopup("Please enter a gift code.", "fail");
                return;
            }
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res.status === 'success') {
                            showPopup(res.message, 'success');
                            document.getElementById('giftCode').value = '';
                        } else {
                            showPopup(res.message, 'fail');
                        }
                    } catch (e) {
                        showPopup("Server error.", "fail");
                    }
                }
            };
            xhr.send('giftCode=' + encodeURIComponent(code));
        });
    </script>
</body>
</html>
