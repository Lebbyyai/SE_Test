import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router } from '@inertiajs/react';
import { useState } from 'react';

type Account = { id: number; name: string; email: string; role: string };
const roles = [
    { value: 'Student', label: 'Student' },
    { value: 'Adviser', label: 'Adviser' },
    { value: 'Coordinator', label: 'Academic Coordinator' },
    { value: 'Administrative Assistant', label: 'Administrative Assistant (AA)' },
    { value: 'Dean', label: 'Dean' },
    { value: 'Finance', label: 'Finance' },
    { value: 'Registrar', label: 'Registrar' },
    { value: 'Proctor', label: 'Proctor' },
];

export default function UserRoleManager({ accounts }: { accounts: Account[] }) {
    const [search, setSearch] = useState('');
    const [drafts, setDrafts] = useState<Record<number, string>>({});
    const [saving, setSaving] = useState<number | null>(null);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const visible = accounts.filter((account) => `${account.name} ${account.email} ${account.role}`.toLowerCase().includes(search.toLowerCase()));

    async function save(account: Account) {
        setSaving(account.id);
        setError('');
        setMessage('');
        try {
            const response = await fetch(`/admin/users/${account.id}/role`, {
                method: 'PATCH',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ role: drafts[account.id] ?? account.role }),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.errors?.role?.[0] ?? data.message ?? 'Could not update the role.');
            router.reload({
                only: ['allUsers', 'stats', 'coordinators'],
                onSuccess: () => {
                    setDrafts({});
                    setMessage(`Role updated for ${account.name}.`);
                },
                onError: () => setError('Role saved, but the account list could not refresh. Reload this page.'),
                onFinish: () => setSaving(null),
            });
        } catch (failure) {
            setError(failure instanceof Error ? failure.message : 'Could not update the role. Please try again.');
            setSaving(null);
        }
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Assign Account Roles</CardTitle>
                <p className="text-muted-foreground text-sm">
                    Choose an existing account, select its role, and save. Academic Coordinator is stored as Coordinator.
                </p>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="space-y-2">
                    <Label htmlFor="role-account-search">Find an account</Label>
                    <Input
                        id="role-account-search"
                        placeholder="Search name, email, or role…"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </div>
                {message && (
                    <p role="status" className="text-sm text-green-700 dark:text-green-400">
                        {message}
                    </p>
                )}
                {error && (
                    <p role="alert" className="text-sm text-red-600 dark:text-red-400">
                        {error}
                    </p>
                )}
                <div className="max-h-[520px] overflow-auto rounded-lg border">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-muted">
                            <tr>
                                <th className="p-3">Account</th>
                                <th className="p-3">Role</th>
                                <th className="p-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            {visible.map((account) => {
                                const protectedAccount = ['Super Admin', 'SuperAdmin'].includes(account.role);
                                const selectedRole = drafts[account.id] ?? account.role;
                                return (
                                    <tr key={account.id} className="border-t">
                                        <td className="p-3">
                                            <p className="font-medium">{account.name}</p>
                                            <p className="text-muted-foreground">{account.email}</p>
                                        </td>
                                        <td className="p-3">
                                            {protectedAccount ? (
                                                'Super Admin (protected)'
                                            ) : (
                                                <select
                                                    aria-label={`Role for ${account.name} (${account.email})`}
                                                    className="bg-background min-w-[220px] rounded-md border p-2"
                                                    value={selectedRole}
                                                    disabled={saving !== null}
                                                    onChange={(event) => setDrafts((previous) => ({ ...previous, [account.id]: event.target.value }))}
                                                >
                                                    {!roles.some((role) => role.value === selectedRole) && (
                                                        <option value={selectedRole} disabled>
                                                            {selectedRole || 'Select a role'}
                                                        </option>
                                                    )}
                                                    {roles.map((role) => (
                                                        <option key={role.value} value={role.value}>
                                                            {role.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            )}
                                        </td>
                                        <td className="p-3">
                                            {!protectedAccount && (
                                                <Button
                                                    size="sm"
                                                    disabled={saving !== null || selectedRole === account.role}
                                                    onClick={() => save(account)}
                                                >
                                                    {saving === account.id ? 'Saving…' : 'Save role'}
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                            {visible.length === 0 && (
                                <tr>
                                    <td colSpan={3} className="text-muted-foreground p-6 text-center">
                                        No accounts found.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <p className="text-muted-foreground text-xs">
                    Saving replaces the account’s previous role. Super Admin accounts cannot be changed here. New users must register before appearing
                    in this list.
                </p>
            </CardContent>
        </Card>
    );
}
