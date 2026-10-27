# Payment Rates CSV import and export

Open `/dean/payment-rates`. Export, Import and Print appear above the rate table and follow the system theme.

- Select Masteral or Doctorate, then Export to download that program's CSV. Missing rates have blank amounts so a new database still gets a usable template.
- Open the file in Excel, enter amounts, and save as CSV (comma delimited / UTF-8). Keep the column names `program_level,type,defense_type,amount`.
- Import the file into the same program. Review the current and incoming values, then Apply import.
- Blank amounts and omitted entries remain unchanged. Enter 0 explicitly to set a zero rate.
- Imports are limited to Dean and Super Admin. Coordinator and Administrative Assistant retain read/export access.
- Files are limited to 256 KB and 200 rows. Invalid headers, duplicate rate keys, unsupported types/stages, mixed programs, formulas, negative values, excessive precision and amounts outside the database range are rejected before any write.
- Existing honorarium payment snapshots are not updated by a rate import. External rates remain excluded from the base subtotal.
- No migration, spreadsheet package, production server access or deployment is required for this change.

The server screenshot supplied by the user establishes the button layout; CSV compatibility with an existing server-exported file has not been claimed because no such file was provided.
