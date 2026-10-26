/**
 * Helpers for company-local wall-clock strings ("2026-10-29T09:00") used by
 * the booking form. They do plain calendar arithmetic with no timezone, so
 * durations ignore DST; the server does the real checks.
 */

export type LocalParts = { date: string; time: string };

export function splitLocal(value: string): LocalParts {
    const [date = '', time = ''] = value.split('T');

    return { date, time: time.slice(0, 5) };
}

export function joinLocal(date: string, time: string): string {
    return `${date}T${time}`;
}

function toUtcMillis(value: string): number {
    const { date, time } = splitLocal(value);
    const [year, month, day] = date.split('-').map(Number);
    const [hour, minute] = time.split(':').map(Number);

    return Date.UTC(year, month - 1, day, hour, minute);
}

export function addMinutesLocal(value: string, minutes: number): string {
    const shifted = new Date(toUtcMillis(value) + minutes * 60_000);

    return shifted.toISOString().slice(0, 16);
}

export function minutesBetweenLocal(from: string, to: string): number {
    return Math.round((toUtcMillis(to) - toUtcMillis(from)) / 60_000);
}

/** "30 min", "8 h", "1 h 30 min", "2 d 4 h" */
export function formatDuration(totalMinutes: number): string {
    const days = Math.floor(totalMinutes / 1440);
    const hours = Math.floor((totalMinutes % 1440) / 60);
    const minutes = totalMinutes % 60;

    return (
        [
            days > 0 ? `${days} d` : '',
            hours > 0 ? `${hours} h` : '',
            minutes > 0 ? `${minutes} min` : '',
        ]
            .filter(Boolean)
            .join(' ') || '0 min'
    );
}

/** Time-of-day choices in slot steps: { value: "09:30", label: "9:30 AM" } */
export function timeOptions(
    slotMinutes: number,
): { value: string; label: string }[] {
    const options = [];

    for (let minute = 0; minute < 1440; minute += slotMinutes) {
        const hour = Math.floor(minute / 60);
        const value = `${String(hour).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`;
        const label = `${hour % 12 === 0 ? 12 : hour % 12}:${String(minute % 60).padStart(2, '0')} ${hour < 12 ? 'AM' : 'PM'}`;

        options.push({ value, label });
    }

    return options;
}
