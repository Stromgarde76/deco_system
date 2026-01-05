<?php
// Archivo: modules/trabajos.php - Vista de Trabajos compacta y organizada
session_start();
require_once "../config/db.php";

// Verifica sesión y empresa seleccionada
if (!isset($_SESSION['usuario'])) {
    header("Location: ../index.php");
    exit();
}
if (!isset($_SESSION['empresa_id'])) {
    header("Location: ../select_empresa.php");
    exit();
}

$empresa_id = $_SESSION['empresa_id'];
$mensaje = "";

// --- EDICIÓN EN LA MISMA PÁGINA ---
$trabajo_editar = null;
if (isset($_GET['editar'])) {
    $id_editar = intval($_GET['editar']);
    $stmt = $conn->prepare("SELECT * FROM trabajos WHERE id_trab=? AND empresa_id=?");
    $stmt->bind_param("ii", $id_editar, $empresa_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $trabajo_editar = $res->fetch_assoc();
    $stmt->close();
}

// Eliminar trabajo si se recibió petición POST de eliminar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'eliminar') {
    $id_trab_del = intval($_POST['id_trab_eliminar']);
    $stmt = $conn->prepare("DELETE FROM trabajos WHERE id_trab=? AND empresa_id=?");
    $stmt->bind_param("ii", $id_trab_del, $empresa_id);
    if ($stmt->execute()) {
        $mensaje = "Trabajo eliminado correctamente.";
    } else {
        $mensaje = "Error al eliminar: " . $conn->error;
    }
    $stmt->close();
}

