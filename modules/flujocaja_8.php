<?php
// Archivo: modules/flujocaja.php
// Módulo: Flujo de Caja (ajustado para actualizar saldos bancarios al crear/editar/eliminar)
// Reemplaza tu archivo flujocaja.php por este (haz backup antes).

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

// --- Obtener bancos con saldos para validar cliente-side ---
$bancos = [];
$rb = $conn->prepare("SELECT id, nombre, IFNULL(saldo,0) AS saldo FROM bancos WHERE empresa_id=? ORDER BY nombre");
$rb->bind_param('i', $empresa_id);
$rb->execute();
$resb = $rb->get_result();
while ($row = $resb->fetch_assoc()) $bancos[] = $row;
$rb->close();

// ------------------- CREATE (guardar) -------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar'])) {
    $fecha = ddmmyyyy_to_sql($_POST['fecha'] ?? date('Y-m-d'));
    $descripcion = trim($_POST['descripcion'] ?? '');
    $instrumento = ($_POST['instrumento'] ?? '') === '$' ? '$' : 'Bs';
    $banco_id = null;
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
        $monto_usd = isset($_POST['monto']) ? parse_monto_usd_from_input($_POST['monto']) : 0.0;
    }

    if ($descripcion === '') $errors[] = "Descripción requerida.";

    if (empty($errors)) {
        // Usar transacción para insertar y actualizar saldo de banco de forma atómica
        $conn->begin_transaction();
        try {
            // Si es Bs, bloquear fila del banco y verificar saldo suficiente
            if ($instrumento === 'Bs' && $banco_id !== null) {
                $qb = $conn->prepare("SELECT IFNULL(saldo,0) FROM bancos WHERE id=? AND empresa_id=? FOR UPDATE");
                $qb->bind_param('ii', $banco_id, $empresa_id);
                $qb->execute();
                $qb->bind_result($bank_saldo_db);
                $qb->fetch();
                $qb->close();
                if ($monto_bs > floatval($bank_saldo_db)) {
                    // saldo insuficiente -> rollback y error
                    $conn->rollback();
                    $errors[] = "La cuenta seleccionada NO cuenta con saldo suficiente (" . number_format(floatval($bank_saldo_db),2,',','.') . " Bs). Cambie la cuenta o ajuste el monto.";
                }
            }

            if (empty($errors)) {
                // Insertar el registro
                $stmt = $conn->prepare("INSERT INTO flujo_caja (empresa_id, fecha, descripcion, instrumento, banco_id, monto_bs, monto_usd, tasa, responsable, beneficiario, creado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                if (!$stmt) throw new Exception("Error de preparación: " . $conn->error);
                $stmt->bind_param("isssidddssi", $empresa_id, $fecha, $descripcion, $instrumento, $banco_id, $monto_bs, $monto_usd, $tasa, $responsable, $beneficiario, $usuario_id);
                if (!$stmt->execute()) {
                    $err = $stmt->error;
                    $stmt->close();
                    throw new Exception("Error al guardar: " . $err);
                }
                $stmt->close();

                // Si es Bs, restar monto del saldo de la cuenta (ya verificada)
                if ($instrumento === 'Bs' && $banco_id !== null && $monto_bs > 0) {
                    $qup = $conn->prepare("UPDATE bancos SET saldo = IFNULL(saldo,0) - ? WHERE id = ? AND empresa_id = ?");
                    if (!$qup) throw new Exception("Error preparando actualización de banco: " . $conn->error);
                    $qup->bind_param('dii', $monto_bs, $banco_id, $empresa_id);
                    if (!$qup->execute()) {
                        $err = $qup->error;
                        $qup->close();
                        throw new Exception("Error al actualizar saldo banco: " . $err);
                    }
                    $qup->close();
                }

                $conn->commit();
                $_SESSION['msg'] = "Registro agregado.";
                header("Location: flujocaja.php");
                exit();
            }
        } catch (Exception $ex) {
            $conn->rollback();
            $errors[] = $ex->getMessage();
        }
    }
}

