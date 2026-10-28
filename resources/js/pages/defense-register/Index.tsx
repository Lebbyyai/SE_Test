import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { csrfHeaders } from '@/lib/csrf';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Person = {
    recipient_key: string;
    recipient_name: string;
    recipient_role: string;
    affiliation: 'internal' | 'external' | null;
    attendance: 'pending' | 'rendered' | 'absent';
};
type Previous = { completed_on: string; confirmed_by: number; confirmed_at: string; notes?: string; participants: Person[] };
type Item = {
    id: number;
    student: string;
    school_id: string;
    title: string;
    program: string;
    defense_type: string;
    date: string;
    start: string;
    end: string;
    mode?: string;
    venue?: string;
    adviser?: string;
    participants: Person[];
    service_status: string;
    completed_on?: string;
    has_record: boolean;
    can_confirm: boolean;
    roster_error?: string;
    history: Previous[];
};
type Props = { items: Item[]; from: string; to: string; today: string };
const labels: Record<string, string> = {
    pending: 'Awaiting confirmation',
    confirmed: 'Service confirmed',
    'needs-reconfirmation': 'Information changed: confirm again',
};

export default function DefenseRegister() {
    const { items, from, to, today } = usePage<Props>().props;
    const filter = useForm({ from, to });
    const [selected, setSelected] = useState<Item | null>(null);
    const [people, setPeople] = useState<Person[]>([]);
    const [completedOn, setCompletedOn] = useState(today);
    const [held, setHeld] = useState(false);
    const [notes, setNotes] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const query = new URLSearchParams({ from, to }).toString();
    const changedTypes =
        selected &&
        people.some(
            (person) => selected.participants.find((original) => original.recipient_key === person.recipient_key)?.affiliation !== person.affiliation,
        );
    const classified = people.filter((p) => p.recipient_role !== 'Adviser').every((p) => p.affiliation);

    function open(item: Item) {
        setSelected(item);
        setPeople(item.participants.map((person) => ({ ...person })));
        setCompletedOn(item.completed_on ?? (item.date <= today ? item.date : today));
        setHeld(false);
        setNotes('');
        setError('');
        setMessage('');
    }
    async function save(classify: boolean) {
        if (!selected || busy) return;
        setBusy(true);
        setError('');
        setMessage('');
        try {
            const response = await fetch(`/defense-register/${selected.id}/${classify ? 'panel-types' : 'confirm-service'}`, {
                method: classify ? 'PATCH' : 'POST',
                credentials: 'same-origin',
                headers: csrfHeaders(true),
                body: JSON.stringify({
                    notes,
                    held,
                    completed_on: completedOn,
                    participants: (classify ? people.filter((p) => p.recipient_role !== 'Adviser') : people).map((p) => ({
                        recipient_key: p.recipient_key,
                        affiliation: p.affiliation,
                        attendance: p.attendance,
                    })),
                }),
            });
            if (response.status === 419) throw new Error('Your session expired. Refresh the page and sign in again.');
            const body = await response.json().catch(() => ({}));
            if (!response.ok)
                throw new Error(
                    Object.values(body.errors ?? {})
                        .flat()
                        .join(' ') ||
                        body.message ||
                        'Could not save. Please try again.',
                );
            setMessage(body.message);
            setSelected(null);
            router.reload({ onFinish: () => setBusy(false) });
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : 'Connection interrupted. Please try again.');
            setBusy(false);
        }
    }
    return (
        <AppLayout breadcrumbs={[{ title: 'Defense Register', href: '/defense-register' }]}>
            <Head title="Defense Register" />
            <main className="mx-auto w-full max-w-7xl space-y-5 p-4 [color-scheme:light] md:p-8 dark:[color-scheme:dark]">
                <div>
                    <h1 className="text-2xl font-semibold">Defense Register</h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Export the defense schedule, classify panelists, and confirm service after each session.
                    </p>
                    <div className="mt-2 flex gap-4 text-sm underline">
                        <Link href="/schedule">Calendar</Link>
                        <Link href="/honorarium-ledger">Honorarium payments</Link>
                        <Link href="/honorarium-documents">Finance documents</Link>
                    </div>
                </div>
                {error && !selected && (
                    <p role="alert" className="rounded border p-3 text-red-600 dark:text-red-400">
                        {error}
                    </p>
                )}
                {message && (
                    <p role="status" className="rounded border p-3 text-green-700 dark:text-green-400">
                        {message}
                    </p>
                )}
                <form
                    className="flex flex-wrap items-end gap-3 rounded-lg border p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        filter.clearErrors();
                        const days = (Date.parse(filter.data.to) - Date.parse(filter.data.from)) / 86400000;
                        if (!Number.isFinite(days) || days < 0 || days > 92) {
                            filter.setError('to', 'Choose a range of no more than 93 days, with the end on or after the start.');
                            return;
                        }
                        filter.get('/defense-register', { preserveScroll: true, preserveState: true });
                    }}
                >
                    <label className="text-sm">
                        From
                        <Input type="date" required value={filter.data.from} onChange={(event) => filter.setData('from', event.target.value)} />
                    </label>
                    <label className="text-sm">
                        To
                        <Input
                            type="date"
                            required
                            min={filter.data.from}
                            value={filter.data.to}
                            onChange={(event) => filter.setData('to', event.target.value)}
                        />
                    </label>
                    <Button disabled={filter.processing}>Apply dates</Button>
                    <a
                        href={`/defense-register/export?${query}`}
                        className="hover:bg-accent inline-flex h-10 items-center rounded-lg border px-3 text-sm"
                    >
                        Download Excel
                    </a>
                    <a
                        href={`/defense-register/print?${query}`}
                        target="_blank"
                        rel="noreferrer"
                        className="hover:bg-accent inline-flex h-10 items-center rounded-lg border px-3 text-sm"
                    >
                        Print / Save as PDF
                    </a>
                    {(filter.errors.from || filter.errors.to) && (
                        <p role="alert" className="w-full text-sm text-red-600 dark:text-red-400">
                            {filter.errors.from || filter.errors.to}
                        </p>
                    )}
                    <p className="text-muted-foreground w-full text-xs">
                        Exports use the displayed date range ({from} to {to}), in Philippine time. Maximum 93 days. Simultaneous sessions remain
                        separate entries.
                    </p>
                </form>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted">
                            <tr>
                                {['Schedule', 'Student / defense', 'Committee', 'Service', 'Action'].map((label) => (
                                    <th key={label} className="p-3">
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((item) => (
                                <tr key={item.id} className="border-t align-top">
                                    <td className="min-w-36 p-3">
                                        <p>{item.date}</p>
                                        <p>
                                            {item.start}–{item.end || 'End unspecified'}
                                        </p>
                                        <p className="text-muted-foreground mt-1">
                                            {item.mode || 'Mode unspecified'}
                                            <br />
                                            {item.venue || 'Venue unspecified'}
                                        </p>
                                    </td>
                                    <td className="min-w-52 p-3">
                                        <p className="font-medium">
                                            {item.student || 'Name unavailable'} · #{item.id}
                                        </p>
                                        <p>
                                            {item.program} · {item.defense_type}
                                        </p>
                                        <p className="text-muted-foreground mt-1">{item.title}</p>
                                    </td>
                                    <td className="min-w-56 p-3">
                                        {item.participants.map((person) => (
                                            <p key={person.recipient_key} className="mb-2">
                                                {person.recipient_name}
                                                <span className="text-muted-foreground block text-xs">
                                                    {person.recipient_role}
                                                    {person.recipient_role !== 'Adviser' && ` · ${person.affiliation ?? 'Unclassified'}`} ·{' '}
                                                    {person.attendance === 'pending' ? 'Service unconfirmed' : person.attendance}
                                                </span>
                                            </p>
                                        ))}
                                        {item.roster_error && <p className="text-red-600 dark:text-red-400">{item.roster_error}</p>}
                                    </td>
                                    <td className="min-w-40 p-3">
                                        {labels[item.service_status]}
                                        {item.completed_on && <p className="mt-1 text-xs">Completed {item.completed_on}</p>}
                                    </td>
                                    <td className="p-3">
                                        {item.can_confirm ? (
                                            <Button size="sm" variant="outline" onClick={() => open(item)}>
                                                Panel types / service
                                            </Button>
                                        ) : (
                                            <span className="text-muted-foreground">View only</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {items.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground p-8 text-center">
                                        No scheduled defenses in this date range. Change the dates or schedule an approved request first.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <p className="text-muted-foreground text-sm">
                    A schedule is not proof of attendance or payment. Only recipients confirmed as having rendered service are included when honoraria
                    are prepared. External recipients require their own configured rates.
                </p>
            </main>
            <Dialog
                open={!!selected}
                onOpenChange={(open) => {
                    if (!open && !busy) setSelected(null);
                }}
            >
                <DialogContent className="max-h-[90svh] overflow-y-auto [color-scheme:light] sm:max-w-2xl dark:[color-scheme:dark]">
                    <DialogTitle>Panel classifications and service</DialogTitle>
                    <DialogDescription>
                        {selected?.student} · {selected?.date} · {selected?.title}
                    </DialogDescription>
                    <div className="space-y-4">
                        {error && (
                            <p role="alert" className="rounded border p-3 text-sm text-red-600 dark:text-red-400">
                                {error}
                            </p>
                        )}
                        <p className="text-muted-foreground text-sm">
                            Save internal/external classifications before Finance reviews the panel breakdown. Changing a classification requires
                            Finance re-verification and makes earlier service evidence stale.
                        </p>
                        {people.map((person, index) => (
                            <div key={person.recipient_key} className="space-y-2 rounded border p-3">
                                <p className="font-medium">
                                    {person.recipient_name} <span className="text-muted-foreground text-sm">({person.recipient_role})</span>
                                </p>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {person.recipient_role !== 'Adviser' && (
                                        <label className="text-sm">
                                            Panel classification
                                            <select
                                                aria-label={`Classification for ${person.recipient_name}`}
                                                className="bg-background mt-1 w-full rounded border p-2"
                                                value={person.affiliation ?? ''}
                                                onChange={(event) =>
                                                    setPeople((previous) =>
                                                        previous.map((p, i) =>
                                                            i === index
                                                                ? { ...p, affiliation: (event.target.value || null) as Person['affiliation'] }
                                                                : p,
                                                        ),
                                                    )
                                                }
                                            >
                                                <option value="">Select internal / external</option>
                                                <option value="internal">Internal</option>
                                                <option value="external">External</option>
                                            </select>
                                        </label>
                                    )}
                                    <label className="text-sm">
                                        Service attendance
                                        <select
                                            aria-label={`Service for ${person.recipient_name}`}
                                            className="bg-background mt-1 w-full rounded border p-2"
                                            value={person.attendance}
                                            onChange={(event) =>
                                                setPeople((previous) =>
                                                    previous.map((p, i) =>
                                                        i === index ? { ...p, attendance: event.target.value as Person['attendance'] } : p,
                                                    ),
                                                )
                                            }
                                        >
                                            <option value="pending">Not yet confirmed</option>
                                            <option value="rendered">Rendered service</option>
                                            <option value="absent">Absent / did not render service</option>
                                        </select>
                                    </label>
                                </div>
                            </div>
                        ))}
                        <label className="block text-sm">
                            Notes / reason for revision
                            <textarea
                                maxLength={2000}
                                className="bg-background mt-1 min-h-20 w-full rounded border p-2"
                                value={notes}
                                onChange={(event) => setNotes(event.target.value)}
                                placeholder="A reason is required when revising existing classifications or confirmed service."
                            />
                        </label>
                        <Button type="button" variant="outline" disabled={busy || !classified || !changedTypes} onClick={() => void save(true)}>
                            Save panel classifications
                        </Button>
                        <div className="space-y-3 border-t pt-4">
                            <label className="block text-sm">
                                Actual completion date
                                <Input
                                    type="date"
                                    min={selected?.date}
                                    max={today}
                                    value={completedOn}
                                    onChange={(event) => setCompletedOn(event.target.value)}
                                />
                            </label>
                            <label className="flex items-start gap-2 text-sm">
                                <input type="checkbox" className="mt-1" checked={held} onChange={(event) => setHeld(event.target.checked)} />I confirm
                                the defense was held and the attendance recorded above is correct.
                            </label>
                            <p className="text-muted-foreground text-xs">
                                Confirm after the scheduled session ends. This marks the defense completed. Revisions preserve prior evidence, void
                                unpaid honoraria, and flag paid records for Finance review.
                            </p>
                            <Button
                                disabled={
                                    busy || !held || !classified || !!changedTypes || people.some((p) => p.attendance === 'pending') || !completedOn
                                }
                                onClick={() => void save(false)}
                            >
                                Confirm completed defense and service
                            </Button>
                        </div>
                        {!!selected?.history.length && (
                            <details>
                                <summary className="cursor-pointer text-sm">Previous confirmations ({selected.history.length})</summary>
                                {selected.history.map((entry, index) => (
                                    <div key={index} className="mt-2 rounded border p-3 text-xs">
                                        <p>
                                            {entry.completed_on} · Confirmed by account #{entry.confirmed_by} at {entry.confirmed_at}
                                        </p>
                                        <p>{entry.notes}</p>
                                        {entry.participants.map((p) => (
                                            <p key={p.recipient_key}>
                                                {p.recipient_name}: {p.affiliation ?? p.recipient_role}, {p.attendance}
                                            </p>
                                        ))}
                                    </div>
                                ))}
                            </details>
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
