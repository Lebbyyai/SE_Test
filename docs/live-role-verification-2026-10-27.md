# Live local role verification — October 27, 2026

## Results

The existing local integrated database was used; it was not reset or replaced with the older SQL export.

- MariaDB access was restored after an isolated-copy check confirmed corruption in the `mysql.global_priv` access table. The original database directory and configuration were backed up and verified with SHA-256 checksums before restoring XAMPP's bundled access-table files. Application account passwords and project tables were retained.
- phpMyAdmin returned HTTP 200 without its previous connection error, and its normal database navigation was verified in the browser.
- All 58 tables in `grad_integration_db` returned OK from the database table check.
- 29 role-page requests: 28 returned HTTP 200 and Finance's dashboard returned the intended redirect to the honorarium workspace.
- Roles covered: Student, Adviser, Coordinator, Administrative Assistant, Finance, Dean and Super Admin.
- No missing Inertia page components and no registered routes pointing to missing controller methods.
- Dean login and the payment-rate page rendered in the browser, including Masteral/Doctorate and Import/Export controls. AA login, saved-record metrics and the monthly schedule calendar also rendered successfully.
- The Dean dashboard still contained a hardcoded pending-honorarium count of seven. It now uses the saved unprocessed disbursement count, covered by a new regression test.
- 268 automated tests passed (1,999 assertions); frontend type checking, lint and production build passed.

The route-by-route server response details are in `diagram-audit-results.json`. Page-load checks do not replace manual acceptance testing of every workflow, live Google sign-in, external mail delivery or school-portal eligibility.

The full database backup and SQL files remain private local files outside this repository.
