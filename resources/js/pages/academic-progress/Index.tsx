import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { filterStudentsByProgram, studentProgramOptions, type ProgramCatalogEntry } from '@/utils/student-progress-filters';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
type Student = {
    id: number;
    name: string;
    program: string | null;
    progress: {
        research_type: { label: string; determined: boolean };
        classification: string | null;
        missed_defenses: number;
        stage: string | null;
        completion: string | null;
        result: string | null;
        subject_enrollment: string | null;
    };
};
const progressLabel = (value: string | null, fallback = 'Not recorded') =>
    value ? value.replace(/[-_]+/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : fallback;

export default function AcademicProgress({ students, programCatalog = [] }: { students: Student[]; programCatalog?: ProgramCatalogEntry[] }) {
    const [search, setSearch] = useState('');
    const [classification, setClassification] = useState('');
    const [program, setProgram] = useState('');
    const [sort, setSort] = useState('');
    const { choices, keyForStudent } = studentProgramOptions(students, programCatalog);
    const matchingStudents = students.filter(
        (s) =>
            `${s.name} ${s.program}`.toLowerCase().includes(search.toLowerCase()) &&
            (!classification || (classification === 'missed' ? s.progress.missed_defenses > 0 : s.progress.classification === classification)),
    );
    const rows = filterStudentsByProgram(matchingStudents, program, sort, keyForStudent);
    return (
        <AppLayout breadcrumbs={[{ title: 'Academic Progress', href: '/academic-progress' }]}>
            <Head title="Academic Progress" />
            <main className="space-y-5 p-6">
                <h1 className="text-2xl font-semibold">Academic Progress</h1>
                <p className="text-muted-foreground">
                    Coordinators record school-verified information and defense outcomes. Advisers, the Dean, and administrative assistants can view.
                </p>
                <div className="flex flex-wrap gap-3">
                    <Input className="max-w-sm" placeholder="Search student or program" value={search} onChange={(e) => setSearch(e.target.value)} />
                    <select
                        aria-label="Student classification"
                        className="bg-background rounded border p-2"
                        value={classification}
                        onChange={(e) => setClassification(e.target.value)}
                    >
                        <option value="">All students</option>
                        <option value="new">New</option>
                        <option value="continuing">Continuing</option>
                        <option value="missed">Previously did not defend</option>
                    </select>
                    <select
                        aria-label="Filter by program"
                        className="bg-background rounded border p-2"
                        value={program}
                        onChange={(e) => setProgram(e.target.value)}
                    >
                        <option value="">All programs</option>
                        {choices.map(({ value, label }) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                    <select
                        aria-label="Sort students"
                        className="bg-background rounded border p-2"
                        value={sort}
                        onChange={(e) => setSort(e.target.value)}
                    >
                        <option value="">Default order</option>
                        <option value="program-asc">Program: A–Z</option>
                        <option value="program-desc">Program: Z–A</option>
                    </select>
                </div>
                <p role="status">{rows.length} students</p>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-left text-sm">
                        <thead>
                            <tr className="border-b">
                                {[
                                    'Student',
                                    'Program',
                                    'Research type',
                                    'Classification',
                                    'Defense stage',
                                    'Completion / result',
                                    'Subject enrollment',
                                    'Progress',
                                ].map((h) => (
                                    <th className="p-3" key={h}>
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((s) => (
                                <tr className="border-b" key={s.id}>
                                    <td className="p-3">{s.name}</td>
                                    <td className="p-3">{s.program}</td>
                                    <td className="p-3">{s.progress.research_type.label}</td>
                                    <td className="p-3">
                                        {progressLabel(s.progress.classification)}
                                        {s.progress.missed_defenses > 0 && (
                                            <p className="text-muted-foreground text-xs">
                                                Previously did not defend: {s.progress.missed_defenses} request(s)
                                            </p>
                                        )}
                                    </td>
                                    <td className="p-3">{progressLabel(s.progress.stage, 'Not started')}</td>
                                    <td className="p-3">
                                        {progressLabel(s.progress.completion)} / {progressLabel(s.progress.result)}
                                    </td>
                                    <td className="p-3">{s.progress.subject_enrollment || 'Not recorded'}</td>
                                    <td className="p-3">
                                        <Link className="underline" href={`/academic-progress/${s.id}`}>
                                            View Progress
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {!rows.length && <p className="p-6 text-center">No matching students in your assigned records.</p>}
                </div>
            </main>
        </AppLayout>
    );
}
