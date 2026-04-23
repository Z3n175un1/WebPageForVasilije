<?php
// Incluir configuración PRIMERO
require_once __DIR__ . '/../config.php';

// Verificar autenticación ANTES que nada
if (!isset($_SESSION['user_id']) || $_SESSION['logged_in'] !== true) {
    // Registrar intento de acceso no autorizado - CORREGIDO: es función, no variable
    log_security_event('acceso_denegado', null, null, get_client_ip(), $_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], 'Intento de acceso sin autenticación', 'medio');
    
    // Redirigir al login
    redirect('auth/login.php');
}

// Verificar que la sesión no haya expirado
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > SESSION_LIFETIME) {
    // Registrar expiración de sesión
    log_security_event('sesion_expirada', $_SESSION['user_id'], $_SESSION['username'], get_client_ip(), $_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], 'Sesión expirada por tiempo', 'bajo');
    
    session_destroy();
    redirect('auth/login.php?expired=1');
}

// Verificar que la IP no haya cambiado
if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== get_client_ip()) {
    // Posible secuestro de sesión - CORREGIDO: es función, no variable
    log_security_event('intento_sospechoso', $_SESSION['user_id'], $_SESSION['username'], get_client_ip(), $_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], 'IP cambiada durante la sesión', 'alto');
    
    session_destroy();
    redirect('auth/login.php?error=ip_changed');
}

// Actualizar última actividad
$_SESSION['last_activity'] = time();

// Verificar modo mantenimiento
if (is_maintenance_mode() && !is_ajax()) {
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
        include ERROR_PATH . '/maintenance.html';
        exit;
    }
}

// Incluir conexión a BD
require_once CONFIG_PATH . '/conn.php';

// Inicializar variables
$mensaje = '';
$tipo_mensaje = '';
$vehiculo_editar = null;
$tab_activa = isset($_GET['tab']) ? $_GET['tab'] : 'vehiculos';

