import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { DefenseOverview } from '@/types/workflows';
import { usePage } from '@inertiajs/react';
import { BadgeDollarSign, CalendarDays, ClipboardList, Moon, Sun, Users } from 'lucide-react';
import React, { useEffect, useState } from 'react';
import GraduateStaffWidget, { type GraduateStaff } from '../widgets/graduate-staff-widget';
import PendingDefenseRequestsWidget from '../widgets/pending-defense-request-widget';
import QuickActionsWidget from '../widgets/quick-actions-widget';
import SchedulesWidget from '../widgets/student-schedules-widget';
import DefenseCountLineChart from '../widgets/visual-charts/defense-count';

type PageProps = {
    graduateStaff?: GraduateStaff[];
    auth: {
        user: {
            id: number;
            name: string;
            role: string;
        } | null;
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

export default function DeanDashboard() {
    const {
        auth: { user },
        graduateStaff = [],
    } = usePage<PageProps>().props;

    const [todayEventCount, setTodayEventCount] = useState<number | null>(null);
    const [pendingRequests, setPendingRequests] = useState<DefenseOverview[]>([]);
    const [loading, setLoading] = useState(true);
    const [panelistsCount, setPanelistsCount] = useState<number>(0);
    const [advisersCount, setAdvisersCount] = useState<number>(0);
    const [assignedProgramsCount, setAssignedProgramsCount] = useState<number>(0);

    useEffect(() => {
        // Fetch all defense requests (not just assigned to user)
        fetch('/defense-requests', {
            headers: { Accept: 'application/json' },
        })
            .then((res) => (res.ok ? res.json() : []))
            .then((data) => {
                const requests = Array.isArray(data) ? data : (data.defenseRequests ?? []);

                const pending = requests.filter((r: DefenseOverview) => (r.normalized_status || r.status) === 'Pending');
                setPendingRequests(pending);
            })
            .catch(() => {
                setPendingRequests([]);
            })
            .finally(() => setLoading(false));

        // Fetch all panelists in the database
        fetch('/api/panelists', {
            headers: { Accept: 'application/json' },
        })
            .then((res) => (res.ok ? res.json() : []))
            .then((data) => {
                setPanelistsCount(Array.isArray(data) ? data.length : 0);
            })
            .catch(() => setPanelistsCount(0));

        // Fetch all advisers in the database
        fetch('/api/advisers', {
            headers: { Accept: 'application/json' },
        })
            .then((res) => (res.ok ? res.json() : []))
            .then((data) => {
                setAdvisersCount(Array.isArray(data) ? data.length : 0);
            })
            .catch(() => setAdvisersCount(0));

        // Fetch all programs in the database
        fetch('/api/programs', {
            headers: { Accept: 'application/json' },
        })
            .then((res) => (res.ok ? res.json() : []))
            .then((data) => {
                setAssignedProgramsCount(Array.isArray(data) ? data.length : 0);
            })
            .catch(() => setAssignedProgramsCount(0));
    }, []);

    // Metric cards (overall, not just assigned to user)
    const metrics = [
        {
            title: 'Pending Defense Requests',
            value: pendingRequests.length,
            description: 'Awaiting coordinator action',
            icon: <ClipboardList className="size-5 text-rose-500" />,
            iconTheme: 'bg-rose-100 text-rose-600 dark:bg-rose-900 dark:text-rose-300',
        },
        {
            title: "Today's Schedules",
            value: todayEventCount ?? 'Checking...',
            description: 'Defenses and school events today',
            icon: <CalendarDays className="size-5 text-violet-500" />,
            iconTheme: 'bg-violet-100 text-violet-600 dark:bg-violet-900 dark:text-violet-300',
        },
        {
            title: 'Pending Honorariums',
            value: 7, // Replace with real value if available
            description: 'Honorariums not yet processed',
            icon: <BadgeDollarSign className="size-5 text-rose-400" />,
            iconTheme: 'bg-rose-100 text-rose-600 dark:bg-rose-900 dark:text-rose-300',
        },
        {
            title: 'Programs',
            value: assignedProgramsCount,
            description: 'Total programs',
            icon: <ClipboardList className="size-5 text-violet-500" />,
            iconTheme: 'bg-violet-100 text-violet-600 dark:bg-violet-900 dark:text-violet-300',
        },
        {
            title: 'Advisers',
            value: advisersCount,
            description: 'Total advisers',
            icon: <Users className="size-5 text-rose-400" />,
            iconTheme: 'bg-rose-100 text-rose-600 dark:bg-rose-900 dark:text-rose-300',
        },
        {
            title: 'Panelists',
            value: <span className="text-3xl font-bold text-gray-900 dark:text-white">{panelistsCount}</span>,
            description: 'Total panelists',
            icon: <Users className="size-5 text-violet-500" />,
            iconTheme: 'bg-violet-100 text-violet-600 dark:bg-violet-900 dark:text-violet-300',
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
                                <span className="relative z-10 mt-1 text-xs text-gray-400 dark:text-gray-500">{user?.role ?? 'Dean'}</span>
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
                        <Tabs defaultValue="overview" className="w-full">
                            <TabsList className="mb-2 w-full sm:w-auto">
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
                                <div className="mx-auto mb-4 grid w-full max-w-screen-xl grid-cols-1 gap-3 px-0 sm:grid-cols-2 md:mb-6 md:gap-4 lg:grid-cols-3 xl:grid-cols-4">
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
                                                        className:
                                                            'size-3 md:size-4 font-extrabold ' +
                                                            (metric.iconTheme?.split(' ').find((c) => c.startsWith('text-')) ?? ''),
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
                                        <SchedulesWidget onTodayCount={setTodayEventCount} />
                                        <GraduateStaffWidget staff={graduateStaff} />
                                        <PendingDefenseRequestsWidget pendingRequests={pendingRequests} loading={loading} />
                                    </div>
                                </div>
                            </TabsContent>

                            {/* Analytics Tab - Mobile Responsive */}
                            <TabsContent value="analytics" className="w-full">
                                <div className="mx-auto mb-4 grid w-full max-w-screen-xl grid-cols-1 gap-4 md:mb-6">
                                    <DefenseCountLineChart />
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
