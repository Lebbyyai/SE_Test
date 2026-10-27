import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { csrfHeaders } from '@/lib/csrf';
import { Head } from '@inertiajs/react';
import React, { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

type BreadcrumbItem = { title: string; href: string };
type Template = { id: number; name: string; code: string; version: number };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Document Templates', href: '/settings/documents' }];

const templateOptions = [
    { label: 'Endorsement Form (Proposal)', value: 'Endorsement Form (Proposal)' },
    { label: 'Endorsement Form (Prefinal)', value: 'Endorsement Form (Prefinal)' },
    { label: 'Endorsement (Final)', value: 'Endorsement (Final)' },
];

export default function DocumentTemplatesIndex() {
    const [list, setList] = useState<Template[]>([]);
    const fileInput = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [form, setForm] = useState({ name: '' });
    const [busy, setBusy] = useState(false);

    async function load() {
        const r = await fetch('/api/document-templates', {
            headers: csrfHeaders(),
            credentials: 'include',
        });
        if (r.ok) setList(await r.json());
    }

    useEffect(() => {
        load();
    }, []);

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        if (!file) return;
        setBusy(true);
        const fd = new FormData();
        fd.append('name', form.name);
        fd.append('file', file);
        const r = await fetch('/api/document-templates', {
            method: 'POST',
            body: fd,
            headers: csrfHeaders(),
            credentials: 'include',
        });
        setBusy(false);
        if (r.ok) {
            setForm({ name: '' });
            setFile(null);
            if (fileInput.current) fileInput.current.value = '';
            load();
            toast.success('Template uploaded.');
        } else {
            const data = await r.json().catch(() => null);
            toast.error(data?.message ?? 'Unable to upload template. Please try again.');
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Document Templates" />
            <SettingsLayout>
                <div className="space-y-10">
                    <div className="space-y-6">
                        <HeadingSmall title="Upload template" description="Add a new PDF template to map fields & signatures." />
                        <form onSubmit={submit} className="mt-2 grid max-w-sm gap-3">
                            <Select value={form.name} onValueChange={(value) => setForm((f) => ({ ...f, name: value }))}>
                                <SelectTrigger className="rounded border px-2 py-1">
                                    <SelectValue placeholder="Select template name" />
                                </SelectTrigger>
                                <SelectContent>
                                    {templateOptions.map((opt) => (
                                        <SelectItem key={opt.value} value={opt.value}>
                                            {opt.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <div className="space-y-2">
                                <input
                                    ref={fileInput}
                                    type="file"
                                    accept=".pdf,application/pdf"
                                    aria-label="PDF template file"
                                    className="hidden"
                                    disabled={busy}
                                    onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="w-full"
                                    disabled={busy}
                                    aria-describedby="template-file-name template-file-help"
                                    onClick={() => fileInput.current?.click()}
                                >
                                    Add File
                                </Button>
                                <p id="template-file-name" role="status" className="text-muted-foreground text-sm break-all">
                                    {file?.name ?? 'No file chosen'}
                                </p>
                                <p id="template-file-help" className="text-muted-foreground text-xs">
                                    PDF only, up to 10 MB.
                                </p>
                            </div>
                            <Button disabled={!file || !form.name || busy} type="submit">
                                {busy ? 'Uploading...' : 'Upload'}
                            </Button>
                        </form>
                    </div>

                    <div className="space-y-4">
                        <HeadingSmall title="Existing templates" description="Manage and edit mapped fields." />
                        <div className="overflow-x-auto">
                            <table className="w-full border text-sm">
                                <thead className="bg-neutral-50">
                                    <tr>
                                        <th className="p-2 text-left">Name</th>
                                        <th className="p-2">Code</th>
                                        <th className="p-2">Version</th>
                                        <th className="p-2">Edit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {list.map((t) => (
                                        <tr key={t.id} className="border-t">
                                            <td className="p-2">{t.name}</td>
                                            <td className="p-2">{t.code}</td>
                                            <td className="p-2 text-center">{t.version}</td>
                                            <td className="p-2">
                                                <a href={`/settings/documents/${t.id}/edit`} target="_blank" rel="noopener noreferrer">
                                                    Fields
                                                </a>
                                            </td>
                                        </tr>
                                    ))}
                                    {!list.length && (
                                        <tr>
                                            <td className="p-4" colSpan={4}>
                                                No templates.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
