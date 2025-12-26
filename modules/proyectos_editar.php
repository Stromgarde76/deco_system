<?php
// Archivo: modules/proyectos_editar.php
session_start();
require_once "../config/db.php";

// Verifica sesión y empresa seleccionada
if (!isset($_SESSION['usuario'])) {
    header("Location: ../index.php");
    exit();
}
if (!isset($_SESSION['empresa_id'])) {
    header("Location: ../select_empresa.php");
    exit();
}

$empresa_id = $_SESSION['empresa_id'];
$mensaje = "";
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Obtener datos actuales del proyecto a editar
$stmt = $conn->prepare("SELECT * FROM proyectos WHERE id_proy=? AND empresa_id=? LIMIT 1");
$stmt->bind_param("ii", $id, $empresa_id);
$stmt->execute();
$result = $stmt->get_result();
$proyecto = $result->fetch_assoc();
$stmt->close();

if (!$proyecto) {
    echo "<p style='color:red; text-align:center;'>Proyecto no encontrado o no pertenece a tu empresa.</p>";
    echo "<p style='text-align:center;'><a href='proyectos.php'>Volver al listado</a></p>";
    exit();
}

// Procesar edición
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'editar') {
    $descripcion = trim($_POST['descripcion']);
    $cliente_id = intval($_POST['cliente_id']);
    $contratista_id = intval($_POST['contratista_id']);
    $fecha_inicio = $_POST['fecha_inicio'];
    $fecha_culminacion = !empty($_POST['fecha_culminacion']) ? $_POST['fecha_culminacion'] : null;
    $estado = $_POST['estado'];
    $moneda = $_POST['moneda'];
    $tasa_cambio = ($moneda === 'USD') ? floatval($_POST['tasa_cambio']) : null;
    // Para guardar, eliminamos puntos y dejamos coma solo para decimales
    $monto_str = str_replace('.', '', $_POST['monto_inicial']);
    $monto_str = str_replace(',', '.', $monto_str);
    $monto_inicial = floatval($monto_str);

    $stmt = $conn->prepare("UPDATE proyectos SET descripcion=?, cliente_id=?, contratista_id=?, fecha_inicio=?, fecha_culminacion=?, estado=?, monto_inicial=?, moneda=?, tasa_cambio=? WHERE id_proy=? AND empresa_id=?");
    $stmt->bind_param("siisssdssii", $descripcion, $cliente_id, $contratista_id, $fecha_inicio, $fecha_culminacion, $estado, $monto_inicial, $moneda, $tasa_cambio, $id, $empresa_id);
    if ($stmt->execute()) {
        $mensaje = "Proyecto actualizado correctamente.";
        // Refrescar datos del proyecto
        $proyecto['descripcion'] = $descripcion;
        $proyecto['cliente_id'] = $cliente_id;
        $proyecto['contratista_id'] = $contratista_id;
        $proyecto['fecha_inicio'] = $fecha_inicio;
        $proyecto['fecha_culminacion'] = $fecha_culminacion;
        $proyecto['estado'] = $estado;
        $proyecto['monto_inicial'] = $monto_inicial;
        $proyecto['moneda'] = $moneda;
        $proyecto['tasa_cambio'] = $tasa_cambio;
    } else {
        $mensaje = "Error al actualizar: " . $conn->error;
    }
    $stmt->close();
}

// Obtener clientes y contratistas para los select
$clientes = [];
$res = $conn->query("SELECT id, nombre FROM clientes WHERE empresa_id = $empresa_id");
while ($row = $res->fetch_assoc()) $clientes[] = $row;

