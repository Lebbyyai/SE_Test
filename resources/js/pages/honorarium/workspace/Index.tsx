import HonorariumRecordFilter from '@/components/honorarium-record-filter';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarDays, ChevronDown, CircleDollarSign, Clock3, FileCheck2, FileText, Search, Send } from 'lucide-react';
import { useEffect, useState } from 'react';

type FinanceDocument = {
    type: string;
    status: string;
    submitted_at?: string | null;
    submitted_by?: string | null;
    reviewed_at?: string | null;
    reviewed_by?: string | null;
    reviewer_role?: string | null;
    finance_notes?: string | null;
};
type HonorariumRecipient = {
    name: string;
    role: string;
    status: string;
    requires_review?: boolean;
    school_year: string;
    semester: string;
    amount: number;
    updated_at?: string | null;
};
type FollowUp = { id: number; recipient_role: string; notes: string; actor: string; occurred_at?: string | null };
type Item = {
    archived: boolean;
    faculty: string[];
    periods: { school_year: string; semester: string }[];
    requested_at?: string | null;
    progress: { percent: number; label: string; steps: { label: string; done: boolean }[] };
    id: number;
    student_name: string;
    student_number?: string | null;
    program?: string | null;
    defense_type: string;
    workflow_state: string;
    scheduled_date?: string | null;
    scheduled_time?: string | null;
    receipt_url?: string | null;
    panel_names: string[];
    faculty_names: string[];
    finance_status: string;
    finance_documents: FinanceDocument[];
    honorarium_recipients: HonorariumRecipient[];
    school_year?: string | null;
    semester?: string | null;
    progress_stage: number;
    progress_percent: number;
    follow_ups: FollowUp[];
    submitted_at?: string | null;
    last_updated_at?: string | null;
};
type Rate = { id: number; program_level: string; type: string; defense_type?: string | null; amount: number };
type ProgramReport = {
    program: string;
    school_year: string;
    semester: string;
    recipients_count: number;
    total_amount: number;
    prepared_amount: number;
    approved_amount: number;
    disbursed_amount: number;
    claimed_amount: number;
};
type Props = {
    canManageDocuments: boolean;
    role: string;
    canSubmitToFinance: boolean;
    items: Item[];
    rates: Rate[];
    facultyOptions: string[];
    terms: { school_year: string; semester: string; value: string }[];
    canFollowUp: boolean;
    canViewReports: boolean;
    programReports: ProgramReport[];
    flash?: { success?: string };
};

const statusLabel: Record<string, string> = {
    not_submitted: 'Not sent to Finance',
    submitted: 'Awaiting Finance',
    verified: 'Finance verified',
    rejected: 'Finance rejected',
};
const progressSteps = ['Receipt', 'Panel breakdown', 'Prepare', 'Approve', 'Disburse', 'Claim', 'Complete'];
const formatDate = (value?: string | null) =>
    value ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' }).format(new Date(value)) : '—';

function currentAction(item: Item): { owner: string; action: string } {
    if (item.progress.label === 'Requires Finance review') return { owner: 'Finance', action: 'Review changed payment records' };
    const receipt = item.finance_documents.find((document) => document.type === 'payment_receipt');
    const breakdown = item.finance_documents.find((document) => document.type === 'panel_breakdown');

    if (!receipt || ['ready', 'rejected'].includes(receipt.status)) return { owner: 'AA', action: 'Send receipt to Finance' };
    if (receipt.status === 'submitted') return { owner: 'Finance', action: 'Verify receipt' };
    if (!breakdown || ['locked', 'ready', 'rejected'].includes(breakdown.status)) return { owner: 'AA', action: 'Send panel breakdown' };
    if (breakdown.status === 'submitted') return { owner: 'Finance', action: 'Verify panel breakdown' };
    if (!item.honorarium_recipients.length) return { owner: 'AA', action: 'Prepare honorarium records' };
    if (item.progress_stage === 5) return { owner: 'Dean / Finance', action: 'Approve honorarium records' };
    if (item.progress_stage === 6) return { owner: 'Finance', action: 'Record disbursement' };
    if (item.progress_stage === 7) return { owner: 'Recipients', action: 'Record claims' };

    return { owner: 'Complete', action: 'All honorarium steps are complete' };
}

