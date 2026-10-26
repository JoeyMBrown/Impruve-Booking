import { Head, router, usePage } from '@inertiajs/react';
import { WeekGrid } from '@/components/calendar/week-grid';
import { WeekToolbar } from '@/components/calendar/week-toolbar';
import { useCompanyClock } from '@/hooks/use-company-clock';
import { timeOfDay, weekDays } from '@/lib/calendar';
import { addMinutesLocal, joinLocal } from '@/lib/local-time';
import { create as createBooking } from '@/routes/bookings';
import type { CalendarBooking, CalendarWeekData } from '@/types';

export default function CalendarWeek(props: CalendarWeekData) {
    const { week, filters, equipment, bookings } = props;
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

    const openBooking = (booking: CalendarBooking) => {
        // The booking drawer is wired up in the next step.
        void booking;
    };

    return (
        <>
            <Head title="Calendar" />

            <div className="flex flex-col gap-4 p-4">
                <WeekToolbar
                    week={week}
                    filters={filters}
                    equipment={equipment}
                />
                <WeekGrid
                    days={days}
                    bookings={bookings}
                    timezone={timezone}
                    clock={clock}
                    onOpen={openBooking}
                    onSlotClick={bookSlot}
                />
            </div>
        </>
    );
}
