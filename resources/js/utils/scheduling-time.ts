export type ClockParts = { hour: string; minute: string; period: string };

export function splitClock(value: string): ClockParts {
    if (!/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/.test(value)) return { hour: '', minute: '', period: '' };
    const [hour, minute] = value.split(':');
    const numeric = Number(hour);
    return { hour: String(numeric % 12 || 12), minute, period: numeric >= 12 ? 'PM' : 'AM' };
}

export function joinClock(parts: ClockParts): string {
    if (!/^(?:[1-9]|1[0-2])$/.test(parts.hour) || !/^[0-5]\d$/.test(parts.minute) || !['AM', 'PM'].includes(parts.period)) return '';
    const hour = (Number(parts.hour) % 12) + (parts.period === 'PM' ? 12 : 0);
    return `${String(hour).padStart(2, '0')}:${parts.minute}`;
}
