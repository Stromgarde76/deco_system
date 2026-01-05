<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION['usuario'])) {
    header('Location: ../index.php');
    exit();
}
if (!isset($_SESSION['empresa_id'])) {
    header('Location: ../select_empresa.php');
    exit();
}

$empresa_id = $_SESSION['empresa_id'];
$msg = "";

// Generar siguiente cod_serv
function generarCodigoServicio($conn, $empresa_id) {
    $sql = "SELECT cod_serv FROM servicios WHERE empresa_id=? ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $empresa_id);
    $stmt->execute();
    $stmt->bind_result($last_cod);
    $last_cod = null;
    $stmt->fetch();
    $stmt->close();
    if ($last_cod && preg_match('/^SRV(\d{4,})$/', $last_cod, $m)) {
        $num = intval($m[1]) + 1;
    } else {
        $num = 1;
    }
    return 'SRV' . str_pad($num, 4, '0', STR_PAD_LEFT);
}

// --- AGREGAR SERVICIO ---
if (isset($_POST['accion']) && $_POST['accion'] === 'agregar') {
    $nombre = trim($_POST['nombre']);
    $tipo = $_POST['tipo'];
    $costo = floatval(str_replace(',', '.', $_POST['costo']));
    $descripcion = trim($_POST['descripcion']);
    $cod_serv = generarCodigoServicio($conn, $empresa_id);

    $sql = "INSERT INTO servicios (empresa_id, cod_serv, nombre, tipo, costo, descripcion) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('isssds', $empresa_id, $cod_serv, $nombre, $tipo, $costo, $descripcion);
    if ($stmt->execute()) {
        $msg = "Servicio agregado exitosamente.";
    } else {
        $msg = "Error al agregar servicio.";
    }
    $stmt->close();
}

// --- EDITAR SERVICIO ---
if (isset($_POST['accion']) && $_POST['accion'] === 'editar' && isset($_POST['servicio_id'])) {
    $servicio_id = intval($_POST['servicio_id']);
    $nombre = trim($_POST['nombre']);
    $tipo = $_POST['tipo'];
    $costo = floatval(str_replace(',', '.', $_POST['costo']));
    $descripcion = trim($_POST['descripcion']);

    $sql = "UPDATE servicios SET nombre=?, tipo=?, costo=?, descripcion=? WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssdsii', $nombre, $tipo, $costo, $descripcion, $servicio_id, $empresa_id);
    if ($stmt->execute()) {
        $msg = "Servicio actualizado.";
    } else {
        $msg = "Error al actualizar servicio.";
    }
    $stmt->close();
}

// --- ELIMINAR SERVICIO ---
if (isset($_POST['accion']) && $_POST['accion'] === 'eliminar' && isset($_POST['servicio_id'])) {
    $servicio_id = intval($_POST['servicio_id']);
    $sql = "DELETE FROM servicios WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $servicio_id, $empresa_id);
    if ($stmt->execute()) {
        $msg = "Servicio eliminado.";
    } else {
        $msg = "Error al eliminar servicio.";
    }
    $stmt->close();
}

// --- OBTENER DATOS DE UN SERVICIO PARA EDITAR ---
$servicio_editar = null;
if (isset($_GET['editar'])) {
    $servicio_id = intval($_GET['editar']);
    $sql = "SELECT * FROM servicios WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $servicio_id, $empresa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $servicio_editar = $result->fetch_assoc();
    $stmt->close();
}

