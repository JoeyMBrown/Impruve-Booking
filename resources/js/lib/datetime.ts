/**
 * Date helpers that always display in the company timezone (shared as the
 * `timezone` page prop), whatever the browser's own timezone is.
 */

function parts(
    iso: string,
    timeZone: string,
    options: Intl.DateTimeFormatOptions,
) {
    return new Intl.DateTimeFormat('en-US', { timeZone, ...options }).format(
        new Date(iso),
    );
}

/** "2026-10-28": the calendar day in the company timezone, for grouping. */
export function dayKey(iso: string, timeZone: string): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date(iso));
}

/** "Wed Oct 28" */
export function formatDay(iso: string, timeZone: string): string {
    return parts(iso, timeZone, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    }).replace(',', '');
}

/** "9:00 AM" */
export function formatTime(iso: string, timeZone: string): string {
    return parts(iso, timeZone, { hour: 'numeric', minute: '2-digit' });
}

/** "Wed Oct 28 · 9:00 AM → 5:00 PM", or both days when the range spans days. */
export function formatRange(
    startsAt: string,
    endsAt: string,
    timeZone: string,
): string {
    const sameDay = dayKey(startsAt, timeZone) === dayKey(endsAt, timeZone);
    const start = `${formatDay(startsAt, timeZone)} · ${formatTime(startsAt, timeZone)}`;

    return sameDay
        ? `${start} → ${formatTime(endsAt, timeZone)}`
        : `${start} → ${formatDay(endsAt, timeZone)} · ${formatTime(endsAt, timeZone)}`;
}

/** "Oct 26, 2026, 2:05 PM" */
export function formatDateTime(iso: string, timeZone: string): string {
    return parts(iso, timeZone, { dateStyle: 'medium', timeStyle: 'short' });
}
