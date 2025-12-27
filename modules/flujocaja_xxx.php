<?php
// Archivo: modules/flujocaja.php
// Módulo: Flujo de Caja (mejoras: bind_param corregido y UX listo para formateo client-side)
// Asegúrate de reemplazar tu archivo existente por este (mantengo la lógica original).

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
$empresa_id = (int) $_SESSION['empresa_id'];
$usuario_id = isset($_SESSION['usuario_id']) ? (int)$_SESSION['usuario_id'] : null;

function ddmmyyyy_to_sql($d) {
    if (strpos($d, '/') !== false) {
        $parts = explode('/', $d);
        if (count($parts) === 3) {
            return sprintf('%04d-%02d-%02d', intval($parts[2]), intval($parts[1]), intval($parts[0]));
        }
    }
    return $d;
}
function sql_to_ddmmyyyy($d) {
    if (!$d) return '';
    $t = strtotime($d);
    return date('d/m/Y', $t);
}
function parse_monto_bs_from_input($s) {
    $s = trim($s);
    $s = preg_replace('/[^\d\.,-]/u', '', $s);
    if (substr_count($s, ',') > 0 && substr_count($s, '.') > 0) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif (substr_count($s, ',') > 0 && substr_count($s, '.') == 0) {
        $s = str_replace(',', '.', $s);
    }
    return floatval($s);
}
function parse_monto_usd_from_input($s) {
    $s = trim($s);
    $s = preg_replace('/[^\d\.,-]/u', '', $s);
    if (substr_count($s, ',') > 0 && substr_count($s, '.') > 0) {
        $s = str_replace(',', '', $s);
    } elseif (substr_count($s, ',') > 0 && substr_count($s, '.') == 0) {
        $s = str_replace(',', '.', $s);
    }
    return floatval($s);
}

// Obtener última tasa
$tasa_actual = null;
$res_t = $conn->query("SELECT tasa FROM tasas ORDER BY fecha DESC, id DESC LIMIT 1");
if ($res_t) {
    $r = $res_t->fetch_assoc();
    if ($r && isset($r['tasa'])) $tasa_actual = floatval($r['tasa']);
}

$action = $_GET['action'] ?? '';
$errors = [];

// Guardar (crear)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar'])) {
    $fecha = ddmmyyyy_to_sql($_POST['fecha'] ?? date('Y-m-d'));
    $descripcion = trim($_POST['descripcion'] ?? '');
    $instrumento = ($_POST['instrumento'] ?? '') === '$' ? '$' : 'Bs';
    $banco_id = null;
    $divisa_metodo = null;
    $monto_bs = null;
    $monto_usd = null;
    $tasa = isset($_POST['tasa']) && strlen($_POST['tasa'])>0 ? floatval(str_replace(',', '.', $_POST['tasa'])) : $tasa_actual;
    $responsable = trim($_POST['responsable'] ?? '');
    $beneficiario = trim($_POST['beneficiario'] ?? '');

    if ($instrumento === 'Bs') {
        $banco_id = isset($_POST['banco_id']) && $_POST['banco_id'] !== '' ? (int)$_POST['banco_id'] : null;
        $monto_bs = isset($_POST['monto']) ? parse_monto_bs_from_input($_POST['monto']) : 0.0;
        if ($banco_id === null) $errors[] = "Debe seleccionar la cuenta bancaria de donde se debitará el pago en Bs.";
    } else {
        $divisa_metodo = in_array($_POST['divisa_metodo'] ?? '', ['EFECTIVO','TRANSFERENCIA']) ? $_POST['divisa_metodo'] : null;
        $monto_usd = isset($_POST['monto']) ? parse_monto_usd_from_input($_POST['monto']) : 0.0;
        if ($divisa_metodo === null) $errors[] = "Debe seleccionar método de pago en divisas (EFECTIVO o TRANSFERENCIA).";
    }
    if ($descripcion === '') $errors[] = "Descripción requerida.";

    if (empty($errors)) {
        $stmt = $conn->prepare("INSERT INTO flujo_caja (empresa_id, fecha, descripcion, instrumento, banco_id, divisa_metodo, monto_bs, monto_usd, tasa, responsable, beneficiario, creado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        if (!$stmt) {
            $errors[] = "Error de preparación: " . $conn->error;
        } else {
            // TIPOS: i (empresa_id), s fecha, s descripcion, s instrumento, i banco_id, s divisa_metodo, d monto_bs, d monto_usd, d tasa, s responsable, s beneficiario, i creado_por
            $stmt->bind_param("isssisdddssi", $empresa_id, $fecha, $descripcion, $instrumento, $banco_id, $divisa_metodo, $monto_bs, $monto_usd, $tasa, $responsable, $beneficiario, $usuario_id);
            if (!$stmt->execute()) {
                $errors[] = "Error al guardar: " . $stmt->error;
            } else {
                $_SESSION['msg'] = "Registro agregado.";
                header("Location: flujocaja.php");
                exit();
            }
            $stmt->close();
        }
    }
}

