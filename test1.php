<?php
// dbtest2.php — verbose connection test, bypasses lib.php's error hiding
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain; charset=utf-8');

// Read the values WITHOUT going through lib.php
$server = 'localhost\\SQLEXPRESS';
$db     = 'AbouMahmoud2026';
$user   = 'alameenbill_reader';
$pass   = 'P@ssw0rd@2026';

echo "Server:   $server\n";
echo "Database: $db\n";
echo "User:     $user\n\n";

$dsn = "sqlsrv:Server=$server;Database=$db;Driver=ODBC Driver 18 for SQL Server;TrustServerCertificate=yes;Encrypt=no";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $row = $pdo->query("SELECT DB_NAME() AS db, SUSER_SNAME() AS login")->fetch();
    echo "CONNECTED\n";
    echo "  db:    " . $row['db'] . "\n";
    echo "  login: " . $row['login'] . "\n";
} catch (PDOException $e) {
    echo "FAILED\n";
    echo "  message: " . $e->getMessage() . "\n";
    echo "  code:    " . $e->getCode() . "\n";
}