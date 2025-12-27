<?php
// Archivo: modules/clientes.php
// Módulo para ver, agregar, editar y eliminar clientes de la empresa actual

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

// --- AGREGAR CLIENTE ---
if (isset($_POST['accion']) && $_POST['accion'] === 'agregar') {
    $nombre = trim($_POST['nombre']);
    $rif = trim($_POST['rif']);
    $telefono = trim($_POST['telefono']);
    $email = trim($_POST['email']);
    $direccion = trim($_POST['direccion']);

    $sql = "INSERT INTO clientes (empresa_id, nombre, rif, telefono, email, direccion) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('isssss', $empresa_id, $nombre, $rif, $telefono, $email, $direccion);
    if ($stmt->execute()) {
        $msg = "Cliente agregado exitosamente.";
    } else {
        $msg = "Error al agregar cliente.";
    }
    $stmt->close();
}

// --- EDITAR CLIENTE ---
if (isset($_POST['accion']) && $_POST['accion'] === 'editar' && isset($_POST['cliente_id'])) {
    $cliente_id = intval($_POST['cliente_id']);
    $nombre = trim($_POST['nombre']);
    $rif = trim($_POST['rif']);
    $telefono = trim($_POST['telefono']);
    $email = trim($_POST['email']);
    $direccion = trim($_POST['direccion']);

    $sql = "UPDATE clientes SET nombre=?, rif=?, telefono=?, email=?, direccion=? WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssssi', $nombre, $rif, $telefono, $email, $direccion, $cliente_id, $empresa_id);
    if ($stmt->execute()) {
        $msg = "Cliente actualizado.";
    } else {
        $msg = "Error al actualizar cliente.";
    }
    $stmt->close();
}

// --- ELIMINAR CLIENTE ---
if (isset($_POST['accion']) && $_POST['accion'] === 'eliminar' && isset($_POST['cliente_id'])) {
    $cliente_id = intval($_POST['cliente_id']);
    $sql = "DELETE FROM clientes WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $cliente_id, $empresa_id);
    if ($stmt->execute()) {
        $msg = "Cliente eliminado.";
    } else {
        $msg = "Error al eliminar cliente.";
    }
    $stmt->close();
}

// --- OBTENER DATOS DE UN CLIENTE PARA EDITAR ---
$cliente_editar = null;
if (isset($_GET['editar'])) {
    $cliente_id = intval($_GET['editar']);
    $sql = "SELECT * FROM clientes WHERE id=? AND empresa_id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $cliente_id, $empresa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $cliente_editar = $result->fetch_assoc();
    $stmt->close();
}

// --- LISTAR CLIENTES DE LA EMPRESA ACTUAL ---
$clientes = [];
$sql = "SELECT * FROM clientes WHERE empresa_id=? ORDER BY nombre ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $empresa_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $clientes[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Clientes</title>
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
        <div class="nav-empresa"><b>Clientes</b> | <span style="color:#FF7F36;"><?php echo htmlspecialchars($_SESSION['nombre']); ?></span></div>
        <div class="nav-user">
            <button type="button" class="btn-volver" onclick="window.location.href='dashboard.php'">Volver</button>
            <a href="../logout.php" class="nav-logout" title="Cerrar sesión">&#x1F511;</a>
        </div>
    </nav>
    <main class="seccion-clientes">
        <section class="clientes-form">
            <h2><?php echo $cliente_editar ? "Editar Cliente" : "Agregar Cliente"; ?></h2>
            <?php if ($msg): ?>
                <div class="msg"><?php echo $msg; ?></div>
            <?php endif; ?>
            <!-- Formulario de clientes: cada campo en su propia línea, usando la clase .formulario -->
            <form method="POST" class="formulario" autocomplete="off">
                <input type="hidden" name="accion" value="<?php echo $cliente_editar ? 'editar' : 'agregar'; ?>">
                <?php if ($cliente_editar): ?>
                    <input type="hidden" name="cliente_id" value="<?php echo $cliente_editar['id']; ?>">
                <?php endif; ?>
                <input type="text" name="nombre" value="<?php echo $cliente_editar['nombre'] ?? ''; ?>" placeholder="Nombre completo" required>
                <input type="text" name="rif" value="<?php echo $cliente_editar['rif'] ?? ''; ?>" placeholder="RIF">
                <input type="text" name="telefono" value="<?php echo $cliente_editar['telefono'] ?? ''; ?>" placeholder="Teléfono">
                <input type="email" name="email" value="<?php echo $cliente_editar['email'] ?? ''; ?>" placeholder="Email">
                <input type="text" name="direccion" value="<?php echo $cliente_editar['direccion'] ?? ''; ?>" placeholder="Dirección">
                <div class="form-btns">
                    <button type="submit" class="btn-principal"><?php echo $cliente_editar ? "Actualizar" : "Agregar"; ?></button>
                    <?php if ($cliente_editar): ?>
                        <a href="clientes.php" class="btn-cancelar">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>
        <section class="clientes-lista">
            <h2>Listado de Clientes</h2>
            <div class="tabla-scroll">
            <table class="tabla-clientes">
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
                    <?php foreach ($clientes as $cli): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($cli['nombre']); ?></td>
                        <td><?php echo htmlspecialchars($cli['rif']); ?></td>
                        <td><?php echo htmlspecialchars($cli['telefono']); ?></td>
                        <td><?php echo htmlspecialchars($cli['email']); ?></td>
                        <td><?php echo htmlspecialchars($cli['direccion']); ?></td>
                        <td>
                            <a href="?editar=<?php echo $cli['id']; ?>" class="btn-accion editar" title="Editar">&#9998;</a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este cliente?');">
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="cliente_id" value="<?php echo $cli['id']; ?>">
                                <button type="submit" class="btn-accion eliminar" title="Eliminar">&#128465;</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (count($clientes) === 0): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; color:#aaa;">Sin clientes registrados.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </section>
    </main>
</body>
</html>