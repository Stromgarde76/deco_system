<?php
// Archivo: modules/flujocaja.php
// Módulo: Flujo de Caja (ajustes solicitados: reordenar inputs, eliminar "Divisas método",
// mostrar alerta si saldo insuficiente y devolver foco al select de banco)
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

// Guardar (crear)
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

        // Server-side check of bank balance (safety) -> reject if insufficient (no force)
        if ($banco_id !== null) {
            $qb = $conn->prepare("SELECT IFNULL(saldo,0) FROM bancos WHERE id=? AND empresa_id=? LIMIT 1");
            $qb->bind_param('ii', $banco_id, $empresa_id);
            $qb->execute();
            $qb->bind_result($bank_saldo_db);
            $qb->fetch();
            $qb->close();
            if ($monto_bs > floatval($bank_saldo_db)) {
                $errors[] = "La cuenta seleccionada NO cuenta con saldo suficiente (" . number_format(floatval($bank_saldo_db),2,',','.') . " Bs). Cambie la cuenta o ajuste el monto.";
            }
        }
    } else {
        // Instrument is USD: no divisa_metodo field anymore — just USD amount
        $monto_usd = isset($_POST['monto']) ? parse_monto_usd_from_input($_POST['monto']) : 0.0;
    }

    if ($descripcion === '') $errors[] = "Descripción requerida.";

    if (empty($errors)) {
        // INSERT without divisa_metodo column (it was removed)
        $stmt = $conn->prepare("INSERT INTO flujo_caja (empresa_id, fecha, descripcion, instrumento, banco_id, monto_bs, monto_usd, tasa, responsable, beneficiario, creado_por) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        if (!$stmt) {
            $errors[] = "Error de preparación: " . $conn->error;
        } else {
            // TIPOS: i empresa_id, s fecha, s descripcion, s instrumento, i banco_id, d monto_bs, d monto_usd, d tasa, s responsable, s beneficiario, i creado_por
            $stmt->bind_param("isssidddssi", $empresa_id, $fecha, $descripcion, $instrumento, $banco_id, $monto_bs, $monto_usd, $tasa, $responsable, $beneficiario, $usuario_id);
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
    $monto_bs = null;
    $monto_usd = null;
    $tasa = isset($_POST['tasa']) && strlen($_POST['tasa'])>0 ? floatval(str_replace(',', '.', $_POST['tasa'])) : $tasa_actual;
    $responsable = trim($_POST['responsable'] ?? '');
    $beneficiario = trim($_POST['beneficiario'] ?? '');

    if ($instrumento === 'Bs') {
        $banco_id = isset($_POST['banco_id']) && $_POST['banco_id'] !== '' ? (int)$_POST['banco_id'] : null;
        $monto_bs = isset($_POST['monto']) ? parse_monto_bs_from_input($_POST['monto']) : 0.0;
        if ($banco_id === null) $errors[] = "Debe seleccionar la cuenta bancaria de donde se debitará el pago en Bs.";

        // Server-side check of bank balance -> reject if insufficient
        if ($banco_id !== null) {
            $qb = $conn->prepare("SELECT IFNULL(saldo,0) FROM bancos WHERE id=? AND empresa_id=? LIMIT 1");
            $qb->bind_param('ii', $banco_id, $empresa_id);
            $qb->execute();
            $qb->bind_result($bank_saldo_db);
            $qb->fetch();
            $qb->close();
            if ($monto_bs > floatval($bank_saldo_db)) {
                $errors[] = "La cuenta seleccionada NO cuenta con saldo suficiente (" . number_format(floatval($bank_saldo_db),2,',','.') . " Bs). Cambie la cuenta o ajuste el monto.";
            }
        }
    } else {
        $monto_usd = isset($_POST['monto']) ? parse_monto_usd_from_input($_POST['monto']) : 0.0;
    }

    if ($descripcion === '') $errors[] = "Descripción requerida.";

    if (empty($errors)) {
        // UPDATE without divisa_metodo
        $stmt = $conn->prepare("UPDATE flujo_caja SET fecha=?, descripcion=?, instrumento=?, banco_id=?, monto_bs=?, monto_usd=?, tasa=?, responsable=?, beneficiario=?, actualizado_por=?, actualizado_en=NOW() WHERE id=? AND empresa_id=?");
        if (!$stmt) {
            $errors[] = "Error de preparación: " . $conn->error;
        } else {
            // TIPOS: s fecha, s desc, s instr, i banco_id, d monto_bs, d monto_usd, d tasa, s responsable, s beneficiario, i actualizado_por, i id, i empresa_id
            $stmt->bind_param("sssidddssiii", $fecha, $descripcion, $instrumento, $banco_id, $monto_bs, $monto_usd, $tasa, $responsable, $beneficiario, $usuario_id, $id, $empresa_id);
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

            <!-- Formulario Agregar: nuevo orden y etiquetas solicitadas -->
            <section id="form-nuevo" class="card-panel" aria-label="Agregar movimiento">
                <h3 style="margin-top:0;">Agregar movimiento</h3>
                <form id="form_agregar" method="POST" onsubmit="return flujocaja_before_submit(this);" class="formulario">
                    <div class="form-row">
                        <!-- 1. Fecha (sin cambios) -->
                        <label>Fecha (DD/MM/AAAA):
                            <input name="fecha" type="text" value="<?php echo date('d/m/Y'); ?>" required placeholder="DD/MM/AAAA" />
                        </label>

                        <!-- 2. Instrumento (solo etiqueta modificada) -->
                        <label>Instrumento de Pago:
                            <select name="instrumento" id="instrumento" onchange="flujocaja_toggle_instrumento();" required>
                                <option value="Bs">Bolívares (Bs)</option>
                                <option value="$">Dólares ($)</option>
                            </select>
                        </label>
                    </div>

                    <!-- Mantengo la selección de cuenta bancaria justo después del instrumento -->
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

                        <!-- Eliminado: Divisas método (no se muestra más) -->
                    </div>

                    <!-- 3. Descripción (etiqueta modificada) -->
                    <div class="form-row" style="margin-top:0.6rem;">
                        <label>Descripción del Pago:
                            <input type="text" name="descripcion" placeholder="Ej: Pago de bono de transporte a Pedro Pérez" required />
                        </label>

                        <!-- 4. Monto (sin cambios funcionales) -->
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
                                                echo '—';
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
                                            <div class="table-actions" aria-label="Acciones">
                                                <a href="flujocaja.php?action=edit&id=<?php echo $it['id']; ?>"
                                                   class="btn-accion editar" title="Editar">
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

            <?php
            // Edición: estructura con llaves (mantengo orden y etiquetas solicitadas)
            if ($action === 'edit' && isset($_GET['id'])) {
                $id = intval($_GET['id']);
                $s = $conn->prepare("SELECT * FROM flujo_caja WHERE id=? AND empresa_id=? LIMIT 1");
                $s->bind_param('ii', $id, $empresa_id);
                $s->execute();
                $row = $s->get_result()->fetch_assoc();
                $s->close();

                if ($row) {
                    // mostrar formulario de edición
                    ?>
                    <section class="card-panel" style="margin-top:1rem;">
                        <h3>Editar movimiento</h3>
                        <form id="form_editar" method="POST" onsubmit="return flujocaja_before_submit(this);" class="formulario">
                            <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                            <div class="form-row">
                                <label>Fecha:
                                    <input name="fecha" type="text" value="<?php echo htmlspecialchars(sql_to_ddmmyyyy($row['fecha'])); ?>" required />
                                </label>
                                <label>Instrumento de Pago:
                                    <select name="instrumento" id="instrumento_edit" onchange="flujocaja_toggle_instrumento_edit();">
                                        <option value="Bs" <?php if($row['instrumento']==='Bs') echo 'selected'; ?>>Bolívares (Bs)</option>
                                        <option value="$" <?php if($row['instrumento']==='$') echo 'selected'; ?>>Dólares ($)</option>
                                    </select>
                                </label>
                            </div>

                            <div class="form-row" style="margin-top:0.6rem;">
                                <label>Cuenta banco (si Bs):
                                    <select name="banco_id" id="banco_id_edit" onchange="flujocaja_on_bank_change_edit();">
                                        <option value="">-- Seleccione cuenta --</option>
                                        <?php foreach($bancos as $b): ?>
                                            <option value="<?php echo $b['id']; ?>" data-saldo="<?php echo htmlspecialchars($b['saldo']); ?>" <?php if($row['banco_id']==$b['id']) echo 'selected'; ?>><?php echo htmlspecialchars($b['nombre']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div id="bank_saldo_info_edit" class="small-note" aria-live="polite"></div>
                                </label>
                            </div>

                            <div class="form-row" style="margin-top:0.6rem;">
                                <label>Descripción del Pago:
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
                            setTimeout(function(){ if (typeof flujocaja_toggle_instrumento_edit === 'function') flujocaja_toggle_instrumento_edit(); }, 80);
                            setTimeout(function(){ if (typeof updateBankSaldoInfo === 'function') updateBankSaldoInfo(document.getElementById('form_editar'),'bank_saldo_info_edit'); }, 120);
                        })();
                    </script>
                    <?php
                } else {
                    if ($action === 'edit') {
                        echo '<div class="msg card-panel">Registro no encontrado.</div>';
                    }
                }
            }
            ?>

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

    // Helpers UI
    function formatBsDisplay(n) {
        n = Number(n) || 0;
        var parts = n.toFixed(2).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        return 'Bs ' + parts.join(',');
    }

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

    // If insufficient, show alert and focus bank select
    function maybeCheckInsufficientAndFocus(form) {
        if (!form) return;
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
            // focus bank select to let user change it
            bankSel.focus();
            return true;
        }
        return false;
    }

    // Attach events after DOM loaded
    document.addEventListener('DOMContentLoaded', function(){
        // Add listeners to monto inputs to trigger check on blur and Enter
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

        const monto_edit = document.getElementById('monto_input_edit');
        if (monto_edit) {
            monto_edit.addEventListener('blur', function(){
                updateBankSaldoInfo(document.getElementById('form_editar'),'bank_saldo_info_edit');
                maybeCheckInsufficientAndFocus(document.getElementById('form_editar'));
            });
            monto_edit.addEventListener('keydown', function(e){
                if (e.key === 'Enter') {
                    e.preventDefault();
                    setTimeout(function(){
                        updateBankSaldoInfo(document.getElementById('form_editar'),'bank_saldo_info_edit');
                        maybeCheckInsufficientAndFocus(document.getElementById('form_editar'));
                    },50);
                }
            });
        }

        // show initial bank info in edit form if present
        if (document.getElementById('form_editar')) {
            updateBankSaldoInfo(document.getElementById('form_editar'),'bank_saldo_info_edit');
        }
    });

    // Keep earlier helper functions (toggle instrument and pre-submit) available
    function flujocaja_on_bank_change() {
        const form = document.getElementById('form_agregar');
        updateBankSaldoInfo(form, 'bank_saldo_info');
    }
    function flujocaja_on_bank_change_edit() {
        const form = document.getElementById('form_editar');
        updateBankSaldoInfo(form, 'bank_saldo_info_edit');
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
    function flujocaja_toggle_instrumento_edit(){
        var instr = document.getElementById('instrumento_edit').value;
        var banco = document.getElementById('banco_id_edit');
        var label = document.getElementById('currency_label_edit');
        if(instr === 'Bs'){
            banco.disabled = false;
            label.innerText = 'Bs';
        } else {
            banco.disabled = true;
            banco.value = '';
            label.innerText = '$';
            var info = document.getElementById('bank_saldo_info_edit');
            if (info) info.innerHTML = '';
        }
    }

    function flujocaja_before_submit(form){
        // Before submit, ensure if Bs and bank selected, the balance is sufficient
        if (maybeCheckInsufficientAndFocus(form)) {
            // insufficient -> prevent submit
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
        var tasa = form.querySelector('input[name="tasa"]');
        if (tasa) tasa.value = tasa.value.replace(',','.');
        return true;
    }
    </script>
</body>
</html>