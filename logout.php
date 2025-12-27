<?php
// Archivo: logout.php
// Ubicación: C:\xampp\htdocs\deco_system\logout.php
// Cierra la sesión y redirige al login

session_start(); // Inicia la sesión para poder destruirla
session_unset(); // Limpia todas las variables de sesión
session_destroy(); // Destruye la sesión por completo

header("Location: index.php"); // Redirige al inicio de sesión
exit();
?>