<?php
require 'config.php';
require 'config/conn.php';
try {
    $pdo = getConnection();
    $sql = file_get_contents('database/normalization.sql');
    $pdo->exec($sql);
    echo "Base de datos normalizada exitosamente.\n";
} catch (Exception $e) {
    echo "Error al normalizar: " . $e->getMessage() . "\n";
}
