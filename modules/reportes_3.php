<?php
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

// Lista de reportes — actualizado para usar "trabajos" en vez de "proyectos"
$reportes = [
    'saldos_bancos'     => 'Saldos por banco',
    'servicios_activos' => 'Servicios activos y costo',
    'trabajos_estado'   => 'Trabajos agrupados por estado',
    'clientes_registrados' => 'Clientes registrados',
    'pagos_pendientes'  => 'Pagos pendientes'
];

$resultados = [];
$campos = [];
$titulo_reporte = '';
$msg = '';
$total_saldos = 0;

// Procesar selección de reporte predefinido
if (isset($_POST['reporte'])) {
    $reporte = $_POST['reporte'];
    $sql = "";
    switch ($reporte) {
        case 'saldos_bancos':
            $sql = "SELECT nombre, tipo_cuenta, saldo FROM bancos WHERE empresa_id=? ORDER BY nombre";
            $titulo_reporte = "Saldos por banco";
            break;
        case 'servicios_activos':
            $sql = "SELECT nombre, tipo, costo FROM servicios WHERE empresa_id=? ORDER BY nombre";
            $titulo_reporte = "Servicios activos y costo";
            break;
        case 'trabajos_estado': // ahora usa la tabla 'trabajos'
            $sql = "SELECT estado, COUNT(*) as cantidad FROM trabajos WHERE empresa_id=? GROUP BY estado";
            $titulo_reporte = "Trabajos agrupados por estado";
            break;
        case 'clientes_registrados':
            $sql = "SELECT nombre, email, telefono FROM clientes WHERE empresa_id=? ORDER BY nombre";
            $titulo_reporte = "Clientes registrados";
            break;
        case 'pagos_pendientes':
            $sql = "SELECT concepto, monto, estado FROM pagos WHERE empresa_id=? AND estado='pendiente'";
            $titulo_reporte = "Pagos pendientes";
            break;
        default:
            $sql = "";
    }

    if ($sql) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            die("Error preparando la consulta: " . $conn->error);
        }
        // Vincular empresa_id si la consulta lo usa (tiene '?')
        if (strpos($sql, '?') !== false) {
            $stmt->bind_param('i', $empresa_id);
        }
        if (!$stmt->execute()) {
            die("Error ejecutando la consulta: " . $stmt->error);
        }
        $res = $stmt->get_result();
        $resultados = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if ($resultados) $campos = array_keys($resultados[0]);

        // Totalizar si es el reporte de saldos por banco
        if ($titulo_reporte === 'Saldos por banco') {
            $total_saldos = 0.0;
            foreach ($resultados as $row) {
                if (isset($row['saldo'])) {
                    $total_saldos += floatval($row['saldo']);
                }
            }
        }
    }
}

