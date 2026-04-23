<?php
/**
 * API REST — Sistema de Gestión de Transporte
 * Backend: PostgreSQL con schema global
 * /api/api.php
 */
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/conn.php';

class API {
    private PDO $pdo;
    private string $method;
    private string $endpoint;
    private array $params;
    private ?array $input;

    public function __construct(PDO $pdo) {
        $this->pdo      = $pdo;
        $this->method   = $_SERVER['REQUEST_METHOD'];
        $this->endpoint = $this->parseEndpoint();
        $this->params   = $_GET;
        $rawInput       = file_get_contents('php://input');
        $this->input    = $rawInput ? json_decode($rawInput, true) : [];
        
        // Ensure schema exists (minimal check)
        $this->pdo->exec("CREATE SCHEMA IF NOT EXISTS global;");
    }

    private function parseEndpoint(): string {
        $uri  = $_SERVER['REQUEST_URI'] ?? '';
        $path = explode('?', $uri)[0];
        $pos  = strpos($path, '/api/');
        return trim(($pos !== false) ? substr($path, $pos + 5) : $path, '/');
    }

    /**
     * Helper para ejecutar consultas y retornar JSON
     */
    private function sendResponse(array $data, int $code = 200): array {
        return array_merge(['success' => true], $data);
    }

