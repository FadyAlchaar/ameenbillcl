<?php
// tools/generate_hash.php — CLI ONLY.
//
// The old instructions said to open a generate_hash.php in the browser and
// then remember to delete it. A web-reachable password tool that people are
// expected to clean up by hand is a trap, so this one refuses to run over
// HTTP and lives outside the document root's normal use.
//
//   Usage:  php tools/generate_hash.php 'the-new-password'
//
// Paste the resulting line into users.php.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script may only be run from the command line.');
}

$password = $argv[1] ?? null;
if ($password === null || $password === '') {
    fwrite(STDERR, "Usage: php tools/generate_hash.php 'password'\n");
    exit(1);
}
if (strlen($password) < 10) {
    fwrite(STDERR, "Refusing: use at least 10 characters.\n");
    exit(1);
}

echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;
