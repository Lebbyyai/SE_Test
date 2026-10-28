import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { csrfHeaders } from '@/lib/csrf';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Entry = { action: string; at: string; actor_name: string; notes?: string; payment_reference?: string };
type Payment = {
    id: number;
    recipient_name: string;
    recipient_role: string;
    amount: string;
    school_year: string;
    semester: string;
    status: string;
    requires_review: boolean;
    payment_reference?: string;
    history: Entry[];
    defense_request: { thesis_title: string; program: string; workflow_state: string };
};
type Props = { records: Payment[]; requests: { id: number; thesis_title: string }[]; canPrepare: boolean; canRecord: boolean; today: string };
const labels: Record<string, string> = {
    prepared: 'Awaiting Finance approval',
    approved: 'Approved',
    disbursed: 'Disbursed',
    claimed: 'Claimed',
    voided: 'Voided after defense changes',
};
const money = (value: string | number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value));
export default function HonorariumLedger() {
    const { records, requests, canPrepare, canRecord, today } = usePage<Props>().props;
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');
    const [semester, setSemester] = useState('');
    const [requestId, setRequestId] = useState('');
    const [year, setYear] = useState(`${today.slice(0, 4)}-${Number(today.slice(0, 4)) + 1}`);
    const [term, setTerm] = useState('1st');
    const [selected, setSelected] = useState<Payment | null>(null);
    const [date, setDate] = useState(today);
    const [reference, setReference] = useState('');
    const [notes, setNotes] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const visible = records.filter(
        (r) =>
            (!status || r.status === status) &&
            (!semester || r.semester === semester) &&
            `${r.recipient_name} ${r.school_year} ${r.defense_request.thesis_title}`.toLowerCase().includes(search.toLowerCase()),
    );
    const next = (row: Payment) =>
        ({ prepared: 'approved', approved: 'disbursed', disbursed: 'claimed' })[row.status as 'prepared' | 'approved' | 'disbursed'];
    async function save(url: string, method: string, data: Record<string, string>) {
        if (busy) return;
        setBusy(true);
        setError('');
        setMessage('');
        try {
            const result = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: csrfHeaders(true),
                body: JSON.stringify(data),
            });
            const body = await result.json().catch(() => ({}));
            if (!result.ok)
                throw new Error(
                    Object.values(body.errors ?? {})
                        .flat()
                        .join(' ') ||
                        body.message ||
                        'Could not save the record.',
                );
            setMessage(body.message);
            setSelected(null);
            router.reload({ onFinish: () => setBusy(false) });
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Connection interrupted. Please try again.');
            setBusy(false);
        }
    }
    function exportCsv() {
        const cell = (value: string) => '"' + (/^[=+\-@]/.test(value) ? "'" + value : value).replaceAll('"', '""') + '"';
        const lines = [
            ['Recipient', 'Role', 'Defense', 'School Year', 'Semester', 'Amount', 'Status', 'Reference'],
            ...visible.map((r) => [
                r.recipient_name,
                r.recipient_role,
                r.defense_request.thesis_title,
                r.school_year,
                r.semester,
                r.amount,
                r.requires_review ? 'Requires review' : labels[r.status],
                r.payment_reference ?? '',
            ]),
        ];
        const url = URL.createObjectURL(new Blob([lines.map((row) => row.map(cell).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8;' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = 'honorarium-records.csv';
        a.click();
        URL.revokeObjectURL(url);
    }
    return (
        <AppLayout breadcrumbs={[{ title: 'Honorarium Payments', href: '/honorarium-ledger' }]}>
            <Head title="Honorarium Payments" />
            <main className="mx-auto w-full max-w-7xl space-y-5 p-4 md:p-8">
                <div>
                    <h1 className="text-2xl font-bold">Honorarium Payments</h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Track each recipient from preparation through claiming. Record confirmed payments here; this screen does not transfer money.
                    </p>
                    <Link href="/honorarium-documents" className="mt-2 inline-block text-sm underline">
                        Review Finance documents
                    </Link>
                    <Link href="/defense-register" className="mt-2 ml-4 inline-block text-sm underline">
                        Defense register and service confirmation
                    </Link>
                </div>
                {error && (
                    <p role="alert" className="rounded border border-red-300 p-3 text-red-700 dark:text-red-300">
                        {error}
                    </p>
                )}
                {message && (
                    <p role="status" className="rounded border border-green-300 p-3 text-green-700 dark:text-green-300">
                        {message}
                    </p>
                )}
                <div className="grid gap-3 sm:grid-cols-4">
                    {['prepared', 'approved', 'disbursed', 'claimed'].map((s) => (
                        <div key={s} className="rounded-lg border p-4">
                            <p className="text-sm">{labels[s]}</p>
                            <p className="text-xl font-semibold">
                                {money(records.filter((r) => r.status === s).reduce((total, r) => total + Number(r.amount), 0))}
                            </p>
                        </div>
                    ))}
                </div>
                {canPrepare && (
                    <form
                        className="space-y-3 rounded-lg border p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void save(`/honorarium-ledger/${requestId}/prepare`, 'POST', { school_year: year, semester: term });
                        }}
                    >
                        <h2 className="font-semibold">Prepare recipient records</h2>
                        <p className="text-muted-foreground text-sm">
                            Confirm defense completion and recipient service in the Defense Register first. Both Finance documents must be verified.
                            Only rendered services are included. Amounts are copied from the dean’s current rates and preserved in the payment record.
                        </p>
                        <div className="grid gap-3 sm:grid-cols-3">
                            <label className="text-sm">
                                Defense request
                                <select
                                    required
                                    value={requestId}
                                    onChange={(e) => setRequestId(e.target.value)}
                                    className="bg-background mt-1 w-full rounded border p-2"
                                >
                                    <option value="">Select a request</option>
                                    {requests.map((r) => (
                                        <option key={r.id} value={r.id}>
                                            #{r.id} · {r.thesis_title}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="text-sm">
                                School year
                                <Input required value={year} onChange={(e) => setYear(e.target.value)} placeholder="2026-2027" className="mt-1" />
                            </label>
                            <label className="text-sm">
                                Semester
                                <select
                                    value={term}
                                    onChange={(e) => setTerm(e.target.value)}
                                    className="bg-background mt-1 w-full rounded border p-2"
                                >
                                    {['1st', '2nd', 'Summer'].map((t) => (
                                        <option key={t}>{t}</option>
                                    ))}
                                </select>
                            </label>
                        </div>
                        <Button disabled={busy || !requestId} type="submit">
                            Prepare from configured rates
                        </Button>
                    </form>
                )}
                <div className="flex flex-wrap gap-3">
                    <Input
                        aria-label="Search payment records"
                        placeholder="Recipient, defense title, or school year"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="max-w-sm"
                    />
                    <select
                        aria-label="Filter by status"
                        value={status}
                        onChange={(e) => setStatus(e.target.value)}
                        className="bg-background rounded border p-2"
                    >
                        <option value="">All statuses</option>
                        {Object.entries(labels).map(([value, label]) => (
                            <option value={value} key={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label="Filter by semester"
                        value={semester}
                        onChange={(e) => setSemester(e.target.value)}
                        className="bg-background rounded border p-2"
                    >
                        <option value="">All semesters</option>
                        {['1st', '2nd', 'Summer'].map((t) => (
                            <option key={t}>{t}</option>
                        ))}
                    </select>
                    <Button variant="outline" onClick={exportCsv}>
                        Export displayed records
                    </Button>
                </div>
                {!visible.length && (
                    <p className="text-muted-foreground rounded border border-dashed p-8 text-center">No matching honorarium records.</p>
                )}
                {visible.map((row) => (
                    <article key={row.id} className="space-y-3 rounded-lg border p-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 className="font-semibold">
                                    {row.recipient_name} · {row.recipient_role}
                                </h2>
                                <p className="text-muted-foreground text-sm">
                                    {row.defense_request.thesis_title} · {row.school_year} / {row.semester}
                                </p>
                                <p className="mt-1 font-semibold">
                                    {money(row.amount)} · {labels[row.status]}
                                </p>
                                {row.payment_reference && <p className="text-sm">Reference: {row.payment_reference}</p>}
                            </div>
                            {canRecord && next(row) && !row.requires_review && (
                                <Button
                                    disabled={busy}
                                    onClick={() => {
                                        setSelected(row);
                                        setDate(today);
                                        setReference('');
                                        setNotes('');
                                        setError('');
                                    }}
                                >
                                    Record {next(row) === 'approved' ? 'Finance approval' : next(row)}
                                </Button>
                            )}
                        </div>
                        {row.requires_review && (
                            <p className="rounded border border-amber-300 p-3 text-sm">
                                Defense information changed after payment was recorded. Finance must reconcile this historical payment before any
                                further action.
                            </p>
                        )}
                        <details>
                            <summary className="cursor-pointer text-sm">Payment history</summary>
                            <ol className="mt-2 space-y-2">
                                {row.history.map((h, i) => (
                                    <li key={i} className="text-sm">
                                        <span className="font-medium">{h.action}</span> ·{' '}
                                        {new Date(h.at).toLocaleString('en-PH', { timeZone: 'Asia/Manila' })} (PH) · {h.actor_name}
                                        <p className="text-muted-foreground whitespace-pre-wrap">{h.notes}</p>
                                    </li>
                                ))}
                            </ol>
                        </details>
                    </article>
                ))}
                <Dialog
                    open={!!selected}
                    onOpenChange={(open) => {
                        if (!open && !busy) setSelected(null);
                    }}
                >
                    <DialogContent>
                        <DialogTitle>Record {selected ? next(selected) : 'payment status'}</DialogTitle>
                        <DialogDescription>Save a record of the Finance action for this recipient.</DialogDescription>
                        {selected && (
                            <form
                                className="space-y-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    void save(`/honorarium-ledger/${selected.id}`, 'PATCH', {
                                        status: next(selected)!,
                                        event_date: date,
                                        payment_reference: reference,
                                        notes,
                                    });
                                }}
                            >
                                <p>
                                    {selected.recipient_name} · {money(selected.amount)}
                                </p>
                                {next(selected) !== 'approved' && (
                                    <label className="block text-sm">
                                        Action date
                                        <Input type="date" required max={today} value={date} onChange={(e) => setDate(e.target.value)} />
                                    </label>
                                )}
                                {next(selected) === 'disbursed' && (
                                    <label className="block text-sm">
                                        Payment reference
                                        <Input required maxLength={150} value={reference} onChange={(e) => setReference(e.target.value)} />
                                    </label>
                                )}
                                <label className="block text-sm">
                                    Notes
                                    <textarea
                                        className="bg-background mt-1 w-full rounded border p-2"
                                        rows={3}
                                        maxLength={2000}
                                        value={notes}
                                        onChange={(e) => setNotes(e.target.value)}
                                    />
                                </label>
                                {error && (
                                    <p role="alert" className="text-sm text-red-600">
                                        {error}
                                    </p>
                                )}
                                <Button disabled={busy} type="submit">
                                    {busy ? 'Saving…' : 'Save confirmed action'}
                                </Button>
                            </form>
                        )}
                    </DialogContent>
                </Dialog>
            </main>
        </AppLayout>
    );
}
