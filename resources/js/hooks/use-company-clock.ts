import { useEffect, useState } from 'react';

export type CompanyClock = { date: string; minutes: number };

function readClock(timeZone: string): CompanyClock {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-CA', {
            timeZone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        })
            .formatToParts(new Date())
            .map((part) => [part.type, part.value]),
    );

    return {
        date: `${parts.year}-${parts.month}-${parts.day}`,
        minutes: Number(parts.hour) * 60 + Number(parts.minute),
    };
}

/**
 * The current company wall-clock time as { date: "2026-10-28", minutes },
 * refreshed every minute. Drives the calendar's "now" line.
 */
export function useCompanyClock(timeZone: string): CompanyClock {
    const [clock, setClock] = useState(() => readClock(timeZone));

    useEffect(() => {
        const timer = setInterval(() => setClock(readClock(timeZone)), 60_000);

        return () => clearInterval(timer);
    }, [timeZone]);

    return clock;
}
