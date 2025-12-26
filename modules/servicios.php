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
$msg = "";

// Generar siguiente cod_serv
function generarCodigoServicio($conn, $empresa_id) {
    $sql = "SELECT cod_serv FROM servicios WHERE empresa_id=? ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $empresa_id);
    $stmt->execute();
    $stmt->bind_result($last_cod);
    $last_cod = null;
    $stmt->fetch();
    $stmt->close();
    if ($last_cod && preg_match('/^SRV(\d{4,})$/', $last_cod, $m)) {
        $num = intval($m[1]) + 1;
    } else {
        $num = 1;
    }
    return 'SRV' . str_pad($num, 4, '0', STR_PAD_LEFT);
}

// --- AGREGAR SERVICIO ---
if (isset($_POST['accion']) && $_POST['accion'] === 'agregar') {
    $nombre = trim($_POST['nombre']);
    $tipo = $_POST['tipo'];
    $costo = floatval(str_replace(',', '.', $_POST['costo']));
    $descripcion = trim($_POST['descripcion']);
    $cod_serv = generarCodigoServicio($conn, $empresa_id);

    $sql = "INSERT INTO servicios (empresa_id, cod_serv, nombre, tipo, costo, descripcion) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('isssds', $empresa_id, $cod_serv, $nombre, $tipo, $costo, $descripcion);
    if ($stmt->execute()) {
        $msg = "Servicio agregado exitosamente.";
    } else {
        $msg = "Error al agregar servicio.";
    }
    $stmt->close();
}

// --- EDITAR SERVICIO ---
if (isset($_POST['accion']) && $_POST['accion'] === 'editar' && isset($_POST['servicio_id'])) {
    $servicio_id = intval($_POST['servicio_id']);
    $nombre = trim($_POST['nombre']);
    $tipo = $_POST['tipo'];
    $costo = floatval(str_replace(',', '.', $_POST['costo']));
    $descripcion = trim($_POST['descripcion']);

    $sql = "UPDATE servicios SET nombre=?, tipo=?, costo=?, descripcion=? WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssdsii', $nombre, $tipo, $costo, $descripcion, $servicio_id, $empresa_id);
    if ($stmt->execute()) {
        $msg = "Servicio actualizado.";
    } else {
        $msg = "Error al actualizar servicio.";
    }
    $stmt->close();
}

// --- ELIMINAR SERVICIO ---
if (isset($_POST['accion']) && $_POST['accion'] === 'eliminar' && isset($_POST['servicio_id'])) {
    $servicio_id = intval($_POST['servicio_id']);
    $sql = "DELETE FROM servicios WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $servicio_id, $empresa_id);
    if ($stmt->execute()) {
        $msg = "Servicio eliminado.";
    } else {
        $msg = "Error al eliminar servicio.";
    }
    $stmt->close();
}

// --- OBTENER DATOS DE UN SERVICIO PARA EDITAR ---
$servicio_editar = null;
if (isset($_GET['editar'])) {
    $servicio_id = intval($_GET['editar']);
    $sql = "SELECT * FROM servicios WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $servicio_id, $empresa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $servicio_editar = $result->fetch_assoc();
    $stmt->close();
}

// --- LISTAR SERVICIOS ---
$servicios = [];
$sql = "SELECT * FROM servicios WHERE empresa_id=? ORDER BY nombre ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $empresa_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $servicios[] = $row;
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Servicios</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .servicios-flex-container {
            display: flex;
            gap: 2rem;
            align-items: flex-start;
        }
        .servicios-form, .servicios-lista {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 0 8px #0001;
            padding: 1.2rem 1.5rem;
        }
        .servicios-form {
            min-width: 330px;
            max-width: 350px;
            flex: 0 0 350px;
        }
        .servicios-lista {
            flex: 1 1 0%;
            min-width: 0;
        }
        .tabla-scroll {
            max-width: 100%;
            overflow-x: auto;
        }
        .tabla-servicios {
            width: 100%;
            border-collapse: collapse;
        }
        .tabla-servicios th, .tabla-servicios td {
            font-size: 16px;
            text-align: center;
            padding: 10px 8px;
            border-bottom: 1px solid #e0e0e0;
        }
        .tabla-servicios th {
            background: #f4f4f4;
            color: #003;
            font-weight: bold;
        }
        .tabla-servicios td {
            color: #003;
        }
        .tabla-servicios .td-costo {
            font-family: monospace;
            font-size: 15px;
            font-weight: bold;
            color: #003399;
            background: #f0f7fa;
            border-radius: 6px;
            text-align: right !important;
        }
        .col-acciones {
            min-width: 85px;
        }
        /* Espaciado proporcional para cada columna */
        .tabla-servicios th:nth-child(1), .tabla-servicios td:nth-child(1) { width: 11%; min-width: 80px; }
        .tabla-servicios th:nth-child(2), .tabla-servicios td:nth-child(2) { width: 24%; min-width: 120px; }
        .tabla-servicios th:nth-child(3), .tabla-servicios td:nth-child(3) { width: 13%; min-width: 90px; }
        .tabla-servicios th:nth-child(4), .tabla-servicios td:nth-child(4) { width: 15%; min-width: 80px; }
        .tabla-servicios th:nth-child(5), .tabla-servicios td:nth-child(5) { width: 27%; min-width: 170px; text-align: left;}
        .tabla-servicios th:nth-child(6), .tabla-servicios td:nth-child(6) { width: 10%; min-width: 80px; }
        @media (max-width: 900px) {
            .servicios-flex-container {
                flex-direction: column;
                gap: 1.5rem;
            }
            .servicios-form,
            .formulario.form-servicios input,
            .formulario.form-servicios select,
            .formulario.form-servicios textarea,
            .formulario.form-servicios .form-btns {
                width: 100% !important;
                max-width: 100% !important;
            }
            .tabla-servicios th, .tabla-servicios td {
                font-size: 13px;
                padding: 6px 3px;
            }
        }
        .formulario.form-servicios input,
        .formulario.form-servicios select,
        .formulario.form-servicios textarea {
            width: 260px !important;
            max-width: 100%;
            display: block;
            box-sizing: border-box;
        }
    </style>
