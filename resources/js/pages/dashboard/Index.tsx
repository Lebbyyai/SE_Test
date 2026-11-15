import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import React from 'react';
import ProctorDashboard from './dashboard-layouts/proctor-dashboard';
import RegistrarDashboard from './dashboard-layouts/registrar-dashboard';

import AdviserDashboard from './dashboard-layouts/adviser-dashboard';
import AssistantDashboard from './dashboard-layouts/assistant-dashboard';
import CoordinatorDashboard from './dashboard-layouts/coordinator-dashboard';
import DeanDashboard from './dashboard-layouts/dean-dashboard';
import StudentDashboard from './dashboard-layouts/student-dashboard';
import SuperAdminDashboard from './dashboard-layouts/super-admin-dashboard';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

type PageProps = {
    auth: {
        user: {
            id: number;
            name: string;
            role: string;
            effective_role?: string;
        };
    };
};

export default function DashboardIndex() {
    const page = usePage<PageProps>().props;
    const {
        auth: { user },
    } = page;

    let ComponentToRender: React.ComponentType<Partial<React.ComponentProps<typeof StudentDashboard>>>;
    const activeRole = user?.effective_role || user.role;

    switch (activeRole) {
        case 'Registrar':
            ComponentToRender = RegistrarDashboard;
            break;
        case 'Proctor':
            ComponentToRender = ProctorDashboard;
            break;
        case 'Student':
            ComponentToRender = StudentDashboard;
            break;
        case 'Administrative Assistant':
        case 'Assistant':
            ComponentToRender = AssistantDashboard;
            break;
        case 'Coordinator':
            ComponentToRender = CoordinatorDashboard;
            break;
        case 'Dean':
            ComponentToRender = DeanDashboard;
            break;
        case 'Adviser':
            ComponentToRender = AdviserDashboard;
            break;
        case 'Super Admin':
        case 'SuperAdmin':
            ComponentToRender = SuperAdminDashboard;
            break;
        default:
            ComponentToRender = () => (
                <div className="p-6 text-amber-600">
                    <p className="font-medium">Limited Access</p>
                    <p className="text-sm text-gray-500">Your role "{activeRole}" does not yet have a dedicated dashboard.</p>
                </div>
            );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <ComponentToRender {...page} />
        </AppLayout>
    );
}
