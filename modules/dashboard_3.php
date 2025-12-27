<?php
// Archivo: dashboard.php
// Ubicación: C:\xampp\htdocs\deco_system\modules\dashboard.php

session_start();
require_once "../config/db.php";

// Verifica si el usuario ha iniciado sesión
if (!isset($_SESSION['usuario'])) {
    header('Location: ../index.php');
    exit();
}

// Verifica si ya seleccionó empresa
if (!isset($_SESSION['empresa_id'])) {
    header('Location: ../select_empresa.php');
    exit();
}

// Obtiene el nombre de la empresa seleccionada
$empresa_id = $_SESSION['empresa_id'];
$empresa_nombre = '';
$stmt = $conn->prepare("SELECT nombre FROM empresas WHERE id=?");
$stmt->bind_param('i', $empresa_id);
$stmt->execute();
$stmt->bind_result($empresa_nombre);
$stmt->fetch();
$stmt->close();

// Ejemplo de conteos/resúmenes
function obtenerConteo($tabla, $empresa_id, $conn) {
    $sql = "SELECT COUNT(*) FROM $tabla WHERE empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $empresa_id);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count;
}

// Obtener el saldo real sumando todos los saldos de las cuentas bancarias de la empresa
$saldos = 0;
$stmt = $conn->prepare("SELECT SUM(saldo) FROM bancos WHERE empresa_id=?");
$stmt->bind_param('i', $empresa_id);
$stmt->execute();
$stmt->bind_result($total_saldos);
$stmt->fetch();
$stmt->close();
$saldos = $total_saldos ? $total_saldos : 0;

// Obtener cantidad exacta de trabajos en estado 'activo'
$trabajos_activos = 0;
$stmt = $conn->prepare("SELECT COUNT(*) FROM trabajos WHERE empresa_id=? AND estado='activo'");
$stmt->bind_param('i', $empresa_id);
$stmt->execute();
$stmt->bind_result($trabajos_activos);
$stmt->fetch();
$stmt->close();

// Ejemplo de pagos pendientes (puedes adaptar a tu estructura real)
$pagos_pendientes = 5; // Simulado

