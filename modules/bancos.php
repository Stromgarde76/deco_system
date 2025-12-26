<?php
// Archivo: bancos.php
// Ubicación: C:\xampp\htdocs\deco_system\modules\bancos.php

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

// --- AGREGAR BANCO ---
if (isset($_POST['accion']) && $_POST['accion'] === 'agregar') {
    $nombre = trim($_POST['nombre']);
    $tipo_cuenta = trim($_POST['tipo_cuenta']);
    $numero_cuenta = preg_replace('/[^0-9]/', '', $_POST['numero_cuenta']);
    $titular = trim($_POST['titular']);
    $saldo = floatval(str_replace(',', '.', $_POST['saldo']));

    if ($tipo_cuenta !== 'ahorros' && $tipo_cuenta !== 'corriente') {
        $msg = "El tipo de cuenta debe ser 'ahorros' o 'corriente'.";
    } else {
        $sql = "INSERT INTO bancos (empresa_id, nombre, tipo_cuenta, numero_cuenta, titular, saldo) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('issssd', $empresa_id, $nombre, $tipo_cuenta, $numero_cuenta, $titular, $saldo);
        if ($stmt->execute()) {
            $msg = "Banco agregado exitosamente.";
        } else {
            $msg = "Error al agregar banco.";
        }
        $stmt->close();
    }
}

// --- EDITAR BANCO ---
if (isset($_POST['accion']) && $_POST['accion'] === 'editar' && isset($_POST['banco_id'])) {
    $banco_id = intval($_POST['banco_id']);
    $nombre = trim($_POST['nombre']);
    $tipo_cuenta = trim($_POST['tipo_cuenta']);
    $numero_cuenta = preg_replace('/[^0-9]/', '', $_POST['numero_cuenta']);
    $titular = trim($_POST['titular']);
    $saldo = floatval(str_replace(',', '.', $_POST['saldo']));

    if ($tipo_cuenta !== 'ahorros' && $tipo_cuenta !== 'corriente') {
        $msg = "El tipo de cuenta debe ser 'ahorros' o 'corriente'.";
    } else {
        $sql = "UPDATE bancos SET nombre=?, tipo_cuenta=?, numero_cuenta=?, titular=?, saldo=? WHERE id=? AND empresa_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssssdii', $nombre, $tipo_cuenta, $numero_cuenta, $titular, $saldo, $banco_id, $empresa_id);
        if ($stmt->execute()) {
            $msg = "Banco actualizado.";
        } else {
            $msg = "Error al actualizar banco.";
        }
        $stmt->close();
    }
}

// --- ELIMINAR BANCO ---
if (isset($_POST['accion']) && $_POST['accion'] === 'eliminar' && isset($_POST['banco_id'])) {
    $banco_id = intval($_POST['banco_id']);
    $sql = "DELETE FROM bancos WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $banco_id, $empresa_id);
    if ($stmt->execute()) {
        $msg = "Banco eliminado.";
    } else {
        $msg = "Error al eliminar banco.";
    }
    $stmt->close();
}

// --- OBTENER DATOS DE UN BANCO PARA EDITAR ---
$banco_editar = null;
if (isset($_GET['editar'])) {
    $banco_id = intval($_GET['editar']);
    $sql = "SELECT * FROM bancos WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $banco_id, $empresa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $banco_editar = $result->fetch_assoc();
    $stmt->close();
}

// --- LISTAR BANCOS DE LA EMPRESA ACTUAL ---
$bancos = [];
$sql = "SELECT * FROM bancos WHERE empresa_id=? ORDER BY nombre ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $empresa_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $bancos[] = $row;
}
$stmt->close();

// --- OBTENER TASA DEL DÍA ---
$tasa_usd = 0;
$fecha_hoy = date('Y-m-d');
$res_tasa = $conn->query("SELECT tasa FROM tasas WHERE fecha='$fecha_hoy' LIMIT 1");
$row_tasa = $res_tasa ? $res_tasa->fetch_assoc() : null;
if ($row_tasa && isset($row_tasa['tasa'])) {
    $tasa_usd = floatval($row_tasa['tasa']);
}

// Función para formatear número de cuenta
function formatearCuenta($cuenta) {
    $cuenta = preg_replace('/[^0-9]/', '', $cuenta);
    if (strlen($cuenta) === 20) {
        return substr($cuenta,0,4).'-'.substr($cuenta,4,4).'-'.substr($cuenta,8,2).'-'.substr($cuenta,10,10);
    }
    return $cuenta;
}

