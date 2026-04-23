# Sistema de Gestión de Transporte

Sistema integral para la administración logística, operativa y financiera de transporte de carga y pasajeros.

---

## Términos de Uso y Propiedad Intelectual
Este software es de **uso exclusivo y privado para la empresa**. Queda terminantemente **prohibida la reproducción total o parcial, réplica, distribución o uso no autorizado** de este sistema por parte de terceros.

El presente código y su arquitectura están protegidos bajo la **Ley N° 1322 de Derechos de Autor del Estado Plurinacional de Bolivia**. Cualquier infracción, uso indebido o filtración de código será procesada conforme a las leyes civiles y penales vigentes en territorio boliviano.

---

## Tecnologías Utilizadas
El sistema emplea un stack moderno y escalable:
- **Backend**: PHP 8.2 con arquitectura relacional PostgreSQL.
- **Frontend**: Angular 15 (Bento Design System & Component-based).
- **Base de Datos**: PostgreSQL 15 (Esquema normalizado).
- **Contenedores**: Docker & Docker Compose para despliegue simplificado.

---

## Instalación y Despliegue

### 1. Requisitos Previos
- [Docker](https://www.docker.com/) y [Docker Compose](https://docs.docker.com/compose/) instalados.

### 2. Configuración del Entorno
Crea tu archivo de variables de entorno basado en la plantilla:
```bash
cp .env.example .env
```
*Asegúrate de editar el archivo `.env` con las credenciales de base de datos y URLs correspondientes.*

### 3. Iniciar el Sistema (Modo Producción)
Ejecuta el siguiente comando para construir y levantar los contenedores:
```bash
docker-compose up -d --build
```
El sistema estará accesible en: `http://localhost:8080`

---

## 🏗️ Estructura del Repositorio
- `api/`: Lógica central y endpoints del sistema.
- `auth/`: Control de acceso, sesiones y logs de seguridad.
- `config/`: Configuración de conexión y cargador de entorno.
- `database/`: Scripts de normalización relacional (`normalization.sql`).
- `transporte-frontend/`: Código fuente de la interfaz de usuario (Angular).
- `public/`: Archivos estáticos y entrada web.

---

## Seguridad
- **Backend Normalizado**: Integridad referencial mediante Foreign Keys.
- **Auditoría**: Logs de seguridad activos para rastreo de operaciones sensibles.
- **Dockerizado**: Entorno aislado y seguro para el despliegue.

---

**© 2026 Desarrollado para la Gestión de Transporte para la empresa de transporte de Vasilije.**
*Prohibida la comercialización o duplicación sin autorización expresa.*
