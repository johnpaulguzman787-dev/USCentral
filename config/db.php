<?php
$conn = new mysqli("localhost", "root", "", "uscentraldb");

if ($conn->connect_error) {
    die("Database connection failed");
}
?>
