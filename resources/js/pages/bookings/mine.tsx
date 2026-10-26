import { Head, Link, router, usePage } from '@inertiajs/react';
import { Pencil, TriangleAlert } from 'lucide-react';
import { CancelButton } from '@/components/booking/booking-details';
import { Button } from '@/components/ui/button';
import { formatRange } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { edit, mine, show } from '@/routes/bookings';
import type { CalendarBooking } from '@/types';

type Tab = 'upcoming' | 'in_progress' | 'past' | 'cancelled';

type Props = {
    tab: Tab;
    tabs: Record<Tab, CalendarBooking[]>;
};

const TAB_LABELS: Record<Tab, string> = {
    upcoming: 'Upcoming',
    in_progress: 'In progress',
    past: 'Past',
    cancelled: 'Cancelled',
};

export default function MyBookings({ tab, tabs }: Props) {
    const { timezone } = usePage().props;
    const needsAttention = [...tabs.upcoming, ...tabs.in_progress].filter(
        (booking) => booking.at_risk,
    ).length;

    const selectTab = (next: Tab) => {
        router.get(
            mine.url({
                query: { tab: next === 'upcoming' ? undefined : next },
            }),
            {},
            {
                only: ['tab'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    return (
        <>
            <Head title="My bookings" />

            <div className="flex max-w-4xl flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold tracking-tight">
                    My bookings
                </h1>

                {needsAttention > 0 && (
                    <p className="flex items-center gap-2 rounded-md border border-red-600/30 bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950/30 dark:text-red-300">
                        <TriangleAlert
                            className="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        {needsAttention}{' '}
                        {needsAttention === 1
                            ? 'booking needs attention'
                            : 'bookings need attention'}
                        : an item is unavailable.
                    </p>
                )}

                <div role="tablist" className="flex flex-wrap gap-1 border-b">
                    {(Object.keys(TAB_LABELS) as Tab[]).map((key) => (
                        <button
                            key={key}
                            type="button"
                            role="tab"
                            aria-selected={tab === key}
                            onClick={() => selectTab(key)}
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm',
                                tab === key
                                    ? 'border-foreground font-medium'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {TAB_LABELS[key]}{' '}
                            <span className="text-muted-foreground">
                                {tabs[key].length}
                            </span>
                        </button>
                    ))}
                </div>

                <ul
                    role="tabpanel"
                    className="flex flex-col divide-y rounded-lg border"
                >
                    {tabs[tab].length === 0 && (
                        <li className="p-6 text-center text-sm text-muted-foreground">
                            No {TAB_LABELS[tab].toLowerCase()} bookings.
                        </li>
                    )}
                    {tabs[tab].map((booking) => (
                        <BookingRow
                            key={booking.id}
                            booking={booking}
                            timezone={timezone}
                        />
                    ))}
                </ul>
            </div>
        </>
    );
}

function BookingRow({
    booking,
    timezone,
}: {
    booking: CalendarBooking;
    timezone: string;
}) {
    const changeable =
        booking.is_active &&
        (booking.phase === 'upcoming' || booking.phase === 'in_progress');
    const inProgress = booking.phase === 'in_progress';

    return (
        <li
            className={cn(
                'flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 text-sm',
                booking.at_risk && 'bg-red-50/60 dark:bg-red-950/20',
            )}
        >
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="text-muted-foreground">
                    {formatRange(booking.starts_at, booking.ends_at, timezone)}
                </span>
                <Link
                    href={show(booking.id)}
                    className="font-medium hover:underline"
                >
                    {booking.title}
                </Link>
                <span className="flex flex-wrap gap-x-2 text-muted-foreground">
                    {booking.items.map((item) => (
                        <span
                            key={item.id}
                            className={cn(
                                'inline-flex items-center gap-1',
                                item.status !== 'available' &&
                                    booking.at_risk &&
                                    'text-red-700 dark:text-red-400',
                            )}
                        >
                            {item.name}
                            {item.status !== 'available' && booking.at_risk && (
                                <TriangleAlert
                                    className="size-3"
                                    aria-label="unavailable"
                                />
                            )}
                        </span>
                    ))}
                </span>
            </div>

            {changeable && (
                <div className="flex gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={edit(booking.id)}>
                            <Pencil />
                            {inProgress
                                ? 'Shorten'
                                : booking.at_risk
                                  ? 'Edit to swap item'
                                  : 'Edit'}
                        </Link>
                    </Button>
                    <CancelButton
                        booking={booking}
                        inProgress={inProgress}
                        size="sm"
                    />
                </div>
            )}
        </li>
    );
}
