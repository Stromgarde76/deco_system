<?php
// Archivo: modules/cobranzas.php
// Módulo para ver, agregar, editar y eliminar cobranzas de la empresa actual

session_start();
require_once "../config/db.php";

// Verifica si el usuario está autenticado y con empresa seleccionada
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

// --- OBTENER BANCOS (CUENTAS DESTINO) ---
$bancos = [];
$sql = "SELECT id, nombre, tipo_cuenta, numero_cuenta FROM bancos WHERE empresa_id=? ORDER BY nombre ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $empresa_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $bancos[] = $row;
$stmt->close();

// --- AGREGAR COBRANZA ---
if (isset($_POST['accion']) && $_POST['accion'] === 'agregar') {
    $cliente_id = intval($_POST['cliente_id']);
    $proyecto_id = intval($_POST['proyecto_id']);
    // FECHA: se toma la seleccionada por el usuario, o la actual si viene vacía
    $fecha = trim($_POST['fecha']) ?: date('Y-m-d');
    $monto = floatval(str_replace(['.', ','], ['', '.'], str_replace('.', '', $_POST['monto'])));
    $descripcion = trim($_POST['descripcion']);
    $destino_id = intval($_POST['destino']);

    $sql = "INSERT INTO cobranzas (empresa_id, cliente_id, proyecto_id, fecha, monto, descripcion, destino) VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiisdsi', $empresa_id, $cliente_id, $proyecto_id, $fecha, $monto, $descripcion, $destino_id);
    if ($stmt->execute()) {
        // Sumar el monto a la cuenta destino
        $sql_upd = "UPDATE bancos SET saldo = saldo + ? WHERE id=? AND empresa_id=?";
        $stmt2 = $conn->prepare($sql_upd);
        $stmt2->bind_param('dii', $monto, $destino_id, $empresa_id);
        $stmt2->execute();
        $stmt2->close();

        $msg = "Cobranza registrada exitosamente y saldo actualizado en la cuenta destino.";
    } else {
        $msg = "Error al registrar cobranza.";
    }
    $stmt->close();
}

// --- EDITAR COBRANZA ---
if (isset($_POST['accion']) && $_POST['accion'] === 'editar' && isset($_POST['cobranza_id'])) {
    $cobranza_id = intval($_POST['cobranza_id']);
    $cliente_id = intval($_POST['cliente_id']);
    $proyecto_id = intval($_POST['proyecto_id']);
    $fecha = trim($_POST['fecha']) ?: date('Y-m-d');
    $monto = floatval(str_replace(['.', ','], ['', '.'], str_replace('.', '', $_POST['monto'])));
    $descripcion = trim($_POST['descripcion']);
    $destino_id = intval($_POST['destino']);

    // Consultar cobranza anterior
    $sql_old = "SELECT monto, destino FROM cobranzas WHERE id=? AND empresa_id=?";
    $stmt_old = $conn->prepare($sql_old);
    $stmt_old->bind_param('ii', $cobranza_id, $empresa_id);
    $stmt_old->execute();
    $rs_old = $stmt_old->get_result();
    $old = $rs_old->fetch_assoc();
    $stmt_old->close();

    // Actualizar cobranza
    $sql = "UPDATE cobranzas SET cliente_id=?, proyecto_id=?, fecha=?, monto=?, descripcion=?, destino=? WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iisdssii', $cliente_id, $proyecto_id, $fecha, $monto, $descripcion, $destino_id, $cobranza_id, $empresa_id);
    if ($stmt->execute()) {
        // Si cambió el destino o el monto, ajusta saldos
        if ($old) {
            if ($old['destino'] != $destino_id) {
                // Quita monto anterior de la cuenta vieja, suma a la nueva
                $sql1 = "UPDATE bancos SET saldo = saldo - ? WHERE id=? AND empresa_id=?";
                $stmt1 = $conn->prepare($sql1);
                $stmt1->bind_param('dii', $old['monto'], $old['destino'], $empresa_id);
                $stmt1->execute();
                $stmt1->close();

                $sql2 = "UPDATE bancos SET saldo = saldo + ? WHERE id=? AND empresa_id=?";
                $stmt2 = $conn->prepare($sql2);
                $stmt2->bind_param('dii', $monto, $destino_id, $empresa_id);
                $stmt2->execute();
                $stmt2->close();
            } else if ($old['monto'] != $monto) {
                // Solo ajusta el mismo destino
                $delta = $monto - $old['monto'];
                $sql2 = "UPDATE bancos SET saldo = saldo + ? WHERE id=? AND empresa_id=?";
                $stmt2 = $conn->prepare($sql2);
                $stmt2->bind_param('dii', $delta, $destino_id, $empresa_id);
                $stmt2->execute();
                $stmt2->close();
            }
        }
        $msg = "Cobranza actualizada y saldo ajustado.";
    } else {
        $msg = "Error al actualizar cobranza.";
    }
    $stmt->close();
}