// ------------------- UPDATE (guardar_edicion) -------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_edicion'])) {
    $id = intval($_POST['id']);
    $fecha = ddmmyyyy_to_sql($_POST['fecha'] ?? date('Y-m-d'));
    $descripcion = trim($_POST['descripcion'] ?? '');
    $instrumento = ($_POST['instrumento'] ?? '') === '$' ? '$' : 'Bs';
    $banco_id = null;
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
        $monto_usd = isset($_POST['monto']) ? parse_monto_usd_from_input($_POST['monto']) : 0.0;
    }

    if ($descripcion === '') $errors[] = "Descripción requerida.";

    if (empty($errors)) {
        // Transacción para revertir efecto anterior y aplicar nuevo
        $conn->begin_transaction();
        try {
            // Bloquear y leer la fila existente
            $sr = $conn->prepare("SELECT instrumento, banco_id, IFNULL(monto_bs,0) AS monto_bs, IFNULL(monto_usd,0) AS monto_usd FROM flujo_caja WHERE id=? AND empresa_id=? FOR UPDATE");
            if (!$sr) throw new Exception("Error preparando lectura registro: " . $conn->error);
            $sr->bind_param('ii', $id, $empresa_id);
            $sr->execute();
            $sr->bind_result($old_instrumento, $old_banco_id, $old_monto_bs, $old_monto_usd);
            if (!$sr->fetch()) {
                $sr->close();
                throw new Exception("Registro no encontrado para edición.");
            }
            $sr->close();

            // 1) Revertir efecto anterior sobre bancos (si aplicaba)
            if ($old_instrumento === 'Bs' && $old_banco_id !== null && $old_monto_bs > 0) {
                $qrev = $conn->prepare("UPDATE bancos SET saldo = IFNULL(saldo,0) + ? WHERE id = ? AND empresa_id = ?");
                if (!$qrev) throw new Exception("Error preparando revert banco: " . $conn->error);
                $qrev->bind_param('dii', $old_monto_bs, $old_banco_id, $empresa_id);
                if (!$qrev->execute()) {
                    $err = $qrev->error;
                    $qrev->close();
                    throw new Exception("Error al revertir saldo banco antiguo: " . $err);
                }
                $qrev->close();
            }

            // 2) Si nuevo instrumento es Bs, comprobar saldo (después de la reversión) y descontar
            if ($instrumento === 'Bs' && $banco_id !== null && $monto_bs > 0) {
                // Bloquear banco fila y verificar saldo
                $qb = $conn->prepare("SELECT IFNULL(saldo,0) FROM bancos WHERE id=? AND empresa_id=? FOR UPDATE");
                if (!$qb) throw new Exception("Error preparando select banco: " . $conn->error);
                $qb->bind_param('ii', $banco_id, $empresa_id);
                $qb->execute();
                $qb->bind_result($bank_saldo_db);
                $qb->fetch();
                $qb->close();
                if ($monto_bs > floatval($bank_saldo_db)) {
                    $conn->rollback();
                    $errors[] = "La cuenta seleccionada NO cuenta con saldo suficiente (" . number_format(floatval($bank_saldo_db),2,',','.') . " Bs). Cambie la cuenta o ajuste el monto.";
                } else {
                    // Restar el nuevo monto
                    $qup = $conn->prepare("UPDATE bancos SET saldo = IFNULL(saldo,0) - ? WHERE id = ? AND empresa_id = ?");
                    if (!$qup) throw new Exception("Error preparando actualización de banco: " . $conn->error);
                    $qup->bind_param('dii', $monto_bs, $banco_id, $empresa_id);
                    if (!$qup->execute()) {
                        $err = $qup->error;
                        $qup->close();
                        throw new Exception("Error al actualizar saldo banco nuevo: " . $err);
                    }
                    $qup->close();
                }
            }

            if (empty($errors)) {
                // 3) Actualizar el registro flujo_caja
                $stmt = $conn->prepare("UPDATE flujo_caja SET fecha=?, descripcion=?, instrumento=?, banco_id=?, monto_bs=?, monto_usd=?, tasa=?, responsable=?, beneficiario=?, actualizado_por=?, actualizado_en=NOW() WHERE id=? AND empresa_id=?");
                if (!$stmt) throw new Exception("Error preparando UPDATE registro: " . $conn->error);
                $stmt->bind_param("sssidddssiii", $fecha, $descripcion, $instrumento, $banco_id, $monto_bs, $monto_usd, $tasa, $responsable, $beneficiario, $usuario_id, $id, $empresa_id);
                if (!$stmt->execute()) {
                    $err = $stmt->error;
                    $stmt->close();
                    throw new Exception("Error al actualizar registro: " . $err);
                }
                $stmt->close();

                $conn->commit();
                $_SESSION['msg'] = "Registro actualizado.";
                header("Location: flujocaja.php");
                exit();
            }
        } catch (Exception $ex) {
            $conn->rollback();
            $errors[] = $ex->getMessage();
        }
    }
}

