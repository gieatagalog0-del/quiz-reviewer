<?php
/*
 * handlers.php - the actual work behind each /api/... address.
 *
 * Who can do what:
 *   Everyone logged in : view and take every shared quiz
 *   Student            : upload quizzes (if students_can_upload is true) and delete THEIR OWN quizzes
 *   Admin              : upload, and delete ANY quiz
 */

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/docx_parser.php';

const GMAIL_RE = '/^[a-z0-9._%+\-]{1,64}@gmail\.com$/';

// =====================================================================
// Accounts
// =====================================================================
function h_register()
{
    $b = json_body(4096);
    $name = trim(preg_replace('/\s+/u', ' ', str_of($b, 'name')));
    $email = strtolower(trim(str_of($b, 'email')));
    $pw = str_of($b, 'password');

    $nameLen = mb_strlen($name);
    if ($nameLen < 2 || $nameLen > 100) {
        throw new ApiException(400, 'Please enter your full name (2 to 100 characters).');
    }
    if (!preg_match(GMAIL_RE, $email)) {
        throw new ApiException(400, 'Please use a valid Gmail address (example: yourname@gmail.com).');
    }
    $pwLen = mb_strlen($pw);
    if ($pwLen < 8 || $pwLen > 100 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        throw new ApiException(400, 'Password must be 8 to 100 characters and include at least one letter and one number.');
    }

    $existing = q('SELECT id, email_verified FROM users WHERE email = ?', [$email])->fetch();
    if ($existing && (int) $existing['email_verified'] === 1) {
        throw new ApiException(409, 'An account with this email already exists. Please log in.');
    }
    $hash = hash_password($pw);
    if ($existing) { // registered earlier but never verified: let them try again
        $userId = (int) $existing['id'];
        q('UPDATE users SET full_name = ?, password_hash = ? WHERE id = ?', [$name, $hash, $userId]);
    } else {
        $role = ($email === strtolower(trim((string) cfg('admin_email', '')))) ? 'admin' : 'student';
        q('INSERT INTO users (full_name, email, password_hash, role) VALUES (?,?,?,?)', [$name, $email, $hash, $role]);
        $userId = (int) db()->lastInsertId();
    }
    send_otp($userId, $email);
    send_json(200, ['ok' => true, 'message' => 'We sent a 6-digit code to ' . $email . '.']);
}

/** Creates a new 6-digit code (valid 10 minutes) and emails it. At most one per minute. */
function send_otp($userId, $email)
{
    $code = make_otp();
    $recent = q('SELECT COUNT(*) FROM email_otps WHERE user_id = ? AND created_at > ?',
        [$userId, now_sql(-60)])->fetchColumn();
    if ((int) $recent > 0) {
        throw new ApiException(429, 'Please wait about a minute before asking for another code.');
    }
    q('DELETE FROM email_otps WHERE user_id = ?', [$userId]);
    q('INSERT INTO email_otps (user_id, code_hash, expires_at, created_at) VALUES (?,?,?,?)',
        [$userId, sha256_hex($userId . ':' . $code), now_sql(600), now_sql()]);

    $mailUser = trim((string) cfg('mail_user', ''));
    if ($mailUser === '') { // DEV MODE: no e-mail account configured, so write the code to a log file
        @file_put_contents(API_DIR . '/storage/dev-mail.log',
            '[' . date('Y-m-d H:i:s') . '] Verification code for ' . $email . ' is ' . $code . "\n", FILE_APPEND | LOCK_EX);
        return;
    }
    try {
        send_mail($email, 'Your College Quiz Reviewer verification code',
            "Your verification code is: $code\n\nIt expires in 10 minutes.\n"
            . "If you did not create an account, you can ignore this email.");
    } catch (Throwable $e) {
        error_log('Email failed: ' . $e->getMessage());
        q('DELETE FROM email_otps WHERE user_id = ?', [$userId]);
        throw new ApiException(502, 'We could not send the verification email. Please try again later or tell your administrator.');
    }
}

