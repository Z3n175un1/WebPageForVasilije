<?php
/**
 * Panel de logs de seguridad
 * Ubicación: /admin/security_logs.php
 * (Solo accesible para admin)
 */

require_once __DIR__ . '/../config.php';
require_once CONFIG_PATH . '/conn.php';

// Verificar que sea admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    redirect('auth/login.php');
}

// Filtros
$filtro_tipo = $_GET['tipo'] ?? '';
$filtro_riesgo = $_GET['riesgo'] ?? '';
$filtro_fecha = $_GET['fecha'] ?? date('Y-m-d');

$sql = "SELECT l.*, u.username as user_name 
        FROM seguridad_logs l 
        LEFT JOIN usuarios u ON l.id_usuario = u.id_usuario 
        WHERE DATE(l.fecha_evento) = ?";
$params = [$filtro_fecha];
$types = "s";

if ($filtro_tipo) {
    $sql .= " AND l.tipo_evento = ?";
    $params[] = $filtro_tipo;
    $types .= "s";
}

if ($filtro_riesgo) {
    $sql .= " AND l.nivel_riesgo = ?";
    $params[] = $filtro_riesgo;
    $types .= "s";
}

$sql .= " ORDER BY l.fecha_evento DESC LIMIT 100";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$logs = $stmt->get_result();

// Estadísticas
$stats = $mysqli->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN nivel_riesgo = 'critico' THEN 1 ELSE 0 END) as criticos,
        SUM(CASE WHEN nivel_riesgo = 'alto' THEN 1 ELSE 0 END) as altos,
        SUM(CASE WHEN DATE(fecha_evento) = CURDATE() THEN 1 ELSE 0 END) as hoy
    FROM seguridad_logs
")->fetch_assoc();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Logs de Seguridad</title>
    <style>
        /* Estilos similares al sistema principal */
    </style>
</head>
<body>
    <div class="container">
        <h1>🔒 Logs de Seguridad</h1>
        
        <div class="stats">
            <div class="stat-card">Total: <?php echo $stats['total']; ?></div>
            <div class="stat-card critico">Críticos: <?php echo $stats['criticos']; ?></div>
            <div class="stat-card alto">Altos: <?php echo $stats['altos']; ?></div>
            <div class="stat-card">Hoy: <?php echo $stats['hoy']; ?></div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>IP</th>
                    <th>Usuario</th>
                    <th>Tipo</th>
                    <th>Riesgo</th>
                    <th>Detalles</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($log = $logs->fetch_assoc()): ?>
                <tr class="riesgo-<?php echo $log['nivel_riesgo']; ?>">
                    <td><?php echo date('d/m/Y H:i:s', strtotime($log['fecha_evento'])); ?></td>
                    <td><?php echo $log['ip_address']; ?></td>
                    <td><?php echo $log['user_name'] ?: $log['username']; ?></td>
                    <td><?php echo $log['tipo_evento']; ?></td>
                    <td><span class="badge <?php echo $log['nivel_riesgo']; ?>"><?php echo $log['nivel_riesgo']; ?></span></td>
                    <td><pre><?php echo json_encode(json_decode($log['datos_adicionales']), JSON_PRETTY_PRINT); ?></pre></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</body>
</html>