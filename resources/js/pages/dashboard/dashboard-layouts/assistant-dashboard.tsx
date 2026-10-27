import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { DefenseOverview } from '@/types/workflows';
import { usePage } from '@inertiajs/react';
import { BarChart3, CalendarDays, CircleEllipsis, ClipboardList, DollarSign, Moon, Sun } from 'lucide-react';
import React, { useEffect, useState } from 'react';
import PendingDefenseRequestsWidget from '../widgets/pending-defense-request-widget';
import QuickActionsWidget from '../widgets/quick-actions-widget';
import SchedulesWidget from '../widgets/student-schedules-widget';

type PageProps = {
    auth: {
        user: {
            id: number;
            name: string;
            role: string;
        } | null;
    };
    stats?: {
        pending_applications: number;
        pending_defense_requests: number;
        pending_payments: number;
        upcoming_deadlines: number;
        pending_honorariums?: number;
        todays_defenses?: number;
        payment_confirmations?: number;
    };
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

export default function AssistantDashboard() {
    const {
        auth: { user },
        stats = {
            pending_applications: 0,
            pending_defense_requests: 0,
            pending_payments: 0,
            upcoming_deadlines: 0,
        },
    } = usePage<PageProps>().props;

    const [loading, setLoading] = useState(false);
    const pendingHonorariums = stats.pending_honorariums;
    const todaysDefenses = stats.todays_defenses;
    const pendingApplications = stats.pending_applications;
    const pendingPayments = stats.pending_payments;
    const pendingDefenseRequests = stats.pending_defense_requests;
    const [pendingRequests, setPendingRequests] = useState<DefenseOverview[]>([]);
    useEffect(() => {
        setLoading(true);
        fetch('/defense-requests', { headers: { Accept: 'application/json' } })
            .then((res) => {
                if (!res.ok) throw new Error('Unable to load defense requests');
                return res.json();
            })
            .then((data) => {
                const requests = Array.isArray(data) ? data : (data.defenseRequests ?? []);
                setPendingRequests(requests.filter((r: DefenseOverview) => (r.normalized_status || r.status) === 'Pending'));
            })
            .catch(() => setPendingRequests([]))
            .finally(() => setLoading(false));
    }, []);

    const [tab, setTab] = useState('overview');

    const metrics = [
        {
            title: 'Pending Honorariums',
            value: pendingHonorariums ?? 'Unavailable',
            description: 'Honorariums not yet processed',
            icon: <DollarSign />,
            iconTheme: 'bg-rose-100 text-rose-600 dark:bg-rose-900 dark:text-rose-300',
            iconColorClass: 'text-rose-500',
        },
        {
            title: "Today's Defense Schedules",
            value: todaysDefenses ?? 'Unavailable',
            description: 'Defenses scheduled for today',
            icon: <CalendarDays />,
            iconTheme: 'bg-violet-100 text-violet-600 dark:bg-violet-900 dark:text-violet-300',
            iconColorClass: 'text-violet-500',
        },
        {
            title: 'Pending Applications',
            value: pendingApplications,
            description: 'Applications awaiting review',
            icon: <ClipboardList />,
            iconTheme: 'bg-rose-100 text-rose-600 dark:bg-rose-900 dark:text-rose-300',
            iconColorClass: 'text-rose-500',
        },
        {
            title: 'Payment Confirmations',
            value: pendingPayments,
            description: 'Payments require verification',
            icon: <CircleEllipsis />,
            iconTheme: 'bg-violet-100 text-violet-600 dark:bg-violet-900 dark:text-violet-300',
            iconColorClass: 'text-violet-500',
        },
        {
            title: 'Defense Requests',
            value: pendingDefenseRequests,
            description: 'Defense requests to process',
            icon: <ClipboardList />,
            iconTheme: 'bg-rose-100 text-rose-600 dark:bg-rose-900 dark:text-rose-300',
            iconColorClass: 'text-rose-500',
        },
    ];

    return (
        <div className="dark:bg-background flex h-full flex-1 flex-col gap-4 overflow-auto bg-white">
            {loading ? (
                <div className="m-0 flex min-h-[70vh] w-full flex-col gap-4 bg-zinc-100 p-0 dark:bg-zinc-900">
                    <Skeleton className="mx-8 mt-8 h-6 w-1/6 rounded bg-zinc-300 dark:bg-zinc-800" />
                    <Skeleton className="mx-8 h-12 w-3/4 rounded bg-zinc-300 dark:bg-zinc-800" />
                    <Skeleton className="mx-8 h-12 w-2/3 rounded bg-zinc-300 dark:bg-zinc-800" />
                    <Skeleton className="mt-4 h-[500px] w-full rounded bg-zinc-300 dark:bg-zinc-800" />
                </div>
            ) : (
                <>
                    {/* Header - Mobile Responsive */}
                    <div className="mt-2 mb-4 px-4 pt-2 md:mt-3 md:mb-7 md:px-7 md:pt-3">
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
                                <h1 className="relative z-10 text-xl font-bold text-gray-900 md:text-2xl lg:text-3xl dark:text-white">
                                    Hi, {user?.name}!
                                </h1>
                                <span className="relative z-10 mt-1 text-xs text-gray-400 dark:text-gray-500">{user?.role ?? 'Assistant'}</span>
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

                    {/* Tabs - Mobile Responsive */}
                    <div className="mx-auto w-full max-w-screen-xl px-4 md:px-7">
                        <Tabs value={tab} onValueChange={setTab} className="w-full">
                            <TabsList className="mb-2">
                                <TabsTrigger value="overview" className="flex-1 sm:flex-none">
                                    Overview
                                </TabsTrigger>
                                <TabsTrigger value="analytics" className="flex-1 sm:flex-none">
                                    Analytics
                                </TabsTrigger>
                            </TabsList>

                            {/* Overview Tab */}
                            <TabsContent value="overview" className="w-full">
                                {/* Metric Cards - Mobile Optimized */}
                                <div className="mx-auto mb-4 grid w-full max-w-screen-xl grid-cols-1 gap-3 px-0 sm:grid-cols-2 md:mb-6 md:gap-4 lg:grid-cols-4">
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
                                                <div
                                                    className={`flex flex-shrink-0 items-center justify-center rounded-full p-1 md:p-1.5 ${metric.iconTheme}`}
                                                >
                                                    {React.cloneElement(metric.icon, {
                                                        className: 'size-3 md:size-4 font-extrabold ' + metric.iconColorClass,
                                                    })}
                                                </div>
                                            </div>
                                            <div className="flex flex-col px-4 pt-1 pb-3 md:px-5 md:pt-2 md:pb-4">
                                                <span className="text-xl leading-none font-bold text-gray-900 md:text-2xl dark:text-white">
                                                    {metric.value}
                                                </span>
                                                <span className="mt-1 truncate text-xs text-gray-400 dark:text-gray-500">{metric.description}</span>
                                            </div>
                                        </Card>
                                    ))}
                                </div>

                                {/* Widgets Body - Mobile Responsive */}
                                <div className="dark:bg-muted mt-2 mb-2 flex w-full flex-col gap-4 rounded-lg bg-gray-100 px-3 py-4 md:gap-6 md:rounded-xl md:px-4 md:py-8">
                                    <div className="mb-2 flex w-full flex-col gap-4">
                                        <SchedulesWidget />
                                        <PendingDefenseRequestsWidget pendingRequests={pendingRequests} loading={loading} />
                                    </div>
                                </div>
                            </TabsContent>

                            {/* Analytics Tab - Mobile Responsive */}
                            <TabsContent value="analytics" className="w-full">
                                <div className="mx-auto mb-4 grid w-full max-w-screen-xl grid-cols-1 gap-4 md:mb-6">
                                    {/* Icon analytic card */}
                                    <Card className="flex flex-row items-center gap-3 p-4 md:gap-4 md:p-6">
                                        <div className="flex flex-shrink-0 items-center justify-center rounded-full bg-violet-100 p-2 md:p-3 dark:bg-violet-900">
                                            <BarChart3 className="size-6 text-violet-600 md:size-8 dark:text-violet-300" />
                                        </div>
                                        <div>
                                            <div className="text-base font-bold text-gray-900 md:text-lg dark:text-white">Analytics</div>
                                            <div className="text-xs text-gray-500 md:text-sm dark:text-gray-400">
                                                Visualize assistant workflow metrics and trends here.
                                            </div>
                                        </div>
                                    </Card>
                                    {/* Add more analytics widgets here later */}
                                </div>
                            </TabsContent>
                        </Tabs>
                    </div>
                </>
            )}
        </div>
    );
}
