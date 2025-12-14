<?php
include('database.php');
session_start();
require_once 'waf.php';
if (isset($_SESSION['username'])) {
    $username = $_SESSION['username'];
} else {
    $username = null;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

$query = "SELECT rank FROM users WHERE username = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();
$loggedInUserRank = $userData['rank'] ?? 'All Users';

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>Hall Of Autism</title>
    <link rel="stylesheet" href="styles.min.css">
    <link rel="stylesheet" href="bootstrap.min.css">

    <style>
        body {
            color: #ffffff;
            background: #000000;
            font-family: 'Courier New', monospace;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .main-header {
            padding: 30px 20px;
            text-align: center;
            background: #000000;
            margin-bottom: 30px;
            border-bottom: 3px solid #5a0d7a;
			margin-top: 20px;
        }

        .logo-container {
            max-width: 180px;
            margin: 0 auto 20px;
            border: 3px solid #5a0d7a;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 0 30px rgba(90, 13, 122, 0.7);
            background: #000000;
        }

        .logo-container img {
            width: 100%;
            height: auto;
            display: block;
        }

        .page-title {
            font-size: 3rem;
            font-weight: 900;
            color: #8a2be2;
            text-shadow: 
                0 0 10px #8a2be2,
                0 0 20px #8a2be2,
                2px 2px 0 #000000;
            margin: 0;
            letter-spacing: 2px;
        }

        .container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .user-box {
            background: #000000;
            border: 2px solid #333333;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            height: 420px;
            display: flex;
            flex-direction: column;
            box-shadow: 
                inset 0 0 20px rgba(90, 13, 122, 0.1),
                0 5px 15px rgba(0,0,0,0.8);
            position: relative;
        }

        .user-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: #5a0d7a;
            box-shadow: 0 0 10px #5a0d7a;
        }

        .user-box:hover {
            transform: translateY(-5px);
            border-color: #8a2be2;
            box-shadow: 
                inset 0 0 30px rgba(90, 13, 122, 0.2),
                0 10px 25px rgba(90, 13, 122, 0.3);
        }

        .user-box img {
            border-radius: 6px;
            width: 100%;
            height: 150px;
            object-fit: cover;
            margin-bottom: 15px;
            border: 2px solid #333333;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .user-box:hover img {
            border-color: #8a2be2;
        }

        .user-box h5 {
            color: #8a2be2;
            font-weight: 800;
            margin-bottom: 10px;
            font-size: 1.2rem;
            text-shadow: 0 0 5px rgba(138, 43, 226, 0.5);
            flex-shrink: 0;
        }

        .user-box p {
            flex: 1;
            overflow-y: auto;
            line-height: 1.4;
            margin-bottom: 15px;
            padding-right: 5px;
            scrollbar-width: thin;
            scrollbar-color: #8a2be2 #000000;
            font-size: 0.9rem;
            color: #cccccc;
            min-height: 80px;
        }

        .user-box p::-webkit-scrollbar {
            width: 4px;
        }

        .user-box p::-webkit-scrollbar-track {
            background: #000000;
            border-radius: 2px;
        }

        .user-box p::-webkit-scrollbar-thumb {
            background: #8a2be2;
            border-radius: 2px;
            box-shadow: 0 0 5px #8a2be2;
        }

        .dox-btn {
            display: inline-block;
            background: #000000;
            color: #8a2be2;
            padding: 10px 25px;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 800;
            transition: all 0.3s ease;
            border: 2px solid #8a2be2;
            flex-shrink: 0;
        }

        .dox-btn:hover {
            background: #8a2be2;
            color: #000000;
            box-shadow: 0 0 15px rgba(138, 43, 226, 0.7);
        }

        @media (max-width: 768px) {
            .container {
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 15px;
                padding: 15px;
            }
            
            .page-title {
                font-size: 2.2rem;
            }
        }

        /* Purple Spark Cursor */
        .purple-spark {
            position: absolute;
            width: 6px;
            height: 6px;
            background: #8a2be2;
            border-radius: 50%;
            pointer-events: none;
            box-shadow: 0 0 10px #8a2be2;
            animation: spark-fade 0.8s ease-out forwards;
            z-index: 9999;
        }

        @keyframes spark-fade {
            0% {
                opacity: 1;
                transform: scale(1);
            }
            100% {
                opacity: 0;
                transform: scale(0) translate(var(--tx), var(--ty));
            }
        }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 10px;
        }
        
        ::-webkit-scrollbar-track {
            background: #000000;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #5a0d7a;
            border: 2px solid #000000;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #3d0059;
        }

        .audio-controls {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
        }

        .audio-btn {
            background: #000000;
            color: #8a2be2;
            border: 2px solid #8a2be2;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            cursor: pointer;
            box-shadow: 0 0 15px rgba(138, 43, 226, 0.5);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: bold;
        }

        .audio-btn:hover {
            background: #8a2be2;
            color: #000000;
            box-shadow: 0 0 25px rgba(138, 43, 226, 0.8);
        }
    </style>