// Para el default del select tipo_cuenta
function selected_tipo_cuenta($valor, $banco_editar) {
    if (isset($banco_editar['tipo_cuenta'])) {
        return $banco_editar['tipo_cuenta'] === $valor ? 'selected' : '';
    }
    if ($valor === 'corriente') {
        return 'selected';
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Bancos</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <script>
    function formatNumeroCuenta(input) {
        let value = input.value.replace(/\D/g,'').slice(0,20);
        let result = '';
        if(value.length > 0) result += value.substring(0,4);
        if(value.length > 4) result += '-' + value.substring(4,8);
        if(value.length > 8) result += '-' + value.substring(8,10);
        if(value.length > 10) result += '-' + value.substring(10,20);
        input.value = result;
    }
    </script>
</head>
<body>
    <nav class="nav-bar">
        <div class="nav-logo">
            <img src="../assets/img/logo.png" alt="Logo" class="nav-logo-img">
        </div>
        <div class="nav-empresa"><b>Bancos</b> | <span style="color:#FF7F36;"><?php echo htmlspecialchars($_SESSION['nombre']); ?></span></div>
        <div class="nav-user">
            <button type="button" class="btn-volver" onclick="window.location.href='dashboard.php'">Volver</button>
            <a href="../logout.php" class="nav-logout" title="Cerrar sesión">&#x1F511;</a>
        </div>
    </nav>
    <main class="seccion-bancos">
        <section class="bancos-form">
            <h2><?php echo $banco_editar ? "Editar Banco" : "Agregar Banco"; ?></h2>
            <?php if ($msg): ?>
                <div class="msg"><?php echo $msg; ?></div>
            <?php endif; ?>
            <form method="POST" class="formulario" autocomplete="off">
                <input type="hidden" name="accion" value="<?php echo $banco_editar ? 'editar' : 'agregar'; ?>">
                <?php if ($banco_editar): ?>
                    <input type="hidden" name="banco_id" value="<?php echo $banco_editar['id']; ?>">
                <?php endif; ?>
                <input type="text" name="nombre" value="<?php echo $banco_editar['nombre'] ?? ''; ?>" placeholder="Nombre del banco" required>
                <select name="tipo_cuenta" required>
                    <option value="">Seleccione tipo de cuenta</option>
                    <option value="ahorros" <?php echo selected_tipo_cuenta('ahorros', $banco_editar); ?>>Ahorros</option>
                    <option value="corriente" <?php echo selected_tipo_cuenta('corriente', $banco_editar); ?>>Corriente</option>
                </select>
                <input type="text" name="numero_cuenta" maxlength="26"
                    value="<?php
                        echo isset($banco_editar['numero_cuenta']) ? formatearCuenta($banco_editar['numero_cuenta']) : '';
                    ?>"
                    placeholder="N° cuenta (20 dígitos)" required
                    oninput="formatNumeroCuenta(this)">
                <input type="text" name="titular" value="<?php echo $banco_editar['titular'] ?? ''; ?>" placeholder="Titular">
                <input type="number" name="saldo" step="0.01" min="0"
                    value="<?php echo isset($banco_editar['saldo']) ? $banco_editar['saldo'] : ''; ?>"
                    placeholder="Saldo inicial Bs.">
                <div class="form-btns">
                    <button type="submit" class="btn-principal"><?php echo $banco_editar ? "Actualizar" : "Agregar"; ?></button>
                    <?php if ($banco_editar): ?>
                        <a href="bancos.php" class="btn-cancelar">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>
        <section class="bancos-lista">
            <h2>Listado de Bancos</h2>
            <div class="tabla-scroll">
            <table class="tabla-bancos">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Tipo</th>
                        <th>N° cuenta</th>
                        <th>Titular</th>
                        <th>Saldo (Bs.)</th>
                        <th class="col-acciones">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bancos as $b): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($b['nombre']); ?></td>
                        <td><?php echo htmlspecialchars($b['tipo_cuenta']); ?></td>
                        <td><?php echo formatearCuenta($b['numero_cuenta']); ?></td>
                        <td><?php echo htmlspecialchars($b['titular']); ?></td>
                        <td class="td-saldo" style="text-align:right;font-weight:bold;">
                            <?php echo number_format($b['saldo'],2,',','.'); ?> Bs.
                            <?php if ($tasa_usd > 0): ?>
                                <br>
                                <small style="font-weight:normal;color:#37659a;">
                                    <?php echo number_format($b['saldo']/$tasa_usd, 2, ',', '.'); ?> USD
                                </small>
                            <?php else: ?>
                                <br>
                                <small style="font-weight:normal;color:#aaa;">
                                    Tasa no disponible
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="?editar=<?php echo $b['id']; ?>" class="btn-accion editar" title="Editar">&#9998;</a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este banco?');">
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="banco_id" value="<?php echo $b['id']; ?>">
                                <button type="submit" class="btn-accion eliminar" title="Eliminar">&#128465;</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($bancos) === 0): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; color:#aaa;">Sin bancos registrados.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </section>
    </main>
</body>
</html>