import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { csrfHeaders } from '@/lib/csrf';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

type Verification = {
    id: number;
    status: string;
    remarks: string | null;
    batch_id: number | null;
    defense_request: { thesis_title: string; first_name: string; last_name: string } | null;
};
type Batch = { id: number; name: string; status: string };

export default function PaymentVerifications({ verifications, batches }: { verifications: Verification[]; batches: Batch[] }) {
    const [selected, setSelected] = useState<number[]>([]);
    const [drafts, setDrafts] = useState<Record<number, { status?: string; remarks?: string }>>({});
    const [name, setName] = useState('');
    const [busy, setBusy] = useState(false);

    async function save(url: string, data: Record<string, unknown>) {
        setBusy(true);
        try {
            const response = await fetch(url, { method: 'POST', headers: csrfHeaders(true), body: JSON.stringify(data) });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || result.error || 'Unable to save.');
            toast.success('Payment verification updated.');
            setSelected([]);
            setDrafts({});
            setName('');
            router.reload({ only: ['verifications', 'batches'] });
        } catch (error) {
            toast.error(error instanceof Error ? error.message : 'Unable to connect. Please try again.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <AppLayout breadcrumbs={[{ title: 'Payment Verifications', href: '/aa/payment-verifications' }]}>
            <Head title="Payment Verifications" />
            <div className="space-y-6 p-6">
                <div>
                    <h1 className="text-2xl font-semibold">Payment Verifications</h1>
                    <p className="text-muted-foreground">Review defense receipts assigned to you or awaiting assignment.</p>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        disabled={busy || !selected.length}
                        onClick={() => save('/aa/payment-verifications/bulk-update', { verification_ids: selected, status: 'verified' })}
                    >
                        Verify selected
                    </Button>
                    <Button
                        variant="outline"
                        disabled={busy || !selected.length}
                        onClick={() => save('/aa/payment-verifications/bulk-update', { verification_ids: selected, status: 'rejected' })}
                    >
                        Reject selected
                    </Button>
                    <Input
                        className="max-w-xs"
                        aria-label="Batch name"
                        placeholder="Batch name"
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        maxLength={120}
                    />
                    <Button
                        variant="outline"
                        disabled={busy || !selected.length || !name.trim()}
                        onClick={() => save('/aa/payment-verifications/batch', { verification_ids: selected, name: name.trim() })}
                    >
                        Create batch
                    </Button>
                </div>
                <div className="bg-card text-card-foreground overflow-x-auto rounded-lg border">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted border-b">
                            <tr>
                                <th className="p-3">Select</th>
                                <th className="p-3">Defense / student</th>
                                <th className="p-3">Status</th>
                                <th className="p-3">Remarks</th>
                                <th className="p-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {verifications.map((row) => (
                                <tr key={row.id} className="border-b last:border-0">
                                    <td className="p-3">
                                        <input
                                            type="checkbox"
                                            aria-label={`Select verification ${row.id}`}
                                            checked={selected.includes(row.id)}
                                            disabled={busy}
                                            onChange={(event) =>
                                                setSelected((current) =>
                                                    event.target.checked ? [...current, row.id] : current.filter((id) => id !== row.id),
                                                )
                                            }
                                        />
                                    </td>
                                    <td className="p-3">
                                        <p className="font-medium">{row.defense_request?.thesis_title ?? 'Request unavailable'}</p>
                                        <p className="text-muted-foreground">
                                            {row.defense_request?.first_name} {row.defense_request?.last_name}
                                        </p>
                                        {row.batch_id && <p className="text-muted-foreground">Batch #{row.batch_id}</p>}
                                    </td>
                                    <td className="p-3">
                                        <select
                                            className="bg-background text-foreground rounded border p-2"
                                            aria-label={`Status for verification ${row.id}`}
                                            value={drafts[row.id]?.status ?? row.status}
                                            onChange={(event) =>
                                                setDrafts((current) => ({ ...current, [row.id]: { ...current[row.id], status: event.target.value } }))
                                            }
                                        >
                                            {['pending', 'verified', 'rejected'].map((status) => (
                                                <option key={status} value={status}>
                                                    {status}
                                                </option>
                                            ))}
                                        </select>
                                    </td>
                                    <td className="min-w-52 p-3">
                                        <Input
                                            aria-label={`Remarks for verification ${row.id}`}
                                            value={drafts[row.id]?.remarks ?? row.remarks ?? ''}
                                            maxLength={5000}
                                            onChange={(event) =>
                                                setDrafts((current) => ({
                                                    ...current,
                                                    [row.id]: { ...current[row.id], remarks: event.target.value },
                                                }))
                                            }
                                        />
                                    </td>
                                    <td className="p-3">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={busy}
                                            onClick={() =>
                                                save(`/aa/payment-verifications/${row.id}/status`, {
                                                    status: drafts[row.id]?.status ?? row.status,
                                                    remarks: drafts[row.id]?.remarks ?? row.remarks,
                                                })
                                            }
                                        >
                                            Save
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                            {!verifications.length && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground p-8 text-center">
                                        No payment verifications available.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <section className="bg-card text-card-foreground space-y-3 rounded-lg border p-5">
                    <h2 className="text-lg font-semibold">Payment batches</h2>
                    {batches.map((batch) => (
                        <div key={batch.id} className="flex items-center justify-between gap-4 border-b py-2 last:border-0">
                            <span>{batch.name}</span>
                            <a className="underline underline-offset-4" href={`/aa/payment-batch/${batch.id}/export`}>
                                Export CSV
                            </a>
                        </div>
                    ))}
                    {!batches.length && <p className="text-muted-foreground">No batches created yet.</p>}
                </section>
            </div>
        </AppLayout>
    );
}
