<?php
require 'config.php';
require 'config/conn.php';
try {
    $pdo = getConnection();
    $stmt = $pdo->query("SELECT DISTINCT proveedor FROM global.gastos WHERE proveedor IS NOT NULL AND proveedor != ''");
    $providers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($providers as $name) {
        // Insert into providers if not exists
        $check = $pdo->prepare("SELECT id_proveedor FROM global.proveedores WHERE nombre_proveedor = :n");
        $check->execute([':n' => $name]);
        $id = $check->fetchColumn();
        
        if (!$id) {
            $ins = $pdo->prepare("INSERT INTO global.proveedores (nombre_proveedor) VALUES (:n) RETURNING id_proveedor");
            $ins->execute([':n' => $name]);
            $id = $ins->fetchColumn();
            echo "Proveedor creado: $name (ID: $id)\n";
        }
        
        // Update gastos
        $upd = $pdo->prepare("UPDATE global.gastos SET id_proveedor = :id WHERE proveedor = :n");
        $upd->execute([':id' => $id, ':n' => $name]);
    }
    echo "Migración de proveedores en Gastos completada.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
