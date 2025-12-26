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

// Obtener la última tasa registrada (no solo la de hoy)
$res = $conn->query("SELECT tasa, fecha FROM tasas ORDER BY fecha DESC, id DESC LIMIT 1");
$row = $res->fetch_assoc();
$tasa_actual = $row ? $row['tasa'] : '';
$fecha_tasa = $row ? $row['fecha'] : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard | <?php echo htmlspecialchars($empresa_nombre); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <style>
        .dashboard-header {
            background: var(--color-panel);
            box-shadow: 0 2px 8px var(--color-shadow);
            border-radius: 14px;
            padding: 1.2em 2em;
            margin-top: 2.2em;
            margin-bottom: 2.2em;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5em;
        }
        .dashboard-row {
            display: flex;
            flex-wrap: wrap;
            gap: 2rem;
            justify-content: center;
            align-items: stretch;
            margin-bottom: 2.1em;
        }
        .dashboard-col {
            flex: 1 1 320px;
            min-width: 270px;
            max-width: 450px;
            display: flex;
            flex-direction: column;
            gap: 1.2em;
        }
        .dashboard-tasa {
            background: var(--color-panel);
            border-radius: 14px;
            box-shadow: 0 2px 8px var(--color-shadow);
            padding: 1em 2em;
            margin-bottom: 1.4em;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            min-width: 350px;
            max-width: 440px;
        }
        .dashboard-bienvenida {
            text-align: center;
            margin-bottom: 1.2em;
        }
        .dashboard-bienvenida p.dashboard-empresa {
            font-size: 1.25rem;
            font-weight: bold;
            margin-top: 0.5em;
            margin-bottom: 0.8em;
            color: var(--color-titulo);
            letter-spacing: 1px;
        }
        .dashboard-resumen {
            display: flex;
            gap: 1.6em;
            justify-content: center;
            align-items: flex-start;
            margin-bottom: 2em;
        }
        .dashboard-card {
            background: var(--color-panel);
            border-radius: 17px;
            min-width: 260px;
            max-width: 260px;
            padding: 1.2rem 1.1rem;
            box-shadow: 0 4px 20px var(--color-shadow);
            display: flex;
            align-items: flex-start;
            gap: 1.2rem;
            color: #222b38;
            margin: 0.8rem 0.1rem;
            position: relative;
            border-left: 6px solid var(--color-principal);
            flex-direction: row;
        }
        .dashboard-card.saldo { border-color: #ffb366; }
        .dashboard-card.pagos { border-color: #37659a; }
        .dashboard-card.trabajos { border-color: #feca57; }
        /* Tasa anterior alineada en fila y a la derecha */
        .tasa-anterior-row {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 4px;
        }
        .tasa-anterior-label {
            font-size: 1em;
            font-weight: 600;
            color: #4d638c;
            margin-right: 8px;
        }
        .tasa-valor {
            font-weight: bold;
            color: var(--color-principal);
            font-size: 1.2em;
            text-align: right;
            margin-bottom: 4px;
            margin-left: auto;
            letter-spacing: 1px;
        }
        .input-tasa-dashboard {
            font-weight: bold;
            font-size: 1.15em;
            letter-spacing: 1px;
            color: var(--color-principal);
            text-align: right;
        }
        @media (max-width: 950px) {
            .dashboard-row, .dashboard-resumen {
                flex-direction: column;
                gap: 1.4em;
            }
            .dashboard-header {
                margin-top: 1em;
                margin-bottom: 1em;
            }
            .dashboard-tasa {
                min-width: 200px;
                max-width: 100%;
            }
        }
    </style>
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
                <p class="dashboard-empresa">Panel principal de <?php echo htmlspecialchars($empresa_nombre); ?></p>
            </section>
            <section class="dashboard-tasa">
                <div class="tasa-anterior-row">
                    <span class="tasa-anterior-label">Tasa anterior:</span>
                    <span class="tasa-valor">
                        <?php echo $tasa_actual ? number_format($tasa_actual, 4, ',', '.') : '--'; ?>
                        <?php if($fecha_tasa) echo "<small style='color:#666;'>&nbsp;($fecha_tasa)</small>"; ?>
                    </span>
                </div>
                <form method="post" class="formulario" style="flex-direction:row;align-items:center;gap:8px;flex-wrap:wrap; justify-content:center;">
                    <label for="nueva_tasa" style="font-weight:bold;margin-right:7px;">
                        Tasa $ hoy:
                    </label>
                    <input type="number" step="0.0001" name="nueva_tasa" id="nueva_tasa"
                        value="<?php echo htmlspecialchars($tasa_actual); ?>"
                        class="input-tasa-dashboard"
                        required>
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