# Payment Rates CSV import and export

Open `/dean/payment-rates`. Export, Import and Print appear above the rate table and follow the system theme.

- Export downloads one CSV containing both Masteral and Doctorate, independent of the selected tab. Missing rates have blank amounts so a new database still gets a usable template.
- Open the file in Excel, enter amounts, and save as CSV (comma delimited / UTF-8). Keep the column names `program_level,type,defense_type,amount`.
- Files may contain both programs; each row is assigned to its program automatically and both programs are imported in one operation. Review the current and incoming values, then Apply import.
- Blank amounts and omitted entries remain unchanged. Enter 0 explicitly to set a zero rate.
- Imports are limited to Dean and Super Admin. Coordinator and Administrative Assistant retain read/export access.
- Files are limited to 256 KB and 200 rows. Invalid headers, duplicate rate keys, unsupported types/stages, formulas, negative values, excessive precision and amounts outside the database range are rejected before any write.
- Existing honorarium payment snapshots are not updated by a rate import. External rates remain excluded from the base subtotal.
- No migration, spreadsheet package, production server access or deployment is required for this change.

Server-exported CSVs use the same four columns. A supplied file included the invalid stage Fincal Defense with amount 2000 and an existing Final entry with amount 1000 for Masteral Adviser. Invalid stages and duplicate keys remain errors; the importer does not guess which rate to keep. Payment writes read the current XSRF cookie so a page token left over from before login does not cause a false session-expired message.

## Additional import formats

Import accepts CSV, JSON and XLSX (maximum 256 KB and 200 rate rows). Export currently remains CSV.

All formats use the same four fields: program_level, type, defense_type, amount. Each row identifies Masteral or Doctorate. One upload can contain both, and preview shows the detected counts before Apply import. Blank or null amounts leave existing values unchanged; zero sets an explicit zero rate.

JSON accepts either an array of rate objects, or an object containing a rates array. Extra object properties such as exported IDs are ignored. Labels must be strings and amounts numeric or numeric strings.

XLSX accepts text and numeric cells, including Excel shared strings, inline strings and multiple worksheets. Every nonempty sheet starts with the four column headings in that order. Formula cells are rejected; paste values before uploading. The reader checks compressed workbook structure and expanded sizes without extracting files, evaluating formulas or following external links. Encrypted, damaged and unsupported files return a validation error. All rows across all worksheets are validated before any writes.

The existing Dean/Super Admin import restriction remains enforced for every format. No new dependency or database migration is needed.
