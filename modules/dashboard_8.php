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
    $stmt = $conn->prepare(
        "INSERT INTO tasas (fecha, tasa, usuario_id) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE tasa=VALUES(tasa), usuario_id=VALUES(usuario_id)"
    );
    $usuario_id = isset($_SESSION['usuario_id']) ? $_SESSION['usuario_id'] : null;
    $stmt->bind_param("sdi", $fecha, $tasa, $usuario_id);
    if ($stmt->execute()) {
        $mensaje_tasa = "Tasa del día actualizada.";
    } else {
        $mensaje_tasa = "Error al actualizar tasa: " . $conn->error;
    }
    $stmt->close();

    // Refrescar la última tasa inmediatamente para mostrar el cambio sin recargar la página manualmente
    $res = $conn->query("SELECT tasa, fecha FROM tasas ORDER BY fecha DESC, id DESC LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    $tasa_actual = $row ? $row['tasa'] : '';
    $fecha_tasa = $row ? $row['fecha'] : '';
}

// Obtener la última tasa registrada (no solo la de hoy) si no fue cargada por el POST
if (!isset($tasa_actual)) {
    $res = $conn->query("SELECT tasa, fecha FROM tasas ORDER BY fecha DESC, id DESC LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    $tasa_actual = $row ? $row['tasa'] : '';
    $fecha_tasa = $row ? $row['fecha'] : '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard | <?php echo htmlspecialchars($empresa_nombre); ?></title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="../assets/css/style.css">

    <!-- Pequeños estilos específicos para organizar mejor el dashboard sin tocar global CSS -->
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
        <div class="container-dashboard">
            <!-- Header: bienvenida + tasa -->
            <div class="header-grid">
                <div class="welcome-block">
                    <h2>Bienvenido, <span class="dashboard-usuario"><?php echo htmlspecialchars($_SESSION['nombre']); ?></span></h2>
                    <p>Panel principal de <strong><?php echo htmlspecialchars($empresa_nombre); ?></strong></p>
                </div>

                <div class="tasa-card-compact" role="region" aria-label="Tasa de cambio">
                    <div class="tasa-row-top">
                        <div class="tasa-anterior-label">Tasa anterior:</div>
                        <div class="tasa-valor">
                            <?php echo $tasa_actual ? number_format($tasa_actual, 4, ',', '.') : '--'; ?>
                            <?php if ($fecha_tasa) echo "<small style='color:#666;'> ($fecha_tasa)</small>"; ?>
                        </div>
                    </div>

                    <form method="post" class="formulario" style="margin:0;">
                        <div class="tasa-form-row">
                            <label for="nueva_tasa" style="font-weight:bold;margin-right:7px;display:none;">Tasa $ hoy:</label>
                            <input aria-label="Tasa a ingresar" type="number" step="0.0001" name="nueva_tasa" id="nueva_tasa"
                                   value="<?php echo htmlspecialchars($tasa_actual); ?>"
                                   class="input-tasa-dashboard"
                                   required>
                            <button type="submit" class="btn-principal">Actualizar</button>
                        </div>
                    </form>

                    <?php if($mensaje_tasa): ?>
                      <div class="msg-info" style="margin-top:4px;"><?php echo $mensaje_tasa; ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Resumen: tarjetas en grid para aprovechar ancho -->
            <section class="resumen-grid" aria-label="Resumen rápido">
                <div class="dashboard-card saldo" role="article" aria-label="Saldo Total">
                    <div class="card-icon">&#128181;</div>
                    <div class="card-info">
                        <div>
                            <div class="card-title">Saldo Total</div>
                            <div class="card-value">Bs <?php echo number_format($saldos, 2, ',', '.'); ?></div>
                        </div>
                        <div class="card-sumatoria">
                            Saldo real sumado en bancos:<br>
                            Bs <?php echo number_format($saldos, 2, ',', '.'); ?>
                        </div>
                    </div>
                </div>

                <div class="dashboard-card pagos" role="article" aria-label="Pagos Pendientes">
                    <div class="card-icon">&#9203;</div>
                    <div class="card-info">
                        <div>
                            <div class="card-title">Pagos Pendientes</div>
                            <div class="card-value"><?php echo $pagos_pendientes; ?></div>
                        </div>
                        <div style="min-height:1rem;"></div>
                    </div>
                </div>

                <div class="dashboard-card trabajos" role="article" aria-label="Trabajos en Ejecución">
                    <div class="card-icon">&#128736;</div>
                    <div class="card-info">
                        <div>
                            <div class="card-title">Trabajos en Ejecución</div>
                            <div class="card-value"><?php echo $trabajos_activos; ?></div>
                        </div>
                        <div class="card-valor-activos">
                            Cantidad exacta de trabajos activos: <?php echo $trabajos_activos; ?>
                        </div>
                    </div>
                </div>
            </section>

            <div class="spacer-bottom" aria-hidden="true"></div>
        </div>

        <!-- módulos (botones) fijos abajo -->
        <section class="dashboard-modulos" aria-label="Módulos rápidos">
            <a href="clientes.php" class="modulo-btn" title="Clientes">
                <span class="modulo-icon">&#128100;</span>
                <span class="modulo-text">Clientes</span>
            </a>
            <a href="contratistas.php" class="modulo-btn" title="Contratistas">
                <span class="modulo-icon">&#128188;</span>
                <span class="modulo-text">Contratistas</span>
            </a>
            <a href="trabajos.php" class="modulo-btn" title="Trabajos">
                <span class="modulo-icon">&#128736;</span>
                <span class="modulo-text">Trabajos</span>
            </a>
            <a href="bancos.php" class="modulo-btn" title="Bancos">
                <span class="modulo-icon">&#128179;</span>
                <span class="modulo-text">Bancos</span>
            </a>
            <a href="pagos.php" class="modulo-btn" title="Pagos">
                <span class="modulo-icon">&#9203;</span>
                <span class="modulo-text">Pagos</span>
            </a>
            <a href="servicios.php" class="modulo-btn" title="Servicios">
                <span class="modulo-icon">&#9881;</span>
                <span class="modulo-text">Servicios</span>
            </a>
            <a href="reportes.php" class="modulo-btn" title="Reportes">
                <span class="modulo-icon">&#128202;</span>
                <span class="modulo-text">Reportes</span>
            </a>
            <a href="configuracion.php" class="modulo-btn" title="Configuración">
                <span class="modulo-icon">&#9881;&#65039;</span>
                <span class="modulo-text">Configuración</span>
            </a>
        </section>
    </main>
</body>
</html>