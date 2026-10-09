# Setup guide: PHP + MySQL (XAMPP)

The backend is now **PHP** (folder `api/`). It connects to MySQL/MariaDB with PDO and runs on XAMPP's Apache.
**Nothing needs to be compiled** (PHP runs as-is), and you no longer need Java, a JDK, or the MariaDB `.jar` driver.

## What the system does

| Area | How it works |
|---|---|
| Built-in subjects | Public, no login (browser only) |
| Accounts | Register (Gmail only) -> 6-digit code by email -> log in. Roles: `admin`, `student` |
| Shared quizzes | Upload a Word (.docx) -> the server reads it -> saved in MySQL -> the whole class can take it |
| Class stats | Each finished built-in quiz saves subject + percentage (no personal data) in MySQL |

| Action | Not logged in | Student | Admin |
|---|---|---|---|
| Built-in subjects | Yes | Yes | Yes |
| See and take class quizzes | No | Yes | Yes |
| Upload a quiz | No | Yes (can be switched off) | Yes |
| Delete a quiz | No | Only their own | Any |

The **admin** is the Gmail you put in `admin_email`: it becomes admin when it registers and passes the code check.

## Folder map

```
college-quiz-reviewer/
├── api/
│   ├── config.php        <- YOUR SETTINGS (database password, admin email, Gmail sender)
│   ├── index.php         <- the PHP backend entry point (all /api calls go through it)
│   ├── test-db.php       <- opens a "is everything connected?" check page
│   ├── inc/              <- backend code (database, accounts, .docx reader, mailer)
│   └── storage/          <- logs (error.log, dev-mail.log)
├── sql/schema.sql        <- database tables (import once)
├── sql/upgrade.sql       <- only if you imported the OLD schema before
└── *.html, css/, js/     <- the website
```

## Step 1 - Put the project in XAMPP

