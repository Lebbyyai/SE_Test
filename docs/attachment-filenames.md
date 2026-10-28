# Defense attachment filenames

New student submissions, adviser document uploads and correction replacements retain original filenames as metadata. Storage continues to use randomized paths to avoid collisions. Authorized downloads and attachment lists use the original names; users do not need to rename stored files.

For old uploads without filename metadata, the system uses a readable document label and thesis title. It cannot reliably reconstruct original filenames that were never saved. Existing browser download history is not renamed.

Correction history records the previous and current document names alongside the existing stored-path history. Access restrictions are unchanged.

After pulling this change, run `php artisan migrate`. The migration only adds a nullable JSON metadata column; it does not move existing files or change defense decisions. The working local database was backed up before applying it.
