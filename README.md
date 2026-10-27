# Integrated Graduate School System

Integration of the existing Comprehensive Exam and Defense Request project with the Honorarium additions.

## First time setup for groupmates

Follow [the groupmate setup guide](docs/groupmate-setup.md) to clone this repository, create your own empty database, install dependencies, and create local demo accounts. No database export or existing uploaded documents are included.

## Running an installation that is already configured

The [October 28 feature audit](docs/full-feature-audit-2026-10-28.md) adds AA exam application and receipt review, repairs document and role-access defects, and records verification and remaining client checks. After pulling, run `npm ci` and `php artisan migrate`, then restart the development process.

The October 25 automated-check and runtime fixes are described in [the verification report](docs/check-fixes-2026-10-25.md).

The new [Defense Register](docs/defense-register-2026-10-26.md) provides schedule exports, internal/external panel classification, and actual service confirmation before honorarium preparation. Run `php artisan migrate` after updating.

Open this folder in VS Code. Start XAMPP MySQL, then run `npm.cmd run dev`. Open http://127.0.0.1:8001.

The integration uses its own `grad_integration_db` database. Original project folders and the original `grad_db` database were preserved.

Finance demo: `finance@uic.edu.ph` / `password`. Existing local demo logins remain available.

See [integration setup and limitations](docs/integration-setup.md) for the module handoff, running workers, Google OAuth redirect configuration, and the Comprehensive Exam audit findings.

Validation on October 24, 2026: payment-ledger, re-verification, scheduling and eligibility improvements are documented in docs/implemented-improvements-2026-10-24.md. The role-page checks include existing Defense Request pages and the new Finance pages. Live school-portal eligibility and final honorarium disbursement remain outside those validated paths.
