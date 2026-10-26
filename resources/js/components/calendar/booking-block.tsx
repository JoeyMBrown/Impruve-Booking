import { TriangleAlert } from 'lucide-react';
import { blockStyle } from '@/lib/category-colors';
import { formatRange } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import type { CalendarBooking } from '@/types';

type Props = {
    booking: CalendarBooking;
    timezone: string;
    compact?: boolean;
    label?: string;
    className?: string;
    style?: React.CSSProperties;
    onOpen: (booking: CalendarBooking) => void;
};

/** One booking on the calendar: title, owner, items and an ⚠ when at risk. */
export function BookingBlock({
    booking,
    timezone,
    compact = false,
    label,
    className,
    style,
    onOpen,
}: Props) {
    const items = booking.items.map((item) => item.name).join(', ');

    return (
        <button
            type="button"
            onClick={(event) => {
                event.stopPropagation();
                onOpen(booking);
            }}
            className={cn(
                'flex flex-col overflow-hidden rounded-md px-1.5 py-1 text-left text-xs leading-tight text-foreground hover:z-10 hover:shadow-md focus-visible:z-10 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                className,
            )}
            style={{
                ...blockStyle(
                    booking.items.map((item) => item.category),
                    booking.is_mine,
                ),
                ...style,
            }}
            aria-label={`${booking.title}, ${booking.owner.name}, ${formatRange(booking.starts_at, booking.ends_at, timezone)}, ${items}${booking.at_risk ? ', at risk: an item is unavailable' : ''}`}
        >
            <span className="flex items-center gap-1 font-semibold">
                {booking.at_risk && (
                    <TriangleAlert
                        className="size-3 shrink-0 text-red-700 dark:text-red-400"
                        aria-hidden="true"
                    />
                )}
                <span className="truncate">{booking.title}</span>
            </span>
            {!compact && (
                <>
                    <span className="truncate">{booking.owner.name}</span>
                    <span className="truncate text-muted-foreground">
                        {items}
                    </span>
                </>
            )}
            {label && (
                <span className="truncate text-muted-foreground">{label}</span>
            )}
        </button>
    );
}
