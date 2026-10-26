import { Link } from '@inertiajs/react';
import { OctagonX, PackageOpen, TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { formatRange, formatTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { show as showBooking } from '@/routes/bookings';
import type { CalendarWeekData } from '@/types';

type Props = Pick<
    CalendarWeekData,
    'out_now' | 'unavailable' | 'my_at_risk'
> & {
    timezone: string;
};

type SectionKey = 'out' | 'unavailable' | 'risk';

/**
 * What's true right now: items out, items out of service and the user's
 * at-risk bookings. A column beside the grid on wide screens; chips that
 * expand above the grid on narrow ones.
 */
export function SidePanel({
    out_now,
    unavailable,
    my_at_risk,
    timezone,
}: Props) {
    const [open, setOpen] = useState<SectionKey | null>(null);

    const sections: {
        key: SectionKey;
        title: string;
        icon: ReactNode;
        count: number;
        body: ReactNode;
    }[] = [
        {
            key: 'out',
            title: 'Out now',
            icon: <PackageOpen className="size-4" aria-hidden="true" />,
            count: out_now.length,
            body: (
                <List empty="Nothing is out right now.">
                    {out_now.map((item) => (
                        <li key={`${item.booking_id}-${item.equipment_id}`}>
                            <Link
                                href={showBooking(item.booking_id)}
                                className="font-medium hover:underline"
                            >
                                {item.name}
                            </Link>{' '}
                            — {item.owner}, until{' '}
                            {formatTime(item.until, timezone)}
                        </li>
                    ))}
                </List>
            ),
        },
        {
            key: 'unavailable',
            title: 'Unavailable',
            icon: <OctagonX className="size-4" aria-hidden="true" />,
            count: unavailable.length,
            body: (
                <List empty="Everything is in service.">
                    {unavailable.map((item) => (
                        <li key={item.equipment_id}>
                            <span className="font-medium">⛔ {item.name}</span>
                            <span className="block text-muted-foreground">
                                Out of service
                                {item.reason && ` — ${item.reason}`}
                            </span>
                        </li>
                    ))}
                </List>
            ),
        },
        {
            key: 'risk',
            title: 'My at-risk bookings',
            icon: <TriangleAlert className="size-4" aria-hidden="true" />,
            count: my_at_risk.length,
            body: (
                <List empty="None of your bookings are at risk.">
                    {my_at_risk.map((booking) => (
                        <li key={booking.id}>
                            <Link
                                href={showBooking(booking.id)}
                                className="font-medium hover:underline"
                            >
                                ⚠ {booking.title}
                            </Link>
                            <span className="block text-muted-foreground">
                                {formatRange(
                                    booking.starts_at,
                                    booking.ends_at,
                                    timezone,
                                )}{' '}
                                · uses{' '}
                                {booking.items
                                    .filter(
                                        (item) => item.status !== 'available',
                                    )
                                    .map((item) => item.name)
                                    .join(', ')}
                            </span>
                        </li>
                    ))}
                </List>
            ),
        },
    ];

    return (
        <>
            {/* Narrow screens: chips above the grid. */}
            <div className="order-first flex flex-col gap-2 lg:hidden">
                <div className="flex flex-wrap gap-2">
                    {sections.map((section) => (
                        <Button
                            key={section.key}
                            variant={
                                open === section.key ? 'default' : 'outline'
                            }
                            size="sm"
                            aria-expanded={open === section.key}
                            onClick={() =>
                                setOpen(
                                    open === section.key ? null : section.key,
                                )
                            }
                            className={cn(
                                section.key === 'risk' &&
                                    section.count > 0 &&
                                    open !== section.key &&
                                    'border-red-600/40 text-red-700 dark:text-red-400',
                            )}
                        >
                            {section.icon}
                            {section.title} · {section.count}
                        </Button>
                    ))}
                </div>
                {sections
                    .filter((section) => section.key === open)
                    .map((section) => (
                        <div
                            key={section.key}
                            className="rounded-lg border p-3 text-sm"
                        >
                            {section.body}
                        </div>
                    ))}
            </div>

            {/* Wide screens: a column beside the grid. */}
            <aside className="hidden w-64 shrink-0 flex-col gap-6 text-sm lg:flex">
                {sections.map((section) => (
                    <section key={section.key} className="flex flex-col gap-2">
                        <h2 className="flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {section.icon}
                            {section.title}
                        </h2>
                        {section.body}
                    </section>
                ))}
            </aside>
        </>
    );
}

function List({ empty, children }: { empty: string; children: ReactNode[] }) {
    if (children.length === 0) {
        return <p className="text-muted-foreground">{empty}</p>;
    }

    return <ul className="flex flex-col gap-2">{children}</ul>;
}