    /**
     * Helper para consultas preparadas
     */
    private function query(string $sql, array $params = []): PDOStatement {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Helper para obtener un registro por ID
     */
    private function findById(string $table, int $id, string $idColumn = ''): ?array {
        $idCol = $idColumn ?: "id_" . rtrim($table, 's');
        if ($table === 'inventario') $idCol = 'id_inventario';
        if ($table === 'personal') $idCol = 'id_personal';
        
        return $this->query("SELECT * FROM global.$table WHERE $idCol = :id", [':id' => $id])->fetch() ?: null;
    }

    /**
     * Formatea recursivamente todos los IDs encontrados en la respuesta
     */
    private function formatResponseIds(array $data): array {
        $prefixes = [
            'id_usuario'       => 'US', 'id_vehiculo' => 'VH', 'id_gasto' => 'GA',
            'id_personal'      => 'PE', 'id_ingreso' => 'IN', 'id_inventario' => 'AL',
            'id_producto'      => 'AL', 'id_tramo' => 'TR', 'id_clasificacion' => 'CL',
            'id_patrimonio'    => 'PT'
        ];

        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                $value = $this->formatResponseIds($value);
            } elseif (isset($prefixes[$key]) && is_numeric($value)) {
                $data[$key . '_format'] = $prefixes[$key] . '-' . str_pad((string)$value, 6, '0', STR_PAD_LEFT);
            }
        }
        return $data;
    }

    public function dispatch(): void {
        try {
            $response = $this->route();
            if (isset($response['success']) && $response['success']) {
                $response = $this->formatResponseIds($response);
            }
            http_response_code(200);
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            $code = (int)$e->getCode() ?: 500;
            if ($code < 100 || $code > 599) $code = 500;
            http_response_code($code);
            echo json_encode(['error' => true, 'message' => $e->getMessage(), 'code' => $code], JSON_UNESCAPED_UNICODE);
        }
    }

    private function route(): array {
        $parts = explode('/', $this->endpoint);
        $base  = $parts[0] ?? '';
        $id    = $parts[1] ?? null;
        if ($id && is_numeric($id)) $this->params['id'] = (int)$id;

        $handlers = [
            'dashboard'      => 'getDashboardStats',
            'vehiculos'      => 'handleVehiculos',
            'gastos'         => 'handleGastos',
            'almacen'        => 'handleAlmacen',
            'clasificacion'  => 'handleClasificacion',
            'combustible'    => 'handleCombustible',
            'personal'       => 'handlePersonal',
            'tramos'         => 'handleTramos',
            'reportes'       => 'handleReportes',
            'ingresos'       => 'handleIngresos',
            'proveedores'    => 'handleProveedores',
            'auth'           => 'handleAuth'
        ];

        if (isset($handlers[$base])) {
            $method = $handlers[$base];
            if ($base === 'reportes' && ($parts[1] ?? '') === 'filtro') return $this->handleFiltroReportes();
            return $this->$method();
        }

        throw new Exception("Endpoint '$this->endpoint' no encontrado", 404);
    }

    private function handleAuth(): array {
        $sub = explode('/', $this->endpoint)[1] ?? '';
        if ($sub === 'login') return $this->handleLogin();
        if ($sub === 'me') return $this->getCurrentUser();
        if ($sub === 'logout') return $this->handleLogout();
        throw new Exception("Sub-endpoint auth '$sub' no encontrado", 404);
    }

    // ================================================================
    // DASHBOARD STATS
    // ================================================================
    private function getDashboardStats(): array {
        // Vehículos
        $stmtV = $this->pdo->query("SELECT COUNT(*) as total,
            SUM(CASE WHEN estado = 1 THEN 1 ELSE 0 END) as activos,
            SUM(CASE WHEN estado = 0 THEN 1 ELSE 0 END) as inactivos,
            SUM(CASE WHEN estado = 2 THEN 1 ELSE 0 END) as en_taller
            FROM global.vehiculos");
        $vehiculos = $stmtV->fetch();

        // Gastos totales del mes actual
        $stmtG = $this->pdo->query("SELECT
            COUNT(*) as cantidad,
            COALESCE(SUM(monto), 0) as total_mes,
            COALESCE(SUM(CASE WHEN tipo_gasto = 'Combustible' THEN monto ELSE 0 END), 0) as combustible,
            COALESCE(SUM(CASE WHEN tipo_gasto = 'Mantenimiento' THEN monto ELSE 0 END), 0) as mantenimiento,
            COALESCE(SUM(CASE WHEN tipo_gasto = 'Sueldos' THEN monto ELSE 0 END), 0) as sueldos,
            COALESCE(SUM(CASE WHEN tipo_gasto = 'Peaje' THEN monto ELSE 0 END), 0) as peajes
            FROM global.gastos
            WHERE DATE_TRUNC('month', fecha_gasto) = DATE_TRUNC('month', CURRENT_DATE)");
        $gastos = $stmtG->fetch();

        // Total gastos global
        $stmtTot = $this->pdo->query("SELECT COALESCE(SUM(monto), 0) as total_general FROM global.gastos");
        $totalGeneral = $stmtTot->fetch()['total_general'];

        // Gastos por tipo (para chart)
        $stmtTipo = $this->pdo->query("SELECT tipo_gasto, COALESCE(SUM(monto), 0) as total
            FROM global.gastos
            GROUP BY tipo_gasto ORDER BY total DESC");
        $porTipo = $stmtTipo->fetchAll();

        // Último período clasificado
        $stmtClas = $this->pdo->query("SELECT COUNT(*) as total,
            COALESCE(SUM(total_gastos), 0) as total_clasificado
            FROM global.clasificacion");
        $clasificacion = $stmtClas->fetch();

        // Total operaciones global (para secuencial RE-)
        $totalOperaciones = 0;
        try {
            $stmtOp1 = $this->pdo->query("SELECT COUNT(*) FROM global.gastos");
            $totalOperaciones += (int)$stmtOp1->fetchColumn();
            $stmtOp2 = $this->pdo->query("SELECT COUNT(*) FROM global.ingresos");
            $totalOperaciones += (int)$stmtOp2->fetchColumn();
            // Si no hay tabla de movimientos, no sumamos nada o sumamos inventario si aplica
        } catch (Exception $e) {}

        return [
            'success' => true,
            'data' => [
                'total_operaciones' => $totalOperaciones,
                'vehiculos' => [
                    'total'    => (int)$vehiculos['total'],
                    'activos'  => (int)$vehiculos['activos'],
                    'inactivos'=> (int)$vehiculos['inactivos'],
                    'en_taller'=> (int)$vehiculos['en_taller'],
                ],
                'gastos_mes' => [
                    'cantidad'      => (int)$gastos['cantidad'],
                    'total'         => (float)$gastos['total_mes'],
                    'combustible'   => (float)$gastos['combustible'],
                    'mantenimiento' => (float)$gastos['mantenimiento'],
                    'sueldos'       => (float)$gastos['sueldos'],
                    'peajes'        => (float)$gastos['peajes'],
                ],
                'total_general'  => (float)$totalGeneral,
                'gastos_por_tipo'=> $porTipo,
                'clasificacion'  => [
                    'total'           => (int)$clasificacion['total'],
                    'total_clasificado'=> (float)$clasificacion['total_clasificado'],
                ],
            ]
        ];
    }

    // ================================================================
    // VEHÍCULOS
    // ================================================================
    private function handleVehiculos(): array {
        $id = $this->params['id'] ?? null;
        switch ($this->method) {
            case 'GET':  return $id ? $this->obtenerVehiculoPorId((int)$id) : $this->listarVehiculos();
            case 'POST': return $this->crearVehiculo();
            case 'PUT':  return $this->actualizarVehiculo();
            case 'DELETE': return $this->eliminarVehiculo();
            default: throw new Exception('Método no permitido', 405);
        }
    }

    private function obtenerVehiculoPorId(int $id): array {
        $stmt = $this->pdo->prepare("
            SELECT v.*, 
                   COALESCE(
                       (SELECT split_part(trim(p.nombres), ' ', 1) || ' ' || split_part(trim(p.apellidos), ' ', 1) 
                        FROM global.personal p WHERE p.id_personal = v.id_personal),
                       v.conductor
                   ) as conductor_formateado
            FROM global.vehiculos v WHERE id_vehiculo = :id
        ");
        $stmt->execute([':id' => $id]);
        $data = $stmt->fetch();
        if (!$data) throw new Exception('Vehículo no encontrado', 404);
        
        $data['conductor'] = $data['conductor_formateado'] ?: ($data['conductor'] ?: 'SIN ASIGNAR');
        
        return ['success' => true, 'data' => $data];
    }

    private function listarVehiculos(): array {
        $page   = max(1, (int)($this->params['page'] ?? 1));
        $limit  = min(100, (int)($this->params['limit'] ?? 50));
        $offset = ($page - 1) * $limit;
        $search = $this->params['busqueda'] ?? '';
        $estado = $this->params['estado'] ?? '';

        $where  = ['1=1'];
        $bind   = [];

        if ($search) {
            $where[] = "(placa_vehiculo ILIKE :search OR conductor ILIKE :search OR tipo_vehiculo ILIKE :search)";
            $bind[':search'] = "%$search%";
        }
        if ($estado !== '') {
            $where[] = "estado = :estado";
            $bind[':estado'] = (int)$estado;
        }

        $whereSQL = implode(' AND ', $where);

        // Total
        $stmtCount = $this->pdo->prepare("SELECT COUNT(*) FROM global.vehiculos WHERE $whereSQL");
        $stmtCount->execute($bind);
        $total = (int)$stmtCount->fetchColumn();

        // Data
        $bind[':limit']  = $limit;
        $bind[':offset'] = $offset;
        $stmt = $this->pdo->prepare(
            "SELECT v.*, 
                    COALESCE(
                        (SELECT split_part(trim(p.nombres), ' ', 1) || ' ' || split_part(trim(p.apellidos), ' ', 1) 
                         FROM global.personal p WHERE p.id_personal = v.id_personal),
                        v.conductor
                    ) as conductor_formateado
             FROM global.vehiculos v WHERE $whereSQL
             ORDER BY fecha_registro DESC LIMIT :limit OFFSET :offset"
        );
        $stmt->execute($bind);
        $data = $stmt->fetchAll();

        // Mapear conductor formateado al campo conductor para el frontend
        foreach ($data as &$v) {
            $v['conductor'] = $v['conductor_formateado'] ?: ($v['conductor'] ?: 'SIN ASIGNAR');
        }

        return [
            'success' => true,
            'data'    => $data,
            'pagination' => [
                'page'  => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ]
        ];
    }

    private function crearVehiculo(): array {
        $required = ['placa_vehiculo', 'tipo_vehiculo'];
        foreach ($required as $field) {
            if (empty($this->input[$field])) {
                throw new Exception("El campo '$field' es requerido", 400);
            }
        }

        $idp = (int)($this->input['id_personal'] ?? 0);
        
        if ($idp) {
            // Verificar si el chofer ya está asignado a otra unidad
            $stmtCheck = $this->pdo->prepare("SELECT placa_vehiculo FROM global.vehiculos WHERE id_personal = :idp");
            $stmtCheck->execute([':idp' => $idp]);
            $existente = $stmtCheck->fetch();
            if ($existente) {
                throw new Exception("El chofer ya está asignado a la unidad: " . $existente['placa_vehiculo'] . ". Debe desasignarlo primero.", 400);
            }

            $stmtP = $this->pdo->prepare("SELECT nombres, apellidos FROM global.personal WHERE id_personal = :id");
            $stmtP->execute([':id' => $idp]);
            $p = $stmtP->fetch();
            if ($p) {
                $n1 = explode(' ', trim($p['nombres']))[0];
                $a1 = explode(' ', trim($p['apellidos']))[0];
                $conductor = "$n1 $a1";
            }
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO global.vehiculos (placa_vehiculo, anho, tipo_vehiculo, conductor, tramo_actual, estado, marca, modelo, color, observaciones, id_personal, kilometraje, capacidad)
            VALUES (:placa, :anho, :tipo, :conductor, :tramo, :estado, :marca, :modelo, :color, :obs, :idp, :km, :cap)
            RETURNING id_vehiculo
        ");
        $stmt->execute([
            ':placa'     => strtoupper(trim($this->input['placa_vehiculo'])),
            ':anho'      => (int)($this->input['anho'] ?? date('Y')),
            ':tipo'      => $this->input['tipo_vehiculo'],
            ':conductor' => $conductor,
            ':tramo'     => $this->input['tramo_actual'] ?? null,
            ':estado'    => (int)($this->input['estado'] ?? 1),
            ':marca'     => $this->input['marca'] ?? null,
            ':modelo'    => $this->input['modelo'] ?? null,
            ':color'     => $this->input['color'] ?? null,
            ':obs'       => $this->input['observaciones'] ?? null,
            ':idp'       => $idp ?: null,
            ':km'        => (float)($this->input['kilometraje'] ?? 0),
            ':cap'       => (float)($this->input['capacidad'] ?? 0),
        ]);

        $id = $stmt->fetchColumn();

        return [
            'success' => true,
            'id'      => (int)$id,
            'message' => 'Vehículo registrado exitosamente'
        ];
    }
    private function actualizarVehiculo(): array {
        $id = $this->params['id'] ?? null;
        if (!$id) throw new Exception('ID requerido', 400);

        $idp = (int)($this->input['id_personal'] ?? 0);
        $conductor = $this->input['conductor'] ?? null;

        if ($idp) {
            // Verificar si el chofer ya está asignado a OTRA unidad
            $stmtCheck = $this->pdo->prepare("SELECT placa_vehiculo FROM global.vehiculos WHERE id_personal = :idp AND id_vehiculo != :id");
            $stmtCheck->execute([':idp' => $idp, ':id' => (int)$id]);
            $existente = $stmtCheck->fetch();
            if ($existente) {
                throw new Exception("El chofer ya está asignado a la unidad: " . $existente['placa_vehiculo'] . ". Debe desasignarlo primero.", 400);
            }

            $stmtP = $this->pdo->prepare("SELECT nombres, apellidos FROM global.personal WHERE id_personal = :id");
            $stmtP->execute([':id' => $idp]);
            $p = $stmtP->fetch();
            if ($p) {
                $n1 = explode(' ', trim($p['nombres']))[0];
                $a1 = explode(' ', trim($p['apellidos']))[0];
                $conductor = "$n1 $a1";
            }
        }

        $stmt = $this->pdo->prepare("
            UPDATE global.vehiculos
            SET placa_vehiculo = :placa, anho = :anho, tipo_vehiculo = :tipo,
                conductor = :conductor, tramo_actual = :tramo, estado = :estado,
                marca = :marca, modelo = :modelo, color = :color, observaciones = :obs,
                id_personal = :idp, kilometraje = :km, capacidad = :cap,
                fecha_actualizacion = CURRENT_TIMESTAMP
            WHERE id_vehiculo = :id
        ");
        $stmt->execute([
            ':id'        => (int)$id,
            ':placa'     => strtoupper(trim($this->input['placa_vehiculo'])),
            ':anho'      => (int)($this->input['anho'] ?? date('Y')),
            ':tipo'      => $this->input['tipo_vehiculo'],
            ':conductor' => $conductor,
            ':tramo'     => $this->input['tramo_actual'] ?? null,
            ':estado'    => (int)($this->input['estado'] ?? 1),
            ':marca'     => $this->input['marca'] ?? null,
            ':modelo'    => $this->input['modelo'] ?? null,
            ':color'     => $this->input['color'] ?? null,
            ':obs'       => $this->input['observaciones'] ?? null,
            ':idp'       => $idp ?: null,
            ':km'        => (float)($this->input['kilometraje'] ?? 0),
            ':cap'       => (float)($this->input['capacidad'] ?? 0),
        ]);

        return ['success' => true, 'message' => 'Vehículo actualizado exitosamente'];
    }

    private function eliminarVehiculo(): array {
        $id = $this->params['id'] ?? null;
        if (!$id) throw new Exception('ID requerido', 400);

        $stmt = $this->pdo->prepare("UPDATE global.vehiculos SET estado = 3 WHERE id_vehiculo = :id");
        $stmt->execute([':id' => (int)$id]);

        return ['success' => true, 'message' => 'Vehículo marcado como vendido'];
    }

    // ================================================================
    // GASTOS
    // ================================================================
    private function handleGastos(): array {
        $id = $this->params['id'] ?? null;
        switch ($this->method) {
            case 'GET':    return $id ? $this->obtenerGastoPorId((int)$id) : $this->listarGastos();
            case 'POST':   return $this->crearGasto();
            case 'PUT':    return $this->actualizarGasto();
            case 'DELETE': return $this->eliminarGasto();
            default: throw new Exception('Método no permitido', 405);
        }
    }

    private function obtenerGastoPorId(int $id): array {
        $stmt = $this->pdo->prepare("
            SELECT g.*, v.placa_vehiculo
            FROM global.gastos g
            LEFT JOIN global.vehiculos v ON g.id_vehiculo = v.id_vehiculo
            WHERE g.id_gasto = :id
        ");
        $stmt->execute([':id' => $id]);
        $data = $stmt->fetch();
        if (!$data) throw new Exception('Gasto no encontrado', 404);
        return ['success' => true, 'data' => $data];
    }

    private function actualizarGasto(): array {
        $id = (int)($this->params['id'] ?? 0);
        if (!$id) throw new Exception('ID requerido', 400);

        $stmt = $this->pdo->prepare("
            UPDATE global.gastos SET
                id_vehiculo = :id_vehiculo,
                tipo_gasto = :tipo_gasto,
                concepto = :concepto,
                descripcion = :descripcion,
                monto = :monto,
                cantidad = :cantidad,
                tipo_combustible = :tipo_combustible,
                kilometraje = :kilometraje,
                caseta = :caseta,
                ruta = :ruta,
                taller = :taller,
                tipo_mantenimiento = :tipo_mantenimiento,
                fecha_gasto = :fecha_gasto,
                comprobante = :comprobante,
                proveedor = :proveedor,
                estado_pago = :estado_pago,
                tipo_pago = :tipo_pago,
                observaciones = :observaciones,
                fecha_actualizacion = CURRENT_TIMESTAMP
            WHERE id_gasto = :id
        ");
        $stmt->execute([
            ':id'                => $id,
            ':id_vehiculo'       => (int)$this->input['id_vehiculo'],
            ':tipo_gasto'        => $this->input['tipo_gasto'],
            ':concepto'          => $this->input['concepto'],
            ':descripcion'       => $this->input['descripcion'] ?? null,
            ':monto'             => (float)$this->input['monto'],
            ':cantidad'          => $this->input['cantidad'] ?? 1,
            ':tipo_combustible'  => $this->input['tipo_combustible'] ?? null,
            ':kilometraje'       => $this->input['kilometraje'] ?? null,
            ':caseta'            => $this->input['caseta'] ?? null,
            ':ruta'              => $this->input['ruta'] ?? null,
            ':taller'            => $this->input['taller'] ?? null,
            ':tipo_mantenimiento'=> $this->input['tipo_mantenimiento'] ?? null,
            ':fecha_gasto'       => $this->input['fecha_gasto'],
            ':comprobante'       => $this->input['comprobante'] ?? null,
            ':proveedor'         => $this->input['proveedor'] ?? null,
            ':estado_pago'       => $this->input['estado_pago'] ?? 'Pagado',
            ':tipo_pago'         => $this->input['tipo_pago'] ?? 'Efectivo',
            ':observaciones'     => $this->input['observaciones'] ?? null,
        ]);

        return ['success' => true, 'message' => 'Gasto actualizado exitosamente'];
    }

    private function eliminarGasto(): array {
        $id = (int)($this->params['id'] ?? 0);
        if (!$id) throw new Exception('ID requerido', 400);
        $stmt = $this->pdo->prepare("DELETE FROM global.gastos WHERE id_gasto = :id");
        $stmt->execute([':id' => $id]);
        return ['success' => true, 'message' => 'Gasto eliminado exitosamente'];
    }

    private function listarGastos(): array {
        $page       = max(1, (int)($this->params['page'] ?? 1));
        $limit      = min(100, (int)($this->params['limit'] ?? 50));
        $offset     = ($page - 1) * $limit;
        $vehiculoId = $this->params['id_vehiculo'] ?? '';
        $tipoGasto  = $this->params['tipo_gasto'] ?? '';
        $fechaInicio= $this->params['fecha_inicio'] ?? '';
        $fechaFin   = $this->params['fecha_fin'] ?? '';

        $where = ['1=1'];
        $bind  = [];

        if ($vehiculoId) {
            $where[] = "g.id_vehiculo = :vid";
            $bind[':vid'] = (int)$vehiculoId;
        }
        if ($tipoGasto) {
            $where[] = "g.tipo_gasto = :tipo";
            $bind[':tipo'] = $tipoGasto;
        }
        if ($fechaInicio) {
            $where[] = "g.fecha_gasto >= :fi";
            $bind[':fi'] = $fechaInicio;
        }
        if ($fechaFin) {
            $where[] = "g.fecha_gasto <= :ff";
            $bind[':ff'] = $fechaFin;
        }

        $whereSQL = implode(' AND ', $where);

        $stmtCount = $this->pdo->prepare(
            "SELECT COUNT(*) FROM global.gastos g WHERE $whereSQL"
        );
        $stmtCount->execute($bind);
        $total = (int)$stmtCount->fetchColumn();

        $bind[':limit']  = $limit;
        $bind[':offset'] = $offset;
        $stmt = $this->pdo->prepare("
            SELECT g.*, v.placa_vehiculo, v.conductor
            FROM global.gastos g
            LEFT JOIN global.vehiculos v ON g.id_vehiculo = v.id_vehiculo
            WHERE $whereSQL
            ORDER BY g.fecha_gasto DESC, g.id_gasto DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->execute($bind);
        $data = $stmt->fetchAll();

        return [
            'success' => true,
            'data'    => $data,
            'pagination' => [
                'page'  => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ]
        ];
    }

    private function crearGasto(): array {
        $required = ['id_vehiculo', 'tipo_gasto', 'concepto', 'monto', 'fecha_gasto'];
        foreach ($required as $field) {
            if (!isset($this->input[$field]) || $this->input[$field] === '') {
                throw new Exception("El campo '$field' es requerido", 400);
            }
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO global.gastos (
                id_vehiculo, tipo_gasto, concepto, descripcion, monto,
                cantidad, tipo_combustible, kilometraje, caseta, ruta,
                taller, tipo_mantenimiento, fecha_gasto, comprobante,
                proveedor, id_proveedor, estado_pago, tipo_pago, observaciones
            ) VALUES (
                :id_vehiculo, :tipo_gasto, :concepto, :descripcion, :monto,
                :cantidad, :tipo_combustible, :kilometraje, :caseta, :ruta,
                :taller, :tipo_mantenimiento, :fecha_gasto, :comprobante,
                :proveedor, :id_proveedor, :estado_pago, :tipo_pago, :observaciones
            ) RETURNING id_gasto
        ");
        $stmt->execute([
            ':id_vehiculo'       => (int)$this->input['id_vehiculo'],
            ':tipo_gasto'        => $this->input['tipo_gasto'],
            ':concepto'          => $this->input['concepto'],
            ':descripcion'       => $this->input['descripcion'] ?? null,
            ':monto'             => (float)$this->input['monto'],
            ':cantidad'          => $this->input['cantidad'] ?? 1,
            ':tipo_combustible'  => $this->input['tipo_combustible'] ?? null,
            ':kilometraje'       => $this->input['kilometraje'] ?? null,
            ':caseta'            => $this->input['caseta'] ?? null,
            ':ruta'              => $this->input['ruta'] ?? null,
            ':taller'            => $this->input['taller'] ?? null,
            ':tipo_mantenimiento'=> $this->input['tipo_mantenimiento'] ?? null,
            ':fecha_gasto'       => $this->input['fecha_gasto'],
            ':comprobante'       => $this->input['comprobante'] ?? null,
            ':proveedor'         => $this->input['proveedor'] ?? null,
            ':id_proveedor'      => (int)($this->input['id_proveedor'] ?? null) ?: null,
            ':estado_pago'       => $this->input['estado_pago'] ?? 'Pagado',
            ':tipo_pago'         => $this->input['tipo_pago'] ?? 'Efectivo',
            ':observaciones'     => $this->input['observaciones'] ?? null,
        ]);

        $id = $stmt->fetchColumn();

        // Si es combustible, insertar detalle
        if ($this->input['tipo_gasto'] === 'Combustible' && !empty($this->input['galones'])) {
            $stmtDet = $this->pdo->prepare("
                INSERT INTO global.combustible_detalle
                (id_gasto, tipo_carburante, galones, precio_por_galon, estacion_servicio,
                 kilometraje_actual, kilometraje_anterior)
                VALUES (:id_gasto, :tipo_carburante, :galones, :ppg, :estacion, :km_actual, :km_anterior)
            ");
            $stmtDet->execute([
                ':id_gasto'       => (int)$id,
                ':tipo_carburante'=> $this->input['tipo_combustible'] ?? 'Diésel',
                ':galones'        => (float)$this->input['galones'],
                ':ppg'            => (float)($this->input['precio_por_galon'] ?? 0),
                ':estacion'       => $this->input['estacion_servicio'] ?? null,
                ':km_actual'      => $this->input['kilometraje_actual'] ?? null,
                ':km_anterior'    => $this->input['kilometraje_anterior'] ?? null,
            ]);
        }

        return [
            'success' => true,
            'id'      => (int)$id,
            'message' => 'Gasto registrado exitosamente'
        ];
    }

    // ================================================================
    // CLASIFICACIÓN
    // ================================================================
    private function handleClasificacion(): array {
        switch ($this->method) {
            case 'GET':  return $this->listarClasificacion();
            case 'POST': return $this->crearClasificacion();
            default: throw new Exception('Método no permitido', 405);
        }
    }

    private function listarClasificacion(): array {
        $page   = max(1, (int)($this->params['page'] ?? 1));
        $limit  = min(100, (int)($this->params['limit'] ?? 50));
        $offset = ($page - 1) * $limit;

        $stmtCount = $this->pdo->query("SELECT COUNT(*) FROM global.clasificacion");
        $total = (int)$stmtCount->fetchColumn();

        $stmt = $this->pdo->prepare("
            SELECT c.*, v.placa_vehiculo, v.conductor
            FROM global.clasificacion c
            LEFT JOIN global.vehiculos v ON c.id_vehiculo = v.id_vehiculo
            ORDER BY c.fecha_clasificacion DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->execute([':limit' => $limit, ':offset' => $offset]);
        $data = $stmt->fetchAll();

        return [
            'success' => true,
            'data'    => $data,
            'pagination' => [
                'page'  => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ]
        ];
    }

    private function crearClasificacion(): array {
        if (empty($this->input['id_vehiculo'])) {
            throw new Exception("El campo 'id_vehiculo' es requerido", 400);
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO global.clasificacion (
                id_vehiculo, periodo,
                combustible, gastos_administracion, compra_activos,
                varios, mantenimiento, peajes, sueldos, viaticos, sueldo_previo,
                contiene_cantidad, relacionado_con_vehiculos, observaciones
            ) VALUES (
                :id_vehiculo, :periodo,
                :combustible, :gastos_admin, :compra_activos,
                :varios, :mantenimiento, :peajes, :sueldos, :viaticos, :sueldo_previo,
                :contiene_cantidad, :rel_veh, :observaciones
            ) RETURNING id_clasificacion
        ");
        $stmt->execute([
            ':id_vehiculo'      => (int)$this->input['id_vehiculo'],
            ':periodo'          => $this->input['periodo'] ?? date('Y-m'),
            ':combustible'      => (float)($this->input['combustible'] ?? 0),
            ':gastos_admin'     => (float)($this->input['gastos_administracion'] ?? 0),
            ':compra_activos'   => (float)($this->input['compra_activos'] ?? 0),
            ':varios'           => (float)($this->input['varios'] ?? 0),
            ':mantenimiento'    => (float)($this->input['mantenimiento'] ?? 0),
            ':peajes'           => (float)($this->input['peajes'] ?? 0),
            ':sueldos'          => (float)($this->input['sueldos'] ?? 0),
            ':viaticos'         => (float)($this->input['viaticos'] ?? 0),
            ':sueldo_previo'    => (float)($this->input['sueldo_previo'] ?? 0),
            ':contiene_cantidad'=> isset($this->input['contiene_cantidad']) ? (bool)$this->input['contiene_cantidad'] : false,
            ':rel_veh'          => isset($this->input['relacionado_con_vehiculos']) ? (bool)$this->input['relacionado_con_vehiculos'] : false,
            ':observaciones'    => $this->input['observaciones'] ?? null,
        ]);

        $id = $stmt->fetchColumn();

        return [
            'success' => true,
            'id'      => (int)$id,
            'message' => 'Clasificación registrada exitosamente'
        ];
    }

    // ================================================================
    // COMBUSTIBLE (detalle)
    // ================================================================
    private function handleCombustible(): array {
        if ($this->method !== 'GET') throw new Exception('Método no permitido', 405);

        $page   = max(1, (int)($this->params['page'] ?? 1));
        $limit  = min(100, (int)($this->params['limit'] ?? 50));
        $offset = ($page - 1) * $limit;

        $stmtCount = $this->pdo->query("SELECT COUNT(*) FROM global.combustible_detalle");
        $total = (int)$stmtCount->fetchColumn();

        $stmt = $this->pdo->prepare("
            SELECT cd.*, g.fecha_gasto, g.concepto, g.monto,
                   v.placa_vehiculo, v.conductor
            FROM global.combustible_detalle cd
            JOIN global.gastos g ON cd.id_gasto = g.id_gasto
            LEFT JOIN global.vehiculos v ON g.id_vehiculo = v.id_vehiculo
            ORDER BY g.fecha_gasto DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->execute([':limit' => $limit, ':offset' => $offset]);

        return [
            'success' => true,
            'data'    => $stmt->fetchAll(),
            'pagination' => [
                'page'  => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
            ]
        ];
    }

    // ================================================================
    // AUTH
    // ================================================================
    private function handleLogin(): array {
        if ($this->method !== 'POST') throw new Exception('Método no permitido', 405);

        $usuario    = trim($this->input['usuario'] ?? $this->input['username'] ?? '');
        $contrasenha= $this->input['contrasenha'] ?? $this->input['password'] ?? '';

        if (!$usuario || !$contrasenha) {
            throw new Exception('Usuario y contraseña son requeridos', 400);
        }

        $stmt = $this->pdo->prepare("
            SELECT id_usuario, usuario, contrasenha, nombres, apellidos, email, rol, estado, bloqueado_hasta
            FROM global.usuarios
            WHERE (usuario = :u OR email = :u)
            LIMIT 1
        ");
        $stmt->execute([':u' => $usuario]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new Exception('Credenciales inválidas', 401);
        }

        // Verificar bloqueo
        if ($user['bloqueado_hasta'] && strtotime($user['bloqueado_hasta']) > time()) {
            throw new Exception('Cuenta bloqueada temporalmente. Intente más tarde.', 403);
        }

        // Verificar estado activo
        if ((int)$user['estado'] !== 1) {
            throw new Exception('Cuenta inactiva', 403);
        }

        // Verificar contraseña
        if (!password_verify($contrasenha, $user['contrasenha'])) {
            // Registrar intento fallido
            $stmtFail = $this->pdo->prepare("
                UPDATE global.usuarios
                SET intentos_fallidos = intentos_fallidos + 1,
                    bloqueado_hasta = CASE WHEN intentos_fallidos >= 4
                        THEN CURRENT_TIMESTAMP + INTERVAL '30 minutes' ELSE NULL END
                WHERE id_usuario = :id
            ");
            $stmtFail->execute([':id' => $user['id_usuario']]);
            throw new Exception('Credenciales inválidas', 401);
        }

        // Reset intentos y registrar login
        $stmtOk = $this->pdo->prepare("
            UPDATE global.usuarios
            SET intentos_fallidos = 0, bloqueado_hasta = NULL,
                ultimo_login = CURRENT_TIMESTAMP,
                ultimo_ip = :ip
            WHERE id_usuario = :id
        ");
        $stmtOk->execute([
            ':id'  => $user['id_usuario'],
            ':ip'  => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);

        // Log actividad
        $stmtLog = $this->pdo->prepare("
            INSERT INTO global.logs_actividad (id_usuario, usuario, accion, modulo, ip_address)
            VALUES (:id, :u, 'login_exitoso', 'autenticacion', :ip)
        ");
        $stmtLog->execute([
            ':id' => $user['id_usuario'],
            ':u'  => $user['usuario'],
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);

        // Iniciar sesión PHP
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['user_id']  = $user['id_usuario'];
        $_SESSION['usuario']  = $user['usuario'];
        $_SESSION['user_role']= $user['rol'];
        $_SESSION['logged_in']= true;

        $token = bin2hex(random_bytes(32));

        return [
            'success' => true,
            'token'   => $token,
            'user'    => [
                'id'       => $user['id_usuario'],
                'usuario'  => $user['usuario'],
                'nombres'  => $user['nombres'],
                'apellidos'=> $user['apellidos'],
                'email'    => $user['email'],
                'rol'      => $user['rol'],
            ]
        ];
    }

    private function handleLogout(): array {
        if (session_status() === PHP_SESSION_NONE) session_start();
        session_destroy();
        return ['success' => true, 'message' => 'Sesión cerrada exitosamente'];
    }

    private function getCurrentUser(): array {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!isset($_SESSION['user_id'])) {
            throw new Exception('No autenticado', 401);
        }
        return [
            'success' => true,
            'user'    => [
                'id'      => $_SESSION['user_id'],
                'usuario' => $_SESSION['usuario'],
                'rol'     => $_SESSION['user_role'],
            ]
        ];
    }

    // ================================================================
    // PERSONAL
    // ================================================================
    private function handlePersonal(): array {
        $id = $this->params['id'] ?? null;
        switch ($this->method) {
            case 'GET':    return $id ? $this->obtenerPersonalPorId((int)$id) : $this->listarPersonal();
            case 'POST':   return $this->crearPersonal();
            case 'PUT':    return $this->actualizarPersonal();
            case 'DELETE': return $this->eliminarPersonal();
            default: throw new Exception('Método no permitido', 405);
        }
    }

    private function obtenerPersonalPorId(int $id): array {
        $stmt = $this->pdo->prepare("SELECT * FROM global.personal WHERE id_personal = :id");
        $stmt->execute([':id' => $id]);
        $data = $stmt->fetch();
        if (!$data) throw new Exception('Personal no encontrado', 404);
        return ['success' => true, 'data' => $data];
    }

    private function listarPersonal(): array {
        $where = [];
        $params = [];

        if (!empty($this->params['cargo']) && $this->params['cargo'] !== 'todos') {
            $where[] = "cargo ILIKE :cargo";
            $params[':cargo'] = '%' . $this->params['cargo'] . '%';
        }

        if (!empty($this->params['busqueda'])) {
            $where[] = "(nombres ILIKE :b OR apellidos ILIKE :b OR ci ILIKE :b OR telefono ILIKE :b)";
            $params[':b'] = '%' . $this->params['busqueda'] . '%';
        }

        $sql = "SELECT * FROM global.personal";
        if ($where) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " ORDER BY apellidos, nombres";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return ['success' => true, 'data' => $stmt->fetchAll()];
    }

    private function crearPersonal(): array {
        $stmt = $this->pdo->prepare("INSERT INTO global.personal (nombres, apellidos, cargo, telefono, licencia, sueldo, estado) VALUES (:n, :a, :c, :t, :l, :s, :e)");
        $stmt->execute([
            ':n' => $this->input['nombres'],
            ':a' => $this->input['apellidos'],
            ':c' => $this->input['cargo'] ?? 'Operador',
            ':t' => $this->input['telefono'] ?? null,
            ':l' => $this->input['licencia'] ?? null,
            ':s' => (float)($this->input['sueldo'] ?? 0),
            ':e' => (int)($this->input['estado'] ?? 1)
        ]);
        return ['success' => true, 'message' => 'Personal registrado exitosamente'];
    }

    private function actualizarPersonal(): array {
        $id = (int)($this->params['id'] ?? 0);
        if (!$id) throw new Exception('ID requerido', 400);

        $stmt = $this->pdo->prepare("UPDATE global.personal SET nombres=:n, apellidos=:a, cargo=:c, telefono=:t, licencia=:l, sueldo=:s, estado=:e WHERE id_personal=:id");
        $stmt->execute([
            ':n' => $this->input['nombres'],
            ':a' => $this->input['apellidos'],
            ':c' => $this->input['cargo'] ?? 'Operador',
            ':t' => $this->input['telefono'] ?? null,
            ':l' => $this->input['licencia'] ?? null,
            ':s' => (float)($this->input['sueldo'] ?? 0),
            ':e' => (int)($this->input['estado'] ?? 1),
            ':id' => $id
        ]);
        return ['success' => true, 'message' => 'Personal actualizado exitosamente'];
    }

    private function eliminarPersonal(): array {
        $id = (int)($this->params['id'] ?? 0);
        if (!$id) throw new Exception('ID requerido', 400);

        // Obtener info del personal
        $stmtP = $this->pdo->prepare("SELECT cargo, nombres, apellidos FROM global.personal WHERE id_personal = :id");
        $stmtP->execute([':id' => $id]);
        $p = $stmtP->fetch();
        if (!$p) throw new Exception('Personal no encontrado', 404);

        $cargo = strtoupper($p['cargo'] ?? '');
        if ($cargo === 'CHOFER' || $cargo === 'CONDUCTOR') {
            // Verificar si está asignado a una UNIDAD
            $stmtV = $this->pdo->prepare("SELECT COUNT(*) FROM global.vehiculos WHERE id_personal = :id");
            $stmtV->execute([':id' => $id]);
            if ((int)$stmtV->fetchColumn() > 0) {
                throw new Exception('No se puede eliminar: El chofer está actualmente asignado a una unidad.', 400);
            }

            // Verificar si ha realizado viajes (Ingresos asociados a cualquier vehículo)
            // Como no hay histórico directo, buscamos registros en ingresos
            $stmtI = $this->pdo->prepare("SELECT COUNT(*) FROM global.ingresos WHERE id_vehiculo IN (SELECT id_vehiculo FROM global.vehiculos WHERE id_personal = :id) OR observaciones ILIKE :name");
            $stmtI->execute([':id' => $id, ':name' => '%' . $p['apellidos'] . '%']);
            
            if ((int)$stmtI->fetchColumn() > 0) {
                // Solo inactivar
                $stmtUpd = $this->pdo->prepare("UPDATE global.personal SET estado = 0 WHERE id_personal = :id");
                $stmtUpd->execute([':id' => $id]);
                return [
                    'success' => true, 
                    'message' => 'El chofer tiene viajes realizados. El registro ha sido INACTIVADO en lugar de eliminado para preservar el historial.'
                ];
            }
        }

        $stmt = $this->pdo->prepare("DELETE FROM global.personal WHERE id_personal = :id");
        $stmt->execute([':id' => $id]);
        return ['success' => true, 'message' => 'Personal eliminado'];
    }

    // ================================================================
    // TRAMOS
    // ================================================================
    private function handleTramos(): array {
        switch ($this->method) {
            case 'GET':  return $this->listarTramos();
            case 'POST': return $this->crearTramo();
            default: throw new Exception('Método no permitido', 405);
        }
    }

    private function listarTramos(): array {
        $stmt = $this->pdo->query("SELECT * FROM global.tramos ORDER BY created_at DESC");
        return ['success' => true, 'data' => $stmt->fetchAll()];
    }

    private function crearTramo(): array {
        $required = ['origen', 'destino', 'kilometros', 'precio_total'];
        foreach ($required as $field) {
            if (empty($this->input[$field])) {
                throw new Exception("El campo '$field' es requerido", 400);
            }
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO global.tramos (origen, destino, kilometros, precio_total, gasolina_promedio, diesel_promedio, gas_promedio)
            VALUES (:o, :d, :k, :p, :g, :di, :gas)
            RETURNING id_tramo
        ");
        $stmt->execute([
            ':o' => $this->input['origen'],
            ':d' => $this->input['destino'],
            ':k' => (float)$this->input['kilometros'],
            ':p' => (float)$this->input['precio_total'],
            ':g' => (float)($this->input['gasolina_promedio'] ?? 0),
            ':di' => (float)($this->input['diesel_promedio'] ?? 0),
            ':gas' => (float)($this->input['gas_promedio'] ?? 0)
        ]);

        return [
            'success' => true,
            'id' => (int)$stmt->fetchColumn(),
            'message' => 'Tramo registrado exitosamente'
        ];
    }

    // ================================================================
    // ALMACÉN
    // ================================================================
    private function handleAlmacen(): array {
        // AUTORUN MIGRATION IF TABLE MISSING OR EMPTY
        try {
            $this->pdo->exec("CREATE SCHEMA IF NOT EXISTS global;");
            $q = $this->pdo->query("SELECT COUNT(*) FROM global.inventario");
            $count = $q ? (int)$q->fetchColumn() : 0;
            
            if ($count === 0) {
                // Forzar carga de datos iniciales
                $sqlPath = __DIR__ . '/../database/2026-03-31MigrationNewDatabase.sql';
                if (file_exists($sqlPath)) {
                    $this->pdo->exec(file_get_contents($sqlPath));
                }
            }
        } catch (Exception $e) {
            // Si la tabla no existe, fallará el count, así que cargamos el SQL
            $sqlPath = __DIR__ . '/../database/2026-03-31MigrationNewDatabase.sql';
            if (file_exists($sqlPath)) {
                $this->pdo->exec(file_get_contents($sqlPath));
            }
        }

        $id = $this->params['id'] ?? null;

        switch ($this->method) {
            case 'GET':  
                if (($parts[1] ?? '') === 'categorias' || ($this->params['sub'] ?? '') === 'categorias') return $this->listarCategoriasAlmacen();
                return $id ? $this->obtenerProductoPorId((int)$id) : $this->listarProductos();
            case 'POST': 
                if (($parts[1] ?? '') === 'movimientos') return $this->registrarMovimientoAlmacen();
                return $this->crearProducto();
            case 'PUT':
                return $this->actualizarProducto();
            case 'DELETE':
                return $this->eliminarProducto();
            default: throw new Exception('Método no permitido', 405);
        }
    }

    private function obtenerProductoPorId(int $id): array {
        $stmt = $this->pdo->prepare("
            SELECT id_inventario as id_producto, codigo, nombre_producto as nombre, 
                   categoria, stock_actual as cantidad, stock_minimo, 
                   precio_compra as precio_unitario, unidad_medida, fecha_ingreso,
                   marca, estado
            FROM global.inventario 
            WHERE id_inventario = :id
        ");
        $stmt->execute([':id' => $id]);
        $data = $stmt->fetch();
        if (!$data) throw new Exception('Producto no encontrado', 404);
        return ['success' => true, 'data' => $data];
    }

    private function actualizarProducto(): array {
        $id = $this->params['id'] ?? null;
        if (!$id) throw new Exception('ID requerido', 400);

        $stmt = $this->pdo->prepare("
            UPDATE global.inventario SET 
                codigo = :cod, nombre_producto = :n, categoria = :cat, 
                stock_actual = :can, stock_minimo = :sm, precio_compra = :pu, 
                unidad_medida = :um, marca = :mar, estado = :est
            WHERE id_inventario = :id
        ");
        $stmt->execute([
            ':id'    => (int)$id,
            ':cod'   => $this->input['codigo'],
            ':n'     => $this->input['nombre'],
            ':cat'   => $this->input['categoria'],
            ':can'   => (float)$this->input['cantidad'],
            ':sm'    => (float)($this->input['stock_minimo'] ?? 0),
            ':pu'    => (float)($this->input['precio_unitario'] ?? 0),
            ':um'    => $this->input['unidad_medida'] ?? 'UNIDAD',
            ':mar'   => $this->input['marca'] ?? null,
            ':est'   => $this->input['estado'] ?? 'ACTIVO'
        ]);
        return ['success' => true, 'message' => 'Producto actualizado'];
    }

    private function eliminarProducto(): array {
        $id = $this->params['id'] ?? null;
        if (!$id) throw new Exception('ID requerido', 400);

        // For simplicity, hard delete. We could soft delete setting estado = 'INACTIVO'
        $stmt = $this->pdo->prepare("DELETE FROM global.inventario WHERE id_inventario = :id");
        $stmt->execute([':id' => (int)$id]);
        return ['success' => true, 'message' => 'Producto eliminado del inventario'];
    }

    private function registrarMovimientoAlmacen(): array {
        $idProd = (int)($this->input['id_producto'] ?? 0);
        $idVeh = (int)($this->input['id_vehiculo'] ?? 0);
        $cant = (float)($this->input['cantidad'] ?? 0);

        if (!$idProd || !$idVeh || $cant <= 0) {
            throw new Exception("Faltan datos obligatorios para el consumo o cantidad inválida", 400);
        }
        
        $this->pdo->beginTransaction();
        try {
            // Verificar stock
            $stmt = $this->pdo->prepare("SELECT stock_actual, nombre_producto FROM global.inventario WHERE id_inventario = :id FOR UPDATE");
            $stmt->execute([':id' => $idProd]);
            $prod = $stmt->fetch();
            
            if (!$prod) throw new Exception("Producto no encontrado");
            if ($prod['stock_actual'] < $cant) throw new Exception("Stock insuficiente. Stock actual: " . $prod['stock_actual']);

            // Crear el registro de movimiento historico (si hay tabla, o simplemente restar, restamos directo para simplicidad si la BD no tiene tabla de movimientos aun)
            $stmtUpd = $this->pdo->prepare("UPDATE global.inventario SET stock_actual = stock_actual - :c WHERE id_inventario = :id");
            $stmtUpd->execute([':c' => $cant, ':id' => $idProd]);

            // Se podria guardar el historico o relacion en otra tabla. El requerimiento de Frontend manda esto para registrar un "consumo"

            $this->pdo->commit();
            return ['success' => true, 'message' => 'Consumo registrado exitosamente. Se descontó del stock.'];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function listarCategoriasAlmacen(): array {
        $stmt = $this->pdo->query("SELECT * FROM global.categorias_almacen ORDER BY nombre");
        return ['success' => true, 'data' => $stmt->fetchAll()];
    }

    private function listarProductos(): array {
        $where = [];
        $params = [];

        if (!empty($this->params['categoria']) && $this->params['categoria'] !== 'todas') {
            $where[] = "categoria = :cat";
            $params[':cat'] = $this->params['categoria'];
        }

        if (!empty($this->params['busqueda'])) {
            $where[] = "(nombre_producto ILIKE :b OR codigo ILIKE :b OR marca ILIKE :b)";
            $params[':b'] = '%' . $this->params['busqueda'] . '%';
        }

        $sql = "
            SELECT i.id_inventario as id_producto, i.codigo, i.nombre_producto as nombre, 
                   i.categoria, i.stock_actual as cantidad, i.stock_minimo, 
                   i.precio_compra as precio_unitario, i.unidad_medida, i.fecha_ingreso,
                   i.marca, i.estado
            FROM global.inventario i
        ";
        
        if ($where) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " ORDER BY i.nombre_producto";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return ['success' => true, 'data' => $stmt->fetchAll()];
    }

    private function crearProducto(): array {
        $stmt = $this->pdo->prepare("
            INSERT INTO global.inventario (codigo, nombre_producto, categoria, stock_actual, stock_minimo, precio_compra, unidad_medida, marca, estado, id_proveedor)
            VALUES (:cod, :n, :cat, :can, :sm, :pu, :um, :mar, :est, :idprov)
        ");
        $stmt->execute([
            ':cod'   => $this->input['codigo'],
            ':n'     => $this->input['nombre'],
            ':cat'   => $this->input['categoria'],
            ':can'   => (float)$this->input['cantidad'],
            ':sm'    => (float)($this->input['stock_minimo'] ?? 0),
            ':pu'    => (float)($this->input['precio_unitario'] ?? 0),
            ':um'    => $this->input['unidad_medida'] ?? 'UNIDAD',
            ':mar'   => $this->input['marca'] ?? null,
            ':est'   => $this->input['estado'] ?? 'ACTIVO',
            ':idprov'=> (int)($this->input['id_proveedor'] ?? null)
        ]);
        return ['success' => true, 'message' => 'Producto registrado en inventario'];
    }

    // ================================================================
    // INGRESOS
    // ================================================================
    private function handleIngresos(): array {
        switch ($this->method) {
            case 'GET':  return $this->listarIngresos();
            case 'POST': return $this->crearIngreso();
            default: throw new Exception('Método no permitido', 405);
        }
    }

    private function listarIngresos(): array {
        $stmt = $this->pdo->query("SELECT i.*, v.placa_vehiculo FROM global.ingresos i LEFT JOIN global.vehiculos v ON i.id_vehiculo = v.id_vehiculo ORDER BY fecha_ingreso DESC");
        return ['success' => true, 'data' => $stmt->fetchAll()];
    }

    private function crearIngreso(): array {
        $idVehiculo = (int)($this->input['id_vehiculo'] ?? 0);
        $toneladas  = (float)($this->input['toneladas'] ?? 0);

        if (!$idVehiculo) throw new Exception("Debe seleccionar una UNIDAD", 400);

        // Controlar que las toneladas no superen la capacidad de la UNIDAD
        $stmtV = $this->pdo->prepare("SELECT capacidad FROM global.vehiculos WHERE id_vehiculo = :id");
        $stmtV->execute([':id' => $idVehiculo]);
        $vehiculo = $stmtV->fetch();
        
        if ($vehiculo && (float)$vehiculo['capacidad'] > 0) {
            if ($toneladas > (float)$vehiculo['capacidad']) {
                throw new Exception("Error: Las toneladas transportadas (" . $toneladas . ") superan la capacidad de la unidad (" . $vehiculo['capacidad'] . ").", 400);
            }
        }

        $stmt = $this->pdo->prepare("INSERT INTO global.ingresos (id_vehiculo, concepto, monto, fecha_ingreso, observaciones, toneladas, kilometraje_conducido, conductor_asignado, id_personal) VALUES (:v, :c, :m, :f, :o, :t, :km, :cond, :idp)");
        $stmt->execute([
            ':v' => $idVehiculo,
            ':c' => $this->input['concepto'],
            ':m' => (float)$this->input['monto'],
            ':f' => $this->input['fecha_ingreso'] ?? date('Y-m-d'),
            ':o' => $this->input['observaciones'] ?? null,
            ':t' => $toneladas,
            ':km' => (float)($this->input['kilometraje_conducido'] ?? 0),
            ':cond' => $this->input['conductor_asignado'] ?? null,
            ':idp'  => (int)($this->input['id_personal'] ?? null) ?: null
        ]);
        return ['success' => true, 'message' => 'Ingreso registrado correctamente'];
    }

    // ================================================================
    // REPORTES
    // ================================================================
    private function handleReportes(): array {
        $type = $this->params['id'] ?? $this->params['type'] ?? 'resumen';
        switch ($type) {
            case 'financiero': return $this->getReporteFinanciero();
            case 'almacen':    return $this->getReporteAlmacen();
            default:           return $this->getReporteResumen();
        }
    }

    private function getReporteFinanciero(): array {
        // Debe (Gastos)
        $stmtD = $this->pdo->query("SELECT SUM(monto) as total FROM global.gastos");
        $debe = (float)$stmtD->fetchColumn();

        // Haber (Ingresos)
        $stmtH = $this->pdo->query("SELECT SUM(monto) as total FROM global.ingresos");
        $haber = (float)$stmtH->fetchColumn();

        // Patrimonio (Activos Variados)
        $stmtP = $this->pdo->query("SELECT SUM(valor_estimado) as total FROM global.patrimonio");
        $pat = (float)$stmtP->fetchColumn();

        // Calcular patrimonio de vehículos como activos automáticos
        $stmtV = $this->pdo->query("SELECT COUNT(*) * 50000 as total FROM global.vehiculos"); // Valor base estimado 50k
        $patVehiculos = (float)$stmtV->fetchColumn();

        // Sincronización Almacén: Calcular valor totalizado de Inventario (Activos Corrientes)
        $stmtA = $this->pdo->query("SELECT SUM(stock_actual * precio_compra) as total FROM global.inventario");
        $patAlmacen = (float)$stmtA->fetchColumn();

        $patrimonio_total = $pat + $patVehiculos + $patAlmacen;

        return [
            'success' => true,
            'data' => [
                'debe'         => $debe,
                'haber'        => $haber,
                'patrimonio'   => $patrimonio_total,
                'balance'      => $haber - $debe,
                'detalles' => [
                    'gastos'          => $debe,
                    'ingresos'        => $haber,
                    'activos_fijos'   => $pat + $patVehiculos,
                    'activos_almacen' => $patAlmacen
                ]
            ]
        ];
    }

    private function getReporteAlmacen(): array {
        $stmt = $this->pdo->query("
            SELECT categoria, COUNT(*) as total_items, SUM(stock_actual * precio_compra) as valor_total
            FROM global.inventario
            GROUP BY categoria
            ORDER BY valor_total DESC
        ");
        return ['success' => true, 'data' => $stmt->fetchAll()];
    }

    private function getReporteResumen(): array {
        return ['success' => true, 'message' => 'Resumen general'];
    }

    // ================================================================
    // FILTRO DE REPORTES UNIFICADO (NUEVO REQUERIMIENTO)
    // ================================================================
    private function handleFiltroReportes(): array {
        $fecha_inicio = $this->params['fecha_inicio'] ?? date('Y-m-d');
        $fecha_fin    = $this->params['fecha_fin'] ?? date('Y-m-d');
        $tipo_reporte = strtoupper($this->params['tipo'] ?? 'TODO');

        $gastos = [];
        $ingresos = [];

        // 1. Obtener Gastos (Egresos)
        $whereG = ["g.fecha_gasto BETWEEN :fi AND :ff"];
        if ($tipo_reporte === 'USUARIOS') {
            $whereG[] = "g.tipo_gasto IN ('Sueldos', 'Viaticos')";
        } elseif ($tipo_reporte === 'UNIDADES') {
            $whereG[] = "g.tipo_gasto IN ('Combustible', 'Mantenimiento', 'Peaje')";
        }
        $whereG_SQL = implode(' AND ', $whereG);

        $stmtG = $this->pdo->prepare("
            SELECT 'GASTO' as tipo_registro, g.id_gasto as id, g.id_gasto, g.fecha_gasto as fecha, 
                   g.concepto, g.monto as egreso, 0 as ingreso, g.observaciones,
                   v.placa_vehiculo, g.id_vehiculo, g.tipo_gasto,
                   g.cantidad, g.kilometraje, 
                   COALESCE((SELECT nombre_proveedor FROM global.proveedores prov WHERE prov.id_proveedor = g.id_proveedor), g.proveedor) as proveedor,
                   '' as toneladas, '' as kilometraje_conducido, '' as conductor_asignado
            FROM global.gastos g
            LEFT JOIN global.vehiculos v ON g.id_vehiculo = v.id_vehiculo
            WHERE $whereG_SQL
        ");
        $stmtG->execute([':fi' => $fecha_inicio, ':ff' => $fecha_fin]);
        $gastos = $stmtG->fetchAll();

        // 2. Obtener Ingresos (Solo si NO es reporte de solo GASTOS)
        if ($tipo_reporte !== 'GASTOS') {
            $whereI = ["i.fecha_ingreso BETWEEN :fi AND :ff"];
            
            $whereI_SQL = implode(' AND ', $whereI);
            $stmtI = $this->pdo->prepare("
                SELECT 'INGRESO' as tipo_registro, i.id_ingreso as id, i.id_ingreso, i.fecha_ingreso as fecha,
                       i.concepto, 0 as egreso, i.monto as ingreso, i.observaciones,
                       v.placa_vehiculo, i.id_vehiculo, 'Flete/Ingreso' as tipo_gasto,
                       1 as cantidad, 0 as kilometraje, '' as proveedor, i.toneladas, i.kilometraje_conducido, 
                       COALESCE((SELECT nombres || ' ' || apellidos FROM global.personal pers WHERE pers.id_personal = i.id_personal), i.conductor_asignado) as conductor_asignado
                FROM global.ingresos i
                LEFT JOIN global.vehiculos v ON i.id_vehiculo = v.id_vehiculo
                WHERE $whereI_SQL
            ");
            $stmtI->execute([':fi' => $fecha_inicio, ':ff' => $fecha_fin]);
            $ingresos = $stmtI->fetchAll();
        }

        // Combinar y ordenar por fecha
        $todo = array_merge($gastos, $ingresos);
        usort($todo, function($a, $b) {
            return strtotime($b['fecha']) - strtotime($a['fecha']);
        });

        // Resumen
        $total_ingresos = array_sum(array_column($ingresos, 'ingreso'));
        $total_egresos  = array_sum(array_column($gastos, 'egreso'));

        return [
            'success' => true,
            'data'    => $todo,
            'resumen' => [
                'total_ingresos' => $total_ingresos,
                'total_egresos'  => $total_egresos,
                'balance'        => $total_ingresos - $total_egresos,
                'periodo'        => "$fecha_inicio a $fecha_fin",
                'tipo_reporte'   => $tipo_reporte
            ]
        ];
    }

    // ================================================================
    // PROVEEDORES
    // ================================================================
    private function handleProveedores(): array {
        switch ($this->method) {
            case 'GET':  return ['success' => true, 'data' => $this->query("SELECT * FROM global.proveedores ORDER BY nombre_proveedor")->fetchAll()];
            case 'POST': 
                $stmt = $this->pdo->prepare("INSERT INTO global.proveedores (nombre_proveedor, nit_ci, contacto, telefono) VALUES (:n, :nit, :c, :t) RETURNING id_proveedor");
                $stmt->execute([':n' => $this->input['nombre_proveedor'], ':nit' => $this->input['nit_ci'] ?? null, ':c' => $this->input['contacto'] ?? null, ':t' => $this->input['telefono'] ?? null]);
                return ['success' => true, 'id' => (int)$stmt->fetchColumn(), 'message' => 'Proveedor registrado'];
            default: throw new Exception('Método no permitido', 405);
        }
    }
}

// ────────────────────────────────────────────────
// Ejecutar
$api = new API($pdo);
$api->dispatch();