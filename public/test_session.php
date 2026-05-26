<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "Testing session...<br>";
session_save_path(__DIR__ . '/../var/share');
echo "Save path: " . session_save_path() . "<br>";
$started = session_start();
echo "Session started: " . ($started ? 'yes' : 'no') . "<br>";
$_SESSION['test'] = 'works';
echo "Session value: " . $_SESSION['test'] . "<br>";
echo "Session ID: " . session_id() . "<br>";
echo "File exists: " . (file_exists(__DIR__ . '/../var/share/sess_' . session_id()) ? 'yes' : 'no') . "<br>";
