import { DefenseDateControl, DefenseTimeControl } from '@/components/defense-scheduling-controls';
import { csrfHeaders } from '@/lib/csrf';
import { errorMessage } from '@/lib/error-message';
import type { RequestActionResponse, WorkflowHistoryEntry } from '@/types/workflows';

import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { format } from 'date-fns';
import {
    ArrowLeft,
    Calendar,
    Check,
    CheckCircle,
    ChevronsUpDown,
    CircleArrowLeft,
    Clock,
    FileText,
    Loader2,
    Save,
    Send,
    Signature,
    User,
    UserCheck,
    Users,
    XCircle,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast, Toaster } from 'sonner';

import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { cn } from '@/lib/utils';
// add: ShadCN table + program level util
import { CorrectionsNotice, isAwaitingCorrections, RequestCorrectionsButton, type CorrectionDetails } from '@/components/defense-request-corrections';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { findPanelMember, getMemberReceivable, getMemberReceivableByProgramLevel } from '@/utils/payment-rates';

type PanelMemberOption = {
    id: string;
    name: string;
    email?: string;
    type?: string;
    status?: string;
    identity?: string;
    daily_defenses?: number;
    is_available?: boolean;
    bookings?: { start: string; end: string; overlaps: boolean; current: boolean }[];
};

type Availability = {
    key: string;
    options: PanelMemberOption[];
    adviser: { is_available: boolean; bookings: { start: string; end: string; overlaps: boolean }[] } | null;
};
const matchesPanel = (option: PanelMemberOption, value: string) =>
    String(option.id) === value || option.name === value || String(option.id) === 'panelist-' + value;
const displaySlotTime = (time: string) => {
    const [hour, minute] = time.split(':').map(Number);
    return `${hour % 12 || 12}:${String(minute).padStart(2, '0')} ${hour >= 12 ? 'PM' : 'AM'}`;
};
const slotLabel = (booking: { start: string; end: string }) => `${displaySlotTime(booking.start)}–${displaySlotTime(booking.end)}`;

export type DefenseRequestFull = CorrectionDetails & {
    id: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    school_id: string;
    program: string;
    thesis_title: string;
    defense_type: string;
    status?: string;
    priority?: 'Low' | 'Medium' | 'High';
    workflow_state?: string;
    defense_adviser?: string;
    attachment_names?: Record<string, string>;
    adviser_profile?: { id: number; name: string; email: string } | null;
    defense_chairperson?: string;
    defense_panelist1?: string;
    defense_panelist2?: string;
    defense_panelist3?: string;
    defense_panelist4?: string;
    scheduled_date?: string;
    scheduled_time?: string;
    scheduled_end_time?: string;
    defense_mode?: string;
    defense_venue?: string;
    scheduling_notes?: string;
    advisers_endorsement?: string;
    rec_endorsement?: string;
    proof_of_payment?: string;
    reference_no?: string;
    endorsement_form?: string | null;
    manuscript_proposal?: string | null;
    similarity_index?: string | null;
    avisee_adviser_attachment?: string | null;
    ai_detection_certificate?: string | null;
    last_status_updated_by?: string;
    last_status_updated_by_name?: string;
    last_status_updated_at?: string;
    workflow_history?: WorkflowHistoryEntry[];
    adviser_status?: string;
    coordinator_status?: string;
    program_level?: string; // "Masteral" | "Doctorate" from server
};

// add: local type for payment rate to avoid cross-file type conflicts
type PaymentRateRow = {
    program_level: string;
    type: string;
    defense_type: string;
    amount: number | string;
};

interface PageProps {
    defenseRequest: DefenseRequestFull;
    userRole: string;
}

function PanelMemberCombobox({
    label,
    value,
    onChange,
    options,
    disabled,
    taken,
}: {
    label: string;
    value: string;
    onChange: (v: string) => void;
    options: PanelMemberOption[];
    disabled?: boolean;
    taken: Set<string>;
}) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');

    const filtered = useMemo(() => {
        if (!q) return options;
        const qq = q.toLowerCase();
        return options.filter((o) => o.name.toLowerCase().includes(qq) || (o.email && o.email.toLowerCase().includes(qq)));
    }, [q, options]);

    return (
        <div className="space-y-1">
            <label className="text-xs font-medium">{label}</label>
            <Popover
                open={open}
                onOpenChange={(o) => {
                    setOpen(o);
                    if (!o) setQ('');
                }}
            >
                <PopoverTrigger asChild>
                    <button
                        type="button"
                        className="bg-background focus:ring-primary/40 hover:bg-accent flex h-9 w-full items-center justify-between rounded-md border px-3 text-left text-sm focus:ring-2 focus:outline-none disabled:opacity-50"
                        disabled={disabled}
                    >
                        <span className={`truncate ${!value ? 'text-muted-foreground' : ''}`}>
                            {options.find((o) => String(o.id) === value || o.name === value || String(o.id) === 'panelist-' + value)?.name ||
                                value ||
                                `Select ${label}`}
                        </span>
                        <ChevronsUpDown size={14} className="opacity-50" />
                    </button>
                </PopoverTrigger>
                <PopoverContent className="w-[320px] p-0" align="start" sideOffset={4} onOpenAutoFocus={(e) => e.preventDefault()}>
                    <Command shouldFilter={false}>
                        <CommandInput value={q} onValueChange={setQ} placeholder="Search..." className="h-9" autoFocus />
                        <CommandEmpty className="text-muted-foreground py-8 text-center text-sm">No matches.</CommandEmpty>
                        <CommandList>
                            <CommandGroup className="max-h-72 overflow-auto">
                                {filtered.map((o) => {
                                    const isSelected = String(o.id) === value || o.name === value || String(o.id) === 'panelist-' + value;
                                    const dup =
                                        (taken.has(o.identity || '') ||
                                            taken.has(o.name) ||
                                            taken.has(String(o.id)) ||
                                            taken.has(String(o.id).replace(/^panelist-/, ''))) &&
                                        !isSelected;
                                    return (
                                        <CommandItem
                                            key={o.id}
                                            value={o.name}
                                            disabled={dup || o.is_available === false}
                                            onSelect={() => {
                                                if (dup || o.is_available === false) return;
                                                onChange(String(o.id));
                                                setOpen(false);
                                                setQ('');
                                            }}
                                            className={`flex flex-col items-start gap-0.5 px-3 py-2 ${isSelected ? 'bg-muted' : ''} ${
                                                dup || o.is_available === false ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'
                                            }`}
                                        >
                                            <div className="flex w-full items-center gap-2">
                                                <Check size={14} className={isSelected ? 'text-muted-foreground opacity-100' : 'opacity-0'} />
                                                <span className="truncate text-sm">{o.name}</span>
                                            </div>
                                            {o.email && (
                                                <span className="text-muted-foreground max-w-[250px] truncate pl-5 text-[11px]">{o.email}</span>
                                            )}
                                            {o.daily_defenses !== undefined && (
                                                <span className="text-muted-foreground pl-5 text-[11px]">
                                                    {o.is_available ? 'Available for this time' : 'Time conflict'} · {o.daily_defenses}{' '}
                                                    {o.daily_defenses === 1 ? 'defense' : 'defenses'} that day
                                                </span>
                                            )}
                                            {o.bookings?.map((booking, index) => (
                                                <span
                                                    key={index}
                                                    className={cn(
                                                        'pl-5 text-[11px]',
                                                        booking.overlaps ? 'text-red-600 dark:text-red-400' : 'text-muted-foreground',
                                                    )}
                                                >
                                                    {booking.overlaps ? 'Overlaps' : 'Also scheduled'}: {slotLabel(booking)}
                                                </span>
                                            ))}
                                            {dup && !isSelected && <span className="text-muted-foreground pl-5 text-[10px]">Already chosen</span>}
                                        </CommandItem>
                                    );
                                })}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
            {options.find((option) => matchesPanel(option, value))?.is_available === false && (
                <p role="alert" className="text-xs text-red-600 dark:text-red-400">
                    This selection overlaps another defense. Choose another person or time.
                </p>
            )}
            {value && !['Chairperson', 'Panelist 1'].includes(label) && (
                <button type="button" disabled={disabled} className="text-muted-foreground text-xs underline" onClick={() => onChange('')}>
                    Clear selection
                </button>
            )}
        </div>
    );
}

