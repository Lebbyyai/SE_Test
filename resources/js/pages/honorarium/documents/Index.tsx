import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { csrfHeaders } from '@/lib/csrf';
import { Head, router, usePage } from '@inertiajs/react';
import { CalendarDays, CheckCircle2, Clock3, FileCheck2, FileText, Send, XCircle } from 'lucide-react';
import { useMemo, useState } from 'react';

type Doc = {
    id: number | null;
    status: string;
    ready_on?: string | null;
    submitted_at?: string | null;
    finance_notes?: string | null;
    payload?: { panel_names?: string[]; panel_details?: { name: string; role: string; affiliation: string }[] } | null;
};
type Item = {
    can_send: boolean;
    id: number;
    student_name: string;
    student_number?: string | null;
    program?: string | null;
    defense_type: string;
    workflow_state: string;
    receipt_url: string;
    panel_names: string[];
    document1: Doc;
    document2: Doc;
};
type Activity = {
    id: number;
    date: string;
    occurred_at: string;
    title: string;
    description?: string | null;
    activity_type: string;
    actor: string;
    defense_request_id?: number | null;
};
type Props = {
    errors?: Record<string, string>;
    role: string;
    canSubmit: boolean;
    canReview: boolean;
    items: Item[];
    activities: Activity[];
    today: string;
    flash?: { success?: string };
};
const labels: Record<string, string> = {
    locked: 'Locked until next day',
    ready: 'Ready to send',
    submitted: 'Sent to Finance',
    verified: 'Verified',
    rejected: 'Rejected',
    not_created: 'Waiting for Document 1',
};
const docName = (type: 'one' | 'two') => (type === 'one' ? 'Document 1 · Payment receipt' : 'Document 2 · Panel names / honorarium breakdown');

