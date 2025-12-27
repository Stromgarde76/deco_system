<?php
// Archivo: modules/proyectos.php - Vista de Proyectos compacta y organizada
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
$id_proy_nuevo = null;

// Eliminar proyecto si se recibió petición POST de eliminar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'eliminar') {
    $id_proy_del = intval($_POST['id_proy_eliminar']);
    $stmt = $conn->prepare("DELETE FROM proyectos WHERE id_proy=? AND empresa_id=?");
    $stmt->bind_param("ii", $id_proy_del, $empresa_id);
    if ($stmt->execute()) {
        $mensaje = "Proyecto eliminado correctamente.";
    } else {
        $mensaje = "Error al eliminar: " . $conn->error;
    }
    $stmt->close();
}

// Procesar nuevo proyecto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'nuevo') {
    $descripcion = trim($_POST['descripcion']);
    $cliente_id = intval($_POST['cliente_id']);
    $contratista_id = intval($_POST['contratista_id']);
    $fecha_inicio = $_POST['fecha_inicio'];
    $fecha_culminacion = !empty($_POST['fecha_culminacion']) ? $_POST['fecha_culminacion'] : null;
    $estado = $_POST['estado'];
    $moneda = $_POST['moneda'];
    $tasa_cambio = ($moneda === 'USD') ? floatval($_POST['tasa_cambio']) : null;
    $monto_inicial = floatval(str_replace(',', '.', $_POST['monto_inicial']));

    $stmt = $conn->prepare("INSERT INTO proyectos 
        (empresa_id, descripcion, cliente_id, contratista_id, fecha_inicio, fecha_culminacion, estado, monto_inicial, moneda, tasa_cambio) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isiisssdsd", $empresa_id, $descripcion, $cliente_id, $contratista_id, $fecha_inicio, $fecha_culminacion, $estado, $monto_inicial, $moneda, $tasa_cambio);
    if ($stmt->execute()) {
        $mensaje = "Proyecto guardado exitosamente.";
        $id_proy_nuevo = $stmt->insert_id;
    } else {
        $mensaje = "Error al guardar: " . $conn->error;
    }
    $stmt->close();
}

// Obtener clientes y contratistas para los select
$clientes = [];
$res = $conn->query("SELECT id, nombre FROM clientes WHERE empresa_id = $empresa_id");
while ($row = $res->fetch_assoc()) $clientes[] = $row;

$contratistas = [];
$res = $conn->query("SELECT id, nombre FROM contratistas WHERE empresa_id = $empresa_id");
while ($row = $res->fetch_assoc()) $contratistas[] = $row;

