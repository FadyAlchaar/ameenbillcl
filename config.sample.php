<?php
// config.sample.php
// ---------------------------------------------------------------
// Copy this file to config.php and fill in the real values.
// config.php is in .gitignore and must NEVER be committed.
// ---------------------------------------------------------------

// 'prod' on the production server, 'dev' locally.
define('APP_ENV', 'dev');

// Timezone of the SQL Server host. The SSE watermarks compare PHP-formatted
// timestamps against DATETIME columns written by Alameen, which are stored in
// the DB server's LOCAL time with no offset. If this does not match the SQL
// Server host, live updates will be skipped or replayed.
define('APP_TZ', 'Asia/Damascus');

if (APP_ENV === 'prod') {
    define('DB_SERVER', 'localhost');
    define('DB_NAME',   'AlbassaDB2026');
    define('DB_USER',   'CHANGE_ME');
    define('DB_PASS',   'CHANGE_ME');
} else {
    define('DB_SERVER', 'localhost\\SQLEXPRESS');
    define('DB_NAME',   'AlbassaDB2026');
    define('DB_USER',   'CHANGE_ME');
    define('DB_PASS',   'CHANGE_ME');
}

require_once __DIR__ . '/lib.php';
