# Crear estructura de documentación
mkdir -p docs/{php-structure,angular-plan,api-specs}

# Listar todos los archivos PHP
find . -name "*.php" -type f > docs/php-structure/files-list.txt

# Documentar las rutas y endpoints
php -r "
\$files = glob('public/*.php');
foreach(\$files as \$file) {
    echo \"File: \$file\n\";
    \$content = file_get_contents(\$file);
    preg_match_all('/\\?(?:GET|POST|REQUEST).*?\$/', \$content, \$matches);
    print_r(\$matches);
}
" > docs/php-structure/routes.txt
