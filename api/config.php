<?php
/*
 * College Quiz Reviewer - settings (PHP + MySQL/MariaDB).
 * Edit the values below, save, and you are done. No compiling is needed for PHP.
 * NEVER share this file or upload it to GitHub: it contains passwords.
 */
return [
    // ---- Database (XAMPP / phpMyAdmin) ----
    'db_host'     => '127.0.0.1',
    'db_port'     => 3306,
    'db_name'     => 'quiz_reviewer',
    'db_user'     => 'root',
    'db_password' => '',   // must be the same password you put in sql/schema.sql

    // ---- Admin ----
    // The Gmail address that becomes ADMIN when it registers and verifies its code. Use your own.
    'admin_email' => 'chanversoza324@gmail.com',

    // ---- Who can upload? ----
    // true  = students and admins can upload quizzes (students delete only their own)
    // false = only the admin can upload
    'students_can_upload' => true,

    // ---- Gmail sender for the verification (OTP) emails ----
    // mail_user = the Gmail that SENDS the codes.
    // mail_app_password = a 16-character Google "App password" (NOT your normal password). See SETUP.md.
    // Leave mail_user EMPTY to test without email: codes are written to api/storage/dev-mail.log instead.
    'mail_host'         => 'smtp.gmail.com',
    'mail_port'         => 465,
    'mail_user'         => 'chanversoza324@gmail.com',
    'mail_app_password' => 'xozk geog ifhz aqte',
    'mail_verify_ssl'   => true,   // set to false ONLY if you get "certificate verify failed" on XAMPP (see SETUP.md)

    // ---- Server ----
    'cookie_secure' => false,           // set to true only when the site is served over HTTPS
    'timezone'      => 'Asia/Manila',   // used for code expiry and login sessions
];
