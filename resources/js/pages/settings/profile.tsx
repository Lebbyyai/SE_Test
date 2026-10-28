import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { User } from '@/types';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { profileNameParts } from '@/utils/profile-name';
import { Head, usePage } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Profile settings',
        href: '/settings/profile',
    },
];

function getInitials(user: User) {
    const first = user.first_name?.trim()?.[0] ?? '';
    const last = user.last_name?.trim()?.[0] ?? '';
    return (first + last).toUpperCase() || 'U';
}

function getFullName(user: User) {
    const name = profileNameParts(user);
    return [name.firstName, name.middleInitial, name.lastName].filter(Boolean).join(' ') || user.display_name || user.name || 'User';
}

export default function Profile({ mustVerifyEmail, status }: { mustVerifyEmail: boolean; status?: string }) {
    const { auth } = usePage<SharedData>().props;
    const user = auth.user;
    const name = profileNameParts(user);

    type Adviser = { name: string; email: string };
    const adviser: Adviser | null = Array.isArray(user.advisers) ? (user.advisers[0] ?? null) : null;
    const [adviserCode] = useState(user.adviser_code ?? '');
    const [,] = useState(false);
    const [,] = useState('');

    // --- Coordinator registration for Adviser ---
    const [,] = useState('');
    const [,] = useState(false);
    const [,] = useState('');
    // Always use { name, email } for coordinator state
    const [coordinator] = useState(() => {
        const arr = Array.isArray(user.coordinators) ? user.coordinators : [];
        if (arr.length > 0) {
            const c = arr[0];
            return {
                name: c.name || [c.first_name, c.middle_name ? `${c.middle_name[0]}.` : '', c.last_name].filter(Boolean).join(' '),
                email: c.email,
            };
        }
        return null;
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Profile settings" />
            <SettingsLayout>
                <div className="space-y-6">
                    <Alert className="flex items-start gap-3 rounded-xl border-rose-200 bg-rose-50 px-6 py-5 text-rose-900 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-100">
                        <Info className="mt-1 h-5 w-5 flex-shrink-0 text-rose-500 dark:text-rose-400" />
                        <div>
                            <AlertTitle className="mb-1 font-semibold">Notice</AlertTitle>
                            <AlertDescription>
                                Profile details come from your linked school account or Google sign-in. Contact your coordinator or administrator if
                                your name or school information needs correction.
                            </AlertDescription>
                        </div>
                    </Alert>
                    <div className="mb-8 flex flex-row items-center gap-6">
                        <Avatar className="h-20 w-20">
                            <AvatarFallback className="bg-zinc-200 text-3xl font-bold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                {getInitials(user)}
                            </AvatarFallback>
                        </Avatar>
                        <div>
                            <div className="text-2xl font-semibold">{getFullName(user)}</div>
                            <div className="text-muted-foreground text-sm">{user.email}</div>
                        </div>
                    </div>
                    <div className="mt-4 grid grid-cols-1 gap-x-12 gap-y-6 md:grid-cols-2">
                        <div>
                            <div className="text-muted-foreground mb-1 text-xs">First Name</div>
                            <div className="text-base font-medium">{name.firstName || '�'}</div>
                        </div>
                        <div>
                            <div className="text-muted-foreground mb-1 text-xs">Middle Initial</div>
                            <div className="text-base font-medium">{name.middleInitial || '�'}</div>
                        </div>
                        <div>
                            <div className="text-muted-foreground mb-1 text-xs">Last Name</div>
                            <div className="text-base font-medium">{user.last_name}</div>
                        </div>
                        <div>
                            <div className="text-muted-foreground mb-1 text-xs">School ID</div>
                            <div className="text-base font-medium">{user.school_id ?? '—'}</div>
                        </div>
                        <div>
                            <div className="text-muted-foreground mb-1 text-xs">Role</div>
                            <div className="text-base font-medium">{user.role ?? '—'}</div>
                        </div>
                        <div>
                            <div className="text-muted-foreground mb-1 text-xs">Email</div>
                            <div className="text-base font-medium">{user.email ?? '—'}</div>
                        </div>
                        {user.program && (
                            <div>
                                <div className="text-muted-foreground mb-1 text-xs">Program</div>
                                <div className="text-base font-medium">{user.program}</div>
                            </div>
                        )}
                        {/* Show Adviser info for Students */}
                        {user.role === 'Student' && (
                            <div>
                                <div className="text-muted-foreground mb-1 text-xs">Adviser</div>
                                {adviser ? (
                                    <>
                                        <div className="text-base font-medium">{adviser.name}</div>
                                        <div className="text-muted-foreground mt-1 text-xs">{adviser.email}</div>
                                    </>
                                ) : (
                                    <div className="text-muted-foreground text-base">No adviser assigned.</div>
                                )}
                            </div>
                        )}
                        {user.role === 'Adviser' && (
                            <>
                                <div>
                                    <div className="text-muted-foreground mb-1 text-xs">Adviser Code</div>
                                    <div className="text-base font-medium">{adviserCode ? String(adviserCode) : '—'}</div>
                                    <div className="text-muted-foreground mt-1 text-xs">
                                        Share this code with your students so they can register you as their adviser.
                                    </div>
                                </div>
                                <div>
                                    <div className="text-muted-foreground mb-1 text-xs">Coordinator</div>
                                    {coordinator ? (
                                        <>
                                            <div className="text-base font-medium">{coordinator.name}</div>
                                            <div className="text-muted-foreground mt-1 text-xs">{coordinator.email}</div>
                                        </>
                                    ) : (
                                        <div className="text-muted-foreground text-base">No coordinator assigned.</div>
                                    )}
                                </div>
                            </>
                        )}
                    </div>
                    {mustVerifyEmail && user.email_verified_at === null && (
                        <div className="mt-6">
                            {status === 'verification-link-sent' && (
                                <div className="mt-2 text-sm font-medium text-green-600">
                                    A new verification link has been sent to your email address.
                                </div>
                            )}
                        </div>
                    )}
                    <div className="h-24" />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
