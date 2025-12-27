<?php
// Archivo: modules/contratistas.php
// Módulo para ver, agregar, editar y eliminar contratistas de la empresa actual

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

// --- AGREGAR CONTRATISTA ---
if (isset($_POST['accion']) && $_POST['accion'] === 'agregar') {
    $nombre = trim($_POST['nombre']);
    $rif = trim($_POST['rif']);
    $telefono = trim($_POST['telefono']);
    $email = trim($_POST['email']);
    $direccion = trim($_POST['direccion']);

    $sql = "INSERT INTO contratistas (empresa_id, nombre, rif, telefono, email, direccion) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('isssss', $empresa_id, $nombre, $rif, $telefono, $email, $direccion);
    if ($stmt->execute()) {
        $msg = "Contratista agregado exitosamente.";
    } else {
        $msg = "Error al agregar contratista.";
    }
    $stmt->close();
}

// --- EDITAR CONTRATISTA ---
if (isset($_POST['accion']) && $_POST['accion'] === 'editar' && isset($_POST['contratista_id'])) {
    $contratista_id = intval($_POST['contratista_id']);
    $nombre = trim($_POST['nombre']);
    $rif = trim($_POST['rif']);
    $telefono = trim($_POST['telefono']);
    $email = trim($_POST['email']);
    $direccion = trim($_POST['direccion']);

    $sql = "UPDATE contratistas SET nombre=?, rif=?, telefono=?, email=?, direccion=? WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssssi', $nombre, $rif, $telefono, $email, $direccion, $contratista_id, $empresa_id);
    if ($stmt->execute()) {
        $msg = "Contratista actualizado.";
    } else {
        $msg = "Error al actualizar contratista.";
    }
    $stmt->close();
}

// --- ELIMINAR CONTRATISTA ---
if (isset($_POST['accion']) && $_POST['accion'] === 'eliminar' && isset($_POST['contratista_id'])) {
    $contratista_id = intval($_POST['contratista_id']);
    $sql = "DELETE FROM contratistas WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $contratista_id, $empresa_id);
    if ($stmt->execute()) {
        $msg = "Contratista eliminado.";
    } else {
        $msg = "Error al eliminar contratista.";
    }
    $stmt->close();
}

// --- OBTENER DATOS DE UN CONTRATISTA PARA EDITAR ---
$contratista_editar = null;
if (isset($_GET['editar'])) {
    $contratista_id = intval($_GET['editar']);
    $sql = "SELECT * FROM contratistas WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $contratista_id, $empresa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $contratista_editar = $result->fetch_assoc();
    $stmt->close();
}

// --- LISTAR CONTRATISTAS DE LA EMPRESA ACTUAL ---
$contratistas = [];
$sql = "SELECT * FROM contratistas WHERE empresa_id=? ORDER BY nombre ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $empresa_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $contratistas[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Contratistas</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <!-- 
        Todos los estilos de formularios están unificados en style.css bajo la clase .formulario.
        Si ves algo fuera de lugar visualmente, asegúrate de que tu HTML use la clase .formulario y NO .formulario-banco, etc.
    -->
</head>
<body>
    <nav class="nav-bar">
        <div class="nav-logo">
            <img src="../assets/img/logo.png" alt="Logo" class="nav-logo-img">
        </div>
        <div class="nav-empresa"><b>Contratistas</b> | <span style="color:#FF7F36;"><?php echo htmlspecialchars($_SESSION['nombre']); ?></span></div>
        <div class="nav-user">
            <button type="button" class="btn-volver" onclick="window.location.href='dashboard.php'">Volver</button>
            <a href="../logout.php" class="nav-logout" title="Cerrar sesión">&#x1F511;</a>
        </div>
    </nav>
    <main class="seccion-contratistas">
        <section class="contratistas-form">
            <h2><?php echo $contratista_editar ? "Editar Contratista" : "Agregar Contratista"; ?></h2>
            <?php if ($msg): ?>
                <div class="msg"><?php echo $msg; ?></div>
            <?php endif; ?>
            <!-- Formulario de contratistas: cada campo en su propia línea, usando la clase .formulario -->
            <form method="POST" class="formulario" autocomplete="off">
                <input type="hidden" name="accion" value="<?php echo $contratista_editar ? 'editar' : 'agregar'; ?>">
                <?php if ($contratista_editar): ?>
                    <input type="hidden" name="contratista_id" value="<?php echo $contratista_editar['id']; ?>">
                <?php endif; ?>
                <input type="text" name="nombre" value="<?php echo $contratista_editar['nombre'] ?? ''; ?>" placeholder="Nombre completo" required>
                <input type="text" name="rif" value="<?php echo $contratista_editar['rif'] ?? ''; ?>" placeholder="RIF">
                <input type="text" name="telefono" value="<?php echo $contratista_editar['telefono'] ?? ''; ?>" placeholder="Teléfono">
                <input type="email" name="email" value="<?php echo $contratista_editar['email'] ?? ''; ?>" placeholder="Email">
                <input type="text" name="direccion" value="<?php echo $contratista_editar['direccion'] ?? ''; ?>" placeholder="Dirección">
                <div class="form-btns">
                    <button type="submit" class="btn-principal"><?php echo $contratista_editar ? "Actualizar" : "Agregar"; ?></button>
                    <?php if ($contratista_editar): ?>
                        <a href="contratistas.php" class="btn-cancelar">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>
        <section class="contratistas-lista">
            <h2>Listado de Contratistas</h2>
            <div class="tabla-scroll">
            <table class="tabla-contratistas">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>RIF</th>
                        <th>Teléfono</th>
                        <th>Email</th>
                        <th>Dirección</th>
                        <th class="col-acciones">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contratistas as $c): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($c['nombre']); ?></td>
                        <td><?php echo htmlspecialchars($c['rif']); ?></td>
                        <td><?php echo htmlspecialchars($c['telefono']); ?></td>
                        <td><?php echo htmlspecialchars($c['email']); ?></td>
                        <td><?php echo htmlspecialchars($c['direccion']); ?></td>
                        <td>
                            <a href="?editar=<?php echo $c['id']; ?>" class="btn-accion editar" title="Editar">&#9998;</a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este contratista?');">
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="contratista_id" value="<?php echo $c['id']; ?>">
                                <button type="submit" class="btn-accion eliminar" title="Eliminar">&#128465;</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($contratistas) === 0): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; color:#aaa;">Sin contratistas registrados.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </section>
    </main>
</body>
</html>