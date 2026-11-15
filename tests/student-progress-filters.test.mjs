import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';
const source = readFileSync(new URL('../resources/js/utils/student-progress-filters.ts', import.meta.url), 'utf8');
const js = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText;
const { filterStudentProgress: filter, defaultProgressFilters: defaults, filterStudentsByProgram, studentProgramOptions } =
    await import('data:text/javascript;base64,' + Buffer.from(js).toString('base64'));
const students = [
    { id: 1, last_name: 'Zulu', progress: { stage: 'Proposal', enrollment_year: 2023, completion_year: null, semester: '1st 2026', subjects: [{ code: 'RES101', title: 'Research Methods' }], updated_at: '2026-10-30T09:00:00Z' } },
    { id: 2, last_name: 'Alpha', progress: { stage: 'Final', enrollment_year: 2020, completion_year: 2026, semester: '2nd 2025', subjects: [{ code: 'THS', title: 'Thesis' }], updated_at: '2026-10-31T09:00:00Z' } },
    { id: 3, last_name: 'Missing', progress: { stage: null, enrollment_year: null, completion_year: null } },
];
const ids = (filters = {}) => filter(students, { ...defaults, ...filters }).map((s) => s.id);
test('sorts progress updates in both directions with missing values last', () => {
    assert.deepEqual(ids(), [2, 1, 3]);
    assert.deepEqual(ids({ sort: 'updated-asc' }), [1, 2, 3]);

    assert.deepEqual(ids({ sort: 'name' }), [2, 3, 1]);
    assert.deepEqual(students.map((s) => s.id), [1, 2, 3]);
});
test('combines stage, completion year, semester and case-insensitive subject name or code', () => {
    assert.deepEqual(ids({ stage: 'Proposal', completion: 'not-verified', semester: '1st 2026', subject: ' res101 ' }), [1]);
    assert.deepEqual(ids({ subject: 'research methods' }), [1]);
    assert.deepEqual(ids({ stage: 'Final', completion: '2026' }), [2]);
    assert.deepEqual(ids({ stage: 'not-started' }), [3]);
    assert.deepEqual(ids({ stage: 'Final', subject: 'RES101' }), []);
});

test('filters by program name and code and sorts both directions with missing programs last', () => {
    const records = [
        { id: 1, program: 'Master of Arts in Education' },
        { id: 2, program: 'Doctor in Business Management' },
        { id: 3, program: null },
        { id: 4, program: ' MIT ' },
        { id: 5, program: 'Master in Information Technology' },
    ];
    const { choices, keyForStudent } = studentProgramOptions(records, [{ code: 'MIT', name: 'Master in Information Technology' }]);
    const mit = choices.find((choice) => choice.label === 'MIT — Master in Information Technology');
    assert.ok(mit);
    assert.equal(choices.length, 3);
    assert.deepEqual(filterStudentsByProgram(records, mit.value, '', keyForStudent).map((s) => s.id), [4, 5]);
    assert.deepEqual(filterStudentsByProgram(records, '', 'program-asc', keyForStudent).map((s) => s.id), [2, 4, 5, 1, 3]);
    assert.deepEqual(filterStudentsByProgram(records, '', 'program-desc', keyForStudent).map((s) => s.id), [1, 4, 5, 2, 3]);
    assert.deepEqual(records.map((s) => s.id), [1, 2, 3, 4, 5]);
});
