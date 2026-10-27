# Run SE_Test locally in VS Code

This repository contains the integrated Defense Request, Comprehensive Exam, and Honorarium code. Each groupmate runs a separate database. Changes to their local records do not appear on another groupmate's computer.

## 1. Install prerequisites

- Git and VS Code.
- XAMPP with PHP 8.2 or newer and MySQL/MariaDB. Enable the PHP extensions required by Composer, including PDO MySQL, fileinfo, mbstring, OpenSSL, XML, cURL, GD, and zip. For XAMPP, uncomment `extension=gd` in `C:\xampp\php\php.ini`, then restart PHP before running `composer install`. PDF generation now installs FPDF through Composer.
- Composer 2 and Node.js 22 LTS with npm.
- Make PHP available in your terminal PATH (normally `C:\xampp\php` on Windows).

## 2. Download and open

In a terminal:

```powershell
git clone https://github.com/Lebbyyai/SE_Test.git
cd SE_Test
code .
```

For a private repository, the owner must invite your GitHub account as a collaborator and you must accept the invitation first.

## 3. Configure your own database

Start MySQL in XAMPP. In phpMyAdmin, create an **empty** database named `grad_integration_db`. Use a different name if you already have a database with that name; never overwrite an existing database for this setup.

From the VS Code terminal, inside SE_Test:

```powershell
Copy-Item .env.example .env
composer install
npm.cmd ci
php artisan key:generate
```

Open `.env` and check:

```dotenv
APP_ENV=local
APP_URL=http://127.0.0.1:8001
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=grad_integration_db
DB_USERNAME=root
DB_PASSWORD=
MAIL_MAILER=log
DEFENSE_REMINDER_EMAILS=false
```

Use your own database name, username, password, and port if different. The empty password is only the usual local XAMPP default. Keep `.env` private; Git ignores it.

## 4. Create tables and local demo accounts

```powershell
php artisan migrate
php artisan demo:diagram-accounts
php artisan demo:finance-account
php artisan db:seed --class=ProgramRecordSeeder
php artisan storage:link
npm.cmd run build
```

Use the explicit program seeder shown above. The general `--seed` command invokes an older sample defense seeder and is not required for this setup. Do not use `migrate:fresh` against an existing database: it deletes tables.

All six demo accounts use password **`password`**:

| Role | Email |
| --- | --- |
| Student | student@uic.edu.ph |
| Adviser | adviser@uic.edu.ph |
| Academic Coordinator | acadcoor@uic.edu.ph |
| Administrative Assistant | aa@uic.edu.ph |
| Dean | dean@uic.edu.ph |
| Finance | finance@uic.edu.ph |

These accounts work only in local/testing environments. This fresh setup does not create a Super Admin account or copy the original installation's users, documents, requests, payments, coordinator assignments, or adviser assignments.

## 5. Start the system

```powershell
npm.cmd run dev
```

Open **http://127.0.0.1:8001/login**. Keep this terminal running. The command starts the Laravel server, frontend development server, queue worker, and scheduler. Press Ctrl+C to stop them. XAMPP MySQL must remain running; Apache is optional because Laravel starts its own web server.

If port 8001 or 5174 is already occupied, stop your other copy of the system before starting this one.

## 6. What to test

Use separate browser profiles or a private window for different roles. Begin with login and navigation, then set up coordinator/adviser assignments using the existing screens before submitting a student defense request. Adviser acceptance is required. Configure Dean-approved payment rates before testing honorarium preparation. Finance verification of both current documents is required before preparing the ledger; the second Finance document unlocks on the next Philippine calendar day.

The database starts empty except for migrations, demo accounts, and program choices. Bring your own dummy test documents. Do not upload real student records to GitHub.

Google sign-in needs your own authorized Google OAuth credentials and the exact callback `http://127.0.0.1:8001/auth/google/callback`. It is optional for local demo testing. School eligibility checks require the authorized school portal connection; demo login does not supply that connection. Comprehensive Exam submission may therefore be blocked by the eligibility check. Email is logged locally instead of delivered to Gmail by default.

See [integration details and limitations](integration-setup.md) and [implemented improvements](implemented-improvements-2026-10-24.md) for the workflows and remaining client decisions. This is a testing project, not a production deployment.

## 7. Get later code updates

Save or commit your own code edits before updating:

```powershell
git pull --ff-only
composer install
npm.cmd ci
php artisan migrate
npm.cmd run build
```

Restart the development command after updating. Your local database and `.env` stay on your computer.
