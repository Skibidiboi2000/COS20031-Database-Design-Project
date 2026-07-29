<?php
$host = "192.168.123.69";
$user = "admin";
$pass = "1234";
$dbname = "SmartFleetProject";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