// --- TASA DE CAMBIO DEL DÍA (MULTIMONEDA) ---
$mensaje_tasa = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nueva_tasa'])) {
    $fecha = date('Y-m-d');
    $tasa = floatval(str_replace(',', '.', $_POST['nueva_tasa']));
    // Si ya existe, actualiza; si no, inserta
    $stmt = $conn->prepare("INSERT INTO tasas (fecha, tasa, usuario_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE tasa=VALUES(tasa), usuario_id=VALUES(usuario_id)");
    $usuario_id = isset($_SESSION['usuario_id']) ? $_SESSION['usuario_id'] : null;
    $stmt->bind_param("sdi", $fecha, $tasa, $usuario_id);
    if ($stmt->execute()) {
        $mensaje_tasa = "Tasa del día actualizada.";
    } else {
        $mensaje_tasa = "Error al actualizar tasa: " . $conn->error;
    }
    $stmt->close();
}
// Obtener la tasa de hoy para mostrarla
$hoy = date('Y-m-d');
$res = $conn->query("SELECT tasa FROM tasas WHERE fecha='$hoy' LIMIT 1");
$row = $res->fetch_assoc();
$tasa_actual = $row ? $row['tasa'] : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard | <?php echo htmlspecialchars($empresa_nombre); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <nav class="nav-bar">
        <div class="nav-logo">
            <img src="../assets/img/logo.png" alt="Logo" class="nav-logo-img">
        </div>
        <div class="nav-empresa"><b>Dashboard</b> | <span style="color:#FF7F36;"><?php echo htmlspecialchars($_SESSION['nombre']); ?></span></div>
        <div class="nav-user">
            <button type="button" class="btn-volver" onclick="window.location.href='/deco_system/select_empresa.php'">Sel. Empresa</button>
            <a href="../logout.php" class="nav-logout" title="Cerrar sesión">&#x1F511;</a>
        </div>
    </nav>
    <main class="dashboard-main">
        <div class="dashboard-header">
            <section class="dashboard-bienvenida">
                <h2>Bienvenido, <span class="dashboard-usuario"><?php echo htmlspecialchars($_SESSION['nombre']); ?></span></h2>
                <p>Panel principal de <span class="dashboard-empresa"><?php echo htmlspecialchars($empresa_nombre); ?></span></p>
            </section>
            <section class="dashboard-tasa">
                <form method="post" class="formulario" style="flex-direction:row;align-items:center;gap:8px;flex-wrap:wrap; justify-content:center;">
                    <label for="nueva_tasa" style="font-weight:bold;margin-right:7px;">Tasa $ hoy:</label>
                    <input type="number" step="0.0001" name="nueva_tasa" id="nueva_tasa" value="<?php echo htmlspecialchars($tasa_actual); ?>" required>
                    <button type="submit" class="btn-principal">Actualizar</button>
                </form>
                <?php if($mensaje_tasa): ?>
                  <div class="msg-info"><?php echo $mensaje_tasa; ?></div>
                <?php endif; ?>
            </section>
        </div>
        <div class="dashboard-row">
            <div class="dashboard-col">
                <section class="dashboard-resumen">
                    <!-- Tarjetas de resumen -->
                    <div class="dashboard-card saldo">
                        <div class="card-icon">&#128181;</div>
                        <div class="card-info">
                            <div class="card-title">Saldo Total</div>
                            <div class="card-value">Bs <?php echo number_format($saldos, 2, ',', '.'); ?></div>
                            <div class="card-sumatoria">
                                Saldo real sumado en bancos:<br>
                                Bs <?php echo number_format($saldos, 2, ',', '.'); ?>
                            </div>
                        </div>
                    </div>
                    <div class="dashboard-card pagos">
                        <div class="card-icon">&#9203;</div>
                        <div class="card-info">
                            <div class="card-title">Pagos Pendientes</div>
                            <div class="card-value"><?php echo $pagos_pendientes; ?></div>
                        </div>
                    </div>
                    <div class="dashboard-card trabajos">
                        <div class="card-icon">&#128736;</div>
                        <div class="card-info">
                            <div class="card-title">Trabajos en Ejecución</div>
                            <div class="card-value"><?php echo $trabajos_activos; ?></div>
                            <div class="card-valor-activos">
                                Cantidad exacta de trabajos activos: <?php echo $trabajos_activos; ?>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
        <section class="dashboard-modulos">
            <a href="clientes.php" class="modulo-btn">
                <span class="modulo-icon">&#128100;</span>
                <span class="modulo-text">Clientes</span>
            </a>
            <a href="contratistas.php" class="modulo-btn">
                <span class="modulo-icon">&#128188;</span>
                <span class="modulo-text">Contratistas</span>
            </a>
            <a href="trabajos.php" class="modulo-btn">
                <span class="modulo-icon">&#128736;</span>
                <span class="modulo-text">Trabajos</span>
            </a>
            <a href="bancos.php" class="modulo-btn">
                <span class="modulo-icon">&#128179;</span>
                <span class="modulo-text">Bancos</span>
            </a>
            <a href="pagos.php" class="modulo-btn">
                <span class="modulo-icon">&#9203;</span>
                <span class="modulo-text">Pagos</span>
            </a>
            <a href="servicios.php" class="modulo-btn">
                <span class="modulo-icon">&#9881;</span>
                <span class="modulo-text">Servicios</span>
            </a>
            <a href="reportes.php" class="modulo-btn">
                <span class="modulo-icon">&#128202;</span>
                <span class="modulo-text">Reportes</span>
            </a>
            <a href="configuracion.php" class="modulo-btn">
                <span class="modulo-icon">&#9881;&#65039;</span>
                <span class="modulo-text">Configuración</span>
            </a>
        </section>
    </main>
</body>
</html>