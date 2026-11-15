import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

type DocumentRow = {
    type: string;
    label: string;
    required: boolean;
    instructions: string | null;
    status: string;
    original_name: string | null;
    remarks: string | null;
    uploaded_at: string | null;
    reviewed_at: string | null;
    version: number;
    has_file: boolean;
    history: { action: string; actor: string; at: string; note: string | null }[];
};
type Props = {
    students: { id: number; name: string; program: string | null; school_id: string | null }[];
    selectedStudent: number | null;
    documents: DocumentRow[];
    isStudent: boolean;
    canReview: boolean;
    search: string;
};
const labels: Record<string, string> = {
    missing: 'Not submitted',
    pending: 'Awaiting review',
    verified: 'Verified',
    returned: 'Returned for correction',
};
function timestamp(value: string) {
    return new Date(value).toLocaleString('en-PH', { timeZone: 'Asia/Manila' });
}

function DocumentCard({ doc, student, isStudent, canReview }: { doc: DocumentRow; student: number; isStudent: boolean; canReview: boolean }) {
    const upload = useForm<{ file: File | null; version: number }>({ file: null, version: doc.version });
    const requirement = useForm({ required: doc.required, instructions: doc.instructions ?? '', version: doc.version });
    const review = useForm({ status: 'verified', remarks: '', version: doc.version });
    const errors = [...Object.values(upload.errors), ...Object.values(requirement.errors), ...Object.values(review.errors)];
    const base = `/comprehensive-documents/${student}/${doc.type}`;
    return (
        <section className="bg-card text-card-foreground rounded-lg border p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="font-semibold">{doc.label}</h2>
                <span className="rounded-md border px-2 py-1 text-sm">{labels[doc.status] ?? doc.status}</span>
            </div>
            <p className="text-muted-foreground mt-2 text-sm">
                {doc.required
                    ? 'Requested for this student. Verification is required before applying.'
                    : 'Not currently required. Upload only if instructed by the school.'}
            </p>
            {doc.instructions && <p className="mt-2 text-sm whitespace-pre-wrap">School instructions: {doc.instructions}</p>}
            {doc.has_file && (
                <div className="mt-3 space-y-1 text-sm">
                    <a className="break-all underline" href={`${base}/download`}>
                        Download {doc.original_name}
                    </a>
                    {doc.uploaded_at && <p className="text-muted-foreground">Uploaded: {timestamp(doc.uploaded_at)} (PH)</p>}
                    {doc.reviewed_at && <p className="text-muted-foreground">Reviewed: {timestamp(doc.reviewed_at)} (PH)</p>}
                </div>
            )}
            {doc.remarks && <p className="mt-3 rounded-md border p-3 text-sm whitespace-pre-wrap">Review feedback: {doc.remarks}</p>}
            {isStudent && (
                <form
                    className="mt-4 space-y-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        upload.post(`${base}/upload`, { forceFormData: true, preserveScroll: true });
                    }}
                >
                    <label className="block text-sm font-medium" htmlFor={`file-${doc.type}`}>
                        {doc.has_file ? 'Replace document' : 'Add document'}
                    </label>
                    <Input
                        id={`file-${doc.type}`}
                        type="file"
                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                        onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)}
                    />
                    <p className="text-muted-foreground text-xs">PDF, Word, JPEG or PNG. Maximum 10 MB. Replacing a file requires a new review.</p>
                    <Button disabled={!upload.data.file || upload.processing} type="submit">
                        {upload.processing ? 'Uploading...' : 'Upload document'}
                    </Button>
                    {upload.progress && <p role="status">Uploading {upload.progress.percentage}%</p>}
                </form>
            )}
            {canReview && (
                <div className="mt-4 space-y-4">
                    <details className="rounded-md border p-3">
                        <summary className="cursor-pointer text-sm font-medium">Set document requirement</summary>
                        <form
                            className="mt-3 space-y-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                requirement.patch(`${base}/requirement`, { preserveScroll: true });
                            }}
                        >
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={requirement.data.required}
                                    onChange={(e) => requirement.setData('required', e.target.checked)}
                                />
                                Required for this student
                            </label>
                            <label className="block text-sm">
                                Instructions / reason
                                <textarea
                                    required
                                    minLength={3}
                                    maxLength={1500}
                                    className="bg-background text-foreground mt-1 w-full rounded-md border p-2"
                                    value={requirement.data.instructions}
                                    onChange={(e) => requirement.setData('instructions', e.target.value)}
                                    placeholder="Specify the document needed, or explain why it is not required."
                                />
                            </label>
                            <Button disabled={requirement.processing} type="submit">
                                Save requirement
                            </Button>
                        </form>
                    </details>
                    {doc.has_file && (
                        <form
                            className="space-y-3 rounded-md border p-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                review.patch(`${base}/review`, { preserveScroll: true });
                            }}
                        >
                            <label className="block text-sm">
                                Document review
                                <select
                                    className="bg-background text-foreground mt-1 w-full rounded-md border p-2"
                                    value={review.data.status}
                                    onChange={(e) => review.setData('status', e.target.value)}
                                >
                                    <option value="verified">Verified</option>
                                    <option value="returned">Return for correction</option>
                                </select>
                            </label>
                            <label className="block text-sm">
                                Feedback {review.data.status === 'returned' ? '(required)' : '(optional)'}
                                <textarea
                                    required={review.data.status === 'returned'}
                                    minLength={3}
                                    maxLength={1500}
                                    className="bg-background text-foreground mt-1 w-full rounded-md border p-2"
                                    value={review.data.remarks}
                                    onChange={(e) => review.setData('remarks', e.target.value)}
                                />
                            </label>
                            <Button disabled={review.processing} type="submit">
                                Save review
                            </Button>
                        </form>
                    )}
                </div>
            )}
            {errors.length > 0 && (
                <div role="alert" className="mt-3 space-y-1 text-sm text-red-600 dark:text-red-400">
                    {errors.map((error, i) => (
                        <p key={i}>{error}</p>
                    ))}
                </div>
            )}
            {doc.history.length > 0 && (
                <details className="mt-4 text-sm">
                    <summary className="cursor-pointer">Document history ({doc.history.length})</summary>
                    <ol className="mt-2 space-y-3">
                        {[...doc.history].reverse().map((event, i) => (
                            <li className="border-l pl-3" key={i}>
                                <p className="font-medium">{event.action}</p>
                                <p className="text-muted-foreground text-xs">
                                    {event.actor} · {timestamp(event.at)} (PH)
                                </p>
                                {event.note && <p className="break-words whitespace-pre-wrap">{event.note}</p>}
                            </li>
                        ))}
                    </ol>
                </details>
            )}
        </section>
    );
}