// --- LISTAR SERVICIOS ---
$servicios = [];
$sql = "SELECT * FROM servicios WHERE empresa_id=? ORDER BY nombre ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $empresa_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $servicios[] = $row;
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Servicios</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <nav class="nav-bar">
        <div class="nav-logo">
            <img src="../assets/img/logo.png" alt="Logo" class="nav-logo-img">
        </div>
        <div class="nav-empresa"><b>Servicios</b> | <span style="color:#FF7F36;"><?php echo htmlspecialchars($_SESSION['nombre']); ?></span></div>
        <div class="nav-user">
            <button type="button" class="btn-volver" onclick="window.location.href='dashboard.php'">Volver</button>
            <a href="../logout.php" class="nav-logout" title="Cerrar sesión">&#x1F511;</a>
        </div>
    </nav>
    <main class="seccion-bancos">
        <section class="bancos-form">
                <h2><?php echo $servicio_editar ? "Editar Servicio" : "Agregar Servicio"; ?></h2>
                <?php if ($msg): ?>
                    <div class="msg"><?php echo $msg; ?></div>
                <?php endif; ?>
                <form method="POST" class="formulario form-servicios" autocomplete="off">
                    <input type="hidden" name="accion" value="<?php echo $servicio_editar ? 'editar' : 'agregar'; ?>">
                    <?php if ($servicio_editar): ?>
                        <input type="hidden" name="servicio_id" value="<?php echo $servicio_editar['id']; ?>">
                    <?php endif; ?>
                    <!-- Código de servicio solo visible si se está editando -->
                    <?php if ($servicio_editar): ?>
                        <input type="text" value="<?php echo htmlspecialchars($servicio_editar['cod_serv']); ?>" disabled placeholder="Código del servicio">
                    <?php endif; ?>
                    <input type="text" name="nombre" value="<?php echo $servicio_editar['nombre'] ?? ''; ?>" required placeholder="Nombre del servicio">
                    <select name="tipo" required>
                        <option value="">Tipo de servicio</option>
                        <option value="eventual" <?php if(($servicio_editar['tipo'] ?? '')=='eventual') echo 'selected'; ?>>Eventual</option>
                        <option value="quincenal" <?php if(($servicio_editar['tipo'] ?? '')=='quincenal') echo 'selected'; ?>>Quincenal</option>
                        <option value="mensual" <?php if(($servicio_editar['tipo'] ?? '')=='mensual') echo 'selected'; ?>>Mensual</option>
                        <option value="anual" <?php if(($servicio_editar['tipo'] ?? '')=='anual') echo 'selected'; ?>>Anual</option>
                    </select>
                    <input type="number" name="costo" step="0.01" min="0"
                        value="<?php echo isset($servicio_editar['costo']) ? $servicio_editar['costo'] : ''; ?>" required
                        placeholder="Costo del servicio">
                    <textarea name="descripcion" placeholder="Descripción"><?php echo $servicio_editar['descripcion'] ?? ''; ?></textarea>
                    <div class="form-btns">
                        <button type="submit" class="btn-principal"><?php echo $servicio_editar ? "Actualizar" : "Agregar"; ?></button>
                        <?php if ($servicio_editar): ?>
                            <a href="servicios.php" class="btn-cancelar">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>
        <section class="bancos-lista">
                <h2>Listado de Servicios</h2>
            <div class="tabla-scroll">
            <table class="tabla-bancos">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nombre</th>
                                <th>Tipo</th>
                                <th>Costo</th>
                                <th>Descripción</th>
                                <th class="col-acciones">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($servicios as $s): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($s['cod_serv']); ?></td>
                                <td><?php echo htmlspecialchars($s['nombre']); ?></td>
                                <td><?php echo ucfirst(htmlspecialchars($s['tipo'])); ?></td>
                                <td class="td-costo"><?php echo number_format($s['costo'],2,',','.'); ?></td>
                                <td class="text-left"><?php echo htmlspecialchars($s['descripcion']); ?></td>
                                <td>
                                    <a href="?editar=<?php echo $s['id']; ?>" class="btn-accion editar" title="Editar">&#9998;</a>
                                    <form method="POST" class="form-inline" onsubmit="return confirm('¿Eliminar este servicio?');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="servicio_id" value="<?php echo $s['id']; ?>">
                                        <button type="submit" class="btn-accion eliminar" title="Eliminar">&#128465;</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (count($servicios) === 0): ?>
                            <tr>
                                <td colspan="6" class="tabla-vacia">Sin servicios registrados.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
            </table>
            </div>
        </section>
    </main>
</body>
</html>