function h_resend()
{
    $email = strtolower(trim(str_of(json_body(2048), 'email')));
    $row = q('SELECT id, email_verified FROM users WHERE email = ?', [$email])->fetch();
    if ($row && (int) $row['email_verified'] === 0) {
        send_otp((int) $row['id'], $email);
    }
    send_json(200, ['ok' => true, 'message' => 'If this email is waiting for verification, a new code was sent.']);
}

function h_verify()
{
    $b = json_body(2048);
    $email = strtolower(trim(str_of($b, 'email')));
    $code = trim(str_of($b, 'code'));
    if (!preg_match('/^\d{6}$/', $code)) {
        throw new ApiException(400, 'Enter the 6-digit code from your email.');
    }
    $u = q('SELECT id, full_name, email, role, email_verified FROM users WHERE email = ?', [$email])->fetch();
    if (!$u) {
        throw new ApiException(400, 'No pending verification for this email. Please register first.');
    }
    if ((int) $u['email_verified'] === 1) {
        throw new ApiException(409, 'This email is already verified. Please log in.');
    }
    $uid = (int) $u['id'];
    $otp = q('SELECT id, code_hash, attempts, expires_at FROM email_otps WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$uid])->fetch();
    if (!$otp) {
        throw new ApiException(400, 'There is no active code. Please request a new one.');
    }
    if (strtotime($otp['expires_at']) <= time()) {
        throw new ApiException(400, 'That code has expired. Please request a new one.');
    }
    $attempts = (int) $otp['attempts'];
    if ($attempts >= 5) {
        throw new ApiException(429, 'Too many wrong attempts. Please request a new code.');
    }
    if (!hash_equals($otp['code_hash'], sha256_hex($uid . ':' . $code))) {
        q('UPDATE email_otps SET attempts = attempts + 1 WHERE id = ?', [$otp['id']]);
        $left = 4 - $attempts;
        throw new ApiException(400, 'Incorrect code. ' . ($left > 0 ? $left . ' attempt(s) left.' : 'Please request a new code.'));
    }
    q('UPDATE users SET email_verified = 1 WHERE id = ?', [$uid]);
    q('DELETE FROM email_otps WHERE user_id = ?', [$uid]);
    start_session($uid);
    send_json(200, ['ok' => true, 'user' => [
        'id' => $uid, 'name' => $u['full_name'], 'email' => $u['email'], 'role' => $u['role'],
    ]]);
}

function h_login()
{
    $b = json_body(4096);
    $email = strtolower(trim(str_of($b, 'email')));
    $pw = str_of($b, 'password');

    // 5 wrong passwords lock that e-mail for 10 minutes (stored in the login_fails table)
    $f = q('SELECT fail_count, first_at FROM login_fails WHERE email = ?', [$email])->fetch();
    if ($f && strtotime($f['first_at']) < time() - 600) {
        q('DELETE FROM login_fails WHERE email = ?', [$email]);
        $f = false;
    }
    if ($f && (int) $f['fail_count'] >= 5) {
        throw new ApiException(429, 'Too many failed attempts. Please wait a few minutes and try again.');
    }

    $u = q('SELECT id, full_name, email, role, password_hash, email_verified FROM users WHERE email = ?', [$email])->fetch();
    if (!$u || !verify_password($pw, $u['password_hash'])) {
        if ($f) {
            q('UPDATE login_fails SET fail_count = fail_count + 1 WHERE email = ?', [$email]);
        } else {
            q('INSERT INTO login_fails (email, fail_count, first_at) VALUES (?,1,?)', [$email, now_sql()]);
        }
        throw new ApiException(401, 'Incorrect email or password.');
    }
    if ((int) $u['email_verified'] !== 1) {
        throw new ApiException(403, 'Please verify your email first. We will send you a new code.', 'not_verified');
    }
    q('DELETE FROM login_fails WHERE email = ?', [$email]);
    start_session((int) $u['id']);
    send_json(200, ['ok' => true, 'user' => [
        'id' => (int) $u['id'], 'name' => $u['full_name'], 'email' => $u['email'], 'role' => $u['role'],
    ]]);
}

function h_logout()
{
    $token = cookie_token();
    if ($token !== null) {
        q('DELETE FROM sessions WHERE token_hash = ?', [sha256_hex($token)]);
    }
    setcookie('sid', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    send_json(200, ['ok' => true]);
}

function h_me()
{
    $u = current_user();
    send_json(200, ['user' => $u ? user_json($u) : null, 'canUpload' => $u !== null && can_upload($u)]);
}

// =====================================================================
// Shared quizzes
// =====================================================================
function h_parse_docx()
{
    $u = require_user();
    if (!can_upload($u)) {
        throw new ApiException(403, 'Your account is not allowed to upload quizzes.');
    }
    $fname = isset($_SERVER['HTTP_X_FILENAME']) ? rawurldecode($_SERVER['HTTP_X_FILENAME']) : '';
    if (!preg_match('/\.docx$/i', $fname)) {
        throw new ApiException(400, 'Please upload a Word file that ends in .docx.');
    }
    $data = read_body(5 * 1024 * 1024);
    $r = DocxQuizParser::parse($data);
    $title = trim(preg_replace('/[_\-]+/', ' ', preg_replace('/\.docx$/i', '', $fname)));
    if ($title === '') {
        $title = 'My quiz';
    }
    if (mb_strlen($title) > 80) {
        $title = mb_substr($title, 0, 80);
    }
    send_json(200, ['title' => $title, 'questions' => $r['questions'], 'warnings' => $r['warnings']]);
}

function h_save_quiz()
{
    $u = require_user();
    if (!can_upload($u)) {
        throw new ApiException(403, 'Your account is not allowed to upload quizzes.');
    }
    $b = json_body(3000000);
    $title = trim(str_of($b, 'title'));
    $tl = mb_strlen($title);
    if ($tl < 1 || $tl > 80) {
        throw new ApiException(400, 'The quiz title must be 1 to 80 characters.');
    }
    if (!isset($b['questions']) || !is_array($b['questions'])) {
        throw new ApiException(400, 'No questions were sent.');
    }
    $qs = array_values($b['questions']);
    if (count($qs) < 1 || count($qs) > 500) {
        throw new ApiException(400, 'A quiz needs 1 to 500 questions.');
    }

    $rows = [];
    $n = 0;
    foreach ($qs as $o) {
        $n++;
        $lab = 'Question ' . $n . ': ';
        if (!is_array($o)) {
            throw new ApiException(400, $lab . 'is not valid.');
        }
        $text = trim(str_of($o, 'q'));
        $len = mb_strlen($text);
        if ($len < 1 || $len > 2000) {
            throw new ApiException(400, $lab . 'the question text must be 1 to 2000 characters.');
        }
        if (!isset($o['choices']) || !is_array($o['choices']) || count($o['choices']) !== 4) {
            throw new ApiException(400, $lab . 'needs exactly 4 choices.');
        }
        $ch = [];
        $choices = array_values($o['choices']);
        for ($i = 0; $i < 4; $i++) {
            $c = is_string($choices[$i]) ? trim($choices[$i]) : '';
            if ($c === '' || mb_strlen($c) > 500) {
                throw new ApiException(400, $lab . 'each choice must be 1 to 500 characters.');
            }
            $ch[$i] = $c;
        }
        if (!isset($o['answer']) || !(is_int($o['answer']) || is_float($o['answer']))) {
            throw new ApiException(400, $lab . 'pick the correct answer.');
        }
        $ans = (int) $o['answer'];
        if ($ans < 0 || $ans > 3) {
            throw new ApiException(400, $lab . 'pick the correct answer.');
        }
        $expl = trim(str_of($o, 'explanation'));
        if ($expl === '') {
            $expl = 'No explanation was provided for this question.';
        }
        if (mb_strlen($expl) > 2000) {
            $expl = mb_substr($expl, 0, 2000);
        }
        $rows[] = [$n, $text, $ch[0], $ch[1], $ch[2], $ch[3], $ans, $expl];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO quizzes (title, uploaded_by, question_count) VALUES (?,?,?)', [$title, $u['id'], count($rows)]);
        $quizId = (int) $pdo->lastInsertId();
        $st = $pdo->prepare('INSERT INTO questions (quiz_id, sort_order, question_text, choice_a, choice_b, choice_c, choice_d, correct_index, explanation) '
            . 'VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($rows as $r) {
            $st->execute([$quizId, $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $r[7]]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    send_json(200, ['ok' => true, 'id' => $quizId]);
}

function h_list_quizzes()
{
    $me = require_user();
    $rows = q('SELECT q.id, q.title, q.question_count, q.created_at, q.uploaded_by, u.full_name '
        . 'FROM quizzes q LEFT JOIN users u ON u.id = q.uploaded_by ORDER BY q.created_at DESC, q.id DESC')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $mine = $r['uploaded_by'] !== null && (int) $r['uploaded_by'] === $me['id'];
        $out[] = [
            'id'        => (int) $r['id'],
            'title'     => $r['title'],
            'count'     => (int) $r['question_count'],
            'created'   => $r['created_at'],
            'by'        => $r['full_name'] === null ? '(deleted user)' : $r['full_name'],
            'canDelete' => $me['role'] === 'admin' || $mine,
        ];
    }
    send_json(200, $out);
}

function h_get_quiz($id)
{
    require_user();
    $quiz = q('SELECT title FROM quizzes WHERE id = ?', [$id])->fetch();
    if (!$quiz) {
        throw new ApiException(404, 'Quiz not found.');
    }
    $rows = q('SELECT question_text, choice_a, choice_b, choice_c, choice_d, correct_index, explanation '
        . 'FROM questions WHERE quiz_id = ? ORDER BY sort_order', [$id])->fetchAll();
    $qs = [];
    foreach ($rows as $r) {
        $qs[] = [
            'q'           => $r['question_text'],
            'choices'     => [$r['choice_a'], $r['choice_b'], $r['choice_c'], $r['choice_d']],
            'answer'      => (int) $r['correct_index'],
            'explanation' => $r['explanation'],
        ];
    }
    send_json(200, ['id' => (string) $id, 'title' => $quiz['title'], 'questions' => $qs]);
}

function h_delete_quiz($id)
{
    $me = require_user();
    $quiz = q('SELECT uploaded_by FROM quizzes WHERE id = ?', [$id])->fetch();
    if (!$quiz) {
        throw new ApiException(404, 'Quiz not found.');
    }
    $isOwner = $quiz['uploaded_by'] !== null && (int) $quiz['uploaded_by'] === $me['id'];
    if ($me['role'] !== 'admin' && !$isOwner) {
        throw new ApiException(403, 'You can only delete quizzes that you uploaded.');
    }
    q('DELETE FROM questions WHERE quiz_id = ?', [$id]); // also done by ON DELETE CASCADE; explicit to be safe
    q('DELETE FROM quizzes WHERE id = ?', [$id]);
    send_json(200, ['ok' => true]);
}

// =====================================================================
// Anonymous statistics (subject + percentage only; no personal data)
// =====================================================================
function subject_stats($subject)
{
    $r = q('SELECT COUNT(*) AS c, AVG(percentage) AS a FROM attempts WHERE subject = ?', [$subject])->fetch();
    $attempts = (int) $r['c'];
    return ['attempts' => $attempts, 'average' => $attempts === 0 ? 0 : round((float) $r['a'], 1)];
}

function h_attempts($method)
{
    if ($method === 'POST') {
        $subject = isset($_POST['subject']) && is_string($_POST['subject']) ? $_POST['subject'] : '';
        $pct = isset($_POST['percentage']) && is_string($_POST['percentage']) ? $_POST['percentage'] : '';
        if (!is_numeric($pct)) {
            send_json(400, ['error' => 'percentage must be a number']);
        }
        $pct = (float) $pct;
        if (!in_array($subject, SUBJECTS, true) || $pct < 0 || $pct > 100) {
            send_json(400, ['error' => 'invalid subject or percentage']);
        }
        q('INSERT INTO attempts (subject, percentage) VALUES (?,?)', [$subject, $pct]);
        send_json(200, subject_stats($subject));
    } elseif ($method === 'GET') {
        $all = [];
        foreach (SUBJECTS as $s) {
            $all[$s] = subject_stats($s);
        }
        send_json(200, $all);
    }
    send_json(405, ['error' => 'method not allowed']);
}