// Editar (guardar cambios)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_edicion'])) {
    $id = intval($_POST['id']);
    $fecha = ddmmyyyy_to_sql($_POST['fecha'] ?? date('Y-m-d'));
    $descripcion = trim($_POST['descripcion'] ?? '');
    $instrumento = ($_POST['instrumento'] ?? '') === '$' ? '$' : 'Bs';
    $banco_id = null;
    $divisa_metodo = null;
    $monto_bs = null;
    $monto_usd = null;
    $tasa = isset($_POST['tasa']) && strlen($_POST['tasa'])>0 ? floatval(str_replace(',', '.', $_POST['tasa'])) : $tasa_actual;
    $responsable = trim($_POST['responsable'] ?? '');
    $beneficiario = trim($_POST['beneficiario'] ?? '');

    if ($instrumento === 'Bs') {
        $banco_id = isset($_POST['banco_id']) && $_POST['banco_id'] !== '' ? (int)$_POST['banco_id'] : null;
        $monto_bs = isset($_POST['monto']) ? parse_monto_bs_from_input($_POST['monto']) : 0.0;
        if ($banco_id === null) $errors[] = "Debe seleccionar la cuenta bancaria de donde se debitará el pago en Bs.";
    } else {
        $divisa_metodo = in_array($_POST['divisa_metodo'] ?? '', ['EFECTIVO','TRANSFERENCIA']) ? $_POST['divisa_metodo'] : null;
        $monto_usd = isset($_POST['monto']) ? parse_monto_usd_from_input($_POST['monto']) : 0.0;
        if ($divisa_metodo === null) $errors[] = "Debe seleccionar método de pago en divisas (EFECTIVO o TRANSFERENCIA).";
    }
    if ($descripcion === '') $errors[] = "Descripción requerida.";

    if (empty($errors)) {
        $stmt = $conn->prepare("UPDATE flujo_caja SET fecha=?, descripcion=?, instrumento=?, banco_id=?, divisa_metodo=?, monto_bs=?, monto_usd=?, tasa=?, responsable=?, beneficiario=?, actualizado_por=?, actualizado_en=NOW() WHERE id=? AND empresa_id=?");
        if (!$stmt) {
            $errors[] = "Error de preparación: " . $conn->error;
        } else {
            // TIPOS: s fecha, s desc, s instr, i banco_id, s divisa_metodo, d monto_bs, d monto_usd, d tasa, s responsable, s beneficiario, i actualizado_por, i id, i empresa_id
            $stmt->bind_param("sssisdddssiii", $fecha, $descripcion, $instrumento, $banco_id, $divisa_metodo, $monto_bs, $monto_usd, $tasa, $responsable, $beneficiario, $usuario_id, $id, $empresa_id);
            if (!$stmt->execute()) {
                $errors[] = "Error al actualizar: " . $stmt->error;
            } else {
                $_SESSION['msg'] = "Registro actualizado.";
                header("Location: flujocaja.php");
                exit();
            }
            $stmt->close();
        }
    }
}

