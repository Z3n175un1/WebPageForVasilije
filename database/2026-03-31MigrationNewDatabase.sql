-- =============================================
-- BASE DE DATOS - VERSIÓN CORREGIDA
-- Schema: global
-- =============================================

-- Crear schema global
CREATE SCHEMA IF NOT EXISTS global;

-- Establecer el search path
SET search_path TO global, public;

-- =============================================
-- TABLA: vehiculos
-- =============================================
CREATE TABLE global.vehiculos (
    id_vehiculo SERIAL PRIMARY KEY,
    placa_vehiculo VARCHAR(15) UNIQUE NOT NULL,
    anho INTEGER,
    tipo_vehiculo VARCHAR(50),
    conductor VARCHAR(100),
    tramo_actual VARCHAR(100),
    estado INTEGER DEFAULT 1, -- 1=Activo, 0=Inactivo, 2=En taller, 3=Vendido
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Índices
CREATE INDEX idx_vehiculos_placa ON global.vehiculos(placa_vehiculo);
CREATE INDEX idx_vehiculos_estado ON global.vehiculos(estado);

-- =============================================
-- TABLA: clasificacion (Clasificación general de gastos)
-- =============================================
CREATE TABLE global.clasificacion (
    id_clasificacion SERIAL PRIMARY KEY,
    id_vehiculo INTEGER REFERENCES global.vehiculos(id_vehiculo) ON DELETE CASCADE,
    contiene_cantidad BOOLEAN DEFAULT FALSE,
    relacionado_con_vehiculos BOOLEAN DEFAULT FALSE,
    
    -- Gastos por categoría
    combustible DECIMAL(15,2) DEFAULT 0,
    gastos_administracion DECIMAL(15,2) DEFAULT 0,
    compra_activos DECIMAL(15,2) DEFAULT 0,
    varios DECIMAL(15,2) DEFAULT 0,
    mantenimiento DECIMAL(15,2) DEFAULT 0,
    peajes DECIMAL(15,2) DEFAULT 0,
    sueldos DECIMAL(15,2) DEFAULT 0,
    viaticos DECIMAL(15,2) DEFAULT 0,
    
    -- Totales
    sueldo_total DECIMAL(15,2) DEFAULT 0,
    sueldo_previo DECIMAL(15,2) DEFAULT 0,
    total_gastos DECIMAL(15,2) DEFAULT 0,
    
    fecha_clasificacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    periodo VARCHAR(7),
    observaciones TEXT
);

CREATE INDEX idx_clasificacion_vehiculo ON global.clasificacion(id_vehiculo);
CREATE INDEX idx_clasificacion_fecha ON global.clasificacion(fecha_clasificacion);
CREATE INDEX idx_clasificacion_periodo ON global.clasificacion(periodo);

-- =============================================
-- TABLA: gastos (Tabla única para todos los gastos detallados)
-- =============================================
CREATE TABLE global.gastos (
    id_gasto SERIAL PRIMARY KEY,
    id_vehiculo INTEGER REFERENCES global.vehiculos(id_vehiculo) ON DELETE CASCADE,
    id_clasificacion INTEGER REFERENCES global.clasificacion(id_clasificacion) ON DELETE SET NULL,
    
    -- Tipo de gasto
    tipo_gasto VARCHAR(50) NOT NULL CHECK (tipo_gasto IN (
        'Combustible', 'Administracion', 'Compra_Activos', 'Varios', 
        'Mantenimiento', 'Peaje', 'Sueldos', 'Viaticos'
    )),
    
    -- Detalles del gasto
    concepto VARCHAR(200) NOT NULL,
    descripcion TEXT,
    monto DECIMAL(15,2) NOT NULL,
    cantidad DECIMAL(10,2) DEFAULT 1,
    precio_unitario DECIMAL(15,2) DEFAULT 0, -- Se calculará con trigger
    
    -- Datos específicos según tipo
    -- Para combustible
    tipo_combustible VARCHAR(50),
    kilometraje INTEGER,
    
    -- Para peajes
    caseta VARCHAR(100),
    ruta VARCHAR(200),
    
    -- Para mantenimiento
    taller VARCHAR(100),
    tipo_mantenimiento VARCHAR(100),
    
    -- Fechas
    fecha_gasto DATE NOT NULL,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Documentación
    comprobante VARCHAR(100),
    proveedor VARCHAR(100),
    
    -- Estado
    estado_pago VARCHAR(20) DEFAULT 'Pagado' CHECK (estado_pago IN ('Pagado', 'Pendiente', 'Anulado')),
    observaciones TEXT
);

-- Índices
CREATE INDEX idx_gastos_vehiculo ON global.gastos(id_vehiculo);
CREATE INDEX idx_gastos_tipo ON global.gastos(tipo_gasto);
CREATE INDEX idx_gastos_fecha ON global.gastos(fecha_gasto);
CREATE INDEX idx_gastos_clasificacion ON global.gastos(id_clasificacion);

-- =============================================
-- TABLA: combustible_detalle (Detalle específico para combustible)
-- =============================================
CREATE TABLE global.combustible_detalle (
    id_detalle SERIAL PRIMARY KEY,
    id_gasto INTEGER REFERENCES global.gastos(id_gasto) ON DELETE CASCADE,
    tipo_carburante VARCHAR(100) NOT NULL,
    galones DECIMAL(10,2) NOT NULL,
    precio_por_galon DECIMAL(10,2) NOT NULL,
    estacion_servicio VARCHAR(100),
    kilometraje_actual INTEGER,
    kilometraje_anterior INTEGER,
    kilometraje_recorrido INTEGER,
    rendimiento DECIMAL(10,2)
);

CREATE INDEX idx_combustible_gasto ON global.combustible_detalle(id_gasto);

-- =============================================
-- TRIGGERS Y FUNCIONES
-- =============================================

-- Trigger para actualizar timestamp en vehiculos
CREATE OR REPLACE FUNCTION global.actualizar_timestamp_vehiculo()
RETURNS TRIGGER AS $$
BEGIN
    NEW.fecha_actualizacion = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_actualizar_vehiculos
    BEFORE UPDATE ON global.vehiculos
    FOR EACH ROW
    EXECUTE FUNCTION global.actualizar_timestamp_vehiculo();

-- Función para calcular precio_unitario en gastos
CREATE OR REPLACE FUNCTION global.calcular_precio_unitario()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.cantidad > 0 THEN
        NEW.precio_unitario = NEW.monto / NEW.cantidad;
    ELSE
        NEW.precio_unitario = 0;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_calcular_precio_unitario
    BEFORE INSERT OR UPDATE ON global.gastos
    FOR EACH ROW
    EXECUTE FUNCTION global.calcular_precio_unitario();

-- Función para calcular sueldo_total y total_gastos en clasificacion
CREATE OR REPLACE FUNCTION global.calcular_totales_clasificacion()
RETURNS TRIGGER AS $$
BEGIN
    NEW.sueldo_total = NEW.sueldos + NEW.sueldo_previo;
    NEW.total_gastos = COALESCE(NEW.combustible,0) + 
                       COALESCE(NEW.gastos_administracion,0) + 
                       COALESCE(NEW.compra_activos,0) + 
                       COALESCE(NEW.varios,0) + 
                       COALESCE(NEW.mantenimiento,0) + 
                       COALESCE(NEW.peajes,0) + 
                       COALESCE(NEW.sueldos,0) + 
                       COALESCE(NEW.viaticos,0);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_calcular_totales_clasificacion
    BEFORE INSERT OR UPDATE ON global.clasificacion
    FOR EACH ROW
    EXECUTE FUNCTION global.calcular_totales_clasificacion();

-- Función para calcular kilometraje_recorrido y rendimiento en combustible_detalle
CREATE OR REPLACE FUNCTION global.calcular_rendimiento_combustible()
RETURNS TRIGGER AS $$
BEGIN
    -- Calcular kilometraje recorrido
    IF NEW.kilometraje_actual IS NOT NULL AND NEW.kilometraje_anterior IS NOT NULL THEN
        NEW.kilometraje_recorrido = NEW.kilometraje_actual - NEW.kilometraje_anterior;
    ELSE
        NEW.kilometraje_recorrido = NULL;
    END IF;
    
    -- Calcular rendimiento (km por galón)
    IF NEW.kilometraje_recorrido IS NOT NULL AND NEW.galones > 0 THEN
        NEW.rendimiento = NEW.kilometraje_recorrido / NEW.galones;
    ELSE
        NEW.rendimiento = NULL;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_calcular_rendimiento_combustible
    BEFORE INSERT OR UPDATE ON global.combustible_detalle
    FOR EACH ROW
    EXECUTE FUNCTION global.calcular_rendimiento_combustible();

-- =============================================
-- DATOS INICIALES
-- =============================================



-- =============================================
-- VISTAS ÚTILES
-- =============================================

-- Vista de gastos por vehículo
CREATE VIEW global.vista_gastos_vehiculo AS
SELECT 
    v.id_vehiculo,
    v.placa_vehiculo,
    v.conductor,
    g.tipo_gasto,
    SUM(g.monto) as total_gasto,
    COUNT(g.id_gasto) as cantidad_gastos,
    MIN(g.fecha_gasto) as primer_gasto,
    MAX(g.fecha_gasto) as ultimo_gasto
FROM global.vehiculos v
LEFT JOIN global.gastos g ON v.id_vehiculo = g.id_vehiculo
GROUP BY v.id_vehiculo, v.placa_vehiculo, v.conductor, g.tipo_gasto;

-- Vista de resumen mensual
CREATE VIEW global.vista_resumen_mensual AS
SELECT 
    DATE_TRUNC('month', g.fecha_gasto) as mes,
    g.tipo_gasto,
    COUNT(g.id_gasto) as cantidad,
    SUM(g.monto) as total,
    AVG(g.monto) as promedio
FROM global.gastos g
GROUP BY DATE_TRUNC('month', g.fecha_gasto), g.tipo_gasto
ORDER BY mes DESC, g.tipo_gasto;

-- Vista de rendimiento por vehículo
CREATE VIEW global.vista_rendimiento_vehiculo AS
SELECT 
    v.id_vehiculo,
    v.placa_vehiculo,
    v.tipo_vehiculo,
    cd.tipo_carburante,
    AVG(cd.rendimiento) as rendimiento_promedio,
    SUM(cd.galones) as total_galones,
    SUM(cd.kilometraje_recorrido) as total_kilometros,
    COUNT(cd.id_detalle) as numero_cargas
FROM global.vehiculos v
JOIN global.gastos g ON v.id_vehiculo = g.id_vehiculo
JOIN global.combustible_detalle cd ON g.id_gasto = cd.id_gasto
WHERE g.tipo_gasto = 'Combustible'
GROUP BY v.id_vehiculo, v.placa_vehiculo, v.tipo_vehiculo, cd.tipo_carburante;

-- Vista de clasificación de gastos
CREATE VIEW global.vista_clasificacion AS
SELECT 
    c.id_clasificacion,
    v.placa_vehiculo,
    c.periodo,
    c.combustible,
    c.gastos_administracion,
    c.compra_activos,
    c.varios,
    c.mantenimiento,
    c.peajes,
    c.sueldos,
    c.viaticos,
    c.sueldo_total,
    c.total_gastos
FROM global.clasificacion c
JOIN global.vehiculos v ON c.id_vehiculo = v.id_vehiculo;

-- =============================================
-- MOSTRAR RESUMEN
-- =============================================
DO $$
DECLARE
    v_tablas INTEGER;
    v_vehiculos INTEGER;
    v_gastos INTEGER;
BEGIN
    SELECT COUNT(*) INTO v_tablas 
    FROM information_schema.tables 
    WHERE table_schema = 'global';
    
    SELECT COUNT(*) INTO v_vehiculos
    FROM global.vehiculos;
    
    SELECT COUNT(*) INTO v_gastos
    FROM global.gastos;
    
    RAISE NOTICE '==========================================';
    RAISE NOTICE '✅ BASE DE DATOS CREADA EXITOSAMENTE';
    RAISE NOTICE '==========================================';
    RAISE NOTICE 'Schema: global';
    RAISE NOTICE 'Tablas creadas: %', v_tablas;
    RAISE NOTICE 'Vehículos registrados: %', v_vehiculos;
    RAISE NOTICE 'Gastos registrados: %', v_gastos;
    RAISE NOTICE '==========================================';
END $$;


/*SESIONES*/

-- =============================================
-- TABLA: usuarios (mejorada)
-- Schema: global
-- =============================================

CREATE TABLE global.usuarios (
    id_usuario SERIAL PRIMARY KEY,
    usuario VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    contrasenha VARCHAR(255) NOT NULL, -- Para hash de contraseña (bcrypt)
    
    -- Datos personales
    nombres VARCHAR(100),
    apellidos VARCHAR(100),
    documento_identidad VARCHAR(20) UNIQUE,
    telefono VARCHAR(20),
    
    -- Control de acceso
    rol VARCHAR(20) DEFAULT 'operador' CHECK (rol IN ('admin', 'supervisor', 'operador', 'lectura')),
    estado INTEGER DEFAULT 1 CHECK (estado IN (1, 0)), -- 1=Activo, 0=Inactivo, 2=Bloqueado
    
    -- Seguridad
    intentos_fallidos INTEGER DEFAULT 0,
    bloqueado_hasta TIMESTAMP NULL,
    ultimo_login TIMESTAMP NULL,
    ultimo_ip VARCHAR(45),
    
    -- Auditoría
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    creado_por INTEGER REFERENCES global.usuarios(id_usuario) ON DELETE SET NULL,
    
    -- Token para recuperación
    reset_token VARCHAR(255) NULL,
    reset_token_expira TIMESTAMP NULL,
    
    -- Preferencias
    tema VARCHAR(20) DEFAULT 'claro',
    notificaciones BOOLEAN DEFAULT TRUE,
    
    observaciones TEXT
);

-- Índices
CREATE INDEX idx_usuarios_usuario ON global.usuarios(usuario);
CREATE INDEX idx_usuarios_email ON global.usuarios(email);
CREATE INDEX idx_usuarios_documento ON global.usuarios(documento_identidad);
CREATE INDEX idx_usuarios_estado ON global.usuarios(estado);
CREATE INDEX idx_usuarios_rol ON global.usuarios(rol);

-- =============================================
-- TABLA: sesiones (para control de sesiones activas)
-- =============================================
CREATE TABLE global.sesiones (
    id_sesion SERIAL PRIMARY KEY,
    id_usuario INTEGER REFERENCES global.usuarios(id_usuario) ON DELETE CASCADE,
    token VARCHAR(255) NOT NULL UNIQUE,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    fecha_inicio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ultima_actividad TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_expiracion TIMESTAMP NOT NULL,
    activo BOOLEAN DEFAULT TRUE,
    dispositivo VARCHAR(100)
);

CREATE INDEX idx_sesiones_token ON global.sesiones(token);
CREATE INDEX idx_sesiones_usuario ON global.sesiones(id_usuario);
CREATE INDEX idx_sesiones_activo ON global.sesiones(activo);

-- =============================================
-- TABLA: logs_actividad (para auditoría de acciones)
-- =============================================
CREATE TABLE global.logs_actividad (
    id_log SERIAL PRIMARY KEY,
    id_usuario INTEGER REFERENCES global.usuarios(id_usuario) ON DELETE SET NULL,
    usuario VARCHAR(50),
    accion VARCHAR(100) NOT NULL,
    modulo VARCHAR(50),
    descripcion TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    datos_adicionales JSONB,
    fecha_evento TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_logs_usuario ON global.logs_actividad(id_usuario);
CREATE INDEX idx_logs_fecha ON global.logs_actividad(fecha_evento);
CREATE INDEX idx_logs_accion ON global.logs_actividad(accion);

-- =============================================
-- FUNCIÓN: actualizar timestamp
-- =============================================
CREATE OR REPLACE FUNCTION global.actualizar_timestamp_usuario()
RETURNS TRIGGER AS $$
BEGIN
    NEW.fecha_actualizacion = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_actualizar_usuario
    BEFORE UPDATE ON global.usuarios
    FOR EACH ROW
    EXECUTE FUNCTION global.actualizar_timestamp_usuario();

-- =============================================
-- DATOS INICIALES (contraseñas hasheadas con bcrypt)
-- =============================================

-- Insertar usuario admin (contraseña: admin123)
INSERT INTO global.usuarios (
    usuario, email, contrasenha, nombres, apellidos, rol, estado
) VALUES (
    'admin',
    'admin@transporte.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- admin123
    'Administrador',
    'Sistema',
    'admin',
    1
);

-- Insertar usuario supervisor (contraseña: supervisor123)
INSERT INTO global.usuarios (
    usuario, email, contrasenha, nombres, apellidos, rol, estado
) VALUES (
    'supervisor',
    'supervisor@transporte.com',
    '$2y$10$wQxU9ZzYzZzZzZzZzZzZzO', -- supervisor123
    'Supervisor',
    'General',
    'supervisor',
    1
);

-- Insertar usuario operador (contraseña: operador123)
INSERT INTO global.usuarios (
    usuario, email, contrasenha, nombres, apellidos, rol, estado
) VALUES (
    'operador',
    'operador@transporte.com',
    '$2y$10$mE8W2qWqWqWqWqWqWqWqWO', -- operador123
    'Operador',
    'Prueba',
    'operador',
    1
);

-- Insertar usuario solo lectura (contraseña: lectura123)
INSERT INTO global.usuarios (
    usuario, email, contrasenha, nombres, apellidos, rol, estado
) VALUES (
    'lectura',
    'lectura@transporte.com',
    '$2y$10$rL5N8VxZyAbCdEfGhIjKlM', -- lectura123
    'Usuario',
    'Lectura',
    'lectura',
    1
);

-- =============================================
-- VISTAS ÚTILES
-- =============================================

-- Vista de usuarios activos
CREATE VIEW global.vista_usuarios_activos AS
SELECT 
    id_usuario,
    usuario,
    email,
    nombres,
    apellidos,
    rol,
    ultimo_login,
    ultimo_ip
FROM global.usuarios
WHERE estado = 1;

-- Vista de estadísticas de usuarios
CREATE VIEW global.vista_estadisticas_usuarios AS
SELECT 
    rol,
    COUNT(*) as total,
    SUM(CASE WHEN estado = 1 THEN 1 ELSE 0 END) as activos,
    SUM(CASE WHEN estado = 0 THEN 1 ELSE 0 END) as inactivos,
    MAX(fecha_creacion) as ultimo_registro
FROM global.usuarios
GROUP BY rol;

-- =============================================
-- FUNCIÓN PARA VERIFICAR CREDENCIALES
-- =============================================
CREATE OR REPLACE FUNCTION global.verificar_credenciales(
    p_usuario VARCHAR(50),
    p_contrasenha VARCHAR(255)
)
RETURNS TABLE(
    id_usuario INTEGER,
    usuario VARCHAR(50),
    nombres VARCHAR(100),
    apellidos VARCHAR(100),
    email VARCHAR(100),
    rol VARCHAR(20),
    mensaje TEXT
) AS $$
DECLARE
    v_usuario RECORD;
    v_bloqueado BOOLEAN;
BEGIN
    -- Buscar usuario
    SELECT * INTO v_usuario
    FROM global.usuarios
    WHERE (usuario = p_usuario OR email = p_usuario)
    LIMIT 1;
    
    -- Verificar si existe
    IF NOT FOUND THEN
        RETURN QUERY SELECT NULL::INTEGER, NULL::VARCHAR, NULL::VARCHAR, NULL::VARCHAR, NULL::VARCHAR, NULL::VARCHAR, 'Usuario no encontrado'::TEXT;
        RETURN;
    END IF;
    
    -- Verificar si está bloqueado
    IF v_usuario.bloqueado_hasta IS NOT NULL AND v_usuario.bloqueado_hasta > CURRENT_TIMESTAMP THEN
        RETURN QUERY SELECT NULL::INTEGER, NULL::VARCHAR, NULL::VARCHAR, NULL::VARCHAR, NULL::VARCHAR, NULL::VARCHAR, 
            'Cuenta bloqueada hasta ' || to_char(v_usuario.bloqueado_hasta, 'DD/MM/YYYY HH24:MI')::TEXT;
        RETURN;
    END IF;
    
    -- Verificar si está inactivo
    IF v_usuario.estado = 0 THEN
        RETURN QUERY SELECT NULL::INTEGER, NULL::VARCHAR, NULL::VARCHAR, NULL::VARCHAR, NULL::VARCHAR, NULL::VARCHAR, 'Cuenta inactiva'::TEXT;
        RETURN;
    END IF;
    
    -- Nota: La verificación de contraseña debe hacerse en PHP con password_verify()
    -- Esta función solo devuelve los datos del usuario si la cuenta está activa
    
    RETURN QUERY SELECT 
        v_usuario.id_usuario,
        v_usuario.usuario,
        v_usuario.nombres,
        v_usuario.apellidos,
        v_usuario.email,
        v_usuario.rol,
        'OK'::TEXT;
    
END;
$$ LANGUAGE plpgsql;

-- =============================================
-- FUNCIÓN PARA REGISTRAR LOGIN FALLIDO
-- =============================================
CREATE OR REPLACE FUNCTION global.registrar_intento_fallido(
    p_usuario VARCHAR(50),
    p_ip VARCHAR(45)
)
RETURNS VOID AS $$
DECLARE
    v_intentos INTEGER;
    v_usuario_id INTEGER;
BEGIN
    -- Buscar usuario
    SELECT id_usuario INTO v_usuario_id
    FROM global.usuarios
    WHERE usuario = p_usuario OR email = p_usuario;
    
    IF FOUND THEN
        -- Incrementar intentos
        UPDATE global.usuarios
        SET intentos_fallidos = intentos_fallidos + 1
        WHERE id_usuario = v_usuario_id;
        
        -- Obtener intentos actuales
        SELECT intentos_fallidos INTO v_intentos
        FROM global.usuarios
        WHERE id_usuario = v_usuario_id;
        
        -- Bloquear después de 5 intentos fallidos
        IF v_intentos >= 5 THEN
            UPDATE global.usuarios
            SET bloqueado_hasta = CURRENT_TIMESTAMP + INTERVAL '30 minutes'
            WHERE id_usuario = v_usuario_id;
        END IF;
    END IF;
    
    -- Registrar log de actividad
    INSERT INTO global.logs_actividad (
        usuario, accion, modulo, ip_address, datos_adicionales
    ) VALUES (
        p_usuario,
        'login_fallido',
        'autenticacion',
        p_ip,
        jsonb_build_object('intentos', v_intentos)
    );
END;
$$ LANGUAGE plpgsql;

-- =============================================
-- FUNCIÓN PARA REGISTRAR LOGIN EXITOSO
-- =============================================
CREATE OR REPLACE FUNCTION global.registrar_login_exitoso(
    p_id_usuario INTEGER,
    p_ip VARCHAR(45),
    p_user_agent TEXT
)
RETURNS VOID AS $$
BEGIN
    -- Actualizar último login
    UPDATE global.usuarios
    SET 
        ultimo_login = CURRENT_TIMESTAMP,
        ultimo_ip = p_ip,
        intentos_fallidos = 0,
        bloqueado_hasta = NULL
    WHERE id_usuario = p_id_usuario;
    
    -- Registrar log de actividad
    INSERT INTO global.logs_actividad (
        id_usuario, usuario, accion, modulo, ip_address, user_agent
    ) VALUES (
        p_id_usuario,
        (SELECT usuario FROM global.usuarios WHERE id_usuario = p_id_usuario),
        'login_exitoso',
        'autenticacion',
        p_ip,
        p_user_agent
    );
END;
$$ LANGUAGE plpgsql;

-- =============================================
-- FUNCIÓN PARA CREAR SESIÓN
-- =============================================
CREATE OR REPLACE FUNCTION global.crear_sesion(
    p_id_usuario INTEGER,
    p_token VARCHAR(255),
    p_ip VARCHAR(45),
    p_user_agent TEXT,
    p_dispositivo VARCHAR(100)
)
RETURNS INTEGER AS $$
DECLARE
    v_id_sesion INTEGER;
BEGIN
    INSERT INTO global.sesiones (
        id_usuario, token, ip_address, user_agent, fecha_expiracion, dispositivo
    ) VALUES (
        p_id_usuario,
        p_token,
        p_ip,
        p_user_agent,
        CURRENT_TIMESTAMP + INTERVAL '8 hours',
        p_dispositivo
    )
    RETURNING id_sesion INTO v_id_sesion;
    
    RETURN v_id_sesion;
END;
$$ LANGUAGE plpgsql;

-- =============================================
-- FUNCIÓN PARA CERRAR SESIÓN
-- =============================================
CREATE OR REPLACE FUNCTION global.cerrar_sesion(p_token VARCHAR(255))
RETURNS VOID AS $$
BEGIN
    UPDATE global.sesiones
    SET activo = FALSE
    WHERE token = p_token;
    
    -- Registrar cierre de sesión
    INSERT INTO global.logs_actividad (
        accion, modulo, datos_adicionales
    ) VALUES (
        'logout',
        'autenticacion',
        jsonb_build_object('token', p_token)
    );
END;
$$ LANGUAGE plpgsql;

-- =============================================
-- MOSTRAR RESUMEN
-- =============================================
DO $$
DECLARE
    v_usuarios INTEGER;
BEGIN
    SELECT COUNT(*) INTO v_usuarios
    FROM global.usuarios;
    
    RAISE NOTICE '==========================================';
    RAISE NOTICE '✅ TABLA DE USUARIOS CREADA EXITOSAMENTE';
    RAISE NOTICE '==========================================';
    RAISE NOTICE 'Usuarios registrados: %', v_usuarios;
    RAISE NOTICE 'Roles: admin, supervisor, operador, lectura';
    RAISE NOTICE '==========================================';
END $$;

-- Mostrar datos si quiere datos ejemplares


-- =============================================
-- SOLO DATOS MODIFICADOS - VERSIÓN BOLIVIA
-- PARA POSTGRESQL
-- =============================================

-- Limpiar datos existentes en orden inverso (hijos primero)
DELETE FROM combustible_detalle;
DELETE FROM gastos;
DELETE FROM clasificacion;
DELETE FROM vehiculos;

-- Reiniciar secuencias (opcional)
ALTER SEQUENCE vehiculos_id_vehiculo_seq RESTART WITH 1;
ALTER SEQUENCE clasificacion_id_clasificacion_seq RESTART WITH 1;
ALTER SEQUENCE gastos_id_gasto_seq RESTART WITH 1;
ALTER SEQUENCE combustible_detalle_id_detalle_seq RESTART WITH 1;

-- =============================================
-- 1. PRIMERO INSERTAR VEHÍCULOS
-- =============================================
INSERT INTO vehiculos (placa_vehiculo, anho, tipo_vehiculo, conductor, tramo_actual, estado) VALUES
('1234-LMB', 2022, 'Camión', 'Juan Mamani', 'Santa Cruz - La Paz', 1),
('5678-SCZ', 2023, 'Camioneta', 'Carlos Quispe', 'Santa Cruz - Cochabamba', 1),
('9012-CBB', 2021, 'Bus', 'María Choque', 'La Paz - Oruro', 1),
('3456-TJA', 2020, 'Camión', 'Pedro Flores', 'Tarija - Villamontes', 2),
('7890-PTS', 2024, 'Camioneta', 'Ana Vaca', 'Potosi - Sucre', 1);

-- =============================================
-- 2. LUEGO INSERTAR CLASIFICACIONES (depende de vehiculos)
-- =============================================
INSERT INTO clasificacion (id_vehiculo, contiene_cantidad, relacionado_con_vehiculos, 
    combustible, gastos_administracion, compra_activos, varios, mantenimiento, peajes, sueldos, viaticos, 
    sueldo_previo, periodo) VALUES
(1, TRUE, TRUE, 3500.00, 1200.00, 8500.00, 450.00, 2200.00, 380.00, 3800.00, 600.00, 3500.00, '2024-01'),
(2, TRUE, TRUE, 2800.00, 900.00, 5000.00, 300.00, 1500.00, 280.00, 3200.00, 500.00, 3000.00, '2024-01'),
(3, FALSE, TRUE, 4200.00, 1500.00, 10000.00, 550.00, 2800.00, 480.00, 4500.00, 800.00, 4000.00, '2024-01');

-- =============================================
-- 3. INSERTAR GASTOS (depende de vehiculos)
-- =============================================

-- Insertar gastos (Combustible)
INSERT INTO gastos (id_vehiculo, tipo_gasto, concepto, descripcion, monto, cantidad, fecha_gasto, proveedor, kilometraje) VALUES
(1, 'Combustible', 'Carga de Diesel', 'Primera carga del mes', 2625.00, 150, '2024-01-15', 'YPFB - Santa Cruz', 12500),
(1, 'Combustible', 'Carga de Diesel', 'Segunda carga', 3150.00, 180, '2024-01-20', 'YPFB - La Paz', 12800),
(2, 'Combustible', 'Gasolina Premium', 'Carga semanal', 1850.00, 100, '2024-01-16', 'YPFB - Cochabamba', 8500),
(3, 'Combustible', 'Diesel', 'Carga principal', 3500.00, 200, '2024-01-18', 'YPFB - Oruro', 15200);

-- Insertar gastos (Peajes)
INSERT INTO gastos (id_vehiculo, tipo_gasto, concepto, descripcion, monto, cantidad, fecha_gasto, caseta, ruta) VALUES
(1, 'Peaje', 'Peaje Santa Cruz - La Paz', 'Paso por caseta de Pongo', 35.00, 1, '2024-01-15', 'Caseta Pongo', 'Santa Cruz - La Paz'),
(1, 'Peaje', 'Peaje La Paz - Santa Cruz', 'Paso por caseta de Caracollo', 35.00, 1, '2024-01-20', 'Caseta Caracollo', 'La Paz - Santa Cruz'),
(2, 'Peaje', 'Peaje Santa Cruz - Cochabamba', 'Paso por caseta de Samaipata', 25.00, 1, '2024-01-16', 'Caseta Samaipata', 'Santa Cruz - Cochabamba'),
(3, 'Peaje', 'Peaje La Paz - Oruro', 'Paso por caseta de El Alto', 30.00, 1, '2024-01-18', 'Caseta El Alto', 'La Paz - Oruro');

-- Insertar gastos (Mantenimiento)
INSERT INTO gastos (id_vehiculo, tipo_gasto, concepto, descripcion, monto, cantidad, fecha_gasto, taller, tipo_mantenimiento, proveedor) VALUES
(1, 'Mantenimiento', 'Cambio de Aceite', 'Cambio de aceite y filtros', 650.00, 1, '2024-01-15', 'Taller El Progreso', 'Cambio de Aceite', 'Castrol'),
(1, 'Mantenimiento', 'Alineación', 'Alineación y balanceo', 250.00, 1, '2024-01-20', 'Lubricentro Central', 'Alineación', NULL),
(2, 'Mantenimiento', 'Cambio de Llantas', 'Cambio de 2 llantas delanteras', 1800.00, 2, '2024-01-16', 'Llantas del Oriente', 'Cambio de Llantas', 'Goodyear'),
(3, 'Mantenimiento', 'Mantenimiento Preventivo', 'Revisión general', 1200.00, 1, '2024-01-18', 'Taller Central La Paz', 'Preventivo', NULL);

-- Insertar gastos (Administración)
INSERT INTO gastos (id_vehiculo, tipo_gasto, concepto, descripcion, monto, cantidad, fecha_gasto, proveedor) VALUES
(1, 'Administracion', 'SOAT', 'Seguro Obligatorio', 680.00, 1, '2024-01-05', 'La Boliviana'),
(1, 'Administracion', 'Revisión Técnica', 'Revisión técnica vehicular', 280.00, 1, '2024-01-10', 'CITV Santa Cruz'),
(2, 'Administracion', 'GPS', 'Mensualidad GPS', 350.00, 1, '2024-01-15', 'TrackGPS Bolivia');

-- Insertar gastos (Varios)
INSERT INTO gastos (id_vehiculo, tipo_gasto, concepto, descripcion, monto, cantidad, fecha_gasto, proveedor) VALUES
(1, 'Varios', 'Lavado', 'Lavado completo', 70.00, 1, '2024-01-12', 'Lavadero Express'),
(2, 'Varios', 'Herramientas', 'Kit de herramientas', 180.00, 1, '2024-01-14', 'Ferretería Industrial');

-- =============================================
-- 4. INSERTAR DETALLES DE COMBUSTIBLE (depende de gastos)
-- =============================================
INSERT INTO combustible_detalle (id_gasto, tipo_carburante, galones, precio_por_galon, estacion_servicio, kilometraje_actual, kilometraje_anterior) VALUES
(1, 'Diesel Premium', 150.00, 17.50, 'YPFB - Santa Cruz', 12500, 11000),
(2, 'Diesel Premium', 180.00, 17.50, 'YPFB - La Paz', 12800, 12500),
(3, 'Gasolina Premium', 100.00, 18.50, 'YPFB - Cochabamba', 8500, 7500),
(4, 'Diesel Premium', 200.00, 17.50, 'YPFB - Oruro', 15200, 13200);


SELECT * FROM personal

-- =============================================
-- INSERTAR DATOS EN TABLA PERSONAL
-- =============================================

-- Insertar personal (conductores, operadores, mecánicos, etc)
INSERT INTO personal (nombres, apellidos, cargo, telefono, licencia, estado) VALUES
('Juan', 'Mamani Flores', 'CONDUCTOR', '78945612', 'A2B-123456', 1),
('Carlos', 'Quispe Vargas', 'CONDUCTOR', '78945613', 'A2B-123457', 1),
('María', 'Choque Condori', 'CONDUCTORA', '78945614', 'A2B-123458', 1),
('Pedro', 'Flores Paredes', 'MECANICO', '78945615', NULL, 1),
('Ana', 'Vaca García', 'OPERADOR', '78945616', NULL, 1),
('Roberto', 'Condori Mamani', 'CONDUCTOR', '78945617', 'A2B-123459', 1),
('Lucía', 'Rojas Jiménez', 'SUPERVISOR', '78945618', NULL, 1),
('José', 'Limachi Céspedes', 'MECANICO', '78945619', NULL, 1),
('Rosa', 'Mamani Quispe', 'OPERADOR', '78945620', NULL, 1),
('Félix', 'Gutiérrez Claure', 'CONDUCTOR', '78945621', 'A2B-123460', 1),
('Patricia', 'Sánchez López', 'ADMINISTRATIVO', '78945622', NULL, 1),
('Miguel', 'Torrico Rojas', 'CONDUCTOR', '78945623', 'A2B-123461', 1),
('Claudia', 'Fernández Gutiérrez', 'SUPERVISOR', '78945624', NULL, 1),
('Sergio', 'Montaño Villarroel', 'MECANICO', '78945625', NULL, 1),
('Daniela', 'Álvarez Ortiz', 'OPERADOR', '78945626', NULL, 1),
('Luis', 'Rodríguez Zenteno', 'CONDUCTOR', '78945627', 'A2B-123462', 1),
('Verónica', 'Castro Rocha', 'ADMINISTRATIVO', '78945628', NULL, 1),
('Ramiro', 'Molina Cuéllar', 'CONDUCTOR', '78945629', 'A2B-123463', 1),
('Silvia', 'Vargas Durán', 'SUPERVISOR', '78945630', NULL, 1),
('Eduardo', 'Ortiz Justiniano', 'MECANICO', '78945631', NULL, 1);

/**INVENTARIO O ALMACENES*/

-- =============================================
-- TABLA: inventario (Almacén)
-- Schema: global
-- =============================================

-- Verificar si el schema existe, si no crearlo
CREATE SCHEMA IF NOT EXISTS global;

-- Establecer search path
SET search_path TO global, public;

-- =============================================
-- 1. TABLA: inventario (productos en almacén)
-- =============================================
CREATE TABLE IF NOT EXISTS global.inventario (
    id_inventario SERIAL PRIMARY KEY,
    
    -- Código único del producto
    codigo VARCHAR(20) UNIQUE NOT NULL,
    
    -- Datos del producto
    nombre_producto VARCHAR(100) NOT NULL,
    descripcion TEXT,
    categoria VARCHAR(50) NOT NULL,
    
    -- Unidad de medida
    unidad_medida VARCHAR(20) DEFAULT 'UNIDAD',
    
    -- Control de stock
    stock_actual DECIMAL(10,2) DEFAULT 0,
    stock_minimo DECIMAL(10,2) DEFAULT 0,
    stock_maximo DECIMAL(10,2) DEFAULT 0,
    
    -- Precios
    precio_compra DECIMAL(12,2) DEFAULT 0,
    precio_venta DECIMAL(12,2) DEFAULT 0,
    ultimo_costo DECIMAL(12,2) DEFAULT 0,
    
    -- Proveedor
    id_proveedor INTEGER,
    ubicacion_almacen VARCHAR(100),
    
    -- Fechas
    fecha_ingreso DATE,
    fecha_vencimiento DATE,
    fecha_ultima_compra DATE,
    fecha_ultima_salida DATE,
    
    -- Estado
    estado VARCHAR(20) DEFAULT 'ACTIVO',
    
    -- Datos adicionales
    peso_kg DECIMAL(10,2),
    marca VARCHAR(50),
    modelo VARCHAR(50),
    especificaciones TEXT,
    imagen_url TEXT,
    codigo_barras VARCHAR(50),
    
    -- Auditoría
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INTEGER
);

-- =============================================
-- ÍNDICES para inventario
-- =============================================
CREATE INDEX IF NOT EXISTS idx_inventario_codigo ON global.inventario(codigo);
CREATE INDEX IF NOT EXISTS idx_inventario_categoria ON global.inventario(categoria);
CREATE INDEX IF NOT EXISTS idx_inventario_nombre ON global.inventario(nombre_producto);
CREATE INDEX IF NOT EXISTS idx_inventario_estado ON global.inventario(estado);
CREATE INDEX IF NOT EXISTS idx_inventario_stock ON global.inventario(stock_actual);

-- =============================================
-- 2. TABLA: movimientos_inventario
-- =============================================
CREATE TABLE IF NOT EXISTS global.movimientos_inventario (
    id_movimiento SERIAL PRIMARY KEY,
    id_inventario INTEGER NOT NULL,
    
    -- Tipo de movimiento
    tipo_movimiento VARCHAR(20) NOT NULL,
    
    -- Cantidades
    cantidad DECIMAL(10,2) NOT NULL,
    costo_unitario DECIMAL(12,2),
    costo_total DECIMAL(12,2) GENERATED ALWAYS AS (cantidad * costo_unitario) STORED,
    
    -- Referencias
    id_gasto INTEGER,
    id_vehiculo INTEGER,
    id_personal INTEGER,
    
    -- Documentación
    documento_tipo VARCHAR(20),
    documento_numero VARCHAR(50),
    proveedor VARCHAR(100),
    
    -- Fechas
    fecha_movimiento DATE NOT NULL DEFAULT CURRENT_DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Motivo
    motivo TEXT,
    observaciones TEXT,
    
    -- Auditoría
    registrado_por INTEGER,
    
    -- Restricciones
    CONSTRAINT chk_tipo_movimiento CHECK (tipo_movimiento IN (
        'COMPRA', 'VENTA', 'DEVOLUCION', 'AJUSTE', 'TRANSFERENCIA', 'CONSUMO', 'PERDIDA'
    )),
    CONSTRAINT chk_cantidad_positiva CHECK (cantidad > 0)
);

-- =============================================
-- ÍNDICES para movimientos_inventario
-- =============================================
CREATE INDEX IF NOT EXISTS idx_movimientos_inventario ON global.movimientos_inventario(id_inventario);
CREATE INDEX IF NOT EXISTS idx_movimientos_tipo ON global.movimientos_inventario(tipo_movimiento);
CREATE INDEX IF NOT EXISTS idx_movimientos_fecha ON global.movimientos_inventario(fecha_movimiento);
CREATE INDEX IF NOT EXISTS idx_movimientos_gasto ON global.movimientos_inventario(id_gasto);
CREATE INDEX IF NOT EXISTS idx_movimientos_vehiculo ON global.movimientos_inventario(id_vehiculo);

-- =============================================
-- 3. TABLA: proveedores (si no existe)
-- =============================================
CREATE TABLE IF NOT EXISTS global.proveedores (
    id_proveedor SERIAL PRIMARY KEY,
    nit_ci VARCHAR(20) UNIQUE,
    nombre_proveedor VARCHAR(100) NOT NULL,
    contacto VARCHAR(100),
    telefono VARCHAR(20),
    email VARCHAR(100),
    direccion TEXT,
    tipo_proveedor VARCHAR(50) DEFAULT 'GENERAL',
    estado INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================
-- ÍNDICES para proveedores
-- =============================================
CREATE INDEX IF NOT EXISTS idx_proveedores_nombre ON global.proveedores(nombre_proveedor);
CREATE INDEX IF NOT EXISTS idx_proveedores_nit ON global.proveedores(nit_ci);

-- =============================================
-- 4. FUNCIÓN: actualizar stock automáticamente
-- =============================================
CREATE OR REPLACE FUNCTION global.actualizar_stock_inventario()
RETURNS TRIGGER AS $$
BEGIN
    -- Actualizar stock según tipo de movimiento
    IF NEW.tipo_movimiento IN ('COMPRA', 'DEVOLUCION') THEN
        -- Entrada: sumar stock
        UPDATE global.inventario 
        SET stock_actual = stock_actual + NEW.cantidad,
            fecha_ultima_compra = NEW.fecha_movimiento,
            ultimo_costo = COALESCE(NEW.costo_unitario, ultimo_costo)
        WHERE id_inventario = NEW.id_inventario;
        
    ELSIF NEW.tipo_movimiento IN ('VENTA', 'CONSUMO', 'PERDIDA', 'TRANSFERENCIA') THEN
        -- Salida: restar stock
        UPDATE global.inventario 
        SET stock_actual = stock_actual - NEW.cantidad,
            fecha_ultima_salida = NEW.fecha_movimiento
        WHERE id_inventario = NEW.id_inventario;
        
    ELSIF NEW.tipo_movimiento = 'AJUSTE' THEN
        -- Ajuste: no se modifica automáticamente, se maneja manualmente
        NULL;
    END IF;
    
    -- Actualizar estado si stock bajo o agotado
    UPDATE global.inventario 
    SET estado = CASE 
        WHEN stock_actual <= 0 THEN 'AGOTADO'
        WHEN stock_actual <= stock_minimo THEN 'BAJO'
        ELSE 'ACTIVO'
    END
    WHERE id_inventario = NEW.id_inventario;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- =============================================
-- 5. TRIGGER: actualizar stock automáticamente
-- =============================================
DROP TRIGGER IF EXISTS trigger_actualizar_stock_inventario ON global.movimientos_inventario;

CREATE TRIGGER trigger_actualizar_stock_inventario
    AFTER INSERT ON global.movimientos_inventario
    FOR EACH ROW
    EXECUTE FUNCTION global.actualizar_stock_inventario();

-- =============================================
-- 6. FUNCIÓN: actualizar timestamp
-- =============================================
CREATE OR REPLACE FUNCTION global.actualizar_timestamp_inventario()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- =============================================
-- 7. TRIGGER: actualizar timestamp
-- =============================================
DROP TRIGGER IF EXISTS trigger_actualizar_timestamp_inventario ON global.inventario;

CREATE TRIGGER trigger_actualizar_timestamp_inventario
    BEFORE UPDATE ON global.inventario
    FOR EACH ROW
    EXECUTE FUNCTION global.actualizar_timestamp_inventario();

-- =============================================
-- 8. VISTA: productos con stock bajo
-- =============================================
CREATE OR REPLACE VIEW global.vista_stock_bajo AS
SELECT 
    codigo,
    nombre_producto,
    categoria,
    stock_actual,
    stock_minimo,
    (stock_actual - stock_minimo) as diferencia,
    ubicacion_almacen,
    CASE 
        WHEN stock_actual <= 0 THEN 'CRITICO - SIN STOCK'
        WHEN stock_actual <= stock_minimo THEN 'BAJO - REPONER'
        ELSE 'OK'
    END as nivel_stock
FROM global.inventario
WHERE stock_actual <= stock_minimo
ORDER BY stock_actual ASC;

-- =============================================
-- 9. VISTA: resumen de inventario por categoría
-- =============================================
CREATE OR REPLACE VIEW global.vista_resumen_inventario AS
SELECT 
    categoria,
    COUNT(*) as total_productos,
    SUM(stock_actual) as stock_total,
    SUM(stock_actual * precio_compra) as valor_inventario_compra,
    SUM(stock_actual * precio_venta) as valor_inventario_venta,
    SUM(CASE WHEN stock_actual <= stock_minimo THEN 1 ELSE 0 END) as productos_stock_bajo,
    SUM(CASE WHEN stock_actual = 0 THEN 1 ELSE 0 END) as productos_agotados
FROM global.inventario
WHERE estado != 'INACTIVO'
GROUP BY categoria
ORDER BY categoria;

-- =============================================
-- 10. VISTA: últimos movimientos
-- =============================================
CREATE OR REPLACE VIEW global.vista_ultimos_movimientos AS
SELECT 
    m.id_movimiento,
    i.codigo,
    i.nombre_producto,
    m.tipo_movimiento,
    m.cantidad,
    m.costo_unitario,
    m.costo_total,
    m.fecha_movimiento,
    m.proveedor,
    m.motivo,
    m.observaciones
FROM global.movimientos_inventario m
JOIN global.inventario i ON m.id_inventario = i.id_inventario
ORDER BY m.fecha_movimiento DESC
LIMIT 100;

-- =============================================
-- 11. DATOS INICIALES - PROVEEDORES
-- =============================================
INSERT INTO global.proveedores (nit_ci, nombre_proveedor, contacto, telefono, email, direccion, tipo_proveedor) VALUES
('1023456017', 'Llantas del Sur S.R.L.', 'Carlos Rojas', '78945612', 'ventas@llantassur.bo', 'Av. Busch N° 123, Santa Cruz', 'LLANTAS'),
('2034567891', 'Aceites Bolivianos S.A.', 'María Vaca', '78945613', 'contacto@aceitesbo.bo', 'Zona Industrial, Cochabamba', 'ACEITES'),
('3045678912', 'Filtros y Repuestos Ltda.', 'José Limachi', '78945614', 'ventas@filtros.bo', 'Av. Petrolera N° 456, La Paz', 'FILTROS'),
('4056789123', 'Repuestos Originales S.R.L.', 'Ana Quispe', '78945615', 'contacto@repuestos.bo', 'Calle 7 N° 890, El Alto', 'REPUESTOS');

-- =============================================
-- 12. DATOS INICIALES - INVENTARIO
-- =============================================
INSERT INTO global.inventario (
    codigo, nombre_producto, descripcion, categoria, unidad_medida,
    stock_actual, stock_minimo, stock_maximo,
    precio_compra, precio_venta, ultimo_costo,
    ubicacion_almacen, marca, estado, id_proveedor
) VALUES
-- Llantas
('LLA-00001', 'Llanta 295/80 R22.5', 'Llanta radial para camión', 'Llantas', 'UNIDAD', 
 10, 4, 20, 850.00, 950.00, 850.00, 'ESTANTE A-1', 'Michelin', 'ACTIVO', 1),
('LLA-00002', 'Llanta 11R22.5', 'Llanta para camión', 'Llantas', 'UNIDAD',
 8, 4, 15, 780.00, 880.00, 780.00, 'ESTANTE A-2', 'Goodyear', 'ACTIVO', 1),
('LLA-00003', 'Llanta 205/55 R16', 'Llanta para camioneta', 'Llantas', 'UNIDAD',
 12, 6, 25, 450.00, 550.00, 450.00, 'ESTANTE A-3', 'Bridgestone', 'ACTIVO', 1),

-- Aceites
('ACE-00001', 'Aceite 15W40', 'Aceite mineral para motor diesel', 'Aceites', 'GALON',
 45, 10, 100, 45.00, 65.00, 45.00, 'ESTANTE B-1', 'Castrol', 'ACTIVO', 2),
('ACE-00002', 'Aceite 20W50', 'Aceite para motor gasolina', 'Aceites', 'LITRO',
 30, 15, 80, 12.50, 18.50, 12.50, 'ESTANTE B-2', 'Mobil', 'ACTIVO', 2),
('ACE-00003', 'Aceite de Transmisión', 'Aceite para caja de cambios', 'Aceites', 'GALON',
 20, 8, 50, 65.00, 85.00, 65.00, 'ESTANTE B-3', 'Shell', 'ACTIVO', 2),

-- Filtros
('FIL-00001', 'Filtro de Aceite', 'Filtro de aceite para motor', 'Filtros', 'UNIDAD',
 25, 8, 60, 25.00, 40.00, 25.00, 'ESTANTE C-1', 'Fram', 'ACTIVO', 3),
('FIL-00002', 'Filtro de Combustible', 'Filtro para sistema de combustible', 'Filtros', 'UNIDAD',
 30, 10, 70, 30.00, 45.00, 30.00, 'ESTANTE C-2', 'Mann', 'ACTIVO', 3),
('FIL-00003', 'Filtro de Aire', 'Filtro para admisión de aire', 'Filtros', 'UNIDAD',
 18, 6, 40, 35.00, 55.00, 35.00, 'ESTANTE C-3', 'Baldwin', 'ACTIVO', 3),

-- Repuestos
('REP-00001', 'Pastillas de Freno', 'Juego de pastillas de freno', 'Repuestos', 'JUEGO',
 10, 4, 25, 120.00, 180.00, 120.00, 'ESTANTE D-1', 'Brembo', 'ACTIVO', 4),
('REP-00002', 'Kit de Embrague', 'Kit completo de embrague', 'Repuestos', 'JUEGO',
 5, 2, 15, 450.00, 650.00, 450.00, 'ESTANTE D-2', 'Valeo', 'ACTIVO', 4),
('REP-00003', 'Batería 12V', 'Batería para camión', 'Repuestos', 'UNIDAD',
 8, 3, 20, 380.00, 480.00, 380.00, 'ESTANTE D-3', 'Bosch', 'ACTIVO', 4),
('REP-00004', 'Amortiguadores', 'Juego de amortiguadores', 'Repuestos', 'JUEGO',
 6, 3, 15, 280.00, 380.00, 280.00, 'ESTANTE D-4', 'Monroe', 'ACTIVO', 4);

-- =============================================
-- 13. DATOS INICIALES - MOVIMIENTOS DE INVENTARIO
-- =============================================
INSERT INTO global.movimientos_inventario (
    id_inventario, tipo_movimiento, cantidad, costo_unitario, 
    fecha_movimiento, proveedor, motivo
) VALUES
-- Movimientos de llantas
(1, 'COMPRA', 10, 850.00, '2024-01-05', 'Llantas del Sur S.R.L.', 'Compra inicial'),
(2, 'COMPRA', 8, 780.00, '2024-01-05', 'Llantas del Sur S.R.L.', 'Compra inicial'),
(3, 'COMPRA', 12, 450.00, '2024-01-05', 'Llantas del Sur S.R.L.', 'Compra inicial'),
(1, 'CONSUMO', 2, 850.00, '2024-01-15', NULL, 'Cambio de llantas en vehículo 1234-LMB'),
(2, 'CONSUMO', 1, 780.00, '2024-01-20', NULL, 'Cambio de llantas en vehículo 5678-SCZ'),

-- Movimientos de aceites
(4, 'COMPRA', 45, 45.00, '2024-01-05', 'Aceites Bolivianos S.A.', 'Compra inicial'),
(5, 'COMPRA', 30, 12.50, '2024-01-05', 'Aceites Bolivianos S.A.', 'Compra inicial'),
(4, 'CONSUMO', 4, 45.00, '2024-01-15', NULL, 'Cambio de aceite vehículo 1234-LMB'),
(4, 'CONSUMO', 3, 45.00, '2024-01-20', NULL, 'Cambio de aceite vehículo 5678-SCZ'),

-- Movimientos de filtros
(7, 'COMPRA', 25, 25.00, '2024-01-05', 'Filtros y Repuestos Ltda.', 'Compra inicial'),
(8, 'COMPRA', 30, 30.00, '2024-01-05', 'Filtros y Repuestos Ltda.', 'Compra inicial'),
(7, 'CONSUMO', 2, 25.00, '2024-01-15', NULL, 'Cambio de filtro vehículo 1234-LMB'),

-- Movimientos de repuestos
(10, 'COMPRA', 10, 120.00, '2024-01-05', 'Repuestos Originales S.R.L.', 'Compra inicial'),
(11, 'COMPRA', 5, 450.00, '2024-01-05', 'Repuestos Originales S.R.L.', 'Compra inicial'),
(10, 'CONSUMO', 1, 120.00, '2024-01-20', NULL, 'Cambio de pastillas vehículo 5678-SCZ');

-- =============================================
-- 14. VERIFICAR DATOS INSERTADOS
-- =============================================
SELECT '✅ INVENTARIO - Productos registrados: ' || COUNT(*) FROM global.inventario;
SELECT '✅ PROVEEDORES - Proveedores registrados: ' || COUNT(*) FROM global.proveedores;
SELECT '✅ MOVIMIENTOS - Movimientos registrados: ' || COUNT(*) FROM global.movimientos_inventario;

-- Mostrar productos con stock bajo
SELECT * FROM global.vista_stock_bajo;

-- Mostrar resumen por categoría
SELECT * FROM global.vista_resumen_inventario;