function followUpRoleForUser(role: string): string | null {
    switch (role.trim().toLowerCase()) {
        case 'finance':
        case 'finance office':
        case 'finance officer':
            return 'Finance';
        case 'administrative assistant':
        case 'assistant':
        case 'aa':
            return 'Administrative Assistant';
        case 'dean':
            return 'Dean';
        default:
            return null;
    }
}

function needsFollowUp(followUp: FollowUp, viewerRole: string): boolean {
    const viewerFollowUpRole = followUpRoleForUser(viewerRole);
    return viewerFollowUpRole !== null && followUp.recipient_role === viewerFollowUpRole;
}

function stepIndex(stage: number): number {
    if (stage <= 1) return 0;
    if (stage <= 3) return 1;
    if (stage === 4) return 2;
    if (stage === 5) return 3;
    if (stage === 6) return 4;
    if (stage === 7) return 5;

    return 6;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Honorarium Tracking', href: '/honorarium-workspace' }];

export default function HonorariumWorkspace() {
    const {
        canManageDocuments,
        role,
        canSubmitToFinance,
        items = [],
        rates = [],
        facultyOptions = [],
        terms = [],
        canFollowUp,
        canViewReports,
        programReports = [],
        flash,
    } = usePage<Props>().props;
    const [tab, setTab] = useState<'workflow' | 'schedule' | 'rates' | 'records' | 'reports'>('workflow');
    const [archived, setArchived] = useState(false);
    const active = items.filter((item) => !item.archived);
    const categoryItems = items.filter((item) => item.archived === archived);
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [facultyFilter, setFacultyFilter] = useState('all');
    const [termFilter, setTermFilter] = useState('all');
    const [programFilter, setProgramFilter] = useState('all');
    const programOptions = [...new Set(categoryItems.map((item) => item.program).filter(Boolean))] as string[];
    const [followUpItem, setFollowUpItem] = useState<Item | null>(null);
    const [followUpRecipient, setFollowUpRecipient] = useState('Finance');
    const [followUpNotes, setFollowUpNotes] = useState('');
    const [followUpError, setFollowUpError] = useState('');
    useEffect(() => {
        const interval = window.setInterval(() => {
            if (document.visibilityState === 'visible') router.reload({ only: ['items', 'facultyOptions', 'terms', 'programReports'] });
        }, 30_000);

        return () => window.clearInterval(interval);
    }, []);
    const filtered = categoryItems.filter(
        (item) =>
            `${item.student_name} ${item.student_number ?? ''} ${item.program ?? ''} ${item.defense_type}`
                .toLowerCase()
                .includes(search.toLowerCase()) &&
            (facultyFilter === 'all' || item.faculty_names.includes(facultyFilter)) &&
            (termFilter === 'all' || item.periods.some((period) => `${period.school_year}|${period.semester}` === termFilter)) &&
            (programFilter === 'all' || item.program === programFilter) &&
            (statusFilter === 'all' ||
                (statusFilter === 'awaiting' && item.finance_status === 'submitted') ||
                (statusFilter === 'verified' && item.finance_status === 'verified') ||
                (statusFilter === 'rejected' && item.finance_status === 'rejected') ||
                (statusFilter === 'receipts' && Boolean(item.receipt_url))),
    );
    function exportTracking() {
        const cell = (value: string) => '"' + (/^[=+\-@]/.test(value) ? "'" + value : value).replaceAll('"', '""') + '"';
        const rows = [
            [
                'Request',
                'Student',
                'Program',
                'Defense',
                'Faculty',
                'School year / semester',
                'Defense submitted (PH)',
                'Latest Finance handoff (PH)',
                'Finance status',
                'Progress',
                'Next action',
            ],
            ...filtered.map((item) => [
                String(item.id),
                item.student_name,
                item.program ?? '',
                item.defense_type,
                item.faculty.join('; '),
                item.periods.map((period) => `${period.school_year} / ${period.semester}`).join('; ') || 'Not recorded',
                formatDate(item.requested_at),
                formatDate(item.submitted_at),
                statusLabel[item.finance_status],
                `${item.progress.percent}%`,
                item.progress.label,
            ]),
        ];
        const url = URL.createObjectURL(
            new Blob(['\uFEFF' + rows.map((row) => row.map(cell).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8;' }),
        );
        const link = document.createElement('a');
        link.href = url;
        link.download = 'honorarium-tracking.csv';
        link.click();
        URL.revokeObjectURL(url);
    }
    const submit = (item: Item) => router.post(`/honorarium-workspace/${item.id}/submit-to-finance`, {}, { preserveScroll: true });
    const openFollowUp = (item: Item) => {
        setFollowUpItem(item);
        setFollowUpRecipient('Finance');
        setFollowUpNotes('');
        setFollowUpError('');
    };
    const sendFollowUp = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!followUpItem) return;

        router.post(
            `/honorarium-workspace/${followUpItem.id}/follow-ups`,
            { recipient_role: followUpRecipient, notes: followUpNotes },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setFollowUpItem(null);
                    setFollowUpNotes('');
                    setFollowUpError('');
                },
                onError: (errors) => {
                    setFollowUpError(errors.recipient_role ?? errors.notes ?? 'Unable to send follow-up. Please try again.');
                },
            },
        );
    };
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Honorarium Tracking" />
            {['Coordinator', 'Administrative Assistant', 'Dean', 'Finance', 'Super Admin', 'SuperAdmin'].includes(role) && (
                <div className="px-6 pt-4">
                    <a className="inline-flex rounded-md border px-3 py-2 text-sm" href="/comprehensive-exam/assignments.csv">
                        Download comprehensive exam faculty assignments (CSV)
                    </a>
                </div>
            )}
            <main className="mx-auto flex w-full max-w-[1600px] flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <div>
                            <h1 className="text-2xl font-semibold tracking-tight">Honorarium</h1>
                            <p className="text-muted-foreground text-sm">Defense request tracking</p>
                        </div>
                        <Badge variant="outline" className="capitalize">
                            {role}
                        </Badge>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canManageDocuments && (
                            <Button asChild variant="outline" size="sm">
                                <Link href="/honorarium-documents">Finance documents</Link>
                            </Button>
                        )}
                        <Button asChild variant="outline" size="sm">
                            <Link href="/honorarium-ledger">Payment ledger</Link>
                        </Button>
                    </div>
                </div>
                {flash?.success && (
                    <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                        {flash.success}
                    </div>
                )}
                <div className="flex flex-wrap gap-2 border-b pb-3">
                    {(
                        [
                            { id: 'workflow', label: 'Workflow', icon: FileCheck2 },
                            { id: 'schedule', label: 'Schedule board', icon: CalendarDays },
                            { id: 'rates', label: 'Payment rates', icon: CircleDollarSign },
                            { id: 'records', label: 'Records', icon: FileText },
                            ...(canViewReports ? [{ id: 'reports' as const, label: 'Program reports', icon: FileCheck2 }] : []),
                        ] as const
                    ).map((t) => (
                        <Button key={t.id} variant={tab === t.id ? 'default' : 'outline'} onClick={() => setTab(t.id)} className="gap-2">
                            <t.icon className="size-4" />
                            {t.label}
                        </Button>
                    ))}
                </div>
                <HonorariumRecordFilter
                    archived={archived}
                    onChange={setArchived}
                    activeCount={active.length}
                    archivedCount={items.length - active.length}
                />
                {tab === 'workflow' && (
                    <Card className="overflow-hidden">
                        <CardHeader className="pb-4">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <CardTitle className="text-base">Defense requests</CardTitle>
                                <Button variant="outline" size="sm" onClick={exportTracking}>
                                    Export displayed CSV
                                </Button>
                                <span className="text-muted-foreground text-sm">{filtered.length} requests</span>
                            </div>
                            <div className="grid gap-2 pt-2 md:grid-cols-2 xl:grid-cols-4">
                                <div className="relative">
                                    <Search className="text-muted-foreground absolute top-2.5 left-3 size-4" />
                                    <Input
                                        className="h-9 pl-9"
                                        placeholder="Search student or program"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                    />
                                </div>
                                <select
                                    className="bg-background h-9 rounded-md border px-3 text-sm"
                                    aria-label="Filter by faculty"
                                    value={facultyFilter}
                                    onChange={(e) => setFacultyFilter(e.target.value)}
                                >
                                    <option value="all">All faculty</option>
                                    {facultyOptions.map((faculty) => (
                                        <option key={faculty} value={faculty}>
                                            {faculty}
                                        </option>
                                    ))}
                                </select>
                                <select
                                    className="bg-background h-9 rounded-md border px-3 text-sm"
                                    aria-label="Filter by semester"
                                    value={termFilter}
                                    onChange={(e) => setTermFilter(e.target.value)}
                                >
                                    <option value="all">All semesters</option>
                                    {terms.map((term) => (
                                        <option key={term.value} value={term.value}>
                                            {term.school_year} · {term.semester}
                                        </option>
                                    ))}
                                </select>
                                <select
                                    className="bg-background h-9 rounded-md border px-3 text-sm"
                                    aria-label="Filter by honorarium status"
                                    value={statusFilter}
                                    onChange={(e) => setStatusFilter(e.target.value)}
                                >
                                    <option value="all">All statuses</option>
                                    <option value="awaiting">Awaiting Finance</option>
                                    <option value="verified">Verified</option>
                                    <option value="receipts">Receipts Available</option>
                                    <option value="rejected">Returned by Finance</option>
                                </select>
                            </div>
                            <select
                                className="bg-background h-9 rounded-md border px-3 text-sm"
                                aria-label="Filter by program"
                                value={programFilter}
                                onChange={(event) => setProgramFilter(event.target.value)}
                            >
                                <option value="all">All programs</option>
                                {programOptions.map((program) => (
                                    <option key={program} value={program}>
                                        {program}
                                    </option>
                                ))}
                            </select>
                            <p className="text-muted-foreground text-xs">
                                Showing up to 500 recently updated requests. Reports use recorded payment terms.
                            </p>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[980px] table-fixed text-left text-sm">
                                    <thead>
                                        <tr className="text-muted-foreground bg-muted/30 border-y text-xs tracking-wide uppercase">
                                            <th className="w-[19%] px-4 py-3 font-medium">Request</th>
                                            <th className="w-[16%] px-3 py-3 font-medium">Faculty & panel</th>
                                            <th className="w-[11%] px-3 py-3 font-medium">Semester</th>
                                            <th className="w-[20%] px-3 py-3 font-medium">Progress</th>
                                            <th className="w-[18%] px-3 py-3 font-medium">Finance & payments</th>
                                            <th className="w-[16%] px-3 py-3 font-medium">Follow-up</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {filtered.map((item) => {
                                            const latestFollowUp = item.follow_ups[0];
                                            const followUpIsForViewer = latestFollowUp ? needsFollowUp(latestFollowUp, role) : false;
                                            const activeStep = stepIndex(item.progress_stage);
                                            const nextAction = currentAction(item);
                                            return (
                                                <tr className="hover:bg-muted/20 border-b align-top transition-colors last:border-0" key={item.id}>
                                                    <td className="px-4 py-4">
                                                        <div className="truncate font-medium" title={item.student_name}>
                                                            {item.student_name}
                                                        </div>
                                                        <div className="text-muted-foreground mt-1 truncate text-xs" title={item.program ?? ''}>
                                                            {item.program || 'Program not set'}
                                                        </div>
                                                        <div className="text-muted-foreground mt-1 flex flex-wrap items-center gap-x-2 text-xs">
                                                            <span>#{item.id}</span>
                                                            <span>{item.defense_type}</span>
                                                            {item.receipt_url && (
                                                                <a
                                                                    className="text-primary underline-offset-2 hover:underline"
                                                                    href={item.receipt_url}
                                                                    target="_blank"
                                                                    rel="noreferrer"
                                                                >
                                                                    Receipt
                                                                </a>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-3 py-4">
                                                        <div className="truncate text-sm" title={item.faculty_names[0] ?? ''}>
                                                            {item.faculty_names[0] || 'Not assigned'}
                                                        </div>
                                                        {item.panel_names.length > 0 ? (
                                                            <details className="text-muted-foreground mt-1 text-xs">
                                                                <summary className="cursor-pointer">Panel · {item.panel_names.length}</summary>
                                                                <ul className="mt-1 space-y-0.5">
                                                                    {item.panel_names.map((name) => (
                                                                        <li className="truncate" key={name}>
                                                                            {name}
                                                                        </li>
                                                                    ))}
                                                                </ul>
                                                            </details>
                                                        ) : (
                                                            <div className="text-muted-foreground mt-1 text-xs">No panel assigned</div>
                                                        )}
                                                    </td>
                                                    <td className="text-muted-foreground px-3 py-4 text-xs">
                                                        {item.school_year && item.semester ? (
                                                            <>
                                                                <div className="text-foreground font-medium">{item.semester}</div>
                                                                <div>{item.school_year}</div>
                                                            </>
                                                        ) : (
                                                            '—'
                                                        )}
                                                    </td>
                                                    <td className="px-3 py-4">
                                                        <div className="mb-2 text-xs font-medium">{progressSteps[activeStep]}</div>
                                                        <ol className="flex items-center" aria-label="Honorarium progress">
                                                            {progressSteps.map((step, index) => {
                                                                const complete = index < activeStep;
                                                                const current = index === activeStep;

                                                                return (
                                                                    <li
                                                                        className={`flex min-w-0 items-center ${index < progressSteps.length - 1 ? 'flex-1' : 'shrink-0'}`}
                                                                        key={step}
                                                                        aria-current={current ? 'step' : undefined}
                                                                        aria-label={`${step}${complete ? ', complete' : current ? ', current step' : ', upcoming'}`}
                                                                    >
                                                                        <span
                                                                            title={step}
                                                                            className={`flex size-5 shrink-0 items-center justify-center rounded-full border text-[10px] font-semibold ${
                                                                                complete
                                                                                    ? 'border-emerald-600 bg-emerald-600 text-white'
                                                                                    : current
                                                                                      ? 'border-primary bg-primary text-primary-foreground'
                                                                                      : 'border-muted-foreground/40 text-muted-foreground'
                                                                            }`}
                                                                        >
                                                                            {complete ? '✓' : index + 1}
                                                                        </span>
                                                                        {index < progressSteps.length - 1 && (
                                                                            <span className="bg-border mx-1 h-px flex-1" aria-hidden="true" />
                                                                        )}
                                                                    </li>
                                                                );
                                                            })}
                                                        </ol>
                                                        <div
                                                            className="text-muted-foreground mt-2 truncate text-xs"
                                                            role="status"
                                                            title={`${nextAction.owner}: ${nextAction.action}`}
                                                        >
                                                            Next: <span className="text-foreground font-medium">{nextAction.owner}</span> ·{' '}
                                                            {nextAction.action}
                                                        </div>
                                                    </td>
                                                    <td className="px-3 py-4">
                                                        <div className="space-y-1.5">
                                                            {(['payment_receipt', 'panel_breakdown'] as const).map((type) => {
                                                                const doc = item.finance_documents.find((document) => document.type === type);
                                                                const label = type === 'payment_receipt' ? 'Receipt' : 'Breakdown';

                                                                return (
                                                                    <div className="flex items-center justify-between gap-2 text-xs" key={type}>
                                                                        <span className="text-muted-foreground">{label}</span>
                                                                        <Badge
                                                                            variant={doc?.status === 'verified' ? 'default' : 'secondary'}
                                                                            className="h-5 px-1.5 text-[10px]"
                                                                        >
                                                                            {doc?.status ?? 'Not sent'}
                                                                        </Badge>
                                                                    </div>
                                                                );
                                                            })}
                                                            <details className="text-muted-foreground border-t pt-1.5 text-[11px]">
                                                                <summary className="cursor-pointer">Activity & panel payments</summary>
                                                                <div className="mt-1 space-y-2">
                                                                    {item.finance_documents.map((doc) => (
                                                                        <div key={doc.type}>
                                                                            <div className="text-foreground font-medium">
                                                                                {doc.type === 'payment_receipt' ? 'Receipt' : 'Panel breakdown'}
                                                                            </div>
                                                                            <div>Sent {formatDate(doc.submitted_at)}</div>
                                                                            <div>
                                                                                Reviewed {formatDate(doc.reviewed_at)}
                                                                                {doc.reviewer_role ? ` · ${doc.reviewer_role}` : ''}
                                                                            </div>
                                                                            {doc.finance_notes && <div>Note: {doc.finance_notes}</div>}
                                                                        </div>
                                                                    ))}
                                                                    <div className="text-foreground font-medium">Panel honoraria</div>
                                                                    {item.honorarium_recipients.length ? (
                                                                        item.honorarium_recipients.map((recipient) => (
                                                                            <div
                                                                                className="flex justify-between gap-2"
                                                                                key={`${recipient.role}-${recipient.name}`}
                                                                            >
                                                                                <span className="truncate">{recipient.name}</span>
                                                                                <span>{recipient.status}</span>
                                                                            </div>
                                                                        ))
                                                                    ) : (
                                                                        <div>No payment records</div>
                                                                    )}
                                                                </div>
                                                            </details>
                                                        </div>
                                                    </td>
                                                    <td className="px-3 py-4">
                                                        {latestFollowUp && (
                                                            <details
                                                                className={`group mb-2 overflow-hidden rounded-lg border text-xs ${
                                                                    followUpIsForViewer
                                                                        ? 'border-primary/30 bg-primary/[0.07]'
                                                                        : 'border-border bg-muted/20'
                                                                }`}
                                                            >
                                                                <summary
                                                                    className={`flex cursor-pointer list-none items-start gap-2 p-2.5 transition-colors [&::-webkit-details-marker]:hidden ${
                                                                        followUpIsForViewer ? 'hover:bg-primary/10' : 'hover:bg-muted/50'
                                                                    }`}
                                                                >
                                                                    <span
                                                                        className={`mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full ${
                                                                            followUpIsForViewer
                                                                                ? 'bg-primary/15 text-primary'
                                                                                : 'bg-muted text-muted-foreground'
                                                                        }`}
                                                                    >
                                                                        <Clock3 className="size-3.5" />
                                                                    </span>
                                                                    <span className="min-w-0 flex-1">
                                                                        <span className="text-foreground block font-semibold">Latest follow-up</span>
                                                                        <span className="text-muted-foreground mt-0.5 block leading-snug">
                                                                            {formatDate(latestFollowUp.occurred_at)}
                                                                        </span>
                                                                        {followUpIsForViewer && (
                                                                            <span className="bg-primary/15 text-primary mt-1 inline-block rounded-full px-2 py-0.5 text-[10px] font-semibold">
                                                                                Follow-up for your role
                                                                            </span>
                                                                        )}
                                                                    </span>
                                                                    <ChevronDown className="text-muted-foreground mt-1 size-3.5 shrink-0 transition-transform group-open:rotate-180" />
                                                                </summary>
                                                                <div
                                                                    className={`text-muted-foreground space-y-1 border-t px-2.5 py-2 ${
                                                                        followUpIsForViewer ? 'border-primary/15' : 'border-border'
                                                                    }`}
                                                                >
                                                                    <p>
                                                                        To{' '}
                                                                        <span className="text-foreground font-medium">
                                                                            {latestFollowUp.recipient_role}
                                                                        </span>
                                                                    </p>
                                                                    <p>
                                                                        Sent by{' '}
                                                                        <span className="text-foreground font-medium">{latestFollowUp.actor}</span>
                                                                    </p>
                                                                    <p className="break-words">{latestFollowUp.notes}</p>
                                                                </div>
                                                            </details>
                                                        )}
                                                        {canFollowUp && !item.archived && (
                                                            <Button
                                                                type="button"
                                                                variant={followUpIsForViewer ? 'default' : 'outline'}
                                                                size="sm"
                                                                className={`h-8 w-full text-xs ${followUpIsForViewer ? 'shadow-sm' : ''}`}
                                                                onClick={() => openFollowUp(item)}
                                                            >
                                                                <Send className="size-3.5" />
                                                                Follow up
                                                            </Button>
                                                        )}
                                                        {!item.archived && canSubmitToFinance && item.receipt_url && (
                                                            <Button className="mt-2 h-7 px-2 text-xs" size="sm" onClick={() => submit(item)}>
                                                                <Send className="mr-1 size-3" />
                                                                Send receipt
                                                            </Button>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                        {filtered.length === 0 && (
                                            <tr>
                                                <td className="text-muted-foreground p-10 text-center" colSpan={6}>
                                                    No matching defense requests.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}
                {tab === 'schedule' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Schedule board</CardTitle>
                            <p className="text-muted-foreground text-sm">
                                Existing defense schedules are shown here. Scheduling conflict prevention remains managed by the current defense
                                scheduling module.
                            </p>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr className="text-muted-foreground border-b">
                                            <th className="p-3">Date</th>
                                            <th className="p-3">Time</th>
                                            <th className="p-3">Student</th>
                                            <th className="p-3">Panel</th>
                                            <th className="p-3">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {categoryItems
                                            .filter((i) => i.scheduled_date)
                                            .sort((a, b) => (a.scheduled_date ?? '').localeCompare(b.scheduled_date ?? ''))
                                            .map((i) => (
                                                <tr className="border-b last:border-0" key={i.id}>
                                                    <td className="p-3">{i.scheduled_date}</td>
                                                    <td className="p-3">{i.scheduled_time ?? '—'}</td>
                                                    <td className="p-3">{i.student_name}</td>
                                                    <td className="p-3">{i.panel_names.join(', ') || '—'}</td>
                                                    <td className="p-3">{i.workflow_state}</td>
                                                </tr>
                                            ))}
                                    </tbody>
                                </table>
                                {!categoryItems.some((i) => i.scheduled_date) && (
                                    <p className="text-muted-foreground p-6 text-center text-sm">No scheduled defenses with receipt records yet.</p>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}
                {tab === 'rates' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Payment rates</CardTitle>
                            <p className="text-muted-foreground text-sm">
                                Read-only rate reference. Rate editing remains in the existing Dean payment-rates module.
                            </p>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr className="text-muted-foreground border-b">
                                            <th className="p-3">Program level</th>
                                            <th className="p-3">Type</th>
                                            <th className="p-3">Defense</th>
                                            <th className="p-3 text-right">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rates.map((rate) => (
                                            <tr className="border-b last:border-0" key={rate.id}>
                                                <td className="p-3">{rate.program_level}</td>
                                                <td className="p-3">{rate.type}</td>
                                                <td className="p-3">{rate.defense_type ?? '—'}</td>
                                                <td className="p-3 text-right">
                                                    ₱
                                                    {Number(rate.amount).toLocaleString(undefined, {
                                                        minimumFractionDigits: 2,
                                                        maximumFractionDigits: 2,
                                                    })}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                {rates.length === 0 && <p className="text-muted-foreground p-6 text-center text-sm">No payment rates configured.</p>}
                            </div>
                        </CardContent>
                    </Card>
                )}
                {tab === 'records' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Honorarium records</CardTitle>
                            <p className="text-muted-foreground text-sm">
                                Current status history snapshot for the receipt-backed records available to your role.
                            </p>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr className="text-muted-foreground border-b">
                                            <th className="p-3">Request</th>
                                            <th className="p-3">Student</th>
                                            <th className="p-3">Workflow</th>
                                            <th className="p-3">Finance</th>
                                            <th className="p-3">Submitted</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {categoryItems.map((i) => (
                                            <tr className="border-b last:border-0" key={i.id}>
                                                <td className="p-3">#{i.id}</td>
                                                <td className="p-3">{i.student_name}</td>
                                                <td className="p-3">{i.workflow_state}</td>
                                                <td className="p-3">{statusLabel[i.finance_status] ?? i.finance_status}</td>
                                                <td className="p-3">{i.submitted_at ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}
                <Dialog
                    open={!!followUpItem}
                    onOpenChange={(open) => {
                        if (!open) setFollowUpItem(null);
                    }}
                >
                    <DialogContent className="max-w-lg">
                        <DialogHeader>
                            <DialogTitle>Follow up on honorarium</DialogTitle>
                            <DialogDescription>Send an email and record this follow-up in the request history.</DialogDescription>
                        </DialogHeader>
                        {followUpItem && (
                            <form className="space-y-4" onSubmit={sendFollowUp}>
                                <div className="bg-muted/30 rounded-lg border p-3">
                                    <p className="font-medium">{followUpItem.student_name}</p>
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        Request #{followUpItem.id} · {followUpItem.program || 'Program not set'} · {followUpItem.defense_type}
                                    </p>
                                    <p className="text-muted-foreground mt-2 text-xs">
                                        Current step: <span className="text-foreground font-medium">{currentAction(followUpItem).owner}</span>
                                        {' · '}
                                        {currentAction(followUpItem).action}
                                    </p>
                                </div>
                                <label className="block space-y-1.5 text-sm font-medium">
                                    Send follow-up to
                                    <select
                                        className="bg-background h-10 w-full rounded-md border px-3 font-normal"
                                        value={followUpRecipient}
                                        onChange={(event) => setFollowUpRecipient(event.target.value)}
                                    >
                                        <option>Finance</option>
                                        <option>Administrative Assistant</option>
                                        <option>Dean</option>
                                    </select>
                                </label>
                                <label className="block space-y-1.5 text-sm font-medium">
                                    Message
                                    <Textarea
                                        className="min-h-28 resize-y font-normal"
                                        value={followUpNotes}
                                        onChange={(event) => setFollowUpNotes(event.target.value)}
                                        required
                                        minLength={3}
                                        maxLength={2000}
                                        placeholder="Add context or ask for a progress update"
                                    />
                                    <span className="text-muted-foreground block text-right text-xs">{followUpNotes.length}/2000</span>
                                </label>
                                {followUpError && (
                                    <p className="text-destructive text-sm" role="alert">
                                        {followUpError}
                                    </p>
                                )}
                                <DialogFooter>
                                    <Button type="button" variant="outline" onClick={() => setFollowUpItem(null)}>
                                        Cancel
                                    </Button>
                                    <Button type="submit" disabled={followUpNotes.trim().length < 3}>
                                        <Send className="size-4" />
                                        Send follow-up
                                    </Button>
                                </DialogFooter>
                            </form>
                        )}
                    </DialogContent>
                </Dialog>
                {tab === 'reports' && canViewReports && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Program honorarium by semester</CardTitle>
                            <p className="text-muted-foreground text-sm">
                                Live totals from prepared honorarium recipient records. Voided records are excluded.
                            </p>
                            <Button asChild variant="outline" className="w-fit">
                                <a href="/honorarium-workspace/reports.csv">Download CSV report</a>
                            </Button>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[900px] text-left text-sm">
                                    <thead>
                                        <tr className="text-muted-foreground border-b">
                                            <th className="p-3">School year / semester</th>
                                            <th className="p-3">Program</th>
                                            <th className="p-3">Recipients</th>
                                            <th className="p-3 text-right">Prepared</th>
                                            <th className="p-3 text-right">Approved</th>
                                            <th className="p-3 text-right">Disbursed</th>
                                            <th className="p-3 text-right">Claimed</th>
                                            <th className="p-3 text-right">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {programReports.map((report) => (
                                            <tr className="border-b last:border-0" key={`${report.school_year}-${report.semester}-${report.program}`}>
                                                <td className="p-3">
                                                    {report.school_year} · {report.semester}
                                                </td>
                                                <td className="p-3">{report.program || 'Unspecified program'}</td>
                                                <td className="p-3">{report.recipients_count}</td>
                                                {[
                                                    report.prepared_amount,
                                                    report.approved_amount,
                                                    report.disbursed_amount,
                                                    report.claimed_amount,
                                                    report.total_amount,
                                                ].map((amount, index) => (
                                                    <td className="p-3 text-right" key={index}>
                                                        ₱
                                                        {Number(amount).toLocaleString('en-PH', {
                                                            minimumFractionDigits: 2,
                                                            maximumFractionDigits: 2,
                                                        })}
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                        {programReports.length === 0 && (
                                            <tr>
                                                <td className="text-muted-foreground p-8 text-center" colSpan={8}>
                                                    No honorarium records are available to report.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </main>
        </AppLayout>
    );
}