// ------------------- DELETE (eliminar) -------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar'])) {
    $id = intval($_POST['id']);

    // Transacción: obtener registro y restaurar saldo si era Bs
    $conn->begin_transaction();
    try {
        $sr = $conn->prepare("SELECT instrumento, banco_id, IFNULL(monto_bs,0) AS monto_bs FROM flujo_caja WHERE id=? AND empresa_id=? FOR UPDATE");
        if (!$sr) throw new Exception("Error preparando lectura para eliminar: " . $conn->error);
        $sr->bind_param('ii', $id, $empresa_id);
        $sr->execute();
        $sr->bind_result($del_instrumento, $del_banco_id, $del_monto_bs);
        if (!$sr->fetch()) {
            $sr->close();
            throw new Exception("Registro no encontrado para eliminar.");
        }
        $sr->close();

        if ($del_instrumento === 'Bs' && $del_banco_id !== null && $del_monto_bs > 0) {
            $qup = $conn->prepare("UPDATE bancos SET saldo = IFNULL(saldo,0) + ? WHERE id = ? AND empresa_id = ?");
            if (!$qup) throw new Exception("Error preparando restauración saldo banco: " . $conn->error);
            $qup->bind_param('dii', $del_monto_bs, $del_banco_id, $empresa_id);
            if (!$qup->execute()) {
                $err = $qup->error;
                $qup->close();
                throw new Exception("Error al restaurar saldo banco: " . $err);
            }
            $qup->close();
        }

        $sd = $conn->prepare("DELETE FROM flujo_caja WHERE id=? AND empresa_id=?");
        if (!$sd) throw new Exception("Error preparando DELETE: " . $conn->error);
        $sd->bind_param('ii', $id, $empresa_id);
        if (!$sd->execute()) {
            $err = $sd->error;
            $sd->close();
            throw new Exception("Error al eliminar registro: " . $err);
        }
        $sd->close();

        $conn->commit();
        $_SESSION['msg'] = "Registro eliminado.";
        header("Location: flujocaja.php");
        exit();
    } catch (Exception $ex) {
        $conn->rollback();
        $errors[] = $ex->getMessage();
    }
}

