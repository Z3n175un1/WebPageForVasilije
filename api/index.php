<?php
/**
 * API del sistema
 * Ubicación: /api/index.php
 */

require_once __DIR__ . '/../config.php';

// Solo aceptar peticiones AJAX o API
header('Content-Type: application/json');

// Verificar método
$method = $_SERVER['REQUEST_METHOD'];
$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : '';

// Respuesta por defecto
$response = [
    'success' => false,
    'message' => 'Endpoint no encontrado',
    'data' => null
];

// Rutas de la API
switch ($endpoint) {
    case 'vehiculos':
        if ($method === 'GET') {
            require_once CONFIG_PATH . '/conn.php';
            $pdo = getConnection();
            $stmt = $pdo->query("SELECT id_vehiculo, placa, tipo_vehiculo, estado FROM global.vehiculos");
            $response = [
                'success' => true,
                'data' => $stmt->fetchAll()
            ];
        }
        break;
        
    case 'productos':
        if ($method === 'GET') {
            require_once CONFIG_PATH . '/conn.php';
            $pdo = getConnection();
            $stmt = $pdo->query("SELECT id_producto, codigo, nombre_producto, stock_actual FROM global.productos");
            $response = [
                'success' => true,
                'data' => $stmt->fetchAll()
            ];
        }
        break;

    case 'tramos':
        require_once CONFIG_PATH . '/conn.php';
        $pdo = getConnection();
        
        if ($method === 'GET') {
            $stmt = $pdo->query("SELECT * FROM global.tramos ORDER BY created_at DESC");
            $response = [
                'success' => true,
                'data' => $stmt->fetchAll()
            ];
        } elseif ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $stmt = $pdo->prepare("INSERT INTO global.tramos (origen, destino, kilometros, precio_total, gasolina_promedio) VALUES (?, ?, ?, ?, ?)");
            $success = $stmt->execute([
                $input['origen'],
                $input['destino'],
                $input['kilometros'],
                $input['precio_total'],
                $input['gasolina_promedio']
            ]);
            $response = [
                'success' => $success,
                'message' => $success ? 'Tramo creado exitosamente' : 'Error al crear tramo'
            ];
        }
        break;
        
    case 'status':
        $response = [
            'success' => true,
            'data' => [
                'status' => 'online',
                'system' => app_config('app_name'),
                'environment' => ENVIRONMENT,
                'timestamp' => time()
            ]
        ];
        break;
}

echo json_encode($response, JSON_PRETTY_PRINT);