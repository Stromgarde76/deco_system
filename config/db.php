<?php
// Archivo: db.php
// Ubicación: C:\xampp\htdocs\deco_system\config\db.php
// Conexión a la base de datos MySQL

$host = "localhost";
$user = "root";
$pass = "";
$dbname = "deco_system";

// Conexión a MySQL
$conn = new mysqli($host, $user, $pass, $dbname);

// Verifica la conexión
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}
?>