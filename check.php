<?php
echo "PHP: " . PHP_VERSION . " (" . (PHP_ZTS ? 'TS' : 'NTS') . ")<br>";
echo "sqlsrv loaded: " . (extension_loaded('sqlsrv') ? 'YES' : 'NO') . "<br>";
echo "pdo_sqlsrv loaded: " . (extension_loaded('pdo_sqlsrv') ? 'YES' : 'NO') . "<br>";
echo "sqlsrv version: " . (phpversion('sqlsrv') ?: 'n/a') . "<br>";
echo "pdo_sqlsrv version: " . (phpversion('pdo_sqlsrv') ?: 'n/a') . "<br>";
echo "SQLSRV_ATTR_ENCODING defined: " . (defined('PDO::SQLSRV_ATTR_ENCODING') ? 'YES' : 'NO') . "<br>";