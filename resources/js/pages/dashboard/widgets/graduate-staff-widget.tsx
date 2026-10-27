import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Mail } from 'lucide-react';
import { useState } from 'react';

export type GraduateStaff = {
    id: number;
    name: string;
    email: string;
    role: string;
    programs: string[];
    students: number | null;
    advisers: number | null;
};

export default function GraduateStaffWidget({ staff }: { staff: GraduateStaff[] }) {
    const [role, setRole] = useState('Coordinator');
    const [query, setQuery] = useState('');
    const [program, setProgram] = useState('');
    const programs = [...new Set(staff.flatMap((person) => person.programs))].sort();
    const rows = staff.filter(
        (person) =>
            person.role === role &&
            (!program || person.programs.includes(program)) &&
            `${person.name} ${person.email}`.toLowerCase().includes(query.toLowerCase()),
    );

    return (
        <section aria-label="Graduate School Staff" className="bg-card text-card-foreground min-w-0 rounded-xl border p-5 md:p-6 dark:bg-zinc-900">
            <h2 className="text-lg font-semibold">Graduate School Staff</h2>
            <p className="text-muted-foreground mt-1 text-sm">Contact coordinators and administrative assistants.</p>
            <div className="my-5 flex flex-wrap items-center justify-between gap-3">
                <div className="bg-muted flex gap-1 rounded-lg p-1">
                    {['Coordinator', 'Administrative Assistant'].map((value) => (
                        <Button
                            key={value}
                            variant={role === value ? 'secondary' : 'ghost'}
                            size="sm"
                            aria-pressed={role === value}
                            onClick={() => setRole(value)}
                        >
                            {value === 'Coordinator' ? 'Coordinators' : 'Assistants'} ({staff.filter((person) => person.role === value).length})
                        </Button>
                    ))}
                </div>
                <div className="flex min-w-0 flex-wrap gap-2">
                    <select
                        aria-label="Filter staff by program"
                        value={program}
                        onChange={(event) => setProgram(event.target.value)}
                        className="bg-background h-9 w-full max-w-64 rounded-md border px-3 text-sm"
                    >
                        <option value="">All Programs</option>
                        {programs.map((value) => (
                            <option key={value} value={value}>
                                {value}
                            </option>
                        ))}
                    </select>
                    <Input
                        aria-label="Search staff"
                        placeholder="Search by name or email..."
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        className="w-full sm:w-64"
                    />
                </div>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead className="border-b">
                        <tr>
                            <th className="px-2 py-3">Name</th>
                            <th className="px-2 py-3">Programs</th>
                            <th className="px-2 py-3 text-center">Students</th>
                            <th className="px-2 py-3 text-center">Advisers</th>
                            <th className="px-2 py-3">Contact</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((person) => (
                            <tr key={person.id} className="border-b last:border-0">
                                <td className="px-2 py-3">
                                    <p className="font-medium">{person.name}</p>
                                    <p className="text-muted-foreground mt-1 text-xs">{person.email}</p>
                                </td>
                                <td className="min-w-48 px-2 py-3">
                                    <div className="flex flex-wrap gap-1">
                                        {person.programs.length ? (
                                            person.programs.map((name) => (
                                                <span key={name} className="rounded-md border px-2 py-1 text-xs">
                                                    {name}
                                                </span>
                                            ))
                                        ) : (
                                            <span className="text-muted-foreground">No programs assigned</span>
                                        )}
                                    </div>
                                </td>
                                <td className="px-2 py-3 text-center">{person.students ?? '—'}</td>
                                <td className="px-2 py-3 text-center">{person.advisers ?? '—'}</td>
                                <td className="px-2 py-3">
                                    <a
                                        href={`mailto:${person.email}`}
                                        aria-label={`Email ${person.name}`}
                                        className="text-muted-foreground hover:text-foreground inline-flex rounded-md p-2"
                                    >
                                        <Mail className="size-4" />
                                    </a>
                                </td>
                            </tr>
                        ))}
                        {!rows.length && (
                            <tr>
                                <td colSpan={5} className="text-muted-foreground py-8 text-center">
                                    No staff match these filters.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            <p className="text-muted-foreground mt-3 text-xs">
                Student counts include accepted adviser connections assigned by the coordinator. A dash means the count does not apply.
            </p>
        </section>
    );
}
