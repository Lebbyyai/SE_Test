import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'; // Add this import
import AppLayout from '@/layouts/app-layout';
import { Head, usePage } from '@inertiajs/react';
import { Download, Pencil, Printer, Upload } from 'lucide-react'; // Add this import for the edit icon
import { useState } from 'react';
import { toast } from 'sonner';

const PROGRAM_LEVELS = ['Masteral', 'Doctorate'] as const;
const DEFENSE_TYPES = ['Proposal', 'Pre-final', 'Final'] as const;
const TYPES = [
    'Adviser',
    'Panel Chair',
    'Panel Member 1',
    'Panel Member 2',
    'Panel Member 3',
    'Panel Member 4',
    'External Panel Chair',
    'External Panel Member',
    'REC Fee',
    'School Share',
];

type Rate = {
    id?: number;
    program_level: string;
    type: string;
    defense_type: string;
    amount: string | number;
};

type Props = {
    rates: Rate[];
};

function paymentRequestHeaders(): Record<string, string> {
    const cookie = document.cookie
        .split(';')
        .map((part) => part.trim())
        .find((part) => part.startsWith('XSRF-TOKEN='));
    const token = cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token
            ? { 'X-XSRF-TOKEN': token }
            : { 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' }),
    };
}

export default function PaymentRatesPage({ rates: initialRates }: Props) {
    const { auth } = usePage<{ auth: { user: { role: string } } }>().props;
    const canEdit = ['Dean', 'Super Admin'].includes(auth.user.role);
    const [rates, setRates] = useState<Rate[]>(initialRates);
    const [edit, setEdit] = useState<{
        program_level: string;
        type: string;
        values: { [defense_type: string]: string };
        idxs: { [defense_type: string]: number };
    } | null>(null);
    const [saving, setSaving] = useState(false);
    const [tab, setTab] = useState<string>(PROGRAM_LEVELS[0]); // Add state for tab

    const [importOpen, setImportOpen] = useState(false);
    const [importFile, setImportFile] = useState<File | null>(null);
    const [importRows, setImportRows] = useState<(Rate & { previous_amount: number | null; action: string })[]>([]);
    const [importing, setImporting] = useState(false);
    const [importError, setImportError] = useState('');

    function closeImport() {
        if (importing) return;
        setImportOpen(false);
        setImportFile(null);
        setImportRows([]);
        setImportError('');
    }

    async function importRatesFile(mode: 'preview' | 'apply') {
        if (!importFile) return;
        setImporting(true);
        setImportError('');
        try {
            const form = new FormData();
            form.append('file', importFile);
            form.append('mode', mode);
            const response = await fetch('/dean/payment-rates/import', {
                method: 'POST',
                headers: paymentRequestHeaders(),
                credentials: 'same-origin',
                body: form,
            });
            if (response.status === 419) throw new Error('Your session expired. Refresh the page and sign in again.');
            const data = await response.json();
            if (!response.ok) {
                const errors = data.errors as Record<string, string[]> | undefined;
                throw new Error(errors ? Object.values(errors).flat().join(' ') : data.message || 'Import failed.');
            }
            if (mode === 'preview') {
                setImportRows(data.rows);
            } else {
                setRates(data.rates);
                setImportRows([]);
                setImportFile(null);
                setImportOpen(false);
                toast.success(`${data.count} rate entries imported.`);
            }
        } catch (error) {
            setImportError(error instanceof Error ? error.message : 'Unable to import this file.');
        } finally {
            setImporting(false);
        }
    }

    function openEdit(program_level: string, type: string, idxs: { [defense_type: string]: number }) {
        if (!canEdit) return;
        setEdit({
            program_level,
            type,
            values: {
                Proposal: rates[idxs.Proposal]?.amount?.toString() ?? '',
                'Pre-final': rates[idxs['Pre-final']]?.amount?.toString() ?? '',
                Final: rates[idxs.Final]?.amount?.toString() ?? '',
            },
            idxs,
        });
    }

    function closeEdit() {
        setEdit(null);
    }

    async function saveEdit() {
        if (!edit) return;
        setSaving(true);
        try {
            const updates: Rate[] = [];
            for (const dtype of DEFENSE_TYPES) {
                updates.push({
                    program_level: edit.program_level,
                    type: edit.type,
                    defense_type: dtype,
                    amount: edit.values[dtype] || 0,
                });
            }
            const res = await fetch('/dean/payment-rates', {
                method: 'POST',
                headers: {
                    ...paymentRequestHeaders(),
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ rates: updates }),
            });

            let data = null;
            try {
                data = await res.json();
            } catch {
                const text = await res.text();
                toast.error('Server error: ' + text);
                setSaving(false);
                return;
            }

            if (res.ok && data.success) {
                // Fetch the latest rates from the backend
                const ratesRes = await fetch('/dean/payment-rates/data', {
                    headers: {
                        Accept: 'application/json',
                    },
                });
                const latest = await ratesRes.json();
                setRates(latest.rates);
                toast.success('Rates updated!');
                closeEdit();
            } else if (res.status === 422 && data && data.errors) {
                const messages = Object.values(data.errors).flat().join(' | ');
                toast.error('Validation error: ' + messages);
            } else {
                toast.error('Failed to update rates: ' + (data?.message || res.status));
            }
        } catch (e) {
            const message = typeof e === 'object' && e !== null && 'message' in e ? e.message : String(e);
            toast.error('Network or server error: ' + message);
        } finally {
            setSaving(false);
        }
    }

    function displayAmount(val: string | number | undefined) {
        if (val === undefined || val === '') return '--';
        const num = Number(val);
        return Number.isFinite(num) ? new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(num) : '--';
    }

    function getRows(program_level: string) {
        // Group by type, then by defense_type
        return TYPES.map((type) => {
            const row: {
                type: string;
                values: (Rate | undefined)[];
                idxs: { [defense_type: string]: number };
            } = {
                type,
                values: [],
                idxs: {},
            };
            for (const dtype of DEFENSE_TYPES) {
                const idx = rates.findIndex((r) => r.program_level === program_level && r.type === type && r.defense_type === dtype);
                row.values.push(idx !== -1 ? rates[idx] : undefined);
                row.idxs[dtype] = idx;
            }
            return row;
        });
    }

    function exportTableToPrint(program_level: string) {
        const rows = getRows(program_level);
        const headers = ['Type', ...DEFENSE_TYPES.map((d) => `${d} Defense`)];
        const data = rows.map((row) => {
            const values = DEFENSE_TYPES.map((dtype, idx) => {
                const val = row.values[idx]?.amount;
                return displayAmount(val);
            });
            return [row.type, ...values];
        });

        const totalRow = [
            'BASE SUBTOTAL (EXCLUDES EXTERNAL)',
            ...DEFENSE_TYPES.map((dtype) => {
                const total = rates
                    .filter((r) => r.program_level === program_level && r.defense_type === dtype && !r.type.startsWith('External Panel '))
                    .reduce((sum, r) => sum + (parseFloat(String(r.amount)) || 0), 0);
                return displayAmount(total);
            }),
        ];

        // Dark mode support for print
        const html = `
      <div style="font-family: Arial, sans-serif; padding: 24px; background: #fff; color: #222;">
        <h2 style="margin-bottom: 16px;">${program_level} Payment Rates</h2>
        <table border="1" cellspacing="0" cellpadding="6" style="border-collapse: collapse; min-width: 600px; font-size: 13px;">
          <thead>
            <tr style="background: #f3f3f3;">
              ${headers.map((h) => `<th style="font-weight: bold; text-align: left;">${h}</th>`).join('')}
            </tr>
          </thead>
          <tbody>
            ${data
                .map(
                    (row) => `
              <tr>
                ${row.map((cell, i) => `<td style="text-align: ${i === 0 ? 'left' : 'right'};">${cell}</td>`).join('')}
              </tr>
            `,
                )
                .join('')}
            <tr style="background: #f3f3f3; font-weight: bold;">
              ${totalRow.map((cell, i) => `<td style="text-align: ${i === 0 ? 'left' : 'right'};">${cell}</td>`).join('')}
            </tr>
          </tbody>
        </table>
        <style>
          @media (prefers-color-scheme: dark) {
            body, div { background: #18181b !important; color: #f3f3f3 !important; }
            table { background: #232329 !important; color: #f3f3f3 !important; }
            th, td { border-color: #444 !important; }
            tr { background: #232329 !important; }
            tr:nth-child(even) { background: #18181b !important; }
            tr[style*="background: #f3f3f3;"] { background: #232329 !important; }
          }
        </style>
      </div>
    `;

        // Open print window
        const win = window.open('', '_blank', 'width=900,height=700');
        if (win) {
            win.document.write(`
        <html>
          <head>
            <title>${program_level} Payment Rates</title>
          </head>
          <body>${html}</body>
        </html>
      `);
            win.document.close();
            win.focus();
            setTimeout(() => {
                win.print();
                win.close();
            }, 300);
        }
    }

    return (
        <AppLayout breadcrumbs={[{ title: 'Payment Rates', href: '/dean/payment-rates' }]}>
            <Head title="Payment Rates" />
            <div className="w-full px-0 py-8">
                {/* Header and description */}
                <div className="mb-6 px-6">
                    <h1 className="mb-1 text-2xl font-bold">Payment Rates</h1>
                    <p className="text-muted-foreground mb-4 text-sm">View and manage payment rates for Masteral and Doctorate programs.</p>
                    <p className="text-muted-foreground text-sm">
                        External rates are per recipient and excluded from the base subtotal. Actual honoraria depend on assigned roles and confirmed
                        service; configuring a rate does not change student charges.
                    </p>
                </div>
                {/* Tabs and Print Button */}
                <div className="mb-6 flex flex-col items-start justify-between gap-4 px-4 sm:flex-row sm:items-center sm:px-6">
                    <Tabs value={tab} onValueChange={setTab} className="">
                        <TabsList>
                            {PROGRAM_LEVELS.map((level) => (
                                <TabsTrigger key={level} value={level} className="text-sm">
                                    {level}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                    </Tabs>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <a href="/dean/payment-rates/export">
                                <Download className="h-4 w-4" /> Export
                            </a>
                        </Button>
                        {canEdit && (
                            <Button variant="outline" onClick={() => setImportOpen(true)}>
                                <Upload className="h-4 w-4" /> Import
                            </Button>
                        )}
                        <Button variant="outline" onClick={() => exportTableToPrint(tab)}>
                            <Printer className="h-4 w-4" /> Print
                        </Button>
                    </div>
                </div>
                {/* Responsive Table */}
                <Tabs value={tab} onValueChange={setTab} className="px-2 sm:px-6">
                    {PROGRAM_LEVELS.map((program_level) => (
                        <TabsContent key={program_level} value={program_level}>
                            <div className="mb-10 w-full overflow-x-auto">
                                <h2 className="mb-2 text-lg font-semibold">{program_level}</h2>
                                <div className="w-full min-w-[700px]">
                                    <div className="overflow-x-auto rounded-none bg-white shadow-sm dark:bg-zinc-900">
                                        <Table className="min-w-[700px] table-fixed">
                                            <colgroup>
                                                <col style={{ width: '180px' }} />
                                                {DEFENSE_TYPES.map((_, i) => (
                                                    <col key={i} style={{ width: '140px' }} />
                                                ))}
                                                <col style={{ width: '100px' }} />
                                            </colgroup>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead className="text-left text-xs font-medium">Type</TableHead>
                                                    {DEFENSE_TYPES.map((dtype) => (
                                                        <TableHead key={dtype} className="text-end text-xs font-medium">
                                                            {dtype} Defense
                                                        </TableHead>
                                                    ))}
                                                    <TableHead className="text-center text-xs font-medium">Action</TableHead>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {getRows(program_level).map((row, rowIdx) => (
                                                    <TableRow
                                                        key={row.type}
                                                        className={rowIdx % 2 === 0 ? 'even:bg-zinc-50 dark:even:bg-zinc-800' : ''}
                                                    >
                                                        <TableCell className="text-left text-xs font-semibold">{row.type}</TableCell>
                                                        {DEFENSE_TYPES.map((dtype, colIdx) => (
                                                            <TableCell key={dtype} className="text-end text-xs">
                                                                {displayAmount(row.values[colIdx]?.amount)}
                                                            </TableCell>
                                                        ))}
                                                        <TableCell className="text-center text-xs">
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                className="mx-auto flex items-center gap-1 px-2 py-1 text-xs"
                                                                disabled={!canEdit}
                                                                onClick={() => openEdit(program_level, row.type, row.idxs)}
                                                            >
                                                                <Pencil className="mr-1 h-3 w-3" />
                                                                Edit
                                                            </Button>
                                                        </TableCell>
                                                    </TableRow>
                                                ))}
                                                {/* Total row */}
                                                <TableRow className="border-t border-zinc-400 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800">
                                                    <TableCell className="text-left text-xs font-bold">BASE SUBTOTAL (EXCLUDES EXTERNAL)</TableCell>
                                                    {DEFENSE_TYPES.map((dtype) => (
                                                        <TableCell key={dtype} className="text-end text-xs font-bold">
                                                            {(() => {
                                                                const total = rates
                                                                    .filter(
                                                                        (r) =>
                                                                            r.program_level === program_level &&
                                                                            r.defense_type === dtype &&
                                                                            !r.type.startsWith('External Panel '),
                                                                    )
                                                                    .reduce((sum, r) => sum + (parseFloat(String(r.amount)) || 0), 0);
                                                                return total === 0
                                                                    ? '--'
                                                                    : total.toLocaleString(undefined, { minimumFractionDigits: 2 });
                                                            })()}
                                                        </TableCell>
                                                    ))}
                                                    <TableCell />
                                                </TableRow>
                                            </TableBody>
                                        </Table>
                                    </div>
                                </div>
                            </div>
                        </TabsContent>
                    ))}
                </Tabs>
                <Dialog
                    open={importOpen}
                    onOpenChange={(open) => {
                        if (!open) closeImport();
                    }}
                >
                    <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>Import payment rates</DialogTitle>
                            <DialogDescription>
                                Upload CSV, JSON or XLSX. Use the same four fields: program_level, type, defense_type and amount. Each row's
                                program_level identifies Masteral or Doctorate, so one file imports both programs regardless of the selected tab. Use
                                numeric amounts without currency signs or commas. Blank amounts and omitted rates are kept; enter 0 for a zero rate.
                                JSON accepts an array of rate objects or a rates array. XLSX worksheets use those four column headings and values
                                without formulas.
                            </DialogDescription>
                        </DialogHeader>
                        <label htmlFor="payment-rate-csv" className="text-sm font-medium">
                            CSV, JSON or XLSX file (maximum 256 KB)
                        </label>
                        <Input
                            id="payment-rate-csv"
                            type="file"
                            accept=".csv,.json,.xlsx,text/csv,application/json,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            disabled={importing}
                            onChange={(event) => {
                                setImportFile(event.target.files?.[0] ?? null);
                                setImportRows([]);
                                setImportError('');
                            }}
                        />
                        {importError && (
                            <p role="alert" className="text-destructive text-sm">
                                {importError}
                            </p>
                        )}
                        {importRows.length > 0 && (
                            <div className="space-y-2">
                                <p className="text-sm">
                                    Review {importRows.length} entries before saving. Existing payments keep their recorded amounts.
                                </p>
                                <p className="text-muted-foreground text-sm">
                                    Detected:{' '}
                                    {PROGRAM_LEVELS.map(
                                        (level) => `${level}: ${importRows.filter((row) => row.program_level === level).length} rates`,
                                    ).join(' | ')}
                                    . Both programs will be saved together.
                                </p>
                                <div className="max-h-72 overflow-auto rounded-md border">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Program</TableHead>
                                                <TableHead>Type</TableHead>
                                                <TableHead>Defense</TableHead>
                                                <TableHead>Current</TableHead>
                                                <TableHead>Imported</TableHead>
                                                <TableHead>Change</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {importRows.map((row) => (
                                                <TableRow key={`${row.program_level}-${row.type}-${row.defense_type}`}>
                                                    <TableCell>{row.program_level}</TableCell>
                                                    <TableCell>{row.type}</TableCell>
                                                    <TableCell>{row.defense_type}</TableCell>
                                                    <TableCell>
                                                        {row.previous_amount === null ? 'Not set' : Number(row.previous_amount).toFixed(2)}
                                                    </TableCell>
                                                    <TableCell>{Number(row.amount).toFixed(2)}</TableCell>
                                                    <TableCell>{row.action}</TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            </div>
                        )}
                        <DialogFooter>
                            <Button variant="outline" disabled={importing} onClick={closeImport}>
                                Cancel
                            </Button>
                            {importRows.length === 0 ? (
                                <Button disabled={!importFile || importing} onClick={() => importRatesFile('preview')}>
                                    {importing ? 'Checking...' : 'Review import'}
                                </Button>
                            ) : (
                                <Button disabled={importing} onClick={() => importRatesFile('apply')}>
                                    {importing ? 'Saving...' : 'Apply import'}
                                </Button>
                            )}
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
                {/* Edit Dialog */}
                <Dialog open={!!edit} onOpenChange={closeEdit}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Edit Rates</DialogTitle>
                        </DialogHeader>
                        {edit !== null && (
                            <div className="space-y-4">
                                <div className="flex flex-col gap-4 sm:flex-row">
                                    <div>
                                        <div className="text-muted-foreground mb-1 text-xs">Program Level</div>
                                        <div className="text-xs font-medium">{edit.program_level}</div>
                                    </div>
                                    <div>
                                        <div className="text-muted-foreground mb-1 text-xs">Type</div>
                                        <div className="text-xs font-medium">{edit.type}</div>
                                    </div>
                                </div>
                                <div>
                                    <Table className="overflow-hidden">
                                        <TableHeader>
                                            <TableRow>
                                                {DEFENSE_TYPES.map((dtype) => (
                                                    <TableHead key={dtype} className="text-end text-xs">
                                                        {dtype} Defense
                                                    </TableHead>
                                                ))}
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            <TableRow>
                                                {DEFENSE_TYPES.map((dtype) => (
                                                    <TableCell key={dtype} className="text-end">
                                                        <Input
                                                            type="number"
                                                            min={0}
                                                            value={edit.values[dtype]}
                                                            onChange={(e) =>
                                                                setEdit({
                                                                    ...edit,
                                                                    values: { ...edit.values, [dtype]: e.target.value },
                                                                })
                                                            }
                                                            className="mx-auto w-32 text-xs"
                                                        />
                                                    </TableCell>
                                                ))}
                                            </TableRow>
                                        </TableBody>
                                    </Table>
                                </div>
                            </div>
                        )}
                        <DialogFooter>
                            <Button variant="outline" onClick={closeEdit} className="text-xs">
                                Cancel
                            </Button>
                            <Button onClick={saveEdit} disabled={saving} className="text-xs">
                                {saving ? 'Saving...' : 'Save'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
