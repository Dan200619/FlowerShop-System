<?php
$servername = "localhost";
$username = "root";
$password = "";

$conn = mysqli_connect($servername, $username, $password) or die ('Cannot connect to server');
mysqli_select_db($conn, "system") or die("No Database found!");
?>