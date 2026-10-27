import { Card } from '@/components/ui/card';
import type { DefenseRequest } from '@/types';
import type { DefenseOverview } from '@/types/workflows';
import { Link, usePage } from '@inertiajs/react';
import { AlertCircle, BadgeDollarSign, CalendarDays, ClipboardList, Moon, Sun, Users } from 'lucide-react';
import React, { useEffect, useState } from 'react';
import DefenseStatusWidget from '../widgets/defense-status-widget';
import ExamEligibilityWidget from '../widgets/exam-eligibility-widget';
import QuickActionsWidget from '../widgets/quick-actions-widget';
import StudentSchedulesWidget from '../widgets/student-schedules-widget';

type DefenseRequirement = {
    id: number;
    thesis_title: string;
    status: string;
    created_at?: string;
};

type RecentPayment = {
    amount: number;
    // add other fields if needed
};

type PageProps = {
    auth: {
        user: {
            id: number;
            name: string;
            role: string;
            school_id?: string;
            advisers?: unknown[]; // Add advisers property, adjust type if needed
        } | null;
    };
    defenseRequirement?: DefenseRequirement;
    defenseRequest?: DefenseRequest;
    defenseRequests?: DefenseRequest[];
    recentPayment?: RecentPayment;
};

function getFormattedDate() {
    const now = new Date();
    return now.toLocaleDateString('en-US', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
    });
}

function isDaytime() {
    const hour = new Date().getHours();
    return hour >= 6 && hour < 18;
}

