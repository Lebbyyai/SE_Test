import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Faculty = { user_id: number; role: string; status: string; name: string; note?: string };
type Subject = { id: number; name: string; room: string | null; date: string | null; start: string | null; end: string | null; faculty: Faculty[] };
type Application = {
    id: number;
    application_id: number;
    student_id: number;
    student: string;
    program: string;
    school_year: string;
    semester: string | null;
    status: string;
    aa_status: string;
    aa_note: string | null;
    registrar_status: string;
    registrar_note: string | null;
    version: number;
    missing: string[];
    receipt_status: string | null;
    subjects: Subject[];
    history: { action: string; actor: string; at: string; note: string | null }[];
    deliveries: { channel: string; status: string; detail: string | null; created_at: string }[];
};
type Props = { role: string; userId: number; program: string | null; applications: Application[]; faculty: { id: number; name: string }[] };
const selectStyle = 'bg-background text-foreground border-input w-full rounded-md border px-3 py-2';
const stamp = (value: string) => new Date(value).toLocaleString('en-PH', { timeZone: 'Asia/Manila' });
const label = (value: string) => value.replace(/\b\w/g, (c) => c.toUpperCase());
export default function Index({ role, applications }: Props) {
    const [search, setSearch] = useState('');
    const [year, setYear] = useState('');
    const [semester, setSemester] = useState('');
    const [selectedProgram, setProgram] = useState('');
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    const filtered = applications.filter(
        (a) =>
            (!year || a.school_year === year) &&
            (!semester || a.semester === semester) &&
            (!selectedProgram || a.program === selectedProgram) &&
            `${a.student} ${a.program} ${a.status}`.toLowerCase().includes(search.toLowerCase()),
    );
    const params = new URLSearchParams({ school_year: year, semester, program: selectedProgram });
    if (!semester) params.delete('semester');
    const isStaff = ['Coordinator', 'Administrative Assistant', 'Registrar', 'Dean', 'Super Admin', 'SuperAdmin'].includes(role);
    return (
        <AppLayout>
            <Head title="Comprehensive Exam Workflow Archive" />
            <div className="space-y-6 p-6">
                <div>
                    <h1 className="text-2xl font-semibold">Comprehensive Exam Workflow Archive</h1>
                    <p className="text-muted-foreground mt-2">Historical applications and exam activity.</p>
                </div>
                {flash?.success && (
                    <p role="status" className="rounded-md border border-green-600 p-3">
                        {flash.success}
                    </p>
                )}
                <div className="flex flex-wrap gap-3">
                    {role !== 'Adviser' && (
                        <Button variant="outline" asChild>
                            <Link href="/comprehensive-documents">Exam documents</Link>
                        </Button>
                    )}
                    {role === 'Student' && (
                        <Button variant="outline" asChild>
                            <Link href="/payment">Submit / track exam receipt</Link>
                        </Button>
                    )}
                    {['Coordinator', 'Administrative Assistant'].includes(role) && (
                        <Button variant="outline" asChild>
                            <Link href="/coordinator/compre-payment">Review exam receipts</Link>
                        </Button>
                    )}
                    {isStaff && (
                        <Button variant="outline" asChild>
                            <a href={`/comprehensive-workflow/export?${params}`}>Export faculty / honorarium reference CSV</a>
                        </Button>
                    )}
                </div>
                <div className="grid gap-3 md:grid-cols-4">
                    <Input
                        placeholder="Search student, program or status"
                        aria-label="Search applications"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    <select aria-label="School year" className={selectStyle} value={year} onChange={(e) => setYear(e.target.value)}>
                        <option value="">All school years</option>
                        {[...new Set(applications.map((a) => a.school_year))].map((y) => (
                            <option key={y}>{y}</option>
                        ))}
                    </select>
                    <select aria-label="Semester" className={selectStyle} value={semester} onChange={(e) => setSemester(e.target.value)}>
                        <option value="">All semesters</option>
                        {['1st', '2nd', 'Summer'].map((v) => (
                            <option key={v}>{v}</option>
                        ))}
                    </select>
                    <select aria-label="Program" className={selectStyle} value={selectedProgram} onChange={(e) => setProgram(e.target.value)}>
                        <option value="">All programs</option>
                        {[...new Set(applications.map((a) => a.program))].map((v) => (
                            <option key={v}>{v}</option>
                        ))}
                    </select>
                </div>
                {!filtered.length && <p className="rounded-xl border p-8 text-center">No applications found.</p>}
                {filtered.map((a) => (
                    <section key={`${a.id}-${a.version}`} id={`application-${a.application_id}`} className="space-y-4 rounded-xl border p-5">
                        <header className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-semibold">
                                    {a.student} · Application #{a.application_id}
                                </h2>
                                <p className="text-muted-foreground">
                                    {a.program} · {a.school_year} · {a.semester ?? 'Semester not recorded'}
                                </p>
                            </div>
                            <strong className="rounded-md border px-3 py-2 text-sm">{a.status}</strong>
                        </header>
                        <ol className="grid gap-2 text-sm md:grid-cols-5">
                            {[
                                ['Submitted', true],
                                ['AA evaluated', a.aa_status === 'evaluated'],
                                ['Faculty confirmed', !a.missing.length],
                                ['Registrar approved', a.registrar_status === 'approved'],
                                ['Payment verified', a.receipt_status === 'approved'],
                            ].map(([text, done]) => (
                                <li key={String(text)} className={`rounded-md border p-2 ${done ? 'border-green-600' : 'text-muted-foreground'}`}>
                                    {done ? '✓ ' : '○ '}
                                    {text}
                                </li>
                            ))}
                        </ol>
                        {a.aa_note && <p className="bg-muted rounded-md p-3">AA notes: {a.aa_note}</p>}
                        {a.registrar_note && <p className="bg-muted rounded-md p-3">Registrar notes: {a.registrar_note}</p>}
                        {!!a.missing.length && <p className="text-muted-foreground text-sm">Awaiting: {a.missing.join('; ')}.</p>}
                        <div className="space-y-3">
                            {a.subjects.map((s) => (
                                <div key={s.id} className="rounded-lg border p-4">
                                    <h3 className="font-semibold">{s.name}</h3>
                                    <p className="text-muted-foreground mt-1">
                                        {s.room ? `${s.room} · ${s.date} · ${s.start}–${s.end} (PH)` : '⚠ Room and schedule not assigned'}
                                    </p>
                                    <ul className="mt-2 space-y-1">
                                        {s.faculty.map((f) => (
                                            <li key={f.user_id}>
                                                {f.name} · {label(f.role)} · {label(f.status)}
                                                {f.note && ` — ${f.note}`}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>
                        {role === 'Coordinator' && (
                            <Button variant="outline" asChild>
                                <Link href={`/academic-progress/${a.student_id}`}>Record verified exam result after the exam</Link>
                            </Button>
                        )}
                        <p className="text-muted-foreground text-sm">
                            Application approval and payment do not mean the exam was passed. A Coordinator must record the verified exam result
                            before Proposal Defense becomes available. Faculty assignments do not establish rendered service or an honorarium payment.
                        </p>
                        <details>
                            <summary className="cursor-pointer font-medium">Activity history ({a.history.length})</summary>
                            <ul className="mt-3 space-y-3">
                                {[...a.history].reverse().map((h, i) => (
                                    <li key={i} className="border-l-2 pl-3">
                                        <strong>{h.action}</strong>
                                        <p className="text-muted-foreground text-sm">
                                            {h.actor} · {stamp(h.at)}
                                        </p>
                                        {h.note && <p>{h.note}</p>}
                                    </li>
                                ))}
                            </ul>
                        </details>
                        {!!a.deliveries.length && (
                            <details>
                                <summary className="cursor-pointer">Email / SMS delivery status</summary>
                                <ul className="mt-2 space-y-2">
                                    {a.deliveries.map((d, i) => (
                                        <li key={i} className="text-sm">
                                            {label(d.channel)}: {label(d.status)} {d.detail && `— ${d.detail}`}
                                        </li>
                                    ))}
                                </ul>
                                <p className="text-muted-foreground mt-2 text-xs">
                                    In-app notices appear immediately. External messages need configured delivery services and a running queue worker.
                                </p>
                            </details>
                        )}
                    </section>
                ))}
            </div>
        </AppLayout>
    );
}
