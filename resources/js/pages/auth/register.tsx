import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AuthLayout from '@/layouts/auth-layout';

type RegisterForm = {
    first_name: string;
    middle_name: string;
    last_name: string;
    email: string;
    password: string;
    password_confirmation: string;
    role: string;
    program: string;
    school_id: string;
};

type ProgramOption = { name: string; code: string; category: string };

export default function Register({ programs = [] }: { programs?: ProgramOption[] }) {
    const DOCTORATE_PROGRAMS = programs.filter((program) => program.category === 'Doctorate').map((program) => program.name);
    const MASTER_PROGRAMS = programs.filter((program) => program.category === 'Masters').map((program) => program.name);
    const { data, setData, post, processing, errors, reset } = useForm<Required<RegisterForm>>({
        first_name: '',
        middle_name: '',
        last_name: '',
        email: '',
        password: '',
        password_confirmation: '',
        role: 'Student',
        program: '',
        school_id: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout title="Create an account (For Development only)" description="Enter your details below to create your account">
            <Head title="Register" />

            <form className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="first_name">Firstname</Label>
                        <Input
                            id="first_name"
                            type="text"
                            required
                            autoFocus
                            tabIndex={1}
                            autoComplete="firstname"
                            value={data.first_name}
                            onChange={(e) => setData('first_name', e.target.value)}
                            disabled={processing}
                            placeholder="Firstname"
                        />
                        <InputError message={errors.first_name} />
                        <Label htmlFor="middle_name">Middle name</Label>
                        <Input
                            id="middle_name"
                            type="text"
                            required
                            autoFocus
                            tabIndex={1}
                            autoComplete="middle"
                            value={data.middle_name}
                            onChange={(e) => setData('middle_name', e.target.value)}
                            disabled={processing}
                            placeholder="Middle Name"
                        />
                        <InputError message={errors.middle_name} />
                        <Label htmlFor="last_name">Lastname</Label>
                        <Input
                            id="last_name"
                            type="text"
                            required
                            autoFocus
                            tabIndex={1}
                            autoComplete="lastname"
                            value={data.last_name}
                            onChange={(e) => setData('last_name', e.target.value)}
                            disabled={processing}
                            placeholder="Lastname"
                        />
                        <InputError message={errors.last_name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="email">Email address</Label>
                        <Input
                            id="email"
                            type="email"
                            required
                            tabIndex={2}
                            autoComplete="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            disabled={processing}
                            placeholder="email@example.com"
                        />
                        <InputError message={errors.email} />

                        <Label htmlFor="school_id">School ID</Label>
                        <Input
                            id="school_id"
                            type="text"
                            required
                            tabIndex={2}
                            autoComplete="00000"
                            value={data.school_id}
                            onChange={(e) => setData('school_id', e.target.value)}
                            disabled={processing}
                            placeholder="230000001603"
                        />
                        <InputError message={errors.school_id} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="password">Password</Label>
                        <Input
                            id="password"
                            type="password"
                            required
                            tabIndex={3}
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            disabled={processing}
                            placeholder="Password"
                        />
                        <InputError message={errors.password} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">Confirm password</Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            required
                            tabIndex={4}
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            disabled={processing}
                            placeholder="Confirm password"
                        />
                        <InputError message={errors.password_confirmation} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="role">Role</Label>
                        <select
                            id="role"
                            required
                            tabIndex={5}
                            value={data.role}
                            onChange={(e) => setData('role', e.target.value)}
                            disabled={processing}
                            className="rounded border px-3 py-2"
                        >
                            <option value="Student">Student</option>
                        </select>
                        <p className="text-muted-foreground text-xs">Staff roles are assigned by the Super Admin after registration.</p>
                        <InputError message={errors.role} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="program">Program (if applicable)</Label>

                        <Select value={data.program} onValueChange={(value) => setData('program', value)} disabled={processing}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Select your program" />
                            </SelectTrigger>

                            <SelectContent className="max-h-[300px] overflow-auto">
                                <SelectGroup>
                                    <span className="px-2 py-1 text-xs font-semibold text-gray-500">Doctorate Programs</span>
                                    {DOCTORATE_PROGRAMS.map((name) => (
                                        <SelectItem key={name} value={name}>
                                            {name}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>

                                <SelectGroup>
                                    <span className="px-2 py-1 text-xs font-semibold text-gray-500">Master’s Programs</span>
                                    {MASTER_PROGRAMS.map((name) => (
                                        <SelectItem key={name} value={name}>
                                            {name}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>

                        <InputError message={errors.program} />
                    </div>

                    <Button type="submit" className="mt-4 w-full" tabIndex={6} disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Create account
                    </Button>
                </div>

                <div className="text-muted-foreground text-center text-sm">
                    Already have an account?{' '}
                    <TextLink href={route('login')} tabIndex={7}>
                        Log in
                    </TextLink>
                </div>
            </form>
        </AuthLayout>
    );
}
