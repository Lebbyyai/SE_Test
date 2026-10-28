import { DefenseRequestProgress, type DefenseProgressFields } from '@/components/defense-request-progress';
import { DocumentGeneratorDialog } from '@/components/DocumentGeneratorDialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import {
    Calendar,
    CheckCircle,
    ChevronDown,
    Clock as ClockIcon,
    GraduationCap,
    Hourglass,
    Info as InfoIcon,
    MapPin,
    MoreVertical,
    Paperclip,
    Plus,
    Users,
    X,
} from 'lucide-react';
import React, { useEffect, useState } from 'react';
import SubmitDefenseRequirements from './submit-defense-requirements';

dayjs.extend(relativeTime);

function resolveFileUrl(url?: string | null) {
    if (!url) return null;
    if (/^https?:\/\//i.test(url) || url.startsWith('/storage/')) return url;
    return `/storage/${url.replace(/^\/?storage\//, '')}`;
}

// Map-to-template helper

// Date/Time helpers (robust)
function formatDatePretty(d?: string | null) {
    if (!d) return '—';
    const parsed = dayjs(d);
    return parsed.isValid() ? parsed.format('MMM D, YYYY') : d;
}
function to12h(t?: string | null) {
    if (!t) return null;
    // Handles HH:mm or HH:mm:ss
    const base = `1970-01-01 ${t}`;
    const parsed = dayjs(base);
    return parsed.isValid() ? parsed.format('hh:mm A') : t;
}
function formatTimeRange(start?: string | null, end?: string | null) {
    const s = to12h(start);
    const e = to12h(end);
    if (!s && !e) return '—';
    if (s && e) return `${s} — ${e}`;
    return s || e || '—';
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Defense Requirements', href: '/defense-requirements' },
];

type DefenseRequirement = DefenseProgressFields & {
    id: number;
    first_name: string;
    last_name: string;
    thesis_title: string;
    adviser: string;
    status: string;
    reference_no?: string;
    program: string;
    attachment_names?: Record<string, string>;
    defense_type?: string;
    created_at?: string;
    workflow_state?: string;
    defense_chairperson?: string;
    defense_panelist1?: string;
    defense_panelist2?: string;
    defense_panelist3?: string;
    defense_panelist4?: string;
    scheduled_date?: string | null;
    scheduled_time?: string | null;
    scheduled_end_time?: string | null;
    formatted_time_range?: string | null;
    defense_venue?: string | null;
    defense_mode?: string | null;
    manuscript_proposal?: string;
    similarity_index?: string;
    rec_endorsement?: string;
    proof_of_payment?: string;
    avisee_adviser_attachment?: string;
    scheduling_notes?: string | null;
    panels_assigned_at?: string | null;
};

type DefenseRequest = DefenseProgressFields & {
    id: number;
    thesis_title: string;
    school_id: string;
    status: string;
    defense_adviser: string;
    workflow_state: string;
    workflow_state_display?: string;
    defense_chairperson?: string | null;
    defense_panelist1?: string | null;
    defense_panelist2?: string | null;
    defense_panelist3?: string | null;
    defense_panelist4?: string | null;
    scheduled_date?: string | null;
    scheduled_time?: string | null;
    scheduled_end_time?: string | null;
    formatted_time_range?: string | null;
    defense_venue?: string | null;
    defense_mode?: string | null;
    adviser_comments?: string | null;
    coordinator_comments?: string | null;
    manuscript_proposal?: string | null;
    similarity_index?: string | null;
    rec_endorsement?: string | null;
    proof_of_payment?: string | null;
    submitted_by?: string | null;
    panels_assigned_at?: string | null;
    scheduling_notes?: string | null;
};

type PageProps = {
    auth: { user: { role: string; school_id: string } };
    defenseRequirements?: DefenseRequirement[];
    defenseRequest?: DefenseRequest | null;
    acceptDefense?: boolean;
};

export default function DefenseRequestIndex() {
    const { props } = usePage<PageProps>();
    const { defenseRequirements = [], defenseRequest: initialDefenseRequest, acceptDefense = true } = props;

    const [showClosedAlert, setShowClosedAlert] = useState(!acceptDefense);
    const [defenseRequest, setDefenseRequest] = useState<DefenseRequest | null>(initialDefenseRequest || null);
    const [, setLastUpdateTime] = useState<string>(dayjs().format('h:mm A'));
    const [loading] = useState(false);

    const TERMINAL_WORKFLOW_STATES = new Set(['cancelled', 'adviser-rejected', 'coordinator-rejected', 'rejected', 'completed']);
    const hasActiveWorkflow =
        defenseRequirements.some((req) => {
            const current = defenseRequest?.id === req.id ? defenseRequest : req;
            return !TERMINAL_WORKFLOW_STATES.has((current.workflow_state || current.status || '').toLowerCase());
        }) ||
        (!!defenseRequest && !TERMINAL_WORKFLOW_STATES.has((defenseRequest.workflow_state || '').toLowerCase()));

    const [open, setOpen] = useState(false);
    const [docGenOpen, setDocGenOpen] = useState(false);
    const [docGenRequest] = useState<{ id: number } | null>(null);
    const [unsubmitDialogOpen, setUnsubmitDialogOpen] = useState(false);
    const [unsubmitReason, setUnsubmitReason] = useState('');
    const [unsubmitOtherReason, setUnsubmitOtherReason] = useState('');
    const [unsubmitTargetId, setUnsubmitTargetId] = useState<number | null>(null);
    const [processingUnsubmit, setProcessingUnsubmit] = useState(false);
    const [openItemId, setOpenItemId] = useState<number | null>(initialDefenseRequest?.id || defenseRequirements[0]?.id || null);

    const STATE_ORDER = ['submitted', 'adviser-approved', 'coordinator-approved', 'panels-assigned', 'scheduled', 'completed'] as const;
    type CanonicalState = (typeof STATE_ORDER)[number];

    function normalizeWorkflowState(raw?: string | null): CanonicalState | null {
        if (!raw) return null;
        const r = raw.toLowerCase();
        if (r === 'submitted' || r === 'adviser-review' || r === 'adviser-corrections') return 'submitted';
        if (r === 'adviser-approved' || r === 'coordinator-review' || r === 'coordinator-corrections') return 'adviser-approved';
        if (r === 'coordinator-approved') return 'coordinator-approved';
        if (r === 'panels-assigned' || r === 'panel-assigned') return 'panels-assigned';
        if (r === 'scheduled') return 'scheduled';
        if (r === 'completed') return 'completed';
        return null;
    }

    function currentStepperIndex(dr: DefenseRequest | { workflow_state?: string } | null): number {
        if (!dr) return 0;
        const wf = (dr.workflow_state || '').toLowerCase();
        const norm = normalizeWorkflowState(wf);
        if (!norm) return 0;
        return STATE_ORDER.indexOf(norm);
    }

    useEffect(() => {
        setDefenseRequest(initialDefenseRequest || null);
    }, [initialDefenseRequest]);

    useEffect(() => {
        if (!defenseRequest?.id) return;
        const controller = new AbortController();
        const pollInterval = setInterval(async () => {
            if (document.hidden) return;
            try {
                const response = await fetch(`/api/defense-request/${defenseRequest.id}`, { signal: controller.signal });
                if (!response.ok || controller.signal.aborted) return;
                const updated = await response.json();
                if (controller.signal.aborted) return;
                const keys: (keyof DefenseRequest)[] = [
                    'workflow_state',
                    'defense_chairperson',
                    'defense_panelist1',
                    'defense_panelist2',
                    'defense_panelist3',
                    'defense_panelist4',
                    'scheduled_date',
                    'scheduled_time',
                    'scheduled_end_time',
                    'defense_venue',
                    'defense_mode',
                    'scheduling_notes',
                    'correction_notes',
                    'correction_requested_at',
                ];
                const changed = keys.some((k) => updated[k] !== defenseRequest[k]);
                if (changed) setLastUpdateTime(dayjs().format('h:mm A'));
                setDefenseRequest((previous) => (previous?.id === updated.id ? { ...previous, ...updated } : previous));
            } catch {
                /* Keep the previous request visible if a background refresh fails. */
            }
        }, 10000);
        return () => {
            clearInterval(pollInterval);
            controller.abort();
        };
    }, [defenseRequest?.id, defenseRequest]);

    function canUnsubmit(req: DefenseRequirement, dr: DefenseRequest | null) {
        const currentState = dr?.id === req.id ? dr.workflow_state : req.workflow_state;
        if (currentState?.endsWith('-corrections')) return false;
        if (!dr) return (req.status || '').toLowerCase() === 'pending';
        const allowed = ['pending', 'submitted', 'adviser-review'];
        if ((req.status || '').toLowerCase() === 'cancelled') return false;
        if (dr.id === req.id && dr.workflow_state === 'cancelled') return false;
        return (req.status || '').toLowerCase() === 'pending' || (dr.id === req.id && allowed.includes(dr.workflow_state));
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Defense Requirements" />
            {loading ? (
                <div className="m-0 flex min-h-[70vh] w-full flex-col gap-4 bg-zinc-100 p-0 dark:bg-zinc-900">
                    <Skeleton className="mx-8 mt-8 h-6 w-1/6 rounded bg-zinc-300 dark:bg-zinc-800" />
                    <Skeleton className="mx-8 h-12 w-3/4 rounded bg-zinc-300 dark:bg-zinc-800" />
                    <Skeleton className="mx-8 h-12 w-2/3 rounded bg-zinc-300 dark:bg-zinc-800" />
                    <Skeleton className="mt-4 h-[500px] w-full rounded bg-zinc-300 dark:bg-zinc-800" />
                </div>
            ) : (
                <div className="flex w-full flex-col px-7 pt-5 pb-5">
                    {/* Alert if submissions are closed */}
                    {!acceptDefense && showClosedAlert && (
                        <Alert className="relative mb-4 flex items-start gap-3 rounded-xl border-yellow-200 bg-yellow-50 px-6 py-5 text-yellow-900 dark:border-yellow-900 dark:bg-yellow-950 dark:text-yellow-100">
                            <InfoIcon className="mt-1 h-5 w-5 flex-shrink-0 text-yellow-500 dark:text-yellow-400" />
                            <div>
                                <AlertTitle className="mb-1 font-semibold">Defense Requirement Submissions Closed</AlertTitle>
                                <AlertDescription>
                                    The defense requirement submission period is currently closed. Please contact your coordinator for more
                                    information.
                                </AlertDescription>
                            </div>
                            <button
                                type="button"
                                className="absolute top-2 right-2 rounded p-1 text-yellow-900 hover:text-yellow-700 dark:text-yellow-100 dark:hover:text-yellow-300"
                                aria-label="Close"
                                onClick={() => setShowClosedAlert(false)}
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </Alert>
                    )}
                    <div className="w-full overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                        <div className="flex w-full flex-row items-center justify-between border-b border-zinc-200 p-3 dark:border-zinc-800">
                            <div className="flex items-center gap-2">
                                <div className="flex h-10 w-10 items-center justify-center rounded-full border border-rose-500 bg-rose-500/10 dark:border-rose-700 dark:bg-rose-500/20">
                                    <GraduationCap className="h-5 w-5 text-rose-400 dark:text-rose-300" />
                                </div>
                                <div>
                                    <span className="text-base font-semibold dark:text-white">Defense Requirements</span>
                                    <p className="text-muted-foreground block text-xs dark:text-zinc-400">
                                        Track your submission, committee, and schedule.
                                    </p>
                                </div>
                            </div>
                            <Button
                                className="rounded-md bg-rose-500 px-5 text-sm disabled:opacity-60 dark:bg-rose-600"
                                onClick={() => setOpen(true)}
                                disabled={hasActiveWorkflow || !acceptDefense}
                                title={
                                    !acceptDefense
                                        ? 'Submissions closed'
                                        : hasActiveWorkflow
                                          ? 'Finish current workflow before submitting another'
                                          : 'Submit new defense requirements'
                                }
                            >
                                <Plus /> Submit requirements
                            </Button>
                            <SubmitDefenseRequirements open={open} onOpenChange={setOpen} onFinish={() => {}} acceptDefense={acceptDefense} />
                        </div>
                        {defenseRequirements.length === 0 ? (
                            <div className="text-muted-foreground p-6 text-center text-sm dark:text-zinc-400">
                                No defense requirements submitted yet.
                            </div>
                        ) : (
                            // Sort by created_at descending (most recent first)
                            [...defenseRequirements]
                                .sort(
                                    (a, b) =>
                                        (b.created_at ? new Date(b.created_at).getTime() : 0) - (a.created_at ? new Date(a.created_at).getTime() : 0),
                                )
                                .map((req) => {
                                    const isOpen = openItemId === req.id;
                                    const timeSubmitted = req.created_at ? dayjs(req.created_at).fromNow() : 'Unknown';

                                    const activeObjForRow =
                                        defenseRequest && defenseRequest.id === req.id
                                            ? defenseRequest
                                            : req.workflow_state
                                              ? { workflow_state: req.workflow_state }
                                              : null;

                                    const stepIdx = currentStepperIndex(activeObjForRow);

                                    // Merge row data with active request so students always see latest schedule/committee
                                    const merged = {
                                        ...req,
                                        ...(defenseRequest && defenseRequest.id === req.id ? defenseRequest : {}),
                                    } as DefenseRequirement & Partial<DefenseRequest>;

                                    const wf = (merged.workflow_state || '').toLowerCase();
                                    const isCancelled = wf === 'cancelled';
                                    const isRejected = wf === 'adviser-rejected' || wf === 'coordinator-rejected' || wf === 'rejected';
                                    const needsCorrections = wf === 'adviser-corrections' || wf === 'coordinator-corrections';
                                    const isCompleted = wf === 'completed';
                                    const showStepper = !isCancelled && !isRejected && !isCompleted;

                                    const workflowSteps = [
                                        { key: 'submitted', label: 'Submitted', icon: <Hourglass className="h-4 w-4" /> },
                                        { key: 'adviser-approved', label: 'Adviser Approved', icon: <CheckCircle className="h-4 w-4" /> },
                                        { key: 'coordinator-approved', label: 'Coordinator Approved', icon: <CheckCircle className="h-4 w-4" /> },
                                        { key: 'panels-assigned', label: 'Panels Assigned', icon: <Users className="h-4 w-4" /> },
                                        { key: 'scheduled', label: 'Scheduled', icon: <Calendar className="h-4 w-4" /> },
                                        { key: 'completed', label: 'Completed', icon: <GraduationCap className="h-4 w-4" /> },
                                    ] as const;

                                    return (
                                        <React.Fragment key={req.id}>
                                            <div className="w-full border-b border-zinc-200 dark:border-zinc-800" />
                                            <Collapsible open={isOpen} onOpenChange={(open) => setOpenItemId(open ? req.id : null)}>
                                                <CollapsibleTrigger asChild>
                                                    <div className="flex cursor-pointer items-center justify-between bg-white px-4 py-3 transition-colors hover:bg-zinc-50 dark:bg-zinc-900 dark:hover:bg-zinc-800">
                                                        <ChevronDown
                                                            className={`text-muted-foreground mr-3 h-4 w-4 transition-transform duration-200 dark:text-zinc-400 ${isOpen ? 'rotate-180' : ''}`}
                                                        />
                                                        <div className="flex min-w-0 flex-1 items-center gap-2">
                                                            {/* Defense type badge before thesis title */}
                                                            {req.defense_type && (
                                                                <Badge variant="outline" className="px-2 py-0.5 text-[11px] font-semibold capitalize">
                                                                    {req.defense_type}
                                                                </Badge>
                                                            )}
                                                            <span className="truncate text-xs font-semibold text-black dark:text-white">
                                                                {merged.thesis_title}
                                                            </span>
                                                            {/* Status badge beside title */}
                                                            {(() => {
                                                                const statusInfo = needsCorrections
                                                                    ? {
                                                                          icon: <InfoIcon className="h-3.5 w-3.5" />,
                                                                          label: 'Corrections needed',
                                                                          tone: 'text-amber-800 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 border-amber-300 dark:border-amber-800',
                                                                      }
                                                                    : isCancelled
                                                                      ? {
                                                                            icon: <X className="h-3.5 w-3.5" />,
                                                                            label: 'Cancelled',
                                                                            tone: 'text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800',
                                                                        }
                                                                      : isRejected
                                                                        ? {
                                                                              icon: <X className="h-3.5 w-3.5" />,
                                                                              label:
                                                                                  wf === 'adviser-rejected'
                                                                                      ? 'Rejected by Adviser'
                                                                                      : 'Rejected by Coordinator',
                                                                              tone: 'text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800',
                                                                          }
                                                                        : isCompleted
                                                                          ? {
                                                                                icon: <CheckCircle className="h-3.5 w-3.5" />,
                                                                                label: 'Defense Completed',
                                                                                tone: 'text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800',
                                                                            }
                                                                          : wf === 'scheduled'
                                                                            ? {
                                                                                  icon: <Calendar className="h-3.5 w-3.5" />,
                                                                                  label: 'Defense Scheduled',
                                                                                  tone: 'text-rose-700 dark:text-rose-300 bg-rose-50 dark:bg-rose-900/20 border-rose-200 dark:border-rose-800',
                                                                              }
                                                                            : wf === 'panels-assigned'
                                                                              ? {
                                                                                    icon: <Users className="h-3.5 w-3.5" />,
                                                                                    label: 'Panels Assigned',
                                                                                    tone: 'text-rose-700 dark:text-rose-300 bg-rose-50 dark:bg-rose-900/20 border-rose-200 dark:border-rose-800',
                                                                                }
                                                                              : wf === 'coordinator-approved'
                                                                                ? {
                                                                                      icon: <CheckCircle className="h-3.5 w-3.5" />,
                                                                                      label: 'Coordinator Approved',
                                                                                      tone: 'text-zinc-700 dark:text-zinc-200 bg-zinc-50 dark:bg-zinc-800/50 border-zinc-200 dark:border-zinc-700',
                                                                                  }
                                                                                : wf === 'adviser-approved'
                                                                                  ? {
                                                                                        icon: <CheckCircle className="h-3.5 w-3.5" />,
                                                                                        label: 'Adviser Approved',
                                                                                        tone: 'text-zinc-700 dark:text-zinc-200 bg-zinc-50 dark:bg-zinc-800/50 border-zinc-200 dark:border-zinc-700',
                                                                                    }
                                                                                  : wf === 'coordinator-review'
                                                                                    ? {
                                                                                          icon: <Hourglass className="h-3.5 w-3.5" />,
                                                                                          label: 'Coordinator Review',
                                                                                          tone: 'text-zinc-600 dark:text-zinc-400 bg-zinc-50 dark:bg-zinc-800/50 border-zinc-200 dark:border-zinc-700',
                                                                                      }
                                                                                    : wf === 'adviser-review'
                                                                                      ? {
                                                                                            icon: <Hourglass className="h-3.5 w-3.5" />,
                                                                                            label: 'Adviser Review',
                                                                                            tone: 'text-zinc-600 dark:text-zinc-400 bg-zinc-50 dark:bg-zinc-800/50 border-zinc-200 dark:border-zinc-700',
                                                                                        }
                                                                                      : {
                                                                                            icon: <Hourglass className="h-3.5 w-3.5" />,
                                                                                            label: 'Submitted',
                                                                                            tone: 'text-zinc-600 dark:text-zinc-400 bg-zinc-50 dark:bg-zinc-800/50 border-zinc-200 dark:border-zinc-700',
                                                                                        };
                                                                return (
                                                                    <span
                                                                        className={`ml-2 inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[11px] font-medium ${statusInfo.tone}`}
                                                                    >
                                                                        {statusInfo.icon}
                                                                        {statusInfo.label}
                                                                    </span>
                                                                );
                                                            })()}
                                                        </div>
                                                        <div className="flex w-64 items-center justify-end">
                                                            {showStepper ? (
                                                                <div className="flex items-center gap-0">
                                                                    {workflowSteps.map((step, idx) => {
                                                                        const isActive = idx <= stepIdx;
                                                                        const stepBg = isActive
                                                                            ? 'bg-rose-500 text-white border-rose-500 dark:bg-rose-600 dark:border-rose-700'
                                                                            : 'bg-zinc-100 text-zinc-400 border-zinc-200 dark:bg-zinc-800 dark:text-zinc-500 dark:border-zinc-700';
                                                                        return (
                                                                            <React.Fragment key={step.key}>
                                                                                <div className="flex items-center justify-center">
                                                                                    <div className={`rounded-full border p-1 ${stepBg}`}>
                                                                                        {step.icon}
                                                                                    </div>
                                                                                </div>
                                                                                {idx < workflowSteps.length - 1 && (
                                                                                    <div
                                                                                        className={`h-1 w-6 ${idx < stepIdx ? 'bg-rose-500 dark:bg-rose-700' : 'bg-zinc-200 dark:bg-zinc-700'}`}
                                                                                    />
                                                                                )}
                                                                            </React.Fragment>
                                                                        );
                                                                    })}
                                                                </div>
                                                            ) : (
                                                                <div className="flex items-center justify-center" />
                                                            )}
                                                        </div>
                                                    </div>
                                                </CollapsibleTrigger>
                                                <CollapsibleContent>
                                                    <div className="px-4 py-3">
                                                        <div className="rounded-xl border border-zinc-200 bg-white p-4 sm:p-5 dark:border-zinc-800 dark:bg-zinc-900">
                                                            {/* Header: only submitted time and menu */}
                                                            <div className="flex items-start justify-between gap-3">
                                                                <div className="min-w-0" />
                                                                <div className="flex shrink-0 items-center gap-2">
                                                                    <span className="text-muted-foreground text-[10px] whitespace-nowrap dark:text-zinc-400">
                                                                        Submitted {timeSubmitted}
                                                                    </span>
                                                                    <DropdownMenu>
                                                                        <DropdownMenuTrigger asChild>
                                                                            <Button
                                                                                variant="outline"
                                                                                size="icon"
                                                                                className="h-7 w-7 p-0"
                                                                                disabled={!!isCancelled}
                                                                            >
                                                                                <MoreVertical className="h-4 w-4" />
                                                                            </Button>
                                                                        </DropdownMenuTrigger>
                                                                        <DropdownMenuContent align="end">
                                                                            <DropdownMenuItem
                                                                                disabled={!canUnsubmit(req, defenseRequest)}
                                                                                onSelect={(e) => {
                                                                                    e.preventDefault();
                                                                                    if (!canUnsubmit(req, defenseRequest)) return;
                                                                                    setUnsubmitTargetId(req.id);
                                                                                    setUnsubmitReason('');
                                                                                    setUnsubmitOtherReason('');
                                                                                    setUnsubmitDialogOpen(true);
                                                                                }}
                                                                            >
                                                                                Unsubmit
                                                                            </DropdownMenuItem>
                                                                        </DropdownMenuContent>
                                                                    </DropdownMenu>
                                                                </div>
                                                            </div>

                                                            <DefenseRequestProgress request={merged} />

                                                            {/* Compact two-column info list (no duplicated status, more spacing) */}
                                                            <div className="grid grid-cols-1 gap-x-8 gap-y-5 text-xs sm:grid-cols-2">
                                                                {/* Thesis Title */}
                                                                <div className="min-w-0">
                                                                    <div className="text-muted-foreground flex items-center gap-1.5 text-[11px]">
                                                                        <InfoIcon className="h-3.5 w-3.5" />
                                                                        Thesis Title
                                                                    </div>
                                                                    <div className="font-medium break-words text-zinc-900 dark:text-zinc-100">
                                                                        {merged.thesis_title || '—'}
                                                                    </div>
                                                                </div>
                                                                {/* Defense Type */}
                                                                <div className="min-w-0">
                                                                    <div className="text-muted-foreground flex items-center gap-1.5 text-[11px]">
                                                                        <GraduationCap className="h-3.5 w-3.5" />
                                                                        Defense Type
                                                                    </div>
                                                                    <div className="font-medium text-zinc-900 dark:text-zinc-100">
                                                                        {req.defense_type || '—'}
                                                                    </div>
                                                                </div>

                                                                {/* Student */}
                                                                <div className="min-w-0">
                                                                    <div className="text-muted-foreground flex items-center gap-1.5 text-[11px]">
                                                                        <Users className="h-3.5 w-3.5" />
                                                                        Student
                                                                    </div>
                                                                    <div className="truncate font-medium text-zinc-900 dark:text-zinc-100">
                                                                        {req.first_name} {req.last_name}
                                                                    </div>
                                                                </div>
                                                                {/* Adviser */}
                                                                <div className="min-w-0">
                                                                    <div className="text-muted-foreground flex items-center gap-1.5 text-[11px]">
                                                                        <Users className="h-3.5 w-3.5" />
                                                                        Adviser
                                                                    </div>
                                                                    <div className="truncate font-medium text-zinc-900 dark:text-zinc-100">
                                                                        {req.adviser || '—'}
                                                                    </div>
                                                                </div>
                                                                {/* Program */}
                                                                <div className="min-w-0">
                                                                    <div className="text-muted-foreground flex items-center gap-1.5 text-[11px]">
                                                                        <GraduationCap className="h-3.5 w-3.5" />
                                                                        Program
                                                                    </div>
                                                                    <div className="font-medium break-words text-zinc-900 dark:text-zinc-100">
                                                                        {req.program || '—'}
                                                                    </div>
                                                                </div>
                                                                {/* Reference No. */}
                                                                <div className="min-w-0">
                                                                    <div className="text-muted-foreground flex items-center gap-1.5 text-[11px]">
                                                                        <Paperclip className="h-3.5 w-3.5" />
                                                                        Reference No.
                                                                    </div>
                                                                    <div className="truncate font-medium text-zinc-900 dark:text-zinc-100">
                                                                        {req.reference_no || '—'}
                                                                    </div>
                                                                </div>

                                                                {/* Date */}
                                                                <div className="min-w-0">
                                                                    <div className="text-muted-foreground flex items-center gap-1.5 text-[11px]">
                                                                        <Calendar className="h-3.5 w-3.5" />
                                                                        Date
                                                                    </div>
                                                                    <div className="font-medium">{formatDatePretty(merged.scheduled_date)}</div>
                                                                </div>
                                                                {/* Time */}
                                                                <div className="min-w-0">
                                                                    <div className="text-muted-foreground flex items-center gap-1.5 text-[11px]">
                                                                        <ClockIcon className="h-3.5 w-3.5" />
                                                                        Time
                                                                    </div>
                                                                    <div className="font-medium">
                                                                        {formatTimeRange(merged.scheduled_time, merged.scheduled_end_time)}
                                                                    </div>
                                                                </div>
                                                                {/* Venue */}
                                                                <div className="min-w-0">
                                                                    <div className="text-muted-foreground flex items-center gap-1.5 text-[11px]">
                                                                        <MapPin className="h-3.5 w-3.5" />
                                                                        Venue
                                                                    </div>
                                                                    <div className="font-medium">{merged.defense_venue || '—'}</div>
                                                                </div>
                                                                {/* Mode */}
                                                                <div className="min-w-0">
                                                                    <div className="text-muted-foreground text-[11px]">Mode</div>
                                                                    <div className="font-medium capitalize">{merged.defense_mode || '—'}</div>
                                                                </div>

                                                                {/* Notes (span 2) */}
                                                                <div className="min-w-0 sm:col-span-2">
                                                                    <div className="text-muted-foreground text-[11px]">Notes</div>
                                                                    <div className="font-medium break-words">{merged.scheduling_notes || '—'}</div>
                                                                </div>

                                                                {/* Committee (chips, span 2) */}
                                                                <div className="min-w-0 sm:col-span-2">
                                                                    <div className="text-muted-foreground flex items-center gap-1.5 text-[11px]">
                                                                        <Users className="h-3.5 w-3.5" />
                                                                        Committee
                                                                    </div>
                                                                    {[
                                                                        merged.defense_chairperson,
                                                                        merged.defense_panelist1,
                                                                        merged.defense_panelist2,
                                                                        merged.defense_panelist3,
                                                                        merged.defense_panelist4,
                                                                    ].some(Boolean) ? (
                                                                        <div className="mt-1.5 flex flex-wrap gap-1.5">
                                                                            {merged.defense_chairperson && (
                                                                                <span className="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-1 text-[11px] dark:bg-zinc-800">
                                                                                    <Users className="h-3.5 w-3.5 text-zinc-500" />
                                                                                    <span className="font-medium">{merged.defense_chairperson}</span>
                                                                                    <span className="text-zinc-500">(Chairperson)</span>
                                                                                </span>
                                                                            )}
                                                                            {merged.defense_panelist1 && (
                                                                                <span className="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-1 text-[11px] dark:bg-zinc-800">
                                                                                    <Users className="h-3.5 w-3.5 text-zinc-500" />
                                                                                    <span className="font-medium">{merged.defense_panelist1}</span>
                                                                                    <span className="text-zinc-500">(Panelist 1)</span>
                                                                                </span>
                                                                            )}
                                                                            {merged.defense_panelist2 && (
                                                                                <span className="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-1 text-[11px] dark:bg-zinc-800">
                                                                                    <Users className="h-3.5 w-3.5 text-zinc-500" />
                                                                                    <span className="font-medium">{merged.defense_panelist2}</span>
                                                                                    <span className="text-zinc-500">(Panelist 2)</span>
                                                                                </span>
                                                                            )}
                                                                            {merged.defense_panelist3 && (
                                                                                <span className="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-1 text-[11px] dark:bg-zinc-800">
                                                                                    <Users className="h-3.5 w-3.5 text-zinc-500" />
                                                                                    <span className="font-medium">{merged.defense_panelist3}</span>
                                                                                    <span className="text-zinc-500">(Panelist 3)</span>
                                                                                </span>
                                                                            )}
                                                                            {merged.defense_panelist4 && (
                                                                                <span className="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-1 text-[11px] dark:bg-zinc-800">
                                                                                    <Users className="h-3.5 w-3.5 text-zinc-500" />
                                                                                    <span className="font-medium">{merged.defense_panelist4}</span>
                                                                                    <span className="text-zinc-500">(Panelist 4)</span>
                                                                                </span>
                                                                            )}
                                                                        </div>
                                                                    ) : (
                                                                        <div className="text-muted-foreground text-[12px]">
                                                                            No committee assigned yet.
                                                                        </div>
                                                                    )}
                                                                </div>

                                                                {/* Attachments (span 2) */}
                                                                <div className="min-w-0 sm:col-span-2">
                                                                    <div className="text-muted-foreground flex items-center gap-1.5 text-[11px]">
                                                                        <Paperclip className="h-3.5 w-3.5" />
                                                                        Attachments
                                                                    </div>
                                                                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                                                                        {req.manuscript_proposal && (
                                                                            <a
                                                                                href={resolveFileUrl(req.manuscript_proposal) || undefined}
                                                                                target="_blank"
                                                                                rel="noopener noreferrer"
                                                                                className="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-1 text-[11px] dark:bg-zinc-800"
                                                                                style={{ textDecoration: 'none' }}
                                                                            >
                                                                                <Paperclip className="h-3.5 w-3.5" />
                                                                                <span className="max-w-[160px] truncate">
                                                                                    Manuscript •{' '}
                                                                                    {req.attachment_names?.manuscript_proposal || 'Document'}
                                                                                </span>
                                                                            </a>
                                                                        )}
                                                                        {req.similarity_index && (
                                                                            <a
                                                                                href={resolveFileUrl(req.similarity_index) || undefined}
                                                                                target="_blank"
                                                                                rel="noopener noreferrer"
                                                                                className="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-1 text-[11px] dark:bg-zinc-800"
                                                                                style={{ textDecoration: 'none' }}
                                                                            >
                                                                                <Paperclip className="h-3.5 w-3.5" />
                                                                                <span className="max-w-[160px] truncate">
                                                                                    Similarity •{' '}
                                                                                    {req.attachment_names?.similarity_index || 'Document'}
                                                                                </span>
                                                                            </a>
                                                                        )}
                                                                        {req.rec_endorsement && (
                                                                            <a
                                                                                href={resolveFileUrl(req.rec_endorsement) || undefined}
                                                                                target="_blank"
                                                                                rel="noopener noreferrer"
                                                                                className="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-1 text-[11px] dark:bg-zinc-800"
                                                                                style={{ textDecoration: 'none' }}
                                                                            >
                                                                                <Paperclip className="h-3.5 w-3.5" />
                                                                                <span className="max-w-[160px] truncate">
                                                                                    Endorsement •{' '}
                                                                                    {req.attachment_names?.rec_endorsement || 'Document'}
                                                                                </span>
                                                                            </a>
                                                                        )}
                                                                        {req.proof_of_payment && (
                                                                            <a
                                                                                href={resolveFileUrl(req.proof_of_payment) || undefined}
                                                                                target="_blank"
                                                                                rel="noopener noreferrer"
                                                                                className="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-1 text-[11px] dark:bg-zinc-800"
                                                                                style={{ textDecoration: 'none' }}
                                                                            >
                                                                                <Paperclip className="h-3.5 w-3.5" />
                                                                                <span className="max-w-[160px] truncate">
                                                                                    Payment • {req.attachment_names?.proof_of_payment || 'Document'}
                                                                                </span>
                                                                            </a>
                                                                        )}
                                                                        {req.avisee_adviser_attachment && (
                                                                            <a
                                                                                href={resolveFileUrl(req.avisee_adviser_attachment) || undefined}
                                                                                target="_blank"
                                                                                rel="noopener noreferrer"
                                                                                className="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-1 text-[11px] dark:bg-zinc-800"
                                                                                style={{ textDecoration: 'none' }}
                                                                            >
                                                                                <Paperclip className="h-3.5 w-3.5" />
                                                                                <span className="max-w-[160px] truncate">
                                                                                    Avisee–Adviser •{' '}
                                                                                    {req.attachment_names?.avisee_adviser_attachment || 'Document'}
                                                                                </span>
                                                                            </a>
                                                                        )}
                                                                        {!req.manuscript_proposal &&
                                                                            !req.similarity_index &&
                                                                            !req.rec_endorsement &&
                                                                            !req.proof_of_payment &&
                                                                            !req.avisee_adviser_attachment && (
                                                                                <div className="text-muted-foreground text-[12px]">
                                                                                    No attachments uploaded.
                                                                                </div>
                                                                            )}
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            {/* Completed callout */}
                                                            {wf === 'completed' && (
                                                                <div className="mt-4 rounded-md border border-green-200 bg-green-50 p-3 dark:bg-green-900/30">
                                                                    <div className="mb-1 text-xs font-bold text-green-700">
                                                                        Congratulations, your defense has been successfully completed!
                                                                    </div>
                                                                    <div className="text-xs text-zinc-700 dark:text-zinc-200">
                                                                        <b>Request for Oral Defense Certificate</b>
                                                                        <br />
                                                                        Please fill out this form:
                                                                        <br />
                                                                        <a
                                                                            href="https://docs.google.com/forms/d/e/1FAIpQLScIFYf8Z6L8q_N2qVEdS4koTJ7jv4HOFnhit-4LKXmOH--Ukg/viewform?usp=send_form"
                                                                            target="_blank"
                                                                            rel="noopener noreferrer"
                                                                            className="break-all text-blue-700 underline"
                                                                        >
                                                                            https://docs.google.com/forms/d/e/1FAIpQLScIFYf8Z6L8q_N2qVEdS4koTJ7jv4HOFnhit-4LKXmOH--Ukg/viewform?usp=send_form
                                                                        </a>
                                                                    </div>
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                </CollapsibleContent>
                                            </Collapsible>
                                        </React.Fragment>
                                    );
                                })
                        )}
                    </div>

                    {/* Unsubmit dialog */}
                    <Dialog open={unsubmitDialogOpen} onOpenChange={setUnsubmitDialogOpen}>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Are you sure?</DialogTitle>
                                <DialogDescription>Please select a reason for unsubmission.</DialogDescription>
                            </DialogHeader>
                            <div className="space-y-3">
                                <RadioGroup value={unsubmitReason} onValueChange={setUnsubmitReason}>
                                    <div className="mt-2 flex items-center gap-2">
                                        <RadioGroupItem value="mistake" id="reason-mistake" />
                                        <label htmlFor="reason-mistake" className="text-sm">
                                            I made a mistake in filling up the forms
                                        </label>
                                    </div>
                                    <div className="mt-2 flex items-center gap-2">
                                        <RadioGroupItem value="cancel" id="reason-cancel" />
                                        <label htmlFor="reason-cancel" className="text-sm">
                                            I'd like to just cancel the submission
                                        </label>
                                    </div>
                                    <div className="mt-2 flex items-center gap-2">
                                        <RadioGroupItem value="outdated" id="reason-outdated" />
                                        <label htmlFor="reason-outdated" className="text-sm">
                                            The submission was outdated
                                        </label>
                                    </div>
                                    <div className="mt-2 flex items-center gap-2">
                                        <RadioGroupItem value="other" id="reason-other" />
                                        <label htmlFor="reason-other" className="text-sm">
                                            Other...
                                        </label>
                                    </div>
                                </RadioGroup>
                                {unsubmitReason === 'other' && (
                                    <Input
                                        placeholder="Enter your reason"
                                        value={unsubmitOtherReason}
                                        onChange={(e) => setUnsubmitOtherReason(e.target.value)}
                                        className="text-sm"
                                    />
                                )}
                                <div className="mt-4 flex justify-end gap-2">
                                    <Button variant="outline" onClick={() => setUnsubmitDialogOpen(false)}>
                                        Cancel
                                    </Button>
                                    <Button
                                        disabled={processingUnsubmit || !unsubmitReason || (unsubmitReason === 'other' && !unsubmitOtherReason)}
                                        onClick={async () => {
                                            setProcessingUnsubmit(true);
                                            try {
                                                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                                                const res = await fetch(`/defense-requirements/${unsubmitTargetId}/unsubmit`, {
                                                    method: 'POST',
                                                    headers: {
                                                        'Content-Type': 'application/json',
                                                        Accept: 'application/json',
                                                        'X-CSRF-TOKEN': csrfToken || '',
                                                    },
                                                    body: JSON.stringify({
                                                        reason: unsubmitReason === 'other' ? unsubmitOtherReason : unsubmitReason,
                                                        _token: csrfToken || '',
                                                    }),
                                                });
                                                if (res.ok) {
                                                    setUnsubmitDialogOpen(false);
                                                    window.location.reload();
                                                } else {
                                                    let data: { message?: string } = {};
                                                    try {
                                                        data = await res.json();
                                                    } catch {
                                                        /* Keep the previous request visible if a background refresh fails. */
                                                    }
                                                    alert(data?.message || 'Failed to unsubmit.');
                                                }
                                            } catch {
                                                alert('Network error. Please try again.');
                                            } finally {
                                                setProcessingUnsubmit(false);
                                            }
                                        }}
                                    >
                                        Submit
                                    </Button>
                                </div>
                            </div>
                        </DialogContent>
                    </Dialog>

                    {/* Document generator dialog */}
                    {docGenRequest && <DocumentGeneratorDialog open={docGenOpen} onOpenChange={setDocGenOpen} defenseRequest={docGenRequest} />}
                </div>
            )}
        </AppLayout>
    );
}
