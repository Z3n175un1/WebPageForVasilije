<?php
require 'config.php';
require 'config/conn.php';
$pdo = getConnection();
$tables = ['ingresos', 'gastos', 'personal', 'vehiculos', 'movimientos_inventario', 'inventario', 'tramos'];
foreach($tables as $t) {
    echo "Table $t:\n";
    $stmt = $pdo->query("SELECT column_name, data_type, is_nullable 
                         FROM information_schema.columns 
                         WHERE table_schema = 'global' AND table_name = '$t'
                         ORDER BY ordinal_position");
    while($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo " - {$r['column_name']} ({$r['data_type']}, nullable: {$r['is_nullable']})\n";
    }
    echo "\n";
}
