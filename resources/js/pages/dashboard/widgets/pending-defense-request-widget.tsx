import { Skeleton } from '@/components/ui/skeleton';
import type { DefenseRequest } from '@/types';
import { formatDistanceToNowStrict, isValid, parseISO } from 'date-fns';
import { ChevronRight, GraduationCap } from 'lucide-react';

function getPriorityBadge(priority?: string) {
    const value = (priority || '').trim();
    const className =
        'cursor-pointer rounded-full px-2 py-1 border font-bold text-[10px] ' +
        (value === 'High'
            ? 'bg-rose-100 text-rose-700 border-rose-200 dark:bg-rose-950 dark:text-rose-300 dark:border-rose-900'
            : value === 'Low'
              ? 'bg-sky-100 text-sky-700 border-sky-200 dark:bg-sky-950 dark:text-sky-300 dark:border-sky-900'
              : 'bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-950 dark:text-amber-300 dark:border-amber-900');

    return <span className={className}>{value || 'Medium'}</span>;
}

type Props = {
    pendingRequests: DefenseRequest[];
    loading?: boolean;
};

const PendingDefenseRequestsWidget: React.FC<Props> = ({ pendingRequests, loading = false }) => {
    const showSkeleton = loading;
    const filteredPendingRequests = pendingRequests;

    return (
        <div className="border-sidebar-border/70 dark:border-sidebar-border flex min-h-[220px] flex-1 flex-col rounded-xl border bg-white p-5 dark:bg-zinc-900">
            {showSkeleton ? (
                <div className="flex h-full flex-col gap-3">
                    <div className="mb-2 flex items-center justify-between">
                        <div>
                            <Skeleton className="mb-2 h-8 w-16 bg-gray-100" />
                            <Skeleton className="h-4 w-32 bg-gray-100" />
                        </div>
                        <div className="flex items-center gap-2">
                            <Skeleton className="h-10 w-10 rounded-full bg-gray-100" />
                            <Skeleton className="h-6 w-6 rounded-full bg-gray-100" />
                        </div>
                    </div>
                    <Skeleton className="mb-2 h-4 w-full bg-gray-100" />
                    <div className="mt-2 flex flex-col gap-2">
                        {[...Array(3)].map((_, i) => (
                            <div key={i} className="flex items-center gap-2">
                                <Skeleton className="h-5 w-3/4 bg-gray-100" />
                                <Skeleton className="h-5 w-16 bg-gray-100" />
                            </div>
                        ))}
                    </div>
                </div>
            ) : (
                <>
                    <a
                        href="/defense-requests"
                        className="group mb-2 flex cursor-pointer items-center justify-between select-none"
                        style={{ textDecoration: 'none' }}
                    >
                        <div>
                            <div className="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">Pending Defense Requests</div>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="inline-flex items-center justify-center rounded-full bg-rose-500 p-2">
                                <GraduationCap className="size-6 text-white" />
                            </span>
                            <ChevronRight className="size-6 text-gray-400 transition-colors transition-transform duration-200 group-hover:translate-x-1 group-hover:text-rose-500" />
                        </div>
                    </a>
                    <hr className="my-2 border-gray-200 dark:border-gray-700" />
                    <div className="overflow-x-auto">
                        {filteredPendingRequests.length === 0 ? (
                            <div className="text-muted-foreground py-10 text-center text-xs">No pending defense requests.</div>
                        ) : (
                            <table className="w-full table-fixed text-xs">
                                <thead>
                                    <tr className="text-gray-500 dark:text-gray-400">
                                        <th className="w-2/5 py-1 pr-2 text-left">Thesis Title</th>
                                        <th className="w-1/5 px-2 py-1 text-left">Adviser</th>
                                        <th className="w-1/5 px-2 py-1 text-left">Presenter</th>
                                        <th className="w-1/6 px-2 py-1 text-center">Submitted</th>
                                        <th className="w-1/6 py-1 pl-2 text-center">Priority</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filteredPendingRequests.slice(0, 3).map((req, idx) => (
                                        <tr
                                            key={req.id}
                                            className={` ${
                                                idx % 2 === 0 ? 'bg-gray-50 dark:bg-zinc-800' : 'bg-white dark:bg-zinc-900'
                                            } hover:bg-rose-50 dark:hover:bg-rose-950`}
                                        >
                                            <td className="w-2/5 truncate py-2 pr-2 font-medium">
                                                {req.thesis_title || <span className="text-gray-400">N/A</span>}
                                            </td>
                                            <td className="w-1/5 px-2 py-2">
                                                {req.adviser || req.defense_adviser || <span className="text-muted-foreground">Not assigned</span>}
                                            </td>
                                            <td className="w-1/5 px-2 py-2">
                                                {/* Presenter: first_name + last_name */}
                                                {`${req.first_name ?? ''} ${req.last_name ?? ''}`.trim() || (
                                                    <span className="text-gray-400">N/A</span>
                                                )}
                                            </td>
                                            <td className="w-1/6 px-2 py-2 text-center">
                                                {(req.submitted_at || req.created_at) &&
                                                isValid(parseISO(req.submitted_at || req.created_at || '')) ? (
                                                    formatDistanceToNowStrict(parseISO(req.submitted_at || req.created_at || ''), { addSuffix: true })
                                                ) : (
                                                    <span className="text-gray-400">N/A</span>
                                                )}
                                            </td>
                                            <td className="w-1/6 py-2 pl-2 text-center">{getPriorityBadge(req.priority)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                        {filteredPendingRequests.length > 3 && (
                            <span className="mt-2 inline-block text-xs text-rose-500">+{filteredPendingRequests.length - 3} more</span>
                        )}
                    </div>
                </>
            )}
        </div>
    );
};

export default PendingDefenseRequestsWidget;
