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
$empresa_id = $_SESSION['empresa_id'];

$reportes = [
    'saldos_bancos' => 'Saldos por banco',
    'servicios_activos' => 'Servicios activos y costo',
    'proyectos_estado' => 'Proyectos agrupados por estado',
    'clientes_registrados' => 'Clientes registrados',
    'pagos_pendientes' => 'Pagos pendientes'
];

$resultados = [];
$campos = [];
$titulo_reporte = '';
$msg = '';
$total_saldos = 0;

// Procesar selección de reporte predefinido
if (isset($_POST['reporte'])) {
    $reporte = $_POST['reporte'];
    switch ($reporte) {
        case 'saldos_bancos':
            $sql = "SELECT nombre, tipo_cuenta, saldo FROM bancos WHERE empresa_id=? ORDER BY nombre";
            $titulo_reporte = "Saldos por banco";
            break;
        case 'servicios_activos':
            $sql = "SELECT nombre, tipo, costo FROM servicios WHERE empresa_id=? ORDER BY nombre";
            $titulo_reporte = "Servicios activos y costo";
            break;
        case 'proyectos_estado':
            $sql = "SELECT estado, COUNT(*) as cantidad FROM proyectos WHERE empresa_id=? GROUP BY estado";
            $titulo_reporte = "Proyectos agrupados por estado";
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
        // Solo bind_param si hay un parámetro ?, para evitar error si alguna consulta no lo necesita
        if (strpos($sql, '?') !== false) {
            $stmt->bind_param('i', $empresa_id);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $resultados = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if ($resultados) $campos = array_keys($resultados[0]);
        // Totalizar si es el reporte de saldos por banco
        if ($titulo_reporte == 'Saldos por banco') {
            foreach ($resultados as $row) {
                $total_saldos += floatval($row['saldo']);
            }
        }
    }
}

// Procesar consulta personalizada (editor sencillo)
if (isset($_POST['consulta_personal'])) {
    $tabla = $_POST['tabla'];
    $campos_editor = $_POST['campos'] ?? '*';
    $limite = intval($_POST['limite'] ?? 30);
    $msg = '';
    // Seguridad: solo permitimos ciertas tablas y campos
    $tablas_permitidas = ['bancos','clientes','proyectos','servicios','pagos'];
    if (!in_array($tabla, $tablas_permitidas)) {
        $msg = "Tabla no permitida.";
    } else {
        $sql = "SELECT $campos_editor FROM $tabla WHERE empresa_id=? LIMIT $limite";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            die("Error preparando la consulta: " . $conn->error);
        }
        $stmt->bind_param('i', $empresa_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $resultados = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $titulo_reporte = "Consulta personalizada: $tabla";
        if ($resultados) $campos = array_keys($resultados[0]);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reportes</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .align-right { text-align: right !important; }
        .bold { font-weight: bold !important; }
        .total-row td {
            background: #e5f2ff;
            font-weight: bold;
            color: #003399;
        }
    </style>
</head>
<body>
    <nav class="nav-bar">
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
                            <?php echo $desc; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-principal" style="width:130px;">Ver reporte</button>
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
                        <option value="proyectos">Proyectos</option>
                        <option value="servicios">Servicios</option>
                        <option value="pagos">Pagos</option>
                    </select>
                </label>
                <label style="flex:2; min-width:160px;">Campos (ej: nombre, saldo) o *:
                    <input type="text" name="campos" placeholder="*" style="width:100%;" />
                </label>
                <label style="flex:1; min-width:90px;">Límite:
                    <input type="number" name="limite" value="30" min="1" max="100" style="width:100%;" />
                </label>
                <button type="submit" name="consulta_personal" class="btn-principal" style="width:130px;">Ejecutar</button>
            </form>
        </section>
        <?php if($titulo_reporte): ?>
        <section class="reporte-resultado" style="width:100%;max-width:900px;">
            <h3 style="font-size:1.2rem; color:#003399; font-weight:bold;"><?php echo htmlspecialchars($titulo_reporte); ?></h3>
            <?php if($resultados): ?>
            <div class="tabla-scroll">
                <table class="reporte-tabla">
                    <thead>
                        <tr>
                            <?php
                            // Detectar si la columna es saldo para agregar clase de alineación
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
                                // Si es el reporte de saldos por banco y es la columna saldo, alineamos, formateamos y ponemos en negrita
                                if ($titulo_reporte == 'Saldos por banco' && strtolower($c) == 'saldo') {
                                    $valor = number_format($row[$c], 2, ',', '.');
                                    echo '<td class="align-right bold">'.htmlspecialchars($valor).'</td>';
                                } else {
                                    echo '<td>'.htmlspecialchars($row[$c]).'</td>';
                                }
                                ?>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php if ($titulo_reporte == 'Saldos por banco'): ?>
                        <tr class="total-row">
                            <?php
                            // Celdas vacías para columnas antes de saldo
                            $saldo_index = array_search('saldo', array_map('strtolower', $campos));
                            for ($i = 0; $i < $saldo_index; $i++) {
                                echo '<td></td>';
                            }
                            ?>
                            <td class="align-right">Total:</td>
                            <td class="align-right bold"><?php echo number_format($total_saldos, 2, ',', '.'); ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="msg">No hay resultados para este reporte.</div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        <?php if($msg): ?>
            <div class="msg"><?php echo $msg; ?></div>
        <?php endif; ?>
    </main>
</body>
</html>