export default function DefenseRequestDetailsPage(props: PageProps) {
    if (!props.defenseRequest) {
        return (
            <AppLayout breadcrumbs={[{ title: 'Dashboard', href: '/dashboard' }]}>
                <Head title="Defense Request - Not Found" />
                <div className="space-y-4 p-6">
                    <p className="text-sm text-red-500">No defense request loaded. Use the list page and click Details again.</p>
                    <Button variant="outline" onClick={() => router.visit('/defense-request')}>
                        Back to list
                    </Button>
                </div>
            </AppLayout>
        );
    }

    return <LoadedDefenseRequestDetailsPage key={props.defenseRequest.id} {...props} />;
}

function LoadedDefenseRequestDetailsPage(rawProps: PageProps) {
    const props: PageProps = rawProps || {};
    const requestProp = props.defenseRequest;
    const userRole: string = props.userRole || '';

    // Build breadcrumbs, use thesis title (truncated) instead of id
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Defense Requests', href: '/defense-request' },
    ];
    if (requestProp?.id) {
        const thesisForCrumb =
            (requestProp.thesis_title?.length || 0) > 60
                ? requestProp.thesis_title.slice(0, 57) + '...'
                : requestProp.thesis_title || `Request #${requestProp.id}`;
        breadcrumbs.push({
            title: thesisForCrumb,
            href: `/coordinator/defense-requests/${requestProp.id}/details`,
        });
    }

    const [request, setRequest] = useState<DefenseRequestFull>(requestProp);
    useEffect(() => {
        setRequest(requestProp);
    }, [requestProp]);
    const awaitingCorrections = isAwaitingCorrections(request.workflow_state);

    // Confirmation dialog state for approve/reject/retrieve
    const [confirm, setConfirm] = useState<{ open: boolean; action: 'approve' | 'reject' | 'retrieve' | null }>({
        open: false,
        action: null,
    });
    const [isLoading, setIsLoading] = useState(false);

    // Panel members simple load
    const [panelMembers, setPanelMembers] = useState<PanelMemberOption[]>([]);
    const [loadingMembers, setLoadingMembers] = useState(false);
    const [panelLoadError, setPanelLoadError] = useState<string | null>(null);

    // add: payment rates state
    const [paymentRates, setPaymentRates] = useState<PaymentRateRow[]>([]);

    useEffect(() => {
        let alive = true;
        async function loadAll() {
            setLoadingMembers(true);
            setPanelLoadError(null);
            const endpoints = ['/coordinator/panel-members', '/api/panel-members'];
            let loaded = false;
            for (const url of endpoints) {
                try {
                    const r = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (!r.ok) {
                        console.warn('Panel member fetch failed', url, r.status);
                        continue;
                    }
                    const data = await r.json();
                    if (alive) {
                        // Keep combined list (adviser + panelists) so we can resolve emails and ids robustly
                        setPanelMembers(Array.isArray(data) ? data : []);
                        loaded = true;
                    }
                    break;
                } catch (e) {
                    console.warn('Fetch error', url, e);
                }
            }
            if (!loaded && alive) {
                setPanelMembers([]);
                setPanelLoadError('Could not load panel members.');
            }
            if (alive) setLoadingMembers(false);
        }
        loadAll();
        return () => {
            alive = false;
        };
    }, []);

    // fetch payment rates once
    useEffect(() => {
        let alive = true;
        (async () => {
            try {
                const r = await fetch('/dean/payment-rates/data', { headers: { Accept: 'application/json' } });
                const json = await r.json();
                const arr = Array.isArray(json?.rates) ? json.rates : Array.isArray(json) ? json : [];
                if (alive) setPaymentRates(arr);
            } catch {
                if (alive) setPaymentRates([]);
            }
        })();
        return () => {
            alive = false;
        };
    }, []);

    // Panels
    const [panels, setPanels] = useState({
        defense_chairperson: request.defense_chairperson ?? '',
        defense_panelist1: request.defense_panelist1 ?? '',
        defense_panelist2: request.defense_panelist2 ?? '',
        defense_panelist3: request.defense_panelist3 ?? '',
        defense_panelist4: request.defense_panelist4 ?? '',
    });

    // Schedule
    const [schedule, setSchedule] = useState({
        scheduled_date: request.scheduled_date?.slice(0, 10) ?? '',
        scheduled_time: request.scheduled_time?.slice(0, 5) ?? '',
        scheduled_end_time: request.scheduled_end_time?.slice(0, 5) ?? '',
        defense_mode: request.defense_mode ?? '',
        defense_venue: request.defense_venue ?? '',
        scheduling_notes: request.scheduling_notes ?? '',
    });

    const [savingPanels, setSavingPanels] = useState(false);
    const [savingSchedule, setSavingSchedule] = useState(false);

    const [availability, setAvailability] = useState<Availability | null>(null);
    const [availabilityError, setAvailabilityError] = useState('');
    const [availabilityRevision, setAvailabilityRevision] = useState(0);
    const slotKey = `${schedule.scheduled_date}|${schedule.scheduled_time}|${schedule.scheduled_end_time}|${availabilityRevision}`;
    const validSlot =
        !!schedule.scheduled_date &&
        !!schedule.scheduled_time &&
        !!schedule.scheduled_end_time &&
        schedule.scheduled_end_time > schedule.scheduled_time;
    const availabilityReady = validSlot && availability?.key === slotKey;
    const availableOptions = availabilityReady ? availability.options : panelMembers;
    const selectedConflicts =
        availabilityReady &&
        Object.values(panels).some((value) => value && !availability.options.some((option) => matchesPanel(option, value) && option.is_available));
    const adviserConflict = availabilityReady && availability.adviser?.is_available === false;
    const changingScheduledSlot =
        request.workflow_state === 'scheduled' &&
        (schedule.scheduled_date !== request.scheduled_date?.slice(0, 10) ||
            schedule.scheduled_time !== request.scheduled_time?.slice(0, 5) ||
            schedule.scheduled_end_time !== request.scheduled_end_time?.slice(0, 5));
    useEffect(() => {
        if (!validSlot) return;
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            setAvailabilityError('');
            try {
                const query = new URLSearchParams({
                    date: schedule.scheduled_date,
                    time: schedule.scheduled_time,
                    end_time: schedule.scheduled_end_time,
                });
                const response = await fetch(`/coordinator/defense-requests/${request.id}/panel-availability?${query}`, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                    credentials: 'same-origin',
                });
                const data = await response.json();
                if (!response.ok) throw new Error(data.message || 'Could not check panel availability.');
                if (!controller.signal.aborted) setAvailability({ ...data, key: slotKey });
            } catch (error) {
                if (!controller.signal.aborted) setAvailabilityError(error instanceof Error ? error.message : 'Could not check panel availability.');
            }
        }, 200);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [request.id, schedule.scheduled_date, schedule.scheduled_time, schedule.scheduled_end_time, validSlot, slotKey]);
    const taken = useMemo(() => {
        const values = Object.values(panels).filter(Boolean);
        const identities = panelMembers
            .filter((option) => values.some((value) => matchesPanel(option, value)))
            .map((option) => (option.email ? 'email:' + option.email.toLowerCase() : option.identity || option.name));
        return new Set([...values, ...identities]);
    }, [panels, panelMembers]);

    const canEdit = ['Coordinator', 'Administrative Assistant', 'Dean'].includes(userRole);

    async function fetchWithSession(url: string, options: RequestInit) {
        return fetch(url, { ...options, credentials: 'same-origin', headers: { ...options.headers, ...csrfHeaders(true) } });
    }

    async function savePanels() {
        if (!availabilityReady || selectedConflicts || adviserConflict || changingScheduledSlot) {
            toast.error(
                'Check the selected time and panel availability first. For an existing defense, save time and panel changes together with Save Schedule.',
            );
            return;
        }
        const toastId = toast.loading('Saving panel assignments...');
        setSavingPanels(true);
        try {
            const res = await fetchWithSession(`/coordinator/defense-requests/${request.id}/panels`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    ...panels,
                    availability_date: schedule.scheduled_date,
                    availability_time: schedule.scheduled_time,
                    availability_end_time: schedule.scheduled_end_time,
                }),
            });

            const contentType = res.headers.get('content-type') || '';
            let data: RequestActionResponse<DefenseRequestFull> = {};
            try {
                if (contentType.includes('application/json')) {
                    data = await res.json();
                } else {
                    const txt = await res.text();
                    data = { error: txt };
                }
            } catch {
                data = { error: 'Invalid response' };
            }

            if (res.ok && data.ok) {
                setRequest((r) => ({ ...r, ...data.request }));
                setAvailabilityRevision((revision) => revision + 1);
                toast.success('Panels saved', {
                    id: toastId,
                    description: [
                        panels.defense_chairperson,
                        panels.defense_panelist1,
                        panels.defense_panelist2,
                        panels.defense_panelist3,
                        panels.defense_panelist4,
                    ]
                        .filter(Boolean)
                        .join(', '),
                });
            } else {
                toast.error(
                    data.error ||
                        Object.values(data.errors ?? {})
                            .flat()
                            .join(' ') ||
                        data.message ||
                        `Failed (${res.status})`,
                    { id: toastId },
                );
            }
        } catch {
            toast.error('Network error saving panels', { id: toastId });
        } finally {
            setSavingPanels(false);
        }
    }

    async function saveSchedule() {
        if (!availabilityReady || selectedConflicts || adviserConflict || !panels.defense_chairperson || !panels.defense_panelist1) {
            toast.error('Choose a valid time, a chairperson, and at least one panel member without time conflicts.');
            return;
        }
        // Validate times
        if (
            !schedule.scheduled_date ||
            !schedule.scheduled_time ||
            !schedule.scheduled_end_time ||
            !schedule.defense_mode ||
            !schedule.defense_venue
        ) {
            toast.error('Please fill in all required scheduling fields.');
            return;
        }
        // Time logic: start < end
        if (schedule.scheduled_time && schedule.scheduled_end_time) {
            const [sh, sm] = schedule.scheduled_time.split(':').map(Number);
            const [eh, em] = schedule.scheduled_end_time.split(':').map(Number);
            const start = sh * 60 + sm;
            const end = eh * 60 + em;
            if (end <= start) {
                toast.error('End time must be after start time.');
                return;
            }
        }
        const toastId = toast.loading('Saving schedule...');
        setSavingSchedule(true);
        try {
            const res = await fetchWithSession(`/coordinator/defense-requests/${request.id}/schedule-json`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({ ...schedule, ...panels }),
            });

            const contentType = res.headers.get('content-type') || '';
            let data: RequestActionResponse<DefenseRequestFull> = {};
            try {
                if (contentType.includes('application/json')) {
                    data = await res.json();
                } else {
                    const txt = await res.text();
                    data = { error: txt };
                }
            } catch {
                data = { error: 'Invalid response' };
            }

            if (res.ok && data.request) {
                setRequest((r) => ({ ...r, ...data.request }));
                setAvailabilityRevision((revision) => revision + 1);
                toast.success('Schedule saved', {
                    id: toastId,
                    description: `${schedule.scheduled_date || ''} ${schedule.scheduled_time || ''}${
                        schedule.scheduled_end_time ? ' - ' + schedule.scheduled_end_time : ''
                    }`,
                });
            } else {
                toast.error(
                    Object.values(data.errors ?? {})
                        .flat()
                        .join(' ') ||
                        data.error ||
                        data.message ||
                        `Failed (${res.status})`,
                    { id: toastId },
                );
            }
        } catch (err: unknown) {
            if (err instanceof Error && errorMessage(err) === 'network') {
                toast.error('Network error saving schedule', { id: toastId });
            } else {
                toast.error('Unknown error saving schedule', { id: toastId });
            }
        } finally {
            setSavingSchedule(false);
        }
    }

    // --- Approve/Reject/Retrieve logic (robust, uses fetchWithSession) ---
    async function handleStatusChange(action: 'approve' | 'reject' | 'retrieve') {
        if (!request.id || awaitingCorrections) return;
        setIsLoading(true);

        let newStatus: 'Pending' | 'Approved' | 'Rejected' = 'Pending';
        if (action === 'approve') newStatus = 'Approved';
        else if (action === 'reject') newStatus = 'Rejected';
        else if (action === 'retrieve') newStatus = 'Pending';

        try {
            const res = await fetchWithSession(`/defense-requests/${request.id}/status`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({ status: newStatus }),
            });
            let data: RequestActionResponse<DefenseRequestFull> = {};
            try {
                data = await res.json();
            } catch {
                data = { error: 'Invalid response from server.' };
            }
            if (res.ok) {
                if (data.request) {
                    setRequest(data.request);
                } else {
                    setRequest((r) => ({
                        ...r,
                        coordinator_status: newStatus,
                        workflow_state: data.workflow_state || r.workflow_state,
                        workflow_history: data.workflow_history || r.workflow_history,
                        status: data.status || r.status,
                    }));
                }
                toast.success(`Coordinator status set to ${newStatus}`);
            } else {
                toast.error(data?.error || `Failed to update status (${res.status})`);
            }
        } catch (err: unknown) {
            if (err instanceof Error && errorMessage(err) === 'network') {
                toast.error('Network error updating status. Please check your connection and try again.');
            } else {
                toast.error('Unknown error updating status.');
            }
        } finally {
            setIsLoading(false);
            setConfirm({ open: false, action: null });
        }
    }

    function formatDate(d?: string) {
        if (!d) return '—';
        try {
            return format(new Date(d), 'PPP');
        } catch {
            return d;
        }
    }

    // Helper to resolve file URLs (add /storage/ if not already absolute)
    function resolveFileUrl(url?: string | null) {
        if (!url) return null;
        if (/^https?:\/\//i.test(url) || url.startsWith('/storage/')) return url;
        return `/storage/${url.replace(/^\/?storage\//, '')}`;
    }

    // Attachments (show all, including manuscript, similarity, avisee, etc.)
    const attachments = [
        {
            label: 'Adviser’s Endorsement',
            name: request.attachment_names?.advisers_endorsement || 'Adviser’s Endorsement',
            url: resolveFileUrl(request.advisers_endorsement),
        },
        {
            label: 'REC Endorsement',
            name: request.attachment_names?.rec_endorsement || 'REC Endorsement',
            url: resolveFileUrl(request.rec_endorsement),
        },
        {
            label: 'Proof of Payment',
            name: request.attachment_names?.proof_of_payment || 'Proof of Payment',
            url: resolveFileUrl(request.proof_of_payment),
        },
        {
            label: 'Manuscript',
            name: request.attachment_names?.manuscript_proposal || 'Manuscript',
            url: resolveFileUrl(request.manuscript_proposal),
        },
        {
            label: 'Similarity Index',
            name: request.attachment_names?.similarity_index || 'Similarity Index',
            url: resolveFileUrl(request.similarity_index),
        },
        {
            label: 'Avisee-Adviser File',
            name: request.attachment_names?.avisee_adviser_attachment || 'Avisee-Adviser File',
            url: resolveFileUrl(request.avisee_adviser_attachment),
        },
        {
            label: 'AI Detection Certificate',
            name: request.attachment_names?.ai_detection_certificate || 'AI Detection Certificate',
            url: resolveFileUrl(request.ai_detection_certificate),
        },
        {
            label: 'Endorsement Form',
            name: request.attachment_names?.endorsement_form || 'Endorsement Form',
            url: resolveFileUrl(request.endorsement_form),
        },
    ];

    // Helper for workflow history rendering robustness
    function resolveHistoryFields(item: WorkflowHistoryEntry) {
        const event = item.event_type || item.action || item.status_change || item.status || item.type || 'Event';
        const desc = item.description || item.details || item.note || item.notes || item.remarks || '';
        const from = item.from_state || item.from || item.previous_state;
        const to = item.to_state || item.to || item.new_state;
        const created = item.created_at || item.timestamp || item.performed_at || item.date || '';
        const userName = item.user_name || item.user?.name || item.actor_name || item.actor || item.performed_by_name || item.performed_by || '';
        return { event, desc, from, to, created, userName };
    }

    const sectionClass = 'rounded-lg border p-5 space-y-3'; // increased padding

    // Helper to get initials for avatar
    function getInitials(user: { first_name?: string; last_name?: string }) {
        const first = user.first_name?.trim()?.[0] ?? '';
        const last = user.last_name?.trim()?.[0] ?? '';
        return (first + last).toUpperCase() || 'U';
    }

    // Helper for readable date
    function formatReadableDate(d?: string) {
        if (!d) return '';
        try {
            return format(new Date(d), 'PPpp');
        } catch {
            return d;
        }
    }

    // Workflow stepper config
    const workflowSteps = [
        {
            key: 'submitted',
            label: 'Submitted',
            icon: <Send className="h-5 w-5" />,
        },
        {
            key: 'adviser-approved',
            label: 'Endorsed by Adviser', // CHANGED from "Approved by Adviser"
            icon: <UserCheck className="h-5 w-5" />,
        },
        {
            key: 'coordinator-approved',
            label: 'Approved by Coordinator',
            icon: <Signature className="h-5 w-5" />,
        },
        {
            key: 'rejected',
            label: 'Rejected by Coordinator',
            icon: <XCircle className="h-5 w-5" />,
        },
        {
            key: 'retrieved',
            label: 'Retrieved (Set to Pending)',
            icon: <CircleArrowLeft className="h-5 w-5" />,
        },
        {
            key: 'panels-assigned',
            label: 'Panels Assigned',
            icon: <User className="h-5 w-5" />,
        },
        {
            key: 'scheduled',
            label: 'Scheduled',
            icon: <Clock className="h-5 w-5" />,
        },
        // Add more steps as needed
    ];

    // Update this function:
    function getStepForEvent(event: string) {
        event = (event || '').toLowerCase();
        if (event === 'resubmitted' || event.includes('correction')) return '';
        if (event.includes('submit')) return 'submitted';
        if (event.includes('adviser')) return 'adviser-approved';
        if (event.includes('rejected')) return 'rejected';
        if (event.includes('retrieved')) return 'retrieved';
        if (event.includes('coordinator')) return 'coordinator-approved';
        if (event.includes('panel')) return 'panels-assigned';
        if (event.includes('schedule')) return 'scheduled';
        return '';
    }

    function formatTime12h(time?: string) {
        if (!time) return '';
        const [h, m] = time.split(':');
        const date = new Date();
        date.setHours(Number(h), Number(m));
        return format(date, 'hh:mm a');
    }

    // Tabs: "details" and "assign-schedule"
    const [tab, setTab] = useState<'details' | 'assign-schedule'>('details');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Toaster position="bottom-right" richColors closeButton />
            <div className="space-y-6 p-5">
                <Head title={request.thesis_title || `Defense Request #${request.id}`} />
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <Button variant="outline" onClick={() => router.visit('/defense-request')} className="h-8 px-2">
                            <ArrowLeft className="h-4 w-4" />
                        </Button>
                        <Tabs value={tab} onValueChange={(v) => setTab(v as 'details' | 'assign-schedule')}>
                            <TabsList className="h-8">
                                <TabsTrigger value="details" className="flex items-center gap-1 px-3 text-sm">
                                    <FileText className="h-4 w-4" /> Details
                                </TabsTrigger>
                                <TabsTrigger value="assign-schedule" className="flex items-center gap-1 px-3 text-sm font-medium">
                                    <Calendar className="h-4 w-4" />
                                    Assign &amp; Schedule
                                </TabsTrigger>
                            </TabsList>
                        </Tabs>
                    </div>
                    {canEdit && (
                        <div className="flex flex-wrap gap-2">
                            {userRole === 'Coordinator' && ['adviser-approved', 'coordinator-review'].includes(request.workflow_state || '') && (
                                <RequestCorrectionsButton
                                    requestId={request.id}
                                    stage="coordinator"
                                    disabled={isLoading}
                                    onReturned={(updated) => setRequest((current) => ({ ...current, ...updated }))}
                                />
                            )}
                            {/* Approve button: only enabled if not already approved or completed */}
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setConfirm({ open: true, action: 'approve' })}
                                disabled={
                                    isLoading ||
                                    awaitingCorrections ||
                                    request.coordinator_status === 'Approved' ||
                                    request.workflow_state === 'completed'
                                }
                            >
                                <CheckCircle className="mr-1 h-4 w-4 text-green-600" />
                                Approve
                            </Button>
                            {/* Reject button: only enabled if not already rejected, not approved, and not completed */}
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setConfirm({ open: true, action: 'reject' })}
                                disabled={
                                    isLoading ||
                                    awaitingCorrections ||
                                    request.coordinator_status === 'Rejected' ||
                                    request.coordinator_status === 'Approved' ||
                                    request.workflow_state === 'completed'
                                }
                            >
                                <XCircle className="mr-1 h-4 w-4 text-red-600" />
                                Reject
                            </Button>
                            {/* Retrieve button: enabled if currently rejected OR approved and not completed */}
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setConfirm({ open: true, action: 'retrieve' })}
                                disabled={
                                    isLoading ||
                                    awaitingCorrections ||
                                    !['Rejected', 'Approved'].includes(request.coordinator_status || '') ||
                                    request.workflow_state === 'completed'
                                }
                            >
                                <CircleArrowLeft className="mr-1 h-4 w-4 text-blue-600" />
                                Retrieve
                            </Button>
                        </div>
                    )}
                </div>

                <CorrectionsNotice request={request} />

                {/* Main content and Workflow Progress */}
                <div className="mb-2 flex flex-col gap-5 md:flex-row">
                    {/* Main column */}
                    <div className="mx-auto flex w-full flex-col gap-5 md:max-w-3xl">
                        <Tabs value={tab} onValueChange={(v) => setTab(v as 'details' | 'assign-schedule')} className="w-full">
                            {/* DETAILS TAB */}
                            <TabsContent value="details" className="space-y-5">
                                {/* Submission summary card */}
                                <div className="rounded-xl border bg-white p-8 dark:bg-zinc-900">
                                    {/* Thesis Title Header with Status */}
                                    <div className="mb-1 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                        <div>
                                            <div className="text-2xl font-semibold">{request.thesis_title}</div>
                                            <div className="text-muted-foreground mt-0.5 text-xs font-medium">Thesis Title</div>
                                        </div>
                                        <div className="flex flex-col gap-1 md:items-end">
                                            {/* Coordinator Status */}
                                            {request.coordinator_status && (
                                                <span
                                                    className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
                                                        request.coordinator_status === 'Approved'
                                                            ? 'bg-green-100 text-green-600'
                                                            : request.coordinator_status === 'Rejected'
                                                              ? 'bg-red-100 text-red-600'
                                                              : request.coordinator_status === 'Needs Signature'
                                                                ? 'bg-blue-100 text-blue-600'
                                                                : request.coordinator_status === 'Not Scheduled'
                                                                  ? 'bg-yellow-100 text-yellow-700'
                                                                  : request.coordinator_status === 'No Assigned Panelists'
                                                                    ? 'bg-orange-100 text-orange-600'
                                                                    : 'bg-gray-100 text-gray-600'
                                                    }`}
                                                >
                                                    Coordinator Status: {request.coordinator_status}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                    {/* Info Grid */}
                                    <div className="mt-6 grid grid-cols-1 gap-x-12 gap-y-6 md:grid-cols-2">
                                        <div>
                                            <div className="text-muted-foreground mb-1 text-xs">Presenter</div>
                                            <div className="flex items-center gap-3">
                                                <Avatar className="h-8 w-8">
                                                    <AvatarFallback className="bg-zinc-200 text-base font-bold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                                        {getInitials(request)}
                                                    </AvatarFallback>
                                                </Avatar>
                                                <div>
                                                    <div className="text-sm leading-tight font-medium">
                                                        {request.first_name} {request.middle_name ? `${request.middle_name} ` : ''}
                                                        {request.last_name}
                                                    </div>
                                                    <div className="text-muted-foreground mt-0.5 text-xs">{request.school_id}</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <div className="text-muted-foreground mb-1 text-xs">Program</div>
                                            <div className="text-sm font-medium">{request.program}</div>
                                        </div>
                                        <div>
                                            <div className="text-muted-foreground mb-1 text-xs">Defense Type</div>
                                            <Badge variant="secondary" className="text-xs font-medium">
                                                {request.defense_type ?? '—'}
                                            </Badge>
                                        </div>
                                        <div>
                                            <div className="text-muted-foreground mb-1 text-xs">Scheduled Date</div>
                                            <div className="text-sm font-medium">{formatDate(request.scheduled_date)}</div>
                                        </div>
                                        <div>
                                            <div className="text-muted-foreground mb-1 text-xs">Time</div>
                                            <div className="text-sm font-medium">
                                                {request.scheduled_time
                                                    ? `${formatTime12h(request.scheduled_time)}${request.scheduled_end_time ? ' - ' + formatTime12h(request.scheduled_end_time) : ''}`
                                                    : '—'}
                                            </div>
                                        </div>
                                        <div>
                                            <div className="text-muted-foreground mb-1 text-xs">Venue</div>
                                            <div className="text-sm font-medium">{request.defense_venue || '—'}</div>
                                        </div>
                                        <div>
                                            <div className="text-muted-foreground mb-1 text-xs">Mode</div>
                                            <div className="text-sm font-medium">
                                                {request.defense_mode ? (request.defense_mode === 'face-to-face' ? 'Face-to-Face' : 'Online') : '—'}
                                            </div>
                                        </div>
                                        <div className="md:col-span-2">
                                            <div className="text-muted-foreground mb-1 text-xs">Notes</div>
                                            <div className="text-sm font-medium">{request.scheduling_notes || '—'}</div>
                                        </div>
                                    </div>
                                </div>

                                {/* Committee (ShadCN table with receivables) */}
                                <div className="space-y-3 rounded-lg border p-5">
                                    <h2 className="flex items-center gap-2 text-sm font-semibold">
                                        <Users className="h-4 w-4" /> Committee
                                    </h2>
                                    <Separator />
                                    {(() => {
                                        const memberFor = (value: string | null | undefined, fallbackRole: string) => {
                                            const resolved = findPanelMember(panelMembers, value);
                                            return {
                                                displayName: resolved?.name || value || '—',
                                                email: resolved?.email || '',
                                                rawValue: value || '',
                                                role: fallbackRole,
                                            };
                                        };

                                        const rows = [
                                            {
                                                key: 'adviser',
                                                info: request.adviser_profile
                                                    ? {
                                                          displayName: request.adviser_profile.name,
                                                          email: request.adviser_profile.email,
                                                          rawValue: String(request.adviser_profile.id),
                                                          role: 'Adviser',
                                                      }
                                                    : memberFor(request.defense_adviser, 'Adviser'),
                                            },
                                            {
                                                key: 'defense_chairperson',
                                                info: memberFor(panels.defense_chairperson || request.defense_chairperson, 'Panel Chair'),
                                            },
                                            {
                                                key: 'defense_panelist1',
                                                info: memberFor(panels.defense_panelist1 || request.defense_panelist1, 'Panel Member'),
                                            },
                                            {
                                                key: 'defense_panelist2',
                                                info: memberFor(panels.defense_panelist2 || request.defense_panelist2, 'Panel Member'),
                                            },
                                            {
                                                key: 'defense_panelist3',
                                                info: memberFor(panels.defense_panelist3 || request.defense_panelist3, 'Panel Member'),
                                            },
                                            {
                                                key: 'defense_panelist4',
                                                info: memberFor(panels.defense_panelist4 || request.defense_panelist4, 'Panel Member'),
                                            },
                                        ].map((r) => {
                                            const namePresent = !!(r.info.rawValue || (r.info.displayName && r.info.displayName !== '—'));
                                            const emailPresent = !!r.info.email;
                                            const adviserEndorsed =
                                                r.key === 'adviser' &&
                                                (request.adviser_status === 'Approved' ||
                                                    [
                                                        'adviser-approved',
                                                        'coordinator-review',
                                                        'coordinator-approved',
                                                        'panels-assigned',
                                                        'scheduled',
                                                        'completed',
                                                    ].includes(request.workflow_state || ''));
                                            const status = adviserEndorsed
                                                ? 'Endorsed'
                                                : namePresent
                                                  ? emailPresent
                                                      ? 'Assigned'
                                                      : 'Email unavailable'
                                                  : '\u2014';

                                            const receivable = namePresent
                                                ? request.program_level
                                                    ? getMemberReceivableByProgramLevel(
                                                          paymentRates,
                                                          request.program_level,
                                                          request.defense_type,
                                                          r.info.role,
                                                      )
                                                    : getMemberReceivable(paymentRates, request.program, request.defense_type, r.info.role)
                                                : null;

                                            return {
                                                name: r.info.displayName,
                                                email: r.info.email || '—',
                                                role: r.info.role,
                                                status,
                                                receivable,
                                            };
                                        });

                                        const formatCurrency = (v: string | number | null | undefined) =>
                                            typeof v === 'number'
                                                ? new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(v)
                                                : (v ?? '—');

                                        return (
                                            <div className="overflow-x-auto rounded-md border">
                                                <Table>
                                                    <TableHeader>
                                                        <TableRow>
                                                            <TableHead className="min-w-[180px]">Name</TableHead>
                                                            <TableHead className="min-w-[220px]">Email</TableHead>
                                                            <TableHead className="min-w-[120px]">Role</TableHead>
                                                            <TableHead className="min-w-[120px]">Status</TableHead>
                                                            <TableHead className="min-w-[160px] text-right">Receivable</TableHead>
                                                        </TableRow>
                                                    </TableHeader>
                                                    <TableBody>
                                                        {rows.map((r, idx) => (
                                                            <TableRow key={idx}>
                                                                <TableCell className="font-medium">{r.name}</TableCell>
                                                                <TableCell className="text-muted-foreground text-xs">{r.email || '—'}</TableCell>
                                                                <TableCell>{r.role}</TableCell>
                                                                <TableCell>
                                                                    <Badge
                                                                        variant="secondary"
                                                                        className={
                                                                            r.status === 'Assigned'
                                                                                ? 'bg-green-100 text-green-700'
                                                                                : r.status === 'Email unavailable'
                                                                                  ? 'bg-yellow-100 text-amber-700'
                                                                                  : 'bg-amber-100 text-amber-700'
                                                                        }
                                                                    >
                                                                        {r.status}
                                                                    </Badge>
                                                                </TableCell>
                                                                <TableCell className="text-right">{formatCurrency(r.receivable)}</TableCell>
                                                            </TableRow>
                                                        ))}
                                                    </TableBody>
                                                </Table>
                                            </div>
                                        );
                                    })()}
                                </div>

                                {/* Attachments */}
                                <div className={sectionClass}>
                                    <h2 className="flex items-center gap-2 text-sm font-semibold">
                                        <FileText className="h-4 w-4" /> Attachments
                                    </h2>
                                    <Separator />
                                    <div className="space-y-2 text-sm">
                                        {attachments.filter((a) => !!a.url).length === 0 && (
                                            <p className="text-muted-foreground text-sm">No attachments.</p>
                                        )}
                                        {attachments
                                            .filter((a) => !!a.url)
                                            .map((a) => (
                                                <a
                                                    key={a.label}
                                                    href={a.url!}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="hover:bg-muted flex items-center gap-2 rounded-md border bg-white px-3 py-2 transition dark:bg-zinc-900"
                                                >
                                                    <FileText className="h-4 w-4" />
                                                    <span className="font-medium">{a.label}</span>
                                                    <span className="text-muted-foreground ml-auto max-w-[180px] truncate text-xs">{a.name}</span>
                                                </a>
                                            ))}
                                        {/* Reference No. as plain text if present */}
                                        {request.reference_no && (
                                            <div className="flex items-center gap-2 rounded-md border bg-white px-3 py-2 dark:bg-zinc-900">
                                                <FileText className="h-4 w-4" />
                                                <span className="font-medium">Reference No.</span>
                                                <span className="ml-auto text-xs">{request.reference_no}</span>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </TabsContent>

                            {/* ASSIGN & SCHEDULE TAB */}
                            <TabsContent value="assign-schedule" className="space-y-5">
                                {/* Scheduling */}
                                <div className={sectionClass}>
                                    <h2 className="flex items-center gap-2 text-sm font-semibold">
                                        <Calendar className="h-4 w-4" /> Scheduling
                                    </h2>
                                    <Separator />
                                    <p className="text-muted-foreground text-xs">
                                        Choose the defense time in Philippine time, then select panel members below.
                                    </p>
                                    {schedule.scheduled_time &&
                                        schedule.scheduled_end_time &&
                                        schedule.scheduled_end_time <= schedule.scheduled_time && (
                                            <p role="alert" className="text-xs text-red-600 dark:text-red-400">
                                                End time must be after start time.
                                            </p>
                                        )}
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <DefenseDateControl
                                            value={schedule.scheduled_date}
                                            onChange={(value) => setSchedule((state) => ({ ...state, scheduled_date: value }))}
                                            disabled={!canEdit || awaitingCorrections}
                                        />
                                        <DefenseTimeControl
                                            label="Start time"
                                            value={schedule.scheduled_time}
                                            onChange={(value) => setSchedule((state) => ({ ...state, scheduled_time: value }))}
                                            disabled={!canEdit || awaitingCorrections}
                                        />
                                        <DefenseTimeControl
                                            label="End time"
                                            value={schedule.scheduled_end_time}
                                            onChange={(value) => setSchedule((state) => ({ ...state, scheduled_end_time: value }))}
                                            disabled={!canEdit || awaitingCorrections}
                                        />
                                        <div>
                                            <div className="text-muted-foreground mb-1 text-xs">Mode</div>
                                            <Select
                                                value={schedule.defense_mode}
                                                onValueChange={(v) => setSchedule((s) => ({ ...s, defense_mode: v }))}
                                                disabled={!canEdit || awaitingCorrections}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select mode" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="face-to-face">Face-to-Face</SelectItem>
                                                    <SelectItem value="online">Online</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="md:col-span-2">
                                            <div className="text-muted-foreground mb-1 text-xs">Venue</div>
                                            <Input
                                                value={schedule.defense_venue}
                                                onChange={(e) => setSchedule((s) => ({ ...s, defense_venue: e.target.value }))}
                                                disabled={!canEdit || awaitingCorrections}
                                            />
                                        </div>
                                        <div className="md:col-span-2">
                                            <div className="text-muted-foreground mb-1 text-xs">Notes</div>
                                            <Input
                                                value={schedule.scheduling_notes}
                                                onChange={(e) => setSchedule((s) => ({ ...s, scheduling_notes: e.target.value }))}
                                                disabled={!canEdit || awaitingCorrections}
                                            />
                                        </div>
                                    </div>
                                </div>
                                {/* Panel Assignment */}
                                <div className={sectionClass}>
                                    <h2 className="flex items-center gap-2 text-sm font-semibold">
                                        <Users className="h-4 w-4" /> Panel Assignment
                                    </h2>
                                    <Separator />
                                    <p className="text-muted-foreground text-xs">
                                        Enter the date, start time, and end time above to check panel availability. Counts show recorded defenses on
                                        that date, including this request once if already scheduled. There is no daily limit.
                                    </p>
                                    {validSlot && !availabilityReady && !availabilityError && (
                                        <p role="status" className="text-muted-foreground text-xs">
                                            Checking availability…
                                        </p>
                                    )}
                                    {availabilityError && (
                                        <div role="alert" className="text-sm text-red-600 dark:text-red-400">
                                            {availabilityError}{' '}
                                            <button
                                                type="button"
                                                className="underline"
                                                onClick={() => setAvailabilityRevision((revision) => revision + 1)}
                                            >
                                                Retry
                                            </button>
                                        </div>
                                    )}
                                    {adviserConflict && (
                                        <p role="alert" className="text-sm text-red-600 dark:text-red-400">
                                            The assigned adviser has an overlapping defense:{' '}
                                            {availability?.adviser?.bookings
                                                .filter((booking) => booking.overlaps)
                                                .map(slotLabel)
                                                .join(', ')}
                                            . Choose another time.
                                        </p>
                                    )}
                                    {changingScheduledSlot && (
                                        <p className="text-muted-foreground text-xs">
                                            Use Save Schedule below to save the new time and panel choices together.
                                        </p>
                                    )}

                                    <div className="grid gap-4 md:grid-cols-2">
                                        {[
                                            { label: 'Chairperson', key: 'defense_chairperson' },
                                            { label: 'Panelist 1', key: 'defense_panelist1' },
                                            { label: 'Panelist 2', key: 'defense_panelist2' },
                                            { label: 'Panelist 3', key: 'defense_panelist3' },
                                            { label: 'Panelist 4', key: 'defense_panelist4' },
                                        ].map(({ label, key }) => (
                                            <PanelMemberCombobox
                                                key={key}
                                                label={label}
                                                value={panels[key as keyof typeof panels]}
                                                onChange={(v) => setPanels((p) => ({ ...p, [key]: v }))}
                                                options={availableOptions}
                                                disabled={!canEdit || awaitingCorrections || loadingMembers || !availabilityReady}
                                                taken={taken}
                                            />
                                        ))}
                                    </div>
                                    <div className="mt-4 flex justify-end">
                                        <Button
                                            onClick={savePanels}
                                            disabled={
                                                !canEdit ||
                                                awaitingCorrections ||
                                                savingPanels ||
                                                savingSchedule ||
                                                !availabilityReady ||
                                                selectedConflicts ||
                                                adviserConflict ||
                                                changingScheduledSlot
                                            }
                                            className="gap-2 text-white hover:cursor-pointer dark:bg-rose-500"
                                        >
                                            {savingPanels && <Loader2 className="h-4 w-4 animate-spin" />}
                                            <Save className="h-4 w-4" />
                                            Save Panel Assignment
                                        </Button>
                                    </div>
                                    {panelLoadError && <div className="mt-2 text-xs text-red-500">{panelLoadError}</div>}
                                </div>
                                <div className="mt-4 flex justify-end">
                                    <Button
                                        onClick={saveSchedule}
                                        disabled={
                                            !canEdit ||
                                            awaitingCorrections ||
                                            savingSchedule ||
                                            savingPanels ||
                                            !availabilityReady ||
                                            selectedConflicts ||
                                            adviserConflict ||
                                            !panels.defense_chairperson ||
                                            !panels.defense_panelist1
                                        }
                                        className="gap-2 hover:cursor-pointer dark:bg-rose-500 dark:text-white"
                                    >
                                        {savingSchedule && <Loader2 className="h-4 w-4 animate-spin" />}
                                        <Save className="h-4 w-4" />
                                        Save Schedule
                                    </Button>
                                </div>
                            </TabsContent>
                        </Tabs>
                    </div>

                    {/* Workflow Progress Stepper sidebar */}
                    <div className="w-full flex-shrink-0 md:w-[340px]">
                        <div className="sticky top-24 h-fit rounded-xl border bg-white p-5 dark:bg-zinc-900">
                            <h2 className="mb-8 flex items-center gap-2 text-xs font-semibold">
                                <Clock className="h-4 w-4" /> Workflow Progress
                            </h2>
                            {/* Stepper */}
                            <div className="relative flex flex-col gap-0">
                                {Array.isArray(request.workflow_history) && request.workflow_history.length > 0 ? (
                                    request.workflow_history.map((item, idx: number) => {
                                        const { event, desc, created, userName } = resolveHistoryFields(item);
                                        const stepKey = getStepForEvent(event);
                                        const step = workflowSteps.find((s) => s.key === stepKey) || {
                                            label:
                                                (
                                                    {
                                                        'corrections-requested': 'Returned for corrections',
                                                        resubmitted: 'Resubmitted by student',
                                                    } as Record<string, string>
                                                )[event] || event.replaceAll('-', ' ').replace(/^./, (letter: string) => letter.toUpperCase()),
                                            icon: <Clock className="h-5 w-5 text-gray-500" />,
                                        };
                                        const isLast = Array.isArray(request.workflow_history) && idx === request.workflow_history.length - 1;
                                        const iconBoxColor = 'bg-gray-100 text-gray-500';
                                        return (
                                            <div key={idx} className="relative flex items-start gap-3">
                                                <div className="flex flex-col items-center">
                                                    <div className={`flex h-9 w-9 items-center justify-center rounded-md ${iconBoxColor}`}>
                                                        {step.icon}
                                                    </div>
                                                    {!isLast && (
                                                        <div className="mx-auto h-8 border-l-2 border-dotted border-gray-300 dark:border-zinc-700"></div>
                                                    )}
                                                </div>
                                                <div className="pb-4">
                                                    <div className="text-xs font-semibold">{step.label}</div>
                                                    <div className="text-muted-foreground text-[11px]">
                                                        {userName && <span>{userName}</span>}
                                                        {created && <span> &middot; {formatReadableDate(created)}</span>}
                                                    </div>
                                                    {(item.comment || desc) && (
                                                        <p className="text-muted-foreground mt-1 text-xs whitespace-pre-wrap">
                                                            {item.comment || desc}
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        );
                                    })
                                ) : (
                                    <div className="text-muted-foreground text-xs">No workflow history yet.</div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                <div className="text-muted-foreground text-[11px]">
                    Last updated by: {request.last_status_updated_by_name || request.last_status_updated_by || '—'}{' '}
                    {request.last_status_updated_at ? `(${request.last_status_updated_at})` : ''}
                </div>
            </div>

            {/* Confirmation Dialog for Approve/Reject/Retrieve */}
            <Dialog
                open={confirm.open}
                onOpenChange={(o) => {
                    if (!o) setConfirm({ open: false, action: null });
                }}
            >
                <DialogContent>
                    <DialogTitle>Confirm Action</DialogTitle>
                    <DialogDescription>
                        {confirm.action === 'approve' ? 'Please review before approving.' : 'Apply this status change?'}
                    </DialogDescription>
                    <div className="mt-3 space-y-3 text-sm">
                        <p>
                            Set request to{' '}
                            <span className="font-semibold">
                                {confirm.action === 'approve' ? 'Approved' : confirm.action === 'reject' ? 'Rejected' : 'Pending'}
                            </span>
                            ?
                        </p>
                        {confirm.action === 'approve' && (
                            <div className="bg-muted/40 flex flex-col items-center gap-3 rounded-md border p-5">
                                <div className="bg-primary/10 rounded-full p-4">
                                    <Signature className="text-primary h-14 w-14" />
                                </div>
                                <p className="text-center text-sm leading-relaxed">
                                    Approving this defense request authorizes the use of your signature on the official defense documents.
                                </p>
                            </div>
                        )}
                    </div>
                    <div className="mt-4 flex justify-end gap-2">
                        <Button variant="ghost" onClick={() => setConfirm({ open: false, action: null })}>
                            Cancel
                        </Button>
                        <Button onClick={() => confirm.action && handleStatusChange(confirm.action)} disabled={isLoading || awaitingCorrections}>
                            Confirm
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
