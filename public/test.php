<?php
$servername = "127.0.0.1";
$username = "root";
$password = "";
$dbname = "onavan";

// Maak verbinding
$conn = new mysqli($servername, $username, $password, $dbname);

// Controleer verbinding
if ($conn->connect_error) {
    die("Verbinding mislukt: " . $conn->connect_error);
}
print_r($conn);
echo "Verbonden met de database!";
$conn->close();
?>
