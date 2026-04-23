<?php
require 'config.php';
require 'config/conn.php';
$pdo = getConnection();
$stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'proveedores' AND table_schema = 'global'");
while ($c = $stmt->fetchColumn()) echo $c . "\n";
