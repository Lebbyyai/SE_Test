import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

type ResponseRecord = {
    id: number;
    family_name: string;
    first_name: string;
    program: string;
    contact_number: string;
    email_address: string;
    id_number: string;
    evaluated_at: string;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PageProps = {
    responses: {
        data: ResponseRecord[];
        links: PaginationLink[];
        total: number;
    };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Comprehensive Exams', href: '/dean/responses' },
    { title: 'Application', href: '/dean/responses' },
];

export default function DeanResponsesIndex() {
    const { responses } = usePage<PageProps>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Comprehensive Exam Applications" />
            <div className="flex w-full flex-col px-7 pt-5 pb-5">
                <div className="bg-card text-card-foreground border-border w-full overflow-hidden rounded-lg border">
                    <div className="border-border flex items-center justify-between border-b p-5">
                        <div>
                            <h1 className="text-base font-semibold">Comprehensive Exam Applications</h1>
                            <p className="text-muted-foreground mt-1 text-xs">Applications reviewed by the Administrative Assistant.</p>
                        </div>
                        <span className="text-muted-foreground text-sm">{responses.total} total</span>
                    </div>
                    {responses.data.length === 0 ? (
                        <p className="text-muted-foreground p-6 text-center text-sm">No applications have been reviewed yet.</p>
                    ) : (
                        <>
                            <div className="w-full overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead className="bg-muted/50 text-muted-foreground">
                                        <tr>
                                            <th className="px-4 py-3 font-medium">Evaluated at</th>
                                            <th className="px-4 py-3 font-medium">Family name</th>
                                            <th className="px-4 py-3 font-medium">First name</th>
                                            <th className="px-4 py-3 font-medium">Program</th>
                                            <th className="px-4 py-3 font-medium">Contact number</th>
                                            <th className="px-4 py-3 font-medium">Email address</th>
                                            <th className="px-4 py-3 font-medium">ID number</th>
                                            <th className="px-4 py-3 font-medium">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {responses.data.map((response) => (
                                            <tr key={response.id} className="border-border border-t">
                                                <td className="px-4 py-3 whitespace-nowrap">{new Date(response.evaluated_at).toLocaleString()}</td>
                                                <td className="px-4 py-3">{response.family_name}</td>
                                                <td className="px-4 py-3">{response.first_name}</td>
                                                <td className="px-4 py-3">{response.program}</td>
                                                <td className="px-4 py-3">{response.contact_number}</td>
                                                <td className="px-4 py-3">{response.email_address}</td>
                                                <td className="px-4 py-3">{response.id_number}</td>
                                                <td className="px-4 py-3">
                                                    <span className="rounded-full border border-green-600 px-2 py-1 text-xs font-medium text-green-700">
                                                        Evaluated
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <nav aria-label="Pagination" className="flex flex-wrap justify-end gap-1 border-t p-4">
                                {responses.links.map((link, index) => (
                                    <Link
                                        key={`${link.label}-${index}`}
                                        href={link.url ?? '#'}
                                        preserveScroll
                                        aria-current={link.active ? 'page' : undefined}
                                        aria-disabled={!link.url}
                                        className={`rounded border px-3 py-1 text-sm ${link.active ? 'border-rose-500 bg-rose-500 text-white' : 'border-border'} ${!link.url ? 'pointer-events-none opacity-50' : ''}`}
                                    >
                                        <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                    </Link>
                                ))}
                            </nav>
                        </>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