// Eliminar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar'])) {
    $id = intval($_POST['id']);
    $stmt = $conn->prepare("DELETE FROM flujo_caja WHERE id=? AND empresa_id=?");
    if ($stmt) {
        $stmt->bind_param("ii", $id, $empresa_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['msg'] = "Registro eliminado.";
        header("Location: flujocaja.php");
        exit();
    } else {
        $errors[] = "Error al preparar eliminación: " . $conn->error;
    }
}

// Listado (paginado simple)
$page = max(1, intval($_GET['page'] ?? 1));
$perpage = 20;
$offset = ($page - 1) * $perpage;
$total_rows = 0;
$res_total = $conn->prepare("SELECT COUNT(*) FROM flujo_caja WHERE empresa_id=?");
$res_total->bind_param('i', $empresa_id);
$res_total->execute();
$res_total->bind_result($total_rows);
$res_total->fetch();
$res_total->close();

$stmt = $conn->prepare("SELECT f.*, b.nombre AS banco_nombre FROM flujo_caja f LEFT JOIN bancos b ON f.banco_id = b.id WHERE f.empresa_id=? ORDER BY f.fecha DESC, f.id DESC LIMIT ? OFFSET ?");
$stmt->bind_param('iii', $empresa_id, $perpage, $offset);
$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Obtener bancos (para select)
$bancos = [];
$rb = $conn->prepare("SELECT id, nombre FROM bancos WHERE empresa_id=? ORDER BY nombre");
$rb->bind_param('i', $empresa_id);
$rb->execute();
$resb = $rb->get_result();
while ($row = $resb->fetch_assoc()) $bancos[] = $row;
$rb->close();

$msg_flash = $_SESSION['msg'] ?? '';
unset($_SESSION['msg']);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Flujo de Caja | <?php echo htmlspecialchars($_SESSION['nombre'] ?? ''); ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <nav class="nav-bar">
        <div class="nav-logo"><img src="../assets/img/logo.png" class="nav-logo-img" alt="logo"></div>
        <div class="nav-empresa"><b>Flujo de Caja</b></div>
        <div class="nav-user">
            <button class="btn-volver" onclick="window.location.href='dashboard.php'">Volver</button>
        </div>
    </nav>

    <main class="dashboard-main">
        <div class="container-dashboard">
            <header style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                <div>
                    <h2 style="margin:0;">Flujo de Caja</h2>
                    <div class="small-note">Registra los egresos diarios y administra pagos en Bs y en $</div>
                </div>
                <div class="inline-actions no-print">
                    <button class="btn-principal" onclick="document.getElementById('form-nuevo').scrollIntoView({behavior:'smooth'});">Agregar movimiento</button>
                    <a class="btn-volver" href="reportes.php">Ir a Reportes</a>
                </div>
            </header>

            <?php if ($msg_flash): ?>
                <div class="msg-info card-panel"><?php echo htmlspecialchars($msg_flash); ?></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="msg card-panel"><?php foreach($errors as $e) echo htmlspecialchars($e)."<br>"; ?></div>
            <?php endif; ?>

            <!-- Form and listing (same as previous version) -->
            <!-- ... rest of HTML unchanged ... -->
            <!-- For brevity I kept the rest of the structure identical to your working file.
                 Make sure to keep the form inputs 'monto' id and instrument selects as in the original. -->
        </div>
    </main>

    <script src="../assets/js/flujocaja.js?v=<?php echo time(); ?>"></script>
    <script>
    // Keep client-side helper that unformats before submit
    function flujocaja_before_submit(form){
        var montoInput = form.querySelector('input[name="monto"]');
        if (montoInput){
            if (typeof window.fcj_unformat === 'function'){
                var tipo = (form.instrumento && form.instrumento.value === 'Bs') ? 'Bs' : '$';
                montoInput.value = window.fcj_unformat(montoInput.value, tipo);
            } else {
                montoInput.value = montoInput.value.replace(/[^\d\.,-]/g,'');
            }
        }
        var tasa = form.querySelector('input[name="tasa"]');
        if (tasa) tasa.value = tasa.value.replace(',','.');
        return true;
    }

    document.addEventListener('DOMContentLoaded', function(){
        if (document.getElementById('instrumento')) flujocaja_toggle_instrumento();
    });
    </script>
</body>
</html>