import { Button } from '@/components/ui/button';
import { splitClock } from '@/utils/scheduling-time';
import { CalendarDays } from 'lucide-react';
import { useId, useState } from 'react';

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
    const times = Array.from({ length: 96 }, (_, index) => {
        const minutes = index * 15;
        return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
    });
    const selected = value.slice(0, 5);
    // Keep an existing saved time selectable even when it is between quarter-hour options.
    if (selected && !times.includes(selected)) times.push(selected);
    times.sort();
    return (
        <label className="block space-y-2">
            <span className="text-muted-foreground block text-xs">{label}</span>
            <select
                aria-label={label}
                disabled={disabled}
                className={selectClass}
                value={selected}
                onChange={(event) => onChange(event.target.value)}
            >
                <option value="">Select time</option>
                {times.map((time) => {
                    const parts = splitClock(time);
                    return (
                        <option key={time} value={time}>
                            {parts.hour}:{parts.minute} {parts.period}
                        </option>
                    );
                })}
            </select>
        </label>
    );
}
