import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { BookingDrawer } from '@/components/calendar/booking-drawer';
import { SidePanel } from '@/components/calendar/side-panel';
import { WeekGrid } from '@/components/calendar/week-grid';
import { WeekToolbar } from '@/components/calendar/week-toolbar';
import { useCompanyClock } from '@/hooks/use-company-clock';
import { timeOfDay, weekDays } from '@/lib/calendar';
import { addMinutesLocal, joinLocal } from '@/lib/local-time';
import { create as createBooking } from '@/routes/bookings';
import type { CalendarBooking, CalendarWeekData } from '@/types';

export default function CalendarWeek(props: CalendarWeekData) {
    const {
        week,
        filters,
        equipment,
        bookings,
        out_now,
        unavailable,
        my_at_risk,
    } = props;
    const { timezone } = usePage().props;
    const clock = useCompanyClock(timezone);
    const days = weekDays(week.start);

    // Clicking an empty slot books exactly that 30-minute slot (and the
    // filtered item, if the calendar shows one item's week).
    const bookSlot = (date: string, minutes: number) => {
        const startsAt = joinLocal(date, timeOfDay(minutes));

        router.visit(
            createBooking({
                query: {
                    starts_at: startsAt,
                    ends_at: addMinutesLocal(startsAt, 30),
                    equipment: filters.equipment
                        ? [filters.equipment]
                        : undefined,
                },
            }),
        );
    };

    // Look the booking up by id so the drawer shows fresh data after a reload.
    const [openBookingId, setOpenBookingId] = useState<number | null>(null);
    const openBooking = (booking: CalendarBooking) =>
        setOpenBookingId(booking.id);
    const selected =
        bookings.find((booking) => booking.id === openBookingId) ?? null;

    return (
        <>
            <Head title="Calendar" />

            <div className="flex flex-col gap-4 p-4">
                <WeekToolbar
                    week={week}
                    filters={filters}
                    equipment={equipment}
                />
                <div className="flex flex-col gap-4 lg:flex-row">
                    <div className="min-w-0 flex-1">
                        <WeekGrid
                            days={days}
                            bookings={bookings}
                            timezone={timezone}
                            clock={clock}
                            onOpen={openBooking}
                            onSlotClick={bookSlot}
                        />
                    </div>
                    <SidePanel
                        out_now={out_now}
                        unavailable={unavailable}
                        my_at_risk={my_at_risk}
                        timezone={timezone}
                    />
                </div>
            </div>

            <BookingDrawer
                booking={selected}
                timezone={timezone}
                onClose={() => setOpenBookingId(null)}
            />
        </>
    );
}
