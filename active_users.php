<?php
require_once 'waf.php';
$activeUsersData = [];
if (file_exists('active_users.json')) {
    $json = file_get_contents('active_users.json');
    $activeUsersData = json_decode($json, true);
    if (!is_array($activeUsersData)) {
        $activeUsersData = [];
    }
}
$activeUsers = count($activeUsersData);
if ($activeUsers === 0) $activeUsers = 1;
?>

<script>
function updateActiveUsers() {
    fetch('track_users.php')
        .then(res => res.text())
        .then(count => {
            document.getElementById('activeUserCount').textContent = count;
        });
}

setInterval(updateActiveUsers, 1000); 
updateActiveUsers();
</script>

<li class="d-flex align-items-center" style="gap: 6px;">
    <div class="pink-pulse"></div>
    <span style="color: #a200ff;"><span id="activeUserCount"><?php echo $activeUsers; ?></span></span>
</li>


<style>
.pink-pulse {
    position: relative;
    width: 7px;
    height: 7px;
    background-color: #a200ff;
    border-radius: 50%;
    animation: pulse 1.5s infinite;
}

@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(255, 182, 193, 0.5);
    }
    70% {
        box-shadow: 0 0 0 10px rgba(255, 182, 193, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(255, 182, 193, 0);
    }
}
</style>