export default function ComprehensiveDocuments({ students, selectedStudent, documents, isStudent, canReview, search }: Props) {
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    const [query, setQuery] = useState(search);
    const selected = students.find((student) => student.id === selectedStudent);
    const pending = documents.filter((doc) => doc.required && doc.status !== 'verified');
    return (
        <AppLayout breadcrumbs={[{ title: 'Comprehensive Exam Documents', href: '/comprehensive-documents' }]}>
            <Head title="Comprehensive Exam Documents" />
            <main className="space-y-5 p-5 sm:p-7">
                <h1 className="text-2xl font-semibold">Comprehensive Exam Documents</h1>
                {flash?.success && (
                    <p role="status" className="rounded-md border border-green-600 p-3 text-sm text-green-700 dark:text-green-400">
                        {flash.success}
                    </p>
                )}
                <p className="text-muted-foreground max-w-3xl text-sm">
                    Submit supporting documents requested by the school and track their review. Local document verification does not replace official
                    Registrar clearance, complete grades, or tuition clearance. Exam receipts are submitted separately after application approval.
                </p>
                <a className="inline-block text-sm underline" href={isStudent ? '/comprehensive-exam' : '/dashboard'}>
                    {isStudent ? 'Back to comprehensive exam' : 'Back to dashboard'}
                </a>
                {!isStudent && (
                    <div className="flex flex-wrap items-end gap-3">
                        <form
                            className="flex gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.get('/comprehensive-documents', { search: query });
                            }}
                        >
                            <Input
                                aria-label="Search students"
                                placeholder="Search name or school ID"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                            />
                            <Button type="submit" variant="outline">
                                Search
                            </Button>
                        </form>
                        <label className="text-sm">
                            Student
                            <select
                                className="bg-background text-foreground ml-2 rounded-md border p-2"
                                value={selectedStudent ?? ''}
                                onChange={(e) => router.get('/comprehensive-documents', { student: e.target.value, search })}
                            >
                                {students.length === 0 && <option value="">No students found</option>}
                                {students.map((student) => (
                                    <option key={student.id} value={student.id}>
                                        {student.name} · {student.school_id ?? 'No school ID'}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                )}
                {selected ? (
                    <>
                        <div className="rounded-md border p-4">
                            <p className="font-semibold">{selected.name}</p>
                            <p className="text-muted-foreground text-sm">
                                {selected.program} · {selected.school_id}
                            </p>
                            <p className="mt-2 text-sm">
                                {pending.length
                                    ? `${pending.length} requested document(s) awaiting submission or verification.`
                                    : 'No outstanding locally requested documents. Official school eligibility checks still apply.'}
                            </p>
                        </div>
                        <div className="grid gap-4 xl:grid-cols-2">
                            {documents.map((doc) => (
                                <DocumentCard
                                    key={`${selectedStudent}-${doc.type}-${doc.version}`}
                                    doc={doc}
                                    student={selectedStudent!}
                                    isStudent={isStudent}
                                    canReview={canReview}
                                />
                            ))}
                        </div>
                    </>
                ) : (
                    <p className="text-muted-foreground rounded-md border p-6">No students found in your permitted programs.</p>
                )}
            </main>
        </AppLayout>
    );
}
