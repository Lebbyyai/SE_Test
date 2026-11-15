import { Link, usePage } from '@inertiajs/react';

type AssignedSchedule = {
    id: number;
    program: string;
    exam_date: string;
    exam_time: string;
    venue: string;
    teacher_assigned: string | null;
    other_proctors: string[];
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PageProps = {
    auth: {
        user: {
            name: string;
        };
    };
    assignedSchedules: {
        data: AssignedSchedule[];
        links: PaginationLink[];
        total: number;
    };
};

export default function ProctorDashboard() {
    const {
        auth: { user },
        assignedSchedules,
    } = usePage<PageProps>().props;

    return (
        <div className="flex min-h-[70vh] flex-col gap-4 px-4 py-6 md:px-7">
            <div>
                <h1 className="text-2xl font-bold text-gray-900 dark:text-white">Proctor Dashboard</h1>
                <p className="text-muted-foreground mt-1 text-sm">Welcome, {user.name}.</p>
            </div>
            <section className="bg-card text-card-foreground border-border w-full overflow-hidden rounded-lg border">
                <div className="border-border flex items-center justify-between border-b p-5">
                    <div>
                        <h2 className="text-base font-semibold">My Assigned Exam Schedules</h2>
                        <p className="text-muted-foreground mt-1 text-xs">Schedules assigned to your Proctor account.</p>
                    </div>
                    <span className="text-muted-foreground text-sm">{assignedSchedules.total} total</span>
                </div>
                {assignedSchedules.data.length === 0 ? (
                    <p className="text-muted-foreground p-6 text-center text-sm">No exam schedules are assigned to you yet.</p>
                ) : (
                    <>
                        <div className="w-full overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-muted/50 text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3 font-medium">Program</th>
                                        <th className="px-4 py-3 font-medium">Date</th>
                                        <th className="px-4 py-3 font-medium">Time</th>
                                        <th className="px-4 py-3 font-medium">Room</th>
                                        <th className="px-4 py-3 font-medium">Teacher assigned</th>
                                        <th className="px-4 py-3 font-medium">Other proctors</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {assignedSchedules.data.map((schedule) => (
                                        <tr key={schedule.id} className="border-border border-t">
                                            <td className="px-4 py-3">{schedule.program}</td>
                                            <td className="px-4 py-3 whitespace-nowrap">{schedule.exam_date}</td>
                                            <td className="px-4 py-3 whitespace-nowrap">{schedule.exam_time}</td>
                                            <td className="px-4 py-3">{schedule.venue}</td>
                                            <td className="px-4 py-3">{schedule.teacher_assigned || 'Not assigned'}</td>
                                            <td className="px-4 py-3">{schedule.other_proctors.join(', ') || 'None'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <nav aria-label="Assigned schedules pagination" className="flex flex-wrap justify-end gap-1 border-t p-4">
                            {assignedSchedules.links.map((link, index) => (
                                <Link
                                    key={`${link.label}-${index}`}
                                    href={link.url ?? '#'}
                                    preserveScroll
                                    aria-current={link.active ? 'page' : undefined}
                                    aria-disabled={!link.url}
                                    className={`rounded border px-3 py-1 text-sm ${link.active ? 'border-rose-500 bg-rose-500 text-white' : 'border-border'} ${!link.url ? 'pointer-events-none opacity-50' : ''}`}
                                >
                                    <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                </Link>
                            ))}
                        </nav>
                    </>
                )}
            </section>
        </div>
    );
}
