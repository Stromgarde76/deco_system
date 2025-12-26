<?php
// Archivo: modules/flujocaja.php
// Módulo: Flujo de Caja (presentación mejorada)
// Nota: conservé la lógica PHP/CRUD que ya tienes y únicamente ajusté la salida HTML/CSS/JS
// para que use las clases y estructuras visuales del resto de la app y quede más coherente.

session_start();
require_once "../config/db.php";

// Verificaciones de sesión / empresa
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

// Helpers de formato y de entrada
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

// Obtener última tasa si es necesario
$tasa_actual = null;
$res_t = $conn->query("SELECT tasa FROM tasas ORDER BY fecha DESC, id DESC LIMIT 1");
if ($res_t) {
    $r = $res_t->fetch_assoc();
    if ($r && isset($r['tasa'])) $tasa_actual = floatval($r['tasa']);
}

// Acciones CRUD (sin cambios respecto a la versión funcional que ya tienes)
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
            $stmt->bind_param("isssissddsss", $empresa_id, $fecha, $descripcion, $instrumento, $banco_id, $divisa_metodo, $monto_bs, $monto_usd, $tasa, $responsable, $beneficiario, $usuario_id);
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
            // Tipos en bind_param deben coincidir con las variables; 's' para strings, 'i' para ints, 'd' para decimal (double)
            $stmt->bind_param("sssisddsdssiii", $fecha, $descripcion, $instrumento, $banco_id, $divisa_metodo, $monto_bs, $monto_usd, $tasa, $responsable, $beneficiario, $usuario_id, $id, $empresa_id);
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
<link rel="stylesheet" href="../assets/css/style.css?v=<?php echo time(); ?>">
<style>
/* Ajustes visuales específicos para armonizar con el dashboard */
.container-dashboard { max-width:1100px; margin:0 auto; padding: 1rem; }
.card-panel { background:var(--color-panel); border-radius:12px; padding:1rem; box-shadow:0 6px 20px var(--color-shadow); margin-bottom:1rem; }
.form-row { display:flex; gap:0.75rem; flex-wrap:wrap; align-items:center; }
.form-row label { flex:1 1 220px; min-width:180px; display:flex; flex-direction:column; gap:0.35rem; font-weight:600; color:var(--color-titulo); }
.form-row input[type="text"], .form-row input[type="number"], .form-row select { padding:8px 10px; border-radius:8px; border:none; background:var(--color-input-bg); box-shadow:0 1px 4px var(--color-shadow); }
.currency-badge { display:inline-block; min-width:44px; font-weight:bold; text-align:center; padding:6px 8px; border-radius:8px; background:#f0f4fb; color:var(--color-principal); margin-right:6px; }
.table-actions button { margin-right:6px; }
.table-actions form { display:inline-block; margin:0; }
.reporte-tabla th, .reporte-tabla td { vertical-align: middle; }
.small-note { font-size:0.88rem; color:#666; margin-top:6px; }
.inline-actions { display:flex; gap:8px; align-items:center; }
@media (max-width:720px) {
    .form-row label { min-width:100%; }
}
</style>
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

            <!-- Formulario Agregar -->
            <section id="form-nuevo" class="card-panel" aria-label="Agregar movimiento">
                <h3 style="margin-top:0;">Agregar movimiento</h3>
                <form method="POST" onsubmit="return flujocaja_before_submit(this);" class="formulario">
                    <div class="form-row">
                        <label>Fecha (DD/MM/AAAA):
                            <input name="fecha" type="text" value="<?php echo date('d/m/Y'); ?>" required placeholder="DD/MM/AAAA" />
                        </label>
                        <label>Instrumento:
                            <select name="instrumento" id="instrumento" onchange="flujocaja_toggle_instrumento();" required>
                                <option value="Bs">Bolívares (Bs)</option>
                                <option value="$">Dólares ($)</option>
                            </select>
                        </label>
                    </div>

                    <div class="form-row" style="margin-top:0.6rem;">
                        <label>Cuenta banco (si Bs):
                            <select name="banco_id" id="banco_id">
                                <option value="">-- Seleccione cuenta --</option>
                                <?php foreach($bancos as $b): ?>
                                    <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Divisas método (si $):
                            <select name="divisa_metodo" id="divisa_metodo" disabled>
                                <option value="">--</option>
                                <option value="EFECTIVO">EFECTIVO</option>
                                <option value="TRANSFERENCIA">TRANSFERENCIA</option>
                            </select>
                        </label>
                    </div>

                    <div class="form-row" style="margin-top:0.6rem;">
                        <label>Descripción:
                            <input type="text" name="descripcion" placeholder="Ej: Pago de bono de transporte a Pedro Pérez" required />
                        </label>
                        <label>Monto:
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div id="currency_label" class="currency-badge">Bs</div>
                                <input type="text" name="monto" id="monto_input" required placeholder="0" />
                            </div>
                        </label>
                    </div>

                    <div class="form-row" style="margin-top:0.6rem;">
                        <label>Tasa (si desea cambiar):
                            <input type="text" name="tasa" id="tasa_input" value="<?php echo $tasa_actual !== null ? htmlspecialchars(str_replace('.',',', (string)$tasa_actual)) : ''; ?>" />
                        </label>
                        <label>Responsable:
                            <input type="text" name="responsable" />
                        </label>
                        <label>Beneficiario:
                            <input type="text" name="beneficiario" />
                        </label>
                    </div>

                    <div style="margin-top:0.9rem;">
                        <button type="submit" name="guardar" class="btn-principal">Guardar</button>
                        <button type="reset" class="btn-cancelar" style="margin-left:8px;">Limpiar</button>
                    </div>
                </form>
            </section>

            <!-- Listado -->
            <section style="margin-top:1rem;">
                <h3 style="margin-bottom:10px;">Movimientos recientes</h3>
                <?php if (count($items) === 0): ?>
                    <div class="msg card-panel">No hay movimientos registrados.</div>
                <?php else: ?>
                    <div class="tabla-scroll card-panel">
                        <table class="reporte-tabla" style="width:100%;">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Descripción</th>
                                    <th>Instrumento</th>
                                    <th>Cuenta / Método</th>
                                    <th class="align-right">Monto</th>
                                    <th>Resp.</th>
                                    <th>Benef.</th>
                                    <th class="no-print">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($items as $it): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(sql_to_ddmmyyyy($it['fecha'])); ?></td>
                                        <td><?php echo htmlspecialchars($it['descripcion']); ?></td>
                                        <td><?php echo $it['instrumento'] === '$' ? '$' : 'Bs'; ?></td>
                                        <td><?php
                                            if ($it['instrumento'] === 'Bs') {
                                                echo htmlspecialchars($it['banco_nombre'] ?? '—');
                                            } else {
                                                echo htmlspecialchars($it['divisa_metodo'] ?? '—');
                                            }
                                        ?></td>
                                        <td class="align-right">
                                            <?php
                                                if ($it['instrumento'] === 'Bs') {
                                                    $v = floatval($it['monto_bs'] ?? 0.0);
                                                    $bs = number_format($v, 2, ',', '.');
                                                    echo 'Bs ' . htmlspecialchars($bs);
                                                    if ($it['tasa'] && floatval($it['tasa'])>0) {
                                                        $usd = $v / floatval($it['tasa']);
                                                        echo '<div style="font-size:0.85rem;color:#666;">(US$ ' . htmlspecialchars(number_format($usd,2,',','.')) . ')</div>';
                                                    }
                                                } else {
                                                    $v = floatval($it['monto_usd'] ?? 0.0);
                                                    $usd = number_format($v, 2, '.', ',');
                                                    echo '$ ' . htmlspecialchars($usd);
                                                    if ($it['tasa'] && floatval($it['tasa'])>0) {
                                                        $bs_equiv = $v * floatval($it['tasa']);
                                                        echo '<div style="font-size:0.85rem;color:#666;">(Bs ' . htmlspecialchars(number_format($bs_equiv,2,',','.')) . ')</div>';
                                                    }
                                                }
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($it['responsable']); ?></td>
                                        <td><?php echo htmlspecialchars($it['beneficiario']); ?></td>
                                        <td class="no-print">
                                            <div class="table-actions">
                                                <form method="GET" style="display:inline;">
                                                    <input type="hidden" name="action" value="edit">
                                                    <input type="hidden" name="id" value="<?php echo $it['id']; ?>">
                                                    <button class="btn-principal" type="submit">Editar</button>
                                                </form>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Eliminar registro?');">
                                                    <input type="hidden" name="id" value="<?php echo $it['id']; ?>">
                                                    <button type="submit" name="eliminar" class="btn-cancelar">Eliminar</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- paginado simple -->
                    <?php
                        $total_pages = max(1, ceil($total_rows / $perpage));
                    ?>
                    <div style="margin-top:0.8rem;">
                        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                            <a href="flujocaja.php?page=<?php echo $p; ?>" class="btn-volver" style="margin-right:6px;<?php echo $p==$page?'opacity:0.8;':''; ?>"><?php echo $p; ?></a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Editar: cargar datos -->
            <?php if ($action === 'edit' && isset($_GET['id'])):
                $id = intval($_GET['id']);
                $s = $conn->prepare("SELECT * FROM flujo_caja WHERE id=? AND empresa_id=? LIMIT 1");
                $s->bind_param('ii', $id, $empresa_id);
                $s->execute();
                $row = $s->get_result()->fetch_assoc();
                $s->close();
                if ($row):
            ?>
                <section class="card-panel" style="margin-top:1rem;">
                    <h3>Editar movimiento</h3>
                    <form method="POST" onsubmit="return flujocaja_before_submit(this);" class="formulario">
                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                        <div class="form-row">
                            <label>Fecha:
                                <input name="fecha" type="text" value="<?php echo htmlspecialchars(sql_to_ddmmyyyy($row['fecha'])); ?>" required />
                            </label>
                            <label>Instrumento:
                                <select name="instrumento" id="instrumento_edit" onchange="flujocaja_toggle_instrumento_edit();">
                                    <option value="Bs" <?php if($row['instrumento']==='Bs') echo 'selected'; ?>>Bolívares (Bs)</option>
                                    <option value="$" <?php if($row['instrumento']==='$') echo 'selected'; ?>>Dólares ($)</option>
                                </select>
                            </label>
                        </div>

                        <div class="form-row" style="margin-top:0.6rem;">
                            <label>Cuenta banco (si Bs):
                                <select name="banco_id" id="banco_id_edit">
                                    <option value="">-- Seleccione cuenta --</option>
                                    <?php foreach($bancos as $b): ?>
                                        <option value="<?php echo $b['id']; ?>" <?php if($row['banco_id']==$b['id']) echo 'selected'; ?>><?php echo htmlspecialchars($b['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Divisas método (si $):
                                <select name="divisa_metodo" id="divisa_metodo_edit">
                                    <option value="">--</option>
                                    <option value="EFECTIVO" <?php if($row['divisa_metodo']==='EFECTIVO') echo 'selected'; ?>>EFECTIVO</option>
                                    <option value="TRANSFERENCIA" <?php if($row['divisa_metodo']==='TRANSFERENCIA') echo 'selected'; ?>>TRANSFERENCIA</option>
                                </select>
                            </label>
                        </div>

                        <div class="form-row" style="margin-top:0.6rem;">
                            <label>Descripción:
                                <input type="text" name="descripcion" required value="<?php echo htmlspecialchars($row['descripcion']); ?>" />
                            </label>
                            <label>Monto:
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <div id="currency_label_edit" class="currency-badge"><?php echo $row['instrumento']==='$' ? '$' : 'Bs'; ?></div>
                                    <input type="text" name="monto" id="monto_input_edit" required
                                        value="<?php
                                            if ($row['instrumento']==='Bs') echo htmlspecialchars(number_format(floatval($row['monto_bs'] ?? 0.0),2,',','.'));
                                            else echo htmlspecialchars(number_format(floatval($row['monto_usd'] ?? 0.0),2,'.',','));
                                        ?>" />
                                </div>
                            </label>
                        </div>

                        <div class="form-row" style="margin-top:0.6rem;">
                            <label>Tasa:
                                <input type="text" name="tasa" id="tasa_input_edit" value="<?php echo htmlspecialchars($row['tasa']); ?>" />
                            </label>
                            <label>Responsable:
                                <input type="text" name="responsable" value="<?php echo htmlspecialchars($row['responsable']); ?>" />
                            </label>
                            <label>Beneficiario:
                                <input type="text" name="beneficiario" value="<?php echo htmlspecialchars($row['beneficiario']); ?>" />
                            </label>
                        </div>

                        <div style="margin-top:0.8rem;">
                            <button type="submit" name="guardar_edicion" class="btn-principal">Guardar cambios</button>
                            <a href="flujocaja.php" class="btn-volver" style="margin-left:8px;">Cancelar</a>
                        </div>
                    </form>
                </section>

                <script>
                    (function(){
                        var instr = '<?php echo $row['instrumento']; ?>';
                        var el = document.getElementById('instrumento_edit');
                        if (el) el.value = instr;
                        // Delay to allow flujocaja.js attach listeners
                        setTimeout(function(){ if (typeof flujocaja_toggle_instrumento_edit === 'function') flujocaja_toggle_instrumento_edit(); }, 80);
                    })();
                </script>
            <?php else: ?>
                <div class="msg card-panel">Registro no encontrado.</div>
            <?php endif; endif; ?>

        </div>

        <div style="height:120px;"></div>
    </main>

    <!-- Cargar JS al final para mejor rendimiento -->
    <script src="../assets/js/flujocaja.js?v=<?php echo time(); ?>"></script>
    <script>
    // funciones de interacción (mantener simples aquí; la lógica de formateo está en flujocaja.js)
    function flujocaja_toggle_instrumento(){
        var instr = document.getElementById('instrumento').value;
        var banco = document.getElementById('banco_id');
        var divm = document.getElementById('divisa_metodo');
        var label = document.getElementById('currency_label');
        var monto = document.getElementById('monto_input');
        if(instr === 'Bs'){
            banco.disabled = false;
            divm.disabled = true;
            divm.value = '';
            label.innerText = 'Bs';
            if (monto) monto.placeholder = '0';
        } else {
            banco.disabled = true;
            banco.value = '';
            divm.disabled = false;
            label.innerText = '$';
            if (monto) monto.placeholder = '0';
        }
    }
    function flujocaja_toggle_instrumento_edit(){
        var instr = document.getElementById('instrumento_edit').value;
        var banco = document.getElementById('banco_id_edit');
        var divm = document.getElementById('divisa_metodo_edit');
        var label = document.getElementById('currency_label_edit');
        if(instr === 'Bs'){
            banco.disabled = false;
            divm.disabled = true;
            divm.value = '';
            label.innerText = 'Bs';
        } else {
            banco.disabled = true;
            banco.value = '';
            divm.disabled = false;
            label.innerText = '$';
        }
    }

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

    // Inicializar al cargar la página
    document.addEventListener('DOMContentLoaded', function(){
        // Asegurarse de que el toggle inicial coincida con el select
        if (document.getElementById('instrumento')) flujocaja_toggle_instrumento();
        // Attach formatting will be done by flujocaja.js
    });
    </script>
</body>
</html>