# ==========================================
# ETAPA 1: Construcción del Frontend (Angular)
# ==========================================
FROM node:18-alpine AS frontend-builder

WORKDIR /app

# Copiar archivos de dependencias
COPY transporte-frontend/package*.json ./

# Instalar dependencias
RUN npm install

# Copiar el código fuente del frontend
COPY transporte-frontend/ ./

# Construir la aplicación para producción
RUN npm run build -- --configuration production --output-path=dist

# ==========================================
# ETAPA 2: Entorno de Ejecución (PHP + Apache)
# ==========================================
FROM php:8.2-apache

# Instalar dependencias del sistema y extensiones de PHP necesarias (PostgreSQL)
RUN apt-get update && apt-get install -y \
    libpq-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Habilitar módulos de Apache necesarios
RUN a2enmod rewrite headers

# Configurar el directorio de trabajo
WORKDIR /var/www/html

# Copiar el código fuente del Backend (PHP)
# Excluimos lo innecesario mediante .dockerignore
COPY . .

# Copiar los archivos compilados del Frontend desde la Etapa 1
# Los colocamos en la raíz para que Apache los sirva como estáticos
COPY --from=frontend-builder /app/dist/ /var/www/html/

# Ajustar permisos para el servidor web
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Exponer el puerto 80
EXPOSE 80

# El comando por defecto de php:apache ya inicia el servidor
