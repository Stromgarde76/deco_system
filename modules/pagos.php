<?php
// Archivo: modules/pagos.php
// Módulo para emitir y listar pagos a contratistas o servicios de la empresa actual

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

// --- OBTENER CONTRATISTAS ---
$contratistas = [];
$sql = "SELECT id, nombre FROM contratistas WHERE empresa_id=? ORDER BY nombre ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $empresa_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $contratistas[] = $row;
$stmt->close();

// --- OBTENER BANCOS (CUENTAS DE LA EMPRESA) ---
$bancos = [];
$sql = "SELECT id, nombre, tipo_cuenta, numero_cuenta FROM bancos WHERE empresa_id=? ORDER BY nombre ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $empresa_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $bancos[] = $row;
$stmt->close();

// --- AGREGAR PAGO ---
if (isset($_POST['accion']) && $_POST['accion'] === 'agregar') {
    $destino_pago = $_POST['destino_pago'] ?? 'contratista';
    $contratista_id = ($destino_pago === 'contratista') ? intval($_POST['contratista_id']) : null;
    // Para servicios, en el futuro usar $_POST['servicio_id']
    $tipo_pago = $_POST['tipo_pago'] ?? 'pago total';
    $fecha = trim($_POST['fecha']) ?: date('Y-m-d');
    $monto = floatval(str_replace(['.', ','], ['', '.'], str_replace('.', '', $_POST['monto'])));
    $descripcion = trim($_POST['descripcion']);
    $cuenta_id = intval($_POST['cuenta']);

    $sql = "INSERT INTO pagos (empresa_id, destino_pago, contratista_id, tipo_pago, fecha, monto, descripcion, cuenta) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { die("Error en prepare: " . $conn->error); }
    $stmt->bind_param('isssssdi', $empresa_id, $destino_pago, $contratista_id, $tipo_pago, $fecha, $monto, $descripcion, $cuenta_id);
    if ($stmt->execute()) {
        // Restar el monto a la cuenta bancaria seleccionada
        $sql_upd = "UPDATE bancos SET saldo = saldo - ? WHERE id=? AND empresa_id=?";
        $stmt2 = $conn->prepare($sql_upd);
        $stmt2->bind_param('dii', $monto, $cuenta_id, $empresa_id);
        $stmt2->execute();
        $stmt2->close();

        $msg = "Pago registrado exitosamente y saldo descontado en la cuenta seleccionada.";
    } else {
        $msg = "Error al registrar pago.";
    }
    $stmt->close();
}

// --- EDITAR PAGO ---
if (isset($_POST['accion']) && $_POST['accion'] === 'editar' && isset($_POST['pago_id'])) {
    $pago_id = intval($_POST['pago_id']);
    $destino_pago = $_POST['destino_pago'] ?? 'contratista';
    $contratista_id = ($destino_pago === 'contratista') ? intval($_POST['contratista_id']) : null;
    $tipo_pago = $_POST['tipo_pago'] ?? 'pago total';
    $fecha = trim($_POST['fecha']) ?: date('Y-m-d');
    $monto = floatval(str_replace(['.', ','], ['', '.'], str_replace('.', '', $_POST['monto'])));
    $descripcion = trim($_POST['descripcion']);
    $cuenta_id = intval($_POST['cuenta']);

    // Buscar datos antiguos
    $sql_old = "SELECT monto, cuenta FROM pagos WHERE id=? AND empresa_id=?";
    $stmt_old = $conn->prepare($sql_old);
    $stmt_old->bind_param('ii', $pago_id, $empresa_id);
    $stmt_old->execute();
    $rs_old = $stmt_old->get_result();
    $old = $rs_old->fetch_assoc();
    $stmt_old->close();

    // Actualizar pago
    $sql = "UPDATE pagos SET destino_pago=?, contratista_id=?, tipo_pago=?, fecha=?, monto=?, descripcion=?, cuenta=? WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { die("Error en prepare: " . $conn->error); }
    $stmt->bind_param('sissdsiii', $destino_pago, $contratista_id, $tipo_pago, $fecha, $monto, $descripcion, $cuenta_id, $pago_id, $empresa_id);
    if ($stmt->execute()) {
        // Si cambió la cuenta o el monto, ajustar saldos
        if ($old) {
            if ($old['cuenta'] != $cuenta_id) {
                // Devuelve monto a la cuenta anterior, descuenta de la nueva
                $sql1 = "UPDATE bancos SET saldo = saldo + ? WHERE id=? AND empresa_id=?";
                $stmt1 = $conn->prepare($sql1);
                $stmt1->bind_param('dii', $old['monto'], $old['cuenta'], $empresa_id);
                $stmt1->execute();
                $stmt1->close();

                $sql2 = "UPDATE bancos SET saldo = saldo - ? WHERE id=? AND empresa_id=?";
                $stmt2 = $conn->prepare($sql2);
                $stmt2->bind_param('dii', $monto, $cuenta_id, $empresa_id);
                $stmt2->execute();
                $stmt2->close();
            } else if ($old['monto'] != $monto) {
                $delta = $old['monto'] - $monto;
                $sql2 = "UPDATE bancos SET saldo = saldo + ? WHERE id=? AND empresa_id=?";
                $stmt2 = $conn->prepare($sql2);
                $stmt2->bind_param('dii', $delta, $cuenta_id, $empresa_id);
                $stmt2->execute();
                $stmt2->close();
            }
        }
        $msg = "Pago actualizado y saldo ajustado.";
    } else {
        $msg = "Error al actualizar pago.";
    }
    $stmt->close();
}

