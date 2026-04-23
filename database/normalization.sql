-- NORMALIZACIÓN Y MEJORA DE BASE DE DATOS (POSTGRESQL)
-- SISTEMA DE GESTIÓN DE TRANSPORTE

-- 1. ASEGURAR SCHEMA
CREATE SCHEMA IF NOT EXISTS global;

-- 2. TABLA PERSONAL (NORMALIZADA)
-- Ya existe, pero aseguramos columnas críticas
ALTER TABLE global.personal ADD COLUMN IF NOT EXISTS ci VARCHAR(20);
ALTER TABLE global.personal ADD COLUMN IF NOT EXISTS sueldo DECIMAL(15,2) DEFAULT 0;

-- 3. TABLA VEHÍCULOS (RELACIONADA CON PERSONAL)
ALTER TABLE global.vehiculos ADD COLUMN IF NOT EXISTS id_personal INTEGER;
ALTER TABLE global.vehiculos ADD COLUMN IF NOT EXISTS capacidad DECIMAL(15,2) DEFAULT 0;
ALTER TABLE global.vehiculos ADD COLUMN IF NOT EXISTS kilometraje DECIMAL(15,2) DEFAULT 0;

-- Añadir FK si no existe
DO $$ 
BEGIN 
    IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'fk_vehiculo_personal') THEN
        ALTER TABLE global.vehiculos ADD CONSTRAINT fk_vehiculo_personal FOREIGN KEY (id_personal) REFERENCES global.personal(id_personal) ON DELETE SET NULL;
    END IF;
END $$;

-- 4. TABLA PROVEEDORES (NUEVA O MEJORADA)
CREATE TABLE IF NOT EXISTS global.proveedores (
    id_proveedor SERIAL PRIMARY KEY,
    nombre VARCHAR(200) NOT NULL,
    nit VARCHAR(50),
    telefono VARCHAR(50),
    direccion TEXT,
    rubro VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 5. TABLA INGRESOS (SINCRONIZADA CON FRONTEND)
ALTER TABLE global.ingresos ADD COLUMN IF NOT EXISTS toneladas DECIMAL(15,2) DEFAULT 0;
ALTER TABLE global.ingresos ADD COLUMN IF NOT EXISTS kilometraje_conducido DECIMAL(15,2) DEFAULT 0;
ALTER TABLE global.ingresos ADD COLUMN IF NOT EXISTS conductor_asignado VARCHAR(255); -- Para respaldo string
ALTER TABLE global.ingresos ADD COLUMN IF NOT EXISTS id_personal INTEGER; -- Relación formal con conductor

DO $$ 
BEGIN 
    IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'fk_ingreso_vehiculo') THEN
        ALTER TABLE global.ingresos ADD CONSTRAINT fk_ingreso_vehiculo FOREIGN KEY (id_vehiculo) REFERENCES global.vehiculos(id_vehiculo) ON DELETE CASCADE;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'fk_ingreso_personal') THEN
        ALTER TABLE global.ingresos ADD CONSTRAINT fk_ingreso_personal FOREIGN KEY (id_personal) REFERENCES global.personal(id_personal) ON DELETE SET NULL;
    END IF;
END $$;

-- 6. TABLA GASTOS (NORMALIZADA Y SINCRONIZADA)
ALTER TABLE global.gastos ADD COLUMN IF NOT EXISTS tipo_pago VARCHAR(50) DEFAULT 'Efectivo';
ALTER TABLE global.gastos ADD COLUMN IF NOT EXISTS id_proveedor INTEGER;
ALTER TABLE global.gastos ADD COLUMN IF NOT EXISTS fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP;

DO $$ 
BEGIN 
    IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'fk_gasto_vehiculo') THEN
        ALTER TABLE global.gastos ADD CONSTRAINT fk_gasto_vehiculo FOREIGN KEY (id_vehiculo) REFERENCES global.vehiculos(id_vehiculo) ON DELETE CASCADE;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'fk_gasto_proveedor') THEN
        ALTER TABLE global.gastos ADD CONSTRAINT fk_gasto_proveedor FOREIGN KEY (id_proveedor) REFERENCES global.proveedores(id_proveedor) ON DELETE SET NULL;
    END IF;
END $$;

-- 7. TABLA TRAMOS (NORMALIZADA)
ALTER TABLE global.tramos ADD COLUMN IF NOT EXISTS diesel_promedio DECIMAL(10,2) DEFAULT 0;
ALTER TABLE global.tramos ADD COLUMN IF NOT EXISTS gas_promedio DECIMAL(10,2) DEFAULT 0;

-- 8. TABLA INVENTARIO (SINCRONIZADA)
ALTER TABLE global.inventario ADD COLUMN IF NOT EXISTS stock_minimo DECIMAL(15,2) DEFAULT 0;
ALTER TABLE global.inventario ADD COLUMN IF NOT EXISTS id_proveedor INTEGER;

-- Limpieza de IDs inválidos antes de aplicar FK
UPDATE global.inventario SET id_proveedor = NULL WHERE id_proveedor = 0;
UPDATE global.vehiculos SET id_personal = NULL WHERE id_personal = 0;
UPDATE global.gastos SET id_vehiculo = NULL WHERE id_vehiculo = 0;
UPDATE global.gastos SET id_proveedor = NULL WHERE id_proveedor = 0;

DO $$ 
BEGIN 
    IF NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE constraint_name = 'fk_inventario_proveedor') THEN
        ALTER TABLE global.inventario ADD CONSTRAINT fk_inventario_proveedor FOREIGN KEY (id_proveedor) REFERENCES global.proveedores(id_proveedor) ON DELETE SET NULL;
    END IF;
END $$;

-- 9. TABLA MOVIMIENTOS INVENTARIO (DETALLE)
CREATE TABLE IF NOT EXISTS global.movimientos_inventario (
    id_movimiento SERIAL PRIMARY KEY,
    id_inventario INTEGER NOT NULL,
    tipo_movimiento VARCHAR(20) NOT NULL, -- 'ENTRADA', 'SALIDA'
    cantidad DECIMAL(15,2) NOT NULL,
    costo_unitario DECIMAL(15,2),
    id_vehiculo INTEGER, -- Para consumos asociados a una unidad
    id_personal INTEGER, -- Quién retira o entrega
    motivo TEXT,
    fecha_movimiento DATE DEFAULT CURRENT_DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mov_inventario FOREIGN KEY (id_inventario) REFERENCES global.inventario(id_inventario) ON DELETE CASCADE,
    CONSTRAINT fk_mov_vehiculo FOREIGN KEY (id_vehiculo) REFERENCES global.vehiculos(id_vehiculo) ON DELETE SET NULL,
    CONSTRAINT fk_mov_personal FOREIGN KEY (id_personal) REFERENCES global.personal(id_personal) ON DELETE SET NULL
);
