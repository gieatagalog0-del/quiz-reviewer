<?php
/*
 * mailer.php - sends a plain-text e-mail through Gmail SMTP (SSL, port 465). No libraries needed.
 * Throws RuntimeException when the mail could not be sent.
 */

function smtp_expect($fp, $code)
{
    $line = '';
    do {
        $line = fgets($fp, 1024);
        if ($line === false) {
            throw new RuntimeException('SMTP connection closed');
        }
    } while (strlen($line) > 3 && $line[3] === '-');
    if (strpos($line, (string) $code) !== 0) {
        throw new RuntimeException('SMTP error: ' . trim($line));
    }
}

function smtp_cmd($fp, $line, $code)
{
    fwrite($fp, $line . "\r\n");
    smtp_expect($fp, $code);
}

function send_mail($to, $subject, $body)
{
    $host = cfg('mail_host', 'smtp.gmail.com');
    $port = (int) cfg('mail_port', 465);
    $user = (string) cfg('mail_user', '');
    $pass = str_replace(' ', '', (string) cfg('mail_app_password', ''));
    if (preg_match('/[\r\n<>]/', $to) || preg_match('/[\r\n<>]/', $user)) {
        throw new RuntimeException('bad address');
    }
    $verify = (bool) cfg('mail_verify_ssl', true);
    $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify]]);
    $fp = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new RuntimeException("Cannot connect to $host:$port ($errstr)");
    }
    stream_set_timeout($fp, 15);
    try {
        smtp_expect($fp, 220);
        smtp_cmd($fp, 'EHLO quiz-reviewer', 250);
        smtp_cmd($fp, 'AUTH LOGIN', 334);
        smtp_cmd($fp, base64_encode($user), 334);
        smtp_cmd($fp, base64_encode($pass), 235);
        smtp_cmd($fp, 'MAIL FROM:<' . $user . '>', 250);
        smtp_cmd($fp, 'RCPT TO:<' . $to . '>', 250);
        smtp_cmd($fp, 'DATA', 354);

        $msg  = 'From: College Quiz Reviewer <' . $user . ">\r\n";
        $msg .= 'To: <' . $to . ">\r\n";
        $msg .= 'Subject: ' . $subject . "\r\n";
        $msg .= 'Date: ' . date('r') . "\r\n";
        $msg .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if ($line !== '' && $line[0] === '.') {
                $line = '.' . $line; // dot-stuffing
            }
            $msg .= $line . "\r\n";
        }
        $msg .= ".\r\n";
        fwrite($fp, $msg);
        smtp_expect($fp, 250);
        fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}
