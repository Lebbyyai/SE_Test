# Feature audit corrections — October 27, 2026

The integrated project remains in SE_Test. These corrections are an ordinary Git update; they do not delete history, reset the database, replace private settings, or distribute uploaded documents.

## Corrections

- Enforce the submission form's required files on the server, including REC endorsement for Prefinal/Final and the advisee-adviser attachment for Proposal.
- Restrict panelist maintenance to staff roles. Scope adviser bulk review and document uploads to the assigned adviser; validate uploaded review documents.
- Keep other coordinators' student assignments when one coordinator removes a shared adviser or an individual connection.
- Respect the adviser's auto-accept setting during code registration and notify the adviser of pending registrations.
- Restore coordinator-code registration, student search, accepted-adviser suggestions and pending-request helpers. Add a route-resolution regression check.
- Remove unused route declarations pointing to a nonexistent assistant batch controller and nonexistent resource update/delete handlers. The implemented AA payment-batch routes and explicit defense workflow actions remain.
- Restore bulk removal for authorized staff, limited to cancelled/rejected requests with no financial records. Validate every selected record before removing any.
- Install FPDF for actual PDF generation. Use the public storage disk for generated-document downloads and enforce request ownership/role access.
- Validate PNG signature replacements before replacing the existing signature, save them transactionally, and supply a default label for file uploads.
- Use the current session cookie for template/signature upload CSRF headers and display template validation errors.
- Supply saved dashboard counts for AA and coordinator honorariums. Replace the coordinator's hardcoded count of seven. Display the student's latest submitted receipt amount with a label that does not imply payment verification.

## Updating an existing installation

Enable GD in PHP, run `composer install`, and restart the local development processes. Follow `docs/groupmate-setup.md` for fresh installations. The browser uses rebuilt assets included with this update; groupmates running Vite should also restart it after pulling.

## Verification boundary

The regression suite uses an isolated test database, fake uploads and fake mail. It includes real PDF template import/output, a complete valid proposal submission, validation failures, assignment protections, route resolution, signatures and dashboard data. These automated results do not mark all 89 manuscript acceptance cases as manually passed. Live school-portal eligibility, Google sign-in and external email delivery still need configured-environment/client acceptance testing.

## Final automated results

- 267 tests passed, 1,988 assertions (21 added regression tests).
- Every registered controller route resolves to an existing public method.
- Composer audit: no known security advisories or abandoned packages after compatible dependency updates.
- PHP formatting, frontend lint, TypeScript, frontend formatting and production build checked before publishing.
- The updated formatter also normalized existing PHP source formatting without intended behavior changes.
- A repeat live demo-role page audit was unavailable because local MySQL refused the connection. The isolated automated tests do not depend on that server. No database reset or uploaded-file replacement was performed.