// Procesar nuevo trabajo O edición
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && ($_POST['accion'] === 'nuevo' || $_POST['accion'] === 'editar')) {
    $descripcion = trim($_POST['descripcion']);
    $cliente_id = intval($_POST['cliente_id']);
    $contratista_id = intval($_POST['contratista_id']);
    $fecha_inicio = $_POST['fecha_inicio'];
    $fecha_culminacion = !empty($_POST['fecha_culminacion']) ? $_POST['fecha_culminacion'] : null;
    $estado = $_POST['estado'];
    $moneda = $_POST['moneda'];
    $tasa_cambio = ($moneda === 'USD') ? floatval($_POST['tasa_cambio']) : null;
    $monto_inicial = floatval(str_replace(',', '.', $_POST['monto_inicial']));

    if ($_POST['accion'] === 'nuevo') {
        $stmt = $conn->prepare("INSERT INTO trabajos 
            (empresa_id, descripcion, cliente_id, contratista_id, fecha_inicio, fecha_culminacion, estado, monto_inicial, moneda, tasa_cambio) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isiisssdsd", $empresa_id, $descripcion, $cliente_id, $contratista_id, $fecha_inicio, $fecha_culminacion, $estado, $monto_inicial, $moneda, $tasa_cambio);
        if ($stmt->execute()) {
            $mensaje = "Trabajo guardado exitosamente.";
        } else {
            $mensaje = "Error al guardar: " . $conn->error;
        }
        $stmt->close();
    }
    if ($_POST['accion'] === 'editar') {
        $id_editar = intval($_POST['id_trab']);
        $stmt = $conn->prepare("UPDATE trabajos SET descripcion=?, cliente_id=?, contratista_id=?, fecha_inicio=?, fecha_culminacion=?, estado=?, monto_inicial=?, moneda=?, tasa_cambio=? WHERE id_trab=? AND empresa_id=?");
        $stmt->bind_param("siisssdssii", $descripcion, $cliente_id, $contratista_id, $fecha_inicio, $fecha_culminacion, $estado, $monto_inicial, $moneda, $tasa_cambio, $id_editar, $empresa_id);
        if ($stmt->execute()) {
            $mensaje = "Trabajo actualizado correctamente.";
        } else {
            $mensaje = "Error al actualizar: " . $conn->error;
        }
        $stmt->close();
        $trabajo_editar = null;
    }
}

// Obtener clientes y contratistas para los select
$clientes = [];
$res = $conn->query("SELECT id, nombre FROM clientes WHERE empresa_id = $empresa_id");
while ($row = $res->fetch_assoc()) $clientes[] = $row;

$contratistas = [];
$res = $conn->query("SELECT id, nombre FROM contratistas WHERE empresa_id = $empresa_id");
while ($row = $res->fetch_assoc()) $contratistas[] = $row;

// Obtener lista de trabajos
$trabajos = [];
$res = $conn->query("SELECT t.*, c.nombre AS cliente_nombre, co.nombre AS contratista_nombre 
    FROM trabajos t
    LEFT JOIN clientes c ON t.cliente_id = c.id
    LEFT JOIN contratistas co ON t.contratista_id = co.id
    WHERE t.empresa_id = $empresa_id
    ORDER BY t.id_trab DESC");
while ($row = $res->fetch_assoc()) $trabajos[] = $row;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Trabajos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script>
    // Mostrar/ocultar el campo "tasa cambio" según la moneda seleccionada
    function actualizarTasa() {
      var moneda = document.querySelector('select[name="moneda"]').value;
      var tasa = document.getElementById('tasa_cambio');
      if (moneda === 'USD') {
        tasa.style.display = '';
        tasa.required = true;
      } else {
        tasa.style.display = 'none';
        tasa.required = false;
      }
    }
    document.addEventListener('DOMContentLoaded', function() {
      var sel = document.querySelector('select[name="moneda"]');
      if (sel) {
        sel.addEventListener('change', actualizarTasa);
        actualizarTasa();
      }
    });

    // Confirmar antes de eliminar
    function confirmarEliminar(id) {
        if (confirm("¿Seguro que deseas eliminar este trabajo?")) {
            document.getElementById('form-eliminar-' + id).submit();
        }
    }
    </script>
</head>
<body>
    <nav class="nav-bar">
        <div class="nav-logo">
            <img src="../assets/img/logo.png" alt="Logo" class="nav-logo-img">
        </div>
        <div class="nav-empresa"><b>Trabajos</b> | <span style="color:#FF7F36;"><?php echo htmlspecialchars($_SESSION['nombre']); ?></span></div>
        <div class="nav-user">
            <button type="button" class="btn-volver" onclick="window.location.href='dashboard.php'">Volver</button>
            <a href="../logout.php" class="nav-logout" title="Cerrar sesión">&#x1F511;</a>
        </div>
    </nav>
    <main class="seccion-bancos">
        <section class="bancos-form">
            <h2><?php echo $trabajo_editar ? "Editar Trabajo" : "Nuevo Trabajo"; ?></h2>
            <?php if ($mensaje): ?>
                <div class="msg"><?php echo $mensaje; ?></div>
            <?php endif; ?>
            <form method="post" class="formulario" autocomplete="off">
                <input type="hidden" name="accion" value="<?php echo $trabajo_editar ? 'editar' : 'nuevo'; ?>">
                <?php if ($trabajo_editar): ?>
                    <input type="hidden" name="id_trab" value="<?php echo htmlspecialchars($trabajo_editar['id_trab']); ?>">
                <?php endif; ?>
                <input type="text" name="id_trab" value="<?php echo $trabajo_editar ? htmlspecialchars($trabajo_editar['id_trab']) : "Automático"; ?>" readonly placeholder="ID Trabajo">
                <input type="text" name="descripcion" required placeholder="Descripción / Nombre" value="<?php echo $trabajo_editar ? htmlspecialchars($trabajo_editar['descripcion']) : ''; ?>">
                <select name="cliente_id" required>
                    <option value="">Cliente</option>
                    <?php foreach ($clientes as $cli): ?>
                    <option value="<?php echo $cli['id']; ?>" <?php echo $trabajo_editar && $cli['id'] == $trabajo_editar['cliente_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cli['nombre']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <select name="contratista_id" required>
                    <option value="">Contratista</option>
                    <?php foreach ($contratistas as $con): ?>
                    <option value="<?php echo $con['id']; ?>" <?php echo $trabajo_editar && $con['id'] == $trabajo_editar['contratista_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($con['nombre']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="fecha_inicio" required placeholder="Fecha de inicio" value="<?php echo $trabajo_editar ? htmlspecialchars($trabajo_editar['fecha_inicio']) : ''; ?>">
                <input type="date" name="fecha_culminacion" placeholder="Fecha de culminación" value="<?php echo $trabajo_editar ? htmlspecialchars($trabajo_editar['fecha_culminacion']) : ''; ?>">
                <select name="estado" required>
                    <option value="Evaluación" <?php echo $trabajo_editar && $trabajo_editar['estado']=='Evaluación' ? 'selected' : ''; ?>>Evaluación</option>
                    <option value="Activo" <?php echo $trabajo_editar && $trabajo_editar['estado']=='Activo' ? 'selected' : ''; ?>>Activo</option>
                    <option value="Finalizado" <?php echo $trabajo_editar && $trabajo_editar['estado']=='Finalizado' ? 'selected' : ''; ?>>Finalizado</option>
                    <option value="En pausa" <?php echo $trabajo_editar && $trabajo_editar['estado']=='En pausa' ? 'selected' : ''; ?>>En pausa</option>
                </select>
                <select name="moneda" required>
                    <option value="VES" <?php echo $trabajo_editar && $trabajo_editar['moneda']=='VES' ? 'selected' : ''; ?>>Bolívares</option>
                    <option value="USD" <?php echo $trabajo_editar && $trabajo_editar['moneda']=='USD' ? 'selected' : ''; ?>>Dólares</option>
                </select>
                <input type="number" step="0.0001" min="0" name="tasa_cambio" id="tasa_cambio" placeholder="Tasa $ a Bs" value="<?php echo $trabajo_editar ? htmlspecialchars($trabajo_editar['tasa_cambio']) : ''; ?>">
                <input type="number" step="0.01" min="0" name="monto_inicial" required placeholder="Monto Inicial" value="<?php echo $trabajo_editar ? htmlspecialchars($trabajo_editar['monto_inicial']) : ''; ?>">
                <div class="form-btns">
                    <button type="submit" class="btn-principal"><?php echo $trabajo_editar ? "Actualizar" : "Guardar Trabajo"; ?></button>
                    <?php if ($trabajo_editar): ?>
                        <a href="trabajos.php" class="btn-cancelar">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>
        <section class="bancos-lista">
            <h2>Lista de Trabajos</h2>
            <div class="tabla-scroll">
            <table class="tabla-bancos">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Descripción</th>
                        <th>Cliente</th>
                        <th>Contratista</th>
                        <th>Inicio</th>
                        <th>Fin</th>
                        <th>Estado</th>
                        <th>Monto Inicial</th>
                        <th class="col-acciones">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($trabajos as $t): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($t['id_trab']); ?></td>
                        <td><?php echo htmlspecialchars($t['descripcion']); ?></td>
                        <td><?php echo htmlspecialchars($t['cliente_nombre']); ?></td>
                        <td><?php echo htmlspecialchars($t['contratista_nombre']); ?></td>
                        <td><?php echo htmlspecialchars($t['fecha_inicio']); ?></td>
                        <td><?php echo htmlspecialchars($t['fecha_culminacion']); ?></td>
                        <td><?php echo htmlspecialchars($t['estado']); ?></td>
                        <td>
                            <?php
                                echo number_format($t['monto_inicial'], 2, ',', '.');
                                echo ($t['moneda'] === 'USD') ? ' $' : ' Bs';
                                if ($t['moneda'] === 'USD' && $t['tasa_cambio']) {
                                    echo "<br><small>(" . number_format($t['monto_inicial'] * $t['tasa_cambio'], 2, ',', '.') . " Bs)</small>";
                                }
                            ?>
                        </td>
                        <td>
                            <a href="trabajos.php?editar=<?php echo urlencode($t['id_trab']); ?>" class="btn-accion editar" title="Editar">&#9998;</a>
                            <form id="form-eliminar-<?php echo $t['id_trab']; ?>" method="post" action="" class="form-inline">
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id_trab_eliminar" value="<?php echo $t['id_trab']; ?>">
                                <button type="button" class="btn-accion eliminar" title="Eliminar" onclick="confirmarEliminar(<?php echo $t['id_trab']; ?>)">&#128465;</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($trabajos) === 0): ?>
                    <tr>
                        <td colspan="9" class="tabla-vacia">Sin trabajos registrados.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </section>
    </main>
</body>
</html>