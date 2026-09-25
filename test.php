
<?php
require_once 'config.php';
header('Content-Type: text/plain; charset=utf-8');

echo "Trying: " . DB_SERVER . " / " . DB_NAME . " as " . DB_USER . "\n\n";
try {
    $pdo = getDBConnection();
    $row = $pdo->query("SELECT DB_NAME() AS db, SUSER_SNAME() AS login")->fetch();
    echo "Connected!\n";
    echo "  Database: " . $row['db'] . "\n";
    echo "  Login:    " . $row['login'] . "\n";
    $n = $pdo->query("SELECT COUNT(*) FROM bu000")->fetchColumn();
    echo "  bu000 rows: $n\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}