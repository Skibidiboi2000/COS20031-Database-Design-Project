<?php
#$host = "127.0.0.1";
#$user = "admin";
#$pass = "1234";
#$dbname = "SmartFleetProject";

$conn = new mysqli('100.98.213.61', 'admin', '1234', 'SmartFleetProject');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