$contratistas = [];
$res = $conn->query("SELECT id, nombre FROM contratistas WHERE empresa_id = $empresa_id");
while ($row = $res->fetch_assoc()) $contratistas[] = $row;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Proyecto</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
    .form-row {
        display: flex;
        align-items: center;
        margin-bottom: 0.9rem;
        gap: 12px;
    }
    .form-label {
        font-weight: bold;
        color: #19396b;
        min-width: 160px;
        text-align: right;
        margin-bottom: 0;
        margin-top: 0;
        font-size: 13px;
        letter-spacing: 0.1px;
        justify-self: flex-end;
    }
    .form-row input[type="text"],
    .form-row input[type="date"],
    .form-row select,
    .form-row input[type="number"] {
        flex: 70 70 200px;
        margin-bottom: 0;
        font-size: 13px;
        padding: 8px 10px;
    }
    .input-monto {
        text-align: right;
        font-weight: bold !important;
        letter-spacing: 1px;
        font-size: 15px !important;
        color: #19396b;
        background: #e9eff6;
        border-radius: 7px;
        border: none;
        box-shadow: 0 1px 4px #19396b08;
    }
    .input-tasa {
        text-align: right;
        font-weight: bold;
        color: #37659a;
        background: #e9eff6;
        border-radius: 7px;
        border: none;
        box-shadow: 0 1px 4px #19396b08;
        font-size: 13px;
        padding: 8px 10px;
    }
    @media (max-width: 700px) {
        .form-row {
            flex-direction: column;
            align-items: stretch;
        }
        .form-label {
            min-width: 0;
            text-align: left;
            margin-bottom: 4px;
        }
    }
    </style>
    <script>
    function formatMontoInput(input) {
        let value = input.value.replace(/[^0-9,\.]/g, '');
        value = value.replace(/\./g, ''); // quita puntos previos
        value = value.replace(',', '.'); // para parsear float

        if (value === '') return;
        let num = parseFloat(value);
        if (isNaN(num)) return;

        // Formatea con separador miles y 2 decimales (español)
        let formatted = num.toLocaleString('es-ES', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        input.value = formatted;
    }
    function actualizarTasa() {
        var moneda = document.getElementById('moneda').value;
        var tasa = document.getElementById('tasa_cambio');
        if (moneda === 'USD') {
            tasa.style.display = '';
            tasa.required = true;
        } else {
            tasa.style.display = 'none';
            tasa.required = false;
        }
    }
    window.addEventListener('DOMContentLoaded', function() {
        var montoInput = document.getElementById("input-monto");
        if (montoInput) {
            formatMontoInput(montoInput); // Formatea al cargar
            montoInput.addEventListener('input', function() {
                formatMontoInput(this);
            });
        }
        // Actualizar visibilidad de tasa de cambio
        var monedaSel = document.getElementById('moneda');
        if (monedaSel) {
            monedaSel.addEventListener('change', actualizarTasa);
            actualizarTasa();
        }
    });
    </script>
</head>
<body>
    <nav class="nav-bar">
        <div class="nav-logo">
            <img src="../assets/img/logo.png" alt="Logo" class="nav-logo-img">
        </div>
        <div class="nav-empresa"><b>Editar Proyecto</b></div>
        <div class="nav-user">
            <button type="button" class="btn-volver" onclick="window.location.href='proyectos.php'">Volver</button>
            <a href="../logout.php" class="nav-logout" title="Cerrar sesión">&#x1F511;</a>
        </div>
    </nav>
    <main>
        <div style="display:flex; justify-content:center; align-items:flex-start; min-height:80vh; padding:2rem 0;">
            <div class="proyectos-formulario">
                <h2 style="margin-bottom:1.4rem;">Editar Proyecto</h2>
                <?php if ($mensaje): ?>
                    <div class="msg-info"><?php echo $mensaje; ?></div>
                <?php endif; ?>
                <form method="post" class="formulario" autocomplete="off">
                    <input type="hidden" name="accion" value="editar">

                    <div class="form-row">
                        <label class="form-label" for="id_proy">ID Proyecto</label>
                        <input type="text" name="id_proy" id="id_proy" value="<?php echo htmlspecialchars($proyecto['id_proy']); ?>" readonly placeholder="ID Proyecto">
                    </div>

                    <div class="form-row">
                        <label class="form-label" for="descripcion">Descripción / Nombre</label>
                        <input type="text" name="descripcion" id="descripcion" required placeholder="Descripción / Nombre" value="<?php echo htmlspecialchars($proyecto['descripcion']); ?>">
                    </div>

                    <div class="form-row">
                        <label class="form-label" for="cliente_id">Cliente</label>
                        <select name="cliente_id" id="cliente_id" required>
                            <option value="">Cliente</option>
                            <?php foreach ($clientes as $cli): ?>
                            <option value="<?php echo $cli['id']; ?>" <?php if($cli['id'] == $proyecto['cliente_id']) echo "selected"; ?>><?php echo htmlspecialchars($cli['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <label class="form-label" for="contratista_id">Contratista</label>
                        <select name="contratista_id" id="contratista_id" required>
                            <option value="">Contratista</option>
                            <?php foreach ($contratistas as $con): ?>
                            <option value="<?php echo $con['id']; ?>" <?php if($con['id'] == $proyecto['contratista_id']) echo "selected"; ?>><?php echo htmlspecialchars($con['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <label class="form-label" for="fecha_inicio">Fecha de inicio</label>
                        <input type="date" name="fecha_inicio" id="fecha_inicio" required placeholder="Fecha de inicio" value="<?php echo htmlspecialchars($proyecto['fecha_inicio']); ?>">
                    </div>

                    <div class="form-row">
                        <label class="form-label" for="fecha_culminacion">Fecha de culminación</label>
                        <input type="date" name="fecha_culminacion" id="fecha_culminacion" placeholder="Fecha de culminación" value="<?php echo htmlspecialchars($proyecto['fecha_culminacion']); ?>">
                    </div>

                    <div class="form-row">
                        <label class="form-label" for="estado">Estado</label>
                        <select name="estado" id="estado" required>
                            <option value="Evaluación" <?php if($proyecto['estado']=='Evaluación') echo 'selected'; ?>>Evaluación</option>
                            <option value="Activo" <?php if($proyecto['estado']=='Activo') echo 'selected'; ?>>Activo</option>
                            <option value="Finalizado" <?php if($proyecto['estado']=='Finalizado') echo 'selected'; ?>>Finalizado</option>
                            <option value="En pausa" <?php if($proyecto['estado']=='En pausa') echo 'selected'; ?>>En pausa</option>
                        </select>
                    </div>

                    <!-- BLOQUE MULTIMONEDA -->
                    <div class="form-row">
                        <label class="form-label" for="moneda">Moneda</label>
                        <select name="moneda" id="moneda" required>
                            <option value="VES" <?php if($proyecto['moneda']=='VES') echo 'selected'; ?>>Bolívares</option>
                            <option value="USD" <?php if($proyecto['moneda']=='USD') echo 'selected'; ?>>Dólares</option>
                        </select>
                    </div>

                    <div class="form-row" id="tasa_cambio_row">
                        <label class="form-label" for="tasa_cambio">Tasa Cambio $ a Bs</label>
                        <input type="number" step="0.0001" min="0" name="tasa_cambio" id="tasa_cambio" class="input-tasa" placeholder="Tasa $ a Bs" value="<?php echo htmlspecialchars($proyecto['tasa_cambio']); ?>">
                    </div>
                    <!-- FIN BLOQUE MULTIMONEDA -->

                    <div class="form-row">
                        <label class="form-label" for="input-monto">Monto Inicial</label>
                        <input type="text" id="input-monto" name="monto_inicial" class="input-monto" required placeholder="Monto Inicial" value="<?php echo number_format($proyecto['monto_inicial'], 2, ',', '.'); ?>">
                    </div>

                    <button type="submit" class="btn-principal">Guardar Cambios</button>
                </form>
            </div>
        </div>
    </main>
</body>
</html>