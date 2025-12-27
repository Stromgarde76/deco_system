<?php
// Archivo: index.php
// Ubicación: C:\xampp\htdocs\deco_system\index.php
// Pantalla principal de login

session_start();
if (isset($_SESSION['usuario'])) {
    header('Location: select_empresa.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>DECO SYSTEM - Login</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-bg">
    <div class="login-container">
        <img src="assets/img/logo.png" alt="Logo Empresa" class="logo">
        <form action="login.php" method="POST">
<!-- <input type="text" name="usuario" placeholder="Usuario" required> -->
<!-- <input type="password" name="clave" placeholder="Contraseña" required> -->
            <input type="text" name="usuario" placeholder="Usuario" required>
            <input type="password" name="clave" placeholder="Contraseña" required>
            <button type="submit">Ingresar</button>
        </form>
        <?php if (isset($_GET['error'])): ?>
            <div class="error-msg">Usuario o contraseña incorrectos</div>
        <?php endif; ?>
    </div>
</body>
</html>