export default function FinanceDocumentsPage() {
    const { errors = {}, role, canSubmit, canReview, items = [], activities = [], today, flash } = usePage<Props>().props;
    const [tab, setTab] = useState<'documents' | 'calendar'>('documents');
    const [notes, setNotes] = useState<Record<number, string>>({});
    const [month, setMonth] = useState(`${today.slice(0, 7)}-01`);
    const [selectedDate, setSelectedDate] = useState(today);
    const monthDate = new Date(`${month}T12:00:00`);
    const monthDays = new Date(monthDate.getFullYear(), monthDate.getMonth() + 1, 0).getDate();
    const startOffset = (new Date(monthDate.getFullYear(), monthDate.getMonth(), 1).getDay() + 6) % 7;
    const byDate = useMemo(
        () =>
            activities.reduce<Record<string, Activity[]>>((acc, a) => {
                (acc[a.date] ??= []).push(a);
                return acc;
            }, {}),
        [activities],
    );
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState('');
    async function act(url: string, payload: Record<string, string> = {}) {
        if (busy) return;
        setBusy(true);
        setActionError('');
        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: csrfHeaders(true),
                body: JSON.stringify(payload),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok)
                throw new Error(
                    Object.values(data.errors ?? {})
                        .flat()
                        .join(' ') ||
                        data.message ||
                        'Could not save this action.',
                );
            router.reload({ onFinish: () => setBusy(false) });
        } catch (error) {
            setActionError(error instanceof Error ? error.message : 'Could not connect. Please try again.');
            setBusy(false);
        }
    }
    const review = (doc: Doc, status: 'verified' | 'rejected') => {
        if (!doc.id) return;
        if (status === 'rejected' && !notes[doc.id]?.trim()) {
            setActionError('Enter a rejection reason first.');
            return;
        }
        void act('/honorarium-documents/finance/' + doc.id + '/review', { status, finance_notes: notes[doc.id] ?? '' });
    };
    const submit = (item: Item, type: 'one' | 'two') => void act('/honorarium-documents/' + item.id + '/submit-' + type);
    const documentCard = (item: Item, doc: Doc, type: 'one' | 'two') => (
        <div className="rounded-lg border p-3" key={`${item.id}-${type}`}>
            <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="font-medium">{docName(type)}</p>
                        <Badge variant={doc.status === 'verified' ? 'default' : doc.status === 'rejected' ? 'destructive' : 'secondary'}>
                            {labels[doc.status] ?? doc.status}
                        </Badge>
                    </div>
                    <p className="text-muted-foreground mt-1 text-xs">
                        {doc.ready_on ? `Ready date: ${doc.ready_on}` : ''}
                        {doc.submitted_at ? ` · Submitted: ${doc.submitted_at}` : ''}
                    </p>
                    {type === 'one' && (
                        <a className="mt-2 inline-flex items-center gap-1 text-sm underline" href={item.receipt_url} target="_blank" rel="noreferrer">
                            <FileText className="size-4" />
                            View student receipt
                        </a>
                    )}
                    {type === 'two' && (
                        <p className="mt-2 text-sm">
                            Panel names: {(doc.payload?.panel_names ?? item.panel_names).join(', ') || 'No panel names recorded'}
                        </p>
                    )}
                    {doc.finance_notes && <p className="bg-muted mt-2 rounded p-2 text-sm">Finance note: {doc.finance_notes}</p>}
                    {type === 'two' &&
                        doc.payload?.panel_details?.map((person, index) => (
                            <p key={index} className="text-muted-foreground mt-1 text-xs">
                                {person.name} · {person.role} · {person.affiliation}
                            </p>
                        ))}
                </div>
                <div className="flex flex-wrap gap-2">
                    {canSubmit && item.can_send && type === 'one' && ['ready', 'rejected'].includes(doc.status) && (
                        <Button disabled={busy} onClick={() => submit(item, 'one')} className="gap-2">
                            <Send className="size-4" />
                            Send Document 1
                        </Button>
                    )}
                    {canSubmit &&
                        item.can_send &&
                        item.document1.status === 'verified' &&
                        type === 'two' &&
                        ['ready', 'rejected'].includes(doc.status) && (
                            <Button onClick={() => submit(item, 'two')} className="gap-2">
                                <Send className="size-4" />
                                Send Document 2
                            </Button>
                        )}
                    {canReview && doc.id && doc.status === 'submitted' && (
                        <>
                            <Button disabled={busy} onClick={() => review(doc, 'verified')} className="gap-1">
                                <CheckCircle2 className="size-4" />
                                Verify
                            </Button>
                            <Button variant="destructive" onClick={() => review(doc, 'rejected')} className="gap-1">
                                <XCircle className="size-4" />
                                Reject
                            </Button>
                        </>
                    )}
                </div>
            </div>
            {canReview && doc.id && doc.status === 'submitted' && (
                <Textarea
                    className="mt-3"
                    value={notes[doc.id] ?? ''}
                    onChange={(e) => setNotes((old) => ({ ...old, [doc.id!]: e.target.value }))}
                    placeholder="Optional verification note; required if rejecting"
                />
            )}
        </div>
    );

    return (
        <AppLayout breadcrumbs={[{ title: 'Finance Documents', href: '/honorarium-documents' }]}>
            <Head title="Finance Document Handoff" />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-5 p-4 md:p-8">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-muted-foreground text-sm">Honorarium Tracking System</p>
                        <h1 className="text-3xl font-bold">Finance Document Handoff</h1>
                        <p className="text-muted-foreground text-sm">Document 1 is sent first. Document 2 unlocks on the next calendar day.</p>
                    </div>
                    <Badge variant="outline" className="w-fit capitalize">
                        {role}
                    </Badge>
                </div>
                {actionError && (
                    <p role="alert" className="rounded border border-red-300 p-3 text-sm text-red-700">
                        {actionError}
                    </p>
                )}
                {Object.keys(errors).length > 0 && (
                    <div role="alert" className="rounded-lg border border-red-200 p-3 text-sm text-red-700">
                        {Object.values(errors).join(' ')}
                    </div>
                )}
                {flash?.success && (
                    <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                        {flash.success}
                    </div>
                )}
                <div className="flex gap-2 border-b pb-3">
                    <Button variant={tab === 'documents' ? 'default' : 'outline'} onClick={() => setTab('documents')}>
                        <FileCheck2 className="mr-2 size-4" />
                        Documents
                    </Button>
                    <Button variant={tab === 'calendar' ? 'default' : 'outline'} onClick={() => setTab('calendar')}>
                        <CalendarDays className="mr-2 size-4" />
                        AA Activity Calendar
                    </Button>
                </div>
                {tab === 'documents' && (
                    <div className="space-y-4">
                        {items.length === 0 && (
                            <Card>
                                <CardContent className="text-muted-foreground p-8 text-center text-sm">
                                    No defense requests with payment receipts were found.
                                </CardContent>
                            </Card>
                        )}
                        {items.map((item) => (
                            <Card key={item.id}>
                                <CardHeader>
                                    <CardTitle className="text-lg">
                                        {item.student_name} <span className="text-muted-foreground text-sm font-normal">· Request #{item.id}</span>
                                    </CardTitle>
                                    <p className="text-muted-foreground text-sm">
                                        {[item.student_number, item.program, item.defense_type].filter(Boolean).join(' · ')}
                                    </p>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    {documentCard(item, item.document1, 'one')}
                                    {documentCard(item, item.document2, 'two')}
                                    <p className="text-muted-foreground text-xs">
                                        Rule: after Document 1 is sent, Document 2 is scheduled to unlock at the start of the next local calendar day.
                                        Finance reviews each document separately.
                                    </p>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
                {tab === 'calendar' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>AA Activity Calendar</CardTitle>
                            <p className="text-muted-foreground text-sm">
                                Submission events show the AA's actual action date. “Document ready” is shown separately and is not counted as a
                                submission.
                            </p>
                        </CardHeader>
                        <CardContent>
                            <div className="mb-4 flex items-center justify-between gap-3">
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        const d = new Date(`${month}T12:00:00`);
                                        d.setMonth(d.getMonth() - 1);
                                        setMonth(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`);
                                    }}
                                >
                                    Previous
                                </Button>
                                <h2 className="font-semibold">{monthDate.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })}</h2>
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        const d = new Date(`${month}T12:00:00`);
                                        d.setMonth(d.getMonth() + 1);
                                        setMonth(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`);
                                    }}
                                >
                                    Next
                                </Button>
                            </div>
                            <div className="text-muted-foreground grid grid-cols-7 gap-1 text-center text-xs">
                                {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => (
                                    <div key={d} className="p-2">
                                        {d}
                                    </div>
                                ))}
                            </div>
                            <div className="grid grid-cols-7 gap-1">
                                {Array.from({ length: startOffset }, (_, i) => (
                                    <div key={`blank-${i}`} />
                                ))}
                                {Array.from({ length: monthDays }, (_, i) => {
                                    const day = String(i + 1).padStart(2, '0');
                                    const date = `${month.slice(0, 7)}-${day}`;
                                    const list = byDate[date] ?? [];
                                    return (
                                        <button
                                            key={date}
                                            onClick={() => setSelectedDate(date)}
                                            className={`min-h-20 rounded-md border p-1 text-left ${selectedDate === date ? 'border-primary bg-muted' : 'border-border'}`}
                                        >
                                            <span className="text-sm">{i + 1}</span>
                                            {list.slice(0, 2).map((a) => (
                                                <span
                                                    key={a.id}
                                                    className={`mt-1 block truncate rounded px-1 py-0.5 text-[10px] ${a.activity_type === 'document_submitted' ? 'bg-primary/10' : a.activity_type === 'document_ready' ? 'bg-amber-100 text-amber-900' : 'bg-muted'}`}
                                                >
                                                    {a.title}
                                                </span>
                                            ))}
                                            {list.length > 2 && <span className="text-[10px]">+{list.length - 2} more</span>}
                                        </button>
                                    );
                                })}
                            </div>
                            <div className="mt-5">
                                <h3 className="mb-2 font-semibold">Activity on {selectedDate}</h3>
                                {(byDate[selectedDate] ?? []).length === 0 ? (
                                    <p className="text-muted-foreground rounded border border-dashed p-5 text-sm">
                                        No recorded activity on this date.
                                    </p>
                                ) : (
                                    <div className="space-y-2">
                                        {byDate[selectedDate].map((a) => (
                                            <div key={a.id} className="rounded-lg border p-3">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-medium">{a.title}</span>
                                                    <Badge variant="outline">{a.activity_type.replaceAll('_', ' ')}</Badge>
                                                </div>
                                                <p className="mt-1 text-sm">{a.description}</p>
                                                <p className="text-muted-foreground mt-1 text-xs">
                                                    {a.occurred_at} · By: {a.actor}
                                                    {a.defense_request_id ? ` · Request #${a.defense_request_id}` : ''}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}
                <p className="text-muted-foreground flex items-center gap-2 text-xs">
                    <Clock3 className="size-4" />
                    If the scheduler is not running, opening this page also checks and unlocks due documents.
                </p>
            </main>
        </AppLayout>
    );
}