</head>
<body>
    <nav class="nav-bar">
        <div class="nav-logo">
            <img src="../assets/img/logo.png" alt="Logo" class="nav-logo-img">
        </div>
        <div class="nav-empresa"><b>Servicios</b> | <span style="color:#FF7F36;"><?php echo htmlspecialchars($_SESSION['nombre']); ?></span></div>
        <div class="nav-user">
            <button type="button" class="btn-volver" onclick="window.location.href='dashboard.php'">Volver</button>
            <a href="../logout.php" class="nav-logout" title="Cerrar sesión">&#x1F511;</a>
        </div>
    </nav>
    <main>
        <div class="servicios-flex-container">
            <section class="servicios-form">
                <h2><?php echo $servicio_editar ? "Editar Servicio" : "Agregar Servicio"; ?></h2>
                <?php if ($msg): ?>
                    <div class="msg"><?php echo $msg; ?></div>
                <?php endif; ?>
                <form method="POST" class="formulario form-servicios" autocomplete="off">
                    <input type="hidden" name="accion" value="<?php echo $servicio_editar ? 'editar' : 'agregar'; ?>">
                    <?php if ($servicio_editar): ?>
                        <input type="hidden" name="servicio_id" value="<?php echo $servicio_editar['id']; ?>">
                    <?php endif; ?>
                    <!-- Código de servicio solo visible si se está editando -->
                    <?php if ($servicio_editar): ?>
                        <input type="text" value="<?php echo htmlspecialchars($servicio_editar['cod_serv']); ?>" disabled placeholder="Código del servicio">
                    <?php endif; ?>
                    <input type="text" name="nombre" value="<?php echo $servicio_editar['nombre'] ?? ''; ?>" required placeholder="Nombre del servicio">
                    <select name="tipo" required>
                        <option value="">Tipo de servicio</option>
                        <option value="eventual" <?php if(($servicio_editar['tipo'] ?? '')=='eventual') echo 'selected'; ?>>Eventual</option>
                        <option value="quincenal" <?php if(($servicio_editar['tipo'] ?? '')=='quincenal') echo 'selected'; ?>>Quincenal</option>
                        <option value="mensual" <?php if(($servicio_editar['tipo'] ?? '')=='mensual') echo 'selected'; ?>>Mensual</option>
                        <option value="anual" <?php if(($servicio_editar['tipo'] ?? '')=='anual') echo 'selected'; ?>>Anual</option>
                    </select>
                    <input type="number" name="costo" step="0.01" min="0"
                        value="<?php echo isset($servicio_editar['costo']) ? $servicio_editar['costo'] : ''; ?>" required
                        placeholder="Costo del servicio">
                    <textarea name="descripcion" placeholder="Descripción"><?php echo $servicio_editar['descripcion'] ?? ''; ?></textarea>
                    <div class="form-btns">
                        <button type="submit" class="btn-principal"><?php echo $servicio_editar ? "Actualizar" : "Agregar"; ?></button>
                        <?php if ($servicio_editar): ?>
                            <a href="servicios.php" class="btn-cancelar">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>
            <section class="servicios-lista">
                <h2>Listado de Servicios</h2>
                <div class="tabla-scroll">
                    <table class="tabla-servicios">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nombre</th>
                                <th>Tipo</th>
                                <th>Costo</th>
                                <th>Descripción</th>
                                <th class="col-acciones">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($servicios as $s): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($s['cod_serv']); ?></td>
                                <td><?php echo htmlspecialchars($s['nombre']); ?></td>
                                <td><?php echo ucfirst(htmlspecialchars($s['tipo'])); ?></td>
                                <td class="td-costo"><?php echo number_format($s['costo'],2,',','.'); ?></td>
                                <td style="text-align:left;"><?php echo htmlspecialchars($s['descripcion']); ?></td>
                                <td>
                                    <a href="?editar=<?php echo $s['id']; ?>" class="btn-accion editar" title="Editar">&#9998;</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este servicio?');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="servicio_id" value="<?php echo $s['id']; ?>">
                                        <button type="submit" class="btn-accion eliminar" title="Eliminar">&#128465;</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (count($servicios) === 0): ?>
                            <tr>
                                <td colspan="6" style="text-align:center; color:#aaa;">Sin servicios registrados.</td>
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