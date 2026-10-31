import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, BookOpen, GraduationCap, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

type Subject = { code: string | null; title: string | null; section?: string | null };
type AcademicRecord = {
    enrollment_year: number | null;
    completion_year: number | null;
    semester: string | null;
    subjects: Subject[];
    record_reference: string;
    recorded_by: string;
    recorded_at: string;
    version: number;
    history: {
        at: string;
        actor_name: string;
        record_reference: string;
        enrollment_year: number | null;
        completion_year: number | null;
        semester: string | null;
    }[];
};
type Defense = {
    id: number;
    title: string;
    stage: string | null;
    status: string;
    submitted_at: string | null;
    date: string | null;
    start: string | null;
    end: string | null;
    held_on: string | null;
    details_url: string | null;
    history: { event: string; at: string | null }[];
};
type Progress = {
    student: { id: number; name: string; school_id: string | null; email: string; program: string | null };
    stage: string | null;
    status: string;
    record: AcademicRecord | null;
    defenses: Defense[];
    portal: { semester: string | null; synced_at: string | null; subjects: Subject[] };
};
const readableDate = (value: string | null) => {
    if (!value) return 'Not available';
    const date = new Date(value.length === 10 ? value + 'T12:00:00+08:00' : value);
    return Number.isNaN(date.getTime())
        ? 'Not available'
        : new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', year: 'numeric', month: 'short', day: 'numeric' }).format(date);
};
const eventLabel = (event: string) => event.replaceAll('-', ' ').replace(/^./, (letter) => letter.toUpperCase());