// --- ELIMINAR COBRANZA ---
if (isset($_POST['accion']) && $_POST['accion'] === 'eliminar' && isset($_POST['cobranza_id'])) {
    $cobranza_id = intval($_POST['cobranza_id']);
    // Buscar datos de la cobranza para restar saldo
    $sql_old = "SELECT monto, destino FROM cobranzas WHERE id=? AND empresa_id=?";
    $stmt_old = $conn->prepare($sql_old);
    $stmt_old->bind_param('ii', $cobranza_id, $empresa_id);
    $stmt_old->execute();
    $rs_old = $stmt_old->get_result();
    $old = $rs_old->fetch_assoc();
    $stmt_old->close();

    $sql = "DELETE FROM cobranzas WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $cobranza_id, $empresa_id);
    if ($stmt->execute()) {
        // Quita el monto de la cuenta destino
        if ($old) {
            $sql1 = "UPDATE bancos SET saldo = saldo - ? WHERE id=? AND empresa_id=?";
            $stmt1 = $conn->prepare($sql1);
            $stmt1->bind_param('dii', $old['monto'], $old['destino'], $empresa_id);
            $stmt1->execute();
            $stmt1->close();
        }
        $msg = "Cobranza eliminada y saldo descontado en la cuenta destino.";
    } else {
        $msg = "Error al eliminar cobranza.";
    }
    $stmt->close();
}

// --- OBTENER DATOS DE UNA COBRANZA PARA EDITAR ---
$cobranza_editar = null;
if (isset($_GET['editar'])) {
    $cobranza_id = intval($_GET['editar']);
    $sql = "SELECT * FROM cobranzas WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $cobranza_id, $empresa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $cobranza_editar = $result->fetch_assoc();
    $stmt->close();
}

// --- OBTENER CLIENTES Y PROYECTOS ---
$clientes = [];
$sql = "SELECT id, nombre FROM clientes WHERE empresa_id=? ORDER BY nombre ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $empresa_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $clientes[] = $row;
$stmt->close();

$proyectos = [];
$sql = "SELECT id_proy, descripcion FROM proyectos WHERE empresa_id=? ORDER BY descripcion ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $empresa_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $proyectos[] = $row;
$stmt->close();

// --- LISTAR COBRANZAS ---
$cobranzas = [];
$sql = "SELECT c.*, cl.nombre AS cliente_nombre, p.descripcion AS proyecto_descripcion, b.nombre AS banco_nombre, b.tipo_cuenta, b.numero_cuenta 
        FROM cobranzas c
        LEFT JOIN clientes cl ON c.cliente_id = cl.id
        LEFT JOIN proyectos p ON c.proyecto_id = p.id_proy
        LEFT JOIN bancos b ON c.destino = b.id
        WHERE c.empresa_id=?
        ORDER BY c.fecha DESC, c.id DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $empresa_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $cobranzas[] = $row;
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cobranzas</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script>
    // Formatea el campo de monto en tiempo real: miles (.) y decimales (,)
    function formatMonto(input) {
        let value = input.value.replace(/\./g, '').replace(/,/g, '');
        if (!value) {
            input.value = '';
            return;
        }
        let parts = value.split('.');
        let intPart = parts[0];
        let decPart = parts[1] ? parts[1].substring(0,2) : '';
        let formatted = '';
        intPart = intPart.replace(/^0+/, '') || '0';
        formatted = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        if (decPart) formatted += ',' + decPart;
        input.value = formatted;
    }
    window.addEventListener('DOMContentLoaded', function() {
        var monto = document.getElementById('monto');
        if (monto) {
            monto.addEventListener('input', function() { formatMonto(this); });
        }

        // Hacer input fecha editable pero con valor por defecto actual
        var fecha = document.querySelector('input[name="fecha"]');
        if (fecha && !fecha.value) {
            var d = new Date();
            var month = '' + (d.getMonth() + 1);
            var day = '' + d.getDate();
            var year = d.getFullYear();
            if (month.length < 2) month = '0' + month;
            if (day.length < 2) day = '0' + day;
            fecha.value = [year, month, day].join('-');
        }
    });
    </script>
