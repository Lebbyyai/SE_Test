import { useEffect, useState } from 'react';

const REQUIREMENTS = ['Complete grades (registrar verified)', 'Complete documents submitted', 'No outstanding tuition balance'];
type EligibilityState = 'Checking...' | 'Eligible' | 'Not eligible' | 'Not verified';

export function examEligibilityLabel(data: unknown): EligibilityState {
    if (typeof data !== 'object' || data === null) return 'Not verified';
    const result = data as { eligible?: unknown; error?: unknown; requirements?: { name?: string; completed?: unknown }[] };
    if (result.error || !Array.isArray(result.requirements)) return 'Not verified';
    const checks = REQUIREMENTS.map((name) => result.requirements?.find((item) => item && item.name === name)?.completed);
    if (checks.some((completed) => completed !== true && completed !== false)) return 'Not verified';
    return result.eligible === true && checks.every((completed) => completed === true) ? 'Eligible' : 'Not eligible';
}

export default function ExamEligibilityWidget({ simple }: { simple?: boolean }) {
    const [status, setStatus] = useState<EligibilityState>('Checking...');
    useEffect(() => {
        const controller = new AbortController();
        async function check() {
            try {
                const response = await fetch('/api/comprehensive-exam/eligibility', {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('Eligibility unavailable');
                const data: unknown = await response.json();
                if (!controller.signal.aborted) setStatus(examEligibilityLabel(data));
            } catch {
                if (!controller.signal.aborted) setStatus('Not verified');
            }
        }
        void check();
        return () => controller.abort();
    }, []);

    const label = (
        <span role="status" className="text-foreground text-2xl font-bold">
            {status}
        </span>
    );
    if (simple) return label;
    return (
        <div className="bg-card text-card-foreground border-border flex aspect-video flex-col justify-between rounded-xl border p-5">
            <h3 className="mb-2 text-[13px] font-medium">Exam Eligibility</h3>
            {label}
        </div>
    );
}
