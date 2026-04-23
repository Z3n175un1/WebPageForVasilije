<?php
/**
 * Cerrar sesión
 * Ubicación: /auth/logout.php
 */

require_once __DIR__ . '/../config.php';
require_once CONFIG_PATH . '/conn.php';

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $session_id = session_id();
    $ip_address = get_client_ip();
    
    // Registrar logout
    log_security_event('logout', $user_id, $_SESSION['username'] ?? null, $ip_address, $_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REQUEST_URI'], 'GET', 'Logout voluntario', 'bajo');
    
    // Marcar sesión como inactiva
    $stmt = $mysqli->prepare("UPDATE sesiones_activas SET activa = FALSE, ultima_actividad = NOW() WHERE session_id = ?");
    $stmt->bind_param("s", $session_id);
    $stmt->execute();
}

// Destruir sesión
$_SESSION = array();
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// Redirigir al login
redirect('auth/login.php');