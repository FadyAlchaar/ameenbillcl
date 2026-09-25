<?php
// api/app-config.php — configuration served to the Android WebView shell.
// Public by design: the shell reads this BEFORE the user logs in, so it must
// not contain anything sensitive.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

echo json_encode([
    "success" => true,

    "application" => [
        "name"    => "Ameen Bill",
        "company" => "LIO",
        "version" => "4.2.1",

        // Was "/dashboard_full.php", which no longer exists — the file was
        // renamed to index.php, so the shell 404'd on launch.
        "start_page" => "/index.php",
    ],

    "branding" => [
        "logo"          => "/branding/logo.png",
        "animation"     => "/branding/splash.json",
        "primary_color" => "#1565C0",
        "accent_color"  => "#00BCD4",
    ],

    "shell" => [
        "minimum_version"     => "1.0.0",
        "recommended_version" => "1.0.0",
        "latest_version"      => "1.0.0",
        "apk"                 => "/download/WebViewShell.apk",
        "force_update"        => false,
    ],

    "server" => [
        "maintenance" => false,
        "message"     => "Welcome",
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
