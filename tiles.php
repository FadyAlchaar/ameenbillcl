<?php
// tiles.php — which tiles appear on the landing page for THIS install.
// This is the DEFAULT every fresh browser sees. Individual users can
// later override it in their own browser via the ⚙️ settings button.
//
// Set a value to false to hide that tile for this installation.
// To add a new tile, create a new entry — the key must be unique and
// the icon must exist in your Tabler webfont.

return [
    'dashboard'          => true,
    'stats'              => true,
    'products'           => true,
    'inventory'          => true,
    'movements'          => true,
    'serial-movements'   => true,
    'customers'          => true,
    'salesmen'           => true,
    'accounts'           => true,
    'bills'              => true,
    'cost-centers'       => true,
    'customer-statement' => true,
    'exchange-rates'     => true,
];