// Procesar formulario de creación/edición de vehículos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['accion'])) {
        $accion = $_POST['accion'];
        
        try {
            if ($accion === 'crear' || $accion === 'editar') {
                $placa = strtoupper(trim($_POST['placa']));
                $tipo_vehiculo = $_POST['tipo_vehiculo'];
                $marca = $_POST['marca'];
                $modelo = $_POST['modelo'];
                $año = $_POST['año'];
                $color = $_POST['color'];
                $estado = $_POST['estado'];
                
                if ($accion === 'crear') {
                    $sql = "INSERT INTO vehiculos (placa, tipo_vehiculo, marca, modelo, año, color, estado) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("ssssiss", $placa, $tipo_vehiculo, $marca, $modelo, $año, $color, $estado);
                    
                    if ($stmt->execute()) {
                        $mensaje = "✅ Vehículo creado exitosamente";
                        $tipo_mensaje = "exito";
                        
                        // Log de actividad - CORREGIDO: es función, no variable
                        log_security_event('vehiculo_creado', $_SESSION['user_id'], $_SESSION['username'], get_client_ip(), $_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REQUEST_URI'], 'POST', "Vehículo: $placa", 'bajo');
                    }
                } else {
                    $id_vehiculo = $_POST['id_vehiculo'];
                    $sql = "UPDATE vehiculos SET placa = ?, tipo_vehiculo = ?, marca = ?, 
                            modelo = ?, año = ?, color = ?, estado = ? WHERE id_vehiculo = ?";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("ssssissi", $placa, $tipo_vehiculo, $marca, $modelo, $año, $color, $estado, $id_vehiculo);
                    
                    if ($stmt->execute()) {
                        $mensaje = "✅ Vehículo actualizado exitosamente";
                        $tipo_mensaje = "exito";
                        
                        // Log de actividad - CORREGIDO: es función, no variable
                        log_security_event('vehiculo_actualizado', $_SESSION['user_id'], $_SESSION['username'], get_client_ip(), $_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REQUEST_URI'], 'POST', "Vehículo ID: $id_vehiculo", 'bajo');
                    }
                }
            } elseif ($accion === 'eliminar') {
                $id_vehiculo = $_POST['id_vehiculo'];
                $sql = "UPDATE vehiculos SET estado = 'Z', fecha_venta = NOW() WHERE id_vehiculo = ?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("i", $id_vehiculo);
                
                if ($stmt->execute()) {
                    $mensaje = "✅ Vehículo marcado como vendido (Z)";
                    $tipo_mensaje = "exito";
                    
                    // Log de actividad - CORREGIDO: es función, no variable
                    log_security_event('vehiculo_vendido', $_SESSION['user_id'], $_SESSION['username'], get_client_ip(), $_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REQUEST_URI'], 'POST', "Vehículo ID: $id_vehiculo", 'medio');
                }
            }
            
            // Procesar acciones del formulario de almacén
            switch ($_POST['accion']) {
                case 'crear_producto':
                    // Generar código automático
                    $categoria = $_POST['categoria_producto'];
                    
                    $stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM productos WHERE categoria_producto = ?");
                    $stmt->bind_param("s", $categoria);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result->fetch_assoc();
                    $secuencial = str_pad($row['total'] + 1, 6, '0', STR_PAD_LEFT);
                    
                    $cat_prefix = substr($categoria, 0, 3);
                    $codigo = $cat_prefix . '-' . $secuencial;
                    
                    $sql = "INSERT INTO productos (codigo, nombre_producto, descripcion, categoria_producto, 
                            unidad_medida, stock_actual, stock_minimo, precio_compra, precio_venta, 
                            ubicacion_almacen, activo) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $mysqli->prepare($sql);
                    $activo = isset($_POST['activo']) ? 1 : 1;
                    $stmt->bind_param("sssssddddsi", 
                        $codigo,
                        $_POST['nombre_producto'],
                        $_POST['descripcion'],
                        $_POST['categoria_producto'],
                        $_POST['unidad_medida'],
                        $_POST['stock_actual'],
                        $_POST['stock_minimo'],
                        $_POST['precio_compra'],
                        $_POST['precio_venta'],
                        $_POST['ubicacion_almacen'],
                        $activo
                    );
                    $stmt->execute();
                    
                    $mensaje = "✅ Producto creado con código: $codigo";
                    $tipo_mensaje = "exito";
                    
                    // Log de actividad - CORREGIDO: es función, no variable
                    log_security_event('producto_creado', $_SESSION['user_id'], $_SESSION['username'], get_client_ip(), $_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REQUEST_URI'], 'POST', "Producto: $codigo", 'bajo');
                    break;

                case 'registrar_consumo':
                    $sql = "INSERT INTO consumo_productos (id_vehiculo, id_producto, id_tramo, 
                            cantidad, kilometraje_actual, costo_unitario, observaciones) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("iiiddss", 
                        $_POST['id_vehiculo'],
                        $_POST['id_producto'],
                        $_POST['id_tramo'] ?: null,
                        $_POST['cantidad'],
                        $_POST['kilometraje_actual'],
                        $_POST['costo_unitario'],
                        $_POST['observaciones']
                    );
                    $stmt->execute();
                    
                    $sql = "UPDATE productos SET stock_actual = stock_actual - ? WHERE id_producto = ?";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("di", $_POST['cantidad'], $_POST['id_producto']);
                    $stmt->execute();
                    
                    $mensaje = "✅ Consumo registrado exitosamente";
                    $tipo_mensaje = "exito";
                    
                    // Log de actividad - CORREGIDO: es función, no variable
                    log_security_event('consumo_registrado', $_SESSION['user_id'], $_SESSION['username'], get_client_ip(), $_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REQUEST_URI'], 'POST', "Cantidad: {$_POST['cantidad']}", 'bajo');
                    break;

                case 'registrar_tramo':
                    $sql = "INSERT INTO tramos (nombre_tramo, ruta_descripcion, distancia_km, 
                            tiempo_estimado, tipo_via, dificultad) VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("ssdsss", 
                        $_POST['nombre_tramo'],
                        $_POST['ruta_descripcion'],
                        $_POST['distancia_km'],
                        $_POST['tiempo_estimado'],
                        $_POST['tipo_via'],
                        $_POST['dificultad']
                    );
                    $stmt->execute();
                    
                    $mensaje = "✅ Tramo registrado exitosamente";
                    $tipo_mensaje = "exito";
                    
                    // Log de actividad - CORREGIDO: es función, no variable
                    log_security_event('tramo_creado', $_SESSION['user_id'], $_SESSION['username'], get_client_ip(), $_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REQUEST_URI'], 'POST', "Tramo: {$_POST['nombre_tramo']}", 'bajo');
                    break;

                case 'registrar_gasto_tramo':
                    $sql = "INSERT INTO gastos_tramo (id_vehiculo, id_tramo, fecha_recorrido,
                            kilometraje_inicio, kilometraje_fin, combustible_galones,
                            costo_combustible, peaje_cantidad, costo_peaje, observaciones)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("iisiddiids", 
                        $_POST['id_vehiculo'],
                        $_POST['id_tramo'],
                        $_POST['fecha_recorrido'],
                        $_POST['kilometraje_inicio'],
                        $_POST['kilometraje_fin'],
                        $_POST['combustible_galones'],
                        $_POST['costo_combustible'],
                        $_POST['peaje_cantidad'],
                        $_POST['costo_peaje'],
                        $_POST['observaciones']
                    );
                    $stmt->execute();
                    
                    $mensaje = "✅ Gasto de tramo registrado exitosamente";
                    $tipo_mensaje = "exito";
                    
                    // Log de actividad - CORREGIDO: es función, no variable
                    log_security_event('gasto_tramo', $_SESSION['user_id'], $_SESSION['username'], get_client_ip(), $_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REQUEST_URI'], 'POST', "Tramo ID: {$_POST['id_tramo']}", 'bajo');
                    break;
                    
                case 'actualizar_precio':
                    $sql = "UPDATE productos SET precio_compra = ?, precio_venta = ? WHERE id_producto = ?";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("ddi", 
                        $_POST['precio_compra'],
                        $_POST['precio_venta'],
                        $_POST['id_producto']
                    );
                    $stmt->execute();
                    
                    $mensaje = "✅ Precios actualizados exitosamente";
                    $tipo_mensaje = "exito";
                    
                    // Log de actividad - CORREGIDO: es función, no variable
                    log_security_event('precio_actualizado', $_SESSION['user_id'], $_SESSION['username'], get_client_ip(), $_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REQUEST_URI'], 'POST', "Producto ID: {$_POST['id_producto']}", 'bajo');
                    break;
            }
        } catch (Exception $e) {
            $mensaje = "❌ Error: " . $e->getMessage();
            $tipo_mensaje = "error";
            
            // Log de error - CORREGIDO: es función, no variable
            log_security_event('error_sistema', $_SESSION['user_id'], $_SESSION['username'], get_client_ip(), $_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $e->getMessage(), 'alto');
        }
    }
}

// Obtener datos para edición
if (isset($_GET['editar'])) {
    $id_editar = (int)$_GET['editar'];
    $stmt_edit = $mysqli->prepare("SELECT * FROM vehiculos WHERE id_vehiculo = ?");
    $stmt_edit->bind_param("i", $id_editar);
    $stmt_edit->execute();
    $vehiculo_editar = $stmt_edit->get_result()->fetch_assoc();
    $tab_activa = 'vehiculos';
}

// Obtener lista de vehículos
$busqueda = isset($_GET['busqueda']) ? $_GET['busqueda'] : '';
$filtro_estado = isset($_GET['filtro_estado']) ? $_GET['filtro_estado'] : '';

$sql_vehiculos = "SELECT * FROM vehiculos WHERE 1=1";
$params_v = [];
$types_v = "";
if ($busqueda) {
    $sql_vehiculos .= " AND (placa LIKE ? OR marca LIKE ? OR modelo LIKE ?)";
    $search_term = "%$busqueda%";
    $params_v[] = $search_term;
    $params_v[] = $search_term;
    $params_v[] = $search_term;
    $types_v .= "sss";
}
if ($filtro_estado && $filtro_estado !== 'todos') {
    $sql_vehiculos .= " AND estado = ?";
    $params_v[] = $filtro_estado;
    $types_v .= "s";
}
$sql_vehiculos .= " ORDER BY fecha_registro DESC";
$stmt_v = $mysqli->prepare($sql_vehiculos);
if ($types_v) {
    $stmt_v->bind_param($types_v, ...$params_v);
}
$stmt_v->execute();
$vehiculos = $stmt_v->get_result();

// Obtener datos para almacén
$productos = [];
$tramos = [];
$vehiculos_activos = [];
$stats = [
    'total_productos' => 0,
    'stock_bajo' => 0,
    'total_tramos' => 0,
    'valor_inventario' => 0
];

$tablas_existen = $mysqli->query("SHOW TABLES LIKE 'productos'")->num_rows > 0;

if ($tablas_existen) {
    $productos = $mysqli->query("SELECT p.* FROM productos p ORDER BY p.codigo");
    if ($productos) {
        $productos = $productos->fetch_all(MYSQLI_ASSOC);
    } else {
        $productos = [];
    }
    
    $tramos = $mysqli->query("SELECT * FROM tramos WHERE activo = TRUE ORDER BY nombre_tramo");
    if ($tramos) {
        $tramos = $tramos->fetch_all(MYSQLI_ASSOC);
    } else {
        $tramos = [];
    }
    
    $vehiculos_activos = $mysqli->query("SELECT id_vehiculo, placa FROM vehiculos WHERE estado = 'Activo'");
    if ($vehiculos_activos) {
        $vehiculos_activos = $vehiculos_activos->fetch_all(MYSQLI_ASSOC);
    } else {
        $vehiculos_activos = [];
    }
    
    $result = $mysqli->query("SELECT COUNT(*) as total FROM productos");
    $stats['total_productos'] = $result ? $result->fetch_assoc()['total'] : 0;
    
    $result = $mysqli->query("SELECT COUNT(*) as total FROM productos WHERE stock_actual <= stock_minimo");
    $stats['stock_bajo'] = $result ? $result->fetch_assoc()['total'] : 0;
    
    $result = $mysqli->query("SELECT COUNT(*) as total FROM tramos");
    $stats['total_tramos'] = $result ? $result->fetch_assoc()['total'] : 0;
    
    $result = $mysqli->query("SELECT SUM(stock_actual * precio_compra) as total FROM productos");
    $stats['valor_inventario'] = $result ? $result->fetch_assoc()['total'] : 0;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Gestión de Transporte</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .navbar {
            background: white;
            border-radius: 10px;
            padding: 15px 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .nav-menu {
            display: flex;
            list-style: none;
            gap: 30px;
            align-items: center;
            flex-wrap: wrap;
        }

        .nav-menu a {
            text-decoration: none;
            color: #333;
            font-weight: 500;
            padding: 10px 15px;
            border-radius: 5px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .nav-menu a:hover, .nav-menu a.activo {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .logo {
            font-size: 24px;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-right: auto;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .seccion {
            display: none;
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .seccion.activo {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .sub-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e1e1e1;
            padding-bottom: 10px;
            flex-wrap: wrap;
        }

        .sub-tab {
            padding: 10px 20px;
            border: none;
            background: none;
            color: #666;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            border-radius: 5px 5px 0 0;
            transition: all 0.3s ease;
        }

        .sub-tab:hover {
            color: #667eea;
        }

        .sub-tab.activo {
            color: #667eea;
            border-bottom: 3px solid #667eea;
            background: rgba(102, 126, 234, 0.1);
        }

        .sub-contenido {
            display: none;
        }

        .sub-contenido.activo {
            display: block;
        }

        .mensaje {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            animation: slideIn 0.3s ease;
        }

        .mensaje.exito {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .mensaje.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .campo-form {
            margin-bottom: 15px;
        }

        .campo-form label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
        }

        .campo-form input, .campo-form select, .campo-form textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e1e1e1;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .campo-form input:focus, .campo-form select:focus, .campo-form textarea:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            margin: 5px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102,126,234,0.4);
        }

        .btn-secundario {
            background: #f8f9fa;
            color: #333;
            border: 2px solid #e1e1e1;
        }

        .btn-secundario:hover {
            background: #e1e1e1;
        }

        .btn-peligro {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
        }

        .resumen-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .card h3 {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 10px;
        }

        .card .numero {
            font-size: 32px;
            font-weight: bold;
        }

        .tabla-container {
            overflow-x: auto;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-top: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            font-weight: 600;
        }

        td {
            padding: 12px 15px;
            border-bottom: 1px solid #e1e1e1;
            color: #333;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .estado-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            display: inline-block;
            min-width: 80px;
        }

        .estado-activo {
            background: #d4edda;
            color: #155724;
        }

        .estado-inactivo {
            background: #f8d7da;
            color: #721c24;
        }

        .estado-vendido {
            background: #f8d7da;
            color: #721c24;
        }

        .estado-z {
            background: #fff3cd;
            color: #856404;
        }

        .estado-alerta {
            background: #f8d7da;
            color: #721c24;
            font-weight: bold;
        }

        .acciones {
            display: flex;
            gap: 8px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-accion {
            padding: 6px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-editar {
            background: #667eea;
            color: white;
        }

        .btn-eliminar {
            background: #f56565;
            color: white;
        }

        .btn-ver {
            background: #48bb78;
            color: white;
        }

        .btn-precio {
            background: #f59e0b;
            color: white;
        }

        .btn-accion:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        .busqueda-bar {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .busqueda-input {
            flex: 1;
            min-width: 200px;
            padding: 12px;
            border: 2px solid #e1e1e1;
            border-radius: 8px;
            font-size: 14px;
        }

        .filtro-select {
            padding: 12px;
            border: 2px solid #e1e1e1;
            border-radius: 8px;
            min-width: 150px;
        }

        .construccion {
            text-align: center;
            padding: 60px 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 2px dashed #e1e1e1;
        }

        .construccion h2 {
            color: #667eea;
            margin-bottom: 20px;
            font-size: 32px;
        }

        .construccion p {
            color: #666;
            font-size: 18px;
            margin-bottom: 30px;
        }

        .construccion .emoji-grande {
            font-size: 80px;
            margin-bottom: 20px;
        }

        .formulario-oculto {
            display: none;
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .badge-codigo {
            background: #667eea;
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        .valor-destacado {
            font-size: 24px;
            font-weight: bold;
            color: #48bb78;
        }

        @media (max-width: 768px) {
            .nav-menu {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .busqueda-bar {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <nav class="navbar">
            <ul class="nav-menu">
                <li class="logo">🚛 Transporte Gestión</li>
                <li><a onclick="cambiarSeccion('dashboard')" class="<?php echo $tab_activa == 'dashboard' ? 'activo' : ''; ?>">🏠 Dashboard</a></li>
                <li><a onclick="cambiarSeccion('vehiculos')" class="<?php echo $tab_activa == 'vehiculos' ? 'activo' : ''; ?>">🚗 Vehículos</a></li>
                <li><a onclick="cambiarSeccion('almacen')" class="<?php echo $tab_activa == 'almacen' ? 'activo' : ''; ?>">📦 Almacén</a></li>
                <li><a onclick="cambiarSeccion('gastos')" class="<?php echo $tab_activa == 'gastos' ? 'activo' : ''; ?>">💰 Gastos</a></li>
                <li><a onclick="cambiarSeccion('reportes')" class="<?php echo $tab_activa == 'reportes' ? 'activo' : ''; ?>">📊 Reportes</a></li>
                <li><a onclick="cambiarSeccion('configuracion')" class="<?php echo $tab_activa == 'configuracion' ? 'activo' : ''; ?>">⚙️ Configuración</a></li>
            </ul>
        </nav>

        <?php if ($mensaje): ?>
            <div class="mensaje <?php echo $tipo_mensaje; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <!-- DASHBOARD -->
        <div id="dashboard" class="seccion <?php echo $tab_activa == 'dashboard' ? 'activo' : ''; ?>">
            <div class="construccion">
                <div class="emoji-grande">🏠</div>
                <h2>Dashboard Principal</h2>
                <p>Bienvenido al sistema de gestión de transporte</p>
                <p>Aquí verás resúmenes y estadísticas generales</p>
                <button class="btn" onclick="cambiarSeccion('vehiculos')">Comenzar a gestionar vehículos</button>
            </div>
        </div>

        <!-- VEHÍCULOS -->
        <div id="vehiculos" class="seccion <?php echo $tab_activa == 'vehiculos' ? 'activo' : ''; ?>">
            <div class="sub-tabs">
                <button class="sub-tab activo" onclick="mostrarSubTab('vehiculos-creacion', event)">✏️ Creación/Edición</button>
                <button class="sub-tab" onclick="mostrarSubTab('vehiculos-lista', event)">📋 Lista de Vehículos</button>
            </div>

            <div id="vehiculos-creacion" class="sub-contenido activo">
                <h2 style="color: #333; margin-bottom: 20px;">
                    <?php echo $vehiculo_editar ? '✏️ Editar Vehículo' : '➕ Registrar Nuevo Vehículo'; ?>
                </h2>
                
                <form method="POST" onsubmit="return validarFormulario()">
                    <?php if ($vehiculo_editar): ?>
                        <input type="hidden" name="accion" value="editar">
                        <input type="hidden" name="id_vehiculo" value="<?php echo $vehiculo_editar['id_vehiculo']; ?>">
                    <?php else: ?>
                        <input type="hidden" name="accion" value="crear">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="campo-form">
                            <label>📋 Placa *</label>
                            <input type="text" name="placa" required maxlength="10" 
                                   value="<?php echo $vehiculo_editar ? $vehiculo_editar['placa'] : ''; ?>"
                                   placeholder="Ej: ABC-123">
                        </div>

                        <div class="campo-form">
                            <label>🚗 Tipo *</label>
                            <select name="tipo_vehiculo" required>
                                <option value="">Seleccionar...</option>
                                <option value="Camion" <?php echo ($vehiculo_editar && $vehiculo_editar['tipo_vehiculo'] == 'Camion') ? 'selected' : ''; ?>>Camión</option>
                                <option value="Camioneta" <?php echo ($vehiculo_editar && $vehiculo_editar['tipo_vehiculo'] == 'Camioneta') ? 'selected' : ''; ?>>Camioneta</option>
                                <option value="Bus" <?php echo ($vehiculo_editar && $vehiculo_editar['tipo_vehiculo'] == 'Bus') ? 'selected' : ''; ?>>Bus</option>
                                <option value="Automovil" <?php echo ($vehiculo_editar && $vehiculo_editar['tipo_vehiculo'] == 'Automovil') ? 'selected' : ''; ?>>Automóvil</option>
                            </select>
                        </div>

                        <div class="campo-form">
                            <label>🏭 Marca</label>
                            <input type="text" name="marca" value="<?php echo $vehiculo_editar ? $vehiculo_editar['marca'] : ''; ?>">
                        </div>

                        <div class="campo-form">
                            <label>📅 Modelo</label>
                            <input type="text" name="modelo" value="<?php echo $vehiculo_editar ? $vehiculo_editar['modelo'] : ''; ?>">
                        </div>

                        <div class="campo-form">
                            <label>📆 Año</label>
                            <input type="number" name="año" value="<?php echo $vehiculo_editar ? $vehiculo_editar['año'] : date('Y'); ?>">
                        </div>

                        <div class="campo-form">
                            <label>🎨 Color</label>
                            <input type="text" name="color" value="<?php echo $vehiculo_editar ? $vehiculo_editar['color'] : ''; ?>">
                        </div>

                        <div class="campo-form">
                            <label>📊 Estado</label>
                            <select name="estado" required>
                                <option value="Activo" <?php echo (!$vehiculo_editar || $vehiculo_editar['estado'] == 'Activo') ? 'selected' : ''; ?>>Activo</option>
                                <option value="Vendido" <?php echo ($vehiculo_editar && $vehiculo_editar['estado'] == 'Vendido') ? 'selected' : ''; ?>>Vendido</option>
                                <option value="Fuera de Servicio" <?php echo ($vehiculo_editar && $vehiculo_editar['estado'] == 'Fuera de Servicio') ? 'selected' : ''; ?>>Fuera de Servicio</option>
                                <option value="Z" <?php echo ($vehiculo_editar && $vehiculo_editar['estado'] == 'Z') ? 'selected' : ''; ?>>Z - Vendido</option>
                            </select>
                        </div>
                    </div>

                    <div style="margin-top: 20px;">
                        <button type="submit" class="btn">
                            <?php echo $vehiculo_editar ? '✏️ Actualizar' : '➕ Registrar'; ?>
                        </button>
                        <?php if ($vehiculo_editar): ?>
                            <a href="?tab=vehiculos" class="btn btn-secundario">↩️ Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div id="vehiculos-lista" class="sub-contenido">
                <h2 style="color: #333; margin-bottom: 20px;">📋 Lista de Vehículos</h2>
                
                <?php
                $total_vehiculos = $mysqli->query("SELECT COUNT(*) as total FROM vehiculos")->fetch_assoc()['total'];
                $activos = $mysqli->query("SELECT COUNT(*) as total FROM vehiculos WHERE estado = 'Activo'")->fetch_assoc()['total'];
                $vendidos = $mysqli->query("SELECT COUNT(*) as total FROM vehiculos WHERE estado IN ('Vendido', 'Z')")->fetch_assoc()['total'];
                ?>
                <div class="resumen-cards">
                    <div class="card"><h3>Total</h3><div class="numero"><?php echo $total_vehiculos; ?></div></div>
                    <div class="card"><h3>Activos</h3><div class="numero"><?php echo $activos; ?></div></div>
                    <div class="card"><h3>Vendidos</h3><div class="numero"><?php echo $vendidos; ?></div></div>
                </div>

                <form method="GET" class="busqueda-bar">
                    <input type="hidden" name="tab" value="vehiculos">
                    <input type="text" name="busqueda" class="busqueda-input" 
                           placeholder="🔍 Buscar..." value="<?php echo htmlspecialchars($busqueda); ?>">
                    <select name="filtro_estado" class="filtro-select">
                        <option value="todos">Todos</option>
                        <option value="Activo" <?php echo $filtro_estado == 'Activo' ? 'selected' : ''; ?>>Activos</option>
                        <option value="Vendido" <?php echo $filtro_estado == 'Vendido' ? 'selected' : ''; ?>>Vendidos</option>
                    </select>
                    <button type="submit" class="btn">Filtrar</button>
                    <a href="?tab=vehiculos" class="btn btn-secundario">Limpiar</a>
                </form>

                <div class="tabla-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Placa</th><th>Tipo</th><th>Marca/Modelo</th>
                                <th>Año</th><th>Color</th><th>Estado</th><th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($vehiculos && $vehiculos->num_rows > 0): ?>
                                <?php while ($row = $vehiculos->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo $row['placa']; ?></strong></td>
                                        <td><?php echo $row['tipo_vehiculo']; ?></td>
                                        <td><?php echo $row['marca'] . ' ' . $row['modelo']; ?></td>
                                        <td><?php echo $row['año']; ?></td>
                                        <td><?php echo $row['color']; ?></td>
                                        <td>
                                            <span class="estado-badge <?php echo $row['estado'] == 'Activo' ? 'estado-activo' : 'estado-vendido'; ?>">
                                                <?php echo $row['estado']; ?>
                                            </span>
                                        </td>
                                        <td class="acciones">
                                            <a href="?tab=vehiculos&editar=<?php echo $row['id_vehiculo']; ?>" class="btn-accion btn-editar">✏️</a>
                                            <?php if ($row['estado'] != 'Z'): ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('¿Marcar como vendido?')">
                                                <input type="hidden" name="accion" value="eliminar">
                                                <input type="hidden" name="id_vehiculo" value="<?php echo $row['id_vehiculo']; ?>">
                                                <button type="submit" class="btn-accion btn-eliminar">💰</button>
                                            </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" style="text-align: center; padding: 40px;">🚗 No hay vehículos</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ALMACÉN -->
        <div id="almacen" class="seccion <?php echo $tab_activa == 'almacen' ? 'activo' : ''; ?>">
            <div style="margin-bottom: 20px;">
                <h2 style="color: #333;">📦 Gestión de Almacén</h2>
                <p style="color: #666;">Códigos automáticos: Lla-000001, Ace-000001, Fil-000001, Rep-000001</p>
            </div>

            <div class="resumen-cards">
                <div class="card"><h3>Total Productos</h3><div class="numero"><?php echo $stats['total_productos']; ?></div></div>
                <div class="card"><h3>Stock Bajo</h3><div class="numero"><?php echo $stats['stock_bajo']; ?></div></div>
                <div class="card"><h3>Valor Inventario</h3><div class="numero">Bs/ <?php echo number_format($stats['valor_inventario'], 2); ?></div></div>
            </div>

            <div class="sub-tabs">
                <button class="sub-tab activo" onclick="mostrarSubTab('productos-lista', event)">📋 Productos</button>
                <button class="sub-tab" onclick="mostrarSubTab('productos-nuevo', event)">➕ Nuevo Producto</button>
                <button class="sub-tab" onclick="mostrarSubTab('consumos', event)">⬇️ Registrar Consumo</button>
                <button class="sub-tab" onclick="mostrarSubTab('tramos', event)">🛣️ Tramos</button>
            </div>

            <!-- Lista de Productos -->
            <div id="productos-lista" class="sub-contenido activo">
                <h3>Productos en Almacén</h3>
                <div class="tabla-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Producto</th>
                                <th>Categoría</th>
                                <th>Unidad</th>
                                <th>Stock</th>
                                <th>Stock Mín</th>
                                <th>Precio Compra</th>
                                <th>Precio Venta</th>
                                <th>Ubicación</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($productos)): ?>
                                <?php foreach ($productos as $p): ?>
                                <tr>
                                    <td><span class="badge-codigo"><?php echo $p['codigo']; ?></span></td>
                                    <td><strong><?php echo $p['nombre_producto']; ?></strong></td>
                                    <td><?php echo $p['categoria_producto']; ?></td>
                                    <td><?php echo $p['unidad_medida']; ?></td>
                                    <td class="<?php echo $p['stock_actual'] <= $p['stock_minimo'] ? 'estado-alerta' : ''; ?>">
                                        <?php echo $p['stock_actual']; ?>
                                    </td>
                                    <td><?php echo $p['stock_minimo']; ?></td>
                                    <td>Bs/ <?php echo number_format($p['precio_compra'], 2); ?></td>
                                    <td>Bs/ <?php echo number_format($p['precio_venta'], 2); ?></td>
                                    <td><?php echo $p['ubicacion_almacen'] ?: '-'; ?></td>
                                    <td>
                                        <span class="estado-badge <?php echo $p['activo'] ? 'estado-activo' : 'estado-inactivo'; ?>">
                                            <?php echo $p['activo'] ? 'Activo' : 'Inactivo'; ?>
                                        </span>
                                    </td>
                                    <td class="acciones">
                                        <button class="btn-accion btn-precio" onclick="mostrarFormPrecio(<?php echo $p['id_producto']; ?>, '<?php echo $p['precio_compra']; ?>', '<?php echo $p['precio_venta']; ?>')">💰</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="11" style="text-align: center; padding: 40px;">📦 No hay productos registrados</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Formulario oculto para actualizar precios -->
            <div id="form-precio" class="formulario-oculto">
                <h4>Actualizar Precios</h4>
                <form method="POST">
                    <input type="hidden" name="accion" value="actualizar_precio">
                    <input type="hidden" name="id_producto" id="precio_id_producto">
                    
                    <div class="form-grid">
                        <div class="campo-form">
                            <label>Precio Compra (Bs/)</label>
                            <input type="number" step="0.01" name="precio_compra" id="precio_compra_input" required>
                        </div>
                        <div class="campo-form">
                            <label>Precio Venta (Bs/)</label>
                            <input type="number" step="0.01" name="precio_venta" id="precio_venta_input" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn">Actualizar</button>
                    <button type="button" class="btn btn-secundario" onclick="ocultarFormPrecio()">Cancelar</button>
                </form>
            </div>

            <!-- Nuevo Producto -->
            <div id="productos-nuevo" class="sub-contenido">
                <h3>➕ Registrar Nuevo Producto</h3>
                <form method="POST">
                    <input type="hidden" name="accion" value="crear_producto">
                    
                    <div class="form-grid">
                        <div class="campo-form">
                            <label>Nombre del Producto *</label>
                            <input type="text" name="nombre_producto" required>
                        </div>
                        
                        <div class="campo-form">
                            <label>Categoría *</label>
                            <select name="categoria_producto" required>
                                <option value="">Seleccionar...</option>
                                <option value="Llantas">Llantas</option>
                                <option value="Aceite">Aceite</option>
                                <option value="Filtros">Filtros</option>
                                <option value="Repuestos">Repuestos</option>
                            </select>
                        </div>
                        
                        <div class="campo-form">
                            <label>Unidad de Medida *</label>
                            <select name="unidad_medida" required>
                                <option value="Pieza">Pieza</option>
                                <option value="Juego">Juego</option>
                                <option value="Docena">Docena</option>
                                <option value="Litro">Litro</option>
                                <option value="Galón">Galón</option>
                                <option value="Unidad">Unidad</option>
                            </select>
                        </div>
                        
                        <div class="campo-form">
                            <label>Stock Actual</label>
                            <input type="number" step="0.01" name="stock_actual" value="0">
                        </div>
                        
                        <div class="campo-form">
                            <label>Stock Mínimo</label>
                            <input type="number" step="0.01" name="stock_minimo" value="0">
                        </div>
                        
                        <div class="campo-form">
                            <label>Precio Compra (Bs/)</label>
                            <input type="number" step="0.01" name="precio_compra">
                        </div>
                        
                        <div class="campo-form">
                            <label>Precio Venta (Bs/)</label>
                            <input type="number" step="0.01" name="precio_venta">
                        </div>
                        
                        <div class="campo-form">
                            <label>Ubicación en Almacén</label>
                            <input type="text" name="ubicacion_almacen" placeholder="Ej: Estante A-1">
                        </div>
                        
                        <div class="campo-form" style="grid-column: span 2;">
                            <label>Descripción</label>
                            <textarea name="descripcion" rows="2"></textarea>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn">Guardar Producto</button>
                </form>
            </div>

            <!-- Registrar Consumo -->
            <div id="consumos" class="sub-contenido">
                <h3>⬇️ Registrar Consumo de Producto</h3>
                <form method="POST">
                    <input type="hidden" name="accion" value="registrar_consumo">
                    
                    <div class="form-grid">
                        <div class="campo-form">
                            <label>Vehículo *</label>
                            <select name="id_vehiculo" required>
                                <option value="">Seleccionar...</option>
                                <?php foreach ($vehiculos_activos as $v): ?>
                                    <option value="<?php echo $v['id_vehiculo']; ?>"><?php echo $v['placa']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="campo-form">
                            <label>Producto *</label>
                            <select name="id_producto" required>
                                <option value="">Seleccionar...</option>
                                <?php foreach ($productos as $p): ?>
                                    <option value="<?php echo $p['id_producto']; ?>">
                                        <?php echo $p['codigo'] . ' - ' . $p['nombre_producto']; ?> (Stock: <?php echo $p['stock_actual']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="campo-form">
                            <label>Tramo</label>
                            <select name="id_tramo">
                                <option value="">Sin tramo</option>
                                <?php foreach ($tramos as $t): ?>
                                    <option value="<?php echo $t['id_tramo']; ?>"><?php echo $t['nombre_tramo']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="campo-form">
                            <label>Cantidad *</label>
                            <input type="number" step="0.01" name="cantidad" required>
                        </div>
                        
                        <div class="campo-form">
                            <label>Kilometraje</label>
                            <input type="number" name="kilometraje_actual">
                        </div>
                        
                        <div class="campo-form">
                            <label>Costo Unitario (Bs/) *</label>
                            <input type="number" step="0.01" name="costo_unitario" required>
                        </div>
                        
                        <div class="campo-form" style="grid-column: span 2;">
                            <label>Observaciones</label>
                            <textarea name="observaciones" rows="2"></textarea>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn">Registrar Consumo</button>
                </form>
            </div>

            <!-- Tramos -->
            <div id="tramos" class="sub-contenido">
                <div style="margin-bottom: 20px;">
                    <button class="btn" onclick="toggleTramoForm()">➕ Nuevo Tramo</button>
                </div>

                <div id="form-tramo" style="display: none;" class="formulario-oculto">
                    <h4>Registrar Nuevo Tramo</h4>
                    <form method="POST">
                        <input type="hidden" name="accion" value="registrar_tramo">
                        
                        <div class="form-grid">
                            <div class="campo-form">
                                <label>Nombre del Tramo *</label>
                                <input type="text" name="nombre_tramo" required>
                            </div>
                            
                            <div class="campo-form">
                                <label>Distancia (KM) *</label>
                                <input type="number" step="0.01" name="distancia_km" required>
                            </div>
                            
                            <div class="campo-form">
                                <label>Tiempo Estimado</label>
                                <input type="time" name="tiempo_estimado">
                            </div>
                            
                            <div class="campo-form">
                                <label>Tipo de Vía</label>
                                <select name="tipo_via">
                                    <option value="Asfaltado">Asfaltado</option>
                                    <option value="Afirmado">Afirmado</option>
                                    <option value="Mixto">Mixto</option>
                                </select>
                            </div>
                            
                            <div class="campo-form">
                                <label>Dificultad</label>
                                <select name="dificultad">
                                    <option value="Baja">Baja</option>
                                    <option value="Media">Media</option>
                                    <option value="Alta">Alta</option>
                                </select>
                            </div>
                            
                            <div class="campo-form" style="grid-column: span 2;">
                                <label>Descripción de la Ruta</label>
                                <textarea name="ruta_descripcion" rows="2"></textarea>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn">Guardar Tramo</button>
                        <button type="button" class="btn btn-secundario" onclick="toggleTramoForm()">Cancelar</button>
                    </form>
                </div>

                <h4>Tramos Registrados</h4>
                <div class="tabla-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Tramo</th>
                                <th>Distancia</th>
                                <th>Tiempo</th>
                                <th>Tipo Vía</th>
                                <th>Dificultad</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($tramos)): ?>
                                <?php foreach ($tramos as $t): ?>
                                <tr>
                                    <td><strong><?php echo $t['nombre_tramo']; ?></strong></td>
                                    <td><?php echo number_format($t['distancia_km'], 1); ?> KM</td>
                                    <td><?php echo $t['tiempo_estimado']; ?></td>
                                    <td><?php echo $t['tipo_via']; ?></td>
                                    <td>
                                        <span class="estado-badge" style="background: 
                                            <?php echo $t['dificultad'] == 'Baja' ? '#d4edda' : ($t['dificultad'] == 'Media' ? '#fff3cd' : '#f8d7da'); ?>">
                                            <?php echo $t['dificultad']; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="text-align: center; padding: 40px;">🛣️ No hay tramos registrados</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- GASTOS -->
        <div id="gastos" class="seccion <?php echo $tab_activa == 'gastos' ? 'activo' : ''; ?>">
            <div class="construccion">
                <div class="emoji-grande">💰</div>
                <h2>Módulo de Gastos</h2>
                <p>Esta sección está en construcción</p>
            </div>
        </div>

        <!-- REPORTES -->
        <div id="reportes" class="seccion <?php echo $tab_activa == 'reportes' ? 'activo' : ''; ?>">
            <div class="construccion">
                <div class="emoji-grande">📊</div>
                <h2>Módulo de Reportes</h2>
                <p>Esta sección está en construcción</p>
            </div>
        </div>

        <!-- CONFIGURACIÓN -->
        <div id="configuracion" class="seccion <?php echo $tab_activa == 'configuracion' ? 'activo' : ''; ?>">
            <div class="construccion">
                <div class="emoji-grande">⚙️</div>
                <h2>Configuración</h2>
                <p>Esta sección está en construcción</p>
            </div>
        </div>
    </div>

    <script>
        function cambiarSeccion(seccionId) {
            document.querySelectorAll('.seccion').forEach(s => s.classList.remove('activo'));
            document.getElementById(seccionId).classList.add('activo');
            
            document.querySelectorAll('.nav-menu a').forEach(a => a.classList.remove('activo'));
            event.target.classList.add('activo');
            
            const url = new URL(window.location);
            url.searchParams.set('tab', seccionId);
            window.history.pushState({}, '', url);
        }

        function mostrarSubTab(subTabId, event) {
            document.querySelectorAll('.sub-contenido').forEach(c => c.classList.remove('activo'));
            document.getElementById(subTabId).classList.add('activo');
            
            document.querySelectorAll('.sub-tab').forEach(t => t.classList.remove('activo'));
            event.target.classList.add('activo');
        }

        function validarFormulario() {
            const placa = document.querySelector('input[name="placa"]').value;
            if (placa.length < 3) {
                alert('La placa debe tener al menos 3 caracteres');
                return false;
            }
            return true;
        }

        function toggleTramoForm() {
            const form = document.getElementById('form-tramo');
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }

        function mostrarFormPrecio(id, precio_compra, precio_venta) {
            document.getElementById('precio_id_producto').value = id;
            document.getElementById('precio_compra_input').value = precio_compra;
            document.getElementById('precio_venta_input').value = precio_venta;
            document.getElementById('form-precio').style.display = 'block';
            
            // Cambiar a la pestaña de lista si es necesario
            document.getElementById('productos-lista').classList.add('activo');
        }

        function ocultarFormPrecio() {
            document.getElementById('form-precio').style.display = 'none';
        }

        document.querySelector('input[name="placa"]')?.addEventListener('input', function(e) {
            this.value = this.value.toUpperCase();
        });

        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab') || 'dashboard';
            
            document.querySelectorAll('.seccion').forEach(s => s.classList.remove('activo'));
            const tabEl = document.getElementById(tab);
            if (tabEl) tabEl.classList.add('activo');
            
            const tabLabels = {
                'dashboard': 'Dashboard',
                'vehiculos': 'Vehículos',
                'almacen': 'Almacén',
                'gastos': 'Gastos',
                'reportes': 'Reportes',
                'configuracion': 'Configuración'
            };
            const targetLabel = tabLabels[tab];
            
            document.querySelectorAll('.nav-menu a').forEach(a => a.classList.remove('activo'));
            if (targetLabel) {
                const links = document.querySelectorAll('.nav-menu a');
                for (let link of links) {
                    if (link.textContent.includes(targetLabel)) {
                        link.classList.add('activo');
                        break;
                    }
                }
            }
        });
    </script>
</body>
</html>