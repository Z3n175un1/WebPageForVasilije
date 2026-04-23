<?php
/**
 * Configuración central del sistema
 * Ubicación: /config.php
 */

// =============================================
// CARGAR VARIABLES DE ENTORNO (.env)
// =============================================
if (file_exists(__DIR__ . '/.env')) {
    $env_file = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($env_file as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// =============================================
// CONFIGURACIÓN DEL ENTORNO
// =============================================
define('ENVIRONMENT', getenv('APP_ENV') ?: 'development'); // development, production

// =============================================
// CONFIGURACIÓN DE LA BASE DE DATOS
// =============================================
define('DB_HOST', getenv('DB_HOST') ?: '');
define('DB_USER', getenv('DB_USERNAME') ?: '');
define('DB_PASS', getenv('DB_PASSWORD') ?: '');
define('DB_NAME', getenv('DB_DATABASE') ?: '');
define('DB_PORT', getenv('DB_PORT') ?: 5432);
define('DB_SCHEMA', getenv('DB_SCHEMA') ?: 'public');
define('DB_DRIVER', getenv('DB_DRIVER') ?: 'pgsql');
define('DB_CHARSET', 'utf8');

// =============================================
// CONFIGURACIÓN DE RUTAS (SEGÚN TU ESTRUCTURA)
// =============================================
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
$base_dir = str_replace('\\', '/', dirname($script_name));
define('BASE_URL', $protocol . $host . rtrim($base_dir, '/'));
define('BASE_PATH', __DIR__);

// Rutas principales
define('PUBLIC_URL', BASE_URL . '/public');
define('PUBLIC_PATH', BASE_PATH . '/public');

define('CONFIG_URL', BASE_URL . '/config');
define('CONFIG_PATH', BASE_PATH . '/config');

define('DATABASE_URL', BASE_URL . '/database');
define('DATABASE_PATH', BASE_PATH . '/database');

define('API_URL', BASE_URL . '/api');
define('API_PATH', BASE_PATH . '/api');

define('ERROR_URL', BASE_URL . '/error');
define('ERROR_PATH', BASE_PATH . '/error');

// =============================================
// CONFIGURACIÓN DE SEGURIDAD
// =============================================
define('SESSION_NAME', getenv('SESSION_NAME') ?: 'transporte_session');
define('SESSION_LIFETIME', getenv('SESSION_LIFETIME') ?: 7200); // 2 horas
define('CSRF_TOKEN_NAME', 'csrf_token');

// Claves para encriptación (desde .env)
define('ENCRYPTION_KEY', getenv('ENCRYPTION_KEY') ?: 'default_key_change_me_123456');
define('ENCRYPTION_IV', substr(ENCRYPTION_KEY, 0, 16));

// =============================================
// CONFIGURACIÓN DE ZONA HORARIA
// =============================================
date_default_timezone_set(getenv('TIMEZONE') ?: 'America/Lima');

// =============================================
// CONFIGURACIÓN DE ERRORES
// =============================================
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
} else { // production
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', BASE_PATH . '/error/error.log');
}

// =============================================
// CONFIGURACIÓN DE SESIÓN
// =============================================
if (session_status() == PHP_SESSION_NONE) {
    ini_set('session.name', SESSION_NAME);
    ini_set('session.cookie_lifetime', SESSION_LIFETIME);
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', ENVIRONMENT === 'production' ? 1 : 0);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

// =============================================
// FUNCIONES DE UTILIDAD
// =============================================

/**
 * Función para generar URL absolutas
 */
function url($path = '') {
    return BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Función para redirigir
 */
function redirect($url) {
    header('Location: ' . url($url));
    exit;
}

/**
 * Función para generar token CSRF
 */
function generate_csrf_token() {
    if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Función para verificar token CSRF
 */
function verify_csrf_token($token) {
    if (!isset($_SESSION[CSRF_TOKEN_NAME]) || $token !== $_SESSION[CSRF_TOKEN_NAME]) {
        return false;
    }
    return true;
}

/**
 * Función para sanitizar input
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * Función para loguear errores
 */
function log_error($message, $type = 'ERROR') {
    $log_file = BASE_PATH . '/error/error.log';
    $log_dir = dirname($log_file);
    
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0777, true);
    }
    
    $log_entry = '[' . date('Y-m-d H:i:s') . '] [' . $type . '] ' . $message . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

/**
 * Función para obtener configuración de la app
 */
function app_config($key, $default = null) {
    static $config = null;
    
    if ($config === null) {
        $config = [
            'app_name' => getenv('APP_NAME') ?: 'Sistema de Gestión de Transporte',
            'app_version' => '1.0.0',
            'items_per_page' => 20,
            'date_format' => 'd/m/Y',
            'datetime_format' => 'd/m/Y H:i:s',
            'currency_symbol' => 'S/',
            'decimal_places' => 2,
            'thousand_separator' => ',',
            'decimal_separator' => '.',
            'maintenance_mode' => false
        ];
    }
    
    return isset($config[$key]) ? $config[$key] : $default;
}

/**
 * Función para formatear moneda
 */
function format_currency($amount) {
    return app_config('currency_symbol') . ' ' . number_format(
        (float)$amount,
        app_config('decimal_places'),
        app_config('decimal_separator'),
        app_config('thousand_separator')
    );
}

/**
 * Función para formatear fecha
 */
function format_date($date, $format = null) {
    if (!$date) return '-';
    $format = $format ?: app_config('date_format');
    $timestamp = is_numeric($date) ? $date : strtotime($date);
    return date($format, $timestamp);
}

/**
 * Función para verificar si es AJAX
 */
function is_ajax() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

/**
 * Función para responder JSON
 */
function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Función para verificar modo mantenimiento
 */
function is_maintenance_mode() {
    return file_exists(BASE_PATH . '/error/maintenance.php') && app_config('maintenance_mode');
}

/**
 * Función para obtener IP del cliente
 */
function get_client_ip() {
    $ipaddress = '';
    if (isset($_SERVER['HTTP_CLIENT_IP']))
        $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
    else if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
        $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
    else if(isset($_SERVER['HTTP_X_FORWARDED']))
        $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
    else if(isset($_SERVER['HTTP_FORWARDED_FOR']))
        $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
    else if(isset($_SERVER['HTTP_FORWARDED']))
        $ipaddress = $_SERVER['HTTP_FORWARDED'];
    else if(isset($_SERVER['REMOTE_ADDR']))
        $ipaddress = $_SERVER['REMOTE_ADDR'];
    else
        $ipaddress = 'UNKNOWN';
    return $ipaddress;
}

/**
 * Función para obtener información del sistema
 */
function system_info() {
    return [
        'environment' => ENVIRONMENT,
        'php_version' => PHP_VERSION,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'database' => DB_NAME,
        'base_url' => BASE_URL,
        'date_time' => date('Y-m-d H:i:s'),
        'timezone' => date_default_timezone_get()
    ];
}

/**
 * Función para registrar eventos de seguridad
 * MODIFICACIÓN: Manejo más robusto de la conexión a BD
 */
function log_security_event($tipo, $id_usuario = null, $username = null, $ip_address = null, $user_agent = null, $url = null, $metodo = null, $datos = null, $nivel_riesgo = 'bajo') {
    
    // Valores por defecto
    if (!$ip_address) {
        $ip_address = get_client_ip();
    }
    
    if (!$user_agent) {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
    
    if (!$url) {
        $url = $_SERVER['REQUEST_URI'] ?? '';
    }
    
    if (!$metodo) {
        $metodo = $_SERVER['REQUEST_METHOD'] ?? '';
    }
    
    // Preparar datos adicionales
    $datos_json = null;
    if ($datos) {
        if (is_array($datos)) {
            $datos_json = json_encode($datos);
        } else {
            $datos_json = json_encode(['message' => $datos, 'time' => time()]);
        }
    }
    
    // Intentar guardar en BD
    try {
        // Intentar obtener conexión global
        global $mysqli;
        
        // Verificar si la conexión existe y está activa
        if (isset($mysqli) && $mysqli && !$mysqli->connect_error) {
            $stmt = $mysqli->prepare("INSERT INTO seguridad_logs 
                                        (tipo_evento, id_usuario, username, ip_address, user_agent, url, metodo, datos_adicionales, nivel_riesgo) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            if ($stmt) {
                $stmt->bind_param("sisssssss", 
                    $tipo, 
                    $id_usuario, 
                    $username, 
                    $ip_address, 
                    $user_agent, 
                    $url, 
                    $metodo, 
                    $datos_json, 
                    $nivel_riesgo
                );
                $stmt->execute();
                $stmt->close();
            }
        }
    } catch (Exception $e) {
        // Si falla el logging en BD, al menos registrar en error_log
        error_log("Security Event: [$tipo] [$nivel_riesgo] IP: $ip_address - " . ($datos ?? ''));
    }
    
    // También registrar en error_log para respaldo si es crítico
    if ($nivel_riesgo === 'critico' || $nivel_riesgo === 'alto') {
        error_log("SECURITY [$nivel_riesgo]: $tipo desde IP: $ip_address - Usuario: " . ($username ?? 'anónimo'));
    }
}
?>