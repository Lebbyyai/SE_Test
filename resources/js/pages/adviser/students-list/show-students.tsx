import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import axios from 'axios';
import { Check, Mail, Trash, Users } from 'lucide-react';
import { useEffect, useState } from 'react';

type Student = {
    id: number;
    student_number: string | null;
    first_name: string | null;
    middle_name: string | null;
    last_name: string | null;
    email: string | null;
    program: string | null;
    coordinator_name?: string | null; // <-- Add this line
};

function getInitials(student: Student) {
    const first = student.first_name?.trim()?.[0] ?? '';
    const last = student.last_name?.trim()?.[0] ?? '';
    return (first + last).toUpperCase() || 'U';
}

export default function ShowStudents() {
    const [students, setStudents] = useState<Student[]>([]);
    const [pendingStudents, setPendingStudents] = useState<Student[]>([]);
    const [pendingLoading, setPendingLoading] = useState(false);
    const [loading, setLoading] = useState(true);
    const [query, setQuery] = useState('');

    const [confirmation, setConfirmation] = useState<{ student: Student; decision: 'accept' | 'reject' } | null>(null);

    useEffect(() => {
        loadAccepted();
        fetchPending();
    }, []);

    const loadAccepted = async () => {
        setLoading(true);
        try {
            const res = await axios.get('/api/adviser/students');
            setStudents(res.data || []);
        } catch (err) {
            console.error('Failed to load students', err);
        } finally {
            setLoading(false);
        }
    };

    const fetchPending = async () => {
        setPendingLoading(true);
        try {
            const res = await axios.get('/api/adviser/pending-students');
            setPendingStudents(res.data || []);
        } catch (err) {
            console.error('Failed to load pending', err);
        } finally {
            setPendingLoading(false);
        }
    };

    const lowerQuery = query.toLowerCase();
    const filteredStudents = students.filter(
        (s) =>
            (s.student_number?.toLowerCase().includes(lowerQuery) ?? false) ||
            (s.first_name?.toLowerCase().includes(lowerQuery) ?? false) ||
            (s.last_name?.toLowerCase().includes(lowerQuery) ?? false) ||
            (s.email?.toLowerCase().includes(lowerQuery) ?? false) ||
            (s.program?.toLowerCase().includes(lowerQuery) ?? false),
    );

    const [assignmentError, setAssignmentError] = useState('');
    const [decidingStudent, setDecidingStudent] = useState<number | null>(null);
    const decideAssignment = async (student: Student, decision: 'accept' | 'reject', sendEmail: boolean) => {
        setAssignmentError('');
        setDecidingStudent(student.id);
        try {
            const token = document.cookie
                .split(';')
                .map((part) => part.trim())
                .find((part) => part.startsWith('XSRF-TOKEN='));
            await axios.post(
                `/api/adviser/pending-students/${student.id}/${decision}`,
                { send_email: sendEmail },
                {
                    headers: { Accept: 'application/json', ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token.slice(11)) } : {}) },
                },
            );
            setConfirmation(null);
            await loadAccepted();
            await fetchPending();
            window.dispatchEvent(new Event('notifications-updated'));
        } catch (error) {
            setAssignmentError(
                axios.isAxiosError(error) && error.response?.status === 419
                    ? 'Your session has expired. Refresh the page and try again.'
                    : 'Unable to update the assignment. Refresh the pending list and try again.',
            );
        } finally {
            setDecidingStudent(null);
        }
    };

    if (loading) {
        return (
            <div className="bg-background m-0 flex min-h-[70vh] w-full flex-col gap-4 p-0">
                <Skeleton className="bg-muted dark:bg-muted mx-8 mt-8 h-6 w-1/6 rounded" />
                <Skeleton className="bg-muted dark:bg-muted mx-8 h-12 w-3/4 rounded" />
                <Skeleton className="bg-muted dark:bg-muted mx-8 h-12 w-2/3 rounded" />
                <Skeleton className="bg-muted dark:bg-muted mt-4 h-[400px] w-full rounded" />
            </div>
        );
    }

    function renderTableRows(rows: Student[], isPending = false) {
        if (rows.length === 0) {
            return (
                <TableRow>
                    <TableCell colSpan={isPending ? 6 : 5} className="text-muted-foreground py-8 text-center text-sm dark:text-zinc-400">
                        No students found.
                    </TableCell>
                </TableRow>
            );
        }

        return rows.map((s) => (
            <TableRow key={s.id} className="dark:hover:bg-zinc-700">
                <TableCell className="flex items-center gap-3 dark:text-zinc-200">
                    <Avatar className="h-8 w-8">
                        <AvatarFallback>{getInitials(s)}</AvatarFallback>
                    </Avatar>
                    <span>
                        {s.first_name || ''} {s.middle_name ? s.middle_name[0] + '.' : ''} {s.last_name || ''}
                    </span>
                </TableCell>
                <TableCell className="dark:text-zinc-200">{s.student_number || 'N/A'}</TableCell>
                <TableCell className="dark:text-zinc-200">{s.email || 'N/A'}</TableCell>
                <TableCell className="dark:text-zinc-200">{s.program || 'N/A'}</TableCell>
                <TableCell className="dark:text-zinc-200">{s.coordinator_name || 'N/A'}</TableCell>
                {isPending && (
                    <TableCell className="flex justify-center gap-2">
                        <Button
                            variant="outline"
                            size="icon"
                            title="Accept"
                            onClick={() => {
                                setAssignmentError('');
                                setConfirmation({ student: s, decision: 'accept' });
                            }}
                            aria-label={`Accept ${s.first_name ?? ''} ${s.last_name ?? ''}`}
                            disabled={decidingStudent !== null}
                            className="border-green-300 text-green-600 hover:bg-green-50 hover:text-green-700"
                        >
                            <Check className="h-4 w-4" />
                        </Button>
                        <Button
                            variant="outline"
                            size="icon"
                            title="Reject"
                            onClick={() => {
                                setAssignmentError('');
                                setConfirmation({ student: s, decision: 'reject' });
                            }}
                            aria-label={`Decline ${s.first_name ?? ''} ${s.last_name ?? ''}`}
                            disabled={decidingStudent !== null}
                            className="border-rose-300 text-rose-600 hover:bg-rose-50 hover:text-rose-700"
                        >
                            <Trash className="h-4 w-4" />
                        </Button>
                    </TableCell>
                )}
            </TableRow>
        ));
    }

    return (
        <div className="bg-background relative flex h-full flex-1 flex-col gap-4 overflow-auto rounded-xl pt-5 pr-7 pl-7 transition-colors dark:bg-zinc-900">
            {assignmentError && (
                <p role="alert" className="text-destructive rounded-lg border p-3 text-sm">
                    {assignmentError}
                </p>
            )}
            {/* Header - two rows: title row, then search row */}
            <div className="border-border mb-1 w-full overflow-hidden rounded-lg border bg-white dark:border-zinc-700 dark:bg-zinc-900">
                {/* Title row */}
                <div className="flex w-full flex-row items-center justify-between border-b bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                    <div className="flex items-center gap-2">
                        <div className="flex h-10 w-10 items-center justify-center rounded-full border border-rose-500 bg-rose-500/10">
                            <Users className="h-5 w-5 text-rose-400" />
                        </div>
                        <div>
                            <span className="text-base font-semibold text-zinc-900 dark:text-zinc-100">My Students</span>
                            <span className="text-muted-foreground block text-xs dark:text-zinc-400">
                                This section shows all students assigned to you.
                            </span>
                        </div>
                    </div>

                    {/* empty placeholder for actions (keeps layout consistent) */}
                    <div />
                </div>

                {/* Search row (separate) */}
                <div className="flex w-full flex-row items-center justify-between border-t bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                    <Input
                        type="text"
                        placeholder="Search..."
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        className="bg-background dark:bg-background h-8 max-w-xs py-1 text-sm"
                    />
                </div>
            </div>

            <Tabs defaultValue="assigned" className="w-full">
                <TabsList className="mb-3">
                    <TabsTrigger value="assigned" className="flex items-center gap-2">
                        Assigned
                        <span className="inline-flex items-center justify-center px-2 py-0.5 text-xs text-zinc-700 dark:text-zinc-100">
                            {students.length}
                        </span>
                    </TabsTrigger>
                    <TabsTrigger value="pending" className="flex items-center gap-2">
                        Pending
                        <span className="inline-flex items-center justify-center px-2 py-0.5 text-xs text-zinc-700 dark:text-zinc-100">
                            {pendingStudents.length}
                        </span>
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="pending">
                    <div className="border-border w-full max-w-full overflow-x-auto rounded-md border bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="dark:text-zinc-100">Name</TableHead>
                                    <TableHead className="dark:text-zinc-100">Student #</TableHead>
                                    <TableHead className="dark:text-zinc-100">Email</TableHead>
                                    <TableHead className="dark:text-zinc-100">Program</TableHead>
                                    <TableHead className="dark:text-zinc-100">Program Coordinator</TableHead>
                                    <TableHead className="text-center dark:text-zinc-100">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {pendingLoading ? (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-muted-foreground py-8 text-center text-sm dark:text-zinc-400">
                                            Loading pending...
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    renderTableRows(pendingStudents, true)
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </TabsContent>

                <TabsContent value="assigned">
                    <div className="border-border w-full max-w-full overflow-x-auto rounded-md border bg-white dark:border-zinc-700 dark:bg-zinc-900">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="dark:text-zinc-100">Name</TableHead>
                                    <TableHead className="dark:text-zinc-100">School ID</TableHead>
                                    <TableHead className="dark:text-zinc-100">Email</TableHead>
                                    <TableHead className="dark:text-zinc-100">Program</TableHead>
                                    <TableHead className="dark:text-zinc-100">Program Coordinator</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>{renderTableRows(filteredStudents, false)}</TableBody>
                        </Table>
                    </div>
                </TabsContent>
            </Tabs>
            <Dialog
                open={confirmation !== null}
                onOpenChange={(open) => {
                    if (!open && decidingStudent === null) setConfirmation(null);
                }}
            >
                <DialogContent
                    className="sm:max-w-lg"
                    onEscapeKeyDown={(event) => {
                        if (decidingStudent !== null) event.preventDefault();
                    }}
                    onPointerDownOutside={(event) => {
                        if (decidingStudent !== null) event.preventDefault();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Mail className={confirmation?.decision === 'accept' ? 'size-5 text-green-500' : 'size-5 text-rose-500'} />
                            {confirmation?.decision === 'accept' ? 'Send Acceptance Email?' : 'Send Decline Email?'}
                        </DialogTitle>
                        <DialogDescription>
                            You are {confirmation?.decision === 'accept' ? 'accepting' : 'declining'} this student assignment. Would you also like to
                            send an email notification to the student?
                        </DialogDescription>
                    </DialogHeader>
                    <div className="bg-muted rounded-lg p-4 text-sm">
                        <p className="font-semibold">
                            {confirmation?.student.first_name} {confirmation?.student.last_name}
                        </p>
                        <p className="text-muted-foreground mt-1">{confirmation?.student.email}</p>
                    </div>
                    <p className="text-muted-foreground text-sm">
                        Both options save your decision and notify the student and coordinator in the system. Skip Email only skips the email.
                    </p>
                    {assignmentError && (
                        <p role="alert" className="text-destructive text-sm">
                            {assignmentError}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={decidingStudent !== null}
                            onClick={() => confirmation && decideAssignment(confirmation.student, confirmation.decision, false)}
                        >
                            Skip Email
                        </Button>
                        <Button
                            className={confirmation?.decision === 'accept' ? 'bg-green-700 text-white hover:bg-green-800' : ''}
                            disabled={decidingStudent !== null}
                            onClick={() => confirmation && decideAssignment(confirmation.student, confirmation.decision, true)}
                        >
                            <Mail className="mr-2 size-4" />
                            {decidingStudent !== null ? 'Saving...' : 'Send Email'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
