<?php
// StyleHub Database Migration & Seeder Script

$host = '127.0.0.1';
$port = '3306';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true
    ]);

    echo "[1/3] Connected to MySQL successfully.\n";

    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("schema.sql not found at {$schemaFile}");
    }
    echo "[2/3] Importing schema.sql...\n";
    $schemaSql = file_get_contents($schemaFile);
    $pdo->exec($schemaSql);
    echo "       Schema imported successfully.\n";

    $sampleFile = __DIR__ . '/sample-data.sql';
    if (!file_exists($sampleFile)) {
        throw new Exception("sample-data.sql not found at {$sampleFile}");
    }
    echo "[3/3] Importing sample-data.sql...\n";
    $sampleSql = file_get_contents($sampleFile);
    $pdo->exec($sampleSql);
    echo "       Sample data imported successfully.\n\n";

    // Summary
    $tables = $pdo->query("SHOW TABLES FROM stylehub_db")->fetchAll(PDO::FETCH_COLUMN);
    echo "Summary of stylehub_db (" . count($tables) . " tables created):\n";
    foreach ($tables as $t) {
        $count = $pdo->query("SELECT COUNT(*) FROM stylehub_db.`$t`")->fetchColumn();
        printf(" - %-25s: %d rows\n", $t, $count);
    }

    echo "\nDatabase migration completed without errors.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

