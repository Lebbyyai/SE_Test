export type StudentProgressSummary = {
    stage: string | null;
    status: string;
    subject_enrollment?: string | null;
    completion_year: number | null;
    semester?: string | null;
    subjects?: { code?: string | null; title?: string | null }[];
    updated_at?: string | null;
};
type StudentProgram = { program?: string | null };
export type ProgramCatalogEntry = { name: string; code: string };

const programKey = (name?: string | null) => (name ?? '').toLowerCase().replace(/[,:]/g, '').trim().replace(/\s+/g, ' ');

export function studentProgramOptions(students: StudentProgram[], catalog: ProgramCatalogEntry[]) {
    const names = new Map<string, string>();
    const options = new Map<string, string>();
    for (const { name, code } of catalog) {
        const key = programKey(name);
        names.set(programKey(code), key);
        options.set(key, code ? code + ' — ' + name : name);
    }
    const keyForStudent = (student: StudentProgram) => {
        const key = programKey(student.program);
        return names.get(key) ?? key;
    };
    for (const student of students) {
        const key = keyForStudent(student);
        if (key && !options.has(key)) options.set(key, student.program!.trim());
    }

    return {
        choices: Array.from(options, ([value, label]) => ({ value, label })).sort((a, b) => a.label.localeCompare(b.label)),
        keyForStudent,
    };
}

export function filterStudentsByProgram<T extends StudentProgram>(
    students: T[],
    program: string,
    sort: string,
    keyForStudent: (student: T) => string,
): T[] {
    const result = students.filter((student) => !program || keyForStudent(student) === program);
    if (sort !== 'program-asc' && sort !== 'program-desc') return result;

    return result.sort((a, b) => {
        const first = keyForStudent(a);
        const second = keyForStudent(b);
        if (!first) return second ? 1 : 0;
        if (!second) return -1;
        return sort === 'program-asc' ? first.localeCompare(second) : second.localeCompare(first);
    });
}

export type ProgressFilters = { stage: string; completion: string; semester: string; subject: string; sort: string };
export const defaultProgressFilters: ProgressFilters = { stage: '', completion: '', semester: '', subject: '', sort: 'updated-desc' };
export function filterStudentProgress<
    T extends { id: number; first_name?: string | null; last_name?: string | null; progress?: StudentProgressSummary },
>(students: T[], filters: ProgressFilters): T[] {
    const result = students.filter(({ progress: p }) => {
        if (filters.stage && (p?.stage || 'not-started') !== filters.stage) return false;

        if (
            filters.completion &&
            (filters.completion === 'not-verified' ? p?.completion_year != null : p?.completion_year?.toString() !== filters.completion)
        )
            return false;
        if (filters.semester && p?.semester !== filters.semester) return false;
        const subject = filters.subject.trim().toLowerCase();
        return !subject || (p?.subjects ?? []).some((row) => `${row.code || ''} ${row.title || ''}`.toLowerCase().includes(subject));
    });
    if (filters.sort === 'program-asc' || filters.sort === 'program-desc') return result;

    return result.sort((a, b) => {
        if (filters.sort === 'name')
            return `${a.last_name || ''} ${a.first_name || ''}`.localeCompare(`${b.last_name || ''} ${b.first_name || ''}`) || a.id - b.id;
        const value = (student: T) => (student.progress?.updated_at ? Date.parse(student.progress.updated_at) : null);
        const av = value(a),
            bv = value(b);
        const aMissing = av === null || !Number.isFinite(av),
            bMissing = bv === null || !Number.isFinite(bv);
        if (aMissing || bMissing) return aMissing === bMissing ? a.id - b.id : aMissing ? 1 : -1;
        return (filters.sort.endsWith('asc') ? av! - bv! : bv! - av!) || a.id - b.id;
    });
}
