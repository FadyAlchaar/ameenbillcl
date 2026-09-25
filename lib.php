<?php
// lib.php — shared bootstrap: error policy, DB connection, timestamp helpers.
// Included from config.php, so every entry point gets it automatically.

if (!defined('APP_ENV')) {
    http_response_code(500);
    exit('Configuration missing. Copy config.sample.php to config.php.');
}

// ─── Error policy ────────────────────────────────────────────────
// Never render PHP errors to the browser. They leak schema, paths and
// connection strings. Everything goes to the web server's error log.
error_reporting(E_ALL);
ini_set('display_errors', APP_ENV === 'prod' ? '0' : '0');
ini_set('log_errors', '1');

// Fail loudly if the SQL Server PHP extensions are missing — otherwise
// the symptom is a confusing 500 on the first DB call.
if (!extension_loaded('pdo_sqlsrv') || !defined('PDO::SQLSRV_ATTR_ENCODING')) {
    http_response_code(500);
    error_log('[AmeenBill] pdo_sqlsrv extension is missing or incomplete. '
            . 'Check php.ini and copy php_sqlsrv_*.dll / php_pdo_sqlsrv_*.dll into ext/.');
    exit('Server misconfigured. Contact the administrator.');
}

// ─── Timezone ────────────────────────────────────────────────────
// Alameen writes CreateDate / LastUpdateDate as DATETIME in the DB server's
// local time, with no offset stored. Every conversion between those values
// and the ISO-8601 strings the browser sees goes through this zone.
date_default_timezone_set(defined('APP_TZ') ? APP_TZ : 'Asia/Damascus');

// ─── Constants ───────────────────────────────────────────────────
// Alameen writes this instead of NULL for "never updated".
const SQL_SENTINEL = '1980-01-01 00:00:00.000';
const SQL_FMT      = 'Y-m-d H:i:s.v';

// ISO-8601 WITH milliseconds. PHP's 'c' format drops them, which silently
// defeated all the ms-safe watermark handling in sse_bills.php: a bill
// created at .500 came back as .000, so it fell inside the watermark again
// and was re-delivered on every probe. Date.parse() reads this fine.
const ISO_FMT = 'Y-m-d\TH:i:s.vP';

// ─── DB ──────────────────────────────────────────────────────────
function getDBConnection() {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = "sqlsrv:Server=" . DB_SERVER . ";Database=" . DB_NAME
         . ";Driver=ODBC Driver 18 for SQL Server"
         . ";TrustServerCertificate=yes;Encrypt=no";

    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::SQLSRV_ATTR_ENCODING    => PDO::SQLSRV_ENCODING_UTF8,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
        return $pdo;
    } catch (PDOException $e) {
        error_log("DB Connection Error: " . $e->getMessage());
        throw new RuntimeException("A database connection error occurred.");
    }
}

/**
 * Emit a JSON error to the client without leaking internals.
 * The real exception goes to the error log.
 */
function jsonFail(Throwable $e, $status = 500, $publicMessage = 'A server error occurred.') {
    error_log('[AmeenBill] ' . get_class($e) . ': ' . $e->getMessage()
              . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code($status);
    }
    echo json_encode(['error' => $publicMessage], JSON_UNESCAPED_UNICODE);
}

// ─── Timestamp helpers ───────────────────────────────────────────

/**
 * Convert an ISO-8601 string sent by the browser into the SQL-comparable
 * wall-clock string used by the DB.
 *
 * This is the fix for the watermark timezone bug: the browser sends UTC
 * ("...Z") while the DB stores local wall time. Without setTimezone() the
 * comparison is off by the UTC offset, which either replays every row or
 * skips every row depending on the sign.
 */
function sqlWatermark($iso, $fallback) {
    if ($iso === null || $iso === '') {
        return $fallback;
    }
    try {
        return (new DateTime($iso))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format(SQL_FMT);
    } catch (Exception $e) {
        return $fallback;
    }
}

/**
 * Convert a raw DB DATETIME string into the SQL-comparable wall-clock form,
 * preserving milliseconds. Returns null for NULL / empty / sentinel values.
 */
function sqlStamp($raw, $treatSentinelAsNull = false) {
    if ($raw === null || $raw === '') {
        return null;
    }
    try {
        $s = ($raw instanceof DateTimeInterface)
            ? $raw->format(SQL_FMT)
            : (new DateTime($raw))->format(SQL_FMT);
    } catch (Exception $e) {
        return null;
    }
    // Alameen occasionally holds junk in these columns ('0000-00-00' and the
    // like), which DateTime happily turns into a negative year. Anything
    // before 1900 is not a real bill timestamp.
    if (strcmp($s, '1900-01-01 00:00:00.000') < 0) {
        return null;
    }
    if ($treatSentinelAsNull && strcmp($s, SQL_SENTINEL) <= 0) {
        return null;
    }
    return $s;
}

/**
 * Convert a raw DB DATETIME into an ISO-8601 string (with the server's
 * offset) for the browser. Returns null instead of throwing on NULL /
 * malformed values — a single bad row must not break the whole response.
 */
function isoStamp($raw, $treatSentinelAsNull = false) {
    $s = sqlStamp($raw, $treatSentinelAsNull);
    if ($s === null) {
        return null;
    }
    try {
        return (new DateTime($s))->format(ISO_FMT);
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Normalise the timestamp columns of a bill row in place, safely.
 */
function normalizeBillDates(array &$bill) {
    $bill['Date']           = isoStamp($bill['Date'] ?? null);
    $bill['CreateDate']     = isoStamp($bill['CreateDate'] ?? null);
    $bill['LastUpdateDate'] = isoStamp($bill['LastUpdateDate'] ?? null, true);
}
