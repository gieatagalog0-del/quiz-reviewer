<?php
/*
 * bootstrap.php - settings, database connection (PDO), JSON helpers, passwords, sessions.
 * Included by api/index.php and api/test-db.php. Never opened directly by the browser.
 */

define('API_DIR', dirname(__DIR__));

ini_set('display_errors', '0');          // never show PHP errors to visitors
ini_set('log_errors', '1');
ini_set('error_log', API_DIR . '/storage/error.log');
error_reporting(E_ALL);

$GLOBALS['CQR_CFG'] = require API_DIR . '/config.php';
date_default_timezone_set(cfg('timezone', 'UTC'));

function cfg($key, $default = null)
{
    $c = $GLOBALS['CQR_CFG'];
    return array_key_exists($key, $c) ? $c[$key] : $default;
}

const SUBJECTS = ['application-development', 'networking', 'oop', 'automata-theory', 'internet-web-technologies'];

// ---------------------------------------------------------------------
// Errors that are shown to the user as a JSON message
// ---------------------------------------------------------------------
class ApiException extends Exception
{
    public $status;
    public $errCode;

    public function __construct($status, $message, $errCode = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errCode = $errCode;
    }
}

// ---------------------------------------------------------------------
// Database (PDO + MySQL/MariaDB). One connection per request.
// ---------------------------------------------------------------------
function db()
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = cfg('db_dsn'); // advanced/testing override; normally not set
        if (!$dsn) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                cfg('db_host', '127.0.0.1'), (int) cfg('db_port', 3306), cfg('db_name', 'quiz_reviewer'));
        }
        $pdo = new PDO($dsn, cfg('db_user'), cfg('db_password'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        if (cfg('db_init')) {
            $pdo->exec(cfg('db_init'));
        }
    }
    return $pdo;
}

/** Run a prepared statement and return it. Always use ? placeholders - never put user text in the SQL. */
function q($sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function now_sql($offsetSeconds = 0)
{
    return date('Y-m-d H:i:s', time() + $offsetSeconds);
}

// ---------------------------------------------------------------------
// Responses and request helpers
// ---------------------------------------------------------------------
function send_json($status, $body)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function read_body($max)
{
    $data = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($data === false) {
        $data = '';
    }
    if (strlen($data) > $max) {
        throw new ApiException(413, 'The upload is too large.');
    }
    return $data;
}

function json_body($max)
{
    $o = json_decode(read_body($max), true);
    if (!is_array($o)) {
        throw new ApiException(400, 'Invalid request.');
    }
    return $o;
}

function str_of($arr, $key)
{
    return (isset($arr[$key]) && is_string($arr[$key])) ? $arr[$key] : '';
}

// ---------------------------------------------------------------------
// Security: PBKDF2 password hashes (same format the old Java server used, so old accounts still work),
// SHA-256 for tokens/codes, random tokens, 6-digit codes.
// ---------------------------------------------------------------------
function hash_password($pw)
{
    $iter = 120000;
    $salt = random_bytes(16);
    $h = hash_pbkdf2('sha256', $pw, $salt, $iter, 32, true);
    return $iter . '$' . base64_encode($salt) . '$' . base64_encode($h);
}

function verify_password($pw, $stored)
{
    $p = explode('$', (string) $stored);
    if (count($p) !== 3 || !ctype_digit($p[0]) || (int) $p[0] < 1000 || (int) $p[0] > 5000000) {
        return false;
    }
    $salt = base64_decode($p[1], true);
    $expected = base64_decode($p[2], true);
    if ($salt === false || $expected === false || $expected === '') {
        return false;
    }
    return hash_equals($expected, hash_pbkdf2('sha256', $pw, $salt, (int) $p[0], strlen($expected), true));
}

function sha256_hex($s)
{
    return hash('sha256', $s);
}

function random_token()
{
    return bin2hex(random_bytes(32)); // 64 hex characters
}

function make_otp()
{
    return sprintf('%06d', random_int(0, 999999));
}

// ---------------------------------------------------------------------
// Login sessions: the cookie "sid" holds a random token; only its hash is stored in MySQL.
// ---------------------------------------------------------------------
function start_session($userId)
{
    q('DELETE FROM sessions WHERE expires_at < ?', [now_sql()]);
    $token = random_token();
    q('INSERT INTO sessions (token_hash, user_id, expires_at) VALUES (?,?,?)',
        [sha256_hex($token), $userId, now_sql(7 * 86400)]);
    setcookie('sid', $token, [
        'expires'  => time() + 7 * 86400,
        'path'     => '/',
        'secure'   => (bool) cfg('cookie_secure', false),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function cookie_token()
{
    $t = isset($_COOKIE['sid']) ? $_COOKIE['sid'] : '';
    return (is_string($t) && preg_match('/^[0-9a-f]{64}$/', $t)) ? $t : null;
}

/** The logged-in, e-mail-verified user, or null. */
function current_user()
{
    $token = cookie_token();
    if ($token === null) {
        return null;
    }
    $row = q('SELECT u.id, u.full_name, u.email, u.role FROM sessions s JOIN users u ON u.id = s.user_id '
        . 'WHERE s.token_hash = ? AND s.expires_at > ? AND u.email_verified = 1',
        [sha256_hex($token), now_sql()])->fetch();
    if (!$row) {
        return null;
    }
    return ['id' => (int) $row['id'], 'name' => $row['full_name'], 'email' => $row['email'], 'role' => $row['role']];
}

function require_user()
{
    $u = current_user();
    if ($u === null) {
        throw new ApiException(401, 'Please log in first.', 'not_logged_in');
    }
    return $u;
}

function can_upload($u)
{
    return $u['role'] === 'admin' || (bool) cfg('students_can_upload', true);
}

function user_json($u)
{
    return ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']];
}
