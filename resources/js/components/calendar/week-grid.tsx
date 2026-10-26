import { useEffect, useRef } from 'react';
import { BookingBlock } from '@/components/calendar/booking-block';
import type { CompanyClock } from '@/hooks/use-company-clock';
import {
    dayLabel,
    isMultiDay,
    lastDay,
    layoutDay,
    MINUTES_PER_DAY,
    segmentOn,
    timeOfDay,
    wallClock,
} from '@/lib/calendar';
import { cn } from '@/lib/utils';
import type { CalendarBooking } from '@/types';

/** Height of one 30-minute row, in pixels. */
const SLOT_HEIGHT = 24;
const SLOT_MINUTES = 30;
const SLOTS = MINUTES_PER_DAY / SLOT_MINUTES;
/** The grid opens scrolled to 07:00. */
const FIRST_VISIBLE_HOUR = 7;
/** Bookings may start up to this long in the past (matches the server). */
const GRACE_MINUTES = 15;

const COLUMNS = 'grid grid-cols-[4rem_repeat(7,minmax(0,1fr))]';

type Props = {
    days: string[];
    bookings: CalendarBooking[];
    timezone: string;
    clock: CompanyClock;
    onOpen: (booking: CalendarBooking) => void;
    onSlotClick: (date: string, minutes: number) => void;
};

export function WeekGrid({
    days,
    bookings,
    timezone,
    clock,
    onOpen,
    onSlotClick,
}: Props) {
    const scrollRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (scrollRef.current) {
            scrollRef.current.scrollTop = FIRST_VISIBLE_HOUR * 2 * SLOT_HEIGHT;
        }
    }, []);

    const multiDay = bookings.filter(isMultiDay);

    return (
        <div className="overflow-x-auto rounded-lg border">
            <div className="min-w-[52rem]">
                <div className={cn(COLUMNS, 'border-b bg-muted/40')}>
                    <div />
                    {days.map((day) => {
                        const { weekday, day: dayOfMonth } = dayLabel(day);
                        const isToday = day === clock.date;

                        return (
                            <div
                                key={day}
                                className={cn(
                                    'border-l px-2 py-2 text-center text-sm',
                                    isToday && 'font-semibold text-red-600',
                                )}
                            >
                                {weekday} {dayOfMonth}
                            </div>
                        );
                    })}
                </div>

                {multiDay.length > 0 && (
                    <div
                        className={cn(COLUMNS, 'gap-y-1 border-b py-1')}
                        aria-label="Multi-day bookings"
                    >
                        <div className="row-span-full px-2 text-xs text-muted-foreground">
                            All day
                        </div>
                        {multiDay.map((booking) => {
                            const first = Math.max(
                                0,
                                days.indexOf(wallClock(booking.starts_at).date),
                            );
                            const lastIndex = days.indexOf(lastDay(booking));
                            const last =
                                lastIndex === -1 ? days.length - 1 : lastIndex;
                            const startsBefore =
                                wallClock(booking.starts_at).date < days[0];

                            return (
                                <BookingBlock
                                    key={booking.id}
                                    booking={booking}
                                    timezone={timezone}
                                    compact
                                    className="mx-0.5"
                                    style={{
                                        gridColumn: `${(startsBefore ? 0 : first) + 2} / ${last + 3}`,
                                    }}
                                    onOpen={onOpen}
                                />
                            );
                        })}
                    </div>
                )}

                <div
                    ref={scrollRef}
                    className="h-[65vh] min-h-96 overflow-y-auto"
                >
                    <div
                        className={COLUMNS}
                        style={{ height: SLOTS * SLOT_HEIGHT }}
                    >
                        <div aria-hidden="true">
                            {Array.from({ length: 24 }, (_, hour) => (
                                <div
                                    key={hour}
                                    className="pr-2 text-right text-xs text-muted-foreground"
                                    style={{ height: 2 * SLOT_HEIGHT }}
                                >
                                    {hour === 0
                                        ? ''
                                        : `${hour % 12 === 0 ? 12 : hour % 12} ${hour < 12 ? 'AM' : 'PM'}`}
                                </div>
                            ))}
                        </div>

                        {days.map((day) => (
                            <DayColumn
                                key={day}
                                day={day}
                                bookings={bookings}
                                timezone={timezone}
                                clock={clock}
                                onOpen={onOpen}
                                onSlotClick={onSlotClick}
                            />
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}

function DayColumn({
    day,
    bookings,
    timezone,
    clock,
    onOpen,
    onSlotClick,
}: Omit<Props, 'days'> & { day: string }) {
    const segments = layoutDay(
        bookings
            .map((booking) => segmentOn(booking, day))
            .filter((segment) => segment !== null),
    );

    // A slot can be booked if it starts no more than the grace period ago.
    const isBookable = (minutes: number) =>
        day > clock.date ||
        (day === clock.date && minutes + GRACE_MINUTES >= clock.minutes);

    return (
        <div className="relative border-l">
            {Array.from({ length: SLOTS }, (_, slot) => {
                const minutes = slot * SLOT_MINUTES;
                const bookable = isBookable(minutes);

                return (
                    <div
                        key={slot}
                        className={cn(
                            'border-b',
                            slot % 2 === 0
                                ? 'border-dashed border-border/50'
                                : 'border-border',
                            bookable
                                ? 'cursor-pointer hover:bg-muted'
                                : 'bg-muted/30',
                        )}
                        style={{ height: SLOT_HEIGHT }}
                        title={
                            bookable
                                ? `Book ${timeOfDay(minutes)}–${timeOfDay(minutes + SLOT_MINUTES)}`
                                : undefined
                        }
                        onClick={
                            bookable
                                ? () => onSlotClick(day, minutes)
                                : undefined
                        }
                    />
                );
            })}

            {segments.map((segment) => (
                <BookingBlock
                    key={segment.booking.id}
                    booking={segment.booking}
                    timezone={timezone}
                    compact={segment.end - segment.start <= SLOT_MINUTES}
                    label={
                        segment.continuesBefore || segment.continuesAfter
                            ? `${dayLabel(wallClock(segment.booking.starts_at).date).weekday}→${dayLabel(lastDay(segment.booking)).weekday}`
                            : undefined
                    }
                    className="absolute"
                    style={{
                        top: (segment.start / SLOT_MINUTES) * SLOT_HEIGHT,
                        height:
                            ((segment.end - segment.start) / SLOT_MINUTES) *
                                SLOT_HEIGHT -
                            2,
                        left: `calc(${(segment.column / segment.columns) * 100}% + 2px)`,
                        width: `calc(${100 / segment.columns}% - 4px)`,
                    }}
                    onOpen={onOpen}
                />
            ))}

            {day === clock.date && (
                <div
                    className="pointer-events-none absolute inset-x-0 z-20 border-t-2 border-red-600"
                    style={{
                        top: (clock.minutes / SLOT_MINUTES) * SLOT_HEIGHT,
                    }}
                    aria-label="Now"
                >
                    <span className="absolute -top-1.5 -left-1 size-2.5 rounded-full bg-red-600" />
                </div>
            )}
        </div>
    );
}