// --- ELIMINAR PAGO ---
if (isset($_POST['accion']) && $_POST['accion'] === 'eliminar' && isset($_POST['pago_id'])) {
    $pago_id = intval($_POST['pago_id']);
    // Buscar datos del pago para devolver el monto a la cuenta
    $sql_old = "SELECT monto, cuenta FROM pagos WHERE id=? AND empresa_id=?";
    $stmt_old = $conn->prepare($sql_old);
    $stmt_old->bind_param('ii', $pago_id, $empresa_id);
    $stmt_old->execute();
    $rs_old = $stmt_old->get_result();
    $old = $rs_old->fetch_assoc();
    $stmt_old->close();

    $sql = "DELETE FROM pagos WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $pago_id, $empresa_id);
    if ($stmt->execute()) {
        // Devolver el monto a la cuenta
        if ($old) {
            $sql1 = "UPDATE bancos SET saldo = saldo + ? WHERE id=? AND empresa_id=?";
            $stmt1 = $conn->prepare($sql1);
            $stmt1->bind_param('dii', $old['monto'], $old['cuenta'], $empresa_id);
            $stmt1->execute();
            $stmt1->close();
        }
        $msg = "Pago eliminado y monto devuelto a la cuenta.";
    } else {
        $msg = "Error al eliminar pago.";
    }
    $stmt->close();
}

// --- OBTENER DATOS DE UN PAGO PARA EDITAR ---
$pago_editar = null;
if (isset($_GET['editar'])) {
    $pago_id = intval($_GET['editar']);
    $sql = "SELECT * FROM pagos WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $pago_id, $empresa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $pago_editar = $result->fetch_assoc();
    $stmt->close();
}

// --- LISTAR PAGOS ---
$pagos = [];
$sql = "SELECT p.*, c.nombre AS contratista_nombre, b.nombre AS banco_nombre, b.tipo_cuenta, b.numero_cuenta
        FROM pagos p
        LEFT JOIN contratistas c ON p.contratista_id = c.id
        LEFT JOIN bancos b ON p.cuenta = b.id
        WHERE p.empresa_id=?
        ORDER BY p.fecha DESC, p.id DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $empresa_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $pagos[] = $row;
$stmt->close();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pagos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script>
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
        function toggleContratista() {
            var destino = document.getElementById('destino_pago');
            var contratistaBox = document.getElementById('contratista_box');
            if (destino && contratistaBox) {
                if (destino.value === 'contratista') {
                    contratistaBox.style.display = '';
                } else {
                    contratistaBox.style.display = 'none';
                }
            }
        }
        var destinoSel = document.getElementById('destino_pago');
        if (destinoSel) {
            destinoSel.addEventListener('change', toggleContratista);
            toggleContratista();
        }
    });
    </script>