1. Install **XAMPP** (https://www.apachefriends.org) if you do not have it.
2. Copy the whole **`college-quiz-reviewer`** folder into `C:\xampp\htdocs\` (macOS/Linux: the `htdocs` folder of XAMPP).
3. Open the **XAMPP Control Panel** and **Start Apache** and **Start MySQL**.

## Step 2 - Create the database (phpMyAdmin)

1. Open `sql/schema.sql` in a text editor. Change `ChangeThisPassword123!` (it appears **2 times**) to your own password. Save.
2. Go to http://localhost/phpmyadmin > click the **SQL** tab > paste the whole file > **Go**.
3. The left list should now show `quiz_reviewer` with 7 tables: users, email_otps, sessions, quizzes, questions, login_fails, attempts.

> **Already imported the OLD schema earlier?** Do not worry, your data is safe. Either import the new `schema.sql` again (it only adds what is missing) or paste only `sql/upgrade.sql` in the SQL tab (it adds the 2 new tables `login_fails` and `attempts`).

## Step 3 - Edit `api/config.php`

Open `api/config.php` in VS Code and change:

| Setting | Put |
|---|---|
| `db_password` | The same password you used in Step 2 |
| `admin_email` | **Your own Gmail** (you become admin) |
| `mail_user`, `mail_app_password` | Leave empty for now to test without email (see Step 5) |

Never share this file or upload it to GitHub.

## Step 4 - Check the connection

Open **http://localhost/college-quiz-reviewer/api/test-db.php**

Every line should have a green check mark. Red lines tell you what to fix (for example "MySQL is not started" or "extension zip is off"). When everything is green, **delete `api/test-db.php`** (it is only for you; it only opens from your own computer anyway).

If an extension is off: open XAMPP > Apache **Config** > `PHP (php.ini)`, remove the `;` in front of the line (for example `extension=zip`), save, and **restart Apache**.

## Step 5 - Open the site and register

Open **http://localhost/college-quiz-reviewer/** (always through `http://localhost/...`, never by double-clicking the HTML file).

1. Click **Register** with the Gmail you set as `admin_email`.
2. **Testing without Gmail (DEV MODE):** while `mail_user` is empty, the 6-digit code is written to `api/storage/dev-mail.log`. Open that file, copy the code, and type it on the verify page.
3. You are logged in as **(admin)**. Try **Upload Quiz** with a `.docx` file.

## Step 6 - Real emails with Gmail (do this when you want codes sent by email)

Google does not let apps use your normal password, so you create a special 16-character "App password":

1. Best practice: create a **separate Gmail** only for sending codes (for example `yourclass.quiz@gmail.com`).
2. Go to https://myaccount.google.com > **Security** > turn on **2-Step Verification**.
3. In the search bar of that page, type **App passwords**, open it, name it "Quiz Reviewer", click **Create**.
4. Copy the 16-character code.
5. In `api/config.php` set `mail_user` = that Gmail and `mail_app_password` = the 16 characters.
6. Register a second Gmail account to test: the code should arrive in its inbox (check Spam).

## Word file format for quizzes

```
1. Which device forwards packets between networks?
A. Hub
B. Switch
C. Router
D. Modem
Answer: C
Explanation: A router uses IP addresses to connect networks.
```
- Exactly 4 choices (A-D), one per line. Number the questions.
- The answer can be an `Answer: C` line, an **Answer Key** list at the end (`1. C`), the correct choice in **bold**/underline/highlight, or `*` after the choice.
- If the reader is unsure about a question, the preview shows a warning and you pick the answer before saving.
- Save as `.docx` (not `.doc`). Maximum 5 MB.

The Word reader is rule-based and runs on your own server. It understands many common layouts but not free-form text such as a lecture handout.

## Security built in
- Passwords: salted PBKDF2 hashes. Codes and login tokens are stored only as hashes.
- Code: 6 digits, valid 10 minutes, 5 wrong tries max, one new code per minute.
- Login: 5 wrong passwords lock that email for 10 minutes. Sessions last 7 days (HttpOnly cookie).
- All database queries use prepared statements (protects against SQL injection).
- Only `@gmail.com` addresses can register. A new account cannot log in until the code is verified.
- Folders `api/inc`, `api/storage`, and `sql` are blocked from the browser by `.htaccess` (XAMPP allows this by default).
- Putting it online: use HTTPS, set `cookie_secure` to `true`, use a strong database password, and delete `api/test-db.php`.

## Troubleshooting
| Problem | Fix |
|---|---|
| Pages say "Could not reach the server" | You opened the file directly. Use http://localhost/college-quiz-reviewer/ with Apache started. |
| `test-db.php`: "Could not connect to the database" | MySQL is not started in XAMPP, or `db_user` / `db_password` in `api/config.php` does not match what you set in `schema.sql`. |
| "Access denied for user quizapp" | Change the password in phpMyAdmin: User accounts > quizapp > Edit privileges > Change password, then use the same one in `api/config.php`. |
| "Table ... doesn't exist" | Import `sql/schema.sql` (or `sql/upgrade.sql` for the 2 new tables). |
| Error 500 on any action | Open `api/storage/error.log` - the last line says what failed. |
| Email not sent (error 502) | Wrong App password, 2-Step Verification is off, or `mail_user` is not the account that made the App password. The reason is in `api/storage/error.log`. |
| Email error "certificate verify failed" (XAMPP on Windows) | Set `'mail_verify_ssl' => false` in `api/config.php` for local testing, or point `openssl.cafile` in php.ini to a CA bundle. |
| Code never arrives | Check Spam, wait for the 60-second timer, press **Resend code**. In DEV MODE read `api/storage/dev-mail.log`. |
| Upload says "no questions found" | Check the Word format above. Save as `.docx`. |
| Upload says "extension zip" error | Enable `extension=zip` in php.ini and restart Apache. |
| Site is in a different folder name | It still works: all addresses are relative. Just open that folder name under `http://localhost/`. |
| Port 80 already in use (Apache will not start) | Close Skype/IIS, or change Apache's port in XAMPP (Config > httpd.conf, `Listen 8080`) and open `http://localhost:8080/college-quiz-reviewer/`. |