export default function StudentProgressPage({ progress }: { progress: Progress }) {
    const { student, record, defenses, portal } = progress;
    const [editing, setEditing] = useState(false);
    const [saved, setSaved] = useState(false);
    const form = useForm({
        version: record?.version ?? 0,
        enrollment_year: record?.enrollment_year?.toString() ?? '',
        completion_year: record?.completion_year?.toString() ?? '',
        semester: record?.semester ?? '',
        subjects: (record?.subjects ?? []).map((subject) => ({ code: subject.code ?? '', title: subject.title ?? '' })),
        record_reference: record?.record_reference ?? '',
        verified_against_school_records: false as boolean,
    });
    const yearNow = Number(new Intl.DateTimeFormat('en', { timeZone: 'Asia/Manila', year: 'numeric' }).format(new Date()));
    const section = 'rounded-xl border bg-card p-5 text-card-foreground';
    const subjectsTable = (subjects: Subject[]) => (
        <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
                <thead>
                    <tr className="border-b">
                        <th className="py-2 pr-4">Code</th>
                        <th className="py-2 pr-4">Subject</th>
                        <th className="py-2">Section</th>
                    </tr>
                </thead>
                <tbody>
                    {subjects.map((subject, index) => (
                        <tr key={index} className="border-b last:border-0">
                            <td className="py-3 pr-4">{subject.code || '—'}</td>
                            <td className="py-3 pr-4">{subject.title || 'Not available'}</td>
                            <td className="py-3">{subject.section || '—'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Dashboard', href: '/dashboard' },
                { title: 'My Students', href: '/adviser/students-list' },
                { title: 'Student Progress', href: `/adviser/students/${student.id}/progress` },
            ]}
        >
            <Head title={`Student Progress · ${student.name}`} />
            <main className="mx-auto w-full max-w-6xl space-y-5 p-4 md:p-8">
                <Link href="/adviser/students-list" className="inline-flex items-center gap-2 text-sm underline">
                    <ArrowLeft className="h-4 w-4" />
                    My Students
                </Link>
                <header className="space-y-2">
                    <h1 className="flex items-center gap-2 text-2xl font-semibold">
                        <GraduationCap />
                        Student Progress
                    </h1>
                    <p className="font-medium">{student.name}</p>
                    <p className="text-muted-foreground text-sm">
                        {student.school_id || 'School ID not available'} · {student.email}
                    </p>
                    <p className="text-muted-foreground text-sm">{student.program || 'Program not available'}</p>
                </header>
                {saved && (
                    <p role="status" className="rounded-lg border border-green-600 p-3 text-sm text-green-700 dark:text-green-300">
                        Student progress information saved.
                    </p>
                )}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {[
                        ['Current defense stage', progress.stage || 'No defense request'],
                        ['Defense status', progress.status],
                        ['Enrollment year', record?.enrollment_year?.toString() || 'Not available'],
                        ['Program completion year', record?.completion_year?.toString() || 'Not verified'],
                    ].map(([title, value]) => (
                        <div key={title} className={section}>
                            <p className="text-muted-foreground text-xs">{title}</p>
                            <p className="mt-2 font-semibold">{value}</p>
                        </div>
                    ))}
                </div>
                <p className="text-muted-foreground text-sm">
                    Final defense completion and program completion are tracked separately. A passed schedule alone does not confirm attendance, a
                    passing outcome, or graduation.
                </p>
                <section className={section}>
                    <h2 className="mb-4 font-semibold">Defense milestones</h2>
                    <div className="grid gap-3 md:grid-cols-3">
                        {['Proposal', 'Prefinal', 'Final'].map((stage) => {
                            const latest = defenses.find((defense) => defense.stage === stage);
                            return (
                                <div key={stage} className="rounded-lg border p-4">
                                    <h3 className="font-medium">{stage}</h3>
                                    <p className="mt-2 text-sm">{latest?.status || 'No request recorded'}</p>
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        {latest?.held_on
                                            ? `Held: ${readableDate(latest.held_on)}`
                                            : latest?.date
                                              ? `Scheduled: ${readableDate(latest.date)}`
                                              : 'No confirmed defense date'}
                                    </p>
                                </div>
                            );
                        })}
                    </div>
                </section>
                <section className={section}>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="font-semibold">School-record information</h2>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setSaved(false);
                                setEditing(!editing);
                            }}
                        >
                            {editing ? 'Close form' : record ? 'Update school-record information' : 'Record school-verified information'}
                        </Button>
                    </div>
                    <p className="text-muted-foreground mt-3 text-sm">
                        Enter enrollment, program completion, and subjects only after checking the school records. This is an adviser-recorded
                        reference, not an automatic Registrar certification.
                    </p>
                    {record ? (
                        <div className="mt-3 space-y-1 text-sm">
                            <p>
                                Recorded by {record.recorded_by} · {readableDate(record.recorded_at)}
                            </p>
                            <p className="break-words whitespace-pre-wrap">Reference: {record.record_reference}</p>
                        </div>
                    ) : (
                        <p className="text-muted-foreground mt-3 text-sm">
                            No school-record information has been entered. Enrollment and completion years are not inferred from the student number or
                            account creation date.
                        </p>
                    )}
                    {editing && (
                        <form
                            className="mt-5 space-y-4 border-t pt-5"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.put(`/adviser/students/${student.id}/progress`, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        form.setData('version', form.data.version + 1);
                                        form.setData('verified_against_school_records', false);
                                        setEditing(false);
                                        setSaved(true);
                                    },
                                });
                            }}
                        >
                            {Object.keys(form.errors).length > 0 && (
                                <p role="alert" className="text-destructive rounded-lg border p-3 text-sm">
                                    {Object.values(form.errors).join(' ')}
                                </p>
                            )}
                            <div className="grid gap-4 sm:grid-cols-2">
                                <label className="space-y-1 text-sm">
                                    Enrollment year
                                    <Input
                                        type="number"
                                        min={1900}
                                        max={yearNow}
                                        value={form.data.enrollment_year}
                                        onChange={(event) => form.setData('enrollment_year', event.target.value)}
                                        placeholder="Year first enrolled in this program"
                                    />
                                </label>
                                <label className="space-y-1 text-sm">
                                    Program completion year (optional)
                                    <Input
                                        type="number"
                                        min={1900}
                                        max={yearNow}
                                        value={form.data.completion_year}
                                        onChange={(event) => form.setData('completion_year', event.target.value)}
                                        placeholder="Leave blank until school-confirmed"
                                    />
                                </label>
                            </div>
                            <label className="block space-y-1 text-sm">
                                Subject semester / school year
                                <Input
                                    value={form.data.semester}
                                    maxLength={150}
                                    onChange={(event) => form.setData('semester', event.target.value)}
                                    placeholder="Example: 1st semester, 2026–2027"
                                />
                            </label>
                            <div className="space-y-3">
                                <div className="flex items-center justify-between">
                                    <h3 className="text-sm font-medium">Subjects taking in that semester</h3>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        disabled={form.data.subjects.length >= 50}
                                        onClick={() => form.setData('subjects', [...form.data.subjects, { code: '', title: '' }])}
                                    >
                                        <Plus className="mr-1 h-4 w-4" />
                                        Add subject
                                    </Button>
                                </div>
                                {form.data.subjects.map((subject, index) => (
                                    <div key={index} className="flex flex-wrap gap-2">
                                        <Input
                                            aria-label={`Subject ${index + 1} code`}
                                            className="w-28"
                                            value={subject.code}
                                            maxLength={40}
                                            placeholder="Code"
                                            onChange={(event) =>
                                                form.setData(
                                                    'subjects',
                                                    form.data.subjects.map((row, i) => (i === index ? { ...row, code: event.target.value } : row)),
                                                )
                                            }
                                        />
                                        <Input
                                            aria-label={`Subject ${index + 1} title`}
                                            className="min-w-48 flex-1"
                                            value={subject.title}
                                            maxLength={200}
                                            required
                                            placeholder="Subject name"
                                            onChange={(event) =>
                                                form.setData(
                                                    'subjects',
                                                    form.data.subjects.map((row, i) => (i === index ? { ...row, title: event.target.value } : row)),
                                                )
                                            }
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Remove subject ${index + 1}`}
                                            onClick={() =>
                                                form.setData(
                                                    'subjects',
                                                    form.data.subjects.filter((_, i) => i !== index),
                                                )
                                            }
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </div>
                                ))}
                            </div>
                            <label className="block space-y-1 text-sm">
                                School record reference / verification note
                                <Textarea
                                    value={form.data.record_reference}
                                    minLength={5}
                                    maxLength={1500}
                                    required
                                    onChange={(event) => form.setData('record_reference', event.target.value)}
                                    placeholder="Identify the school record checked and explain corrections when updating."
                                />
                            </label>
                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    required
                                    checked={form.data.verified_against_school_records}
                                    onChange={(event) => form.setData('verified_against_school_records', event.target.checked)}
                                    className="mt-1"
                                />
                                I checked these details against official school records.
                            </label>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'Saving…' : 'Save school-record information'}
                            </Button>
                        </form>
                    )}
                </section>
                <section className={section}>
                    <h2 className="flex items-center gap-2 font-semibold">
                        <BookOpen className="h-4 w-4" />
                        Subjects
                    </h2>
                    {record?.semester ? (
                        <div className="mt-3 space-y-3">
                            <p className="text-sm">{record.semester} · Adviser-recorded</p>
                            {record.subjects.length ? (
                                subjectsTable(record.subjects)
                            ) : (
                                <p className="text-muted-foreground text-sm">No subjects recorded for this semester.</p>
                            )}
                        </div>
                    ) : (
                        <p className="text-muted-foreground mt-3 text-sm">
                            Current subjects are not available until school-record information is entered or school data is synced.
                        </p>
                    )}
                    {portal.subjects.length > 0 && (
                        <details className="mt-4 rounded-lg border p-3">
                            <summary className="cursor-pointer text-sm font-medium">Latest school-portal subject snapshot</summary>
                            <p className="text-muted-foreground my-3 text-xs">
                                {portal.semester || 'Semester not available'} · Last synced: {readableDate(portal.synced_at)}. This snapshot is not
                                live enrollment confirmation.
                            </p>
                            {subjectsTable(portal.subjects)}
                        </details>
                    )}
                </section>
                <section className={section}>
                    <h2 className="mb-4 font-semibold">Defense progress history</h2>
                    {defenses.length ? (
                        <div className="space-y-4">
                            {defenses.map((defense) => (
                                <article key={defense.id} className="space-y-2 rounded-lg border p-4">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h3 className="font-medium">{defense.title}</h3>
                                            <p className="text-muted-foreground text-sm">
                                                {defense.stage || 'Defense'} · {defense.status}
                                            </p>
                                        </div>
                                        {defense.details_url && (
                                            <Link href={defense.details_url} className="text-sm underline">
                                                View defense request
                                            </Link>
                                        )}
                                    </div>
                                    <p className="text-muted-foreground text-xs">
                                        Submitted: {readableDate(defense.submitted_at)}
                                        {defense.date &&
                                            ` · Scheduled: ${readableDate(defense.date)} ${defense.start?.slice(0, 5) || ''}${defense.end ? '–' + defense.end.slice(0, 5) : ''} (PH)`}
                                    </p>
                                    {defense.history.length > 0 && (
                                        <ol className="space-y-2 border-l pl-4 text-sm">
                                            {defense.history.map((event, index) => (
                                                <li key={index}>
                                                    <span>{eventLabel(event.event)}</span>
                                                    <span className="text-muted-foreground ml-2 text-xs">{readableDate(event.at)}</span>
                                                </li>
                                            ))}
                                        </ol>
                                    )}
                                </article>
                            ))}
                        </div>
                    ) : (
                        <p className="text-muted-foreground text-sm">No defense requests recorded.</p>
                    )}
                </section>
                {record && record.history.length > 0 && (
                    <details className={section}>
                        <summary className="cursor-pointer font-medium">Previous school-record updates ({record.history.length})</summary>
                        <ul className="mt-4 space-y-3 text-sm">
                            {[...record.history].reverse().map((entry, index) => (
                                <li key={index} className="space-y-1 border-b pb-3 last:border-0">
                                    <p>
                                        {entry.actor_name} · {readableDate(entry.at)}
                                    </p>
                                    <p>
                                        Enrollment: {entry.enrollment_year ?? 'Not available'} · Completion: {entry.completion_year ?? 'Not verified'}
                                    </p>
                                    <p className="break-words whitespace-pre-wrap">{entry.record_reference}</p>
                                </li>
                            ))}
                        </ul>
                    </details>
                )}
            </main>
        </AppLayout>
    );
}
