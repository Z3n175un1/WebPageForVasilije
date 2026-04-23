<?php
/**
 * Conexión a base de datos PostgreSQL
 * Ubicación: /config/conn.php
 * Usa PDO con PostgreSQL y schema global
 */

if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../config.php';
}

try {
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Establecer search_path al schema global
    $pdo->exec("SET search_path TO global, public");

} catch (PDOException $e) {
    error_log("Error de conexión a BD PostgreSQL: " . $e->getMessage());

    if (php_sapi_name() !== 'cli') {
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api') !== false) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'error'   => true,
                'message' => 'Error de conexión a la base de datos: ' . $e->getMessage()
            ]);
            exit(1);
        }

        if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
            echo "<div style='color:red;padding:10px;border:1px solid red;border-radius:5px;margin:10px;'>";
            echo "❌ Error de conexión PostgreSQL.<br>";
            echo "<small>" . htmlspecialchars($e->getMessage()) . "</small>";
            echo "</div>";
        } else {
            echo "<div style='color:red;padding:10px;border:1px solid red;border-radius:5px;margin:10px;'>❌ Error de conexión a la base de datos.</div>";
        }
    } else {
        echo "Error de conexión: " . $e->getMessage() . "\n";
    }
    exit(1);
}

/**
 * Helper para obtener la conexión PDO
 */
function getConnection(): PDO {
    global $pdo;
    return $pdo;
}
?>