<?php
/*
 * test-db.php - open http://localhost/college-quiz-reviewer/api/test-db.php
 * Shows whether PHP can reach MySQL and whether everything the app needs is in place.
 * Only works from your own computer. Delete this file before putting the site online.
 */
require __DIR__ . '/inc/bootstrap.php';

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('This page only works from the computer that runs the server.');
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$checks = [];
function add(&$checks, $ok, $label, $hint = '')
{
    $checks[] = [$ok, $label, $hint];
}

add($checks, version_compare(PHP_VERSION, '7.4.0', '>='), 'PHP version ' . PHP_VERSION, 'Use XAMPP with PHP 7.4 or newer.');
add($checks, extension_loaded('pdo_mysql'), 'PHP extension pdo_mysql', 'In XAMPP: php.ini, remove the ; before extension=pdo_mysql, then restart Apache.');
add($checks, extension_loaded('zip'), 'PHP extension zip (reads .docx files)', 'In XAMPP: php.ini, remove the ; before extension=zip, then restart Apache.');
add($checks, extension_loaded('dom'), 'PHP extension dom (reads .docx files)', 'Enable extension dom in php.ini.');
add($checks, extension_loaded('mbstring'), 'PHP extension mbstring', 'In XAMPP: php.ini, remove the ; before extension=mbstring.');
add($checks, extension_loaded('openssl'), 'PHP extension openssl (needed to send Gmail)', 'In XAMPP: php.ini, remove the ; before extension=openssl.');
add($checks, is_writable(API_DIR . '/storage'), 'Folder api/storage is writable', 'Give the web server write permission to api/storage.');
add($checks, cfg('db_password') !== 'ChangeThisPassword123!', 'Database password changed from the default', 'Change db_password in api/config.php (and the password in sql/schema.sql).');
add($checks, strpos((string) cfg('admin_email'), 'your.admin') === false, 'admin_email is set', 'Put your own Gmail in api/config.php (admin_email).');

$connected = false;
try {
    $v = db()->query('SELECT VERSION()')->fetchColumn();
    $connected = true;
    add($checks, true, 'Connected to the database as "' . cfg('db_user') . '" (server ' . $v . ')');
} catch (Throwable $e) {
    add($checks, false, 'Could not connect to the database', 'Is MySQL started in XAMPP? Are db_user / db_password in api/config.php correct? (' . $e->getMessage() . ')');
}

if ($connected) {
    foreach (['users', 'email_otps', 'sessions', 'quizzes', 'questions', 'login_fails', 'attempts'] as $t) {
        try {
            $n = db()->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn();
            add($checks, true, 'Table ' . $t . ' found (' . (int) $n . ' row(s))');
        } catch (Throwable $e) {
            $hint = in_array($t, ['login_fails', 'attempts'], true)
                ? 'Run sql/upgrade.sql in phpMyAdmin (SQL tab).'
                : 'Import sql/schema.sql in phpMyAdmin (SQL tab).';
            add($checks, false, 'Table ' . $t . ' is missing', $hint);
        }
    }
}
$allOk = true;
foreach ($checks as $c) {
    $allOk = $allOk && $c[0];
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Database check</title>
<style>
body{font-family:system-ui,Segoe UI,Arial,sans-serif;max-width:760px;margin:2rem auto;padding:0 1rem;color:#1b2a41}
li{margin:.45rem 0;list-style:none}.ok{color:#14803c}.bad{color:#b42318}.hint{display:block;margin-left:1.6rem;color:#555;font-size:.9rem}
.banner{padding:.8rem 1rem;border-radius:8px;font-weight:600}.banner.ok{background:#e7f6ec}.banner.bad{background:#fdecea}
</style></head><body>
<h1>Database check</h1>
<p class="banner <?= $allOk ? 'ok' : 'bad' ?>"><?= $allOk ? 'Everything looks good. You can delete api/test-db.php now.' : 'Something needs attention. Fix the red items below, then refresh this page.' ?></p>
<ul>
<?php foreach ($checks as $c): ?>
  <li class="<?= $c[0] ? 'ok' : 'bad' ?>"><?= $c[0] ? '&#10004;' : '&#10008;' ?> <?= htmlspecialchars($c[1]) ?>
  <?php if (!$c[0] && $c[2]): ?><span class="hint"><?= htmlspecialchars($c[2]) ?></span><?php endif; ?></li>
<?php endforeach; ?>
</ul>
</body></html>
