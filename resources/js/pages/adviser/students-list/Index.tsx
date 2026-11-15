import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import type { ProgramCatalogEntry } from '@/utils/student-progress-filters';
import { Head } from '@inertiajs/react';
import ShowStudents from './show-students';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'My Students', href: '/adviser/students-list' },
];

export default function AdviserStudentsListPage({ programCatalog = [] }: { programCatalog?: ProgramCatalogEntry[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Students" />
            <ShowStudents programCatalog={programCatalog} />
        </AppLayout>
    );
}