</head>
<body>
    <nav class="nav-bar">
        <div class="nav-logo">
            <img src="../assets/img/logo.png" alt="Logo" class="nav-logo-img">
        </div>
        <div class="nav-empresa"><b>Pagos</b> | <span style="color:#FF7F36;"><?php echo htmlspecialchars($_SESSION['nombre']); ?></span></div>
        <div class="nav-user">
            <button type="button" class="btn-volver" onclick="window.location.href='dashboard.php'">Volver</button>
            <a href="../logout.php" class="nav-logout" title="Cerrar sesión">&#x1F511;</a>
        </div>
    </nav>
    <main class="seccion-pagos">
        <div class="pagos-flex-container">
            <section class="pagos-form">
                <h2><?php echo $pago_editar ? "Editar Pago" : "Emitir Pago"; ?></h2>
                <?php if ($msg): ?>
                    <div class="msg"><?php echo $msg; ?></div>
                <?php endif; ?>
                <form method="POST" class="formulario form-pagos" autocomplete="off">
                    <input type="hidden" name="accion" value="<?php echo $pago_editar ? 'editar' : 'agregar'; ?>">
                    <?php if ($pago_editar): ?>
                        <input type="hidden" name="pago_id" value="<?php echo $pago_editar['id']; ?>">
                    <?php endif; ?>
                    <select name="destino_pago" id="destino_pago" required>
                        <option value="contratista" <?php if(($pago_editar['destino_pago'] ?? 'contratista') === 'contratista') echo 'selected'; ?>>Contratista</option>
                        <option value="servicio" <?php if(($pago_editar['destino_pago'] ?? '') === 'servicio') echo 'selected'; ?>>Servicios</option>
                    </select>
                    <div id="contratista_box">
                        <select name="contratista_id">
                            <option value="">Seleccione Contratista</option>
                            <?php foreach ($contratistas as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php if (($pago_editar['contratista_id'] ?? '') == $c['id']) echo "selected"; ?>>
                                    <?php echo htmlspecialchars($c['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <select name="tipo_pago" required>
                        <option value="">Tipo de Pago</option>
                        <option value="adelanto" <?php if(($pago_editar['tipo_pago'] ?? '') === 'adelanto') echo 'selected'; ?>>Adelanto</option>
                        <option value="pago total" <?php if(($pago_editar['tipo_pago'] ?? '') === 'pago total') echo 'selected'; ?>>Pago Total</option>
                        <option value="ajuste" <?php if(($pago_editar['tipo_pago'] ?? '') === 'ajuste') echo 'selected'; ?>>Ajuste</option>
                    </select>
                    <input type="date" name="fecha"
                        value="<?php echo $pago_editar['fecha'] ?? date('Y-m-d'); ?>"
                        placeholder="Fecha">
                    <select name="cuenta" required>
                        <option value="">Cuenta de la Empresa</option>
                        <?php foreach ($bancos as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php if (($pago_editar['cuenta'] ?? '') == $b['id']) echo "selected"; ?>>
                                <?php echo htmlspecialchars($b['nombre'] . " (" . $b['tipo_cuenta'] . ") - " . substr($b['numero_cuenta'], -4)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="monto" name="monto" value="<?php echo isset($pago_editar['monto']) ? number_format($pago_editar['monto'], 2, ',', '.') : ''; ?>" required placeholder="Monto (Bs. o Divisas)">
                    <textarea name="descripcion" placeholder="Concepto o Descripción"><?php echo $pago_editar['descripcion'] ?? ''; ?></textarea>
                    <div class="form-btns">
                        <button type="submit" class="btn-principal"><?php echo $pago_editar ? "Actualizar" : "Emitir"; ?></button>
                        <?php if ($pago_editar): ?>
                            <a href="pagos.php" class="btn-cancelar">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>
            <section class="pagos-lista">
                <h2>Listado de Pagos</h2>
                <div class="tabla-scroll">
                    <table class="tabla-pagos">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Destino</th>
                                <th>Contratista</th>
                                <th>Tipo de Pago</th>
                                <th>Cuenta</th>
                                <th>Monto</th>
                                <th>Concepto</th>
                                <th class="col-acciones">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pagos as $p): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($p['fecha']); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($p['destino_pago'])); ?></td>
                                <td><?php echo htmlspecialchars($p['contratista_nombre']); ?></td>
                                <td><?php echo htmlspecialchars($p['tipo_pago']); ?></td>
                                <td class="td-banco">
                                    <?php echo htmlspecialchars($p['banco_nombre']); ?>
                                    <br>
                                    <span style="color:#666;font-size:11px">
                                        <?php echo htmlspecialchars($p['tipo_cuenta']); ?> - ****<?php echo substr($p['numero_cuenta'], -4); ?>
                                    </span>
                                </td>
                                <td class="td-monto"><?php echo number_format($p['monto'], 2, ',', '.'); ?></td>
                                <td style="text-align:left;"><?php echo htmlspecialchars($p['descripcion']); ?></td>
                                <td>
                                    <a href="?editar=<?php echo $p['id']; ?>" class="btn-accion editar" title="Editar">&#9998;</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este pago?');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="pago_id" value="<?php echo $p['id']; ?>">
                                        <button type="submit" class="btn-accion eliminar" title="Eliminar">&#128465;</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (count($pagos) === 0): ?>
                            <tr>
                                <td colspan="8" style="text-align:center; color:#aaa;">Sin pagos registrados.</td>
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