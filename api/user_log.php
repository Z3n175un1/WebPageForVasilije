<?php
/**
 * API para registro de eventos de seguridad
 * Ubicación: /api/user_log.php
 * 
 * Uso: 
 *   POST /api/user_log.php
 *   {
 *     "tipo": "intento_sospechoso",
 *     "datos": {...}
 *   }
 */

require_once __DIR__ . '/../config.php';
require_once CONFIG_PATH . '/conn.php';

// Solo aceptar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

// Obtener datos del request
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    // Si no es JSON, intentar con POST normal
    $input = $_POST;
}

// Validar datos mínimos
if (!isset($input['tipo'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Falta el tipo de evento']);
    exit;
}

// Obtener información del cliente
$ip_address = get_client_ip();
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$url = $input['url'] ?? $_SERVER['HTTP_REFERER'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'];
$datos = $input['datos'] ?? [];
$nivel_riesgo = $input['nivel_riesgo'] ?? 'medio';

// Determinar si hay usuario logueado
$id_usuario = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? ($input['username'] ?? null);

// Detectar posibles ataques
$patrones_sospechosos = [
    'sql' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'UNION', '--', ';', '1=1'],
    'xss' => ['<script', 'javascript:', 'onerror=', 'onload='],
    'path' => ['../', '..\\', '/etc/', 'C:\\']
];

$nivel_riesgo_calculado = 'bajo';
$detalles_adicionales = [];

// Analizar datos en busca de patrones maliciosos
foreach ($_REQUEST as $key => $value) {
    if (is_string($value)) {
        $value_upper = strtoupper($value);
        
        // Buscar SQL injection
        foreach ($patrones_sospechosos['sql'] as $patron) {
            if (stripos($value_upper, $patron) !== false) {
                $nivel_riesgo_calculado = 'critico';
                $detalles_adicionales[] = "Posible SQL injection en campo: $key";
                break;
            }
        }
        
        // Buscar XSS
        foreach ($patrones_sospechosos['xss'] as $patron) {
            if (stripos($value, $patron) !== false) {
                $nivel_riesgo_calculado = 'alto';
                $detalles_adicionales[] = "Posible XSS en campo: $key";
                break;
            }
        }
    }
}

// Analizar URL en busca de path traversal
foreach ($patrones_sospechosos['path'] as $patron) {
    if (strpos($url, $patron) !== false) {
        $nivel_riesgo_calculado = 'critico';
        $detalles_adicionales[] = "Posible path traversal en URL";
        break;
    }
}

// Si se especificó un nivel de riesgo manual, usarlo, sino el calculado
$nivel_final = $input['nivel_riesgo'] ?? $nivel_riesgo_calculado;

// Preparar datos adicionales
$datos_json = json_encode([
    'input' => $datos,
    'request' => [
        'method' => $_SERVER['REQUEST_METHOD'],
        'query' => $_GET,
        'post' => $_POST,
        'files' => $_FILES ? 'presentes' : 'ninguno'
    ],
    'detecciones' => $detalles_adicionales,
    'timestamp' => time()
]);

// Registrar en base de datos
try {
    $stmt = $mysqli->prepare("INSERT INTO seguridad_logs 
                               (tipo_evento, id_usuario, username, ip_address, user_agent, url, metodo, datos_adicionales, nivel_riesgo) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $stmt->bind_param(
        "sisssssss",
        $input['tipo'],
        $id_usuario,
        $username,
        $ip_address,
        $user_agent,
        $url,
        $metodo,
        $datos_json,
        $nivel_final
    );
    
    $stmt->execute();
    
    // Si el nivel es crítico, enviar alerta (opcional)
    if ($nivel_final === 'critico') {
        // Aquí podrías enviar un email o notificación
        error_log("ALERTA DE SEGURIDAD CRÍTICA: " . $input['tipo'] . " desde IP: " . $ip_address);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Evento registrado',
        'nivel_riesgo' => $nivel_final,
        'id' => $stmt->insert_id
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Error al registrar evento',
        'message' => ENVIRONMENT === 'development' ? $e->getMessage() : 'Error interno'
    ]);
    
    // Log de error
    error_log("Error en user_log.php: " . $e->getMessage());
}