import type { CalendarBooking } from '@/types';

/**
 * Week-grid layout maths. Booking times arrive as company-local ISO strings,
 * so the wall-clock date and minutes are read straight from the string and
 * the browser never converts timezones.
 */

export const MINUTES_PER_DAY = 1440;

/** "2026-10-28T09:30:00-05:00" → { date: "2026-10-28", minutes: 570 } */
export function wallClock(iso: string): { date: string; minutes: number } {
    return {
        date: iso.slice(0, 10),
        minutes: Number(iso.slice(11, 13)) * 60 + Number(iso.slice(14, 16)),
    };
}

/** Calendar-date arithmetic on "YYYY-MM-DD" strings. */
export function addDays(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day + days))
        .toISOString()
        .slice(0, 10);
}

/** Mon → Sun dates of the week starting on `monday`. */
export function weekDays(monday: string): string[] {
    return Array.from({ length: 7 }, (_, index) => addDays(monday, index));
}

/** "Mon 26" */
export function dayLabel(date: string): { weekday: string; day: number } {
    const [year, month, day] = date.split('-').map(Number);
    const weekday = new Date(Date.UTC(year, month - 1, day)).toLocaleDateString(
        'en-US',
        { weekday: 'short', timeZone: 'UTC' },
    );

    return { weekday, day };
}

/** The last calendar day a booking occupies (an end at 00:00 doesn't count). */
export function lastDay(booking: CalendarBooking): string {
    const end = wallClock(booking.ends_at);

    return end.minutes === 0 ? addDays(end.date, -1) : end.date;
}

export function isMultiDay(booking: CalendarBooking): boolean {
    return wallClock(booking.starts_at).date !== lastDay(booking);
}

export type Segment = {
    booking: CalendarBooking;
    /** Minutes since midnight where this day's part starts and ends. */
    start: number;
    end: number;
    continuesBefore: boolean;
    continuesAfter: boolean;
};

/** The part of a booking that falls on one day, if any. */
export function segmentOn(
    booking: CalendarBooking,
    date: string,
): Segment | null {
    const start = wallClock(booking.starts_at);
    const end = wallClock(booking.ends_at);

    if (start.date > date || lastDay(booking) < date) {
        return null;
    }

    return {
        booking,
        start: start.date < date ? 0 : start.minutes,
        end: end.date > date ? MINUTES_PER_DAY : end.minutes,
        continuesBefore: start.date < date,
        continuesAfter: end.date > date && lastDay(booking) > date,
    };
}

export type PlacedSegment = Segment & { column: number; columns: number };

/**
 * Lay overlapping segments side by side, like Google Calendar: each group of
 * transitively overlapping segments shares its width between columns.
 */
export function layoutDay(segments: Segment[]): PlacedSegment[] {
    const sorted = [...segments].sort(
        (a, b) => a.start - b.start || b.end - a.end,
    );
    const placed: PlacedSegment[] = [];
    let group: PlacedSegment[] = [];
    let columnEnds: number[] = [];
    let groupEnd = -1;

    const closeGroup = () => {
        for (const segment of group) {
            segment.columns = columnEnds.length;
        }

        placed.push(...group);
        group = [];
        columnEnds = [];
    };

    for (const segment of sorted) {
        if (segment.start >= groupEnd) {
            closeGroup();
        }

        let column = columnEnds.findIndex((end) => end <= segment.start);

        if (column === -1) {
            column = columnEnds.length;
            columnEnds.push(segment.end);
        } else {
            columnEnds[column] = segment.end;
        }

        group.push({ ...segment, column, columns: 1 });
        groupEnd = Math.max(groupEnd, segment.end);
    }

    closeGroup();

    return placed;
}

/** "09:30" for minutes since midnight. */
export function timeOfDay(minutes: number): string {
    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
}
