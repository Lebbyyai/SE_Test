import { Button } from '@/components/ui/button';
import { joinClock, splitClock, type ClockParts } from '@/utils/scheduling-time';
import { CalendarDays } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

const selectClass =
    'bg-background text-foreground h-10 w-full rounded-md border px-2 text-sm [color-scheme:light] disabled:opacity-50 dark:[color-scheme:dark]';
const todayInManila = () =>
    new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());

export function DefenseDateControl({ value, onChange, disabled }: { value: string; onChange: (value: string) => void; disabled?: boolean }) {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState(value || todayInManila());
    const id = useId();
    const [year, month, day] = draft.split('-').map(Number);
    const yearNow = Number(todayInManila().slice(0, 4));
    const years = [...new Set([...Array.from({ length: 16 }, (_, index) => yearNow - 5 + index), year])].sort((a, b) => a - b);
    const days = new Date(year, month, 0).getDate();
    const updateDate = (nextYear: number, nextMonth: number, nextDay: number) => {
        const lastDay = new Date(nextYear, nextMonth, 0).getDate();
        setDraft(`${nextYear}-${String(nextMonth).padStart(2, '0')}-${String(Math.min(nextDay, lastDay)).padStart(2, '0')}`);
    };
    return (
        <div className="space-y-2">
            <div className="text-muted-foreground text-xs">Defense date</div>
            <p className="text-sm">
                {value
                    ? new Intl.DateTimeFormat('en-PH', { month: 'long', day: 'numeric', year: 'numeric' }).format(new Date(value + 'T12:00:00'))
                    : 'No date selected'}
            </p>
            <Button
                type="button"
                variant="outline"
                disabled={disabled}
                aria-expanded={open}
                aria-controls={id}
                onClick={() => {
                    if (!open) setDraft(value || todayInManila());
                    setOpen(!open);
                }}
            >
                <CalendarDays className="mr-2 h-4 w-4" />
                {value ? 'Change date' : 'Choose date'}
            </Button>
            {open && (
                <fieldset id={id} disabled={disabled} className="space-y-3 rounded-md border p-3">
                    <legend className="px-1 text-xs">Choose defense date</legend>
                    <div className="grid grid-cols-3 gap-2">
                        <label className="space-y-1 text-xs">
                            Month
                            <select className={selectClass} value={month} onChange={(event) => updateDate(year, Number(event.target.value), day)}>
                                {Array.from({ length: 12 }, (_, index) => (
                                    <option key={index + 1} value={index + 1}>
                                        {new Intl.DateTimeFormat('en-PH', { month: 'short' }).format(new Date(2026, index, 1))}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="space-y-1 text-xs">
                            Day
                            <select className={selectClass} value={day} onChange={(event) => updateDate(year, month, Number(event.target.value))}>
                                {Array.from({ length: days }, (_, index) => (
                                    <option key={index + 1} value={index + 1}>
                                        {index + 1}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="space-y-1 text-xs">
                            Year
                            <select className={selectClass} value={year} onChange={(event) => updateDate(Number(event.target.value), month, day)}>
                                {years.map((item) => (
                                    <option key={item} value={item}>
                                        {item}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => {
                                onChange(draft);
                                setOpen(false);
                            }}
                        >
                            Use this date
                        </Button>
                        <Button type="button" size="sm" variant="ghost" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                    </div>
                </fieldset>
            )}
        </div>
    );
}

export function DefenseTimeControl({
    label,
    value,
    onChange,
    disabled,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
}) {
    const [parts, setParts] = useState<ClockParts>(() => splitClock(value));
    const lastEmitted = useRef(value);
    useEffect(() => {
        if (value !== lastEmitted.current) {
            setParts(splitClock(value));
            lastEmitted.current = value;
        }
    }, [value]);
    const update = (field: keyof ClockParts, next: string) => {
        const updated = { ...parts, [field]: next };
        setParts(updated);
        lastEmitted.current = joinClock(updated);
        onChange(lastEmitted.current);
    };
    return (
        <fieldset disabled={disabled} className="space-y-1">
            <legend className="text-muted-foreground mb-1 text-xs">{label}</legend>
            <div className="grid grid-cols-3 gap-2">
                <select
                    aria-label={`${label} hour`}
                    className={selectClass}
                    value={parts.hour}
                    onChange={(event) => update('hour', event.target.value)}
                >
                    <option value="">Hour</option>
                    {Array.from({ length: 12 }, (_, index) => (
                        <option key={index + 1} value={index + 1}>
                            {index + 1}
                        </option>
                    ))}
                </select>
                <select
                    aria-label={`${label} minute`}
                    className={selectClass}
                    value={parts.minute}
                    onChange={(event) => update('minute', event.target.value)}
                >
                    <option value="">Minute</option>
                    {Array.from({ length: 60 }, (_, index) => (
                        <option key={index} value={String(index).padStart(2, '0')}>
                            {String(index).padStart(2, '0')}
                        </option>
                    ))}
                </select>
                <select
                    aria-label={`${label} AM or PM`}
                    className={selectClass}
                    value={parts.period}
                    onChange={(event) => update('period', event.target.value)}
                >
                    <option value="">AM/PM</option>
                    <option value="AM">AM</option>
                    <option value="PM">PM</option>
                </select>
            </div>
        </fieldset>
    );
}
