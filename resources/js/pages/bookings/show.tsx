import { Head, Link, usePage } from '@inertiajs/react';
import { BookingDetails } from '@/components/booking/booking-details';
import { calendar } from '@/routes';
import type { CalendarBooking } from '@/types';

export default function BookingShow({ booking }: { booking: CalendarBooking }) {
    const { timezone } = usePage().props;

    return (
        <>
            <Head title={booking.title} />

            <div className="flex max-w-2xl flex-col gap-4 p-4">
                <Link
                    href={calendar()}
                    className="text-sm text-muted-foreground hover:underline"
                >
                    ← Calendar
                </Link>
                <h1 className="text-xl font-semibold tracking-tight">
                    {booking.title}
                </h1>
                <BookingDetails booking={booking} timezone={timezone} />
            </div>
        </>
    );
}
