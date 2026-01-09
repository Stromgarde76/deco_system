<?php
// Archivo: modules/flujocaja.php
// Módulo: Flujo de Caja
// Usuario ingresa montos usando punto como decimal (652485.20)
// Sistema muestra en formato latino (652.485,20)

session_start();
require_once "../config/db.php";
require_once "../config/amount_utils.php";

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
    // tasa: server expects normalized decimal with dot
    $tasa = isset($_POST['tasa']) && strlen($_POST['tasa'])>0 ? parseAmount($_POST['tasa']) : $tasa_actual;
    $responsable = trim($_POST['responsable'] ?? '');
    $beneficiario = trim($_POST['beneficiario'] ?? '');

    if ($instrumento === 'Bs') {
        $banco_id = isset($_POST['banco_id']) && $_POST['banco_id'] !== '' ? (int)$_POST['banco_id'] : null;
        $monto_bs = isset($_POST['monto']) ? parseAmount($_POST['monto']) : 0.0;
        if ($banco_id === null) $errors[] = "Debe seleccionar la cuenta bancaria de donde se debitará el pago en Bs.";
    } else {
        $monto_usd = isset($_POST['monto']) ? parseAmount($_POST['monto']) : 0.0;
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
    $tasa = isset($_POST['tasa']) && strlen($_POST['tasa'])>0 ? parseAmount($_POST['tasa']) : $tasa_actual;
    $responsable = trim($_POST['responsable'] ?? '');
    $beneficiario = trim($_POST['beneficiario'] ?? '');

    if ($instrumento === 'Bs') {
        $banco_id = isset($_POST['banco_id']) && $_POST['banco_id'] !== '' ? (int)$_POST['banco_id'] : null;
        $monto_bs = isset($_POST['monto']) ? parseAmount($_POST['monto']) : 0.0;
        if ($banco_id === null) $errors[] = "Debe seleccionar la cuenta bancaria de donde se debitará el pago en Bs.";
    } else {
        $monto_usd = isset($_POST['monto']) ? parseAmount($_POST['monto']) : 0.0;
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
<link rel="stylesheet" href="../assets/css/style.css">
<script src="../assets/js/amount-input.js"></script>
</head>
<body>
    <nav class="nav-bar">
        <div class="nav-logo"><img src="../assets/img/logo.png" class="nav-logo-img" alt="logo"></div>
        <div class="nav-empresa"><b>Flujo de Caja</b> | <span style="color:#FF7F36;"><?php echo htmlspecialchars($_SESSION['nombre']); ?></span></div>
        <div class="nav-user">
            <button class="btn-volver" onclick="window.location.href='dashboard.php'">Volver</button>
            <a href="../logout.php" class="nav-logout" title="Cerrar sesión">&#x1F511;</a>
        </div>
    </nav>

    <main class="seccion-bancos">
        <section class="bancos-form">
            <h2><?php echo isset($_GET['editar']) ? "Editar Movimiento" : "Agregar Movimiento"; ?></h2>
            <?php if ($msg_flash): ?>
                <div class="msg"><?php echo htmlspecialchars($msg_flash); ?></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="msg"><?php foreach($errors as $e) echo htmlspecialchars($e)."<br>"; ?></div>
            <?php endif; ?>
            <form id="form_agregar" method="POST" onsubmit="return flujocaja_before_submit(this);" class="formulario" autocomplete="off">
                <input type="hidden" name="id" id="form_id" value="">
                <input type="text" name="fecha" id="fecha_input" value="<?php echo date('d/m/Y'); ?>" required placeholder="Fecha (DD/MM/AAAA)">
                <select name="instrumento" id="instrumento" onchange="flujocaja_toggle_instrumento();" required>
                    <option value="">Seleccione instrumento</option>
                    <option value="Bs" selected>Bolívares (Bs)</option>
                    <option value="$">Dólares ($)</option>
                </select>
                <select name="banco_id" id="banco_id" onchange="flujocaja_on_bank_change();">
                    <option value="">-- Seleccione cuenta --</option>
                    <?php foreach($bancos as $b): ?>
                        <option value="<?php echo $b['id']; ?>" data-saldo="<?php echo htmlspecialchars($b['saldo']); ?>"><?php echo htmlspecialchars($b['nombre']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="bank_saldo_info" class="small-note" aria-live="polite"></div>
                <input type="text" name="descripcion" id="descripcion_input" placeholder="Descripción del Pago" required>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div id="currency_label" class="currency-badge">Bs</div>
                    <input type="text" name="monto" id="monto_input" class="amount-input" required placeholder="Monto" style="flex: 1;">
                </div>
                <input type="text" name="tasa" id="tasa_input" value="<?php echo $tasa_actual!==null ? htmlspecialchars(str_replace(',', '.', (string)$tasa_actual)) : ''; ?>" placeholder="Tasa (opcional)">
                <input type="text" name="responsable" id="responsable_input" placeholder="Responsable">
                <input type="text" name="beneficiario" id="beneficiario_input" placeholder="Beneficiario">
                <div class="form-btns">
                    <button type="submit" id="submit_btn" name="guardar" class="btn-principal">Guardar</button>
                    <button type="button" id="cancel_edit_btn" class="btn-cancelar" style="display: none;">Cancelar</button>
                </div>
            </form>
        </section>
        <section class="bancos-lista">
            <h2>Listado de Movimientos</h2>
            <div class="tabla-scroll">
            <table class="tabla-bancos">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Descripción</th>
                        <th>Instrumento</th>
                        <th>Cuenta</th>
                        <th>Monto</th>
                        <th>Resp.</th>
                        <th>Benef.</th>
                        <th class="col-acciones">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($items as $it):
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
                            <td class="td-saldo">
                                <?php if ($it['instrumento'] === 'Bs'):
                                    $v = floatval($it['monto_bs'] ?? 0.0);
                                ?>
                                    <span class="amount" data-raw="<?php echo $v; ?>" data-currency="Bs">Bs <?php echo htmlspecialchars(number_format($v, 2, ',', '.')); ?></span>
                                    <?php if ($it['tasa'] && floatval($it['tasa'])>0):
                                        $usd = $v / floatval($it['tasa']);
                                    ?>
                                        <br><small class="saldo-secundario">(US$ <?php echo htmlspecialchars(number_format($usd,2,',','.')); ?>)</small>
                                    <?php endif; ?>
                                <?php else:
                                    $v = floatval($it['monto_usd'] ?? 0.0);
                                ?>
                                    <span class="amount" data-raw="<?php echo $v; ?>" data-currency="$">$ <?php echo htmlspecialchars(number_format($v,2,',','.')); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $data_resp; ?></td>
                            <td><?php echo $data_ben; ?></td>
                            <td>
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
                                   onclick="loadRecordIntoMainForm(this); return false;">&#9998;</a>

                                <form method="POST" class="form-inline" onsubmit="return confirm('¿Eliminar este movimiento?');">
                                    <input type="hidden" name="id" value="<?php echo $it['id']; ?>">
                                    <button type="submit" name="eliminar" class="btn-accion eliminar" title="Eliminar">&#128465;</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($items) === 0): ?>
                    <tr>
                        <td colspan="8" class="tabla-vacia">Sin movimientos registrados.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
            <?php
                $total_pages = max(1, ceil($total_rows / $perpage));
            ?>
            <?php if ($total_pages > 1): ?>
            <div style="margin-top: 1rem;">
                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                    <a href="flujocaja.php?page=<?php echo $p; ?>" class="btn-volver" style="margin-right: 0.5rem; <?php echo $p==$page ? 'opacity: 0.6;' : ''; ?>"><?php echo $p; ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </section>
    </main>

    <!-- Cargar JS al final -->
    <script src="../assets/js/flujocaja.js?v=<?php echo time(); ?>"></script>
    <script>
    // Usar formato latino: miles = '.' y decimal = ',' (ejemplo: 1.250.520,45)
    // El usuario ingresa con punto como decimal (652485.20) y se muestra en formato latino
    const LATIN_LOCALE = 'es-ES'; // es-ES produce 1.250.520,45
    function nf2_latin() {
        try {
            return new Intl.NumberFormat(LATIN_LOCALE, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        } catch(e) {
            return new Intl.NumberFormat('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
    }
    const NUM_FORMAT = nf2_latin();

    // Format number for display (2 decimals) using Latin format
    function fcj_formatNumber(value) {
        if (value === null || value === undefined || isNaN(Number(value))) return '';
        return NUM_FORMAT.format(Number(value));
    }
    // Currency wrappers
    function fcj_formatBs(value) { return fcj_formatNumber(value); }
    function fcj_formatUsd(value) { return fcj_formatNumber(value); }

    // Unformat string into normalized "1234.56" (dot as decimal, no thousands)
    function fcj_unformat(str) {
        if (str === null || str === undefined) return '';
        str = String(str).trim();
        if (str === '') return '';
        // Remove NBSP and normal spaces
        str = str.replace(/\u00A0/g,'').replace(/\s/g,'');
        // Remove currency symbols and letters
        str = str.replace(/[^\d\-\.,]/g,'');
        
        // If string contains both separators, the last one is decimal
        if (str.indexOf('.') > -1 && str.indexOf(',') > -1) {
            var lastDot = str.lastIndexOf('.');
            var lastComma = str.lastIndexOf(',');
            if (lastComma > lastDot) {
                // Latin format: 1.234,56 -> remove dots, replace comma with dot
                str = str.replace(/\./g, '').replace(',', '.');
            } else {
                // US format: 1,234.56 -> remove commas, keep dot
                str = str.replace(/,/g, '');
            }
        } else if (str.indexOf(',') > -1) {
            // Only comma, assume it's decimal: 1234,56 -> 1234.56
            str = str.replace(',', '.');
        }
        // else: only dot or neither, keep as is (dot is decimal)
        
        // Remove any character except digits, dot, minus
        str = str.replace(/[^0-9\.\-]/g, '');
        // Normalize multiple dots (keep last dot as decimal)
        var parts = str.split('.');
        if (parts.length > 2) {
            var last = parts.pop();
            str = parts.join('') + '.' + last;
        }
        return str;
    }

    // Expose globally
    window.fcj_formatBs = fcj_formatBs;
    window.fcj_formatUsd = fcj_formatUsd;
    window.fcj_unformat = fcj_unformat;

    // Exponer bancos balances para uso en cliente (raw numbers)
    const BANK_BALANCES = <?php
        $map = [];
        foreach ($bancos as $b) {
            $map[(int)$b['id']] = floatval($b['saldo']);
        }
        echo json_encode($map, JSON_NUMERIC_CHECK);
    ?>;

    // Format all .amount spans on load according to forced format
    function formatAllAmountsOnPage() {
        var nodes = document.querySelectorAll('.amount');
        nodes.forEach(function(el) {
            var raw = el.getAttribute('data-raw');
            var curr = el.getAttribute('data-currency') || '';
            if (raw === null || raw === undefined) return;
            var num = Number(raw);
            if (isNaN(num)) return;
            var formatted = fcj_formatNumber(num);
            if (curr === 'Bs') {
                el.textContent = 'Bs ' + formatted;
            } else if (curr === '$') {
                el.textContent = '$ ' + formatted;
            } else {
                el.textContent = formatted;
            }
        });
    }

    // Helper to safely set select value
    function setSelectValue(sel, val) {
        if (!sel) return;
        try { sel.value = val; } catch(e) {}
    }

    // Load a record (anchor element) into the main form for editing
    function loadRecordIntoMainForm(el) {
        if (!el || !el.dataset) return;
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

        document.getElementById('form_id').value = id;
        document.getElementById('fecha_input').value = fecha;
        document.getElementById('descripcion_input').value = descripcion;
        if (document.getElementById('tasa_input')) document.getElementById('tasa_input').value = fcj_formatNumber(Number(tasa)||0);
        if (document.getElementById('responsable_input')) document.getElementById('responsable_input').value = responsable;
        if (document.getElementById('beneficiario_input')) document.getElementById('beneficiario_input').value = beneficiario;

        var instrSel = document.getElementById('instrumento');
        if (instrSel) setSelectValue(instrSel, instrumento);
        var badge = document.getElementById('currency_label');
        if (badge) badge.innerText = instrumento === 'Bs' ? 'Bs' : '$';

        var bancoSel = document.getElementById('banco_id');
        if (bancoSel) setSelectValue(bancoSel, banco);

        var montoInput = document.getElementById('monto_input');
        if (montoInput) {
            if (instrumento === 'Bs') montoInput.value = fcj_formatNumber(Number(montoBs)||0);
            else montoInput.value = fcj_formatNumber(Number(montoUsd)||0);
        }

        var submitBtn = document.getElementById('submit_btn');
        if (submitBtn) {
            submitBtn.name = 'guardar_edicion';
            submitBtn.textContent = 'Actualizar';
        }
        var cancelBtn = document.getElementById('cancel_edit_btn');
        if (cancelBtn) cancelBtn.style.display = 'inline-block';

        document.querySelector('.bancos-form').scrollIntoView({behavior:'smooth', block:'center'});
        flujocaja_toggle_instrumento();
        updateBankSaldoInfo(document.getElementById('form_agregar'), 'bank_saldo_info');
        setTimeout(function(){ var desc = document.getElementById('descripcion_input'); if (desc) desc.focus(); }, 200);
    }

    // Cancel edit mode
    document.addEventListener('DOMContentLoaded', function(){
        var cancelBtn = document.getElementById('cancel_edit_btn');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function(){
                var form = document.getElementById('form_agregar');
                form.reset();
                document.getElementById('form_id').value = '';
                var submitBtn = document.getElementById('submit_btn');
                if (submitBtn) { submitBtn.name = 'guardar'; submitBtn.textContent = 'Guardar'; }
                this.style.display = 'none';
                if (document.getElementById('currency_label')) document.getElementById('currency_label').innerText = 'Bs';
                if (document.getElementById('instrumento')) document.getElementById('instrumento').value = 'Bs';
                flujocaja_toggle_instrumento();
                updateBankSaldoInfo(document.getElementById('form_agregar'), 'bank_saldo_info');
            });
        }

        // Format all amounts shown on the page using forced format
        formatAllAmountsOnPage();
    });

    // Update the small note that shows balance and mark red if insufficient
    function updateBankSaldoInfo(form, infoId) {
        if (!form) return;
        const bankSel = form.querySelector('select[name="banco_id"]');
        const montoInput = form.querySelector('input[name="monto"]');
        const info = document.getElementById(infoId);
        if (!bankSel || !info) return;
        const bid = bankSel.value ? parseInt(bankSel.value,10) : null;
        if (!bid) { info.innerHTML = ''; bankSel.style.border=''; return; }
        const saldo = BANK_BALANCES[bid] || 0;
        const saldoStr = fcj_formatNumber(saldo);
        let montoRaw = 0;
        if (montoInput) {
            var u = fcj_unformat(montoInput.value);
            montoRaw = u ? parseFloat(u) : 0;
        }
        if (montoRaw > saldo) {
            info.innerHTML = 'Saldo disponible: <span style="font-weight:800;color:#8B0000;">Bs ' + saldoStr + '</span>';
            bankSel.style.border = '2px solid #8B0000';
        } else {
            info.innerHTML = 'Saldo disponible: Bs ' + saldoStr;
            bankSel.style.border = '';
        }
    }

    // If insufficient, show alert and focus bank select
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
        const raw = fcj_unformat(montoInput.value);
        const montoVal = raw ? parseFloat(raw) : 0;
        if (montoVal > saldo) {
            alert("LA CUENTA SELECCIONADA NO CUENTA CON SUFICIENTE SALDO");
            bankSel.focus();
            return true;
        }
        return false;
    }

    // Events on monto input to update bank info and check insufficiency
    document.addEventListener('DOMContentLoaded', function(){
        // Initialize amount input with our utility
        initAmountInput('#monto_input');
        
        const monto = document.getElementById('monto_input');
        if (monto) {
            // Additional custom handling for bank saldo validation
            // Override blur to also update bank info
            monto.addEventListener('blur', function(){
                updateBankSaldoInfo(document.getElementById('form_agregar'), 'bank_saldo_info');
                maybeCheckInsufficientAndFocus(document.getElementById('form_agregar'));
            });
        }
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
        if (maybeCheckInsufficientAndFocus(form)) {
            return false;
        }

        // Normalize monto and tasa fields so server receives "." as decimal separator and no thousands
        var montoInput = form.querySelector('input[name="monto"]');
        if (montoInput){
            var u = fcj_unformat(montoInput.value);
            montoInput.value = u; // normalized string like 1234.56 or empty
        }
        var tasa = form.querySelector('input[name="tasa"]');
        if (tasa) {
            var tu = fcj_unformat(tasa.value);
            tasa.value = tu;
        }

        // If we are in edit mode (form_id has value), ensure submit button uses guardar_edicion
        var idval = document.getElementById('form_id').value;
        var submitBtn = document.getElementById('submit_btn');
        if (idval && submitBtn) {
            submitBtn.name = 'guardar_edicion';
        } else if (submitBtn) {
            submitBtn.name = 'guardar';
        }
        return true;
    }
    </script>
</body>
</html>