export default function StudentDashboard() {
    const page = usePage<PageProps>().props;
    const {
        auth: { user },
    } = page;
    const serverDefenseRequests = page.defenseRequests ?? [];

    const [allRequests, setAllRequests] = useState<DefenseOverview[]>([]);
    const [,] = useState<DefenseOverview[]>([]);
    const [todayEventCount, setTodayEventCount] = useState<number | null>(null);
    const [loading, setLoading] = useState(true);
    const [,] = useState(true);
    const [showAdviserAlert, setShowAdviserAlert] = useState(true);

    useEffect(() => {
        const hydrate = (requests: DefenseOverview[]) => {
            let mine = requests.filter((r: DefenseOverview) => {
                const sb = r.submitted_by;
                return sb === user?.id || sb === String(user?.id);
            });
            if (mine.length === 0) mine = requests;
            const sorted = [...mine].sort((a, b) => {
                const at = a.created_at ? new Date(a.created_at).getTime() : 0;
                const bt = b.created_at ? new Date(b.created_at).getTime() : 0;
                return bt - at;
            });
            setAllRequests(sorted);
        };

        const load = async () => {
            setLoading(true);
            try {
                const res = await fetch('/defense-request', { headers: { Accept: 'application/json' } });
                if (!res.ok) throw new Error('bad');
                const data = await res.json();
                const list = Array.isArray(data) ? data : (data.defenseRequests ?? []);
                hydrate(list);
            } catch {
                setAllRequests([]);
            } finally {
                setLoading(false);
            }
        };
        load();
    }, [user?.id]);

    const approvedDefenses = serverDefenseRequests && serverDefenseRequests.length > 0 ? serverDefenseRequests : allRequests;

    // Example: Get most recent payment amount (replace with real data source)
    const recentPaymentAmount = page.recentPayment?.amount ?? 0; // If you have recentPayment in props

    // --- Student Metrics Cards ---
    const metrics = [
        {
            title: 'Defense Submissions',
            value: allRequests.length,
            description: 'Total defense requests submitted',
            icon: <ClipboardList />, // icon only, styling below
            iconTheme: 'bg-rose-100 text-rose-600 dark:bg-rose-900 dark:text-rose-300',
        },
        {
            title: "Today's Schedules",
            value: todayEventCount === null ? 'Checking...' : todayEventCount,
            description: 'Events scheduled for today',
            icon: <CalendarDays />,
            iconTheme: 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-300',
        },
        {
            title: 'Recent Payment',
            value: `₱${recentPaymentAmount.toLocaleString()}`,
            description: 'Most recent payment made',
            icon: <BadgeDollarSign />,
            iconTheme: 'bg-amber-100 text-amber-600 dark:bg-amber-900 dark:text-amber-300',
        },
        {
            title: 'Exam Eligibility',
            value: <ExamEligibilityWidget simple />,
            description: 'Check if you are eligible for exams',
            icon: <Users />,
            iconTheme: 'bg-green-100 text-green-600 dark:bg-green-900 dark:text-green-300',
        },
    ];

    return (
        <div className="dark:bg-background flex h-full flex-1 flex-col gap-4 overflow-auto bg-white">
            {/* Skeleton Loader */}
            {loading ? (
                <div className="m-0 flex min-h-[70vh] w-full flex-col gap-4 bg-zinc-100 p-0 dark:bg-zinc-900">
                    {/* Top short row */}
                    <div className="mx-8 mt-8 h-6 w-1/6 rounded bg-zinc-300 dark:bg-zinc-800" />
                    {/* Main rows */}
                    <div className="mx-8 h-12 w-3/4 rounded bg-zinc-300 dark:bg-zinc-800" />
                    <div className="mx-8 h-12 w-2/3 rounded bg-zinc-300 dark:bg-zinc-800" />
                    {/* Big rectangle for dashboard body */}
                    <div className="mt-4 h-[500px] w-full rounded bg-zinc-300 dark:bg-zinc-800" />
                </div>
            ) : (
                <>
                    {/* Header - Mobile Responsive */}
                    <div className="mt-4 mb-4 px-4 md:mt-5 md:mb-6 md:px-7">
                        <div className="relative flex flex-col gap-4 overflow-hidden md:flex-row md:items-center md:justify-between md:gap-0">
                            <div className="flex flex-col">
                                <span className="relative z-10 mb-1 flex items-center text-xs font-semibold text-gray-700 md:mb-2 dark:text-gray-200">
                                    {isDaytime() ? (
                                        <Sun className="mr-1 size-3 text-rose-500 md:size-4" />
                                    ) : (
                                        <Moon className="mr-1 size-3 text-rose-500 md:size-4" />
                                    )}
                                    {getFormattedDate()}
                                </span>
                                <h1 className="relative z-10 text-xl font-extrabold text-gray-900 md:text-2xl lg:text-3xl dark:text-white">
                                    Hi, {user?.name}!
                                </h1>
                                <span className="relative z-10 mt-2 text-xs font-extrabold text-zinc-500 md:mt-3">
                                    {user?.role ?? 'Student'}
                                    {user?.school_id && (
                                        <>
                                            <span className="mx-1">/</span>
                                            <span className="font-bold">{user.school_id}</span>
                                        </>
                                    )}
                                </span>
                            </div>
                            {/* Quick Actions - Hidden on mobile, shown on md+ */}
                            <div className="hidden items-center md:flex">
                                <div className="mx-4 h-12 w-px bg-gray-300 opacity-60 dark:bg-gray-700" />
                                <div className="mr-8">
                                    <QuickActionsWidget userRole={user?.role} />
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* ALERT: Student has not registered an adviser - Mobile Responsive */}
                    {user?.advisers?.length === 0 && showAdviserAlert && (
                        <div className="mx-4 my-3 md:mx-7">
                            <div className="relative flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50 px-4 py-4 text-rose-900 md:gap-3 md:px-6 md:py-5 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-100">
                                <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0 text-rose-500 md:mt-1 md:h-5 md:w-5 dark:text-rose-400" />
                                <div className="flex-1 pr-6">
                                    <span className="mb-1 block text-sm font-semibold md:text-base">You are not registered to an adviser yet.</span>
                                    <span className="text-xs md:text-sm">
                                        Contact your coordinator for assistance.
                                        <Link
                                            href="/settings/profile"
                                            className="ml-2 font-medium text-rose-600 underline hover:text-rose-700 dark:text-rose-300 dark:hover:text-rose-200"
                                        >
                                            Go to Settings
                                        </Link>
                                    </span>
                                </div>
                                <button
                                    onClick={() => setShowAdviserAlert(false)}
                                    className="absolute top-2 right-2 rounded px-1.5 py-0.5 text-sm text-rose-600 hover:bg-rose-100 md:top-3 md:right-4 md:px-2 md:py-1 md:text-base dark:text-rose-200 dark:hover:bg-rose-900"
                                    aria-label="Close"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>
                    )}

                    {/* Metrics Cards - Optimized for Mobile */}
                    <div className="mx-auto mb-4 grid w-full max-w-screen-xl grid-cols-1 gap-3 px-4 sm:grid-cols-2 md:mb-6 md:gap-4 md:px-7 lg:grid-cols-4">
                        {metrics.map((metric, idx) => (
                            <Card
                                key={idx}
                                className="col-span-1 flex h-auto min-h-0 flex-col justify-between rounded-lg border border-gray-200 bg-white p-0 shadow-sm transition hover:shadow-md md:rounded-xl dark:border-zinc-800 dark:bg-zinc-900"
                                style={{ minWidth: 0 }}
                            >
                                <div className="flex items-center justify-between px-4 pt-3 pb-0 md:px-5 md:pt-4">
                                    <div className="text-xs leading-tight font-extrabold text-gray-800 md:text-sm dark:text-zinc-100">
                                        {metric.title}
                                    </div>
                                    <div className={`flex flex-shrink-0 items-center justify-center rounded-full p-1 md:p-1.5 ${metric.iconTheme}`}>
                                        {React.cloneElement(metric.icon, {
                                            className:
                                                'size-3 md:size-4 font-extrabold ' +
                                                (metric.iconTheme?.split(' ').find((c) => c.startsWith('text-')) ?? ''),
                                        })}
                                    </div>
                                </div>
                                <div className="flex flex-col px-4 pt-1 pb-3 md:px-5 md:pt-2 md:pb-4">
                                    <span className="text-xl leading-none font-bold text-gray-900 md:text-2xl dark:text-white">{metric.value}</span>
                                    <span className="mt-1 truncate text-xs text-gray-400 dark:text-gray-500">{metric.description}</span>
                                </div>
                            </Card>
                        ))}
                    </div>

                    {/* Widgets Body - Mobile Responsive */}
                    <div className="dark:bg-muted mx-2 mt-2 mb-2 flex flex-col gap-4 rounded-lg bg-gray-100 px-3 py-4 md:mx-4 md:gap-6 md:rounded-xl md:px-5 md:py-8">
                        <div className="mb-2 flex w-full flex-col gap-4">
                            {/* Schedules first */}
                            <StudentSchedulesWidget onTodayCount={setTodayEventCount} />
                            {/* Defense Status Widget beside the schedule widget on desktop, below on mobile */}
                            <DefenseStatusWidget recentRequests={approvedDefenses} loading={loading} />
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
