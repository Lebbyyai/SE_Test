import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Printer, RefreshCw } from 'lucide-react';
import React, { useCallback, useEffect, useRef, useState } from 'react';

interface SemesterOption {
    id: string;
    label: string;
    selected: boolean;
}
interface RecordRow {
    code: string | null;
    title: string | null;
    units: number | string | null;
    course_type: string | null;
    prelim: string | null;
    midterm: string | null;
    finals: string | null;
    average: string | number | null;
    average_var: string | number | null;
    units_earned: number | string | null;
    section: string | null;
    current_balance: number | string | null;
    rating_show: string | number | null;
}
interface ParsedData {
    student: { student_number: string | null; name: string | null; program: string | null };
    semesters: SemesterOption[];
    current_semester_id: string | null;
    records: RecordRow[];
    gwa: string | null;
}

const AcademicRecordsDashboard: React.FC = () => {
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [data, setData] = useState<ParsedData | null>(null);

    const [semesterId, setSemesterId] = useState<string | undefined>(undefined);

    // For select width
    const selectRef = useRef<HTMLDivElement>(null);
    const [selectWidth, setSelectWidth] = useState<number>(180);

    const load = useCallback(() => {
        setLoading(true);
        setError(null);
        setNotice(null);
        setData(null);
        const url = '/legacy/academic-records' + (semesterId ? `?semester_id=${semesterId}` : '');
        fetch(url, { headers: { Accept: 'application/json' } })
            .then(async (r) => {
                const json = await r.json().catch(() => ({}));
                if (!r.ok) throw new Error(json.error || `Unable to load academic records (HTTP ${r.status}).`);
                if (json.available === false) {
                    setNotice(json.message);
                    return;
                }
                setData(json.data as ParsedData);
            })
            .catch((e) => setError(e.message))
            .finally(() => setLoading(false));
    }, [semesterId]);

    useEffect(() => {
        load();
    }, [load]);

    // Print handler for the table
    const handlePrint = () => {
        const table = document.getElementById('academic-records-table');
        if (!table) return;
        const printWindow = window.open('', '', 'width=900,height=600');
        if (!printWindow) return;
        printWindow.document.write('<html><head><title>Print Academic Records</title>');
        printWindow.document.write(
            '<style>table{width:100%;border-collapse:collapse;}th,td{border:1px solid #ccc;padding:6px;text-align:center;}th{text-align:center;}body{font-family:sans-serif;}</style>',
        );
        printWindow.document.write('</head><body>');
        printWindow.document.write(table.outerHTML);
        printWindow.document.write('</body></html>');
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
    };

    // Dynamically set select width based on content
    useEffect(() => {
        if (selectRef.current && data?.semesters) {
            // Find the longest label
            const longest = data.semesters.reduce((a, b) => (a.label.length > b.label.length ? a : b), { label: '' } as SemesterOption);
            // Create a temporary span to measure width
            const span = document.createElement('span');
            span.style.visibility = 'hidden';
            span.style.position = 'absolute';
            span.style.fontSize = '16px';
            span.style.fontWeight = '400';
            span.style.padding = '8px';
            span.innerText = longest.label || 'Select semester';
            document.body.appendChild(span);
            setSelectWidth(span.offsetWidth + 40); // Add padding for icon
            document.body.removeChild(span);
        }
    }, [data?.semesters]);

    return (
        <div className="dark:bg-background flex h-full flex-1 flex-col gap-4 overflow-auto bg-white">
            {/* Skeleton Loader */}
            {loading ? (
                <div className="m-0 flex min-h-[70vh] w-full flex-col gap-4 bg-zinc-100 p-0 dark:bg-zinc-900">
                    {/* Top short row */}
                    <Skeleton className="mx-8 mt-8 h-6 w-1/6 rounded bg-zinc-300 dark:bg-zinc-800" />
                    {/* Main rows */}
                    <Skeleton className="mx-8 h-12 w-3/4 rounded bg-zinc-300 dark:bg-zinc-800" />
                    <Skeleton className="mx-8 h-12 w-2/3 rounded bg-zinc-300 dark:bg-zinc-800" />
                    {/* Big rectangle for dashboard body */}
                    <Skeleton className="mt-4 h-[500px] w-full rounded bg-zinc-300 dark:bg-zinc-800" />
                </div>
            ) : (
                <>
                    {/* Top Buttons */}
                    <div className="mb-4 flex items-center justify-between">
                        <div className="flex gap-2">
                            <button
                                onClick={load}
                                className="flex items-center gap-2 rounded border px-3 py-2 disabled:opacity-50"
                                disabled={loading}
                                title="Reload"
                            >
                                <RefreshCw size={18} className={loading ? 'animate-spin' : ''} />
                            </button>
                            <button
                                onClick={handlePrint}
                                className="flex items-center gap-2 rounded border px-3 py-2"
                                title="Print Table"
                                disabled={loading || !data}
                            >
                                <Printer size={18} />
                            </button>
                        </div>
                    </div>

                    {/* Error */}
                    {error && (
                        <Card className="mb-4 rounded-xl border shadow-none">
                            <CardContent className="bg-red-50 py-4 text-red-700 dark:bg-red-950/30 dark:text-red-300">{error}</CardContent>
                        </Card>
                    )}

                    {notice && (
                        <Card className="mb-4 rounded-xl border shadow-none">
                            <CardHeader>
                                <CardTitle>Academic records unavailable</CardTitle>
                            </CardHeader>
                            <CardContent role="status" className="text-muted-foreground">
                                {notice}
                            </CardContent>
                        </Card>
                    )}

                    {!loading && !error && data && (
                        <>
                            <Card className="mb-4 w-full rounded-xl border shadow-none">
                                <CardHeader>
                                    <CardTitle>Student Information & Semester</CardTitle>
                                </CardHeader>
                                <CardContent className="grid w-full grid-cols-1 gap-4 md:grid-cols-3">
                                    <div>
                                        <h2 className="mb-2 text-sm font-medium text-gray-500">Student</h2>
                                        <p className="text-sm">
                                            <span className="font-semibold">School ID:</span> {data.student.student_number || '—'}
                                        </p>
                                        <p className="text-sm">
                                            <span className="font-semibold">Name:</span> {data.student.name || '—'}
                                        </p>
                                        <p className="text-sm">
                                            <span className="font-semibold">Program:</span> {data.student.program || '—'}
                                        </p>
                                        {data.gwa && (
                                            <p className="mt-2 text-sm">
                                                <span className="font-semibold">GWA:</span> {data.gwa}
                                            </p>
                                        )}
                                    </div>
                                    <div className="md:col-span-2">
                                        <h2 className="mb-2 text-sm font-medium text-gray-500">Semester</h2>
                                        <div ref={selectRef} style={{ width: selectWidth }}>
                                            <Select value={semesterId ?? data.current_semester_id ?? ''} onValueChange={(val) => setSemesterId(val)}>
                                                <SelectTrigger className="w-full rounded border bg-gray-100" style={{ width: selectWidth }}>
                                                    <SelectValue placeholder="Select semester" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {data.semesters.map((s) => (
                                                        <SelectItem key={s.id} value={s.id}>
                                                            {s.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <p className="mt-2 text-xs text-gray-500">Select a semester to reload real grades.</p>
                                    </div>
                                </CardContent>
                            </Card>

                            <Card className="w-full rounded-xl border shadow-none">
                                <CardHeader>
                                    <CardTitle>Courses</CardTitle>
                                </CardHeader>
                                <CardContent className="w-full">
                                    <div className="mb-3 flex w-full items-center justify-between">
                                        <span className="text-lg font-semibold">Courses</span>
                                        <span className="text-xs text-gray-500">{data.records.length} rows</span>
                                    </div>
                                    {data.records.length === 0 ? (
                                        <div className="text-sm text-gray-500">No records returned for this semester.</div>
                                    ) : (
                                        <div className="w-full overflow-x-auto">
                                            <Table id="academic-records-table">
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead>Code</TableHead>
                                                        <TableHead>Title</TableHead>
                                                        <TableHead>Units</TableHead>
                                                        <TableHead>Type</TableHead>
                                                        <TableHead>Prelim</TableHead>
                                                        <TableHead>Midterm</TableHead>
                                                        <TableHead>Finals</TableHead>
                                                        <TableHead>Average</TableHead>
                                                        <TableHead>Units Earned</TableHead>
                                                        <TableHead>Section</TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {data.records.map((r, i) => {
                                                        const avg = r.average || r.average_var || '';
                                                        return (
                                                            <TableRow key={i} className="hover:bg-gray-50">
                                                                <TableCell>{r.code}</TableCell>
                                                                <TableCell>{r.title}</TableCell>
                                                                <TableCell className="text-center">{r.units}</TableCell>
                                                                <TableCell className="text-center">{r.course_type}</TableCell>
                                                                <TableCell className="text-center">{r.prelim}</TableCell>
                                                                <TableCell className="text-center">{r.midterm}</TableCell>
                                                                <TableCell className="text-center">{r.finals}</TableCell>
                                                                <TableCell className="text-center">
                                                                    {avg}
                                                                    {avg && r.units_earned !== null ? ` / ${r.units_earned}` : ''}
                                                                </TableCell>
                                                                <TableCell className="text-center">{r.units_earned}</TableCell>
                                                                <TableCell className="text-center">{r.section}</TableCell>
                                                            </TableRow>
                                                        );
                                                    })}
                                                </TableBody>
                                            </Table>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </>
                    )}
                </>
            )}
        </div>
    );
};

export default AcademicRecordsDashboard;
