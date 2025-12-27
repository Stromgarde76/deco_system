<?php
// Archivo: login.php
// Ubicación: C:\xampp\htdocs\deco_system\login.php
// Procesa el login del usuario

session_start();
require_once "config/db.php"; // Incluye la conexión a la base de datos

$usuario = $_POST['usuario'] ?? '';
$clave = $_POST['clave'] ?? '';

// Consulta para verificar usuario y clave
$sql = "SELECT * FROM usuarios WHERE usuario=? AND clave=MD5(?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $usuario, $clave);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $data = $result->fetch_assoc();
    $_SESSION['usuario'] = $data['usuario'];
    $_SESSION['rol'] = $data['rol'];
    $_SESSION['nombre'] = $data['nombre'];
    $_SESSION['empresa_id'] = $data['empresa_id'];
    header('Location: select_empresa.php');
} else {
    header('Location: index.php?error=1');
}
exit();
?>