// ------------------- listado/presentación (sin cambios) -------------------
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
/* Ajustes visuales específicos */
.container-dashboard { max-width:1100px; margin:0 auto; padding: 1rem; }
.card-panel { background:var(--color-panel); border-radius:12px; padding:1rem; box-shadow:0 6px 20px var(--color-shadow); margin-bottom:1rem; }
.form-row { display:flex; gap:0.75rem; flex-wrap:wrap; align-items:center; }
.form-row label { flex:1 1 220px; min-width:180px; display:flex; flex-direction:column; gap:0.35rem; font-weight:600; color:var(--color-titulo); }
.form-row input[type="text"], .form-row input[type="number"], .form-row select { padding:8px 10px; border-radius:8px; border:none; background:var(--color-input-bg); box-shadow:0 1px 4px var(--color-shadow); }
.currency-badge { display:inline-block; min-width:44px; font-weight:bold; text-align:center; padding:6px 8px; border-radius:8px; background:#f0f4fb; color:var(--color-principal); margin-right:6px; }
.table-actions { display:flex; gap:8px; align-items:center; justify-content:flex-end; }
.sr-only { position:absolute !important; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
.small-note { font-size:0.88rem; color:#666; margin-top:6px; }

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

            <!-- Formulario principal (servirá para crear y para editar) -->
            <section id="form-nuevo" class="card-panel" aria-label="Agregar movimiento">
                <h3 style="margin-top:0;" id="form-title">Agregar movimiento</h3>
                <form id="form_agregar" method="POST" onsubmit="return flujocaja_before_submit(this);" class="formulario">
                    <!-- hidden id: if set, we are editing -->
                    <input type="hidden" name="id" id="form_id" value="">
                    <div class="form-row">
                        <!-- 1. Fecha (sin cambios) -->
                        <label>Fecha (DD/MM/AAAA):
                            <input name="fecha" id="fecha_input" type="text" value="<?php echo date('d/m/Y'); ?>" required placeholder="DD/MM/AAAA" />
                        </label>

                        <!-- 2. Instrumento (solo etiqueta modificada) -->
                        <label>Instrumento de Pago:
                            <select name="instrumento" id="instrumento" onchange="flujocaja_toggle_instrumento();" required>
                                <option value="Bs">Bolívares (Bs)</option>
                                <option value="$">Dólares ($)</option>
                            </select>
                        </label>
                    </div>

                    <!-- Cuenta banco -->
                    <div class="form-row" style="margin-top:0.6rem;">
                        <label>Cuenta banco (si Bs):
                            <select name="banco_id" id="banco_id" onchange="flujocaja_on_bank_change();">
                                <option value="">-- Seleccione cuenta --</option>
                                <?php foreach($bancos as $b): ?>
                                    <option value="<?php echo $b['id']; ?>" data-saldo="<?php echo htmlspecialchars($b['saldo']); ?>"><?php echo htmlspecialchars($b['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div id="bank_saldo_info" class="small-note" aria-live="polite"></div>
                        </label>
                    </div>

                    <!-- 3. Descripción -->
                    <div class="form-row" style="margin-top:0.6rem;">
                        <label>Descripción del Pago:
                            <input type="text" name="descripcion" id="descripcion_input" placeholder="Ej: Pago de bono de transporte a Pedro Pérez" required />
                        </label>

                        <!-- 4. Monto -->
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
                            <input type="text" name="responsable" id="responsable_input" />
                        </label>
                        <label>Beneficiario:
                            <input type="text" name="beneficiario" id="beneficiario_input" />
                        </label>
                    </div>

                    <div style="margin-top:0.9rem;">
                        <button type="submit" id="submit_btn" name="guardar" class="btn-principal">Guardar</button>
                        <button type="button" id="cancel_edit_btn" class="btn-volver" style="margin-left:8px;display:none;">Cancelar edición</button>
                        <button type="reset" class="btn-cancelar" style="margin-left:8px;">Limpiar</button>
                    </div>
                </form>
            </section>

            <!-- Listado (sin cambios visuales) -->
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
                                    <th>Cuenta</th>
                                    <th class="align-right">Monto</th>
                                    <th>Resp.</th>
                                    <th>Benef.</th>
                                    <th class="no-print">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($items as $it): 
                                    // Prepare safe values for data attributes
                                    $data_id = (int)$it['id'];
                                    $data_fecha = htmlspecialchars(sql_to_ddmmyyyy($it['fecha']));
                                    $data_desc = htmlspecialchars($it['descripcion']);
                                    $data_instr = ($it['instrumento'] === '$') ? '$' : 'Bs';
                                    $data_banco = isset($it['banco_id']) ? (int)$it['banco_id'] : '';
                                    $data_monto_bs = isset($it['monto_bs']) ? floatval($it['monto_bs']) : 0;
                                    $data_monto_usd = isset($it['monto_usd']) ? floatval($it['monto_usd']) : 0;
                                    $data_tasa = isset($it['tasa']) ? htmlspecialchars($it['tasa']) : '';
                                    $data_resp = htmlspecialchars($it['responsable']);
                                    $data_ben = htmlspecialchars($it['beneficiario']);
                                ?>
                                    <tr>
                                        <td><?php echo $data_fecha; ?></td>
                                        <td><?php echo $data_desc; ?></td>
                                        <td><?php echo $data_instr; ?></td>
                                        <td><?php echo htmlspecialchars($it['banco_nombre'] ?? '—'); ?></td>
                                        <td class="align-right">
                                            <?php if ($it['instrumento'] === 'Bs'): 
                                                $v = floatval($it['monto_bs'] ?? 0.0);
                                                $bs = number_format($v, 2, ',', '.');
                                            ?>
                                                Bs <?php echo htmlspecialchars($bs); ?>
                                                <?php if ($it['tasa'] && floatval($it['tasa'])>0): 
                                                    $usd = $v / floatval($it['tasa']);
                                                ?>
                                                    <div style="font-size:0.85rem;color:#666;">(US$ <?php echo htmlspecialchars(number_format($usd,2,',','.')); ?>)</div>
                                                <?php endif; ?>
                                            <?php else: 
                                                $v = floatval($it['monto_usd'] ?? 0.0);
                                                $usd = number_format($v, 2, '.', ',');
                                            ?>
                                                $ <?php echo htmlspecialchars($usd); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $data_resp; ?></td>
                                        <td><?php echo $data_ben; ?></td>
                                        <td class="no-print">
                                            <div class="table-actions" aria-label="Acciones">
                                                <!-- Editar: load into main form via data attributes -->
                                                <a href="#" class="btn-accion editar"
                                                   title="Editar"
                                                   data-id="<?php echo $data_id; ?>"
                                                   data-fecha="<?php echo $data_fecha; ?>"
                                                   data-descripcion="<?php echo $data_desc; ?>"
                                                   data-instrumento="<?php echo $data_instr; ?>"
                                                   data-banco="<?php echo $data_banco; ?>"
                                                   data-montobs="<?php echo $data_monto_bs; ?>"
                                                   data-montousd="<?php echo $data_monto_usd; ?>"
                                                   data-tasa="<?php echo $data_tasa; ?>"
                                                   data-responsable="<?php echo $data_resp; ?>"
                                                   data-beneficiario="<?php echo $data_ben; ?>"
                                                   onclick="loadRecordIntoMainForm(this); return false;">
                                                    <span aria-hidden="true">&#9998;</span>
                                                    <span class="sr-only">Editar</span>
                                                </a>

                                                <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('¿Eliminar registro?');">
                                                    <input type="hidden" name="id" value="<?php echo $it['id']; ?>">
                                                    <button type="submit" name="eliminar" class="btn-accion eliminar" title="Eliminar">
                                                        <span aria-hidden="true">&#128465;</span>
                                                        <span class="sr-only">Eliminar</span>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

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

        </div>

        <div style="height:120px;"></div>
    </main>

    <!-- Cargar JS al final -->
    <script src="../assets/js/flujocaja.js?v=<?php echo time(); ?>"></script>
    <script>
    // Exponer bancos balances para uso en cliente
    const BANK_BALANCES = <?php
        $map = [];
        foreach ($bancos as $b) {
            $map[(int)$b['id']] = floatval($b['saldo']);
        }
        echo json_encode($map, JSON_NUMERIC_CHECK);
    ?>;

    // Helper to safely set select value
    function setSelectValue(sel, val) {
        if (!sel) return;
        sel.value = val;
        // If option doesn't exist, try to add? we assume exists.
    }

    // Load a record (anchor element) into the main form for editing
    function loadRecordIntoMainForm(el) {
        if (!el || !el.dataset) return;
        // Populate fields
        var id = el.dataset.id || '';
        var fecha = el.dataset.fecha || '';
        var descripcion = el.dataset.descripcion || '';
        var instrumento = el.dataset.instrumento || 'Bs';
        var banco = el.dataset.banco || '';
        var montoBs = el.dataset.montobs || '0';
        var montoUsd = el.dataset.montousd || '0';
        var tasa = el.dataset.tasa || '';
        var responsable = el.dataset.responsable || '';
        var beneficiario = el.dataset.beneficiario || '';

        // Set form values
        document.getElementById('form_id').value = id;
        document.getElementById('fecha_input').value = fecha;
        document.getElementById('descripcion_input').value = descripcion;
        document.getElementById('tasa_input').value = tasa;
        document.getElementById('responsable_input').value = responsable;
        document.getElementById('beneficiario_input').value = beneficiario;

        // Instrumento
        var instrSel = document.getElementById('instrumento');
        if (instrSel) setSelectValue(instrSel, instrumento);
        // Currency badge
        var badge = document.getElementById('currency_label');
        if (badge) badge.innerText = instrumento === 'Bs' ? 'Bs' : '$';

        // Banco
        var bancoSel = document.getElementById('banco_id');
        if (bancoSel) {
            setSelectValue(bancoSel, banco);
        }

        // Monto: format for display using fcj_format functions if available
        var montoInput = document.getElementById('monto_input');
        if (montoInput) {
            if (instrumento === 'Bs') {
                if (typeof window.fcj_formatBs === 'function') montoInput.value = window.fcj_formatBs(Number(montoBs));
                else montoInput.value = Number(montoBs).toFixed(2);
            } else {
                if (typeof window.fcj_formatUsd === 'function') montoInput.value = window.fcj_formatUsd(Number(montoUsd));
                else montoInput.value = Number(montoUsd).toFixed(2);
            }
        }

        // Switch button to edit mode
        var submitBtn = document.getElementById('submit_btn');
        if (submitBtn) {
            submitBtn.name = 'guardar_edicion';
            submitBtn.textContent = 'Guardar cambios';
        }
        var cancelBtn = document.getElementById('cancel_edit_btn');
        if (cancelBtn) cancelBtn.style.display = 'inline-block';

        // Scroll into view
        document.getElementById('form-nuevo').scrollIntoView({behavior:'smooth', block:'center'});

        // Update bank info and visuals
        updateBankSaldoInfo(document.getElementById('form_agregar'), 'bank_saldo_info');

        // Focus first editable field
        setTimeout(function(){
            var desc = document.getElementById('descripcion_input');
            if (desc) desc.focus();
        }, 200);
    }

    // Cancel edit mode: clear id and reset button
    document.getElementById('cancel_edit_btn').addEventListener('click', function(){
        // reset form
        var form = document.getElementById('form_agregar');
        form.reset();
        document.getElementById('form_id').value = '';
        var submitBtn = document.getElementById('submit_btn');
        if (submitBtn) {
            submitBtn.name = 'guardar';
            submitBtn.textContent = 'Guardar';
        }
        this.style.display = 'none';
        // restore defaults
        document.getElementById('currency_label').innerText = 'Bs';
        // update bank info
        updateBankSaldoInfo(document.getElementById('form_agregar'), 'bank_saldo_info');
    });

    // Existing helpers (updateBankSaldoInfo, maybeCheckInsufficientAndFocus, flujocaja_before_submit, etc.)
    // Keep functions from previous implementation here so the checks/formatting still work.
    function formatBsDisplay(n) {
        n = Number(n) || 0;
        var parts = n.toFixed(2).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        return 'Bs ' + parts.join(',');
    }

    function updateBankSaldoInfo(form, infoId) {
        if (!form) return;
        const bankSel = form.querySelector('select[name="banco_id"]');
        const montoInput = form.querySelector('input[name="monto"]');
        const info = document.getElementById(infoId);
        if (!bankSel || !info) return;
        const bid = bankSel.value ? parseInt(bankSel.value,10) : null;
        if (!bid) { info.innerHTML = ''; bankSel.style.border=''; return; }
        const saldo = BANK_BALANCES[bid] || 0;
        const saldoStr = saldo.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ".").replace(".", ",").replace(/,([^,]*)$/, ',$1');
        let montoRaw = 0;
        if (montoInput) {
            montoRaw = (typeof window.fcj_unformat === 'function') ? window.fcj_unformat(montoInput.value, 'Bs') : montoInput.value.replace(/[^\d\.,-]/g,'');
            montoRaw = montoRaw ? parseFloat(montoRaw.toString().replace(',','.')) : 0;
        }
        if (montoRaw > saldo) {
            info.innerHTML = 'Saldo disponible: <span style="font-weight:800;color:#8B0000;">Bs ' + saldoStr + '</span>';
            bankSel.style.border = '2px solid #8B0000';
        } else {
            info.innerHTML = 'Saldo disponible: Bs ' + saldoStr;
            bankSel.style.border = '';
        }
    }

    function maybeCheckInsufficientAndFocus(form) {
        if (!form) return false;
        const instr = form.querySelector('select[name="instrumento"]');
        if (!instr || instr.value !== 'Bs') return false;
        const bankSel = form.querySelector('select[name="banco_id"]');
        if (!bankSel || !bankSel.value) return false;
        const bid = parseInt(bankSel.value, 10);
        const saldo = BANK_BALANCES[bid] || 0;
        const montoInput = form.querySelector('input[name="monto"]');
        if (!montoInput) return false;
        const raw = (typeof window.fcj_unformat === 'function') ? window.fcj_unformat(montoInput.value, 'Bs') : montoInput.value.replace(/[^\d\.,-]/g,'');
        const montoVal = raw ? parseFloat(raw.toString().replace(',','.')) : 0;
        if (montoVal > saldo) {
            alert("LA CUENTA SELECCIONADA NO CUENTA CON SUFICIENTE SALDO");
            bankSel.focus();
            return true;
        }
        return false;
    }

    // wire monto input events to update bank info and check insufficiency
    document.addEventListener('DOMContentLoaded', function(){
        const monto = document.getElementById('monto_input');
        if (monto) {
            monto.addEventListener('blur', function(){
                updateBankSaldoInfo(document.getElementById('form_agregar'), 'bank_saldo_info');
                maybeCheckInsufficientAndFocus(document.getElementById('form_agregar'));
            });
            monto.addEventListener('keydown', function(e){
                if (e.key === 'Enter') {
                    e.preventDefault();
                    setTimeout(function(){
                        updateBankSaldoInfo(document.getElementById('form_agregar'), 'bank_saldo_info');
                        maybeCheckInsufficientAndFocus(document.getElementById('form_agregar'));
                    }, 50);
                }
            });
        }

        // initial bank info
        updateBankSaldoInfo(document.getElementById('form_agregar'), 'bank_saldo_info');
    });

    function flujocaja_on_bank_change() {
        const form = document.getElementById('form_agregar');
        updateBankSaldoInfo(form, 'bank_saldo_info');
    }

    function flujocaja_toggle_instrumento(){
        var instr = document.getElementById('instrumento').value;
        var banco = document.getElementById('banco_id');
        var label = document.getElementById('currency_label');
        var monto = document.getElementById('monto_input');
        if(instr === 'Bs'){
            banco.disabled = false;
            label.innerText = 'Bs';
            if (monto) monto.placeholder = '0';
        } else {
            banco.disabled = true;
            banco.value = '';
            label.innerText = '$';
            if (monto) monto.placeholder = '0';
            var info = document.getElementById('bank_saldo_info');
            if (info) info.innerHTML = '';
        }
    }

    function flujocaja_before_submit(form){
        // If editing/creating and Bs selected, block submit if insufficient and focus bank select
        if (maybeCheckInsufficientAndFocus(form)) {
            return false;
        }

        var montoInput = form.querySelector('input[name="monto"]');
        if (montoInput){
            if (typeof window.fcj_unformat === 'function'){
                var tipo = (form.instrumento && form.instrumento.value === 'Bs') ? 'Bs' : '$';
                montoInput.value = window.fcj_unformat(montoInput.value, tipo);
            } else {
                montoInput.value = montoInput.value.replace(/[^\d\.,-]/g,'');
            }
        }

        // If we are in edit mode (form_id has value), ensure submit button uses guardar_edicion
        var idval = document.getElementById('form_id').value;
        var submitBtn = document.getElementById('submit_btn');
        if (idval && submitBtn) {
            // ensure name is guardar_edicion so server updates
            submitBtn.name = 'guardar_edicion';
        } else if (submitBtn) {
            submitBtn.name = 'guardar';
        }

        var tasa = form.querySelector('input[name="tasa"]');
        if (tasa) tasa.value = tasa.value.replace(',','.');
        return true;
    }
    </script>
</body>
</html>