// Procesar consulta personalizada (editor sencillo)
if (isset($_POST['consulta_personal'])) {
    $tabla = $_POST['tabla'];
    $campos_editor = trim($_POST['campos'] ?? '*');
    $limite = intval($_POST['limite'] ?? 30);
    $msg = '';

    // Seguridad: permitir tablas conocidas (incluimos 'trabajos' y mantenemos 'proyectos' por compatibilidad)
    $tablas_permitidas = ['bancos','clientes','trabajos','proyectos','servicios','pagos'];

    if (!in_array($tabla, $tablas_permitidas, true)) {
        $msg = "Tabla no permitida.";
    } else {
        // Sanitizar campos: permitir '*' o lista de identificadores simples (letras, números, guión bajo y comas)
        if ($campos_editor !== '*' && !preg_match('/^[a-zA-Z0-9_,\s]+$/', $campos_editor)) {
            $msg = "Campos no permitidos en la consulta.";
        } else {
            // Construir SQL de forma segura: el nombre de tabla ya está validado contra la lista
            $sql = "SELECT $campos_editor FROM `$tabla` WHERE empresa_id=? LIMIT ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                die("Error preparando la consulta: " . $conn->error);
            }
            // bind empresa_id y límite (enteros)
            $stmt->bind_param('ii', $empresa_id, $limite);
            if (!$stmt->execute()) {
                die("Error ejecutando la consulta: " . $stmt->error);
            }
            $res = $stmt->get_result();
            $resultados = $res->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $titulo_reporte = "Consulta personalizada: " . htmlspecialchars($tabla);
            if ($resultados) $campos = array_keys($resultados[0]);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reportes</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        .align-right { text-align: right !important; }
        .bold { font-weight: bold !important; }
        .total-row td {
            background: #e5f2ff;
            font-weight: bold;
            color: #003399;
        }

        /* ================= PRINT STYLES ================= */
        /* We'll hide navigation and interactive controls in print and only show the report content */
        @media print {
            /* Hide everything first */
            body * { visibility: hidden; }

            /* Make the printable area visible */
            #print-area, #print-area * { visibility: visible; }

            /* Position printable area at the top-left for printing */
            #print-area { position: absolute; left: 0; top: 0; width: 100%; }

            /* Make tables more print-friendly */
            table.reporte-tabla {
                border-collapse: collapse !important;
                width: 100% !important;
                font-size: 12pt !important;
            }
            table.reporte-tabla th, table.reporte-tabla td {
                border: 1px solid #ddd !important;
                padding: 8px !important;
            }

            /* Hide elements you don't want on print */
            .no-print { display: none !important; }

            /* Page margins */
            @page { margin: 15mm; }
        }

        /* Optional simple print-friendly header inside printable area */
        .print-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:0.6rem; }
        .print-title { font-size:1.1rem; font-weight:800; color:#19396b; }
    </style>
</head>
<body>
    <nav class="nav-bar no-print">
        <div class="nav-logo">
            <img src="../assets/img/logo.png" alt="Logo" class="nav-logo-img">
        </div>
        <div class="nav-empresa"><b>Reportes</b></div>
        <div class="nav-user">
            <button type="button" class="btn-volver" onclick="window.location.href='dashboard.php'">Volver</button>
        </div>
    </nav>

    <main style="display: flex; flex-direction: column; align-items: center; min-height: 90vh;">
        <section class="reporte-form" style="width:100%;max-width:600px;">
            <h2 style="font-size:1.3rem; color:#19396b; font-weight:bold; margin-bottom: 1.1rem;">Reportes generales</h2>
            <form method="POST" style="display:flex; gap:1rem; flex-wrap:wrap; align-items:center;">
                <select name="reporte" required style="flex:1;min-width:200px;">
                    <option value="">Seleccione un reporte</option>
                    <?php foreach($reportes as $key => $desc): ?>
                        <option value="<?php echo $key; ?>" <?php if(isset($_POST['reporte']) && $_POST['reporte']==$key) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($desc); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-principal no-print" style="width:130px;">Ver reporte</button>
            </form>
        </section>

        <section class="editor-form" style="width:100%;max-width:600px;">
            <h2 style="font-size:1.3rem; color:#19396b; font-weight:bold; margin-bottom: 1.1rem;">Editor de reportes (consulta personalizada)</h2>
            <form method="POST" style="display:flex; flex-wrap:wrap; gap:1rem; align-items:center;">
                <label style="flex:1; min-width:140px;">Tabla:
                    <select name="tabla" required style="width:100%;">
                        <option value="">Seleccione tabla</option>
                        <option value="bancos">Bancos</option>
                        <option value="clientes">Clientes</option>
                        <option value="trabajos" <?php if(isset($_POST['tabla']) && $_POST['tabla']=='trabajos') echo 'selected'; ?>>Trabajos</option>
                        <option value="proyectos" <?php if(isset($_POST['tabla']) && $_POST['tabla']=='proyectos') echo 'selected'; ?>>Proyectos (antiguo)</option>
                        <option value="servicios">Servicios</option>
                        <option value="pagos">Pagos</option>
                    </select>
                </label>
                <label style="flex:2; min-width:160px;">Campos (ej: nombre, saldo) o *:
                    <input type="text" name="campos" placeholder="*" value="<?php echo isset($_POST['campos']) ? htmlspecialchars($_POST['campos']) : '*'; ?>" style="width:100%;" />
                </label>
                <label style="flex:1; min-width:90px;">Límite:
                    <input type="number" name="limite" value="<?php echo isset($_POST['limite']) ? intval($_POST['limite']) : 30; ?>" min="1" max="100" style="width:100%;" />
                </label>
                <button type="submit" name="consulta_personal" class="btn-principal no-print" style="width:130px;">Ejecutar</button>
            </form>
        </section>

        <?php if($titulo_reporte): ?>
        <section class="reporte-resultado" style="width:100%;max-width:900px;">
            <div id="print-area">
                <div class="print-header no-print" style="margin-bottom:0.8rem;">
                    <div class="print-title"><?php echo htmlspecialchars($titulo_reporte); ?></div>
                    <div style="display:flex; gap:0.5rem;">
                        <button type="button" onclick="window.print()" class="btn-principal no-print">Imprimir</button>
                        <button type="button" onclick="exportTableToCSV()" class="btn-principal no-print">Exportar CSV</button>
                    </div>
                </div>

                <h3 style="font-size:1.2rem; color:#003399; font-weight:bold; margin:0 0 0.6rem;"><?php echo htmlspecialchars($titulo_reporte); ?></h3>

                <?php if($resultados): ?>
                <div class="tabla-scroll">
                    <table class="reporte-tabla">
                        <thead>
                            <tr>
                                <?php
                                foreach($campos as $c):
                                    $col_class = '';
                                    if ($titulo_reporte == 'Saldos por banco' && strtolower($c) == 'saldo') {
                                        $col_class = ' class="align-right"';
                                    }
                                ?>
                                    <th<?php echo $col_class; ?>><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$c))); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($resultados as $row): ?>
                            <tr>
                                <?php foreach($campos as $c): ?>
                                    <?php
                                    if ($titulo_reporte == 'Saldos por banco' && strtolower($c) == 'saldo') {
                                        $valor = number_format($row[$c], 2, ',', '.');
                                        echo '<td class="align-right bold">'.htmlspecialchars($valor).'</td>';
                                    } else {
                                        echo '<td>'.htmlspecialchars($row[$c] ?? '').'</td>';
                                    }
                                    ?>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>

                            <?php if ($titulo_reporte == 'Saldos por banco'): ?>
                            <tr class="total-row">
                                <?php
                                // Buscar índice de la columna "saldo" (case-insensitive)
                                $lowered = array_map('strtolower', $campos);
                                $saldo_index = array_search('saldo', $lowered);
                                $cols = count($campos);
                                for ($i = 0; $i < $cols; $i++) {
                                    if ($i === $saldo_index) {
                                        echo '<td class="align-right bold">'.number_format($total_saldos, 2, ',', '.').'</td>';
                                    } else {
                                        echo '<td></td>';
                                    }
                                }
                                ?>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <div class="msg">No hay resultados para este reporte.</div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if($msg): ?>
            <div class="msg"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <div class="spacer-bottom" style="height:120px;"></div>
    </main>

    <script>
    // Export current resultado table (print-area) to CSV.
    // Simple implementation that will export visible table headers and body cells.
    function download(filename, content) {
        var blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
        if (navigator.msSaveBlob) { // IE 10+
            navigator.msSaveBlob(blob, filename);
        } else {
            var link = document.createElement("a");
            if (link.download !== undefined) {
                var url = URL.createObjectURL(blob);
                link.setAttribute("href", url);
                link.setAttribute("download", filename);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }
    }

    function exportTableToCSV(filename) {
        filename = filename || ('reporte-' + new Date().toISOString().slice(0,10) + '.csv');
        var table = document.querySelector('#print-area table.reporte-tabla');
        if (!table) {
            alert('No hay tabla para exportar.');
            return;
        }
        var rows = Array.from(table.querySelectorAll('tr'));
        var csv = [];
        rows.forEach(function(row) {
            var cols = Array.from(row.querySelectorAll('th,td')).map(function(cell) {
                var text = cell.innerText.replace(/\n/g, ' ').trim();
                // Escape double quotes by doubling them, and wrap fields containing commas/quotes/newlines in quotes
                if (text.indexOf('"') !== -1) text = text.replace(/"/g, '""');
                if (text.indexOf(',') !== -1 || text.indexOf('"') !== -1) text = '"' + text + '"';
                return text;
            });
            csv.push(cols.join(','));
        });
        download(filename, csv.join('\r\n'));
    }
    </script>
</body>
</html>