</head>
<body>
    <nav class="nav-bar">
        <div class="nav-logo">
            <img src="../assets/img/logo.png" alt="Logo" class="nav-logo-img">
        </div>
        <div class="nav-empresa"><b>Cobranzas</b> | <span style="color:#FF7F36;"><?php echo htmlspecialchars($_SESSION['nombre']); ?></span></div>
        <div class="nav-user">
            <button type="button" class="btn-volver" onclick="window.location.href='dashboard.php'">Volver</button>
            <a href="../logout.php" class="nav-logout" title="Cerrar sesión">&#x1F511;</a>
        </div>
    </nav>
    <main class="seccion-cobranzas">
        <div class="cobranzas-flex-container">
            <section class="cobranzas-form">
                <h2><?php echo $cobranza_editar ? "Editar Cobranza" : "Registrar Cobranza"; ?></h2>
                <?php if ($msg): ?>
                    <div class="msg"><?php echo $msg; ?></div>
                <?php endif; ?>
                <!-- Formulario de cobranzas: cada campo en su propia línea, usando la clase .formulario y form-cobranzas -->
                <form method="POST" class="formulario form-cobranzas" autocomplete="off">
                    <input type="hidden" name="accion" value="<?php echo $cobranza_editar ? 'editar' : 'agregar'; ?>">
                    <?php if ($cobranza_editar): ?>
                        <input type="hidden" name="cobranza_id" value="<?php echo $cobranza_editar['id']; ?>">
                    <?php endif; ?>
                    <select name="cliente_id" required>
                        <option value="">Seleccione Cliente</option>
                        <?php foreach ($clientes as $cli): ?>
                            <option value="<?php echo $cli['id']; ?>" <?php if (($cobranza_editar['cliente_id'] ?? '') == $cli['id']) echo "selected"; ?>>
                                <?php echo htmlspecialchars($cli['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="proyecto_id" required>
                        <option value="">Seleccione Proyecto</option>
                        <?php foreach ($proyectos as $p): ?>
                            <option value="<?php echo $p['id_proy']; ?>" <?php if (($cobranza_editar['proyecto_id'] ?? '') == $p['id_proy']) echo "selected"; ?>>
                                <?php echo htmlspecialchars($p['descripcion']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <!-- El campo fecha ahora por defecto es la actual, pero editable -->
                    <input type="date" name="fecha"
                        value="<?php echo $cobranza_editar['fecha'] ?? date('Y-m-d'); ?>"
                        placeholder="Fecha">
                    <!-- Campo destino (cuenta bancaria) -->
                    <select name="destino" required>
                        <option value="">Destino: Cuenta Bancaria</option>
                        <?php foreach ($bancos as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php if (($cobranza_editar['destino'] ?? '') == $b['id']) echo "selected"; ?>>
                                <?php echo htmlspecialchars($b['nombre'] . " (" . $b['tipo_cuenta'] . ") - " . substr($b['numero_cuenta'], -4)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="monto" name="monto" value="<?php echo isset($cobranza_editar['monto']) ? number_format($cobranza_editar['monto'], 2, ',', '.') : ''; ?>" required placeholder="Monto (Bs. o Divisas)">
                    <textarea name="descripcion" placeholder="Descripción"><?php echo $cobranza_editar['descripcion'] ?? ''; ?></textarea>
                    <div class="form-btns">
                        <button type="submit" class="btn-principal"><?php echo $cobranza_editar ? "Actualizar" : "Registrar"; ?></button>
                        <?php if ($cobranza_editar): ?>
                            <a href="cobranzas.php" class="btn-cancelar">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>
            <section class="cobranzas-lista">
                <h2>Listado de Cobranzas</h2>
                <div class="tabla-scroll">
                    <table class="tabla-cobranzas">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Cliente</th>
                                <th>Proyecto</th>
                                <th>Destino</th>
                                <th>Monto</th>
                                <th>Descripción</th>
                                <th class="col-acciones">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cobranzas as $c): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($c['fecha']); ?></td>
                                <td><?php echo htmlspecialchars($c['cliente_nombre']); ?></td>
                                <td><?php echo htmlspecialchars($c['proyecto_descripcion']); ?></td>
                                <td class="td-banco">
                                    <?php echo htmlspecialchars($c['banco_nombre']); ?>
                                    <br>
                                    <span class="info-secundaria">
                                        <?php echo htmlspecialchars($c['tipo_cuenta']); ?> - ****<?php echo substr($c['numero_cuenta'], -4); ?>
                                    </span>
                                </td>
                                <td class="td-monto"><?php echo number_format($c['monto'], 2, ',', '.'); ?></td>
                                <td class="text-left"><?php echo htmlspecialchars($c['descripcion']); ?></td>
                                <td>
                                    <a href="?editar=<?php echo $c['id']; ?>" class="btn-accion editar" title="Editar">&#9998;</a>
                                    <form method="POST" class="form-inline" onsubmit="return confirm('¿Eliminar esta cobranza?');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="cobranza_id" value="<?php echo $c['id']; ?>">
                                        <button type="submit" class="btn-accion eliminar" title="Eliminar">&#128465;</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (count($cobranzas) === 0): ?>
                            <tr>
                                <td colspan="7" class="tabla-vacia">Sin cobranzas registradas.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</body>
</html>