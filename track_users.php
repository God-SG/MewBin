<?php
ini_set('session.use_cookies', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.use_trans_sid', 0);
session_start();
$sessionId = session_id();
$time = time();
$timeout = 60;

$file = 'active_users.json';

$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

if (!is_array($data)) {
    $data = [];
}

foreach ($data as $key => $entry) {
    if ($entry['time'] < ($time - $timeout)) {
        unset($data[$key]);
    }
}

$data[$sessionId] = ['time' => $time];
file_put_contents($file, json_encode($data));


echo count($data);
?>