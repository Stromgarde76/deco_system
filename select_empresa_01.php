<?php
// Archivo: select_empresa.php
// Ubicación: C:\xampp\htdocs\deco_system\select_empresa.php
// Permite al usuario seleccionar con qué empresa trabajar.

session_start();
require_once "config/db.php";

// Si el usuario no ha iniciado sesión, redirige al login
if (!isset($_SESSION['usuario'])) {
    header("Location: index.php");
    exit();
}

// Si ya seleccionó empresa, lo manda al Dashboard
if (isset($_SESSION['empresa_id'])) {
    header("Location: modules/dashboard.php");
    exit();
}

// Cuando el usuario selecciona una empresa
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['empresa_id'])) {
    $_SESSION['empresa_id'] = intval($_POST['empresa_id']);
    header("Location: modules/dashboard.php");
    exit();
}

// Obtiene las empresas de la base de datos
$empresas = [];
$sql = "SELECT id, nombre FROM empresas";
$res = $conn->query($sql);
while ($row = $res->fetch_assoc()) {
    $empresas[] = $row;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Selecciona la Empresa</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="empresa-bg">
    <div class="empresa-container">
        <h2>Selecciona la empresa</h2>
        <div class="empresa-lista">
            <?php foreach ($empresas as $empresa): ?>
            <form method="POST" class="empresa-card">
                <input type="hidden" name="empresa_id" value="<?php echo $empresa['id']; ?>">
                <button type="submit" class="empresa-btn">
                    <span class="empresa-icon">&#128188;</span>
                    <span class="empresa-nombre"><?php echo htmlspecialchars($empresa['nombre']); ?></span>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
        <a href="logout.php" class="volver-login">Cerrar sesión</a>
    </div>
</body>
</html>