// Obtener lista de proyectos
$proyectos = [];
$res = $conn->query("SELECT p.*, c.nombre AS cliente_nombre, t.nombre AS contratista_nombre 
    FROM proyectos p
    LEFT JOIN clientes c ON p.cliente_id = c.id
    LEFT JOIN contratistas t ON p.contratista_id = t.id
    WHERE p.empresa_id = $empresa_id
    ORDER BY p.id_proy DESC");
while ($row = $res->fetch_assoc()) $proyectos[] = $row;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Proyectos</title>
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
        if (confirm("¿Seguro que deseas eliminar este proyecto?")) {
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
        <div class="nav-empresa"><b>Proyectos</b> | <span style="color:#FF7F36;"><?php echo htmlspecialchars($_SESSION['nombre']); ?></span></div>
        <div class="nav-user">
            <button type="button" class="btn-volver" onclick="window.location.href='dashboard.php'">Volver</button>
            <a href="../logout.php" class="nav-logout" title="Cerrar sesión">&#x1F511;</a>
        </div>
    </nav>
    <main>
        <div class="proyectos-main">
            <div class="proyectos-formulario">
                <h2>Nuevo Proyecto</h2>
                <?php if ($mensaje): ?>
                    <div class="msg-info"><?php echo $mensaje; ?>
                        <?php if ($id_proy_nuevo): ?> | <b>ID Proyecto:</b> <?php echo $id_proy_nuevo; ?><?php endif; ?>
                    </div>
                <?php endif; ?>
                <form method="post" class="formulario" autocomplete="off">
                    <input type="hidden" name="accion" value="nuevo">
                    <input type="text" name="id_proy" value="<?php echo $id_proy_nuevo ? $id_proy_nuevo : 'Automático'; ?>" readonly placeholder="ID Proyecto">
                    <input type="text" name="descripcion" required placeholder="Descripción / Nombre">
                    <select name="cliente_id" required>
                        <option value="">Cliente</option>
                        <?php foreach ($clientes as $cli): ?>
                        <option value="<?php echo $cli['id']; ?>"><?php echo htmlspecialchars($cli['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="contratista_id" required>
                        <option value="">Contratista</option>
                        <?php foreach ($contratistas as $con): ?>
                        <option value="<?php echo $con['id']; ?>"><?php echo htmlspecialchars($con['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="fecha_inicio" required placeholder="Fecha de inicio">
                    <input type="date" name="fecha_culminacion" placeholder="Fecha de culminación">
                    <select name="estado" required>
                        <option value="Evaluación">Evaluación</option>
                        <option value="Activo">Activo</option>
                        <option value="Finalizado">Finalizado</option>
                        <option value="En pausa">En pausa</option>
                    </select>
                    <!-- AQUI BLOQUE MULTIMONEDA -->
                    <select name="moneda" required>
                        <option value="VES">Bolívares</option>
                        <option value="USD">Dólares</option>
                    </select>
                    <input type="number" step="0.0001" min="0" name="tasa_cambio" id="tasa_cambio" placeholder="Tasa $ a Bs">
                    <!-- --------------------- -->
                    <input type="number" step="0.01" min="0" name="monto_inicial" required placeholder="Monto Inicial">
                    <button type="submit" class="btn-principal">Guardar Proyecto</button>
                </form>
            </div>
            <div class="proyectos-listado">
                <h2>Lista de Proyectos</h2>
                <div class="tabla-scroll">
                    <table class="tabla-proyectos">
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
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($proyectos as $p): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($p['id_proy']); ?></td>
                                <td><?php echo htmlspecialchars($p['descripcion']); ?></td>
                                <td><?php echo htmlspecialchars($p['cliente_nombre']); ?></td>
                                <td><?php echo htmlspecialchars($p['contratista_nombre']); ?></td>
                                <td><?php echo htmlspecialchars($p['fecha_inicio']); ?></td>
                                <td><?php echo htmlspecialchars($p['fecha_culminacion']); ?></td>
                                <td><?php echo htmlspecialchars($p['estado']); ?></td>
                                <td>
                                <?php
                                    echo number_format($p['monto_inicial'], 2, ',', '.');
                                    echo ($p['moneda'] === 'USD') ? ' $' : ' Bs';
                                    if ($p['moneda'] === 'USD' && $p['tasa_cambio']) {
                                        echo "<br><small>(" . number_format($p['monto_inicial'] * $p['tasa_cambio'], 2, ',', '.') . " Bs)</small>";
                                    }
                                ?>
                                </td>
                                <td class="col-acciones">
                                    <!-- Botón editar -->
                                    <a href="proyectos_editar.php?id=<?php echo urlencode($p['id_proy']); ?>" class="btn-accion editar" title="Editar">
                                        <i class="fa fa-edit"></i>
                                    </a>
                                    <!-- Botón eliminar (formulario para método POST seguro) -->
                                    <form id="form-eliminar-<?php echo $p['id_proy']; ?>" method="post" action="" style="display:inline;">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="id_proy_eliminar" value="<?php echo $p['id_proy']; ?>">
                                        <button type="button" class="btn-accion eliminar" title="Eliminar" onclick="confirmarEliminar(<?php echo $p['id_proy']; ?>)">
                                            <i class="fa fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</body>
</html>