</head>

<body>
<audio id="background-audio" loop>
  <source src="HOAsong.mp3" type="audio/mp3">
  Your browser does not support the audio element.
</audio>

<div class="audio-controls">
    <button class="audio-btn" onclick="toggleAudio()">♪</button>
</div>

<?php include('navnav.php'); ?>

<div class="main-header">
    <div class="logo-container">
        <img src="mbin.png" alt="Hall of Autism Logo">
    </div>
    <h1 class="page-title">HALL OF AUTISM</h1>
</div>

<script>
    // Purple Spark Cursor Effect
    document.addEventListener('mousemove', function(e) {
        const spark = document.createElement('div');
        spark.className = 'purple-spark';
        spark.style.left = e.pageX - 3 + 'px';
        spark.style.top = e.pageY - 3 + 'px';
        
        const tx = (Math.random() - 0.5) * 80;
        const ty = (Math.random() - 0.5) * 80;
        spark.style.setProperty('--tx', tx + 'px');
        spark.style.setProperty('--ty', ty + 'px');
        
        document.body.appendChild(spark);
        
        setTimeout(() => {
            if (spark.parentNode) {
                spark.remove();
            }
        }, 800);
    });

    // Audio Controls
    const audio = document.getElementById('background-audio');
    
    function toggleAudio() {
        if (audio.paused) {
            audio.play().catch(e => console.log('Audio play failed:', e));
        } else {
            audio.pause();
        }
    }

    document.addEventListener('click', function initAudio() {
        if (audio.paused) {
            audio.play().catch(e => console.log('Audio play failed:', e));
        }
        document.removeEventListener('click', initAudio);
    });
</script>

<?php
$sql = "SELECT picture, username, about_me, link FROM hoa";
$result = $conn->query($sql);
?>

<div class="container">
    <?php
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            echo '<div class="user-box">';
            
            $picturePath = !empty($row['picture']) ? $row['picture'] : '/default.png';
            echo '<img src="' . htmlspecialchars($picturePath, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8') . '">';
            
            echo '<h5>' . htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8') . '</h5>';
            
            echo '<p>' . htmlspecialchars($row['about_me'], ENT_QUOTES, 'UTF-8') . '</p>';

            $safeLink = filter_var($row['link'], FILTER_VALIDATE_URL) && (stripos($row['link'], 'http://') === 0 || stripos($row['link'], 'https://') === 0)
                ? htmlspecialchars($row['link'], ENT_QUOTES, 'UTF-8')
                : '#';

            echo '<a href="' . $safeLink . '" target="_blank" class="dox-btn">DOX</a>';
            
            echo '</div>';
        }
    } else {
        echo "<p style='text-align: center; width: 100%; color: #8a2be2; text-shadow: 0 0 10px #8a2be2;'>NO DATA FOUND</p>";
    }
    ?>
</div>

<?php
$conn->close();
?>

<script src="/bootstrap.bundle.min.js"></script>
</body>
</html>