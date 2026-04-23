<?php
require 'config.php';
require 'config/conn.php';

try {
    $pdo = getConnection();
    $stmt = $pdo->query("SELECT id_ingreso, observaciones FROM global.ingresos WHERE observaciones IS NOT NULL AND observaciones LIKE '{%}'");
    
    $count = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data = json_decode($row['observaciones'], true);
        if ($data) {
            $toneladas = $data['toneladas'] ?? 0;
            $km = $data['kilometraje_conducido'] ?? 0;
            $conductor = $data['conductor_asignado'] ?? null;
            
            // Intentar buscar el id_personal si el nombre coincide
            $id_personal = null;
            if ($conductor) {
                // El conductor suele venir como "Nombre Apellido" (primeros nombres/apellidos)
                $parts = explode(' ', $conductor);
                if (count($parts) >= 2) {
                    $n1 = $parts[0];
                    $a1 = $parts[1];
                    $stmtP = $pdo->prepare("SELECT id_personal FROM global.personal WHERE nombres ILIKE :n AND apellidos ILIKE :a LIMIT 1");
                    $stmtP->execute([':n' => "$n1%", ':a' => "$a1%"]);
                    $id_personal = $stmtP->fetchColumn() ?: null;
                }
            }

            $update = $pdo->prepare("UPDATE global.ingresos SET toneladas = :t, kilometraje_conducido = :km, conductor_asignado = :cond, id_personal = :idp WHERE id_ingreso = :id");
            $update->execute([
                ':t' => $toneladas,
                ':km' => $km,
                ':cond' => $conductor,
                ':idp' => $id_personal,
                ':id' => $row['id_ingreso']
            ]);
            $count++;
        }
    }
    echo "Migración completada: $count registros actualizados.\n";
} catch (Exception $e) {
    echo "Error en migración: " . $e->getMessage() . "\n";
}
