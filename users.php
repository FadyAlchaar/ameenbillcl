<?php
// users.php - local login credentials for the dashboard.
// This has NOTHING to do with the SQL Server database — it's just the access
// gate for the dashboard page itself.
//
// To add or change a user, generate a hash from the COMMAND LINE:
//
//     php tools/generate_hash.php 'the-new-password'
//
// and paste the result below. (The old instructions pointed at a
// browser-accessible generate_hash.php that you had to remember to delete
// afterwards — tools/generate_hash.php refuses to run over HTTP instead.)

$DASHBOARD_USERS = [
    // Default 'admin' account. CHANGE THIS before deploying: the hash that
    // shipped in the repo was public, so its password must be considered known.
    'admin' => '$2y$10$qNhdfdU1D5DPzYYdKGycOOh0xAJgXiHtPrg5kew.MksQ1/aPDP/xC',
];
